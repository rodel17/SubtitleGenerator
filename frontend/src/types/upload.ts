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
  /** Translating the transcript into the chosen language */
  | 'translating'
  | 'ready'
  | 'failed'

export type MediaKind = 'video' | 'audio'

/** Statuses the server is still working through; the store polls these */
export const SERVER_PROCESSING_STATUSES: readonly UploadStatus[] = [
  'processing',
  'uploaded',
  'transcribing',
  'translating',
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
  /** Language the subtitles are translated into; unset keeps the spoken language */
  translateTo?: string
  format?: SubtitleFormat
  error?: string
  /** Where to download the subtitles, once ready */
  downloadUrl?: string
  /** ISO time the server deletes the subtitles if they are never downloaded */
  expiresAt?: string
  /** The subtitles are being fetched right now */
  downloading?: boolean
  /** Why the last download did not work; the subtitles are still there to try again */
  downloadError?: string
  /** Chosen before uploading: the server emails the subtitles as soon as they're ready */
  emailWhenReady?: boolean
  /** The server is emailing the subtitles, or could not */
  emailStatus?: EmailStatus
  /** Why emailing did not work; the subtitles can still be downloaded */
  emailError?: string
  /** "Email to me" was clicked and the server hasn't answered yet */
  emailRequesting?: boolean
}

export type EmailStatus = 'sending' | 'failed'

/**
 * Ready subtitles on their way to the user's inbox. These aren't offered for
 * download; the server deletes the upload once the email is sent.
 */
export function isEmailing(item: UploadItem): boolean {
  return (
    item.status === 'ready' &&
    (item.emailStatus === 'sending' || (item.emailWhenReady === true && item.emailStatus === undefined))
  )
}

/**
 * "Team Interview.mp4" + "srt" -> "Team Interview.srt", as the API names the file.
 * Both formats come as one zip: "Team Interview.zip". Translated subtitles carry
 * the language: "Team Interview.fil.srt".
 */
export function subtitleFileName(mediaName: string, format: SubtitleFormat, translateTo?: string): string {
  const dot = mediaName.lastIndexOf('.')
  const base = dot > 0 ? mediaName.slice(0, dot) : mediaName
  return `${base}${translateTo ? `.${translateTo}` : ''}.${format === 'both' ? 'zip' : format}`
}

/** Status of an upload as the Laravel API reports it */
export type ServerUploadStatus =
  | 'uploading'
  | 'assembling'
  | 'uploaded'
  | 'transcribing'
  | 'translating'
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
  translate_to: string | null
  /** "local" (translated on our server) when translating, otherwise null */
  translation_engine: string | null
  subtitle_format: SubtitleFormat
  email_when_ready: boolean
  status: ServerUploadStatus
  /** 0–100 while the server is assembling, transcribing or translating, otherwise null */
  progress: number | null
  error: string | null
  email_status: EmailStatus | null
  email_error: string | null
  /** Subtitle download (one file, or a zip for both formats), only once the upload is ready */
  download_url?: string
  created_at: string
  completed_at: string | null
  /** When a ready or failed upload is deleted if nobody downloads it; null while processing */
  expires_at: string | null
}

/** Must match `uploads.allowed_extensions` in backend/config/uploads.php ("ts" = MPEG transport stream) */
export const ACCEPTED_EXTENSIONS = ['mp4', 'mov', 'mkv', 'ts', 'mp3', 'wav', 'm4a'] as const

/** Must match `uploads.max_size` in backend/config/uploads.php */
export const MAX_FILE_SIZE = 500 * 1024 * 1024

/** Must match `uploads.retention_hours` in backend/config/uploads.php */
export const RETENTION_HOURS = 6

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

/** Must match `translation.targets` in backend/config/translation.php */
export const TRANSLATION_TARGETS = [
  { value: 'fil', label: 'Filipino' },
  { value: 'en', label: 'English' },
  { value: 'ja', label: 'Japanese' },
  { value: 'zh', label: 'Chinese (Simplified)' },
  { value: 'ko', label: 'Korean' },
] as const

export function translationLabel(value: string | undefined): string | undefined {
  return TRANSLATION_TARGETS.find((target) => target.value === value)?.label ?? value
}
