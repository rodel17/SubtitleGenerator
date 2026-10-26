import { FFmpeg, FFFSType } from '@ffmpeg/ffmpeg'
// FFmpeg compiled to WebAssembly (GPL-2.0-or-later, https://github.com/ffmpegwasm/ffmpeg.wasm).
// Served from our own site by Vite, so nothing about the user's file reaches a third party.
import coreURL from '@ffmpeg/core?url'
import wasmURL from '@ffmpeg/core/wasm?url'

/** The video has no audio, so there is nothing to transcribe */
export class NoAudioTrackError extends Error {
  constructor() {
    super('This video has no audio track, so there is nothing to transcribe.')
    this.name = 'NoAudioTrackError'
  }
}

export interface ExtractAudioOptions {
  signal?: AbortSignal
  /** 0–100 */
  onProgress?: (percent: number) => void
}

/** "Team Interview.mp4" -> "Team Interview.mka" */
export function audioFileName(videoName: string): string {
  const dot = videoName.lastIndexOf('.')
  return `${dot > 0 ? videoName.slice(0, dot) : videoName}.mka`
}

/**
 * Copy a video's audio track into its own file, in the browser, without re-encoding:
 * the audio is exactly the original, and only it needs to be uploaded. The video is
 * read in pieces (WORKERFS), never loaded into memory whole; only the audio is.
 * Matroska audio (.mka) can hold any audio codec, so copying never needs a conversion.
 *
 * Rejects with NoAudioTrackError when there is no audio, an AbortError when cancelled,
 * and anything else (e.g. out of memory, unreadable file) as a plain Error.
 */
export async function extractAudio(video: File, options: ExtractAudioOptions = {}): Promise<File> {
  const { signal, onProgress } = options
  if (signal?.aborted) throw new DOMException('Audio extraction was cancelled.', 'AbortError')

  const ffmpeg = new FFmpeg()
  const cancel = () => ffmpeg.terminate()
  signal?.addEventListener('abort', cancel)

  let hasNoAudio = false
  ffmpeg.on('log', ({ message }) => {
    if (/matches no streams|does not contain any stream/i.test(message)) hasNoAudio = true
  })
  ffmpeg.on('progress', ({ progress }) => {
    if (Number.isFinite(progress)) onProgress?.(Math.max(0, Math.min(99, Math.floor(progress * 100))))
  })

  try {
    await ffmpeg.load({ coreURL, wasmURL })
    await ffmpeg.createDir('/input')
    await ffmpeg.mount(FFFSType.WORKERFS, { files: [video] }, '/input')

    const exitCode = await ffmpeg.exec(['-i', `/input/${video.name}`, '-map', '0:a:0', '-vn', '-c:a', 'copy', '/audio.mka'])
    if (hasNoAudio) throw new NoAudioTrackError()
    if (exitCode !== 0) throw new Error(`FFmpeg could not extract the audio (exit code ${exitCode}).`)

    const audio = await ffmpeg.readFile('/audio.mka')
    if (typeof audio === 'string') throw new Error('FFmpeg returned text instead of audio.')
    onProgress?.(100)

    // Same modification time as the video, so a resumed upload can be matched to it
    return new File([audio as Uint8Array<ArrayBuffer>], audioFileName(video.name), {
      type: 'audio/x-matroska',
      lastModified: video.lastModified,
    })
  } catch (error) {
    if (signal?.aborted) throw new DOMException('Audio extraction was cancelled.', 'AbortError')
    throw error
  } finally {
    signal?.removeEventListener('abort', cancel)
    // Ends the worker and frees its memory; the next video loads the (cached) engine again
    ffmpeg.terminate()
  }
}
