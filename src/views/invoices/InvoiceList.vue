<template>
  <div>
    <!-- Page Header -->
    <div class="mb-6 flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Invoices</h1>
        <p class="mt-1 text-sm text-gray-500">Manage customer invoices and payments</p>
      </div>
      <Button @click="$router.push('/invoices/create')">
        <svg class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
        </svg>
        New Invoice
      </Button>
    </div>

    <!-- Filters -->
    <Card class="mb-6">
      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <!-- Search -->
        <Input
          v-model="filters.search"
          placeholder="Search invoices..."
          @input="debouncedSearch"
        >
          <template #icon>
            <svg class="h-5 w-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
            </svg>
          </template>
        </Input>

        <!-- Status Filter -->
        <Select
          v-model="filters.status"
          :options="statusOptions"
          placeholder="All Statuses"
          @change="loadInvoices"
        />

        <!-- Date Range -->
        <Input
          v-model="filters.dateFrom"
          type="date"
          label="From Date"
          @change="loadInvoices"
        />

        <Input
          v-model="filters.dateTo"
          type="date"
          label="To Date"
          @change="loadInvoices"
        />
      </div>

      <div class="mt-4 flex items-center justify-between">
        <div class="text-sm text-gray-500">
          {{ total }} total invoices
        </div>
        <Button
          variant="ghost"
          size="sm"
          @click="resetFilters"
        >
          Reset Filters
        </Button>
      </div>
    </Card>

    <!-- Alert Messages -->
    <Alert v-if="successMessage" variant="success" class="mb-6" @close="successMessage = ''">
      {{ successMessage }}
    </Alert>

    <Alert v-if="errorMessage" variant="danger" class="mb-6" @close="errorMessage = ''">
      {{ errorMessage }}
    </Alert>

    <!-- Invoices Table -->
    <Card>
      <Table
        :columns="columns"
        :data="invoices"
        :loading="loading"
        :pagination="true"
        :per-page="perPage"
        :total="total"
        :current-page="currentPage"
        :selectable="true"
        @row-click="viewInvoice"
        @sort="handleSort"
        @page-change="handlePageChange"
        @selection-change="handleSelectionChange"
      >
        <!-- Custom cell: Invoice Number -->
        <template #cell(invoice_number)="{ value }">
          <span class="font-medium text-primary-600">#{{ value }}</span>
        </template>

        <!-- Custom cell: Customer -->
        <template #cell(customer_name)="{ row }">
          <div>
            <div class="font-medium text-gray-900">{{ row.customer_name }}</div>
            <div class="text-sm text-gray-500">{{ row.customer_email }}</div>
          </div>
        </template>

        <!-- Custom cell: Status -->
        <template #cell(status)="{ value }">
          <Badge :variant="getStatusVariant(value)">
            {{ value }}
          </Badge>
        </template>

        <!-- Custom cell: Total -->
        <template #cell(total_amount)="{ value }">
          <span class="font-semibold text-gray-900">{{ formatCurrency(value) }}</span>
        </template>

        <!-- Custom cell: Date -->
        <template #cell(created_at)="{ value }">
          {{ formatDate(value) }}
        </template>

        <!-- Custom cell: Due Date -->
        <template #cell(due_date)="{ value, row }">
          <span :class="{ 'text-red-600 font-medium': isOverdue(value, row.status) }">
            {{ formatDate(value) }}
          </span>
        </template>

        <!-- Actions -->
        <template #actions="{ row }">
          <div class="flex items-center gap-2">
            <button
              @click.stop="viewInvoice(row)"
              class="text-primary-600 hover:text-primary-900"
              title="View"
            >
              <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
              </svg>
            </button>

            <button
              @click.stop="downloadPdf(row)"
              class="text-gray-600 hover:text-gray-900"
              title="Download PDF"
            >
              <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
              </svg>
            </button>

            <button
              v-if="row.status === 'draft'"
              @click.stop="sendInvoice(row)"
              class="text-blue-600 hover:text-blue-900"
              title="Send Invoice"
            >
              <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
              </svg>
            </button>

            <button
              @click.stop="deleteInvoice(row)"
              class="text-red-600 hover:text-red-900"
              title="Delete"
            >
              <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
              </svg>
            </button>
          </div>
        </template>

        <!-- Empty state -->
        <template #empty>
          <div class="text-center py-8">
            <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
            </svg>
            <h3 class="mt-2 text-sm font-medium text-gray-900">No invoices</h3>
            <p class="mt-1 text-sm text-gray-500">Get started by creating a new invoice.</p>
            <div class="mt-6">
              <Button @click="$router.push('/invoices/create')">
                <svg class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                </svg>
                New Invoice
              </Button>
            </div>
          </div>
        </template>
      </Table>
    </Card>

    <!-- Bulk Actions -->
    <div v-if="selectedInvoices.length > 0" class="fixed bottom-0 inset-x-0 pb-2 sm:pb-5 z-10">
      <div class="max-w-7xl mx-auto px-2 sm:px-6 lg:px-8">
        <Card class="shadow-lg">
          <div class="flex items-center justify-between flex-wrap">
            <div class="flex items-center">
              <span class="text-sm font-medium text-gray-900">
                {{ selectedInvoices.length }} invoice{{ selectedInvoices.length > 1 ? 's' : '' }} selected
              </span>
            </div>
            <div class="flex items-center gap-2">
              <Button variant="outline" size="sm" @click="bulkSend">
                Send Selected
              </Button>
              <Button variant="danger" size="sm" @click="bulkDelete">
                Delete Selected
              </Button>
            </div>
          </div>
        </Card>
      </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <Modal
      v-model="showDeleteModal"
      title="Delete Invoice"
      size="sm"
    >
      <p class="text-sm text-gray-500">
        Are you sure you want to delete this invoice? This action cannot be undone.
      </p>

      <template #footer>
        <div class="flex justify-end gap-3">
          <Button variant="outline" @click="showDeleteModal = false">
            Cancel
          </Button>
          <Button variant="danger" @click="confirmDelete" :loading="deleting">
            Delete
          </Button>
        </div>
      </template>
    </Modal>
  </div>
</template>

<script setup>
import { ref, onMounted, computed } from 'vue'
import { useRouter } from 'vue-router'
import Card from '@/components/ui/Card.vue'
import Button from '@/components/ui/Button.vue'
import Input from '@/components/ui/Input.vue'
import Select from '@/components/ui/Select.vue'
import Table from '@/components/ui/Table.vue'
import Badge from '@/components/ui/Badge.vue'
import Alert from '@/components/ui/Alert.vue'
import Modal from '@/components/ui/Modal.vue'
import invoiceService from '@/services/invoice.service'

const router = useRouter()

// State
const loading = ref(false)
const invoices = ref([])
const total = ref(0)
const currentPage = ref(1)
const perPage = ref(10)

const filters = ref({
  search: '',
  status: '',
  dateFrom: '',
  dateTo: '',
})

const selectedInvoices = ref([])
const showDeleteModal = ref(false)
const invoiceToDelete = ref(null)
const deleting = ref(false)

const successMessage = ref('')
const errorMessage = ref('')

// Options
const statusOptions = [
  { value: '', label: 'All Statuses' },
  { value: 'draft', label: 'Draft' },
  { value: 'pending', label: 'Pending' },
  { value: 'paid', label: 'Paid' },
  { value: 'overdue', label: 'Overdue' },
  { value: 'cancelled', label: 'Cancelled' },
]

// Table columns
const columns = [
  { key: 'invoice_number', label: 'Invoice #', sortable: true },
  { key: 'customer_name', label: 'Customer', sortable: true },
  { key: 'status', label: 'Status', sortable: true },
  { key: 'total_amount', label: 'Amount', sortable: true },
  { key: 'created_at', label: 'Created', sortable: true },
  { key: 'due_date', label: 'Due Date', sortable: true },
]

// Lifecycle
onMounted(() => {
  loadInvoices()
})

// Methods
async function loadInvoices() {
  try {
    loading.value = true
    errorMessage.value = ''

    const params = {
      page: currentPage.value,
      per_page: perPage.value,
      ...filters.value,
    }

    const response = await invoiceService.getAll(params)

    invoices.value = response.data || []
    total.value = response.total || 0
    currentPage.value = response.current_page || 1
  } catch (error) {
    console.error('Failed to load invoices:', error)
    errorMessage.value = 'Failed to load invoices. Please try again.'
  } finally {
    loading.value = false
  }
}

function viewInvoice(invoice) {
  router.push(`/invoices/${invoice.id}`)
}

async function downloadPdf(invoice) {
  try {
    const blob = await invoiceService.generatePdf(invoice.id)
    const url = window.URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = `invoice-${invoice.invoice_number}.pdf`
    link.click()
    window.URL.revokeObjectURL(url)
  } catch (error) {
    console.error('Failed to download PDF:', error)
    errorMessage.value = 'Failed to download PDF. Please try again.'
  }
}

async function sendInvoice(invoice) {
  try {
    await invoiceService.send(invoice.id)
    successMessage.value = `Invoice #${invoice.invoice_number} sent successfully.`
    await loadInvoices()
  } catch (error) {
    console.error('Failed to send invoice:', error)
    errorMessage.value = 'Failed to send invoice. Please try again.'
  }
}

function deleteInvoice(invoice) {
  invoiceToDelete.value = invoice
  showDeleteModal.value = true
}

async function confirmDelete() {
  if (!invoiceToDelete.value) return

  try {
    deleting.value = true
    await invoiceService.delete(invoiceToDelete.value.id)
    successMessage.value = `Invoice #${invoiceToDelete.value.invoice_number} deleted successfully.`
    showDeleteModal.value = false
    invoiceToDelete.value = null
    await loadInvoices()
  } catch (error) {
    console.error('Failed to delete invoice:', error)
    errorMessage.value = 'Failed to delete invoice. Please try again.'
  } finally {
    deleting.value = false
  }
}

async function bulkSend() {
  try {
    const promises = selectedInvoices.value
      .filter(inv => inv.status === 'draft')
      .map(inv => invoiceService.send(inv.id))

    await Promise.all(promises)
    successMessage.value = `${promises.length} invoice(s) sent successfully.`
    selectedInvoices.value = []
    await loadInvoices()
  } catch (error) {
    console.error('Failed to send invoices:', error)
    errorMessage.value = 'Failed to send some invoices. Please try again.'
  }
}

async function bulkDelete() {
  if (!confirm(`Are you sure you want to delete ${selectedInvoices.value.length} invoice(s)?`)) {
    return
  }

  try {
    const promises = selectedInvoices.value.map(inv => invoiceService.delete(inv.id))
    await Promise.all(promises)
    successMessage.value = `${promises.length} invoice(s) deleted successfully.`
    selectedInvoices.value = []
    await loadInvoices()
  } catch (error) {
    console.error('Failed to delete invoices:', error)
    errorMessage.value = 'Failed to delete some invoices. Please try again.'
  }
}

function handleSort({ key, order }) {
  filters.value.sort = order === 'asc' ? key : `-${key}`
  loadInvoices()
}

function handlePageChange(page) {
  currentPage.value = page
  loadInvoices()
}

function handleSelectionChange(selected) {
  selectedInvoices.value = selected
}

function resetFilters() {
  filters.value = {
    search: '',
    status: '',
    dateFrom: '',
    dateTo: '',
  }
  currentPage.value = 1
  loadInvoices()
}

// Debounced search
let searchTimeout = null
function debouncedSearch() {
  clearTimeout(searchTimeout)
  searchTimeout = setTimeout(() => {
    currentPage.value = 1
    loadInvoices()
  }, 300)
}

// Utilities
function formatCurrency(amount) {
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
  }).format(amount || 0)
}

function formatDate(date) {
  if (!date) return ''
  return new Intl.DateTimeFormat('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
  }).format(new Date(date))
}

function getStatusVariant(status) {
  const variants = {
    'paid': 'success',
    'pending': 'warning',
    'overdue': 'danger',
    'draft': 'default',
    'cancelled': 'default',
  }
  return variants[status?.toLowerCase()] || 'default'
}

function isOverdue(dueDate, status) {
  if (status === 'paid' || !dueDate) return false
  return new Date(dueDate) < new Date()
}
</script>
