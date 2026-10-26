<script setup lang="ts">
import { nextTick, onBeforeUnmount, ref, watch } from 'vue'
import LoadingSpinner from '@/components/LoadingSpinner.vue'

const props = withDefaults(
  defineProps<{
    open: boolean
    title: string
    message: string
    confirmLabel: string
    /** Shown on the confirm button while `busy` */
    busyLabel?: string
    cancelLabel?: string
    /** The confirmed action is running: show a spinner and block closing */
    busy?: boolean
    error?: string | null
    /** "danger" (red) for deleting; "primary" (accent) for going ahead with something */
    tone?: 'danger' | 'primary'
  }>(),
  { busyLabel: 'Working…', cancelLabel: 'Cancel', busy: false, error: null, tone: 'danger' },
)

const emit = defineEmits<{
  confirm: []
  cancel: []
}>()

const cancelButton = ref<HTMLButtonElement | null>(null)
let previouslyFocused: HTMLElement | null = null

function cancel() {
  if (!props.busy) emit('cancel')
}

function onKeydown(event: KeyboardEvent) {
  if (event.key === 'Escape') cancel()
}

// Focus the safe choice when opening; give focus back to where it was when closing
watch(
  () => props.open,
  async (open) => {
    if (open) {
      previouslyFocused = document.activeElement as HTMLElement | null
      document.addEventListener('keydown', onKeydown)
      await nextTick()
      cancelButton.value?.focus()
    } else {
      document.removeEventListener('keydown', onKeydown)
      previouslyFocused?.focus()
    }
  },
)

onBeforeUnmount(() => document.removeEventListener('keydown', onKeydown))
</script>

<template>
  <div
    v-if="open"
    class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 px-4"
    data-test="confirm-backdrop"
    @click.self="cancel"
  >
    <div
      role="alertdialog"
      aria-modal="true"
      aria-labelledby="confirm-title"
      aria-describedby="confirm-message"
      class="flex w-full max-w-100 flex-col gap-5 rounded-2xl border border-slate-200 bg-white p-6 shadow-xl"
    >
      <div class="flex flex-col gap-2">
        <h2 id="confirm-title" class="text-base font-semibold">{{ title }}</h2>
        <p id="confirm-message" class="text-sm leading-normal text-slate-600">{{ message }}</p>
        <p v-if="error" role="alert" class="text-sm text-red-700">{{ error }}</p>
      </div>

      <div class="flex justify-end gap-2.5">
        <button
          ref="cancelButton"
          type="button"
          class="h-10 cursor-pointer rounded-[10px] border border-slate-300 bg-white px-4 text-sm font-semibold text-slate-700 hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50"
          :disabled="busy"
          @click="cancel"
        >
          {{ cancelLabel }}
        </button>
        <button
          type="button"
          class="flex h-10 cursor-pointer items-center gap-2 rounded-[10px] px-4 text-sm font-semibold text-white disabled:cursor-wait disabled:opacity-80"
          :class="tone === 'primary' ? 'bg-accent hover:bg-accent-hover' : 'bg-red-600 hover:bg-red-700'"
          :disabled="busy"
          :aria-busy="busy"
          @click="emit('confirm')"
        >
          <LoadingSpinner v-if="busy" class="size-4" />
          {{ busy ? busyLabel : confirmLabel }}
        </button>
      </div>
    </div>
  </div>
</template>
