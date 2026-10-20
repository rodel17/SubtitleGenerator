<script setup lang="ts">
import { ref } from 'vue'
import { ACCEPTED_EXTENSIONS } from '@/types/upload'

defineProps<{
  selected: File[]
}>()

const emit = defineEmits<{
  add: [files: File[]]
}>()

const isDragging = ref(false)
const input = ref<HTMLInputElement | null>(null)

const acceptAttr = ACCEPTED_EXTENSIONS.map((ext) => `.${ext}`).join(',')
const acceptLabel = ACCEPTED_EXTENSIONS.map((ext) => ext.toUpperCase()).join(', ')

function onDrop(event: DragEvent) {
  isDragging.value = false
  const files = Array.from(event.dataTransfer?.files ?? [])
  if (files.length) emit('add', files)
}

function onChange() {
  const files = Array.from(input.value?.files ?? [])
  if (files.length) emit('add', files)
  // Reset so picking the same file again still fires `change`
  if (input.value) input.value.value = ''
}
</script>

<template>
  <label
    for="file-input"
    class="flex cursor-pointer flex-col items-center justify-center gap-3.5 rounded-xl border-2 border-dashed px-6 py-12 text-center transition-colors focus-within:ring-2 focus-within:ring-accent focus-within:ring-offset-2"
    :class="
      isDragging
        ? 'border-accent bg-accent-soft'
        : 'border-slate-300 bg-slate-50 hover:border-accent hover:bg-accent-soft/60'
    "
    @dragenter.prevent="isDragging = true"
    @dragover.prevent="isDragging = true"
    @dragleave.prevent="isDragging = false"
    @drop.prevent="onDrop"
  >
    <span class="flex size-14 items-center justify-center rounded-2xl bg-accent-soft">
      <svg
        class="size-6.5 text-accent"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-hidden="true"
      >
        <path d="M12 16V4M6 10l6-6 6 6M4 16v3a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-3" />
      </svg>
    </span>

    <span class="flex flex-col gap-1">
      <span class="text-base font-semibold">
        {{ isDragging ? 'Drop to add your files' : 'Drag and drop your file here' }}
      </span>
      <span class="text-sm text-slate-600">
        or
        <span class="font-semibold text-accent underline underline-offset-3">
          browse from your computer
        </span>
      </span>
    </span>

    <span v-if="selected.length" class="text-sm font-medium text-slate-900">
      {{ selected.length }} file{{ selected.length > 1 ? 's' : '' }} selected:
      {{ selected.map((f) => f.name).join(', ') }}
    </span>
    <span v-else class="text-[13px] text-slate-600">{{ acceptLabel }}</span>

    <input
      id="file-input"
      ref="input"
      type="file"
      multiple
      :accept="acceptAttr"
      class="sr-only"
      @change="onChange"
    />
  </label>
</template>
