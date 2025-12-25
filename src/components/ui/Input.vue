<template>
  <div :class="{ 'w-full': fullWidth }">
    <label v-if="label" :for="id" class="block text-sm font-medium text-gray-700 mb-1">
      {{ label }}
      <span v-if="required" class="text-red-500">*</span>
    </label>

    <div class="relative">
      <input
        :id="id"
        :type="type"
        :value="modelValue"
        :placeholder="placeholder"
        :disabled="disabled"
        :required="required"
        :autocomplete="autocomplete"
        :class="inputClasses"
        ref="inputRef"
        @input="$emit('update:modelValue', $event.target.value)"
        @blur="$emit('blur', $event)"
        @focus="$emit('focus', $event)"
      />

      <!-- Icon -->
      <div v-if="icon" class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
        <component :is="icon" class="h-5 w-5 text-gray-400" />
      </div>
    </div>

    <!-- Helper text or error -->
    <p v-if="error" class="mt-1 text-sm text-red-600">
      {{ error }}
    </p>
    <p v-else-if="helperText" class="mt-1 text-sm text-gray-500">
      {{ helperText }}
    </p>
  </div>
</template>

<script setup>
import { computed, nextTick, ref, watch } from 'vue'

const props = defineProps({
  id: {
    type: String,
    default: () => `input-${Math.random().toString(36).substr(2, 9)}`,
  },
  modelValue: {
    type: [String, Number],
    default: '',
  },
  type: {
    type: String,
    default: 'text',
  },
  label: {
    type: String,
    default: '',
  },
  placeholder: {
    type: String,
    default: '',
  },
  error: {
    type: String,
    default: '',
  },
  helperText: {
    type: String,
    default: '',
  },
  disabled: {
    type: Boolean,
    default: false,
  },
  required: {
    type: Boolean,
    default: false,
  },
  autocomplete: {
    type: String,
    default: 'off',
  },
  icon: {
    type: Object,
    default: null,
  },
  fullWidth: {
    type: Boolean,
    default: true,
  },
})

const emit = defineEmits(['update:modelValue', 'blur', 'focus', 'validation-error'])

const inputRef = ref(null)
const isShaking = ref(false)
let shakeTimeoutId = null

const inputClasses = computed(() => {
  const base = 'block w-full rounded-md shadow-sm transition-colors focus:outline-none focus:ring-2 focus:ring-offset-0 disabled:opacity-50 disabled:cursor-not-allowed sm:text-sm'
  const shakeClass = isShaking.value ? 'input-shake' : ''

  if (props.error) {
    return `${base} ${shakeClass} border-red-300 text-red-900 placeholder-red-300 focus:ring-red-500 focus:border-red-500`
  }

  const iconPadding = props.icon ? 'pl-10' : 'px-3'
  return `${base} ${iconPadding} ${shakeClass} py-2 border-gray-300 focus:ring-primary-500 focus:border-primary-500`
})

watch(
  () => props.error,
  async (newError, oldError) => {
    if (!newError || newError === oldError) {
      return
    }

    emit('validation-error', newError)
    isShaking.value = true

    await nextTick()
    if (inputRef.value?.scrollIntoView) {
      inputRef.value.scrollIntoView({ behavior: 'smooth', block: 'center' })
    }

    if (shakeTimeoutId) {
      clearTimeout(shakeTimeoutId)
    }
    shakeTimeoutId = setTimeout(() => {
      isShaking.value = false
    }, 450)
  }
)
</script>

<style scoped>
@keyframes input-shake {
  0%,
  100% {
    transform: translateX(0);
  }
  20%,
  60% {
    transform: translateX(-4px);
  }
  40%,
  80% {
    transform: translateX(4px);
  }
}

.input-shake {
  animation: input-shake 0.3s ease-in-out;
}
</style>
