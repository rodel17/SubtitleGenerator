import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { isAxiosError } from 'axios'
import { api, errorMessage } from '@/lib/api'
import { isRetryable, resumeInChunks, uploadInChunks } from '@/services/chunkedUpload'
import { saveBlob } from '@/lib/download'
import { formatBytes } from '@/lib/format'
import {
  mediaKindOf,
  SERVER_PROCESSING_STATUSES,
  subtitleFileName,
  type ServerUploadStatus,
  type SubtitleFileFormat,
  type SubtitleFormat,
  type UploadItem,
  type UploadResource,
  type UploadStatus,
} from '@/types/upload'

/** How often to ask the server about uploads it is still assembling or transcribing */
export const POLL_INTERVAL_MS = 2000

const INTERRUPTED_MESSAGE = 'Upload was interrupted. Resume to continue where it stopped.'

const STATUS_FROM_SERVER: Record<ServerUploadStatus, UploadStatus> = {
  uploading: 'interrupted',
  assembling: 'processing',
  uploaded: 'uploaded',
  transcribing: 'transcribing',
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
    format: resource.subtitle_format,
    subtitles: resource.subtitles,
  }
}

/** Copy the server's view of an upload onto a row the store already shows. */
function applyResource(item: UploadItem, resource: UploadResource) {
  item.status = STATUS_FROM_SERVER[resource.status]
  item.progress = resource.progress ?? undefined
  item.error = errorFor(resource)
  item.subtitles = resource.subtitles
}

export const useUploadsStore = defineStore('uploads', () => {
  const items = ref<UploadItem[]>([])
  const isLoading = ref(false)
  const loadError = ref<string | null>(null)

  // Not reactive: File objects and controllers only matter to the upload loop.
  // An interrupted upload keeps its File here so it can resume without re-picking it.
  const pendingFiles = new Map<string, File>()
  const controllers = new Map<string, AbortController>()
  let isProcessingQueue = false
  let pollTimer: ReturnType<typeof setTimeout> | null = null

  /** The browser must stay on the page for these; the server handles everything after */
  const hasActiveUploads = computed(() =>
    items.value.some((item) => ['queued', 'uploading'].includes(item.status)),
  )

  async function fetchRecent() {
    isLoading.value = true
    loadError.value = null

    try {
      const { data } = await api.get<{ data: UploadResource[] }>('/uploads')
      const known = new Set(items.value.map((item) => item.serverId))
      items.value.push(...data.data.filter((upload) => !known.has(upload.id)).map(fromResource))
      startPolling()
    } catch (error) {
      loadError.value = errorMessage(error, 'Could not load recent uploads.')
    } finally {
      isLoading.value = false
    }
  }

  function enqueue(files: File[], language: string, format: SubtitleFormat) {
    const queued = files.map((file) => {
      const id = crypto.randomUUID()
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
      }
    })

    items.value.unshift(...queued)
    void processQueue()
  }

  /** Show a file that was rejected before upload (wrong type, too large). */
  function addRejected(file: File, reason: string) {
    items.value.unshift({
      id: crypto.randomUUID(),
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

    const matches =
      source.name === item.name &&
      source.size === item.size &&
      (item.lastModified === undefined || source.lastModified === item.lastModified)

    if (!matches) {
      item.error = `That is a different file. Choose "${item.name}" (${formatBytes(item.size)}) to resume.`
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

  async function upload(item: UploadItem) {
    const file = pendingFiles.get(item.id)
    if (!file) return

    const controller = new AbortController()
    controllers.set(item.id, controller)
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
            onStarted: (resource) => (item.serverId = resource.id),
          })
      applyResource(item, accepted)
      pendingFiles.delete(item.id)
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
        pendingFiles.delete(item.id)
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
    return item.serverId !== undefined && SERVER_PROCESSING_STATUSES.includes(item.status)
  }

  async function refreshProcessing() {
    await Promise.all(
      items.value.filter(isServerProcessing).map(async (item) => {
        try {
          const { data } = await api.get<{ data: UploadResource }>(`/uploads/${item.serverId}`)
          applyResource(item, data.data)
        } catch (error) {
          if (isAxiosError(error) && error.response?.status === 404) {
            item.status = 'failed'
            item.error = 'This upload no longer exists.'
          }
          // Anything else (network blip, 5xx): try again on the next poll
        }
      }),
    )
  }

  /** Fetch a subtitle file and save it, marking the format as downloading meanwhile. */
  async function download(id: string, format: SubtitleFileFormat) {
    const item = items.value.find((candidate) => candidate.id === id)
    const url = item?.subtitles?.[format]
    if (!item || !url || item.downloading?.includes(format)) return

    item.downloading = [...(item.downloading ?? []), format]
    item.downloadError = undefined

    try {
      const { data } = await api.get<Blob>(url, { responseType: 'blob' })
      saveBlob(data, subtitleFileName(item.name, format))
    } catch {
      // The error body is a Blob here, so there is no server message to show
      item.downloadError = `The ${format.toUpperCase()} file could not be downloaded. Please try again.`
    } finally {
      item.downloading = item.downloading.filter((candidate) => candidate !== format)
    }
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

    pendingFiles.delete(id)
    items.value = items.value.filter((candidate) => candidate.id !== id)
  }

  return {
    items,
    isLoading,
    loadError,
    hasActiveUploads,
    fetchRecent,
    enqueue,
    addRejected,
    hasFileFor,
    resume,
    download,
    remove,
  }
})
