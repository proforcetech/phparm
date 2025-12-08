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
              <div class="flex items-center gap-2">
                <Badge :variant="row.severity === 'out' ? 'danger' : row.severity === 'low' ? 'warning' : 'secondary'">
                  {{ row.stock_quantity }}
                </Badge>
                <span class="text-xs text-gray-500">Threshold {{ row.low_stock_threshold }}</span>
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

const router = useRouter()
const loading = ref(false)
const deletingId = ref(null)
const items = ref([])
const page = ref(1)
const perPage = 10
const hasNextPage = ref(false)

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
      severity: item.stock_quantity === 0 ? 'out' : item.stock_quantity <= item.low_stock_threshold ? 'low' : 'ok',
    }))
    hasNextPage.value = data.length === perPage
  } finally {
    loading.value = false
  }
}

const goToCreate = () => router.push('/inventory/create')
const editItem = (id) => router.push(`/inventory/${id}/edit`)
const goToAlerts = () => router.push('/inventory/alerts')

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
  } finally {
    deletingId.value = null
  }
}

onMounted(() => {
  loadItems()
})
</script>
