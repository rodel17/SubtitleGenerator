<script setup lang="ts">
import { ref } from 'vue'
import FileDropzone from '@/components/upload/FileDropzone.vue'
import FormatToggle from '@/components/upload/FormatToggle.vue'
import UploadListItem from '@/components/upload/UploadListItem.vue'
import {
  ACCEPTED_EXTENSIONS,
  isAcceptedFile,
  mediaKindOf,
  type SubtitleFormat,
  type UploadItem,
} from '@/types/upload'

const languages = ['Auto-detect', 'English', 'Filipino', 'Spanish', 'Japanese']

const selectedFiles = ref<File[]>([])
const language = ref(languages[0])
const format = ref<SubtitleFormat>('srt')

// Sample rows showing every state until the upload API is wired up
const uploads = ref<UploadItem[]>([
  { id: 'sample-1', name: 'team-interview.mp4', kind: 'video', status: 'uploading', progress: 64 },
  { id: 'sample-2', name: 'podcast-episode-12.mp3', kind: 'audio', status: 'transcribing' },
  {
    id: 'sample-3',
    name: 'product-demo.mov',
    kind: 'video',
    status: 'ready',
    language: 'English',
    format: 'srt',
  },
])

function addFiles(files: File[]) {
  const accepted = files.filter(isAcceptedFile)
  const rejected = files.filter((file) => !isAcceptedFile(file))

  selectedFiles.value = [...selectedFiles.value, ...accepted]

  uploads.value.unshift(
    ...rejected.map((file) => ({
      id: crypto.randomUUID(),
      name: file.name,
      kind: mediaKindOf(file.name),
      status: 'failed' as const,
      error: `Unsupported format. Try ${ACCEPTED_EXTENSIONS.slice(0, 3)
        .map((ext) => ext.toUpperCase())
        .join(', ')}.`,
    })),
  )
}

function clearSelection() {
  selectedFiles.value = []
}

function generate() {
  uploads.value.unshift(
    ...selectedFiles.value.map((file) => ({
      id: crypto.randomUUID(),
      name: file.name,
      kind: mediaKindOf(file.name),
      status: 'queued' as const,
      language: language.value,
      format: format.value,
    })),
  )
  selectedFiles.value = []
}

function removeUpload(id: string) {
  uploads.value = uploads.value.filter((item) => item.id !== id)
}
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
      <FileDropzone :selected="selectedFiles" @add="addFiles" />

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
            <option v-for="lang in languages" :key="lang">{{ lang }}</option>
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
        v-if="uploads.length"
        class="divide-y divide-slate-100 rounded-2xl border border-slate-200 bg-white"
      >
        <UploadListItem
          v-for="item in uploads"
          :key="item.id"
          :item="item"
          @cancel="removeUpload"
          @remove="removeUpload"
        />
      </ul>
      <p
        v-else
        class="rounded-2xl border border-slate-200 bg-white px-5 py-8 text-center text-sm text-slate-600"
      >
        No uploads yet.
      </p>
    </section>
  </main>
</template>
