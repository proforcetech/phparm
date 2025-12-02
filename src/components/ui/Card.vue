<template>
  <div :class="cardClasses">
    <!-- Header -->
    <div v-if="title || $slots.header" class="px-6 py-4 border-b border-gray-200">
      <slot name="header">
        <h3 class="text-lg font-medium text-gray-900">{{ title }}</h3>
      </slot>
    </div>

    <!-- Body -->
    <div :class="bodyClasses">
      <slot />
    </div>

    <!-- Footer -->
    <div v-if="$slots.footer" class="px-6 py-4 bg-gray-50 border-t border-gray-200">
      <slot name="footer" />
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  title: {
    type: String,
    default: '',
  },
  padding: {
    type: Boolean,
    default: true,
  },
  shadow: {
    type: String,
    default: 'md',
    validator: (value) => ['none', 'sm', 'md', 'lg', 'xl'].includes(value),
  },
  hover: {
    type: Boolean,
    default: false,
  },
})

const cardClasses = computed(() => {
  const classes = ['bg-white rounded-lg overflow-hidden']

  // Shadow
  const shadowClasses = {
    none: '',
    sm: 'shadow-sm',
    md: 'shadow-md',
    lg: 'shadow-lg',
    xl: 'shadow-xl',
  }
  classes.push(shadowClasses[props.shadow])

  // Hover effect
  if (props.hover) {
    classes.push('transition-shadow hover:shadow-lg cursor-pointer')
  }

  return classes.join(' ')
})

const bodyClasses = computed(() => {
  return props.padding ? 'p-6' : ''
})
</script>
