<script setup lang="ts">
import { computed } from 'vue'
import { formatBytes } from '@/lib/format'
import { isEmailing, languageLabel, type UploadItem } from '@/types/upload'

// Ready uploads are only listed while being emailed; otherwise SubtitlesReadyDialog asks for them

const props = defineProps<{
  item: UploadItem
}>()

defineEmits<{
  cancel: [id: string]
  remove: [id: string]
  resume: [id: string]
}>()

const iconTone = computed(() => {
  if (props.item.status === 'failed') return 'bg-red-50 text-red-700'
  if (props.item.status === 'interrupted') return 'bg-amber-50 text-amber-700'
  if (props.item.kind === 'audio') return 'bg-amber-50 text-amber-700'
  return 'bg-accent-soft text-accent'
})

const detailsLabel = computed(() =>
  [
    languageLabel(props.item.language),
    props.item.format?.toUpperCase(),
    formatBytes(props.item.size),
  ]
    .filter(Boolean)
    .join(' · '),
)

const emailing = computed(() => isEmailing(props.item))

const isInProgress = computed(
  () => ['uploading', 'processing', 'uploaded', 'transcribing'].includes(props.item.status) || emailing.value,
)

/** Waiting in the transcription queue, or for the email to go out, has no measurable progress */
const hasProgress = computed(() => props.item.status !== 'uploaded' && !emailing.value)

const progressLabel = computed(() => {
  const percent = `${props.item.progress ?? 0}%`
  if (emailing.value) return 'Emailing to you…'
  if (props.item.status === 'uploading') return `Uploading · ${percent}`
  if (props.item.status === 'processing') return `Finalizing · ${percent}`
  if (props.item.status === 'uploaded') return 'Waiting to transcribe…'
  return `Transcribing · ${percent}`
})

const secondaryButton =
  'h-10 cursor-pointer rounded-[10px] border border-slate-300 bg-white px-3.5 text-sm font-semibold text-slate-700 hover:bg-slate-100'
</script>

<template>
  <li class="flex flex-wrap items-center gap-4 px-5 py-4.5">
    <span class="flex size-10 shrink-0 items-center justify-center rounded-[10px]" :class="iconTone">
      <svg
        class="size-5"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-hidden="true"
      >
        <template v-if="item.status === 'failed'">
          <circle cx="12" cy="12" r="9" />
          <path d="M12 8v4M12 16h.01" />
        </template>
        <template v-else-if="item.status === 'interrupted'">
          <circle cx="12" cy="12" r="9" />
          <path d="M10 9v6M14 9v6" />
        </template>
        <template v-else-if="item.kind === 'audio'">
          <path d="M9 18V5l12-2v13" />
          <circle cx="6" cy="18" r="3" />
          <circle cx="18" cy="16" r="3" />
        </template>
        <template v-else>
          <rect x="2" y="6" width="14" height="12" rx="2" />
          <path d="m22 8-6 4 6 4V8z" />
        </template>
      </svg>
    </span>

    <!-- In-progress rows: name + status on one line, bar underneath -->
    <div v-if="isInProgress" class="flex min-w-0 flex-[1_1_240px] flex-col gap-2">
      <div class="flex justify-between gap-3 text-sm">
        <span class="truncate font-semibold">{{ item.name }}</span>
        <span class="shrink-0 text-slate-600">{{ progressLabel }}</span>
      </div>
      <div
        class="h-1.5 overflow-hidden rounded-full bg-slate-100"
        role="progressbar"
        :aria-label="`${item.name} progress`"
        :aria-valuenow="hasProgress ? (item.progress ?? 0) : undefined"
        aria-valuemin="0"
        aria-valuemax="100"
      >
        <div
          v-if="hasProgress"
          class="h-full rounded-full bg-accent transition-[width]"
          :style="{ width: `${item.progress ?? 0}%` }"
        />
        <div v-else class="h-full w-2/5 animate-indeterminate rounded-full bg-accent" />
      </div>
    </div>

    <!-- Settled rows: name + status line -->
    <div v-else class="flex min-w-0 flex-[1_1_240px] flex-col gap-1">
      <span class="truncate text-sm font-semibold">{{ item.name }}</span>
      <span v-if="item.status === 'interrupted'" class="text-[13px] text-amber-700">{{ item.error }}</span>
      <span v-else-if="item.status === 'failed'" class="text-[13px] text-red-700">{{ item.error }}</span>
      <span v-else class="text-[13px] text-slate-600">Queued · {{ detailsLabel }}</span>
    </div>

    <!-- Actions -->
    <button
      v-if="item.status === 'uploading'"
      type="button"
      aria-label="Cancel upload"
      class="flex size-11 cursor-pointer items-center justify-center rounded-[10px] text-slate-600 hover:bg-slate-100"
      @click="$emit('cancel', item.id)"
    >
      <svg class="size-4.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
        <path d="M18 6 6 18M6 6l12 12" />
      </svg>
    </button>

    <div v-else-if="item.status === 'interrupted'" class="flex gap-2">
      <button type="button" :class="secondaryButton" @click="$emit('remove', item.id)">Remove</button>
      <button
        type="button"
        class="h-10 cursor-pointer rounded-[10px] bg-accent px-3.5 text-sm font-semibold text-white hover:bg-accent-hover"
        @click="$emit('resume', item.id)"
      >
        Resume
      </button>
    </div>

    <button
      v-else-if="['failed', 'queued'].includes(item.status)"
      type="button"
      :class="secondaryButton"
      @click="$emit('remove', item.id)"
    >
      Remove
    </button>

    <!-- Being emailed: nothing to do but wait; keeps the row aligned with rows that have an action -->
    <span v-else-if="emailing" class="size-11" aria-hidden="true" />

    <!-- Finalizing, waiting or transcribing on the server: stopping deletes it there -->
    <button
      v-else
      type="button"
      aria-label="Stop and delete"
      title="Stop and delete"
      class="flex size-11 cursor-pointer items-center justify-center rounded-[10px] text-slate-600 hover:bg-red-50 hover:text-red-700"
      @click="$emit('cancel', item.id)"
    >
      <svg class="size-4.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
        <path d="M18 6 6 18M6 6l12 12" />
      </svg>
    </button>
  </li>
</template>
