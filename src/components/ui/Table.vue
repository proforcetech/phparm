<template>
  <div class="flex flex-col">
    <!-- Table Container -->
    <div class="overflow-x-auto">
      <div class="inline-block min-w-full align-middle">
        <div class="overflow-hidden shadow ring-1 ring-black ring-opacity-5 rounded-lg">
          <table class="min-w-full divide-y divide-gray-300">
            <!-- Header -->
            <thead class="bg-gray-50">
              <tr>
                <!-- Selection checkbox column -->
                <th v-if="selectable" scope="col" class="relative px-4 py-3.5 sm:w-12 sm:px-6">
                  <input
                    type="checkbox"
                    :checked="allSelected"
                    :indeterminate="someSelected"
                    @change="toggleAll"
                    class="absolute left-4 top-1/2 -mt-2 h-4 w-4 rounded border-gray-300 text-primary-600 focus:ring-primary-600"
                  />
                </th>

                <!-- Column headers -->
                <th
                  v-for="column in columns"
                  :key="column.key"
                  scope="col"
                  class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900"
                  :class="[column.sortable ? 'cursor-pointer select-none hover:bg-gray-100' : '']"
                  @click="column.sortable && sort(column.key)"
                >
                  <div class="flex items-center gap-2">
                    <span>{{ column.label }}</span>
                    <span v-if="column.sortable && sortKey === column.key" class="flex-none">
                      <svg v-if="sortOrder === 'asc'" class="h-4 w-4 text-gray-400" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M14.707 12.707a1 1 0 01-1.414 0L10 9.414l-3.293 3.293a1 1 0 01-1.414-1.414l4-4a1 1 0 011.414 0l4 4a1 1 0 010 1.414z" clip-rule="evenodd" />
                      </svg>
                      <svg v-else class="h-4 w-4 text-gray-400" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                      </svg>
                    </span>
                  </div>
                </th>

                <!-- Actions column -->
                <th v-if="$slots.actions" scope="col" class="relative py-3.5 pl-3 pr-4 sm:pr-6">
                  <span class="sr-only">Actions</span>
                </th>
              </tr>
            </thead>

            <!-- Body -->
            <tbody class="divide-y divide-gray-200 bg-white">
              <!-- Loading state -->
              <tr v-if="loading">
                <td :colspan="columnCount" class="px-3 py-12 text-center">
                  <div class="flex justify-center">
                    <Loading size="lg" />
                  </div>
                </td>
              </tr>

              <!-- Empty state -->
              <tr v-else-if="data.length === 0">
                <td :colspan="columnCount" class="px-3 py-12 text-center">
                  <div class="text-gray-500">
                    <slot name="empty">
                      <p class="text-sm">No data available</p>
                    </slot>
                  </div>
                </td>
              </tr>

              <!-- Data rows -->
              <tr
                v-else
                v-for="(row, rowIndex) in data"
                :key="getRowKey(row, rowIndex)"
                :class="[
                  rowIndex % 2 === 0 ? 'bg-white' : 'bg-gray-50',
                  hoverable ? 'hover:bg-gray-100 cursor-pointer' : ''
                ]"
                @click="handleRowClick(row)"
              >
                <!-- Selection checkbox -->
                <td v-if="selectable" class="relative px-4 sm:w-12 sm:px-6">
                  <input
                    type="checkbox"
                    :checked="isSelected(row)"
                    @change="toggleRow(row)"
                    @click.stop
                    class="absolute left-4 top-1/2 -mt-2 h-4 w-4 rounded border-gray-300 text-primary-600 focus:ring-primary-600"
                  />
                </td>

                <!-- Data cells -->
                <td
                  v-for="column in columns"
                  :key="column.key"
                  class="whitespace-nowrap px-3 py-4 text-sm text-gray-900"
                >
                  <slot :name="`cell(${column.key})`" :row="row" :value="getCellValue(row, column.key)">
                    {{ getCellValue(row, column.key) }}
                  </slot>
                </td>

                <!-- Actions -->
                <td v-if="$slots.actions" class="relative whitespace-nowrap py-4 pl-3 pr-4 text-right text-sm font-medium sm:pr-6">
                  <slot name="actions" :row="row" />
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Pagination -->
    <div v-if="pagination && !loading" class="flex items-center justify-between border-t border-gray-200 bg-white px-4 py-3 sm:px-6 mt-4">
      <div class="flex flex-1 justify-between sm:hidden">
        <button
          @click="previousPage"
          :disabled="currentPage === 1"
          class="relative inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed"
        >
          Previous
        </button>
        <button
          @click="nextPage"
          :disabled="currentPage === totalPages"
          class="relative ml-3 inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed"
        >
          Next
        </button>
      </div>
      <div class="hidden sm:flex sm:flex-1 sm:items-center sm:justify-between">
        <div>
          <p class="text-sm text-gray-700">
            Showing
            <span class="font-medium">{{ startIndex }}</span>
            to
            <span class="font-medium">{{ endIndex }}</span>
            of
            <span class="font-medium">{{ total }}</span>
            results
          </p>
        </div>
        <div>
          <nav class="isolate inline-flex -space-x-px rounded-md shadow-sm" aria-label="Pagination">
            <button
              @click="previousPage"
              :disabled="currentPage === 1"
              class="relative inline-flex items-center rounded-l-md px-2 py-2 text-gray-400 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 focus:z-20 focus:outline-offset-0 disabled:opacity-50 disabled:cursor-not-allowed"
            >
              <span class="sr-only">Previous</span>
              <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd" />
              </svg>
            </button>

            <button
              v-for="page in displayedPages"
              :key="page"
              @click="goToPage(page)"
              :class="[
                page === currentPage
                  ? 'z-10 bg-primary-600 text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600'
                  : 'text-gray-900 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 focus:outline-offset-0',
                'relative inline-flex items-center px-4 py-2 text-sm font-semibold focus:z-20'
              ]"
            >
              {{ page }}
            </button>

            <button
              @click="nextPage"
              :disabled="currentPage === totalPages"
              class="relative inline-flex items-center rounded-r-md px-2 py-2 text-gray-400 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 focus:z-20 focus:outline-offset-0 disabled:opacity-50 disabled:cursor-not-allowed"
            >
              <span class="sr-only">Next</span>
              <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd" />
              </svg>
            </button>
          </nav>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch, useSlots } from 'vue'
import Loading from './Loading.vue'

const props = defineProps({
  columns: {
    type: Array,
    default: () => [],
    // Format: [{ key: 'id', label: 'ID', sortable: true }, ...]
  },
  data: {
    type: Array,
    default: () => [],
  },
  loading: {
    type: Boolean,
    default: false,
  },
  selectable: {
    type: Boolean,
    default: false,
  },
  hoverable: {
    type: Boolean,
    default: true,
  },
  rowKey: {
    type: String,
    default: 'id',
  },
  pagination: {
    type: Boolean,
    default: false,
  },
  perPage: {
    type: Number,
    default: 10,
  },
  total: {
    type: Number,
    default: 0,
  },
  currentPage: {
    type: Number,
    default: 1,
  },
})

const slots = useSlots()

const emit = defineEmits([
  'row-click',
  'sort',
  'selection-change',
  'page-change',
])

// Sorting
const sortKey = ref('')
const sortOrder = ref('asc')

function sort(key) {
  if (sortKey.value === key) {
    sortOrder.value = sortOrder.value === 'asc' ? 'desc' : 'asc'
  } else {
    sortKey.value = key
    sortOrder.value = 'asc'
  }
  emit('sort', { key: sortKey.value, order: sortOrder.value })
}

// Selection
const selectedRows = ref([])

const allSelected = computed(() => {
  return props.data.length > 0 && selectedRows.value.length === props.data.length
})

const someSelected = computed(() => {
  return selectedRows.value.length > 0 && selectedRows.value.length < props.data.length
})

function isSelected(row) {
  return selectedRows.value.some(r => getRowKey(r) === getRowKey(row))
}

function toggleRow(row) {
  const key = getRowKey(row)
  const index = selectedRows.value.findIndex(r => getRowKey(r) === key)

  if (index > -1) {
    selectedRows.value.splice(index, 1)
  } else {
    selectedRows.value.push(row)
  }

  emit('selection-change', selectedRows.value)
}

function toggleAll() {
  if (allSelected.value) {
    selectedRows.value = []
  } else {
    selectedRows.value = [...props.data]
  }
  emit('selection-change', selectedRows.value)
}

// Pagination
const totalPages = computed(() => Math.ceil(props.total / props.perPage))

const startIndex = computed(() => (props.currentPage - 1) * props.perPage + 1)
const endIndex = computed(() => Math.min(props.currentPage * props.perPage, props.total))

const displayedPages = computed(() => {
  const pages = []
  const maxPages = 7

  if (totalPages.value <= maxPages) {
    for (let i = 1; i <= totalPages.value; i++) {
      pages.push(i)
    }
  } else {
    if (props.currentPage <= 4) {
      for (let i = 1; i <= 5; i++) pages.push(i)
      pages.push('...')
      pages.push(totalPages.value)
    } else if (props.currentPage >= totalPages.value - 3) {
      pages.push(1)
      pages.push('...')
      for (let i = totalPages.value - 4; i <= totalPages.value; i++) pages.push(i)
    } else {
      pages.push(1)
      pages.push('...')
      for (let i = props.currentPage - 1; i <= props.currentPage + 1; i++) pages.push(i)
      pages.push('...')
      pages.push(totalPages.value)
    }
  }

  return pages.filter(p => p !== '...')
})

function goToPage(page) {
  if (page !== props.currentPage) {
    emit('page-change', page)
  }
}

function previousPage() {
  if (props.currentPage > 1) {
    emit('page-change', props.currentPage - 1)
  }
}

function nextPage() {
  if (props.currentPage < totalPages.value) {
    emit('page-change', props.currentPage + 1)
  }
}

// Utilities
const columnCount = computed(() => {
  let count = props.columns?.length ?? 0
  if (props.selectable) count++
  if (slots.actions) count++
  return count
})

function getRowKey(row, index) {
  return row[props.rowKey] ?? index
}

function getCellValue(row, key) {
  return key.split('.').reduce((obj, k) => obj?.[k], row)
}

function handleRowClick(row) {
  if (props.hoverable) {
    emit('row-click', row)
  }
}

// Clear selection when data changes
watch(() => props.data, () => {
  selectedRows.value = []
})
</script>
