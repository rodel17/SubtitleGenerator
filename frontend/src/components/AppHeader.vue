<script setup lang="ts">
import { computed, ref } from 'vue'
import { RouterLink } from 'vue-router'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import { reloadTo } from '@/lib/navigation'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()

const initial = computed(() => auth.user?.name.trim().charAt(0).toUpperCase() ?? '')
const signingOut = ref(false)

async function signOut() {
  signingOut.value = true
  await auth.signOut()
  reloadTo('/login')
}
</script>

<template>
  <header class="border-b border-slate-200 bg-white">
    <div
      class="mx-auto flex max-w-240 flex-wrap items-center justify-between gap-4 px-6 py-4"
    >
      <RouterLink to="/" class="flex items-center gap-2.5">
        <span class="flex size-8 items-center justify-center rounded-lg bg-accent">
          <svg
            class="size-4.5"
            viewBox="0 0 24 24"
            fill="none"
            stroke="white"
            stroke-width="2"
            stroke-linecap="round"
            stroke-linejoin="round"
            aria-hidden="true"
          >
            <rect x="3" y="5" width="18" height="14" rx="2" />
            <path d="M7 15h4M13 15h4M7 11h10" />
          </svg>
        </span>
        <span class="text-base font-semibold tracking-tight">Subtitle Generator</span>
      </RouterLink>

      <!-- One page only: files aren't kept, so there is no "My files" -->
      <div v-if="auth.user" class="flex flex-wrap items-center gap-4">
        <div class="flex items-center gap-3">
          <img
            v-if="auth.user.avatar"
            :src="auth.user.avatar"
            alt=""
            class="size-8 rounded-full"
            referrerpolicy="no-referrer"
          />
          <span
            v-else
            class="flex size-8 items-center justify-center rounded-full bg-accent-soft text-sm font-semibold text-accent"
            aria-hidden="true"
          >
            {{ initial }}
          </span>
          <span class="hidden max-w-40 truncate text-sm text-slate-700 sm:block" :title="auth.user.email">
            {{ auth.user.name }}
          </span>
          <button
            type="button"
            class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900 disabled:opacity-70"
            :disabled="signingOut"
            @click="signOut"
          >
            <LoadingSpinner v-if="signingOut" class="size-4" />
            {{ signingOut ? 'Signing out…' : 'Sign out' }}
          </button>
        </div>
      </div>
    </div>
  </header>
</template>
