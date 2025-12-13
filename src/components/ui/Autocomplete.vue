<template>
  <div :class="{ 'w-full': fullWidth }" class="relative">
    <label v-if="label" :for="id" class="block text-sm font-medium text-gray-700 mb-1">
      {{ label }}
      <span v-if="required" class="text-red-500">*</span>
    </label>

    <div class="relative">
      <input
        :id="id"
        ref="inputRef"
        type="text"
        :value="displayValue"
        :placeholder="placeholder"
        :disabled="disabled"
        :required="required"
        :class="inputClasses"
        autocomplete="off"
        @input="onInput"
        @focus="onFocus"
        @blur="onBlur"
        @keydown.down.prevent="navigateDown"
        @keydown.up.prevent="navigateUp"
        @keydown.enter.prevent="selectHighlighted"
        @keydown.escape="closeDropdown"
      />

      <!-- Loading Spinner -->
      <div v-if="loading" class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none">
        <svg class="animate-spin h-4 w-4 text-gray-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
          <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
        </svg>
      </div>

      <!-- Clear Button -->
      <div v-else-if="modelValue && !disabled" class="absolute inset-y-0 right-0 pr-3 flex items-center">
        <button
          type="button"
          @click.stop="clearSelection"
          class="text-gray-400 hover:text-gray-600"
        >
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      </div>

      <!-- Dropdown -->
      <div
        v-if="showDropdown && (results.length > 0 || loading)"
        class="absolute z-50 mt-1 w-full bg-white shadow-lg max-h-60 rounded-md py-1 text-base ring-1 ring-black ring-opacity-5 overflow-auto focus:outline-none sm:text-sm"
      >
        <div v-if="loading" class="px-4 py-2 text-sm text-gray-500">
          Searching...
        </div>
        <div
          v-else
          v-for="(item, index) in results"
          :key="getItemValue(item)"
          :class="[
            'cursor-pointer select-none relative py-2 px-4 hover:bg-indigo-50',
            index === highlightedIndex ? 'bg-indigo-50' : ''
          ]"
          @mousedown.prevent="selectItem(item)"
          @mouseenter="highlightedIndex = index"
        >
          <slot name="item" :item="item">
            <div>
              <div class="font-medium text-gray-900">{{ getItemLabel(item) }}</div>
              <div v-if="getItemSubtext(item)" class="text-sm text-gray-500">{{ getItemSubtext(item) }}</div>
            </div>
          </slot>
        </div>
      </div>

      <!-- No Results -->
      <div
        v-if="showDropdown && !loading && results.length === 0 && searchQuery"
        class="absolute z-50 mt-1 w-full bg-white shadow-lg rounded-md py-2 px-4 text-base ring-1 ring-black ring-opacity-5 sm:text-sm"
      >
        <div class="text-sm text-gray-500">No results found</div>
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
import { ref, computed, watch } from 'vue'

const props = defineProps({
  id: {
    type: String,
    default: () => `autocomplete-${Math.random().toString(36).substr(2, 9)}`,
  },
  modelValue: {
    type: [String, Number, Object],
    default: null,
  },
  label: {
    type: String,
    default: '',
  },
  placeholder: {
    type: String,
    default: 'Search...',
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
  // Search function that returns a promise with results
  searchFn: {
    type: Function,
    required: true,
  },
  // Function to get the value from an item
  itemValue: {
    type: [String, Function],
    default: 'id',
  },
  // Function to get the label from an item
  itemLabel: {
    type: [String, Function],
    default: 'name',
  },
  // Function to get subtext from an item (optional)
  itemSubtext: {
    type: [String, Function],
    default: null,
  },
  // Minimum characters before searching
  minChars: {
    type: Number,
    default: 1,
  },
  // Debounce delay in ms
  debounce: {
    type: Number,
    default: 300,
  },
})

const emit = defineEmits(['update:modelValue', 'select'])

const inputRef = ref(null)
const searchQuery = ref('')
const results = ref([])
const loading = ref(false)
const showDropdown = ref(false)
const highlightedIndex = ref(0)
const selectedItem = ref(null)
let debounceTimer = null

const inputClasses = computed(() => {
  const base = 'block w-full rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm'
  const state = props.error
    ? 'border-red-300 text-red-900 placeholder-red-300'
    : 'border-gray-300'
  const padding = 'pr-10'

  return `${base} ${state} ${padding}`
})

const displayValue = computed(() => {
  if (selectedItem.value) {
    return getItemLabel(selectedItem.value)
  }
  return searchQuery.value
})

function getItemValue(item) {
  if (typeof props.itemValue === 'function') {
    return props.itemValue(item)
  }
  return item[props.itemValue]
}

function getItemLabel(item) {
  if (typeof props.itemLabel === 'function') {
    return props.itemLabel(item)
  }
  return item[props.itemLabel]
}

function getItemSubtext(item) {
  if (!props.itemSubtext) return null
  if (typeof props.itemSubtext === 'function') {
    return props.itemSubtext(item)
  }
  return item[props.itemSubtext]
}

async function performSearch(query) {
  if (query.length < props.minChars) {
    results.value = []
    return
  }

  loading.value = true
  try {
    const data = await props.searchFn(query)
    results.value = data || []
    highlightedIndex.value = 0
  } catch (error) {
    console.error('Search failed:', error)
    results.value = []
  } finally {
    loading.value = false
  }
}

function onInput(event) {
  const value = event.target.value
  searchQuery.value = value
  selectedItem.value = null
  emit('update:modelValue', null)
  showDropdown.value = true

  clearTimeout(debounceTimer)
  debounceTimer = setTimeout(() => {
    performSearch(value)
  }, props.debounce)
}

function onFocus() {
  showDropdown.value = true
  if (searchQuery.value.length >= props.minChars && results.value.length === 0) {
    performSearch(searchQuery.value)
  }
}

function onBlur() {
  // Delay to allow click events on dropdown items
  setTimeout(() => {
    showDropdown.value = false
  }, 200)
}

function selectItem(item) {
  selectedItem.value = item
  const value = getItemValue(item)
  emit('update:modelValue', value)
  emit('select', item)
  showDropdown.value = false
  searchQuery.value = getItemLabel(item)
}

function clearSelection() {
  selectedItem.value = null
  searchQuery.value = ''
  emit('update:modelValue', null)
  results.value = []
  showDropdown.value = false
  inputRef.value?.focus()
}

function navigateDown() {
  if (highlightedIndex.value < results.value.length - 1) {
    highlightedIndex.value++
  }
}

function navigateUp() {
  if (highlightedIndex.value > 0) {
    highlightedIndex.value--
  }
}

function selectHighlighted() {
  if (results.value[highlightedIndex.value]) {
    selectItem(results.value[highlightedIndex.value])
  }
}

function closeDropdown() {
  showDropdown.value = false
}

// Watch for external changes to modelValue
watch(() => props.modelValue, async (newValue) => {
  if (newValue && !selectedItem.value) {
    // If we have a value but no selected item, we might need to fetch it
    // This handles the case when editing an existing record
    loading.value = true
    try {
      const data = await props.searchFn(String(newValue))
      const item = data?.find(d => getItemValue(d) === newValue)
      if (item) {
        selectedItem.value = item
        searchQuery.value = getItemLabel(item)
      }
    } catch (error) {
      console.error('Failed to load initial value:', error)
    } finally {
      loading.value = false
    }
  } else if (!newValue) {
    selectedItem.value = null
    searchQuery.value = ''
  }
}, { immediate: true })
</script>
