<template>
  <div v-if="modelValue" :class="alertClasses" role="alert">
    <div class="flex">
      <!-- Icon -->
      <div class="flex-shrink-0">
        <component :is="alertIcon" class="h-5 w-5" aria-hidden="true" />
      </div>

      <!-- Content -->
      <div class="ml-3 flex-1">
        <h3 v-if="title" :class="titleClasses">
          {{ title }}
        </h3>
        <div :class="contentClasses">
          <slot>
            <p>{{ message }}</p>
          </slot>
        </div>
      </div>

      <!-- Close button -->
      <div v-if="closable" class="ml-auto pl-3">
        <div class="-mx-1.5 -my-1.5">
          <button
            @click="close"
            type="button"
            :class="closeButtonClasses"
          >
            <span class="sr-only">Dismiss</span>
            <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
              <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" />
            </svg>
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import {
  CheckCircleIcon,
  ExclamationTriangleIcon,
  XCircleIcon,
  InformationCircleIcon,
} from '@heroicons/vue/24/solid'

const props = defineProps({
  modelValue: {
    type: Boolean,
    default: true,
  },
  variant: {
    type: String,
    default: 'info',
    validator: (value) => ['success', 'warning', 'danger', 'info'].includes(value),
  },
  title: {
    type: String,
    default: '',
  },
  message: {
    type: String,
    default: '',
  },
  closable: {
    type: Boolean,
    default: true,
  },
})

const emit = defineEmits(['update:modelValue', 'close'])

const variantConfig = {
  success: {
    bg: 'bg-green-50',
    icon: CheckCircleIcon,
    iconColor: 'text-green-400',
    titleColor: 'text-green-800',
    textColor: 'text-green-700',
    closeColor: 'text-green-500 hover:bg-green-100',
  },
  warning: {
    bg: 'bg-yellow-50',
    icon: ExclamationTriangleIcon,
    iconColor: 'text-yellow-400',
    titleColor: 'text-yellow-800',
    textColor: 'text-yellow-700',
    closeColor: 'text-yellow-500 hover:bg-yellow-100',
  },
  danger: {
    bg: 'bg-red-50',
    icon: XCircleIcon,
    iconColor: 'text-red-400',
    titleColor: 'text-red-800',
    textColor: 'text-red-700',
    closeColor: 'text-red-500 hover:bg-red-100',
  },
  info: {
    bg: 'bg-blue-50',
    icon: InformationCircleIcon,
    iconColor: 'text-blue-400',
    titleColor: 'text-blue-800',
    textColor: 'text-blue-700',
    closeColor: 'text-blue-500 hover:bg-blue-100',
  },
}

const config = computed(() => variantConfig[props.variant])

const alertClasses = computed(() => {
  return `rounded-md p-4 ${config.value.bg}`
})

const alertIcon = computed(() => config.value.icon)

const titleClasses = computed(() => {
  return `text-sm font-medium ${config.value.titleColor}`
})

const contentClasses = computed(() => {
  return `text-sm ${config.value.textColor} ${props.title ? 'mt-2' : ''}`
})

const closeButtonClasses = computed(() => {
  return `inline-flex rounded-md p-1.5 focus:outline-none focus:ring-2 focus:ring-offset-2 ${config.value.closeColor}`
})

function close() {
  emit('update:modelValue', false)
  emit('close')
}
</script>
