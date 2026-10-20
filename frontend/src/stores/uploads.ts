import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { api, errorMessage } from '@/lib/api'
import { uploadInChunks } from '@/services/chunkedUpload'
import {
  mediaKindOf,
  type SubtitleFormat,
  type UploadItem,
  type UploadResource,
} from '@/types/upload'

function fromResource(resource: UploadResource): UploadItem {
  const interrupted = resource.status === 'uploading'

  return {
    id: resource.id,
    serverId: resource.id,
    name: resource.name,
    kind: mediaKindOf(resource.name),
    size: resource.size,
    status: interrupted ? 'failed' : 'uploaded',
    error: interrupted ? 'Upload was interrupted. Remove it and try again.' : undefined,
    language: resource.language ?? undefined,
    format: resource.subtitle_format,
  }
}

export const useUploadsStore = defineStore('uploads', () => {
  const items = ref<UploadItem[]>([])
  const isLoading = ref(false)
  const loadError = ref<string | null>(null)

  // Not reactive: File objects and controllers only matter to the upload loop
  const pendingFiles = new Map<string, File>()
  const controllers = new Map<string, AbortController>()
  let isProcessingQueue = false

  const hasActiveUploads = computed(() =>
    items.value.some((item) => ['queued', 'uploading', 'processing'].includes(item.status)),
  )

  async function fetchRecent() {
    isLoading.value = true
    loadError.value = null

    try {
      const { data } = await api.get<{ data: UploadResource[] }>('/uploads')
      const known = new Set(items.value.map((item) => item.serverId))
      items.value.push(...data.data.filter((upload) => !known.has(upload.id)).map(fromResource))
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

    try {
      await uploadInChunks(file, {
        language: item.language ?? 'auto',
        format: item.format ?? 'srt',
        signal: controller.signal,
        onStarted: (resource) => (item.serverId = resource.id),
        onProgress: (percent) => (item.progress = percent),
        onAssembling: () => (item.status = 'processing'),
      })
      item.status = 'uploaded'
    } catch (error) {
      // Cancelled by the user: `remove` already took the row away
      if (controller.signal.aborted) return
      item.status = 'failed'
      item.error = errorMessage(error, 'Upload failed.')
    } finally {
      pendingFiles.delete(item.id)
      controllers.delete(item.id)
    }
  }

  /** Cancel an in-flight upload (if any) and delete it everywhere. */
  async function remove(id: string) {
    const item = items.value.find((candidate) => candidate.id === id)
    if (!item) return

    controllers.get(id)?.abort()
    pendingFiles.delete(id)
    items.value = items.value.filter((candidate) => candidate.id !== id)

    if (item.serverId) {
      try {
        await api.delete(`/uploads/${item.serverId}`)
      } catch {
        // Already gone, or the server is unreachable; abandoned uploads are pruned server-side
      }
    }
  }

  return { items, isLoading, loadError, hasActiveUploads, fetchRecent, enqueue, addRejected, remove }
})
