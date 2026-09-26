<script setup lang="ts">
  import axios from 'axios'

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
  Template
</template>

<style scoped>

</style>
