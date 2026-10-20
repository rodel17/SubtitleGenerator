<script setup lang="ts">
import { onBeforeUnmount, ref } from 'vue'
import { formatBytes, formatDuration } from '@/lib/format'
import { mediaKindOf } from '@/types/upload'

const props = defineProps<{
  file: File
}>()

defineEmits<{
  remove: []
}>()

// The browser plays the local file directly; nothing is uploaded for the preview
const objectUrl = URL.createObjectURL(props.file)
const kind = mediaKindOf(props.file.name)
const duration = ref<number | null>(null)
const canPreview = ref(true)

function onLoadedMetadata(event: Event) {
  const media = event.target as HTMLMediaElement
  if (Number.isFinite(media.duration)) duration.value = media.duration
}

onBeforeUnmount(() => URL.revokeObjectURL(objectUrl))
</script>

<template>
  <div class="flex flex-col gap-3 rounded-xl border border-slate-200 p-3">
    <video
      v-if="kind === 'video' && canPreview"
      :src="objectUrl"
      controls
      preload="metadata"
      class="aspect-video max-h-80 w-full rounded-lg bg-slate-900 object-contain"
      @loadedmetadata="onLoadedMetadata"
      @error="canPreview = false"
    />
    <audio
      v-else-if="kind === 'audio' && canPreview"
      :src="objectUrl"
      controls
      preload="metadata"
      class="w-full"
      @loadedmetadata="onLoadedMetadata"
      @error="canPreview = false"
    />
    <p
      v-else
      class="rounded-lg bg-slate-100 px-4 py-6 text-center text-[13px] text-slate-600"
    >
      Your browser can't preview this format, but it can still be uploaded.
    </p>

    <div class="flex items-center gap-3">
      <div class="flex min-w-0 flex-1 flex-col gap-0.5">
        <span class="truncate text-sm font-semibold">{{ file.name }}</span>
        <span class="text-[13px] text-slate-600">
          {{ formatBytes(file.size) }}
          <template v-if="duration !== null"> · {{ formatDuration(duration) }}</template>
        </span>
      </div>
      <button
        type="button"
        :aria-label="`Remove ${file.name}`"
        class="flex size-11 shrink-0 cursor-pointer items-center justify-center rounded-[10px] text-slate-600 hover:bg-slate-100"
        @click="$emit('remove')"
      >
        <svg class="size-4.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
          <path d="M18 6 6 18M6 6l12 12" />
        </svg>
      </button>
    </div>
  </div>
</template>
