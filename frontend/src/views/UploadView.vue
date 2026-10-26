<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import FileDropzone from '@/components/upload/FileDropzone.vue'
import FormatToggle from '@/components/upload/FormatToggle.vue'
import SelectedFilePreview from '@/components/upload/SelectedFilePreview.vue'
import SubtitlesReadyDialog from '@/components/upload/SubtitlesReadyDialog.vue'
import UploadListItem from '@/components/upload/UploadListItem.vue'
import { formatBytes } from '@/lib/format'
import { localId } from '@/lib/id'
import { useAuthStore } from '@/stores/auth'
import { useUploadsStore } from '@/stores/uploads'
import {
  ACCEPTED_EXTENSIONS,
  isAcceptedFile,
  isEmailing,
  LANGUAGES,
  MAX_FILE_SIZE,
  RETENTION_HOURS,
  SERVER_PROCESSING_STATUSES,
  type SubtitleFormat,
  type UploadItem,
} from '@/types/upload'

const uploads = useUploadsStore()
const auth = useAuthStore()

interface SelectedFile {
  id: string
  file: File
}

const selectedFiles = ref<SelectedFile[]>([])
const language = ref<string>(LANGUAGES[0].value)
const format = ref<SubtitleFormat>('srt')
/** Email the subtitles as soon as they're ready, so the page can be closed */
const emailWhenReady = ref(false)

function addFiles(files: File[]) {
  for (const file of files) {
    if (!isAcceptedFile(file)) {
      uploads.addRejected(
        file,
        `Unsupported format. Try ${ACCEPTED_EXTENSIONS.slice(0, 3)
          .map((ext) => ext.toUpperCase())
          .join(', ')}.`,
      )
    } else if (file.size > MAX_FILE_SIZE) {
      uploads.addRejected(file, `File is larger than the ${formatBytes(MAX_FILE_SIZE)} limit.`)
    } else {
      selectedFiles.value.push({ id: localId(), file })
    }
  }
}

function removeSelected(id: string) {
  selectedFiles.value = selectedFiles.value.filter((selected) => selected.id !== id)
}

function clearSelection() {
  selectedFiles.value = []
}

function generate() {
  uploads.enqueue(
    selectedFiles.value.map((selected) => selected.file),
    language.value,
    format.value,
    emailWhenReady.value,
  )
  selectedFiles.value = []
}

// Deleting something the server already has asks first, and waits for the server
const pendingRemoval = ref<UploadItem | null>(null)
const isRemoving = ref(false)
const removalError = ref<string | null>(null)

const removalCopy = computed(() => {
  const item = pendingRemoval.value
  if (!item) return null

  if (item.status === 'uploading') {
    return {
      title: 'Stop this upload?',
      message: `The upload of "${item.name}" will stop and the part already sent will be deleted.`,
      confirmLabel: 'Stop upload',
      busyLabel: 'Stopping…',
      cancelLabel: 'Keep uploading',
    }
  }

  if (SERVER_PROCESSING_STATUSES.includes(item.status)) {
    return {
      title: 'Stop processing?',
      message: `Processing of "${item.name}" will stop and the file will be deleted. This can't be undone.`,
      confirmLabel: 'Stop and delete',
      busyLabel: 'Stopping…',
      cancelLabel: 'Keep going',
    }
  }

  return {
    title: 'Delete this upload?',
    message: `"${item.name}" and everything uploaded so far will be deleted. This can't be undone.`,
    confirmLabel: 'Delete',
    busyLabel: 'Deleting…',
    cancelLabel: 'Cancel',
  }
})

function requestRemove(id: string) {
  const item = uploads.items.find((candidate) => candidate.id === id)
  if (!item) return

  // Nothing on the server yet (rejected or still queued): nothing to lose
  if (!item.serverId) {
    void uploads.remove(id)
    return
  }

  removalError.value = null
  pendingRemoval.value = item
}

async function confirmRemove() {
  if (!pendingRemoval.value) return

  isRemoving.value = true
  removalError.value = null

  try {
    await uploads.remove(pendingRemoval.value.id)
    pendingRemoval.value = null
  } catch (error) {
    removalError.value = error instanceof Error ? error.message : 'Could not delete the upload.'
  } finally {
    isRemoving.value = false
  }
}

function cancelRemove() {
  pendingRemoval.value = null
  removalError.value = null
}

// Ready subtitles are only listed while being emailed; otherwise the dialog asks for them
const listedItems = computed(() =>
  uploads.items.filter((item) => item.status !== 'ready' || isEmailing(item)),
)
const isDiscarding = ref(false)
const discardError = ref<string | null>(null)

async function discard(id: string) {
  isDiscarding.value = true
  discardError.value = null

  try {
    await uploads.remove(id)
  } catch (error) {
    discardError.value = error instanceof Error ? error.message : 'Could not discard the subtitles.'
  } finally {
    isDiscarding.value = false
  }
}

// After a reload the browser no longer has the file, so the user picks it again
const resumePicker = ref<HTMLInputElement | null>(null)
const resumingId = ref<string | null>(null)

function resume(id: string) {
  if (uploads.hasFileFor(id)) {
    uploads.resume(id)
    return
  }

  resumingId.value = id
  resumePicker.value?.click()
}

function resumeWithPickedFile(event: Event) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]

  if (file && resumingId.value) uploads.resume(resumingId.value, file)

  resumingId.value = null
  input.value = ''
}

// Leaving the page would kill in-flight uploads, so ask first
function warnBeforeLeaving(event: BeforeUnloadEvent) {
  if (uploads.hasActiveUploads) event.preventDefault()
}

onMounted(() => {
  window.addEventListener('beforeunload', warnBeforeLeaving)
  if (!uploads.items.length) void uploads.fetchRecent()
})

onBeforeUnmount(() => window.removeEventListener('beforeunload', warnBeforeLeaving))
</script>

<template>
  <main class="mx-auto flex max-w-240 flex-col gap-8 px-6 pt-12 pb-16">
    <div class="flex flex-col gap-2">
      <h1 class="text-[28px] font-bold tracking-tight">Upload media</h1>
      <p class="text-[15px] leading-normal text-slate-600">
        Add a video or audio file and we'll transcribe it into a subtitle track you can download.
      </p>
    </div>

    <section class="flex flex-col gap-6 rounded-2xl border border-slate-200 bg-white p-6">
      <FileDropzone @add="addFiles" />

      <div v-if="selectedFiles.length" class="flex flex-col gap-3">
        <h2 class="text-[13px] font-semibold text-slate-700">
          Selected ({{ selectedFiles.length }})
        </h2>
        <div class="grid grid-cols-[repeat(auto-fill,minmax(280px,1fr))] gap-3">
          <SelectedFilePreview
            v-for="selected in selectedFiles"
            :key="selected.id"
            :file="selected.file"
            @remove="removeSelected(selected.id)"
          />
        </div>
      </div>

      <div class="grid grid-cols-[repeat(auto-fit,minmax(240px,1fr))] gap-5">
        <div class="flex flex-col gap-2">
          <label for="language" class="text-[13px] font-semibold text-slate-700">
            Spoken language
          </label>
          <select
            id="language"
            v-model="language"
            class="h-11 rounded-[10px] border border-slate-300 bg-white px-3 text-sm focus:border-accent focus:ring-2 focus:ring-accent/20 focus:outline-none"
          >
            <option v-for="lang in LANGUAGES" :key="lang.value" :value="lang.value">
              {{ lang.label }}
            </option>
          </select>
        </div>

        <FormatToggle v-model="format" />
      </div>

      <label class="flex cursor-pointer items-start gap-2.5 text-sm text-slate-700">
        <input
          v-model="emailWhenReady"
          type="checkbox"
          class="mt-0.5 size-4 shrink-0 cursor-pointer accent-accent"
          data-test="email-when-ready"
        />
        <span>
          Email the subtitles to
          <span class="font-medium break-all text-slate-900">{{ auth.user?.email ?? 'me' }}</span>
          when they're ready, so you can close this page
        </span>
      </label>

      <div
        class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-5"
      >
        <span class="text-[13px] text-slate-600">
          Files are processed privately and deleted from our servers as soon as you download or
          email the subtitles, or {{ RETENTION_HOURS }} hours after processing if you don't.
        </span>
        <div class="flex gap-2.5">
          <button
            type="button"
            class="h-11 cursor-pointer rounded-[10px] border border-slate-300 bg-white px-4.5 text-sm font-semibold text-slate-700 hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50"
            :disabled="!selectedFiles.length"
            @click="clearSelection"
          >
            Clear
          </button>
          <button
            type="button"
            class="h-11 cursor-pointer rounded-[10px] bg-accent px-5 text-sm font-semibold text-white hover:bg-accent-hover disabled:cursor-not-allowed disabled:opacity-50"
            :disabled="!selectedFiles.length"
            @click="generate"
          >
            Generate subtitles
          </button>
        </div>
      </div>
    </section>

    <section class="flex flex-col gap-3">
      <h2 class="text-base font-semibold">In progress</h2>

      <p
        v-for="notice in uploads.emailed"
        :key="notice.id"
        role="status"
        class="flex items-start justify-between gap-3 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-900"
      >
        <span>
          The subtitles for <span class="font-semibold break-all">{{ notice.name }}</span> were
          emailed to you and deleted from our servers.
        </span>
        <button
          type="button"
          :aria-label="`Dismiss notice for ${notice.name}`"
          class="shrink-0 cursor-pointer rounded-md p-0.5 text-green-800 hover:bg-green-100"
          @click="uploads.dismissEmailed(notice.id)"
        >
          <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
            <path d="M18 6 6 18M6 6l12 12" />
          </svg>
        </button>
      </p>

      <ul
        v-if="listedItems.length"
        class="divide-y divide-slate-100 rounded-2xl border border-slate-200 bg-white"
      >
        <UploadListItem
          v-for="item in listedItems"
          :key="item.id"
          :item="item"
          @cancel="requestRemove"
          @remove="requestRemove"
          @resume="resume"
        />
      </ul>
      <p
        v-else
        class="rounded-2xl border border-slate-200 bg-white px-5 py-8 text-center text-sm"
        :class="uploads.loadError ? 'text-red-700' : 'text-slate-600'"
      >
        {{
          uploads.isLoading ? 'Loading uploads…' : (uploads.loadError ?? 'Nothing in progress.')
        }}
      </p>
      <input
        ref="resumePicker"
        type="file"
        class="hidden"
        aria-label="Choose the file to resume"
        data-test="resume-picker"
        @change="resumeWithPickedFile"
      />
    </section>

    <ConfirmDialog
      :open="removalCopy !== null"
      :title="removalCopy?.title ?? ''"
      :message="removalCopy?.message ?? ''"
      :confirm-label="removalCopy?.confirmLabel ?? ''"
      :busy-label="removalCopy?.busyLabel"
      :cancel-label="removalCopy?.cancelLabel"
      :busy="isRemoving"
      :error="removalError"
      @confirm="confirmRemove"
      @cancel="cancelRemove"
    />

    <SubtitlesReadyDialog
      v-if="uploads.nextReady"
      :item="uploads.nextReady"
      :email="auth.user?.email"
      :discarding="isDiscarding"
      :discard-error="discardError"
      @download="uploads.download"
      @email="uploads.email"
      @discard="discard"
    />
  </main>
</template>
