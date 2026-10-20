export type SubtitleFormat = 'srt' | 'vtt' | 'both'

export type UploadStatus = 'queued' | 'uploading' | 'transcribing' | 'ready' | 'failed'

export type MediaKind = 'video' | 'audio'

export interface UploadItem {
  id: string
  name: string
  kind: MediaKind
  status: UploadStatus
  /** 0–100, only meaningful while uploading */
  progress?: number
  language?: string
  format?: SubtitleFormat
  error?: string
}

export const ACCEPTED_EXTENSIONS = ['mp4', 'mov', 'mkv', 'mp3', 'wav', 'm4a'] as const

const AUDIO_EXTENSIONS = ['mp3', 'wav', 'm4a']

export function fileExtension(name: string): string {
  return name.split('.').pop()?.toLowerCase() ?? ''
}

export function isAcceptedFile(file: File): boolean {
  return (ACCEPTED_EXTENSIONS as readonly string[]).includes(fileExtension(file.name))
}

export function mediaKindOf(name: string): MediaKind {
  return AUDIO_EXTENSIONS.includes(fileExtension(name)) ? 'audio' : 'video'
}
