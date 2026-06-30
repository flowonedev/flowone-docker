<script setup>
import { RouterView } from 'vue-router'
import { useAuthStore } from './stores/auth'
import { useToastStore } from './stores/toast'
import ToastContainer from './components/ToastContainer.vue'

const auth = useAuthStore()
const toast = useToastStore()

// Initialize auth state from localStorage
auth.initFromStorage()
</script>

<template>
  <div class="min-h-screen bg-surface-900">
    <RouterView />
    <ToastContainer />
  </div>
</template>

