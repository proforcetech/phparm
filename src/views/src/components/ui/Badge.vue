<template>
  <span :class="badgeClasses">
    <slot />
  </span>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  variant: {
    type: String,
    default: 'default',
    validator: (value) => ['default', 'success', 'warning', 'danger', 'info', 'primary', 'secondary'].includes(value),
  },
  size: {
    type: String,
    default: 'md',
    validator: (value) => ['sm', 'md', 'lg'].includes(value),
  },
  rounded: {
    type: Boolean,
    default: false,
  },
  dot: {
    type: Boolean,
    default: false,
  },
})

const variantClasses = {
  default: 'bg-gray-100 text-gray-800',
  secondary: 'bg-gray-200 text-gray-800',
  success: 'bg-green-100 text-green-800',
  warning: 'bg-yellow-100 text-yellow-800',
  danger: 'bg-red-100 text-red-800',
  info: 'bg-blue-100 text-blue-800',
  primary: 'bg-primary-100 text-primary-800',
}

const sizeClasses = {
  sm: 'px-2 py-0.5 text-xs',
  md: 'px-2.5 py-0.5 text-sm',
  lg: 'px-3 py-1 text-base',
}

const badgeClasses = computed(() => {
  const classes = [
    'inline-flex items-center font-medium',
    variantClasses[props.variant],
    sizeClasses[props.size],
  ]

  if (props.rounded) {
    classes.push('rounded-full')
  } else {
    classes.push('rounded')
  }

  if (props.dot) {
    classes.push('gap-1.5')
  }

  return classes.join(' ')
})
</script>

<style scoped>
.badge-dot::before {
  content: '';
  @apply inline-block w-1.5 h-1.5 rounded-full bg-current;
}
</style>
