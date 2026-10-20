<script setup lang="ts">
import type { SubtitleFormat } from '@/types/upload'

const model = defineModel<SubtitleFormat>({ required: true })

const options: { value: SubtitleFormat; label: string }[] = [
  { value: 'srt', label: 'SRT' },
  { value: 'vtt', label: 'VTT' },
  { value: 'both', label: 'Both' },
]
</script>

<template>
  <div class="flex flex-col gap-2">
    <span id="format-label" class="text-[13px] font-semibold text-slate-700">Output format</span>
    <div
      role="group"
      aria-labelledby="format-label"
      class="flex h-11 gap-1 rounded-[10px] bg-slate-100 p-1"
    >
      <button
        v-for="option in options"
        :key="option.value"
        type="button"
        :aria-pressed="model === option.value"
        class="flex-1 cursor-pointer rounded-[7px] text-sm transition-colors"
        :class="
          model === option.value
            ? 'bg-white font-semibold text-slate-900 shadow-sm'
            : 'font-medium text-slate-600 hover:text-slate-900'
        "
        @click="model = option.value"
      >
        {{ option.label }}
      </button>
    </div>
  </div>
</template>
