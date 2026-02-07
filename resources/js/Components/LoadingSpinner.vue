<script setup>
defineProps({
  size: {
    type: String,
    default: 'md', // sm, md, lg, xl
  },
  color: {
    type: String,
    default: 'primary', // primary, success, warning, danger
  },
})
</script>

<template>
  <div class="flex items-center justify-center">
    <div
      class="animate-spin rounded-full border-4 border-solid border-current border-r-transparent"
      :class="{
        'h-5 w-5': size === 'sm',
        'h-8 w-8': size === 'md',
        'h-12 w-12': size === 'lg',
        'h-16 w-16': size === 'xl',
        'text-primary-600': color === 'primary',
        'text-success-600': color === 'success',
        'text-warning-600': color === 'warning',
        'text-danger-600': color === 'danger',
      }"
      role="status"
      aria-label="Loading"
    >
      <span class="sr-only">Loading...</span>
    </div>
  </div>
</template>
