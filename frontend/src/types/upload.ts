export type SubtitleFormat = 'srt' | 'vtt' | 'both'

export type UploadStatus =
  | 'queued'
  | 'uploading'
  /** Stopped part-way; the server keeps the chunks it has, so it can be resumed */
  | 'interrupted'
  /** The server is joining the chunks into one file */
  | 'processing'
  /** File is complete and waiting for a transcription worker */
  | 'uploaded'
  | 'transcribing'
  | 'ready'
  | 'failed'

export type MediaKind = 'video' | 'audio'

/** Statuses the server is still working through; the store polls these */
export const SERVER_PROCESSING_STATUSES: readonly UploadStatus[] = [
  'processing',
  'uploaded',
  'transcribing',
]

export interface UploadItem {
  /** Client-side id; stays stable while the server id is still unknown */
  id: string
  /** Id of the upload on the API, once it has been started */
  serverId?: string
  name: string
  kind: MediaKind
  size: number
  /** `File.lastModified` as reported to the server; used to check a file picked to resume */
  lastModified?: number
  status: UploadStatus
  /** 0–100 while uploading, processing or transcribing */
  progress?: number
  language?: string
  format?: SubtitleFormat
  error?: string
  /** Download URL per generated format, once ready */
  subtitles?: Partial<Record<SubtitleFileFormat, string>>
  /** Formats whose file is being fetched right now */
  downloading?: SubtitleFileFormat[]
  /** Why the last download did not work; the upload itself is still fine */
  downloadError?: string
}

/** A format a subtitle file can be downloaded in ("both" produces one of each) */
export type SubtitleFileFormat = 'srt' | 'vtt'

/** "Team Interview.mp4" + "srt" -> "Team Interview.srt", as the API names the file */
export function subtitleFileName(mediaName: string, format: SubtitleFileFormat): string {
  const dot = mediaName.lastIndexOf('.')
  return `${dot > 0 ? mediaName.slice(0, dot) : mediaName}.${format}`
}

/** Status of an upload as the Laravel API reports it */
export type ServerUploadStatus =
  | 'uploading'
  | 'assembling'
  | 'uploaded'
  | 'transcribing'
  | 'ready'
  | 'failed'

/** Shape of `UploadResource` returned by the Laravel API */
export interface UploadResource {
  id: string
  name: string
  extension: string
  mime_type: string | null
  last_modified: number | null
  size: number
  chunk_size: number
  total_chunks: number
  language: string | null
  subtitle_format: SubtitleFormat
  status: ServerUploadStatus
  /** 0–100 while the server is assembling or transcribing, otherwise null */
  progress: number | null
  error: string | null
  /** Download URLs, only once the upload is ready */
  subtitles?: Partial<Record<'srt' | 'vtt', string>>
  created_at: string
  completed_at: string | null
}

/** Must match `uploads.allowed_extensions` in backend/config/uploads.php ("ts" = MPEG transport stream) */
export const ACCEPTED_EXTENSIONS = ['mp4', 'mov', 'mkv', 'ts', 'mp3', 'wav', 'm4a'] as const

/** Must match `uploads.max_size` in backend/config/uploads.php */
export const MAX_FILE_SIZE = 500 * 1024 * 1024

/** Must match `uploads.media_retention_hours` in backend/config/uploads.php */
export const MEDIA_RETENTION_HOURS = 24

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
