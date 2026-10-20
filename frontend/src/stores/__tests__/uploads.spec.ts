import { afterEach, beforeEach, describe, it, expect, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { AxiosError, AxiosHeaders, type AxiosInstance, type AxiosResponse } from 'axios'

import { api } from '@/lib/api'
import { resumeInChunks, uploadInChunks, type ChunkedUploadOptions } from '@/services/chunkedUpload'
import type { UploadResource } from '@/types/upload'
import { POLL_INTERVAL_MS, useUploadsStore } from '../uploads'

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
const destroy = vi.mocked(api.delete)
const upload = vi.mocked(uploadInChunks)
const resumeUpload = vi.mocked(resumeInChunks)

function resource(overrides: Partial<UploadResource> = {}): UploadResource {
  return {
    id: 'server-1',
    name: 'clip.mp4',
    extension: 'mp4',
    mime_type: 'video/mp4',
    last_modified: null,
    progress: null,
    error: null,
    size: 1024,
    chunk_size: 20,
    total_chunks: 52,
    language: 'en',
    subtitle_format: 'srt',
    status: 'ready',
    created_at: '2026-10-20T12:00:00.000000Z',
    completed_at: '2026-10-20T12:01:00.000000Z',
    ...overrides,
  }
}

function file(name = 'clip.mp4', bytes = 10, lastModified = 1_700_000_000_000): File {
  return new File(['x'.repeat(bytes)], name, { lastModified })
}

/** A promise you resolve or reject from the test, to hold an upload mid-flight. */
function deferred<T = void>() {
  let resolve!: (value: T) => void
  let reject!: (reason: unknown) => void
  const promise = new Promise<T>((res, rej) => {
    resolve = res
    reject = rej
  })
  return { promise, resolve, reject }
}

/** Resolve pending microtasks so the queue loop can advance. */
const tick = () => new Promise((resolve) => setTimeout(resolve, 0))

beforeEach(() => {
  setActivePinia(createPinia())
  get.mockReset()
  destroy.mockReset().mockResolvedValue({ data: '' })
  upload.mockReset()
  resumeUpload.mockReset()
})

afterEach(() => {
  vi.useRealTimers()
})

describe('fetchRecent', () => {
  it('shows ready uploads from the server with their download links', async () => {
    get.mockResolvedValue({
      data: {
        data: [
          resource({
            language: 'ja',
            subtitle_format: 'vtt',
            last_modified: 123,
            subtitles: { vtt: 'http://api.test/subtitles/vtt' },
          }),
        ],
      },
    })
    const store = useUploadsStore()

    await store.fetchRecent()

    expect(get).toHaveBeenCalledWith('/uploads')
    expect(store.items).toEqual([
      {
        id: 'server-1',
        serverId: 'server-1',
        name: 'clip.mp4',
        kind: 'video',
        size: 1024,
        lastModified: 123,
        status: 'ready',
        progress: undefined,
        error: undefined,
        language: 'ja',
        format: 'vtt',
        subtitles: { vtt: 'http://api.test/subtitles/vtt' },
      },
    ])
  })

  it('shows uploads that never finished as interrupted, ready to resume', async () => {
    get.mockResolvedValue({ data: { data: [resource({ status: 'uploading', name: 'talk.mp3' })] } })
    const store = useUploadsStore()

    await store.fetchRecent()

    expect(store.items[0]).toMatchObject({
      kind: 'audio',
      status: 'interrupted',
      error: 'Upload was interrupted. Resume to continue where it stopped.',
    })
  })

  it.each([
    ['assembling', 'processing'],
    ['uploaded', 'uploaded'],
    ['transcribing', 'transcribing'],
  ] as const)('shows a %s upload as %s with its progress', async (serverStatus, status) => {
    vi.useFakeTimers()
    get.mockResolvedValue({ data: { data: [resource({ status: serverStatus, progress: 30 })] } })
    const store = useUploadsStore()

    await store.fetchRecent()

    expect(store.items[0]).toMatchObject({ status, progress: 30 })
  })

  it('shows why the server could not process an upload', async () => {
    get.mockResolvedValue({ data: { data: [resource({ status: 'failed', error: 'No speech was detected in this file.' })] } })
    const store = useUploadsStore()

    await store.fetchRecent()

    expect(store.items[0]).toMatchObject({ status: 'failed', error: 'No speech was detected in this file.' })
  })

  it('leaves the language unset when the server has none', async () => {
    get.mockResolvedValue({ data: { data: [resource({ language: null })] } })
    const store = useUploadsStore()

    await store.fetchRecent()

    expect(store.items[0]!.language).toBeUndefined()
  })

  it('keeps local uploads first and does not duplicate ones it already tracks', async () => {
    const pending = deferred()
    upload.mockImplementation(async (_file, options) => {
      options.onStarted?.(resource({ id: 'server-local', status: 'uploading' }))
      await pending.promise
      return resource({ id: 'server-local' })
    })
    get.mockResolvedValue({
      data: { data: [resource({ id: 'server-local', status: 'uploading' }), resource({ id: 'server-old' })] },
    })
    const store = useUploadsStore()
    store.enqueue([file('local.mp4')], 'en', 'srt')
    await tick()

    await store.fetchRecent()

    expect(store.items.map((item) => item.serverId)).toEqual(['server-local', 'server-old'])
    expect(store.items[0]!.status).toBe('uploading')
  })

  it('tracks loading while the request is in flight', async () => {
    const response = deferred<{ data: { data: UploadResource[] } }>()
    get.mockReturnValue(response.promise)
    const store = useUploadsStore()

    const fetching = store.fetchRecent()
    expect(store.isLoading).toBe(true)
    response.resolve({ data: { data: [] } })
    await fetching

    expect(store.isLoading).toBe(false)
  })

  it('stores an error message when the request fails', async () => {
    get.mockRejectedValue(new AxiosError('Network Error', 'ERR_NETWORK'))
    const store = useUploadsStore()

    await store.fetchRecent()

    expect(store.loadError).toBe('Network Error')
    expect(store.isLoading).toBe(false)
    expect(store.items).toEqual([])
  })

  it('clears a previous error when retried successfully', async () => {
    get.mockRejectedValueOnce(new Error('offline')).mockResolvedValueOnce({ data: { data: [] } })
    const store = useUploadsStore()
    await store.fetchRecent()

    await store.fetchRecent()

    expect(store.loadError).toBeNull()
  })
})

describe('enqueue', () => {
  it('adds the files to the top of the list as queued, in order', () => {
    upload.mockReturnValue(new Promise(() => {}))
    const store = useUploadsStore()
    store.addRejected(file('old.txt'), 'Unsupported format.')

    store.enqueue([file('a.mp4'), file('b.mp3', 25)], 'fil', 'both')

    expect(store.items.map((item) => item.name)).toEqual(['a.mp4', 'b.mp3', 'old.txt'])
    expect(store.items[1]).toMatchObject({ kind: 'audio', size: 25, language: 'fil', format: 'both' })
  })

  it('uploads the file with the chosen language and format', async () => {
    upload.mockResolvedValue(resource())
    const store = useUploadsStore()
    const selected = file('a.mp4')

    store.enqueue([selected], 'ja', 'vtt')
    await tick()

    expect(upload).toHaveBeenCalledWith(
      selected,
      expect.objectContaining({ language: 'ja', format: 'vtt', signal: expect.any(AbortSignal) }),
    )
  })

  it('uploads one file at a time', async () => {
    const first = deferred<UploadResource>()
    upload.mockReturnValueOnce(first.promise).mockResolvedValueOnce(resource())
    const store = useUploadsStore()

    store.enqueue([file('a.mp4'), file('b.mp4')], 'en', 'srt')
    await tick()

    expect(upload).toHaveBeenCalledTimes(1)
    expect(store.items.map((item) => item.status)).toEqual(['uploading', 'queued'])

    first.resolve(resource())
    await tick()

    expect(upload).toHaveBeenCalledTimes(2)
    expect(store.items.map((item) => item.status)).toEqual(['ready', 'ready'])
  })

  it('picks up files added while another upload is running', async () => {
    const first = deferred<UploadResource>()
    upload.mockReturnValueOnce(first.promise).mockResolvedValueOnce(resource())
    const store = useUploadsStore()
    store.enqueue([file('a.mp4')], 'en', 'srt')
    await tick()

    store.enqueue([file('b.mp4')], 'en', 'srt')
    await tick()
    expect(upload).toHaveBeenCalledTimes(1)
    first.resolve(resource())
    await tick()

    expect(upload).toHaveBeenCalledTimes(2)
  })

  it('reflects progress reported by the uploader', async () => {
    const hold = deferred()
    let callbacks!: ChunkedUploadOptions
    upload.mockImplementation(async (_file, options) => {
      callbacks = options
      await hold.promise
      return resource()
    })
    const store = useUploadsStore()
    store.enqueue([file()], 'en', 'srt')
    await tick()
    const item = () => store.items[0]!

    expect(item()).toMatchObject({ status: 'uploading', progress: 0 })
    callbacks.onStarted?.(resource({ id: 'server-9', status: 'uploading' }))
    callbacks.onProgress?.(42)
    expect(item()).toMatchObject({ serverId: 'server-9', progress: 42 })
    callbacks.onAssembling?.()
    expect(item().status).toBe('processing')
    hold.resolve()
    await tick()

    expect(item().status).toBe('ready')
  })

  it('marks the upload failed with the server message', async () => {
    const response = {
      status: 422,
      statusText: '',
      data: { errors: { file: ['The file is not a supported video or audio file.'] } },
      headers: {},
      config: { headers: new AxiosHeaders() },
    } as AxiosResponse
    upload.mockRejectedValue(new AxiosError('Request failed', 'ERR_BAD_REQUEST', undefined, undefined, response))
    const store = useUploadsStore()

    store.enqueue([file()], 'en', 'srt')
    await tick()

    expect(store.items[0]).toMatchObject({
      status: 'failed',
      error: 'The file is not a supported video or audio file.',
    })
  })

  it('continues with the next file after one fails', async () => {
    upload.mockRejectedValueOnce(new Error('boom')).mockResolvedValueOnce(resource())
    const store = useUploadsStore()

    store.enqueue([file('a.mp4'), file('b.mp4')], 'en', 'srt')
    await tick()

    expect(store.items.map((item) => item.status)).toEqual(['failed', 'ready'])
  })
})

describe('addRejected', () => {
  it('adds a failed row at the top with the reason', () => {
    const store = useUploadsStore()

    store.addRejected(file('notes.txt', 3), 'Unsupported format.')

    expect(store.items[0]).toMatchObject({ name: 'notes.txt', size: 3, status: 'failed', error: 'Unsupported format.' })
    expect(store.items[0]!.serverId).toBeUndefined()
    expect(upload).not.toHaveBeenCalled()
  })
})

describe('remove', () => {
  it('aborts an in-flight upload, removes the row and deletes it on the server', async () => {
    let signal!: AbortSignal
    upload.mockImplementation((_file, options) => {
      signal = options.signal
      options.onStarted?.(resource({ id: 'server-5', status: 'uploading' }))
      return new Promise((_resolve, reject) => {
        options.signal.addEventListener('abort', () => reject(new Error('aborted')))
      })
    })
    const store = useUploadsStore()
    store.enqueue([file()], 'en', 'srt')
    await tick()

    await store.remove(store.items[0]!.id)
    await tick()

    expect(signal.aborted).toBe(true)
    expect(store.items).toEqual([])
    expect(destroy).toHaveBeenCalledWith('/uploads/server-5')
  })

  it('never uploads a queued file that was removed before its turn', async () => {
    const first = deferred<UploadResource>()
    upload.mockReturnValueOnce(first.promise)
    const store = useUploadsStore()
    store.enqueue([file('a.mp4'), file('b.mp4')], 'en', 'srt')
    await tick()

    await store.remove(store.items[1]!.id)
    first.resolve(resource())
    await tick()

    expect(upload).toHaveBeenCalledTimes(1)
    expect(store.items.map((item) => item.name)).toEqual(['a.mp4'])
  })

  it('deletes an uploaded file from the server', async () => {
    get.mockResolvedValue({ data: { data: [resource({ id: 'server-7' })] } })
    const store = useUploadsStore()
    await store.fetchRecent()

    await store.remove('server-7')

    expect(destroy).toHaveBeenCalledWith('/uploads/server-7')
    expect(store.items).toEqual([])
  })

  it('does not call the server for a row it never created there', async () => {
    const store = useUploadsStore()
    store.addRejected(file('notes.txt'), 'Unsupported format.')

    await store.remove(store.items[0]!.id)

    expect(destroy).not.toHaveBeenCalled()
    expect(store.items).toEqual([])
  })

  it('still removes the row when the server delete fails', async () => {
    get.mockResolvedValue({ data: { data: [resource({ id: 'server-8' })] } })
    destroy.mockRejectedValue(new AxiosError('Network Error', 'ERR_NETWORK'))
    const store = useUploadsStore()
    await store.fetchRecent()

    await expect(store.remove('server-8')).resolves.toBeUndefined()

    expect(store.items).toEqual([])
  })

  it('ignores an id it does not know', async () => {
    const store = useUploadsStore()

    await store.remove('missing')

    expect(destroy).not.toHaveBeenCalled()
  })
})

describe('hasActiveUploads', () => {
  it('is false when nothing is running', async () => {
    get.mockResolvedValue({ data: { data: [resource()] } })
    const store = useUploadsStore()
    store.addRejected(file('notes.txt'), 'Unsupported format.')
    await store.fetchRecent()

    expect(store.hasActiveUploads).toBe(false)
  })

  it('is true while a file is queued or uploading, and false once it finishes', async () => {
    const hold = deferred<UploadResource>()
    upload.mockReturnValue(hold.promise)
    const store = useUploadsStore()

    store.enqueue([file()], 'en', 'srt')
    expect(store.hasActiveUploads).toBe(true)
    hold.resolve(resource())
    await tick()

    expect(store.hasActiveUploads).toBe(false)
  })

  it('is false once the browser has handed the file over, while the server assembles it', async () => {
    upload.mockImplementation((_file, options) => {
      options.onAssembling?.()
      return new Promise(() => {})
    })
    const store = useUploadsStore()

    store.enqueue([file()], 'en', 'srt')
    await tick()

    expect(store.items[0]!.status).toBe('processing')
    expect(store.hasActiveUploads).toBe(false)
  })
})

function httpError(status: number, data: unknown = {}): AxiosError {
  const response = { status, statusText: '', data, headers: {}, config: { headers: new AxiosHeaders() } } as AxiosResponse
  return new AxiosError(`Request failed with status code ${status}`, 'ERR_BAD_RESPONSE', undefined, undefined, response)
}

const networkError = () => new AxiosError('Network Error', 'ERR_NETWORK')

describe('following server processing', () => {
  /** Upload one file whose completion the server accepts with the given status. */
  async function uploadAccepted(store: ReturnType<typeof useUploadsStore>, accepted: Partial<UploadResource>) {
    upload.mockImplementation(async (_file, options) => {
      options.onStarted?.(resource({ id: 'server-1', status: 'uploading' }))
      return resource({ id: 'server-1', ...accepted })
    })
    store.enqueue([file()], 'en', 'srt')
    await vi.advanceTimersByTimeAsync(0)
  }

  it('asks the server for progress every two seconds until the subtitles are ready', async () => {
    vi.useFakeTimers()
    const store = useUploadsStore()
    get
      .mockResolvedValueOnce({ data: { data: resource({ status: 'transcribing', progress: 40 }) } })
      .mockResolvedValueOnce({
        data: { data: resource({ status: 'ready', subtitles: { srt: 'http://api.test/subtitles/srt' } }) },
      })
    await uploadAccepted(store, { status: 'assembling', progress: 0 })
    expect(store.items[0]).toMatchObject({ status: 'processing', progress: 0 })

    await vi.advanceTimersByTimeAsync(POLL_INTERVAL_MS)
    expect(get).toHaveBeenLastCalledWith('/uploads/server-1')
    expect(store.items[0]).toMatchObject({ status: 'transcribing', progress: 40 })

    await vi.advanceTimersByTimeAsync(POLL_INTERVAL_MS)
    expect(store.items[0]).toMatchObject({
      status: 'ready',
      progress: undefined,
      subtitles: { srt: 'http://api.test/subtitles/srt' },
    })

    await vi.advanceTimersByTimeAsync(POLL_INTERVAL_MS * 3)
    expect(get).toHaveBeenCalledTimes(2)
  })

  it('shows the reason when processing fails, and stops asking', async () => {
    vi.useFakeTimers()
    const store = useUploadsStore()
    get.mockResolvedValue({ data: { data: resource({ status: 'failed', error: 'No speech was detected in this file.' }) } })
    await uploadAccepted(store, { status: 'assembling' })

    await vi.advanceTimersByTimeAsync(POLL_INTERVAL_MS * 3)

    expect(store.items[0]).toMatchObject({ status: 'failed', error: 'No speech was detected in this file.' })
    expect(get).toHaveBeenCalledTimes(1)
  })

  it('keeps asking through a temporary network error', async () => {
    vi.useFakeTimers()
    const store = useUploadsStore()
    get.mockRejectedValueOnce(networkError()).mockResolvedValueOnce({ data: { data: resource({ status: 'ready' }) } })
    await uploadAccepted(store, { status: 'transcribing', progress: 10 })

    await vi.advanceTimersByTimeAsync(POLL_INTERVAL_MS)
    expect(store.items[0]).toMatchObject({ status: 'transcribing', progress: 10 })
    await vi.advanceTimersByTimeAsync(POLL_INTERVAL_MS)

    expect(store.items[0]!.status).toBe('ready')
  })

  it('marks the row failed when the upload was deleted on the server', async () => {
    vi.useFakeTimers()
    const store = useUploadsStore()
    get.mockRejectedValue(httpError(404))
    await uploadAccepted(store, { status: 'uploaded' })

    await vi.advanceTimersByTimeAsync(POLL_INTERVAL_MS)

    expect(store.items[0]).toMatchObject({ status: 'failed', error: 'This upload no longer exists.' })
  })

  it('follows uploads that were already processing when the page loaded', async () => {
    vi.useFakeTimers()
    get
      .mockResolvedValueOnce({ data: { data: [resource({ id: 'server-2', status: 'transcribing', progress: 5 })] } })
      .mockResolvedValueOnce({ data: { data: resource({ id: 'server-2', status: 'transcribing', progress: 60 }) } })
    const store = useUploadsStore()
    await store.fetchRecent()

    await vi.advanceTimersByTimeAsync(POLL_INTERVAL_MS)

    expect(get).toHaveBeenLastCalledWith('/uploads/server-2')
    expect(store.items[0]!.progress).toBe(60)
  })

  it('does not poll when nothing is processing', async () => {
    vi.useFakeTimers()
    get.mockResolvedValue({ data: { data: [resource({ status: 'ready' }), resource({ id: 'b', status: 'failed' })] } })
    const store = useUploadsStore()
    await store.fetchRecent()

    await vi.advanceTimersByTimeAsync(POLL_INTERVAL_MS * 3)

    expect(get).toHaveBeenCalledTimes(1)
  })
})

describe('resume', () => {
  /** Start an upload that the network cuts off after the server created it. */
  async function interruptedUpload(store: ReturnType<typeof useUploadsStore>, selected = file()) {
    upload.mockImplementationOnce(async (_file, options) => {
      options.onStarted?.(resource({ id: 'server-4', status: 'uploading' }))
      throw networkError()
    })
    store.enqueue([selected], 'en', 'srt')
    await tick()
    return store.items[0]!
  }

  it('keeps an upload cut off by the network as interrupted, with its file', async () => {
    const store = useUploadsStore()

    const item = await interruptedUpload(store)

    expect(item).toMatchObject({
      status: 'interrupted',
      error: 'Upload was interrupted. Resume to continue where it stopped.',
    })
    expect(store.hasFileFor(item.id)).toBe(true)
  })

  it('resumes with the kept file on the same server upload', async () => {
    const store = useUploadsStore()
    const selected = file()
    const item = await interruptedUpload(store, selected)
    resumeUpload.mockResolvedValue(resource({ id: 'server-4' }))

    expect(store.resume(item.id)).toBeUndefined()
    await tick()

    expect(resumeUpload).toHaveBeenCalledWith(selected, 'server-4', expect.objectContaining({ signal: expect.any(AbortSignal) }))
    expect(upload).toHaveBeenCalledTimes(1)
    expect(store.items[0]!.status).toBe('ready')
    expect(store.hasFileFor(item.id)).toBe(false)
  })

  it('becomes interrupted again when the resumed upload is cut off too', async () => {
    const store = useUploadsStore()
    const item = await interruptedUpload(store)
    resumeUpload.mockRejectedValue(networkError())

    store.resume(item.id)
    await tick()

    expect(store.items[0]!.status).toBe('interrupted')
    expect(store.hasFileFor(item.id)).toBe(true)
  })

  it('fails instead of offering resume when the server rejected the file', async () => {
    upload.mockImplementation(async (_file, options) => {
      options.onStarted?.(resource({ id: 'server-4', status: 'uploading' }))
      throw httpError(422, { errors: { file: ['The file is not a supported video or audio file.'] } })
    })
    const store = useUploadsStore()

    store.enqueue([file()], 'en', 'srt')
    await tick()

    expect(store.items[0]).toMatchObject({ status: 'failed', error: 'The file is not a supported video or audio file.' })
    expect(store.hasFileFor(store.items[0]!.id)).toBe(false)
  })

  describe('after a page reload', () => {
    async function reloadedInterruptedUpload(overrides: Partial<UploadResource> = {}) {
      get.mockResolvedValue({
        data: {
          data: [
            resource({
              id: 'server-6',
              status: 'uploading',
              name: 'clip.mp4',
              size: 10,
              last_modified: 1_700_000_000_000,
              ...overrides,
            }),
          ],
        },
      })
      const store = useUploadsStore()
      await store.fetchRecent()
      return store
    }

    it('needs the user to pick the file again', async () => {
      const store = await reloadedInterruptedUpload()

      expect(store.hasFileFor('server-6')).toBe(false)
      expect(store.resume('server-6')).toBe('Choose the file to resume.')
      expect(resumeUpload).not.toHaveBeenCalled()
    })

    it('resumes with the picked file when it is the same file', async () => {
      const store = await reloadedInterruptedUpload()
      resumeUpload.mockResolvedValue(resource({ id: 'server-6' }))
      const picked = file('clip.mp4', 10, 1_700_000_000_000)

      expect(store.resume('server-6', picked)).toBeUndefined()
      await tick()

      expect(resumeUpload).toHaveBeenCalledWith(picked, 'server-6', expect.anything())
    })

    it.each([
      ['a different name', () => file('other.mp4', 10, 1_700_000_000_000)],
      ['a different size', () => file('clip.mp4', 11, 1_700_000_000_000)],
      ['a different modification time', () => file('clip.mp4', 10, 1_600_000_000_000)],
    ])('refuses a file with %s and says which file to pick', async (_case, picked) => {
      const store = await reloadedInterruptedUpload()

      const error = store.resume('server-6', picked())

      expect(error).toBe('That is a different file. Choose "clip.mp4" (10 B) to resume.')
      expect(store.items[0]).toMatchObject({ status: 'interrupted', error })
      expect(resumeUpload).not.toHaveBeenCalled()
    })

    it('matches on name and size alone when the server has no modification time', async () => {
      const store = await reloadedInterruptedUpload({ last_modified: null })
      resumeUpload.mockResolvedValue(resource({ id: 'server-6' }))

      expect(store.resume('server-6', file('clip.mp4', 10, 42))).toBeUndefined()
    })
  })

  it('ignores rows that are not interrupted', async () => {
    get.mockResolvedValue({ data: { data: [resource({ id: 'server-9', status: 'ready' })] } })
    const store = useUploadsStore()
    await store.fetchRecent()

    expect(store.resume('server-9', file())).toBeUndefined()

    expect(resumeUpload).not.toHaveBeenCalled()
    expect(store.items[0]!.status).toBe('ready')
  })
})
