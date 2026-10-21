import { afterEach, beforeEach, describe, it, expect, vi } from 'vitest'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { AxiosError, type AxiosInstance } from 'axios'

import { api } from '@/lib/api'
import { resumeInChunks, uploadInChunks } from '@/services/chunkedUpload'
import { useUploadsStore } from '@/stores/uploads'
import type { UploadResource } from '@/types/upload'
import UploadView from '../UploadView.vue'

vi.mock('@/lib/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/lib/api')>()),
  api: { get: vi.fn<AxiosInstance['get']>(), delete: vi.fn<AxiosInstance['delete']>() },
}))
vi.mock('@/services/chunkedUpload', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/services/chunkedUpload')>()),
  uploadInChunks: vi.fn<typeof uploadInChunks>(),
  resumeInChunks: vi.fn<typeof resumeInChunks>(),
}))

const get = vi.mocked(api.get)
const upload = vi.mocked(uploadInChunks)
const resumeUpload = vi.mocked(resumeInChunks)

// Each mounted view registers a window listener; unmount so tests don't leak into each other
enableAutoUnmount(afterEach)

function resource(overrides: Partial<UploadResource> = {}): UploadResource {
  return {
    id: 'server-1',
    name: 'product-demo.mov',
    extension: 'mov',
    mime_type: 'video/quicktime',
    last_modified: null,
    progress: null,
    error: null,
    size: 2048,
    chunk_size: 20,
    total_chunks: 103,
    language: 'en',
    subtitle_format: 'srt',
    status: 'ready',
    created_at: '2026-10-20T12:00:00.000000Z',
    completed_at: '2026-10-20T12:01:00.000000Z',
    ...overrides,
  }
}

function fileOf(name: string, bytes = 10): File {
  return new File(['x'.repeat(bytes)], name)
}

function hugeFile(name: string): File {
  const file = fileOf(name)
  Object.defineProperty(file, 'size', { value: 500 * 1024 ** 2 + 1 })
  return file
}

async function mountView() {
  const wrapper = mount(UploadView, { attachTo: document.body })
  await flushPromises()
  return wrapper
}

async function choose(wrapper: Awaited<ReturnType<typeof mountView>>, files: File[]) {
  const input = wrapper.get('input[type="file"]')
  Object.defineProperty(input.element, 'files', { value: files, configurable: true })
  await input.trigger('change')
}

const generateButton = (wrapper: Awaited<ReturnType<typeof mountView>>) =>
  wrapper.findAll('button').find((button) => button.text() === 'Generate subtitles')!
const clearButton = (wrapper: Awaited<ReturnType<typeof mountView>>) =>
  wrapper.findAll('button').find((button) => button.text() === 'Clear')!

beforeEach(() => {
  setActivePinia(createPinia())
  get.mockReset().mockResolvedValue({ data: { data: [] } })
  upload.mockReset().mockResolvedValue(resource())
  resumeUpload.mockReset()
  vi.mocked(api.delete).mockReset()
  vi.stubGlobal('URL', Object.assign(URL, { createObjectURL: vi.fn<typeof URL.createObjectURL>(() => 'blob:x'), revokeObjectURL: vi.fn<typeof URL.revokeObjectURL>() }))
})

afterEach(() => {
  vi.unstubAllGlobals()
  document.body.innerHTML = ''
})

describe('recent uploads', () => {
  it('loads recent uploads when the page opens', async () => {
    get.mockResolvedValue({ data: { data: [resource()] } })

    const wrapper = await mountView()

    expect(get).toHaveBeenCalledWith('/uploads')
    expect(wrapper.text()).toContain('product-demo.mov')
    expect(wrapper.text().replace(/\s+/g, ' ')).toContain('Ready · English · SRT · 2.0 KB')
  })

  it('does not reload when the list already has items', async () => {
    useUploadsStore().addRejected(fileOf('notes.txt'), 'Unsupported format.')

    await mountView()

    expect(get).not.toHaveBeenCalled()
  })

  it('shows a loading message, then the empty state', async () => {
    let finish!: (value: unknown) => void
    get.mockReturnValue(new Promise((resolve) => (finish = resolve)))
    const wrapper = mount(UploadView)
    await flushPromises()

    expect(wrapper.text()).toContain('Loading uploads…')
    finish({ data: { data: [] } })
    await flushPromises()

    expect(wrapper.text()).toContain('No uploads yet.')
  })

  it('shows the error when the list cannot be loaded', async () => {
    get.mockRejectedValue(new Error('Network Error'))

    const wrapper = await mountView()

    expect(wrapper.text()).toContain('Network Error')
  })
})

describe('choosing files', () => {
  it('disables Clear and Generate until a file is chosen', async () => {
    const wrapper = await mountView()

    expect(generateButton(wrapper).attributes('disabled')).toBeDefined()
    expect(clearButton(wrapper).attributes('disabled')).toBeDefined()
  })

  it('previews each accepted file before uploading anything', async () => {
    const wrapper = await mountView()

    await choose(wrapper, [fileOf('clip.mp4'), fileOf('talk.mp3')])

    expect(wrapper.text()).toContain('Selected (2)')
    expect(wrapper.findAll('video')).toHaveLength(1)
    expect(wrapper.findAll('audio')).toHaveLength(1)
    expect(generateButton(wrapper).attributes('disabled')).toBeUndefined()
    expect(upload).not.toHaveBeenCalled()
  })

  it('adds to the selection instead of replacing it', async () => {
    const wrapper = await mountView()

    await choose(wrapper, [fileOf('a.mp4')])
    await choose(wrapper, [fileOf('b.mp4')])

    expect(wrapper.text()).toContain('Selected (2)')
  })

  it('lists an unsupported file as failed instead of selecting it', async () => {
    const wrapper = await mountView()

    await choose(wrapper, [fileOf('notes.txt')])

    expect(wrapper.text()).not.toContain('Selected')
    expect(wrapper.text()).toContain('notes.txt')
    expect(wrapper.text()).toContain('Unsupported format. Try MP4, MOV, MKV.')
  })

  it('lists a file over the size limit as failed instead of selecting it', async () => {
    const wrapper = await mountView()

    await choose(wrapper, [hugeFile('movie.mp4')])

    expect(wrapper.text()).not.toContain('Selected')
    expect(wrapper.text()).toContain('File is larger than the 500 MB limit.')
  })

  it('keeps the valid files from a mixed selection', async () => {
    const wrapper = await mountView()

    await choose(wrapper, [fileOf('good.mp4'), fileOf('bad.txt'), hugeFile('huge.mov')])

    expect(wrapper.text()).toContain('Selected (1)')
    expect(useUploadsStore().items.map((item) => item.name)).toEqual(['huge.mov', 'bad.txt'])
  })

  it('removes one previewed file', async () => {
    const wrapper = await mountView()
    await choose(wrapper, [fileOf('a.mp4'), fileOf('b.mp4')])

    await wrapper.get('button[aria-label="Remove a.mp4"]').trigger('click')

    expect(wrapper.text()).toContain('Selected (1)')
    expect(wrapper.text()).not.toContain('a.mp4')
  })

  it('clears the whole selection', async () => {
    const wrapper = await mountView()
    await choose(wrapper, [fileOf('a.mp4'), fileOf('b.mp4')])

    await clearButton(wrapper).trigger('click')

    expect(wrapper.text()).not.toContain('Selected')
    expect(generateButton(wrapper).attributes('disabled')).toBeDefined()
  })
})

describe('generating subtitles', () => {
  it('uploads the selected files with the chosen language and format', async () => {
    const wrapper = await mountView()
    const clip = fileOf('clip.mp4')
    await choose(wrapper, [clip])
    await wrapper.get('select#language').setValue('ja')
    await wrapper.findAll('[role="group"] button').find((button) => button.text() === 'VTT')!.trigger('click')

    await generateButton(wrapper).trigger('click')
    await flushPromises()

    expect(upload).toHaveBeenCalledWith(clip, expect.objectContaining({ language: 'ja', format: 'vtt' }))
    expect(wrapper.text().replace(/\s+/g, ' ')).toContain('Ready · Japanese · VTT · 10 B')
  })

  it('defaults to auto-detect and SRT', async () => {
    const wrapper = await mountView()
    await choose(wrapper, [fileOf('clip.mp4')])

    await generateButton(wrapper).trigger('click')
    await flushPromises()

    expect(upload).toHaveBeenCalledWith(expect.any(File), expect.objectContaining({ language: 'auto', format: 'srt' }))
  })

  it('empties the selection once the upload starts', async () => {
    const wrapper = await mountView()
    await choose(wrapper, [fileOf('clip.mp4')])

    await generateButton(wrapper).trigger('click')

    expect(wrapper.text()).not.toContain('Selected')
  })

})

describe('deleting an upload', () => {
  const dialog = (wrapper: Awaited<ReturnType<typeof mountView>>) => wrapper.find('[role="alertdialog"]')
  const dialogButton = (wrapper: Awaited<ReturnType<typeof mountView>>, name: string) =>
    dialog(wrapper).findAll('button').find((button) => button.text() === name)!

  async function mountWithReadyUpload() {
    get.mockResolvedValue({ data: { data: [resource({ id: 'server-3', name: 'interview.mp4' })] } })
    const wrapper = await mountView()
    await wrapper.get('button[aria-label="Delete upload"]').trigger('click')
    return wrapper
  }

  it('asks for confirmation before deleting anything', async () => {
    const wrapper = await mountWithReadyUpload()

    expect(dialog(wrapper).text()).toContain('Delete this upload?')
    expect(dialog(wrapper).text()).toContain(`"interview.mp4" and its subtitles will be deleted. This can't be undone.`)
    expect(api.delete).not.toHaveBeenCalled()
  })

  it('focuses Cancel so a stray Enter does not delete', async () => {
    const wrapper = await mountWithReadyUpload()
    await flushPromises()

    expect(document.activeElement?.textContent?.trim()).toBe('Cancel')
    expect(wrapper.exists()).toBe(true)
  })

  it('keeps the upload when cancelled', async () => {
    const wrapper = await mountWithReadyUpload()

    await dialogButton(wrapper, 'Cancel').trigger('click')

    expect(dialog(wrapper).exists()).toBe(false)
    expect(api.delete).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('interview.mp4')
  })

  it('closes on Escape without deleting', async () => {
    const wrapper = await mountWithReadyUpload()

    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
    await flushPromises()

    expect(dialog(wrapper).exists()).toBe(false)
    expect(api.delete).not.toHaveBeenCalled()
  })

  it('shows a spinner on the button while the server deletes, then removes the row', async () => {
    let finish!: () => void
    vi.mocked(api.delete).mockReturnValue(new Promise((resolve) => (finish = () => resolve({ data: '' }))))
    const wrapper = await mountWithReadyUpload()

    await dialogButton(wrapper, 'Delete').trigger('click')

    const busyButton = dialogButton(wrapper, 'Deleting…')
    expect(busyButton.attributes('disabled')).toBeDefined()
    expect(busyButton.attributes('aria-busy')).toBe('true')
    expect(busyButton.find('[data-test="spinner"]').exists()).toBe(true)
    expect(dialogButton(wrapper, 'Cancel').attributes('disabled')).toBeDefined()
    expect(api.delete).toHaveBeenCalledWith('/uploads/server-3')

    finish()
    await flushPromises()

    expect(dialog(wrapper).exists()).toBe(false)
    expect(wrapper.text()).toContain('No uploads yet.')
  })

  it('cannot be dismissed while deleting', async () => {
    vi.mocked(api.delete).mockReturnValue(new Promise(() => {}))
    const wrapper = await mountWithReadyUpload()
    await dialogButton(wrapper, 'Delete').trigger('click')

    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
    await wrapper.get('[data-test="confirm-backdrop"]').trigger('click')

    expect(dialog(wrapper).exists()).toBe(true)
  })

  it('shows the error and keeps the upload when the delete fails, so it can be retried', async () => {
    vi.mocked(api.delete)
      .mockRejectedValueOnce(new AxiosError('Network Error', 'ERR_NETWORK'))
      .mockResolvedValueOnce({ data: '' })
    const wrapper = await mountWithReadyUpload()

    await dialogButton(wrapper, 'Delete').trigger('click')
    await flushPromises()

    expect(dialog(wrapper).get('[role="alert"]').text()).toBe('Network Error')
    expect(wrapper.text()).toContain('interview.mp4')

    await dialogButton(wrapper, 'Delete').trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('No uploads yet.')
  })

  it('removes a failed upload after confirming', async () => {
    get.mockResolvedValue({
      data: { data: [resource({ id: 'server-4', status: 'failed', error: 'No speech was detected in this file.' })] },
    })
    vi.mocked(api.delete).mockResolvedValue({ data: '' })
    const wrapper = await mountView()

    await wrapper.findAll('button').find((button) => button.text() === 'Remove')!.trigger('click')
    await dialogButton(wrapper, 'Delete').trigger('click')
    await flushPromises()

    expect(api.delete).toHaveBeenCalledWith('/uploads/server-4')
    expect(wrapper.text()).toContain('No uploads yet.')
  })

  it('asks to stop an upload that is still sending', async () => {
    upload.mockImplementation((_file, options) => {
      options.onStarted?.(resource({ id: 'server-7', status: 'uploading' }))
      return new Promise(() => {})
    })
    const wrapper = await mountView()
    await choose(wrapper, [fileOf('clip.mp4')])
    await generateButton(wrapper).trigger('click')
    await flushPromises()

    await wrapper.get('button[aria-label="Cancel upload"]').trigger('click')

    expect(dialog(wrapper).text()).toContain('Stop this upload?')
    expect(dialogButton(wrapper, 'Keep uploading').exists()).toBe(true)
    expect(dialogButton(wrapper, 'Stop upload').exists()).toBe(true)
  })

  it('removes a file that never reached the server without asking', async () => {
    const wrapper = await mountView()
    await choose(wrapper, [fileOf('notes.txt')])

    await wrapper.findAll('button').find((button) => button.text() === 'Remove')!.trigger('click')
    await flushPromises()

    expect(dialog(wrapper).exists()).toBe(false)
    expect(wrapper.text()).not.toContain('notes.txt')
    expect(api.delete).not.toHaveBeenCalled()
  })
})

describe('resuming an interrupted upload', () => {
  const resumeButton = (wrapper: Awaited<ReturnType<typeof mountView>>) =>
    wrapper.findAll('button').find((button) => button.text() === 'Resume')!

  async function pickToResume(wrapper: Awaited<ReturnType<typeof mountView>>, file: File) {
    const picker = wrapper.get('[data-test="resume-picker"]')
    Object.defineProperty(picker.element, 'files', { value: [file], configurable: true })
    await picker.trigger('change')
    await flushPromises()
  }

  it('asks for the file after a reload, then resumes on the same server upload', async () => {
    get.mockResolvedValue({
      data: { data: [resource({ id: 'server-5', name: 'clip.mp4', size: 10, status: 'uploading' })] },
    })
    resumeUpload.mockResolvedValue(resource({ id: 'server-5', name: 'clip.mp4', size: 10 }))
    const wrapper = await mountView()
    const picker = wrapper.get<HTMLInputElement>('[data-test="resume-picker"]').element
    const click = vi.spyOn(picker, 'click')

    await resumeButton(wrapper).trigger('click')
    expect(click).toHaveBeenCalled()
    const picked = fileOf('clip.mp4')
    await pickToResume(wrapper, picked)

    expect(resumeUpload).toHaveBeenCalledWith(picked, 'server-5', expect.anything())
    expect(wrapper.text().replace(/\s+/g, ' ')).toContain('Ready · English · SRT · 10 B')
  })

  it('explains which file to pick when the user picks a different one', async () => {
    get.mockResolvedValue({
      data: { data: [resource({ id: 'server-5', name: 'clip.mp4', size: 10, status: 'uploading' })] },
    })
    const wrapper = await mountView()

    await resumeButton(wrapper).trigger('click')
    await pickToResume(wrapper, fileOf('other.mp4'))

    expect(resumeUpload).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('That is a different file. Choose "clip.mp4" (10 B) to resume.')
  })
})

describe('leaving the page', () => {
  it('asks for confirmation while an upload is running', async () => {
    upload.mockReturnValue(new Promise(() => {}))
    const wrapper = await mountView()
    await choose(wrapper, [fileOf('clip.mp4')])
    await generateButton(wrapper).trigger('click')

    const event = new Event('beforeunload', { cancelable: true })
    window.dispatchEvent(event)

    expect(event.defaultPrevented).toBe(true)
  })

  it('lets the page close when nothing is uploading', async () => {
    await mountView()

    const event = new Event('beforeunload', { cancelable: true })
    window.dispatchEvent(event)

    expect(event.defaultPrevented).toBe(false)
  })

  it('stops listening after the view is unmounted', async () => {
    upload.mockReturnValue(new Promise(() => {}))
    const wrapper = await mountView()
    await choose(wrapper, [fileOf('clip.mp4')])
    await generateButton(wrapper).trigger('click')

    wrapper.unmount()
    const event = new Event('beforeunload', { cancelable: true })
    window.dispatchEvent(event)

    expect(event.defaultPrevented).toBe(false)
  })
})
