<script setup lang="ts">
import { RouterLink } from 'vue-router'
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

      <nav class="flex items-center gap-1 text-sm font-medium">
        <RouterLink
          to="/"
          class="rounded-lg px-3 py-2 text-slate-600 hover:bg-slate-100 hover:text-slate-900"
          exact-active-class="bg-slate-100 text-slate-900!"
        >
          Upload
        </RouterLink>
        <a href="#" class="rounded-lg px-3 py-2 text-slate-600 hover:bg-slate-100 hover:text-slate-900">
          My files
        </a>
        <a href="#" class="rounded-lg px-3 py-2 text-slate-600 hover:bg-slate-100 hover:text-slate-900">
          Settings
        </a>
      </nav>
    </div>
  </header>
</template>
