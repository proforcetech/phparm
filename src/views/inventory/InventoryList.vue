<template>
  <div>
    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Inventory</h1>
        <p class="mt-1 text-sm text-gray-500">Search, filter, and manage stock</p>
      </div>
      <Button @click="goToCreate">
        <svg class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
        </svg>
        Add Item
      </Button>
    </div>

    <div class="grid grid-cols-1 gap-4 xl:grid-cols-4">
      <Card class="xl:col-span-3">
        <div class="flex flex-col gap-4">
          <div class="grid grid-cols-1 gap-3 md:grid-cols-4">
            <div>
              <label class="block text-sm font-medium text-gray-700">Search</label>
              <Input v-model="filters.query" placeholder="Name or SKU" @input="refresh" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700">Category</label>
              <Input v-model="filters.category" placeholder="Brakes" @input="refresh" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700">Location</label>
              <Input v-model="filters.location" placeholder="Aisle 3" @input="refresh" />
            </div>
            <div class="flex items-end gap-2">
              <input
                id="lowStockOnly"
                v-model="filters.low_stock_only"
                type="checkbox"
                class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                @change="refresh"
              />
              <label for="lowStockOnly" class="text-sm text-gray-700">Show low stock only</label>
            </div>
          </div>

          <Table :columns="columns" :data="items" :loading="loading" hoverable>
            <template #cell(name)="{ row }">
              <div>
                <div class="font-semibold text-gray-900">{{ row.name }}</div>
                <p class="text-xs text-gray-500">SKU: {{ row.sku || '—' }}</p>
              </div>
            </template>
            <template #cell(stock_quantity)="{ row }">
              <div v-if="row.is_tracked" class="flex items-center gap-2">
                <Badge :variant="row.severity === 'out' ? 'danger' : row.severity === 'low' ? 'warning' : 'secondary'">
                  {{ row.stock_quantity }}
                </Badge>
                <span class="text-xs text-gray-500">Threshold {{ row.low_stock_threshold }}</span>
              </div>
              <div v-else class="flex items-center gap-2">
                <Badge variant="info">Catalog</Badge>
                <span class="text-xs text-gray-500">Not tracked</span>
              </div>
            </template>
            <template #cell(pricing)="{ row }">
              <div class="text-sm text-gray-800">
                <div>
                  <span class="font-semibold">Cost:</span> ${{ Number(row.cost).toFixed(2) }}
                </div>
                <div>
                  <span class="font-semibold">Price:</span> ${{ Number(row.sale_price).toFixed(2) }}
                </div>
                <div class="text-xs text-gray-500">Markup: {{ row.markup ?? '—' }}%</div>
              </div>
            </template>
            <template #cell(reorder_quantity)="{ row }">
              <div class="text-sm text-gray-900">{{ row.reorder_quantity || '—' }}</div>
              <p class="text-xs text-gray-500">Vendor: {{ row.vendor || '—' }}</p>
            </template>
            <template #actions="{ row }">
              <div class="flex gap-2">
                <Button size="sm" variant="secondary" @click="editItem(row.id)">Edit</Button>
                <Button size="sm" variant="danger" :loading="deletingId === row.id" @click="confirmDelete(row.id)">
                  Delete
                </Button>
              </div>
            </template>
            <template #empty>
              <p class="text-sm text-gray-500">No inventory items found for the current filters.</p>
            </template>
          </Table>

          <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
            <div class="text-sm text-gray-600">Page {{ page }} ({{ items.length }} items)</div>
            <div class="flex items-center gap-2">
              <Button :disabled="page === 1" variant="secondary" @click="previousPage">Previous</Button>
              <Button :disabled="!hasNextPage" variant="secondary" @click="nextPage">Next</Button>
            </div>
          </div>
        </div>
      </Card>

      <Card class="space-y-3">
        <h3 class="text-lg font-semibold text-gray-900">Quick actions</h3>
        <p class="text-sm text-gray-600">Import/export and alerts at a glance.</p>
        <div class="rounded-md bg-yellow-50 p-3 text-sm text-yellow-800">
          Low stock alerting is enabled. Use the toggle above to triage items that need restocking.
        </div>
        <Button variant="secondary" @click="goToAlerts">View Alerts</Button>
        <Button variant="secondary" @click="$router.push('/cp/inventory/pull-requests')">Pull Requests</Button>
        <div class="pt-2 border-t border-gray-100 space-y-2">
          <h4 class="text-sm font-semibold text-gray-900">CSV Import/Export</h4>
          <div class="space-y-2">
            <Button size="sm" variant="secondary" :loading="exportingCsv" @click="handleExportCsv" class="w-full">
              <svg class="h-4 w-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
              </svg>
              Export to CSV
            </Button>
            <Button size="sm" variant="secondary" :loading="importingCsv" @click="triggerFileUpload" class="w-full">
              <svg class="h-4 w-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
              </svg>
              Import from CSV
            </Button>
            <Button size="sm" variant="secondary" @click="downloadTemplate" class="w-full">
              <svg class="h-4 w-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
              </svg>
              Download Template
            </Button>
            <input
              ref="csvFileInput"
              type="file"
              accept=".csv"
              class="hidden"
              @change="handleFileSelect"
            />
          </div>
          <div v-if="importResult" class="mt-2 text-xs">
            <div v-if="importResult.created > 0" class="text-green-700">✓ Created: {{ importResult.created }}</div>
            <div v-if="importResult.updated > 0" class="text-blue-700">✓ Updated: {{ importResult.updated }}</div>
            <div v-if="importResult.duplicates > 0" class="text-yellow-700">⚠ Duplicates: {{ importResult.duplicates }}</div>
            <div v-if="importResult.failed > 0" class="text-red-700">✗ Failed: {{ importResult.failed }}</div>
          </div>
        </div>
        <div class="pt-2 border-t border-gray-100 space-y-2">
          <h4 class="text-sm font-semibold text-gray-900">Manage lists</h4>
          <div class="flex flex-wrap gap-2">
            <Button size="sm" variant="secondary" @click="$router.push('/cp/inventory/categories')">Categories</Button>
            <Button size="sm" variant="secondary" @click="$router.push('/cp/inventory/vendors')">Vendors</Button>
            <Button size="sm" variant="secondary" @click="$router.push('/cp/inventory/locations')">Locations</Button>
          </div>
        </div>
      </Card>
    </div>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import Badge from '@/components/ui/Badge.vue'
import Button from '@/components/ui/Button.vue'
import Card from '@/components/ui/Card.vue'
import Input from '@/components/ui/Input.vue'
import Table from '@/components/ui/Table.vue'
import inventoryService from '@/services/inventory.service'
import { useToast } from '@/stores/toast'

const router = useRouter()
const toast = useToast()
const loading = ref(false)
const deletingId = ref(null)
const items = ref([])
const page = ref(1)
const perPage = 10
const hasNextPage = ref(false)
const exportingCsv = ref(false)
const importingCsv = ref(false)
const csvFileInput = ref(null)
const importResult = ref(null)

const filters = reactive({ query: '', category: '', location: '', low_stock_only: false })

const columns = [
  { key: 'name', label: 'Item' },
  { key: 'category', label: 'Category' },
  { key: 'stock_quantity', label: 'Stock' },
  { key: 'pricing', label: 'Pricing' },
  { key: 'reorder_quantity', label: 'Reorder' },
]

const refresh = () => {
  page.value = 1
  loadItems()
}

const loadItems = async () => {
  loading.value = true
  try {
    const params = {
      limit: perPage,
      offset: (page.value - 1) * perPage,
    }

    Object.entries(filters).forEach(([key, value]) => {
      if (value) params[key] = value
    })

    const data = await inventoryService.list(params)
    items.value = data.map((item) => ({
      ...item,
      is_tracked: item.is_tracked !== false && item.is_tracked !== 0, // Handle boolean or int from API
      severity: !item.is_tracked ? 'ok' : item.stock_quantity === 0 ? 'out' : item.stock_quantity <= item.low_stock_threshold ? 'low' : 'ok',
    }))
    hasNextPage.value = data.length === perPage
  } catch (error) {
    console.error('Failed to load inventory list', error)
    toast.error(error.response?.data?.message || 'Failed to load inventory items')
    items.value = []
    hasNextPage.value = false
  } finally {
    loading.value = false
  }
}

const goToCreate = () => router.push('/cp/inventory/create')
const editItem = (id) => router.push(`/cp/inventory/${id}/edit`)
const goToAlerts = () => router.push('/cp/inventory/alerts')

const nextPage = () => {
  if (!hasNextPage.value) return
  page.value += 1
  loadItems()
}

const previousPage = () => {
  if (page.value === 1) return
  page.value -= 1
  loadItems()
}

const confirmDelete = async (id) => {
  if (!confirm('Delete this inventory item?')) return
  deletingId.value = id
  try {
    await inventoryService.remove(id)
    items.value = items.value.filter((item) => item.id !== id)
    toast.success('Inventory item deleted')
  } catch (error) {
    console.error('Failed to delete inventory item', error)
    toast.error(error.response?.data?.message || 'Failed to delete inventory item')
  } finally {
    deletingId.value = null
  }
}

const handleExportCsv = async () => {
  exportingCsv.value = true
  try {
    const blob = await inventoryService.exportCsv(filters)
    const url = window.URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = `inventory_export_${new Date().toISOString().split('T')[0]}.csv`
    document.body.appendChild(link)
    link.click()
    document.body.removeChild(link)
    window.URL.revokeObjectURL(url)
    toast.success('Inventory exported successfully')
  } catch (error) {
    console.error('Failed to export inventory', error)
    toast.error(error.response?.data?.message || 'Failed to export inventory')
  } finally {
    exportingCsv.value = false
  }
}

const downloadTemplate = () => {
  try {
    inventoryService.downloadTemplate()
    toast.success('Template downloaded')
  } catch (error) {
    console.error('Failed to download template', error)
    toast.error('Failed to download template')
  }
}

const triggerFileUpload = () => {
  importResult.value = null
  csvFileInput.value?.click()
}

const handleFileSelect = async (event) => {
  const file = event.target.files?.[0]
  if (!file) return

  if (!file.name.endsWith('.csv')) {
    toast.error('Please select a CSV file')
    return
  }

  importingCsv.value = true
  try {
    const csvContent = await file.text()

    const updateExisting = confirm(
      'Update existing items?\n\n' +
      'Click OK to update existing items (matched by SKU).\n' +
      'Click Cancel to skip duplicates and only create new items.'
    )

    const result = await inventoryService.importCsv(csvContent, updateExisting)
    importResult.value = result

    const messages = []
    if (result.created > 0) messages.push(`${result.created} created`)
    if (result.updated > 0) messages.push(`${result.updated} updated`)
    if (result.duplicates > 0 && !updateExisting) messages.push(`${result.duplicates} skipped (duplicates)`)
    if (result.failed > 0) messages.push(`${result.failed} failed`)

    if (result.failed === 0) {
      toast.success(`Import complete: ${messages.join(', ')}`)
    } else {
      toast.warning(`Import finished with errors: ${messages.join(', ')}`)
      if (result.errors?.length > 0) {
        console.error('Import errors:', result.errors)
      }
    }

    // Refresh the list after import
    if (result.created > 0 || result.updated > 0) {
      await loadItems()
    }
  } catch (error) {
    console.error('Failed to import CSV', error)
    toast.error(error.response?.data?.message || 'Failed to import CSV')
    importResult.value = null
  } finally {
    importingCsv.value = false
    // Reset file input
    if (csvFileInput.value) {
      csvFileInput.value.value = ''
    }
  }
}

onMounted(() => {
  loadItems()
})
</script>
