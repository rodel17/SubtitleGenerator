<script setup lang="ts">
  import axios from 'axios'
  import { RouterView } from 'vue-router'
  import AppHeader from '@/components/AppHeader.vue'

  interface TestResponse {
    message: string
  }

  axios.get<TestResponse>(`${import.meta.env.VITE_API_URL}/test`)
    .then(response => {
      console.log('working') // "API is working"
      console.log(response.data.message) // "API is working"
    })
</script>

<template>
  <div class="min-h-screen">
    <AppHeader />
    <RouterView />
  </div>
</template>
