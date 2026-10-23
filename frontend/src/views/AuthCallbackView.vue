<script setup lang="ts">
import { onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import { useAuthStore } from '@/stores/auth'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

onMounted(() => {
  // The backend puts the token in the fragment (#token=…), which never reaches a server
  const token = new URLSearchParams(route.hash.slice(1)).get('token')

  if (!token) {
    void router.replace({ name: 'login', query: { error: 'Sign-in failed. Please try again.' } })
    return
  }

  auth.signIn(token)
  // replace, so the URL holding the token doesn't stay in the browser history
  void router.replace({ name: 'upload' })
})
</script>

<template>
  <main class="flex justify-center px-6 pt-24">
    <p class="flex items-center gap-3 text-[15px] text-slate-600" role="status">
      <LoadingSpinner class="size-5 text-accent" />
      Signing you in…
    </p>
  </main>
</template>
