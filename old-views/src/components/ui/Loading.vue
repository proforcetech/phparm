<template>
  <div v-if="fullscreen" class="fixed inset-0 bg-white bg-opacity-75 flex items-center justify-center z-50">
    <div class="text-center">
      <component :is="spinnerComponent" :class="sizeClasses" />
      <p v-if="text" class="mt-4 text-gray-600">{{ text }}</p>
    </div>
  </div>

  <div v-else-if="overlay" class="absolute inset-0 bg-white bg-opacity-75 flex items-center justify-center rounded-lg">
    <div class="text-center">
      <component :is="spinnerComponent" :class="sizeClasses" />
      <p v-if="text" class="mt-4 text-gray-600">{{ text }}</p>
    </div>
  </div>

  <div v-else class="flex items-center justify-center" :class="{ 'flex-col': text }">
    <component :is="spinnerComponent" :class="sizeClasses" />
    <p v-if="text" :class="text ? 'mt-2 text-gray-600' : 'ml-2 text-gray-600'">{{ text }}</p>
  </div>
</template>

<script setup>
import { computed, h } from 'vue'

const props = defineProps({
  size: {
    type: String,
    default: 'md',
    validator: (value) => ['sm', 'md', 'lg', 'xl'].includes(value),
  },
  variant: {
    type: String,
    default: 'spinner',
    validator: (value) => ['spinner', 'dots', 'pulse'].includes(value),
  },
  color: {
    type: String,
    default: 'primary',
  },
  text: {
    type: String,
    default: '',
  },
  fullscreen: {
    type: Boolean,
    default: false,
  },
  overlay: {
    type: Boolean,
    default: false,
  },
})

const sizeMap = {
  sm: 'h-4 w-4',
  md: 'h-8 w-8',
  lg: 'h-12 w-12',
  xl: 'h-16 w-16',
}

const sizeClasses = computed(() => sizeMap[props.size])

const colorClass = computed(() => {
  const colors = {
    primary: 'text-primary-600',
    white: 'text-white',
    gray: 'text-gray-600',
  }
  return colors[props.color] || colors.primary
})

const spinnerComponent = computed(() => {
  if (props.variant === 'spinner') {
    return h('svg', {
      class: `animate-spin ${colorClass.value}`,
      xmlns: 'http://www.w3.org/2000/svg',
      fill: 'none',
      viewBox: '0 0 24 24',
    }, [
      h('circle', {
        class: 'opacity-25',
        cx: '12',
        cy: '12',
        r: '10',
        stroke: 'currentColor',
        'stroke-width': '4',
      }),
      h('path', {
        class: 'opacity-75',
        fill: 'currentColor',
        d: 'M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z',
      }),
    ])
  }

  if (props.variant === 'dots') {
    return h('div', { class: 'flex space-x-2' }, [
      h('div', { class: `${sizeClasses.value} ${colorClass.value} rounded-full bg-current animate-bounce` }),
      h('div', { class: `${sizeClasses.value} ${colorClass.value} rounded-full bg-current animate-bounce`, style: { animationDelay: '0.1s' } }),
      h('div', { class: `${sizeClasses.value} ${colorClass.value} rounded-full bg-current animate-bounce`, style: { animationDelay: '0.2s' } }),
    ])
  }

  // pulse
  return h('div', {
    class: `${sizeClasses.value} ${colorClass.value} rounded-full bg-current animate-pulse`,
  })
})
</script>
