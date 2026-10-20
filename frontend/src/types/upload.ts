export type SubtitleFormat = 'srt' | 'vtt' | 'both'

export type UploadStatus =
  | 'queued'
  | 'uploading'
  | 'processing'
  | 'uploaded'
  | 'transcribing'
  | 'ready'
  | 'failed'

export type MediaKind = 'video' | 'audio'

export interface UploadItem {
  /** Client-side id; stays stable while the server id is still unknown */
  id: string
  /** Id of the upload on the API, once it has been started */
  serverId?: string
  name: string
  kind: MediaKind
  size: number
  status: UploadStatus
  /** 0–100, only meaningful while uploading */
  progress?: number
  language?: string
  format?: SubtitleFormat
  error?: string
}

/** Shape of `UploadResource` returned by the Laravel API */
export interface UploadResource {
  id: string
  name: string
  extension: string
  mime_type: string | null
  size: number
  chunk_size: number
  total_chunks: number
  language: string | null
  subtitle_format: SubtitleFormat
  status: 'uploading' | 'uploaded'
  created_at: string
  completed_at: string | null
}

/** Must match `uploads.allowed_extensions` in backend/config/uploads.php ("ts" = MPEG transport stream) */
export const ACCEPTED_EXTENSIONS = ['mp4', 'mov', 'mkv', 'ts', 'mp3', 'wav', 'm4a'] as const

/** Must match `uploads.max_size` in backend/config/uploads.php */
export const MAX_FILE_SIZE = 2 * 1024 * 1024 * 1024

export const LANGUAGES = [
  { value: 'auto', label: 'Auto-detect' },
  { value: 'en', label: 'English' },
  { value: 'fil', label: 'Filipino' },
  { value: 'es', label: 'Spanish' },
  { value: 'ja', label: 'Japanese' },
] as const

const AUDIO_EXTENSIONS = ['mp3', 'wav', 'm4a']

/** Lowercase text after the last dot, or '' when there is none (same as PHP's pathinfo) */
export function fileExtension(name: string): string {
  const dot = name.lastIndexOf('.')
  return dot === -1 ? '' : name.slice(dot + 1).toLowerCase()
}

export function isAcceptedFile(file: File): boolean {
  return (ACCEPTED_EXTENSIONS as readonly string[]).includes(fileExtension(file.name))
}

export function mediaKindOf(name: string): MediaKind {
  return AUDIO_EXTENSIONS.includes(fileExtension(name)) ? 'audio' : 'video'
}

export function languageLabel(value: string | undefined): string | undefined {
  return LANGUAGES.find((lang) => lang.value === value)?.label ?? value
}
