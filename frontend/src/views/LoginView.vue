<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRoute } from 'vue-router'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import { googleSignInUrl } from '@/lib/api'

const route = useRoute()

/** Set by the backend when Google sign-in failed or was cancelled */
const error = computed(() => (typeof route.query.error === 'string' ? route.query.error : null))

const redirecting = ref(false)
</script>

<template>
  <main class="mx-auto flex max-w-100 flex-col gap-6 px-6 pt-20 pb-16">
    <section class="flex flex-col gap-6 rounded-2xl border border-slate-200 bg-white p-8">
      <div class="flex flex-col gap-2">
        <h1 class="text-[22px] font-bold tracking-tight">Sign in</h1>
        <p class="text-[15px] leading-normal text-slate-600">
          Use your Google account to upload media and keep your subtitles in one place.
        </p>
      </div>

      <p
        v-if="error"
        role="alert"
        class="rounded-[10px] border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"
      >
        {{ error }}
      </p>

      <a
        :href="googleSignInUrl()"
        class="flex h-11 items-center justify-center gap-3 rounded-[10px] border border-slate-300 bg-white text-sm font-semibold text-slate-800 hover:bg-slate-50 focus:ring-2 focus:ring-accent/20 focus:outline-none"
        :class="{ 'pointer-events-none opacity-70': redirecting }"
        :aria-disabled="redirecting"
        @click="redirecting = true"
      >
        <LoadingSpinner v-if="redirecting" class="size-4.5 text-slate-500" />
        <svg v-else class="size-4.5" viewBox="0 0 48 48" aria-hidden="true">
          <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z" />
          <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z" />
          <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z" />
          <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z" />
        </svg>
        {{ redirecting ? 'Redirecting to Google…' : 'Sign in with Google' }}
      </a>
    </section>
  </main>
</template>
