<template>
  <div>
    <div class="mb-6 flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Workorders</h1>
        <p class="mt-1 text-sm text-gray-500">Manage active repair workorders</p>
      </div>
    </div>

    <!-- Stats Cards -->
    <div class="mb-6 grid grid-cols-2 md:grid-cols-5 gap-4">
      <Card class="text-center">
        <div class="text-2xl font-bold text-gray-600">{{ stats.pending }}</div>
        <div class="text-sm text-gray-500">Pending</div>
      </Card>
      <Card class="text-center">
        <div class="text-2xl font-bold text-blue-600">{{ stats.in_progress }}</div>
        <div class="text-sm text-gray-500">In Progress</div>
      </Card>
      <Card class="text-center">
        <div class="text-2xl font-bold text-yellow-600">{{ stats.on_hold }}</div>
        <div class="text-sm text-gray-500">On Hold</div>
      </Card>
      <Card class="text-center">
        <div class="text-2xl font-bold text-green-600">{{ stats.completed }}</div>
        <div class="text-sm text-gray-500">Completed</div>
      </Card>
      <Card class="text-center">
        <div class="text-2xl font-bold text-primary-600">{{ stats.total_active }}</div>
        <div class="text-sm text-gray-500">Active Total</div>
      </Card>
    </div>

    <!-- Filters -->
    <Card class="mb-6">
      <div class="grid grid-cols-1 md:grid-cols-6 gap-4">
        <div>
          <label class="block text-sm font-medium text-gray-700">Status</label>
          <Select
            v-model="filters.status"
            :options="statusOptions"
            placeholder="All statuses"
            class="mt-1"
            @change="loadWorkorders"
          />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Priority</label>
          <Select
            v-model="filters.priority"
            :options="priorityOptions"
            placeholder="All priorities"
            class="mt-1"
            @change="loadWorkorders"
          />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Technician</label>
          <Select
            v-model="filters.technician_id"
            :options="technicianOptions"
            placeholder="All technicians"
            class="mt-1"
            @change="loadWorkorders"
          />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Customer ID</label>
          <Input
            v-model="filters.customer_id"
            type="number"
            placeholder="Filter by customer"
            class="mt-1"
            @input="debouncedLoad"
          />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Search</label>
          <Input
            v-model="filters.term"
            placeholder="Workorder number..."
            class="mt-1"
            @input="debouncedLoad"
          />
        </div>
        <div class="flex items-end">
          <Button variant="outline" class="w-full" @click="clearFilters">
            Clear Filters
          </Button>
        </div>
      </div>
    </Card>

    <!-- Workorders Table -->
    <Card>
      <Table :columns="columns" :data="workorders" :loading="loading" hoverable>
        <template #cell-number="{ row }">
          <router-link
            :to="`/cp/workorders/${row.id}`"
            class="text-primary-600 hover:text-primary-800 font-medium"
          >
            {{ row.number }}
          </router-link>
        </template>

        <template #cell-status="{ row }">
          <Badge :variant="getStatusVariant(row.status)">
            {{ formatStatus(row.status) }}
          </Badge>
        </template>

        <template #cell-priority="{ row }">
          <Badge :variant="getPriorityVariant(row.priority)">
            {{ formatStatus(row.priority) }}
          </Badge>
        </template>

        <template #cell-grand_total="{ row }">
          {{ formatCurrency(row.grand_total) }}
        </template>

        <template #cell-assigned_technician_id="{ row }">
          <span v-if="row.assigned_technician_id">
            {{ getTechnicianName(row.assigned_technician_id) }}
          </span>
          <span v-else class="text-gray-400 italic">Unassigned</span>
        </template>

        <template #cell-started_at="{ row }">
          {{ row.started_at ? formatDate(row.started_at) : '-' }}
        </template>

        <template #cell-created_at="{ row }">
          {{ formatDate(row.created_at) }}
        </template>

        <template #cell-actions="{ row }">
          <div class="flex gap-2">
            <Button
              variant="ghost"
              size="sm"
              @click="viewWorkorder(row.id)"
              title="View details"
            >
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
              </svg>
            </Button>
            <Button
              v-if="row.status === 'pending'"
              variant="ghost"
              size="sm"
              @click="startWorkorder(row)"
              title="Start Work"
            >
              <svg class="h-4 w-4 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z" />
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
            </Button>
            <Button
              v-if="row.status === 'in_progress'"
              variant="ghost"
              size="sm"
              @click="completeWorkorder(row)"
              title="Mark Complete"
            >
              <svg class="h-4 w-4 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
            </Button>
            <Button
              v-if="row.status === 'completed'"
              variant="ghost"
              size="sm"
              @click="convertToInvoice(row)"
              title="Convert to Invoice"
            >
              <svg class="h-4 w-4 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
              </svg>
            </Button>
          </div>
        </template>
      </Table>

      <!-- Pagination -->
      <div v-if="!loading && workorders.length > 0" class="mt-4 flex items-center justify-between border-t border-gray-200 pt-4">
        <div class="text-sm text-gray-700">
          Showing {{ ((currentPage - 1) * pageSize) + 1 }} to {{ Math.min(currentPage * pageSize, meta.total) }} of {{ meta.total }}
        </div>
        <div class="flex gap-2">
          <Button
            variant="outline"
            size="sm"
            :disabled="currentPage === 1"
            @click="previousPage"
          >
            Previous
          </Button>
          <Button
            variant="outline"
            size="sm"
            :disabled="workorders.length < pageSize"
            @click="nextPage"
          >
            Next
          </Button>
        </div>
      </div>
    </Card>

    <!-- Convert to Invoice Modal -->
    <Modal v-if="showConvertModal" @close="showConvertModal = false">
      <template #title>Convert to Invoice</template>
      <template #content>
        <div class="space-y-4">
          <p class="text-sm text-gray-600">
            Convert workorder #{{ selectedWorkorder?.number }} to an invoice?
          </p>
          <Alert variant="info">
            This will create an invoice with all completed work from this workorder.
          </Alert>
          <div>
            <label class="block text-sm font-medium text-gray-700">Due Date (Optional)</label>
            <Input
              v-model="convertForm.due_date"
              type="date"
              class="mt-1"
            />
          </div>
        </div>
      </template>
      <template #actions>
        <Button variant="outline" @click="showConvertModal = false">Cancel</Button>
        <Button @click="confirmConvert" :disabled="converting">
          {{ converting ? 'Converting...' : 'Convert to Invoice' }}
        </Button>
      </template>
    </Modal>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import Card from '@/components/ui/Card.vue'
import Button from '@/components/ui/Button.vue'
import Badge from '@/components/ui/Badge.vue'
import Input from '@/components/ui/Input.vue'
import Select from '@/components/ui/Select.vue'
import Table from '@/components/ui/Table.vue'
import Modal from '@/components/ui/Modal.vue'
import Alert from '@/components/ui/Alert.vue'
import workorderService from '@/services/workorder.service'
import userService from '@/services/user.service'
import { useToast } from '@/stores/toast'

const router = useRouter()
const toast = useToast()

const loading = ref(false)
const workorders = ref([])
const technicians = ref([])
const currentPage = ref(1)
const pageSize = 50
const showConvertModal = ref(false)
const selectedWorkorder = ref(null)
const converting = ref(false)

const meta = reactive({
  total: 0,
  limit: pageSize,
  offset: 0
})

const stats = reactive({
  pending: 0,
  in_progress: 0,
  on_hold: 0,
  completed: 0,
  total_active: 0
})

const filters = reactive({
  status: '',
  priority: '',
  technician_id: '',
  customer_id: '',
  term: ''
})

const convertForm = reactive({
  due_date: ''
})

const columns = [
  { key: 'number', label: 'Workorder #' },
  { key: 'status', label: 'Status' },
  { key: 'priority', label: 'Priority' },
  { key: 'assigned_technician_id', label: 'Technician' },
  { key: 'grand_total', label: 'Total' },
  { key: 'started_at', label: 'Started' },
  { key: 'created_at', label: 'Created' },
  { key: 'actions', label: '' }
]

const statusOptions = [
  { value: '', label: 'All Statuses' },
  { value: 'pending', label: 'Pending' },
  { value: 'in_progress', label: 'In Progress' },
  { value: 'on_hold', label: 'On Hold' },
  { value: 'completed', label: 'Completed' },
  { value: 'cancelled', label: 'Cancelled' }
]

const priorityOptions = [
  { value: '', label: 'All Priorities' },
  { value: 'urgent', label: 'Urgent' },
  { value: 'high', label: 'High' },
  { value: 'normal', label: 'Normal' },
  { value: 'low', label: 'Low' }
]

const technicianOptions = ref([{ value: '', label: 'All Technicians' }])

onMounted(async () => {
  await Promise.all([
    loadWorkorders(),
    loadStats(),
    loadTechnicians()
  ])
})

let debounceTimer = null
function debouncedLoad() {
  clearTimeout(debounceTimer)
  debounceTimer = setTimeout(() => {
    currentPage.value = 1
    loadWorkorders()
  }, 500)
}

async function loadWorkorders() {
  try {
    loading.value = true
    const params = {
      ...filters,
      limit: pageSize,
      offset: (currentPage.value - 1) * pageSize
    }
    // Remove empty filters
    Object.keys(params).forEach(key => {
      if (params[key] === '' || params[key] === null) {
        delete params[key]
      }
    })

    const response = await workorderService.getWorkorders(params)
    workorders.value = response.data?.data || response.data || []
    if (response.data?.meta) {
      Object.assign(meta, response.data.meta)
    }
  } catch (error) {
    console.error('Failed to load workorders:', error)
    toast.error('Failed to load workorders')
  } finally {
    loading.value = false
  }
}

async function loadStats() {
  try {
    const response = await workorderService.getStats()
    Object.assign(stats, response.data || {})
  } catch (error) {
    console.error('Failed to load stats:', error)
  }
}

async function loadTechnicians() {
  try {
    const response = await userService.getUsers({ role: 'technician' })
    const users = response.data || []
    technicians.value = users
    technicianOptions.value = [
      { value: '', label: 'All Technicians' },
      ...users.map(u => ({ value: u.id, label: u.name }))
    ]
  } catch (error) {
    console.error('Failed to load technicians:', error)
  }
}

function clearFilters() {
  filters.status = ''
  filters.priority = ''
  filters.technician_id = ''
  filters.customer_id = ''
  filters.term = ''
  currentPage.value = 1
  loadWorkorders()
}

function nextPage() {
  currentPage.value++
  loadWorkorders()
}

function previousPage() {
  if (currentPage.value > 1) {
    currentPage.value--
    loadWorkorders()
  }
}

function viewWorkorder(id) {
  router.push(`/cp/workorders/${id}`)
}

async function startWorkorder(workorder) {
  try {
    await workorderService.updateStatus(workorder.id, 'in_progress', 'Work started')
    toast.success('Workorder started')
    loadWorkorders()
    loadStats()
  } catch (error) {
    console.error('Failed to start workorder:', error)
    toast.error(error.response?.data?.error || 'Failed to start workorder')
  }
}

async function completeWorkorder(workorder) {
  try {
    await workorderService.updateStatus(workorder.id, 'completed', 'Work completed')
    toast.success('Workorder marked as complete')
    loadWorkorders()
    loadStats()
  } catch (error) {
    console.error('Failed to complete workorder:', error)
    toast.error(error.response?.data?.error || 'Failed to complete workorder')
  }
}

function convertToInvoice(workorder) {
  selectedWorkorder.value = workorder
  convertForm.due_date = ''
  showConvertModal.value = true
}

async function confirmConvert() {
  try {
    converting.value = true
    const response = await workorderService.convertToInvoice(
      selectedWorkorder.value.id,
      convertForm.due_date || null
    )

    toast.success('Workorder converted to invoice successfully')
    showConvertModal.value = false

    // Redirect to the new invoice
    if (response.data?.data?.id) {
      router.push(`/cp/invoices/${response.data.data.id}`)
    } else {
      loadWorkorders()
      loadStats()
    }
  } catch (error) {
    console.error('Failed to convert workorder:', error)
    toast.error(error.response?.data?.error || 'Failed to convert workorder')
  } finally {
    converting.value = false
  }
}

function getTechnicianName(id) {
  const tech = technicians.value.find(t => t.id === id)
  return tech?.name || `Tech #${id}`
}

function getStatusVariant(status) {
  const variants = {
    pending: 'default',
    in_progress: 'info',
    on_hold: 'warning',
    completed: 'success',
    cancelled: 'danger'
  }
  return variants[status?.toLowerCase()] || 'default'
}

function getPriorityVariant(priority) {
  const variants = {
    urgent: 'danger',
    high: 'warning',
    normal: 'default',
    low: 'secondary'
  }
  return variants[priority?.toLowerCase()] || 'default'
}

function formatStatus(status) {
  if (!status) return ''
  return status
    .split('_')
    .map(word => word.charAt(0).toUpperCase() + word.slice(1))
    .join(' ')
}

function formatCurrency(amount) {
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD'
  }).format(amount || 0)
}

function formatDate(date) {
  if (!date) return ''
  return new Intl.DateTimeFormat('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric'
  }).format(new Date(date))
}
</script>
