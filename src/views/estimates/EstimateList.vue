<template>
  <div>
    <div class="mb-6 flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Estimates</h1>
        <p class="mt-1 text-sm text-gray-500">Manage customer estimates and quotes</p>
      </div>
      <Button @click="$router.push('/estimates/create')">
        <svg class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
        </svg>
        New Estimate
      </Button>
    </div>

    <!-- Filters -->
    <Card class="mb-6">
      <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
        <div>
          <label class="block text-sm font-medium text-gray-700">Status</label>
          <Select
            v-model="filters.status"
            :options="statusOptions"
            placeholder="All statuses"
            class="mt-1"
            @change="loadEstimates"
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
          <label class="block text-sm font-medium text-gray-700">Vehicle ID</label>
          <Input
            v-model="filters.vehicle_id"
            type="number"
            placeholder="Filter by vehicle"
            class="mt-1"
            @input="debouncedLoad"
          />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Search</label>
          <Input
            v-model="filters.term"
            placeholder="Estimate number..."
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

    <!-- Estimates Table -->
    <Card>
      <Table :columns="columns" :data="estimates" :loading="loading" hoverable>
        <template #cell-number="{ row }">
          <router-link
            :to="`/estimates/${row.id}`"
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

        <template #cell-grand_total="{ row }">
          {{ formatCurrency(row.grand_total) }}
        </template>

        <template #cell-expiration_date="{ row }">
          <span :class="isExpiringSoon(row.expiration_date) ? 'text-red-600' : 'text-gray-900'">
            {{ row.expiration_date ? formatDate(row.expiration_date) : '-' }}
          </span>
        </template>

        <template #cell-actions="{ row }">
          <div class="flex gap-2">
            <Button
              variant="ghost"
              size="sm"
              @click="viewEstimate(row.id)"
              title="View details"
            >
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
              </svg>
            </Button>
            <Button
              v-if="row.status === 'approved'"
              variant="ghost"
              size="sm"
              @click="convertToInvoice(row.id)"
              title="Convert to Invoice"
            >
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
              </svg>
            </Button>
          </div>
        </template>
      </Table>

      <!-- Pagination -->
      <div v-if="!loading && estimates.length > 0" class="mt-4 flex items-center justify-between border-t border-gray-200 pt-4">
        <div class="text-sm text-gray-700">
          Showing {{ ((currentPage - 1) * pageSize) + 1 }} to {{ Math.min(currentPage * pageSize, estimates.length) }}
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
            :disabled="estimates.length < pageSize"
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
            Convert estimate #{{ selectedEstimate?.number }} to an invoice?
          </p>
          <div>
            <label class="block text-sm font-medium text-gray-700">Issue Date</label>
            <Input
              v-model="convertForm.issue_date"
              type="date"
              class="mt-1"
              required
            />
          </div>
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
        <Button @click="confirmConvert" :disabled="!convertForm.issue_date">
          Convert to Invoice
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
import estimateService from '@/services/estimate.service'
import { useToast } from '@/stores/toast'

const router = useRouter()
const toast = useToast()

const loading = ref(false)
const estimates = ref([])
const currentPage = ref(1)
const pageSize = 50
const showConvertModal = ref(false)
const selectedEstimate = ref(null)

const filters = reactive({
  status: '',
  customer_id: '',
  vehicle_id: '',
  term: ''
})

const convertForm = reactive({
  issue_date: new Date().toISOString().split('T')[0],
  due_date: ''
})

const columns = [
  { key: 'number', label: 'Estimate #' },
  { key: 'customer_id', label: 'Customer' },
  { key: 'vehicle_id', label: 'Vehicle' },
  { key: 'status', label: 'Status' },
  { key: 'grand_total', label: 'Total' },
  { key: 'expiration_date', label: 'Expires' },
  { key: 'created_at', label: 'Created' },
  { key: 'actions', label: '' }
]

const statusOptions = [
  { value: '', label: 'All Statuses' },
  { value: 'draft', label: 'Draft' },
  { value: 'sent', label: 'Sent' },
  { value: 'approved', label: 'Approved' },
  { value: 'declined', label: 'Declined' },
  { value: 'expired', label: 'Expired' },
  { value: 'needs_reapproval', label: 'Needs Reapproval' },
  { value: 'converted', label: 'Converted' }
]

onMounted(() => {
  loadEstimates()
})

let debounceTimer = null
function debouncedLoad() {
  clearTimeout(debounceTimer)
  debounceTimer = setTimeout(() => {
    currentPage.value = 1
    loadEstimates()
  }, 500)
}

async function loadEstimates() {
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

    const response = await estimateService.getEstimates(params)
    estimates.value = response.data || []
  } catch (error) {
    console.error('Failed to load estimates:', error)
    toast.error('Failed to load estimates')
  } finally {
    loading.value = false
  }
}

function clearFilters() {
  filters.status = ''
  filters.customer_id = ''
  filters.vehicle_id = ''
  filters.term = ''
  currentPage.value = 1
  loadEstimates()
}

function nextPage() {
  currentPage.value++
  loadEstimates()
}

function previousPage() {
  if (currentPage.value > 1) {
    currentPage.value--
    loadEstimates()
  }
}

function viewEstimate(id) {
  router.push(`/estimates/${id}`)
}

function convertToInvoice(id) {
  selectedEstimate.value = estimates.value.find(e => e.id === id)
  showConvertModal.value = true
}

async function confirmConvert() {
  try {
    const response = await estimateService.convertToInvoice(selectedEstimate.value.id, {
      issue_date: convertForm.issue_date,
      due_date: convertForm.due_date || null
    })

    toast.success('Estimate converted to invoice successfully')
    showConvertModal.value = false

    // Redirect to the new invoice
    if (response.data?.id) {
      router.push(`/invoices/${response.data.id}`)
    } else {
      loadEstimates()
    }
  } catch (error) {
    console.error('Failed to convert estimate:', error)
    toast.error(error.response?.data?.message || 'Failed to convert estimate')
  }
}

function getStatusVariant(status) {
  const variants = {
    draft: 'default',
    sent: 'info',
    approved: 'success',
    declined: 'danger',
    expired: 'warning',
    needs_reapproval: 'warning',
    converted: 'success'
  }
  return variants[status?.toLowerCase()] || 'default'
}

function formatStatus(status) {
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

function isExpiringSoon(expirationDate) {
  if (!expirationDate) return false
  const expiry = new Date(expirationDate)
  const now = new Date()
  const daysUntilExpiry = (expiry - now) / (1000 * 60 * 60 * 24)
  return daysUntilExpiry > 0 && daysUntilExpiry <= 7
}
</script>
