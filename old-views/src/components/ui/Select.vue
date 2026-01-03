<template>
  <div :class="{ 'w-full': fullWidth }">
    <label v-if="label" :for="id" class="block text-sm font-medium text-gray-700 mb-1">
      {{ label }}
      <span v-if="required" class="text-red-500">*</span>
    </label>

    <select
      :id="id"
      :value="modelValue"
      :disabled="disabled"
      :required="required"
      :class="selectClasses"
      @change="handleChange"
    >
      <option v-if="placeholder" value="" disabled>{{ placeholder }}</option>
      <option
        v-for="option in options"
        :key="getOptionValue(option)"
        :value="getOptionValue(option)"
      >
        {{ getOptionLabel(option) }}
      </option>
    </select>

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
import { computed } from 'vue'

const props = defineProps({
  id: {
    type: String,
    default: () => `select-${Math.random().toString(36).substr(2, 9)}`,
  },
  modelValue: {
    type: [String, Number, Boolean],
    default: '',
  },
  label: {
    type: String,
    default: '',
  },
  placeholder: {
    type: String,
    default: 'Select an option',
  },
  options: {
    type: Array,
    required: true,
  },
  valueKey: {
    type: String,
    default: 'value',
  },
  labelKey: {
    type: String,
    default: 'label',
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
  fullWidth: {
    type: Boolean,
    default: true,
  },
})

const emit = defineEmits(['update:modelValue'])

const selectClasses = computed(() => {
  const base = 'block w-full rounded-md shadow-sm transition-colors focus:outline-none focus:ring-2 focus:ring-offset-0 disabled:opacity-50 disabled:cursor-not-allowed sm:text-sm px-3 py-2'

  if (props.error) {
    return `${base} border-red-300 text-red-900 focus:ring-red-500 focus:border-red-500`
  }

  return `${base} border-gray-300 focus:ring-primary-500 focus:border-primary-500`
})

function getOptionValue(option) {
  return typeof option === 'object' ? option[props.valueKey] : option
}

function getOptionLabel(option) {
  return typeof option === 'object' ? option[props.labelKey] : option
}

function handleChange(event) {
  const { options, selectedIndex, value } = event.target
  const selectedOption = options[selectedIndex]
  const parsedValue = selectedOption && Object.prototype.hasOwnProperty.call(selectedOption, '_value')
    ? selectedOption._value
    : value

  emit('update:modelValue', parsedValue)
}
</script>
