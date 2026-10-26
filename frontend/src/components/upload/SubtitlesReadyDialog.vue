<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import { formatBytes, formatTime } from '@/lib/format'
import { languageLabel, type UploadItem } from '@/types/upload'

/**
 * Shown when an upload's subtitles are ready. It can't be closed (no Esc, no
 * backdrop click): the user downloads or emails the subtitles, after which
 * everything is deleted, or discards them on purpose.
 */
const props = withDefaults(
  defineProps<{
    item: UploadItem
    /** The signed-in user's address; emails only ever go there */
    email?: string
    /** Discarding is running: show a spinner and block the buttons */
    discarding?: boolean
    discardError?: string | null
  }>(),
  { email: undefined, discarding: false, discardError: null },
)

const emit = defineEmits<{
  download: [id: string]
  email: [id: string]
  discard: [id: string]
}>()

const isBusy = computed(() => props.item.downloading === true || props.item.emailRequesting === true)
const error = computed(() => props.item.emailError ?? props.item.downloadError)

const confirmingDiscard = ref(false)
const downloadButton = ref<HTMLButtonElement | null>(null)
const previouslyFocused = document.activeElement as HTMLElement | null

const detailsLabel = computed(() =>
  [languageLabel(props.item.language), formatBytes(props.item.size)].filter(Boolean).join(' · '),
)

const downloadLabel = computed(() => {
  if (props.item.downloading) return 'Downloading…'
  if (props.item.format === 'both') return 'Download SRT + VTT (.zip)'
  return `Download ${(props.item.format ?? 'srt').toUpperCase()}`
})

const expiresAt = computed(() => (props.item.expiresAt ? formatTime(props.item.expiresAt) : null))

// Each upload starts fresh, with the download button focused
watch(
  () => props.item.id,
  async () => {
    confirmingDiscard.value = false
    await nextTick()
    downloadButton.value?.focus()
  },
  { immediate: true },
)

onBeforeUnmount(() => previouslyFocused?.focus())
</script>

<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 px-4">
    <div
      role="dialog"
      aria-modal="true"
      aria-labelledby="ready-title"
      aria-describedby="ready-message"
      class="flex w-full max-w-110 flex-col gap-5 rounded-2xl border border-slate-200 bg-white p-6 shadow-xl"
    >
      <div class="flex flex-col gap-2">
        <span class="flex size-10 items-center justify-center rounded-full bg-green-50 text-green-700">
          <svg
            class="size-5"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="2.5"
            stroke-linecap="round"
            stroke-linejoin="round"
            aria-hidden="true"
          >
            <path d="M20 6 9 17l-5-5" />
          </svg>
        </span>
        <h2 id="ready-title" class="text-base font-semibold">Your subtitles are ready</h2>
        <p id="ready-message" class="text-sm leading-normal text-slate-600">
          <span class="font-semibold break-all text-slate-900">{{ item.name }}</span>
          · {{ detailsLabel }}
        </p>
        <p class="text-[13px] leading-normal text-slate-600">
          They're deleted from our servers as soon as you download or email them<template
            v-if="expiresAt"
            >, or at {{ expiresAt }} if you don't</template
          >.
        </p>
        <p v-if="error" role="alert" class="text-sm text-red-700">
          {{ error }}
        </p>
      </div>

      <div v-if="!confirmingDiscard" class="flex flex-col items-center gap-3">
        <button
          ref="downloadButton"
          type="button"
          class="flex h-11 w-full cursor-pointer items-center justify-center gap-2 rounded-[10px] bg-accent px-5 text-sm font-semibold text-white hover:bg-accent-hover disabled:cursor-wait disabled:opacity-80"
          :disabled="isBusy"
          :aria-busy="item.downloading"
          @click="emit('download', item.id)"
        >
          <LoadingSpinner v-if="item.downloading" class="size-4" />
          <svg
            v-else
            class="size-4"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
            stroke-linecap="round"
            stroke-linejoin="round"
            aria-hidden="true"
          >
            <path d="M12 4v12M6 10l6 6 6-6M4 20h16" />
          </svg>
          {{ downloadLabel }}
        </button>
        <button
          type="button"
          class="flex h-11 w-full cursor-pointer items-center justify-center gap-2 rounded-[10px] border border-slate-300 bg-white px-5 text-sm font-semibold text-slate-700 hover:bg-slate-100 disabled:cursor-wait disabled:opacity-60"
          :disabled="isBusy"
          :aria-busy="item.emailRequesting"
          :aria-describedby="email ? 'ready-email-to' : undefined"
          @click="emit('email', item.id)"
        >
          <LoadingSpinner v-if="item.emailRequesting" class="size-4" />
          <svg
            v-else
            class="size-4"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
            stroke-linecap="round"
            stroke-linejoin="round"
            aria-hidden="true"
          >
            <rect x="3" y="5" width="18" height="14" rx="2" />
            <path d="m3 7 9 6 9-6" />
          </svg>
          {{ item.emailRequesting ? 'Sending…' : 'Email to me' }}
        </button>
        <p v-if="email" id="ready-email-to" class="-mt-1 text-[13px] text-slate-600">
          Sends to <span class="font-medium break-all text-slate-900">{{ email }}</span>
        </p>
        <button
          type="button"
          class="cursor-pointer text-[13px] font-medium text-slate-600 underline underline-offset-2 hover:text-red-700 disabled:cursor-not-allowed disabled:opacity-50"
          :disabled="isBusy"
          @click="confirmingDiscard = true"
        >
          Discard without saving
        </button>
      </div>

      <div v-else class="flex flex-col gap-3 rounded-xl bg-red-50 p-4">
        <p class="text-sm leading-normal text-red-900">
          Delete these subtitles without saving them? This can't be undone.
        </p>
        <p v-if="discardError" role="alert" class="text-sm text-red-700">{{ discardError }}</p>
        <div class="flex justify-end gap-2.5">
          <button
            type="button"
            class="h-10 cursor-pointer rounded-[10px] border border-slate-300 bg-white px-4 text-sm font-semibold text-slate-700 hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50"
            :disabled="discarding"
            @click="confirmingDiscard = false"
          >
            Keep
          </button>
          <button
            type="button"
            class="flex h-10 cursor-pointer items-center gap-2 rounded-[10px] bg-red-600 px-4 text-sm font-semibold text-white hover:bg-red-700 disabled:cursor-wait disabled:opacity-80"
            :disabled="discarding"
            :aria-busy="discarding"
            @click="emit('discard', item.id)"
          >
            <LoadingSpinner v-if="discarding" class="size-4" />
            {{ discarding ? 'Discarding…' : 'Discard' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
