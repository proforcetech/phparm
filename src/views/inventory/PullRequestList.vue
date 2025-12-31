<template>
  <div>
    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Inventory Pull Requests</h1>
        <p class="mt-1 text-sm text-gray-500">Manage parts and supply requests for workorders</p>
      </div>
      <div class="flex items-center gap-3">
        <Button variant="secondary" @click="$router.push('/cp/inventory')">
          Back to Inventory
        </Button>
      </div>
    </div>

    <!-- Summary Cards -->
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-4 mb-6">
      <Card class="text-center">
        <div class="text-3xl font-bold text-blue-600">{{ summary.pending_pulls }}</div>
        <div class="text-sm text-gray-600">Ready to Pull</div>
      </Card>
      <Card class="text-center">
        <div class="text-3xl font-bold text-yellow-600">{{ summary.pending_orders }}</div>
        <div class="text-sm text-gray-600">Need Ordering</div>
      </Card>
      <Card class="text-center">
        <div class="text-3xl font-bold text-purple-600">{{ summary.on_order }}</div>
        <div class="text-sm text-gray-600">On Order</div>
      </Card>
      <Card class="text-center">
        <div class="text-3xl font-bold text-gray-600">{{ summary.total_open }}</div>
        <div class="text-sm text-gray-600">Total Open</div>
      </Card>
    </div>

    <Card>
      <!-- Filters -->
      <div class="flex flex-col gap-4 mb-4">
        <div class="grid grid-cols-1 gap-3 md:grid-cols-4">
          <div>
            <label class="block text-sm font-medium text-gray-700">Status</label>
            <Select
              v-model="filters.status"
              :options="statusOptions"
              placeholder="All statuses"
              @update:modelValue="refresh"
            />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700">Type</label>
            <Select
              v-model="filters.request_type"
              :options="typeOptions"
              placeholder="All types"
              @update:modelValue="refresh"
            />
          </div>
          <div class="flex items-end gap-2">
            <input
              id="pendingOnly"
              v-model="filters.pending_only"
              type="checkbox"
              class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
              @change="refresh"
            />
            <label for="pendingOnly" class="text-sm text-gray-700">Show open requests only</label>
          </div>
        </div>
      </div>

      <!-- Table -->
      <Table :columns="columns" :data="items" :loading="loading" hoverable>
        <template #cell(description)="{ row }">
          <div>
            <div class="font-semibold text-gray-900">{{ row.description }}</div>
            <p class="text-xs text-gray-500">
              SKU: {{ row.sku || '—' }}
              <span v-if="row.inventory_item_name" class="ml-2">
                ({{ row.inventory_item_name }})
              </span>
            </p>
          </div>
        </template>

        <template #cell(workorder)="{ row }">
          <router-link
            :to="`/cp/workorders/${row.workorder_id}`"
            class="text-indigo-600 hover:text-indigo-800 font-medium"
          >
            {{ row.workorder_number }}
          </router-link>
          <p v-if="row.job_description" class="text-xs text-gray-500 truncate max-w-[150px]">
            {{ row.job_description }}
          </p>
        </template>

        <template #cell(quantity)="{ row }">
          <div class="text-sm">
            <span class="font-medium">{{ row.quantity_fulfilled }}</span>
            <span class="text-gray-500"> / {{ row.quantity_requested }}</span>
          </div>
        </template>

        <template #cell(request_type)="{ row }">
          <Badge :variant="row.request_type === 'pull' ? 'success' : 'warning'">
            {{ row.request_type === 'pull' ? 'Pull from Stock' : 'Order Required' }}
          </Badge>
        </template>

        <template #cell(status)="{ row }">
          <Badge :variant="getStatusVariant(row.status)">
            {{ formatStatus(row.status) }}
          </Badge>
        </template>

        <template #cell(pricing)="{ row }">
          <div class="text-sm text-gray-800">
            <div>
              <span class="font-semibold">Cost:</span> ${{ Number(row.unit_cost).toFixed(2) }}
            </div>
            <div>
              <span class="font-semibold">Price:</span> ${{ Number(row.unit_price).toFixed(2) }}
            </div>
          </div>
        </template>

        <template #actions="{ row }">
          <div class="flex gap-2">
            <!-- Pull from inventory -->
            <Button
              v-if="row.status === 'pending' && row.request_type === 'pull'"
              size="sm"
              variant="success"
              :loading="processingId === row.id"
              @click="pullFromStock(row)"
            >
              Pull
            </Button>

            <!-- Mark as ordered -->
            <Button
              v-if="row.status === 'pending' && row.request_type === 'order'"
              size="sm"
              variant="primary"
              :loading="processingId === row.id"
              @click="showOrderModal(row)"
            >
              Order
            </Button>

            <!-- Mark as received -->
            <Button
              v-if="row.status === 'ordered'"
              size="sm"
              variant="success"
              :loading="processingId === row.id"
              @click="markReceived(row)"
            >
              Received
            </Button>

            <!-- Cancel -->
            <Button
              v-if="['pending', 'ordered'].includes(row.status)"
              size="sm"
              variant="ghost"
              :loading="processingId === row.id"
              @click="cancelRequest(row)"
              title="Cancel request"
            >
              <svg class="h-4 w-4 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </Button>
          </div>
        </template>

        <template #empty>
          <p class="text-sm text-gray-500">No pull requests found.</p>
        </template>
      </Table>

      <!-- Pagination -->
      <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between mt-4">
        <div class="text-sm text-gray-600">
          Showing {{ items.length }} of {{ total }} requests
        </div>
        <div class="flex items-center gap-2">
          <Button :disabled="page === 1" variant="secondary" @click="previousPage">Previous</Button>
          <Button :disabled="!hasNextPage" variant="secondary" @click="nextPage">Next</Button>
        </div>
      </div>
    </Card>

    <!-- Order Modal -->
    <Modal v-model="showOrderModalFlag" @close="showOrderModalFlag = false">
      <template #title>Mark as Ordered</template>
      <template #content>
        <div class="space-y-4">
          <p class="text-sm text-gray-600">
            Mark this item as ordered: <strong>{{ selectedRequest?.description }}</strong>
          </p>
          <div>
            <label class="block text-sm font-medium text-gray-700">Order Reference (Optional)</label>
            <Input
              v-model="orderForm.order_reference"
              placeholder="PO number or order reference"
              class="mt-1"
            />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700">Vendor</label>
            <Input
              v-model="orderForm.vendor"
              :placeholder="selectedRequest?.vendor || 'Vendor name'"
              class="mt-1"
            />
          </div>
        </div>
      </template>
      <template #footer>
        <div class="flex justify-end gap-3">
          <Button variant="outline" @click="showOrderModalFlag = false">Cancel</Button>
          <Button @click="confirmOrder" :disabled="ordering">
            {{ ordering ? 'Processing...' : 'Mark as Ordered' }}
          </Button>
        </div>
      </template>
    </Modal>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import Badge from '@/components/ui/Badge.vue'
import Button from '@/components/ui/Button.vue'
import Card from '@/components/ui/Card.vue'
import Input from '@/components/ui/Input.vue'
import Select from '@/components/ui/Select.vue'
import Table from '@/components/ui/Table.vue'
import Modal from '@/components/ui/Modal.vue'
import pullRequestService from '@/services/pull-request.service'
import { useToast } from '@/stores/toast'

const router = useRouter()
const toast = useToast()
const loading = ref(false)
const processingId = ref(null)
const ordering = ref(false)
const items = ref([])
const total = ref(0)
const page = ref(1)
const perPage = 20
const hasNextPage = ref(false)

const summary = ref({
  pending_pulls: 0,
  pending_orders: 0,
  on_order: 0,
  total_open: 0,
})

const filters = reactive({
  status: '',
  request_type: '',
  pending_only: true,
})

const showOrderModalFlag = ref(false)
const selectedRequest = ref(null)
const orderForm = reactive({
  order_reference: '',
  vendor: '',
})

const statusOptions = [
  { value: '', label: 'All Statuses' },
  { value: 'pending', label: 'Pending' },
  { value: 'pulled', label: 'Pulled' },
  { value: 'ordered', label: 'Ordered' },
  { value: 'received', label: 'Received' },
  { value: 'cancelled', label: 'Cancelled' },
]

const typeOptions = [
  { value: '', label: 'All Types' },
  { value: 'pull', label: 'Pull from Stock' },
  { value: 'order', label: 'Order Required' },
]

const columns = [
  { key: 'description', label: 'Item' },
  { key: 'workorder', label: 'Workorder' },
  { key: 'quantity', label: 'Qty' },
  { key: 'request_type', label: 'Type' },
  { key: 'status', label: 'Status' },
  { key: 'pricing', label: 'Pricing' },
]

const refresh = () => {
  page.value = 1
  loadItems()
}

const loadSummary = async () => {
  try {
    const response = await pullRequestService.getSummary()
    summary.value = response.data
  } catch (err) {
    console.error('Failed to load summary:', err)
  }
}

const loadItems = async () => {
  loading.value = true
  try {
    const params = {
      limit: perPage,
      offset: (page.value - 1) * perPage,
    }

    if (filters.status) params.status = filters.status
    if (filters.request_type) params.request_type = filters.request_type
    if (filters.pending_only) params.pending_only = 'true'

    const response = await pullRequestService.list(params)
    items.value = response.data.items || []
    total.value = response.data.total || 0
    hasNextPage.value = items.value.length === perPage
  } finally {
    loading.value = false
  }
}

const pullFromStock = async (row) => {
  processingId.value = row.id
  try {
    await pullRequestService.markAsPulled(row.id, row.quantity_requested - row.quantity_fulfilled)
    toast.success('Item pulled from inventory')
    loadItems()
    loadSummary()
  } catch (err) {
    console.error('Failed to pull from stock:', err)
    toast.error('Failed to pull from inventory')
  } finally {
    processingId.value = null
  }
}

const showOrderModal = (row) => {
  selectedRequest.value = row
  orderForm.order_reference = ''
  orderForm.vendor = row.vendor || ''
  showOrderModalFlag.value = true
}

const confirmOrder = async () => {
  if (!selectedRequest.value) return
  ordering.value = true
  try {
    await pullRequestService.markAsOrdered(selectedRequest.value.id, orderForm.order_reference)
    toast.success('Item marked as ordered')
    showOrderModalFlag.value = false
    loadItems()
    loadSummary()
  } catch (err) {
    console.error('Failed to mark as ordered:', err)
    toast.error('Failed to mark as ordered')
  } finally {
    ordering.value = false
  }
}

const markReceived = async (row) => {
  processingId.value = row.id
  try {
    await pullRequestService.markAsReceived(row.id, row.quantity_requested - row.quantity_fulfilled)
    toast.success('Item marked as received')
    loadItems()
    loadSummary()
  } catch (err) {
    console.error('Failed to mark as received:', err)
    toast.error('Failed to mark as received')
  } finally {
    processingId.value = null
  }
}

const cancelRequest = async (row) => {
  if (!confirm('Cancel this pull request?')) return
  processingId.value = row.id
  try {
    await pullRequestService.cancel(row.id)
    toast.success('Request cancelled')
    loadItems()
    loadSummary()
  } catch (err) {
    console.error('Failed to cancel request:', err)
    toast.error('Failed to cancel request')
  } finally {
    processingId.value = null
  }
}

const getStatusVariant = (status) => {
  const variants = {
    pending: 'default',
    pulled: 'success',
    ordered: 'info',
    received: 'success',
    cancelled: 'danger',
  }
  return variants[status] || 'default'
}

const formatStatus = (status) => {
  if (!status) return ''
  return status.charAt(0).toUpperCase() + status.slice(1)
}

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

onMounted(() => {
  loadSummary()
  loadItems()
})
</script>
