<template>
  <div :class="{ 'w-full': fullWidth }">
    <label v-if="label" :for="id" class="block text-sm font-medium text-gray-700 mb-1">
      {{ label }}
      <span v-if="required" class="text-red-500">*</span>
    </label>

    <textarea
      :id="id"
      :value="modelValue"
      :placeholder="placeholder"
      :disabled="disabled"
      :required="required"
      :rows="rows"
      :maxlength="maxlength"
      :class="textareaClasses"
      @input="$emit('update:modelValue', $event.target.value)"
      @blur="$emit('blur', $event)"
      @focus="$emit('focus', $event)"
    ></textarea>

    <!-- Character count -->
    <div v-if="maxlength" class="mt-1 flex justify-between items-center">
      <p v-if="error" class="text-sm text-red-600">
        {{ error }}
      </p>
      <p v-else-if="helperText" class="text-sm text-gray-500">
        {{ helperText }}
      </p>
      <span v-else class="flex-1"></span>
      <span class="text-xs text-gray-500">
        {{ modelValue?.length || 0 }} / {{ maxlength }}
      </span>
    </div>
    <div v-else>
      <p v-if="error" class="mt-1 text-sm text-red-600">
        {{ error }}
      </p>
      <p v-else-if="helperText" class="mt-1 text-sm text-gray-500">
        {{ helperText }}
      </p>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  id: {
    type: String,
    default: () => `textarea-${Math.random().toString(36).substr(2, 9)}`,
  },
  modelValue: {
    type: String,
    default: '',
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
  rows: {
    type: Number,
    default: 4,
  },
  maxlength: {
    type: Number,
    default: null,
  },
  fullWidth: {
    type: Boolean,
    default: true,
  },
})

defineEmits(['update:modelValue', 'blur', 'focus'])

const textareaClasses = computed(() => {
  const base = 'block w-full rounded-md shadow-sm transition-colors focus:outline-none focus:ring-2 focus:ring-offset-0 disabled:opacity-50 disabled:cursor-not-allowed sm:text-sm px-3 py-2'

  if (props.error) {
    return `${base} border-red-300 text-red-900 placeholder-red-300 focus:ring-red-500 focus:border-red-500`
  }

  return `${base} border-gray-300 focus:ring-primary-500 focus:border-primary-500`
})
</script>
