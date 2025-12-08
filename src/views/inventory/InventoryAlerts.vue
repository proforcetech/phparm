<template>
  <div>
    <div class="mb-6 flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Inventory Alerts</h1>
        <p class="mt-1 text-sm text-gray-500">Track low and out-of-stock items from the dashboard</p>
      </div>
      <Button variant="outline" @click="$router.push('/inventory')">
        Back to inventory
      </Button>
    </div>

    <Card class="mb-6">
      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div class="p-4 rounded-lg bg-red-50 border border-red-100">
          <p class="text-sm font-medium text-red-700">Out of Stock</p>
          <p class="mt-2 text-3xl font-bold text-red-800">{{ summary.out_of_stock }}</p>
          <p class="text-sm text-red-600">Items currently unavailable</p>
        </div>

        <div class="p-4 rounded-lg bg-amber-50 border border-amber-100">
          <p class="text-sm font-medium text-amber-700">Low Stock</p>
          <p class="mt-2 text-3xl font-bold text-amber-800">{{ summary.low_stock }}</p>
          <p class="text-sm text-amber-600">Items approaching threshold</p>
        </div>
      </div>
    </Card>

    <Card>
      <template #header>
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          <h3 class="text-lg font-medium text-gray-900">Alert Items</h3>
          <div class="flex gap-3 w-full sm:w-auto">
            <input
              v-model="query"
              type="text"
              placeholder="Search by name or SKU"
              class="flex-1 rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500"
              @keyup.enter="loadAlerts(true)"
            />
            <Button variant="primary" @click="loadAlerts(true)">Search</Button>
          </div>
        </div>
      </template>

      <div v-if="error" class="mb-4">
        <Alert variant="danger">{{ error }}</Alert>
      </div>

      <div v-if="loading" class="py-8 flex justify-center">
        <Loading text="Loading alerts..." />
      </div>

      <div v-else>
        <div v-if="items.length === 0" class="py-6 text-center text-gray-500">
          No low-stock items found for this filter.
        </div>

        <div v-else class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
              <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Item</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">SKU</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Location</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Stock</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Threshold</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
              </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
              <tr v-for="item in items" :key="item.id" class="hover:bg-gray-50">
                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">{{ item.name }}</td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ item.sku || '—' }}</td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ item.location || '—' }}</td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ item.stock_quantity }}</td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ item.low_stock_threshold }}</td>
                <td class="px-6 py-4 whitespace-nowrap text-sm">
                  <Badge :variant="item.severity === 'out' ? 'danger' : 'warning'">
                    {{ item.severity === 'out' ? 'Out of Stock' : 'Low Stock' }}
                  </Badge>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="flex items-center justify-between mt-6">
          <div class="text-sm text-gray-500">
            Showing {{ items.length }} results
          </div>
          <div class="flex gap-2">
            <Button variant="outline" :disabled="offset === 0" @click="previousPage">Previous</Button>
            <Button variant="outline" :disabled="!hasMore" @click="nextPage">Next</Button>
          </div>
        </div>
      </div>
    </Card>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import Card from '@/components/ui/Card.vue'
import Button from '@/components/ui/Button.vue'
import Badge from '@/components/ui/Badge.vue'
import Loading from '@/components/ui/Loading.vue'
import Alert from '@/components/ui/Alert.vue'
import inventoryService from '@/services/inventory.service'
import dashboardService from '@/services/dashboard.service'

const items = ref([])
const summary = ref({ out_of_stock: 0, low_stock: 0 })
const query = ref('')
const limit = ref(10)
const offset = ref(0)
const hasMore = ref(false)
const loading = ref(true)
const error = ref(null)

onMounted(() => {
  loadAlerts()
})

async function loadAlerts(reset = false) {
  try {
    loading.value = true
    error.value = null

    if (reset) {
      offset.value = 0
    }

    const listLimit = limit.value + 1
    const [alertList, tileData] = await Promise.all([
      inventoryService.getLowStock({
        query: query.value,
        limit: listLimit,
        offset: offset.value,
      }),
      dashboardService.getInventoryLowStockTile().catch(() => null),
    ])

    const normalizedList = Array.isArray(alertList?.data)
      ? alertList.data
      : Array.isArray(alertList)
        ? alertList
        : []

    hasMore.value = normalizedList.length > limit.value
    items.value = normalizedList.slice(0, limit.value)

    if (tileData?.counts) {
      summary.value = tileData.counts
    }
  } catch (err) {
    console.error('Failed to load alerts', err)
    error.value = 'Unable to load inventory alerts. Please try again.'
  } finally {
    loading.value = false
  }
}

function nextPage() {
  if (!hasMore.value) return
  offset.value += limit.value
  loadAlerts()
}

function previousPage() {
  if (offset.value === 0) return
  offset.value = Math.max(0, offset.value - limit.value)
  loadAlerts()
}
</script>
