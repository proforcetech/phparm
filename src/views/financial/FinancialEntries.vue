<template>
  <div class="p-6 space-y-6">
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-semibold text-gray-900">Purchases & Expenses</h1>
        <p class="text-sm text-gray-600">Track vendor spend, references, and categories with CSV export.</p>
      </div>
      <button
        class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700"
        @click="openForm()"
      >
        Add Entry
      </button>
    </div>

    <div class="bg-white shadow rounded p-4 space-y-4">
      <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
        <div>
          <label class="block text-sm font-medium text-gray-700">Type</label>
          <select v-model="filters.type" class="mt-1 w-full border-gray-300 rounded">
            <option value="">All</option>
            <option value="purchase">Purchase</option>
            <option value="expense">Expense</option>
            <option value="income">Income</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Category</label>
          <select v-model="filters.category" class="mt-1 w-full border-gray-300 rounded">
            <option value="">All</option>
            <option v-for="option in categoryOptions" :key="option.value" :value="option.value">
              {{ option.label }}
            </option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Vendor</label>
          <select v-model="filters.vendor_id" class="mt-1 w-full border-gray-300 rounded">
            <option value="">All</option>
            <option v-for="option in vendorOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Start Date</label>
          <input v-model="filters.start_date" type="date" class="mt-1 w-full border-gray-300 rounded" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">End Date</label>
          <input v-model="filters.end_date" type="date" class="mt-1 w-full border-gray-300 rounded" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Search</label>
          <input
            v-model="filters.search"
            type="text"
            placeholder="Vendor, reference, PO"
            class="mt-1 w-full border-gray-300 rounded"
          />
        </div>
      </div>
      <div class="flex gap-3">
        <button
          class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700"
          @click="fetchEntries"
        >
          Apply Filters
        </button>
        <button
          class="px-4 py-2 bg-gray-100 text-gray-700 rounded hover:bg-gray-200"
          @click="resetFilters"
        >
          Reset
        </button>
        <button
          class="ml-auto px-4 py-2 bg-green-600 text-white rounded hover:bg-green-700"
          @click="exportEntries"
        >
          Export CSV
        </button>
      </div>
    </div>

    <div class="bg-white shadow rounded overflow-hidden">
      <div class="hidden md:block">
        <table class="min-w-full divide-y divide-gray-200">
          <thead class="bg-gray-50">
            <tr>
              <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
              <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
              <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Category</th>
              <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Vendor</th>
              <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Reference</th>
              <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">PO</th>
              <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
              <th class="px-4 py-2" />
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-200">
            <tr v-for="entry in entries" :key="entry.id" class="hover:bg-gray-50">
              <td class="px-4 py-2 text-sm text-gray-900">{{ entry.entry_date }}</td>
              <td class="px-4 py-2 text-sm capitalize">{{ entry.type }}</td>
              <td class="px-4 py-2 text-sm">{{ entry.category }}</td>
              <td class="px-4 py-2 text-sm">{{ vendorLabel(entry) }}</td>
              <td class="px-4 py-2 text-sm">{{ entry.reference }}</td>
              <td class="px-4 py-2 text-sm">{{ entry.purchase_order }}</td>
              <td class="px-4 py-2 text-sm text-right font-semibold">${{ Number(entry.amount).toFixed(2) }}</td>
              <td class="px-4 py-2 text-right text-sm space-x-2">
                <button class="text-blue-600 hover:underline" @click="openForm(entry)">Edit</button>
                <button class="text-red-600 hover:underline" @click="confirmDelete(entry)">Delete</button>
              </td>
            </tr>
            <tr v-if="!entries.length && !loading">
              <td class="px-4 py-6 text-center text-sm text-gray-500" colspan="8">No entries found.</td>
            </tr>
            <tr v-if="loading">
              <td class="px-4 py-6 text-center text-sm text-gray-500" colspan="8">Loading...</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="entries.length" class="space-y-3 p-4 md:hidden">
        <div v-for="entry in entries" :key="entry.id" class="rounded border border-gray-200 bg-gray-50 p-3 shadow-sm">
          <div class="flex items-start justify-between gap-2">
            <div>
              <p class="text-sm font-semibold text-gray-900">{{ vendorLabel(entry) }}</p>
              <p class="text-xs text-gray-600">{{ entry.entry_date }} • {{ entry.category || 'Uncategorized' }}</p>
            </div>
            <span class="text-sm font-semibold text-gray-900">${{ Number(entry.amount).toFixed(2) }}</span>
          </div>
          <div class="mt-2 text-xs text-gray-700 space-y-1">
            <div class="capitalize">Type: {{ entry.type }}</div>
            <div>Reference: {{ entry.reference || '—' }}</div>
            <div>PO: {{ entry.purchase_order || '—' }}</div>
            <div>Description: {{ entry.description || '—' }}</div>
          </div>
          <div class="mt-3 flex gap-3 text-sm">
            <button class="text-blue-600 font-semibold" @click="openForm(entry)">Edit</button>
            <button class="text-red-600 font-semibold" @click="confirmDelete(entry)">Delete</button>
          </div>
        </div>
      </div>
      <div v-else-if="loading" class="p-4 text-center text-sm text-gray-500">Loading...</div>
      <div v-else class="p-4 text-center text-sm text-gray-500">No entries found.</div>

      <div class="flex items-center justify-between px-4 py-3 bg-gray-50">
        <div class="text-sm text-gray-600">Page {{ filters.page }}</div>
        <div class="space-x-2">
          <button
            class="px-3 py-1 bg-gray-100 rounded disabled:opacity-50"
            :disabled="filters.page === 1 || loading"
            @click="changePage(filters.page - 1)"
          >
            Previous
          </button>
          <button
            class="px-3 py-1 bg-gray-100 rounded disabled:opacity-50"
            :disabled="!hasMore || loading"
            @click="changePage(filters.page + 1)"
          >
            Next
          </button>
        </div>
      </div>
    </div>

    <div v-if="showForm" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-40">
      <div class="bg-white rounded shadow-lg w-full max-w-2xl p-6 space-y-4">
        <div class="flex items-center justify-between">
          <h2 class="text-lg font-semibold">{{ form.id ? 'Edit Entry' : 'Add Entry' }}</h2>
          <button class="text-gray-500 hover:text-gray-700" @click="closeForm">✕</button>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700">Type</label>
            <select v-model="form.type" class="mt-1 w-full border-gray-300 rounded">
              <option value="purchase">Purchase</option>
              <option value="expense">Expense</option>
              <option value="income">Income</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700">Category</label>
            <select v-model="form.category" class="mt-1 w-full border-gray-300 rounded">
              <option value="">Select category</option>
              <option v-for="option in categoryOptions" :key="option.value" :value="option.value">
                {{ option.label }}
              </option>
            </select>
            <p v-if="lookupsLoading.categories" class="mt-1 text-xs text-gray-500">Loading categories...</p>
            <p v-else-if="lookupError.categories" class="mt-1 text-xs text-red-600">{{ lookupError.categories }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700">Reference</label>
            <input v-model="form.reference" type="text" class="mt-1 w-full border-gray-300 rounded" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700">Purchase Order</label>
            <input v-model="form.purchase_order" type="text" class="mt-1 w-full border-gray-300 rounded" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700">Vendor</label>
            <select v-model="form.vendor_id" class="mt-1 w-full border-gray-300 rounded">
              <option value="">Select vendor</option>
              <option v-for="option in vendorOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
            </select>
            <p v-if="lookupsLoading.vendors" class="mt-1 text-xs text-gray-500">Loading vendors...</p>
            <p v-else-if="lookupError.vendors" class="mt-1 text-xs text-red-600">{{ lookupError.vendors }}</p>
            <p v-else-if="legacyVendorName" class="mt-1 text-xs text-amber-600">Legacy vendor: {{ legacyVendorName }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700">Amount</label>
            <input v-model.number="form.amount" type="number" step="0.01" class="mt-1 w-full border-gray-300 rounded" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700">Entry Date</label>
            <input v-model="form.entry_date" type="date" class="mt-1 w-full border-gray-300 rounded" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700">Description</label>
            <textarea v-model="form.description" class="mt-1 w-full border-gray-300 rounded" rows="2"></textarea>
          </div>
        </div>
        <div class="space-y-2">
          <label class="block text-sm font-medium text-gray-700">Attachment</label>
          <div class="flex flex-col gap-2">
            <input
              type="file"
              accept="application/pdf,image/png,image/jpeg"
              @change="handleFileChange"
            />
            <div v-if="form.attachment_path && !removeAttachment" class="flex items-center gap-3 text-sm text-gray-700">
              <a :href="form.attachment_path" class="text-blue-600 hover:underline" target="_blank" rel="noopener">View current file</a>
              <button class="text-red-600 hover:underline" type="button" @click="markAttachmentRemoval">Remove</button>
            </div>
            <div v-else-if="removeAttachment" class="text-sm text-gray-500">Attachment will be removed</div>
            <div v-else-if="pendingFile" class="text-sm text-gray-700">{{ pendingFile.name }}</div>
            <p class="text-xs text-gray-500">PDF or image files only.</p>
          </div>
        </div>
        <div class="flex justify-end space-x-3">
          <button class="px-4 py-2 bg-gray-100 rounded" @click="closeForm">Cancel</button>
          <button class="px-4 py-2 bg-blue-600 text-white rounded" @click="saveEntry">
            {{ form.id ? 'Update' : 'Create' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { reactive, ref, onMounted, computed } from 'vue'
import financialService from '@/services/financial.service'
import inventoryMetaService from '@/services/inventory-meta.service'
import financialVendorService from '@/services/financial-vendor.service'
import { useToast } from '@/stores/toast'

const toast = useToast()
const entries = ref([])
const loading = ref(false)
const showForm = ref(false)
const hasMore = ref(false)
const categoryOptions = ref([])
const vendorOptions = ref([])
const lookupsLoading = reactive({ categories: false, vendors: false })
const lookupError = reactive({ categories: '', vendors: '' })
const legacyVendorName = ref('')
const vendorLookup = computed(() => new Map(vendorOptions.value.map((option) => [option.value, option.label])))
const filters = reactive({
  type: '',
  category: '',
  vendor_id: '',
  start_date: '',
  end_date: '',
  search: '',
  page: 1,
  per_page: 25,
})

const form = reactive({
  id: null,
  type: 'expense',
  category: '',
  reference: '',
  purchase_order: '',
  vendor_id: '',
  amount: 0,
  entry_date: '',
  description: '',
  attachment_path: null,
})
const pendingFile = ref(null)
const removeAttachment = ref(false)

onMounted(fetchEntries)
onMounted(loadLookups)

function loadLookups() {
  loadLookup('categories', categoryOptions)
  loadCategories()
  loadVendors()
}

async function loadCategories() {
  lookupsLoading.categories = true
  lookupError.categories = ''
  try {
    const data = await inventoryMetaService.list(type)
    target.value = data.map((item) => ({ label: item.name, value: item.name }))
    const data = await financialService.listCategories()
    categoryOptions.value = data.map((item) => ({
      label: item.name,
      value: item.name,
      type: item.type,
    }))
  } catch (err) {
    console.error(err)
    lookupError.categories = 'Unable to load categories'
  } finally {
    lookupsLoading.categories = false
  }
}

async function loadVendors() {
  lookupsLoading.vendors = true
  lookupError.vendors = ''
  try {
    const data = await inventoryMetaService.list('vendors', { parts_supplier: true })
    vendorOptions.value = data.map((item) => ({ label: item.name, value: item.name }))
  } catch (err) {
    console.error(err)
    lookupError.vendors = 'Unable to load vendors'
  } finally {
    lookupsLoading.vendors = false
  }
}

async function loadVendors() {
  lookupsLoading.vendors = true
  lookupError.vendors = ''
  try {
    const data = await financialVendorService.list()
    const items = Array.isArray(data) ? data : data.data || []
    vendorOptions.value = items.map((item) => ({ label: item.name, value: item.id }))
  } catch (err) {
    console.error(err)
    lookupError.vendors = 'Unable to load vendors'
  } finally {
    lookupsLoading.vendors = false
  }
}

function fetchEntries() {
  loading.value = true
  const params = { ...filters }
  const selectedVendor = vendorLookup.value.get(filters.vendor_id)
  if (selectedVendor) {
    params.vendor = selectedVendor
  }
  financialService
    .list(params)
    .then((res) => {
      entries.value = res.data || []
      hasMore.value = entries.value.length === filters.per_page
    })
    .catch(() => toast.error('Failed to load entries'))
    .finally(() => {
      loading.value = false
    })
}

function resetFilters() {
  filters.type = ''
  filters.category = ''
  filters.vendor_id = ''
  filters.start_date = ''
  filters.end_date = ''
  filters.search = ''
  filters.page = 1
  fetchEntries()
}

function changePage(page) {
  filters.page = Math.max(1, page)
  fetchEntries()
}

function handleFileChange(event) {
  const file = event.target?.files?.[0]
  pendingFile.value = file || null
  if (file) {
    removeAttachment.value = false
  }
}

function markAttachmentRemoval() {
  removeAttachment.value = true
  pendingFile.value = null
}

function openForm(entry = null) {
  if (entry) {
    const matchedVendor = entry.vendor_id
      ? entry.vendor_id
      : vendorOptions.value.find((option) => option.label === entry.vendor)?.value || ''
    Object.assign(form, {
      ...entry,
      vendor_id: matchedVendor || '',
    })
    legacyVendorName.value = entry.vendor && !matchedVendor ? entry.vendor : ''
    pendingFile.value = null
    removeAttachment.value = false
  } else {
    Object.assign(form, {
      id: null,
      type: 'expense',
      category: '',
      reference: '',
      purchase_order: '',
      vendor_id: '',
      amount: 0,
      entry_date: '',
      description: '',
      attachment_path: null,
    })
    legacyVendorName.value = ''
    pendingFile.value = null
    removeAttachment.value = false
  }
  showForm.value = true
}

function closeForm() {
  showForm.value = false
}

async function saveEntry() {
  const selectedVendorLabel = vendorLookup.value.get(form.vendor_id)
  const payload = { ...form }
  if (selectedVendorLabel) {
    payload.vendor = selectedVendorLabel
  } else if (legacyVendorName.value) {
    payload.vendor = legacyVendorName.value
  }
  try {
    const saved = payload.id
      ? await financialService.update(payload.id, payload)
      : await financialService.create(payload)

    const entryId = saved.id || payload.id

    if (removeAttachment.value && entryId) {
      await financialService.removeAttachment(entryId)
      form.attachment_path = null
    }

    if (pendingFile.value && entryId) {
      const uploaded = await financialService.uploadAttachment(entryId, pendingFile.value)
      form.attachment_path = uploaded.path
    }

    toast.success('Entry saved')
    showForm.value = false
    fetchEntries()
  } catch (err) {
    console.error(err)
    toast.error('Failed to save entry')
  }
}

function confirmDelete(entry) {
  if (!confirm('Delete this entry?')) return
  financialService
    .destroy(entry.id)
    .then(() => {
      toast.success('Entry deleted')
      fetchEntries()
    })
    .catch(() => toast.error('Failed to delete entry'))
}

function exportEntries() {
  const params = { ...filters }
  const selectedVendor = vendorLookup.value.get(filters.vendor_id)
  if (selectedVendor) {
    params.vendor = selectedVendor
  }
  financialService
    .exportEntries(params)
    .then((res) => {
      const rows = res.data
      if (!rows || !rows.length) {
        toast.info('No data to export')
        return
      }
      const header = Object.keys(rows[0])
      const csvRows = [header.join(',')]
      rows.forEach((row) => {
        csvRows.push(header.map((key) => `"${(row[key] ?? '').toString().replace('"', '""')}"`).join(','))
      })
      const blob = new Blob([csvRows.join('\n')], { type: 'text/csv;charset=utf-8;' })
      const url = URL.createObjectURL(blob)
      const link = document.createElement('a')
      link.href = url
      link.setAttribute('download', res.filename || 'financial-entries.csv')
      document.body.appendChild(link)
      link.click()
      document.body.removeChild(link)
      URL.revokeObjectURL(url)
    })
    .catch(() => toast.error('Failed to export entries'))
}

function vendorLabel(entry) {
  return (
    entry.vendor_name ||
    entry.vendor ||
    vendorLookup.value.get(entry.vendor_id) ||
    'Unknown vendor'
  )
}
</script>
