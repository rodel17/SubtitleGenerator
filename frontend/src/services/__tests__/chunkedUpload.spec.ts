import { afterEach, beforeEach, describe, it, expect, vi } from 'vitest'
import { AxiosError, AxiosHeaders, CanceledError, type AxiosInstance, type AxiosResponse } from 'axios'

import { api } from '@/lib/api'
import type { UploadResource } from '@/types/upload'
import { chunkRanges, resumeInChunks, uploadInChunks, type ChunkedUploadOptions } from '../chunkedUpload'

vi.mock('@/lib/api', () => ({
  api: { post: vi.fn<AxiosInstance['post']>(), get: vi.fn<AxiosInstance['get']>() },
}))

const post = vi.mocked(api.post)
const get = vi.mocked(api.get)

function resource(overrides: Partial<UploadResource> = {}): UploadResource {
  return {
    id: 'upload-1',
    name: 'clip.wav',
    extension: 'wav',
    mime_type: null,
    last_modified: null,
    progress: null,
    error: null,
    size: 45,
    chunk_size: 20,
    total_chunks: 3,
    language: 'en',
    subtitle_format: 'srt',
    status: 'uploading',
    created_at: '2026-10-20T12:00:00.000000Z',
    completed_at: null,
    ...overrides,
  }
}

function httpError(status: number, data: unknown = {}): AxiosError {
  const response = {
    status,
    statusText: '',
    data,
    headers: {},
    config: { headers: new AxiosHeaders() },
  } as AxiosResponse

  return new AxiosError(`Request failed with status code ${status}`, 'ERR_BAD_RESPONSE', undefined, undefined, response)
}

function fileOf(bytes: number, name = 'clip.wav', type = 'audio/wav'): File {
  const content = Array.from({ length: bytes }, (_, i) => String.fromCharCode(97 + (i % 26))).join('')
  return new File([content], name, { type })
}

function options(overrides: Partial<ChunkedUploadOptions> = {}): ChunkedUploadOptions {
  return { language: 'en', format: 'srt', signal: new AbortController().signal, ...overrides }
}

/** Route mocked POSTs by URL; `chunk` receives the FormData of each chunk request. */
function routeRequests(handlers: {
  start?: () => Promise<unknown>
  chunk?: (body: FormData, config: { onUploadProgress?: (e: { loaded: number }) => void }) => Promise<unknown>
  complete?: () => Promise<unknown>
} = {}) {
  post.mockImplementation(((url: string, body: unknown, config: never) => {
    if (url === '/uploads') return handlers.start?.() ?? Promise.resolve({ data: { data: resource() } })
    if (url.endsWith('/chunks')) return handlers.chunk?.(body as FormData, config) ?? Promise.resolve({ data: '' })
    if (url.endsWith('/completion')) {
      return handlers.complete?.() ?? Promise.resolve({ data: { data: resource({ status: 'uploaded' }) } })
    }
    throw new Error(`Unexpected POST ${url}`)
  }) as typeof api.post)
}

function chunkCalls() {
  return post.mock.calls.filter(([url]) => String(url).endsWith('/chunks'))
}

beforeEach(() => {
  post.mockReset()
  get.mockReset()
})

afterEach(() => {
  vi.useRealTimers()
})

describe('chunkRanges', () => {
  it('covers every byte exactly once, with a shorter last chunk', () => {
    expect(chunkRanges(25, 10)).toEqual([
      { index: 0, start: 0, end: 10 },
      { index: 1, start: 10, end: 20 },
      { index: 2, start: 20, end: 25 },
    ])
  })

  it('returns a single chunk for files smaller than the chunk size', () => {
    expect(chunkRanges(3, 10)).toEqual([{ index: 0, start: 0, end: 3 }])
  })

  it('does not add an empty chunk when the size divides evenly', () => {
    expect(chunkRanges(20, 10)).toHaveLength(2)
  })

  it('returns no chunks for an empty file', () => {
    expect(chunkRanges(0, 10)).toEqual([])
  })
})

describe('uploadInChunks', () => {
  it('starts the upload with the file details', async () => {
    routeRequests()

    const file = fileOf(45)

    await uploadInChunks(file, options({ language: 'ja', format: 'vtt' }))

    expect(post).toHaveBeenNthCalledWith(
      1,
      '/uploads',
      {
        name: 'clip.wav',
        size: 45,
        mime_type: 'audio/wav',
        last_modified: file.lastModified,
        language: 'ja',
        subtitle_format: 'vtt',
      },
      expect.objectContaining({ signal: expect.any(AbortSignal) }),
    )
  })

  it('sends a null MIME type when the browser does not know one', async () => {
    routeRequests()

    await uploadInChunks(fileOf(45, 'clip.mkv', ''), options())

    expect(post.mock.calls[0]![1]).toMatchObject({ mime_type: null })
  })

  it('splits the file using the chunk size chosen by the server', async () => {
    routeRequests({ start: async () => ({ data: { data: resource({ chunk_size: 20 }) } }) })

    await uploadInChunks(fileOf(45), options())

    const sent = chunkCalls().map(([, body]) => body as FormData)
    expect(sent.map((form) => form.get('index'))).toEqual(['0', '1', '2'])
    expect(sent.map((form) => (form.get('chunk') as Blob).size)).toEqual([20, 20, 5])
  })

  it('sends each byte range of the file in its own chunk', async () => {
    routeRequests()

    await uploadInChunks(fileOf(45), options())

    const texts = await Promise.all(
      chunkCalls().map(([, body]) => ((body as FormData).get('chunk') as Blob).text()),
    )
    expect(texts.join('')).toBe(await fileOf(45).text())
  })

  it('sends chunks to the upload id returned by the server', async () => {
    routeRequests({ start: async () => ({ data: { data: resource({ id: 'abc-123' }) } }) })

    await uploadInChunks(fileOf(45), options())

    expect(chunkCalls().every(([url]) => url === '/uploads/abc-123/chunks')).toBe(true)
    expect(post).toHaveBeenLastCalledWith('/uploads/abc-123/completion', null, expect.anything())
  })

  it('returns the completed upload from the server', async () => {
    routeRequests({ complete: async () => ({ data: { data: resource({ status: 'uploaded', mime_type: 'audio/x-wav' }) } }) })

    const result = await uploadInChunks(fileOf(45), options())

    expect(result).toMatchObject({ status: 'uploaded', mime_type: 'audio/x-wav' })
  })

  it('reports the lifecycle in order: started, progress, assembling, 100%', async () => {
    const events: string[] = []
    routeRequests({
      complete: async () => {
        events.push('complete-request')
        return { data: { data: resource({ status: 'uploaded' }) } }
      },
    })

    await uploadInChunks(fileOf(45), options({
      onStarted: (upload) => events.push(`started:${upload.id}`),
      onProgress: (percent) => events.push(`progress:${percent}`),
      onAssembling: () => events.push('assembling'),
    }))

    expect(events[0]).toBe('started:upload-1')
    expect(events.slice(-3)).toEqual(['assembling', 'complete-request', 'progress:100'])
  })

  it('reports progress by bytes sent and holds below 100 until the server finishes', async () => {
    const progress: number[] = []
    routeRequests({
      chunk: async (_body, config) => {
        config.onUploadProgress?.({ loaded: 10 })
        return { data: '' }
      },
    })

    await uploadInChunks(fileOf(45), options({ onProgress: (percent) => progress.push(percent) }))

    expect(progress).toContain(22) // the first half-sent chunk: 10 of 45 bytes
    expect(progress.slice(0, -1).every((percent) => percent <= 99)).toBe(true)
    expect(progress[progress.length - 1]).toBe(100)
  })

  it('never has more than three chunks in flight', async () => {
    let inFlight = 0
    let maxInFlight = 0
    routeRequests({
      start: async () => ({ data: { data: resource({ size: 200, chunk_size: 20, total_chunks: 10 }) } }),
      chunk: async () => {
        inFlight++
        maxInFlight = Math.max(maxInFlight, inFlight)
        await new Promise((resolve) => setTimeout(resolve, 5))
        inFlight--
        return { data: '' }
      },
    })

    await uploadInChunks(fileOf(200), options())

    expect(chunkCalls()).toHaveLength(10)
    expect(maxInFlight).toBe(3)
  })

  it.each([
    ['a 500 response', () => httpError(500)],
    ['a 503 response', () => httpError(503)],
    ['a 408 timeout', () => httpError(408)],
    ['a 429 rate limit', () => httpError(429)],
    ['a network error', () => new AxiosError('Network Error', 'ERR_NETWORK')],
  ])('retries a chunk after %s and then succeeds', async (_label, makeError) => {
    vi.useFakeTimers()
    let attempts = 0
    routeRequests({
      start: async () => ({ data: { data: resource({ size: 20, total_chunks: 1 }) } }),
      chunk: async () => {
        attempts++
        if (attempts === 1) throw makeError()
        return { data: '' }
      },
    })

    const upload = uploadInChunks(fileOf(20), options())
    await vi.advanceTimersByTimeAsync(1000) // first backoff: 500ms * 2

    await expect(upload).resolves.toMatchObject({ status: 'uploaded' })
    expect(attempts).toBe(2)
  })

  it('waits longer before each retry', async () => {
    vi.useFakeTimers()
    let attempts = 0
    routeRequests({
      start: async () => ({ data: { data: resource({ size: 20, total_chunks: 1 }) } }),
      chunk: async () => {
        attempts++
        if (attempts < 3) throw httpError(500)
        return { data: '' }
      },
    })

    const upload = uploadInChunks(fileOf(20), options())

    await vi.advanceTimersByTimeAsync(999)
    expect(attempts).toBe(1)
    await vi.advanceTimersByTimeAsync(1)
    expect(attempts).toBe(2)
    await vi.advanceTimersByTimeAsync(1999)
    expect(attempts).toBe(2)
    await vi.advanceTimersByTimeAsync(1)
    expect(attempts).toBe(3)
    await expect(upload).resolves.toBeDefined()
  })

  it('fails after three attempts and never asks the server to assemble', async () => {
    vi.useFakeTimers()
    routeRequests({
      start: async () => ({ data: { data: resource({ size: 20, total_chunks: 1 }) } }),
      chunk: async () => {
        throw httpError(500)
      },
    })

    const outcome = uploadInChunks(fileOf(20), options()).catch((error: unknown) => error)
    await vi.advanceTimersByTimeAsync(10_000)

    expect(await outcome).toMatchObject({ response: { status: 500 } })
    expect(chunkCalls()).toHaveLength(3)
    expect(post.mock.calls.some(([url]) => String(url).endsWith('/completion'))).toBe(false)
  })

  it.each([
    ['422 validation error', 422],
    ['404 not found', 404],
    ['409 conflict', 409],
  ])('does not retry a chunk rejected with %s', async (_label, status) => {
    routeRequests({
      start: async () => ({ data: { data: resource({ size: 20, total_chunks: 1 }) } }),
      chunk: async () => {
        throw httpError(status)
      },
    })

    await expect(uploadInChunks(fileOf(20), options())).rejects.toMatchObject({ response: { status } })
    expect(chunkCalls()).toHaveLength(1)
  })

  it('does not retry a cancelled request', async () => {
    routeRequests({
      start: async () => ({ data: { data: resource({ size: 20, total_chunks: 1 }) } }),
      chunk: async () => {
        throw new CanceledError()
      },
    })

    await expect(uploadInChunks(fileOf(20), options())).rejects.toBeInstanceOf(CanceledError)
    expect(chunkCalls()).toHaveLength(1)
  })

  it('stops waiting to retry as soon as the upload is aborted', async () => {
    vi.useFakeTimers()
    const controller = new AbortController()
    routeRequests({
      start: async () => ({ data: { data: resource({ size: 20, total_chunks: 1 }) } }),
      chunk: async () => {
        throw httpError(500)
      },
    })

    const outcome = uploadInChunks(fileOf(20), options({ signal: controller.signal })).catch((error: unknown) => error)
    await vi.advanceTimersByTimeAsync(100)
    controller.abort('user cancelled')

    expect(await outcome).toBe('user cancelled')
    expect(chunkCalls()).toHaveLength(1)
  })

  it('passes the abort signal to every request', async () => {
    const controller = new AbortController()
    routeRequests()

    await uploadInChunks(fileOf(45), options({ signal: controller.signal }))

    expect(post.mock.calls.every(([, , config]) => config?.signal === controller.signal)).toBe(true)
  })

  it('sends no chunks when the server refuses to start the upload', async () => {
    const onStarted = vi.fn<(upload: UploadResource) => void>()
    routeRequests({
      start: async () => {
        throw httpError(422, { errors: { size: ['The file is larger than the 2 GB limit.'] } })
      },
    })

    await expect(uploadInChunks(fileOf(45), options({ onStarted }))).rejects.toMatchObject({
      response: { status: 422 },
    })
    expect(chunkCalls()).toHaveLength(0)
    expect(onStarted).not.toHaveBeenCalled()
  })

  it('rejects when the server fails to assemble the file', async () => {
    routeRequests({
      complete: async () => {
        throw httpError(422, { errors: { file: ['The file is not a supported video or audio file.'] } })
      },
    })

    await expect(uploadInChunks(fileOf(45), options())).rejects.toMatchObject({
      response: { status: 422 },
    })
  })
})

describe('resumeInChunks', () => {
  function serverHas(received: number[], chunkSize = 20) {
    get.mockResolvedValue({ data: { data: { chunk_size: chunkSize, total_chunks: 3, received } } })
  }

  it('asks the server which chunks it already has', async () => {
    serverHas([])
    routeRequests()

    await resumeInChunks(fileOf(45), 'upload-7', options())

    expect(get).toHaveBeenCalledWith('/uploads/upload-7/chunks', expect.objectContaining({ signal: expect.any(AbortSignal) }))
  })

  it('sends only the missing chunks, with the bytes of the original file', async () => {
    serverHas([0, 2])
    routeRequests()
    const file = fileOf(45)

    await resumeInChunks(file, 'upload-7', options())

    const sent = chunkCalls()
    expect(sent.map(([url]) => url)).toEqual(['/uploads/upload-7/chunks'])
    const form = sent[0]![1] as FormData
    expect(form.get('index')).toBe('1')
    expect(await (form.get('chunk') as Blob).text()).toBe(await file.slice(20, 40).text())
  })

  it('never starts a new upload', async () => {
    serverHas([0])
    routeRequests()

    await resumeInChunks(fileOf(45), 'upload-7', options())

    expect(post.mock.calls.some(([url]) => url === '/uploads')).toBe(false)
  })

  it('starts the progress from what the server already has', async () => {
    serverHas([0, 1])
    routeRequests()
    const progress: number[] = []

    await resumeInChunks(fileOf(45), 'upload-7', options({ onProgress: (percent) => progress.push(percent) }))

    expect(progress[0]).toBe(88) // 40 of 45 bytes already on the server
    expect(progress[progress.length - 1]).toBe(100)
  })

  it('goes straight to completion when every chunk already arrived', async () => {
    serverHas([0, 1, 2])
    routeRequests()

    const result = await resumeInChunks(fileOf(45), 'upload-7', options())

    expect(chunkCalls()).toHaveLength(0)
    expect(post).toHaveBeenLastCalledWith('/uploads/upload-7/completion', null, expect.anything())
    expect(result.status).toBe('uploaded')
  })

  it('splits the file with the chunk size the upload was started with', async () => {
    serverHas([], 15)
    routeRequests()

    await resumeInChunks(fileOf(45), 'upload-7', options())

    expect(chunkCalls().map(([, body]) => ((body as FormData).get('chunk') as Blob).size)).toEqual([15, 15, 15])
  })

  it('rejects without sending chunks when the server no longer accepts them', async () => {
    get.mockRejectedValue(httpError(409, { message: 'This upload is already complete.' }))
    routeRequests()

    await expect(resumeInChunks(fileOf(45), 'upload-7', options())).rejects.toThrow('409')
    expect(post).not.toHaveBeenCalled()
  })
})
