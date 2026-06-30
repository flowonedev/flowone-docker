<script setup>
import { RouterView } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { onMounted } from 'vue'

const auth = useAuthStore()

onMounted(() => {
  auth.checkAuth()
})
</script>

<template>
  <RouterView />
</template>

