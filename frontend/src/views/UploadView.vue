<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import FileDropzone from '@/components/upload/FileDropzone.vue'
import FormatToggle from '@/components/upload/FormatToggle.vue'
import SelectedFilePreview from '@/components/upload/SelectedFilePreview.vue'
import UploadListItem from '@/components/upload/UploadListItem.vue'
import { formatBytes } from '@/lib/format'
import { useUploadsStore } from '@/stores/uploads'
import {
  ACCEPTED_EXTENSIONS,
  isAcceptedFile,
  LANGUAGES,
  MAX_FILE_SIZE,
  type SubtitleFormat,
} from '@/types/upload'

const uploads = useUploadsStore()

interface SelectedFile {
  id: string
  file: File
}

const selectedFiles = ref<SelectedFile[]>([])
const language = ref<string>(LANGUAGES[0].value)
const format = ref<SubtitleFormat>('srt')

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
      selectedFiles.value.push({ id: crypto.randomUUID(), file })
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
  )
  selectedFiles.value = []
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
        Add a video or audio file and we'll transcribe it into a subtitle track you can edit and
        download.
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

      <div
        class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-5"
      >
        <span class="text-[13px] text-slate-600">
          Files are processed privately and deleted after [RETENTION PERIOD].
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
      <div class="flex items-baseline justify-between gap-3">
        <h2 class="text-base font-semibold">Recent uploads</h2>
        <a href="#" class="text-sm font-medium text-accent hover:text-accent-hover">View all</a>
      </div>

      <ul
        v-if="uploads.items.length"
        class="divide-y divide-slate-100 rounded-2xl border border-slate-200 bg-white"
      >
        <UploadListItem
          v-for="item in uploads.items"
          :key="item.id"
          :item="item"
          @cancel="uploads.remove"
          @remove="uploads.remove"
        />
      </ul>
      <p
        v-else
        class="rounded-2xl border border-slate-200 bg-white px-5 py-8 text-center text-sm"
        :class="uploads.loadError ? 'text-red-700' : 'text-slate-600'"
      >
        {{
          uploads.isLoading ? 'Loading uploads…' : (uploads.loadError ?? 'No uploads yet.')
        }}
      </p>
    </section>
  </main>
</template>
