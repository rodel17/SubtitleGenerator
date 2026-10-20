import { beforeEach, describe, it, expect, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { AxiosError, AxiosHeaders, type AxiosInstance, type AxiosResponse } from 'axios'

import { api } from '@/lib/api'
import { uploadInChunks, type ChunkedUploadOptions } from '@/services/chunkedUpload'
import type { UploadResource } from '@/types/upload'
import { useUploadsStore } from '../uploads'

vi.mock('@/lib/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/lib/api')>()),
  api: { get: vi.fn<AxiosInstance['get']>(), delete: vi.fn<AxiosInstance['delete']>() },
}))
vi.mock('@/services/chunkedUpload', () => ({ uploadInChunks: vi.fn<typeof uploadInChunks>() }))

const get = vi.mocked(api.get)
const destroy = vi.mocked(api.delete)
const upload = vi.mocked(uploadInChunks)

function resource(overrides: Partial<UploadResource> = {}): UploadResource {
  return {
    id: 'server-1',
    name: 'clip.mp4',
    extension: 'mp4',
    mime_type: 'video/mp4',
    size: 1024,
    chunk_size: 20,
    total_chunks: 52,
    language: 'en',
    subtitle_format: 'srt',
    status: 'uploaded',
    created_at: '2026-10-20T12:00:00.000000Z',
    completed_at: '2026-10-20T12:01:00.000000Z',
    ...overrides,
  }
}

function file(name = 'clip.mp4', bytes = 10): File {
  return new File(['x'.repeat(bytes)], name)
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
})

describe('fetchRecent', () => {
  it('shows completed uploads from the server as uploaded', async () => {
    get.mockResolvedValue({ data: { data: [resource({ language: 'ja', subtitle_format: 'vtt' })] } })
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
        status: 'uploaded',
        error: undefined,
        language: 'ja',
        format: 'vtt',
      },
    ])
  })

  it('shows uploads that never finished as interrupted failures', async () => {
    get.mockResolvedValue({ data: { data: [resource({ status: 'uploading', name: 'talk.mp3' })] } })
    const store = useUploadsStore()

    await store.fetchRecent()

    expect(store.items[0]).toMatchObject({
      kind: 'audio',
      status: 'failed',
      error: 'Upload was interrupted. Remove it and try again.',
    })
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
    expect(store.items.map((item) => item.status)).toEqual(['uploaded', 'uploaded'])
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

    expect(item().status).toBe('uploaded')
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

    expect(store.items.map((item) => item.status)).toEqual(['failed', 'uploaded'])
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

  it('is true while the server is assembling the file', async () => {
    upload.mockImplementation((_file, options) => {
      options.onAssembling?.()
      return new Promise(() => {})
    })
    const store = useUploadsStore()

    store.enqueue([file()], 'en', 'srt')
    await tick()

    expect(store.items[0]!.status).toBe('processing')
    expect(store.hasActiveUploads).toBe(true)
  })
})
