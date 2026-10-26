import { isAxiosError, isCancel } from 'axios'
import { api } from '@/lib/api'
import type { SubtitleFormat, TranslationEngine, UploadResource } from '@/types/upload'

/** Chunks sent at the same time for one file */
const PARALLEL_CHUNKS = 3
/** Attempts per chunk before the whole upload fails */
const MAX_ATTEMPTS = 3

export interface ChunkedUploadOptions {
  language: string
  format: SubtitleFormat
  /** Have the server email the subtitles as soon as they're ready */
  emailWhenReady?: boolean
  /** Translate the subtitles into this language (see TRANSLATION_TARGETS) */
  translateTo?: string
  /** Standard ("local", the default) or Enhanced ("azure") */
  translationEngine?: TranslationEngine
  /** Name to show and name the subtitles after, when the file is the audio of a video */
  displayName?: string
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

/**
 * 4xx responses (except timeouts / rate limits) won't succeed on retry; network
 * errors and 5xx might, so an upload that stopped on one of these can be resumed.
 */
export function isRetryable(error: unknown): boolean {
  if (isCancel(error)) return false
  if (!isAxiosError(error) || !error.response) return true

  const status = error.response.status
  return status >= 500 || status === 408 || status === 429
}

/**
 * Upload a file in chunks: start the upload, send chunks a few at a time
 * (each retried with backoff), then ask the API to assemble them.
 *
 * Resolves with the upload as the API accepted it for assembly (status
 * "assembling"); assembly and transcription continue on the server.
 */
export async function uploadInChunks(
  file: File,
  options: ChunkedUploadOptions,
): Promise<UploadResource> {
  const { data: started } = await api.post<{ data: UploadResource }>(
    '/uploads',
    {
      name: file.name,
      display_name: options.displayName ?? null,
      size: file.size,
      mime_type: file.type || null,
      last_modified: file.lastModified,
      language: options.language,
      subtitle_format: options.format,
      email_when_ready: options.emailWhenReady ?? false,
      translate_to: options.translateTo ?? null,
      translation_engine: options.translateTo ? (options.translationEngine ?? 'local') : null,
    },
    { signal: options.signal },
  )
  options.onStarted?.(started.data)

  return sendChunksAndComplete(file, started.data.id, started.data.chunk_size, new Set(), options)
}

/**
 * Continue an interrupted upload of the same file: ask the API which chunks it
 * already has and send only the rest.
 */
export async function resumeInChunks(
  file: File,
  uploadId: string,
  options: Pick<ChunkedUploadOptions, 'signal' | 'onProgress' | 'onAssembling'>,
): Promise<UploadResource> {
  const { data } = await api.get<{
    data: { chunk_size: number; total_chunks: number; received: number[] }
  }>(`/uploads/${uploadId}/chunks`, { signal: options.signal })

  return sendChunksAndComplete(
    file,
    uploadId,
    data.data.chunk_size,
    new Set(data.data.received),
    options,
  )
}

async function sendChunksAndComplete(
  file: File,
  uploadId: string,
  chunkSize: number,
  received: Set<number>,
  options: Pick<ChunkedUploadOptions, 'signal' | 'onProgress' | 'onAssembling'>,
): Promise<UploadResource> {
  const { signal, onProgress } = options

  const ranges = chunkRanges(file.size, chunkSize)
  // Chunks the server already has count as sent, so progress resumes where it stopped
  const sentBytes = ranges.map((range) => (received.has(range.index) ? range.end - range.start : 0))
  const pending = ranges.filter((range) => !received.has(range.index))
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
        await api.post(`/uploads/${uploadId}/chunks`, body, {
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

  if (received.size) reportProgress()

  // A small worker pool: each worker takes the next chunk until none are left
  let next = 0
  const worker = async () => {
    while (next < pending.length) {
      await sendChunk(pending[next++]!)
    }
  }
  await Promise.all(Array.from({ length: Math.min(PARALLEL_CHUNKS, pending.length) }, worker))

  options.onAssembling?.()
  const { data: completed } = await api.post<{ data: UploadResource }>(
    `/uploads/${uploadId}/completion`,
    null,
    { signal },
  )
  onProgress?.(100)

  return completed.data
}
