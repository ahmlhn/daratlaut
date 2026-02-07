<script setup>
import { ref, computed } from 'vue'
import Toast from './Toast.vue'

const toasts = ref([])
let nextId = 0

function addToast(type, message, duration = 4000) {
  const id = nextId++
  toasts.value.push({ id, type, message, duration, show: true })
  
  setTimeout(() => {
    removeToast(id)
  }, duration + 300) // Extra time for exit animation
}

function removeToast(id) {
  const index = toasts.value.findIndex(t => t.id === id)
  if (index !== -1) {
    toasts.value[index].show = false
    setTimeout(() => {
      toasts.value.splice(index, 1)
    }, 300) // Wait for animation
  }
}

// Expose methods to parent
defineExpose({
  success: (message, duration) => addToast('success', message, duration),
  error: (message, duration) => addToast('error', message, duration),
  warning: (message, duration) => addToast('warning', message, duration),
  info: (message, duration) => addToast('info', message, duration),
})
</script>

<template>
  <div class="pointer-events-none fixed right-0 top-0 z-50 flex w-full flex-col items-end px-4 py-6 sm:p-6">
    <Toast
      v-for="toast in toasts"
      :key="toast.id"
      :show="toast.show"
      :type="toast.type"
      :message="toast.message"
      :duration="toast.duration"
      @close="removeToast(toast.id)"
    />
  </div>
</template>
