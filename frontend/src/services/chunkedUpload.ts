import { isAxiosError, isCancel } from 'axios'
import { api } from '@/lib/api'
import type { SubtitleFormat, UploadResource } from '@/types/upload'

/** Chunks sent at the same time for one file */
const PARALLEL_CHUNKS = 3
/** Attempts per chunk before the whole upload fails */
const MAX_ATTEMPTS = 3

export interface ChunkedUploadOptions {
  language: string
  format: SubtitleFormat
  signal: AbortSignal
  /** Called once the API has created the upload, before any chunk is sent */
  onStarted?: (upload: UploadResource) => void
  /** Overall progress, 0–100 */
  onProgress?: (percent: number) => void
  /** Called after the last chunk, while the API joins the chunks */
  onAssembling?: () => void
}

export interface ChunkRange {
  index: number
  start: number
  end: number
}

/** Split a file of `size` bytes into `chunkSize` pieces; the last one may be shorter. */
export function chunkRanges(size: number, chunkSize: number): ChunkRange[] {
  const count = Math.ceil(size / chunkSize)

  return Array.from({ length: count }, (_, index) => ({
    index,
    start: index * chunkSize,
    end: Math.min((index + 1) * chunkSize, size),
  }))
}

function wait(ms: number, signal: AbortSignal): Promise<void> {
  return new Promise((resolve, reject) => {
    const timer = setTimeout(resolve, ms)
    signal.addEventListener('abort', () => {
      clearTimeout(timer)
      reject(signal.reason)
    })
  })
}

/** 4xx responses (except timeouts / rate limits) won't succeed on retry */
function isRetryable(error: unknown): boolean {
  if (isCancel(error)) return false
  if (!isAxiosError(error) || !error.response) return true

  const status = error.response.status
  return status >= 500 || status === 408 || status === 429
}

/**
 * Upload a file in chunks: start the upload, send chunks a few at a time
 * (each retried with backoff), then ask the API to assemble them.
 */
export async function uploadInChunks(
  file: File,
  options: ChunkedUploadOptions,
): Promise<UploadResource> {
  const { signal, onProgress } = options

  const { data: started } = await api.post<{ data: UploadResource }>(
    '/uploads',
    {
      name: file.name,
      size: file.size,
      mime_type: file.type || null,
      language: options.language,
      subtitle_format: options.format,
    },
    { signal },
  )
  const upload = started.data
  options.onStarted?.(upload)

  const ranges = chunkRanges(file.size, upload.chunk_size)
  const sentBytes = new Array<number>(ranges.length).fill(0)
  const reportProgress = () => {
    const total = sentBytes.reduce((sum, bytes) => sum + bytes, 0)
    onProgress?.(Math.min(99, Math.floor((total / file.size) * 100)))
  }

  async function sendChunk({ index, start, end }: ChunkRange) {
    for (let attempt = 1; ; attempt++) {
      const body = new FormData()
      body.append('index', String(index))
      body.append('chunk', file.slice(start, end), `${index}`)

      try {
        await api.post(`/uploads/${upload.id}/chunks`, body, {
          signal,
          onUploadProgress: (event) => {
            sentBytes[index] = Math.min(event.loaded, end - start)
            reportProgress()
          },
        })
        sentBytes[index] = end - start
        reportProgress()
        return
      } catch (error) {
        sentBytes[index] = 0
        if (attempt >= MAX_ATTEMPTS || !isRetryable(error)) throw error
        await wait(500 * 2 ** attempt, signal)
      }
    }
  }

  // A small worker pool: each worker takes the next chunk until none are left
  let next = 0
  const worker = async () => {
    while (next < ranges.length) {
      await sendChunk(ranges[next++]!)
    }
  }
  await Promise.all(Array.from({ length: Math.min(PARALLEL_CHUNKS, ranges.length) }, worker))

  options.onAssembling?.()
  const { data: completed } = await api.post<{ data: UploadResource }>(
    `/uploads/${upload.id}/completion`,
    null,
    { signal },
  )
  onProgress?.(100)

  return completed.data
}
