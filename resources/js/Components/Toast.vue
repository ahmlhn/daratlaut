<script setup>
import { ref, watch, onMounted } from 'vue'

const props = defineProps({
  show: Boolean,
  type: {
    type: String,
    default: 'info', // success, error, warning, info
  },
  message: String,
  duration: {
    type: Number,
    default: 4000,
  },
})

const emit = defineEmits(['close'])

const visible = ref(props.show)
let timeout = null

watch(() => props.show, (newVal) => {
  visible.value = newVal
  if (newVal) {
    clearTimeout(timeout)
    timeout = setTimeout(() => {
      close()
    }, props.duration)
  }
})

function close() {
  visible.value = false
  emit('close')
}

onMounted(() => {
  if (props.show) {
    timeout = setTimeout(() => {
      close()
    }, props.duration)
  }
})
</script>

<template>
  <Transition
    enter-active-class="transition-all duration-300 ease-out"
    enter-from-class="translate-y-2 opacity-0"
    enter-to-class="translate-y-0 opacity-100"
    leave-active-class="transition-all duration-200 ease-in"
    leave-from-class="translate-y-0 opacity-100"
    leave-to-class="translate-y-2 opacity-0"
  >
    <div
      v-if="visible"
      class="pointer-events-auto mb-4 flex w-full max-w-sm overflow-hidden rounded-xl shadow-xl"
      :class="{
        'bg-gradient-to-r from-success-500 to-success-600': type === 'success',
        'bg-gradient-to-r from-danger-500 to-danger-600': type === 'error',
        'bg-gradient-to-r from-warning-500 to-warning-600': type === 'warning',
        'bg-gradient-to-r from-primary-500 to-primary-600': type === 'info',
      }"
    >
      <!-- Icon -->
      <div class="flex w-16 items-center justify-center">
        <!-- Success icon -->
        <svg v-if="type === 'success'" class="h-7 w-7 text-white animate-scale-in" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
        <!-- Error icon -->
        <svg v-else-if="type === 'error'" class="h-7 w-7 text-white animate-scale-in" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
        <!-- Warning icon -->
        <svg v-else-if="type === 'warning'" class="h-7 w-7 text-white animate-scale-in" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
        </svg>
        <!-- Info icon -->
        <svg v-else class="h-7 w-7 text-white animate-scale-in" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
      </div>

      <!-- Content -->
      <div class="flex-1 px-4 py-4">
        <p class="text-sm font-semibold text-white">{{ message }}</p>
      </div>

      <!-- Close button -->
      <button
        @click="close"
        class="group flex w-12 items-center justify-center transition-all duration-200 hover:bg-white/10"
      >
        <svg class="h-5 w-5 text-white/80 transition-all group-hover:rotate-90 group-hover:text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
        </svg>
      </button>
    </div>
  </Transition>
</template>
