import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { isAxiosError } from 'axios'
import { api, errorMessage } from '@/lib/api'
import { isRetryable, resumeInChunks, uploadInChunks } from '@/services/chunkedUpload'
import { saveBlob } from '@/lib/download'
import { extractAudio, NoAudioTrackError } from '@/lib/extractAudio'
import { formatBytes } from '@/lib/format'
import { localId } from '@/lib/id'
import {
  isEmailing,
  MAX_FILE_SIZE,
  mediaKindOf,
  SERVER_PROCESSING_STATUSES,
  subtitleFileName,
  type ServerUploadStatus,
  type SubtitleFormat,
  type TranslationEngine,
  type UploadItem,
  type UploadResource,
  type UploadStatus,
} from '@/types/upload'

/** How often to ask the server about uploads it is still assembling or transcribing */
export const POLL_INTERVAL_MS = 2000

const INTERRUPTED_MESSAGE = 'Upload was interrupted. Resume to continue where it stopped.'

const CANNOT_PREPARE_MESSAGE =
  "This browser can't prepare large videos. Use Chrome, Edge or Firefox on a computer, or choose a file under 500 MB."

const STATUS_FROM_SERVER: Record<ServerUploadStatus, UploadStatus> = {
  uploading: 'interrupted',
  assembling: 'processing',
  uploaded: 'uploaded',
  transcribing: 'transcribing',
  translating: 'translating',
  ready: 'ready',
  failed: 'failed',
}

function errorFor(resource: UploadResource): string | undefined {
  if (resource.status === 'uploading') return INTERRUPTED_MESSAGE
  if (resource.status === 'failed') return resource.error ?? 'Processing failed.'
  return undefined
}

function fromResource(resource: UploadResource): UploadItem {
  return {
    id: resource.id,
    serverId: resource.id,
    name: resource.name,
    kind: mediaKindOf(resource.name),
    size: resource.size,
    lastModified: resource.last_modified ?? undefined,
    status: STATUS_FROM_SERVER[resource.status],
    progress: resource.progress ?? undefined,
    error: errorFor(resource),
    language: resource.language ?? undefined,
    translateTo: resource.translate_to ?? undefined,
    translationEngine: resource.translation_engine ?? undefined,
    translatedWith: resource.translated_with ?? undefined,
    format: resource.subtitle_format,
    downloadUrl: resource.download_url,
    expiresAt: resource.expires_at ?? undefined,
    audioOnly: resource.extension === 'mka',
    emailWhenReady: resource.email_when_ready,
    emailStatus: resource.email_status ?? undefined,
    emailError: resource.email_error ?? undefined,
  }
}

/** Copy the server's view of an upload onto a row the store already shows. */
function applyResource(item: UploadItem, resource: UploadResource) {
  item.status = STATUS_FROM_SERVER[resource.status]
  item.progress = resource.progress ?? undefined
  item.error = errorFor(resource)
  item.downloadUrl = resource.download_url
  item.expiresAt = resource.expires_at ?? undefined
  item.translatedWith = resource.translated_with ?? undefined
  item.emailWhenReady = resource.email_when_ready
  item.emailStatus = resource.email_status ?? undefined
  item.emailError = resource.email_error ?? undefined
}

/** Optional choices made on the upload form */
export interface EnqueueOptions {
  emailWhenReady?: boolean
  translateTo?: string
  translationEngine?: TranslationEngine
}

/** An emailed upload that the server has since deleted, to tell the user it arrived */
export interface EmailedUpload {
  id: string
  name: string
}

export const useUploadsStore = defineStore('uploads', () => {
  const items = ref<UploadItem[]>([])
  const isLoading = ref(false)
  const loadError = ref<string | null>(null)
  /** Subtitles the server has emailed (and then deleted), until the user dismisses the notice */
  const emailed = ref<EmailedUpload[]>([])
  /** Engines the server can translate with; Enhanced ("azure") only when it's configured */
  const translationEngines = ref<TranslationEngine[]>(['local'])

  // Not reactive: File objects and controllers only matter to the upload loop.
  // An interrupted upload keeps its File here so it can resume without re-picking it.
  const pendingFiles = new Map<string, File>()
  /** Audio copied out of a video in pendingFiles; this is what gets uploaded */
  const preparedAudio = new Map<string, File>()
  /** Videos the user agreed to upload whole because their audio couldn't be prepared */
  const uploadWhole = new Set<string>()
  const controllers = new Map<string, AbortController>()
  let isProcessingQueue = false
  let pollTimer: ReturnType<typeof setTimeout> | null = null

  /** The browser must stay on the page for these; the server handles everything after */
  const hasActiveUploads = computed(() =>
    items.value.some((item) => ['queued', 'preparing', 'confirm_full_upload', 'uploading'].includes(item.status)),
  )

  /** A video whose audio couldn't be prepared, waiting for the user to allow uploading it whole */
  const nextFullUploadConfirmation = computed(() =>
    items.value.find((item) => item.status === 'confirm_full_upload'),
  )

  /**
   * Ready subtitles wait for the user to download or email them, the one that finished
   * first (earliest deletion time) first; ties keep list order. Ones being emailed don't.
   */
  const nextReady = computed(() =>
    items.value
      .filter((item) => item.status === 'ready' && !isEmailing(item))
      .reduce<UploadItem | undefined>(
        (first, item) => (!first || (item.expiresAt ?? '~') < (first.expiresAt ?? '~') ? item : first),
        undefined,
      ),
  )

  /** Load what the server still has: uploads in progress, waiting to be downloaded, or failed. */
  async function fetchRecent() {
    isLoading.value = true
    loadError.value = null

    try {
      const { data } = await api.get<{ data: UploadResource[] }>('/uploads')
      const known = new Set(items.value.map((item) => item.serverId))
      items.value.push(...data.data.filter((upload) => !known.has(upload.id)).map(fromResource))
      startPolling()
    } catch (error) {
      loadError.value = errorMessage(error, 'Could not load your uploads.')
    } finally {
      isLoading.value = false
    }
  }

  /** Whether Enhanced translation can be offered; Standard always can */
  async function fetchTranslationEngines() {
    try {
      const { data } = await api.get<{ data: TranslationEngine[] }>('/translation-engines')
      translationEngines.value = data.data
    } catch {
      translationEngines.value = ['local']
    }
  }

  function enqueue(files: File[], language: string, format: SubtitleFormat, options: EnqueueOptions = {}) {
    const { emailWhenReady = false, translateTo, translationEngine } = options

    const queued = files.map((file) => {
      const id = localId()
      pendingFiles.set(id, file)

      return {
        id,
        name: file.name,
        kind: mediaKindOf(file.name),
        size: file.size,
        lastModified: file.lastModified,
        status: 'queued' as const,
        language,
        format,
        emailWhenReady,
        translateTo,
        translationEngine: translateTo ? (translationEngine ?? 'local') : undefined,
      }
    })

    items.value.unshift(...queued)
    void processQueue()
  }

  /** Show a file that was rejected before upload (wrong type, too large). */
  function addRejected(file: File, reason: string) {
    items.value.unshift({
      id: localId(),
      name: file.name,
      kind: mediaKindOf(file.name),
      size: file.size,
      status: 'failed',
      error: reason,
    })
  }

  /** Whether an interrupted upload can resume without the user picking the file again. */
  function hasFileFor(id: string): boolean {
    return pendingFiles.has(id)
  }

  /**
   * Resume an interrupted upload. Pass the file when the page was reloaded since
   * (the browser cannot keep it); it must be the same file that was started.
   * Returns an error message when the file does not match.
   */
  function resume(id: string, file?: File): string | undefined {
    const item = items.value.find((candidate) => candidate.id === id)
    if (!item || item.status !== 'interrupted' || !item.serverId) return undefined

    const source = file ?? pendingFiles.get(id)
    if (!source) return 'Choose the file to resume.'

    // Only a video's audio was uploaded: the size to compare is the audio's, checked once it's prepared again
    const sizeMatches = item.audioOnly && mediaKindOf(source.name) === 'video' ? true : source.size === item.size
    const matches =
      source.name === item.name &&
      sizeMatches &&
      (item.lastModified === undefined || source.lastModified === item.lastModified)

    if (!matches) {
      item.error = item.audioOnly
        ? `That is a different file. Choose "${item.name}" to resume.`
        : `That is a different file. Choose "${item.name}" (${formatBytes(item.size)}) to resume.`
      return item.error
    }

    pendingFiles.set(id, source)
    item.status = 'queued'
    item.error = undefined
    void processQueue()
    return undefined
  }

  /** Upload queued files one at a time; each file already sends chunks in parallel. */
  async function processQueue() {
    if (isProcessingQueue) return
    isProcessingQueue = true

    try {
      let next: UploadItem | undefined
      while ((next = items.value.find((item) => item.status === 'queued'))) {
        await upload(next)
      }
    } finally {
      isProcessingQueue = false
    }
  }

  /** The user agreed to upload the whole video after its audio couldn't be prepared. */
  function confirmFullUpload(id: string) {
    const item = items.value.find((candidate) => candidate.id === id)
    if (!item || item.status !== 'confirm_full_upload') return

    uploadWhole.add(id)
    item.status = 'queued'
    item.error = undefined
    void processQueue()
  }

  /**
   * What to upload for a queued file: an audio file as it is, and for a video only its
   * audio, copied out in the browser. Null when there is nothing to upload (yet): the
   * row then says why, or waits for the user to allow uploading the whole video.
   */
  async function fileToUpload(item: UploadItem, original: File, signal: AbortSignal): Promise<File | null> {
    if (mediaKindOf(original.name) !== 'video' || uploadWhole.has(item.id)) return original

    const prepared = preparedAudio.get(item.id)
    if (prepared) return prepared

    item.status = 'preparing'
    item.progress = 0

    try {
      const audio = await extractAudio(original, { signal, onProgress: (percent) => (item.progress = percent) })

      if (audio.size > MAX_FILE_SIZE) {
        return fail(item, `The audio of this video is larger than the ${formatBytes(MAX_FILE_SIZE)} limit.`)
      }
      // Resuming after a reload: the audio prepared again must be what the server has
      if (item.serverId && audio.size !== item.size) {
        return fail(item, "This video doesn't match the interrupted upload. Remove it and add the video again.")
      }

      preparedAudio.set(item.id, audio)
      item.audioOnly = true
      return audio
    } catch (error) {
      if (signal.aborted) throw error
      if (error instanceof NoAudioTrackError) return fail(item, error.message)

      // Most likely not enough memory. Uploading the whole video works like before, if the user agrees.
      if (!item.serverId && original.size <= MAX_FILE_SIZE) {
        item.status = 'confirm_full_upload'
        item.progress = undefined
        return null
      }
      return fail(item, CANNOT_PREPARE_MESSAGE)
    }
  }

  function fail(item: UploadItem, reason: string): null {
    item.status = 'failed'
    item.error = reason
    item.progress = undefined
    forgetFiles(item.id)
    return null
  }

  function forgetFiles(id: string) {
    pendingFiles.delete(id)
    preparedAudio.delete(id)
    uploadWhole.delete(id)
  }

  async function upload(item: UploadItem) {
    const original = pendingFiles.get(item.id)
    if (!original) return

    const controller = new AbortController()
    controllers.set(item.id, controller)

    let file: File | null
    try {
      file = await fileToUpload(item, original, controller.signal)
    } catch {
      // Cancelled by the user while preparing: `remove` already took the row away
      controllers.delete(item.id)
      return
    }
    if (!file) {
      controllers.delete(item.id)
      return
    }

    item.status = 'uploading'
    item.progress = 0

    const callbacks = {
      signal: controller.signal,
      onProgress: (percent: number) => (item.progress = percent),
      onAssembling: () => {
        item.status = 'processing'
        item.progress = 0
      },
    }

    try {
      const accepted = item.serverId
        ? await resumeInChunks(file, item.serverId, callbacks)
        : await uploadInChunks(file, {
            ...callbacks,
            language: item.language ?? 'auto',
            format: item.format ?? 'srt',
            emailWhenReady: item.emailWhenReady ?? false,
            translateTo: item.translateTo,
            translationEngine: item.translationEngine,
            displayName: file === original ? undefined : original.name,
            onStarted: (resource) => (item.serverId = resource.id),
          })
      applyResource(item, accepted)
      forgetFiles(item.id)
      startPolling()
    } catch (error) {
      // Cancelled by the user: `remove` already took the row away
      if (controller.signal.aborted) return

      if (item.serverId && isRetryable(error)) {
        item.status = 'interrupted'
        item.error = INTERRUPTED_MESSAGE
      } else {
        item.status = 'failed'
        item.error = errorMessage(error, 'Upload failed.')
        forgetFiles(item.id)
      }
    } finally {
      controllers.delete(item.id)
    }
  }

  /** Poll the server for rows it is still working on, until none are left. */
  function startPolling() {
    if (pollTimer !== null) return
    if (!items.value.some(isServerProcessing)) return

    pollTimer = setTimeout(async () => {
      await refreshProcessing()
      pollTimer = null
      startPolling()
    }, POLL_INTERVAL_MS)
  }

  function isServerProcessing(item: UploadItem): boolean {
    return item.serverId !== undefined && (SERVER_PROCESSING_STATUSES.includes(item.status) || isEmailing(item))
  }

  async function refreshProcessing() {
    await Promise.all(
      items.value.filter(isServerProcessing).map(async (item) => {
        try {
          const { data } = await api.get<{ data: UploadResource }>(`/uploads/${item.serverId}`)
          applyResource(item, data.data)
        } catch (error) {
          if (isAxiosError(error) && error.response?.status === 404) {
            if (isEmailing(item)) {
              // Sent: the server deletes an upload once its email is out
              emailed.value.push({ id: item.id, name: item.name })
              items.value = items.value.filter((candidate) => candidate.id !== item.id)
              return
            }
            item.status = 'failed'
            item.error = 'This upload no longer exists.'
          }
          // Anything else (network blip, 5xx): try again on the next poll
        }
      }),
    )
  }

  /**
   * Fetch the subtitles and save them, then delete the upload from the server:
   * nothing is kept once the user has the file. A failed download keeps
   * everything so the user can try again.
   */
  async function download(id: string) {
    const item = items.value.find((candidate) => candidate.id === id)
    if (!item?.downloadUrl || item.downloading) return

    item.downloading = true
    item.downloadError = undefined

    try {
      const { data } = await api.get<Blob>(item.downloadUrl, { responseType: 'blob' })
      saveBlob(data, subtitleFileName(item.name, item.format ?? 'srt', item.translateTo))
    } catch (error) {
      item.downloading = false
      // The error body is a Blob here, so there is no server message to show
      item.downloadError =
        isAxiosError(error) && error.response?.status === 404
          ? 'These subtitles are no longer available.'
          : 'The subtitles could not be downloaded. Please try again.'
      return
    }

    try {
      await api.delete(`/uploads/${item.serverId}`)
    } catch {
      // The user has the file either way; the server deletes leftovers after RETENTION_HOURS
    }

    items.value = items.value.filter((candidate) => candidate.id !== id)
  }

  /**
   * Ask the server to email the subtitles to the signed-in user. The row then shows
   * "Emailing" until the server has sent them and deleted the upload (polling sees 404).
   */
  async function email(id: string) {
    const item = items.value.find((candidate) => candidate.id === id)
    if (!item?.serverId || item.status !== 'ready' || item.emailRequesting || isEmailing(item)) return

    item.emailRequesting = true
    item.emailError = undefined
    item.downloadError = undefined

    try {
      const { data } = await api.post<{ data: UploadResource }>(`/uploads/${item.serverId}/email`)
      applyResource(item, data.data)
      startPolling()
    } catch (error) {
      item.emailError = errorMessage(error, "We couldn't email your subtitles. Please try again.")
    } finally {
      item.emailRequesting = false
    }
  }

  function dismissEmailed(id: string) {
    emailed.value = emailed.value.filter((notice) => notice.id !== id)
  }

  /**
   * Cancel an in-flight upload (if any) and delete it everywhere. Resolves once
   * the server has deleted it; rejects with a readable message when it could not,
   * leaving the row in place so the user can try again.
   */
  async function remove(id: string) {
    const item = items.value.find((candidate) => candidate.id === id)
    if (!item) return

    const wasUploading = controllers.has(id)
    controllers.get(id)?.abort()

    if (item.serverId) {
      try {
        await api.delete(`/uploads/${item.serverId}`)
      } catch (error) {
        // 404: it is already gone, which is what the user wanted
        if (!(isAxiosError(error) && error.response?.status === 404)) {
          if (wasUploading) {
            // The upload loop stopped; the kept File lets the user resume instead
            item.status = 'interrupted'
            item.error = INTERRUPTED_MESSAGE
          }
          throw new Error(errorMessage(error, 'Could not delete the upload. Please try again.'))
        }
      }
    }

    forgetFiles(id)
    items.value = items.value.filter((candidate) => candidate.id !== id)
  }

  return {
    items,
    isLoading,
    loadError,
    emailed,
    translationEngines,
    hasActiveUploads,
    nextReady,
    nextFullUploadConfirmation,
    fetchRecent,
    fetchTranslationEngines,
    enqueue,
    addRejected,
    hasFileFor,
    resume,
    confirmFullUpload,
    download,
    email,
    dismissEmailed,
    remove,
  }
})
