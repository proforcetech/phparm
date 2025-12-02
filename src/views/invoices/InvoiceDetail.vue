<template>
  <div>
    <!-- Loading State -->
    <div v-if="loading" class="flex justify-center py-12">
      <Loading size="xl" text="Loading invoice..." />
    </div>

    <!-- Error State -->
    <Alert v-else-if="error" variant="danger" class="mb-6">
      {{ error }}
      <div class="mt-4">
        <Button variant="outline" @click="$router.push('/invoices')">
          Back to Invoices
        </Button>
      </div>
    </Alert>

    <!-- Invoice Detail -->
    <div v-else>
      <!-- Header -->
      <div class="mb-6 flex items-center justify-between">
        <div class="flex items-center gap-4">
          <Button variant="ghost" @click="$router.push('/invoices')">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
            </svg>
          </Button>
          <div>
            <h1 class="text-2xl font-bold text-gray-900">Invoice #{{ invoice.invoice_number }}</h1>
            <div class="mt-1 flex items-center gap-2">
              <Badge :variant="getStatusVariant(invoice.status)">
                {{ invoice.status }}
              </Badge>
              <span class="text-sm text-gray-500">
                Created {{ formatDate(invoice.created_at) }}
              </span>
            </div>
          </div>
        </div>

        <div class="flex items-center gap-2">
          <Button variant="outline" @click="downloadPdf">
            <svg class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
            </svg>
            Download PDF
          </Button>

          <Button v-if="invoice.status === 'draft'" @click="sendInvoice">
            <svg class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
            </svg>
            Send Invoice
          </Button>

          <Button v-if="invoice.status === 'pending'" variant="primary" @click="recordPayment">
            Record Payment
          </Button>

          <Button variant="outline" @click="editInvoice">
            Edit
          </Button>
        </div>
      </div>

      <!-- Success/Error Messages -->
      <Alert v-if="successMessage" variant="success" class="mb-6" @close="successMessage = ''">
        {{ successMessage }}
      </Alert>

      <Alert v-if="errorMessage" variant="danger" class="mb-6" @close="errorMessage = ''">
        {{ errorMessage }}
      </Alert>

      <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Main Content -->
        <div class="lg:col-span-2 space-y-6">
          <!-- Invoice Details -->
          <Card title="Invoice Information">
            <div class="grid grid-cols-2 gap-6">
              <!-- Customer Info -->
              <div>
                <h3 class="text-sm font-medium text-gray-500 mb-2">Bill To</h3>
                <div class="text-sm">
                  <p class="font-medium text-gray-900">{{ invoice.customer?.name }}</p>
                  <p class="text-gray-600">{{ invoice.customer?.email }}</p>
                  <p class="text-gray-600">{{ invoice.customer?.phone }}</p>
                  <p class="text-gray-600 mt-1">
                    {{ invoice.customer?.address }}<br>
                    {{ invoice.customer?.city }}, {{ invoice.customer?.state }} {{ invoice.customer?.zip_code }}
                  </p>
                </div>
              </div>

              <!-- Invoice Info -->
              <div>
                <div class="space-y-3">
                  <div>
                    <div class="text-sm font-medium text-gray-500">Invoice Number</div>
                    <div class="text-sm text-gray-900">#{{ invoice.invoice_number }}</div>
                  </div>
                  <div>
                    <div class="text-sm font-medium text-gray-500">Invoice Date</div>
                    <div class="text-sm text-gray-900">{{ formatDate(invoice.invoice_date) }}</div>
                  </div>
                  <div>
                    <div class="text-sm font-medium text-gray-500">Due Date</div>
                    <div class="text-sm" :class="{ 'text-red-600 font-medium': isOverdue }">
                      {{ formatDate(invoice.due_date) }}
                      <span v-if="isOverdue" class="text-xs">(Overdue)</span>
                    </div>
                  </div>
                  <div v-if="invoice.vehicle">
                    <div class="text-sm font-medium text-gray-500">Vehicle</div>
                    <div class="text-sm text-gray-900">
                      {{ invoice.vehicle.year }} {{ invoice.vehicle.make }} {{ invoice.vehicle.model }}
                    </div>
                    <div class="text-xs text-gray-500">{{ invoice.vehicle.vin }}</div>
                  </div>
                </div>
              </div>
            </div>
          </Card>

          <!-- Line Items -->
          <Card title="Line Items">
            <div class="overflow-hidden">
              <table class="min-w-full divide-y divide-gray-300">
                <thead class="bg-gray-50">
                  <tr>
                    <th scope="col" class="py-3 pl-4 pr-3 text-left text-sm font-semibold text-gray-900">Description</th>
                    <th scope="col" class="px-3 py-3 text-right text-sm font-semibold text-gray-900">Qty</th>
                    <th scope="col" class="px-3 py-3 text-right text-sm font-semibold text-gray-900">Rate</th>
                    <th scope="col" class="px-3 py-3 text-right text-sm font-semibold text-gray-900">Amount</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 bg-white">
                  <tr v-for="item in invoice.line_items" :key="item.id">
                    <td class="whitespace-nowrap py-4 pl-4 pr-3 text-sm">
                      <div class="font-medium text-gray-900">{{ item.description }}</div>
                      <div v-if="item.notes" class="text-gray-500 text-xs mt-1">{{ item.notes }}</div>
                    </td>
                    <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-900 text-right">{{ item.quantity }}</td>
                    <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-900 text-right">{{ formatCurrency(item.unit_price) }}</td>
                    <td class="whitespace-nowrap px-3 py-4 text-sm font-medium text-gray-900 text-right">
                      {{ formatCurrency(item.quantity * item.unit_price) }}
                    </td>
                  </tr>
                </tbody>
                <tfoot>
                  <tr>
                    <td colspan="3" class="py-3 pl-4 pr-3 text-right text-sm font-medium text-gray-900">Subtotal</td>
                    <td class="px-3 py-3 text-sm font-medium text-gray-900 text-right">{{ formatCurrency(invoice.subtotal) }}</td>
                  </tr>
                  <tr v-if="invoice.tax_amount > 0">
                    <td colspan="3" class="py-3 pl-4 pr-3 text-right text-sm font-medium text-gray-900">Tax</td>
                    <td class="px-3 py-3 text-sm font-medium text-gray-900 text-right">{{ formatCurrency(invoice.tax_amount) }}</td>
                  </tr>
                  <tr v-if="invoice.discount_amount > 0">
                    <td colspan="3" class="py-3 pl-4 pr-3 text-right text-sm font-medium text-gray-900">Discount</td>
                    <td class="px-3 py-3 text-sm font-medium text-gray-900 text-right">-{{ formatCurrency(invoice.discount_amount) }}</td>
                  </tr>
                  <tr class="bg-gray-50">
                    <td colspan="3" class="py-3 pl-4 pr-3 text-right text-base font-semibold text-gray-900">Total</td>
                    <td class="px-3 py-3 text-base font-semibold text-gray-900 text-right">{{ formatCurrency(invoice.total_amount) }}</td>
                  </tr>
                </tfoot>
              </table>
            </div>

            <div v-if="invoice.notes" class="mt-4 pt-4 border-t border-gray-200">
              <h4 class="text-sm font-medium text-gray-900 mb-2">Notes</h4>
              <p class="text-sm text-gray-600 whitespace-pre-wrap">{{ invoice.notes }}</p>
            </div>
          </Card>

          <!-- Payment History -->
          <Card v-if="invoice.payments && invoice.payments.length > 0" title="Payment History">
            <div class="divide-y divide-gray-200">
              <div
                v-for="payment in invoice.payments"
                :key="payment.id"
                class="py-4 flex items-center justify-between"
              >
                <div>
                  <div class="flex items-center gap-3">
                    <Badge :variant="payment.status === 'completed' ? 'success' : 'warning'">
                      {{ payment.status }}
                    </Badge>
                    <span class="text-sm font-medium text-gray-900">
                      {{ payment.payment_method }}
                    </span>
                  </div>
                  <p class="mt-1 text-sm text-gray-500">
                    {{ formatDate(payment.payment_date) }}
                    <span v-if="payment.transaction_id" class="ml-2">
                      Transaction: {{ payment.transaction_id }}
                    </span>
                  </p>
                </div>
                <div class="text-right">
                  <div class="text-sm font-semibold text-gray-900">
                    {{ formatCurrency(payment.amount) }}
                  </div>
                </div>
              </div>
            </div>
          </Card>
        </div>

        <!-- Sidebar -->
        <div class="space-y-6">
          <!-- Payment Summary -->
          <Card title="Payment Summary">
            <dl class="space-y-3">
              <div class="flex justify-between text-sm">
                <dt class="text-gray-500">Total Amount</dt>
                <dd class="font-medium text-gray-900">{{ formatCurrency(invoice.total_amount) }}</dd>
              </div>
              <div class="flex justify-between text-sm">
                <dt class="text-gray-500">Amount Paid</dt>
                <dd class="font-medium text-green-600">{{ formatCurrency(invoice.paid_amount || 0) }}</dd>
              </div>
              <div class="pt-3 border-t border-gray-200 flex justify-between">
                <dt class="text-base font-semibold text-gray-900">Balance Due</dt>
                <dd class="text-base font-semibold text-gray-900">
                  {{ formatCurrency((invoice.total_amount || 0) - (invoice.paid_amount || 0)) }}
                </dd>
              </div>
            </dl>

            <div v-if="invoice.status === 'pending'" class="mt-4 pt-4 border-t border-gray-200">
              <Button class="w-full" @click="processPayment">
                Process Payment
              </Button>
            </div>
          </Card>

          <!-- Activity Log -->
          <Card v-if="invoice.activity_log && invoice.activity_log.length > 0" title="Activity Log">
            <div class="flow-root">
              <ul class="-mb-8">
                <li v-for="(activity, idx) in invoice.activity_log" :key="activity.id">
                  <div class="relative pb-8">
                    <span
                      v-if="idx !== invoice.activity_log.length - 1"
                      class="absolute top-4 left-4 -ml-px h-full w-0.5 bg-gray-200"
                      aria-hidden="true"
                    ></span>
                    <div class="relative flex space-x-3">
                      <div>
                        <span class="h-8 w-8 rounded-full bg-gray-100 flex items-center justify-center ring-8 ring-white">
                          <svg class="h-5 w-5 text-gray-600" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd" />
                          </svg>
                        </span>
                      </div>
                      <div class="flex min-w-0 flex-1 justify-between space-x-4 pt-1.5">
                        <div>
                          <p class="text-sm text-gray-900">{{ activity.description }}</p>
                          <p class="text-xs text-gray-500">{{ activity.user }}</p>
                        </div>
                        <div class="whitespace-nowrap text-right text-xs text-gray-500">
                          {{ formatDate(activity.created_at) }}
                        </div>
                      </div>
                    </div>
                  </div>
                </li>
              </ul>
            </div>
          </Card>
        </div>
      </div>
    </div>

    <!-- Payment Modal -->
    <Modal
      v-model="showPaymentModal"
      title="Record Payment"
      size="md"
    >
      <div class="space-y-4">
        <Input
          v-model="paymentForm.amount"
          type="number"
          label="Amount"
          placeholder="0.00"
          step="0.01"
          required
        />

        <Select
          v-model="paymentForm.payment_method"
          :options="paymentMethods"
          label="Payment Method"
          required
        />

        <Input
          v-model="paymentForm.transaction_id"
          label="Transaction ID"
          placeholder="Optional"
        />

        <Input
          v-model="paymentForm.payment_date"
          type="date"
          label="Payment Date"
          required
        />

        <Textarea
          v-model="paymentForm.notes"
          label="Notes"
          placeholder="Optional notes..."
          rows="3"
        />
      </div>

      <template #footer>
        <div class="flex justify-end gap-3">
          <Button variant="outline" @click="showPaymentModal = false">
            Cancel
          </Button>
          <Button @click="submitPayment" :loading="processingPayment">
            Record Payment
          </Button>
        </div>
      </template>
    </Modal>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Card from '@/components/ui/Card.vue'
import Button from '@/components/ui/Button.vue'
import Input from '@/components/ui/Input.vue'
import Select from '@/components/ui/Select.vue'
import Textarea from '@/components/ui/Textarea.vue'
import Badge from '@/components/ui/Badge.vue'
import Alert from '@/components/ui/Alert.vue'
import Loading from '@/components/ui/Loading.vue'
import Modal from '@/components/ui/Modal.vue'
import invoiceService from '@/services/invoice.service'

const route = useRoute()
const router = useRouter()

const loading = ref(true)
const error = ref(null)
const invoice = ref({})
const successMessage = ref('')
const errorMessage = ref('')

const showPaymentModal = ref(false)
const processingPayment = ref(false)
const paymentForm = ref({
  amount: '',
  payment_method: 'cash',
  transaction_id: '',
  payment_date: new Date().toISOString().split('T')[0],
  notes: '',
})

const paymentMethods = [
  { value: 'cash', label: 'Cash' },
  { value: 'check', label: 'Check' },
  { value: 'card', label: 'Credit/Debit Card' },
  { value: 'stripe', label: 'Stripe' },
  { value: 'square', label: 'Square' },
  { value: 'paypal', label: 'PayPal' },
]

const isOverdue = computed(() => {
  if (invoice.value.status === 'paid' || !invoice.value.due_date) return false
  return new Date(invoice.value.due_date) < new Date()
})

onMounted(async () => {
  await loadInvoice()
})

async function loadInvoice() {
  try {
    loading.value = true
    error.value = null

    const id = route.params.id
    const response = await invoiceService.getById(id)
    invoice.value = response.data || response
  } catch (err) {
    console.error('Failed to load invoice:', err)
    error.value = 'Failed to load invoice. Please try again.'
  } finally {
    loading.value = false
  }
}

async function downloadPdf() {
  try {
    const blob = await invoiceService.generatePdf(invoice.value.id)
    const url = window.URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = `invoice-${invoice.value.invoice_number}.pdf`
    link.click()
    window.URL.revokeObjectURL(url)
  } catch (err) {
    console.error('Failed to download PDF:', err)
    errorMessage.value = 'Failed to download PDF. Please try again.'
  }
}

async function sendInvoice() {
  try {
    await invoiceService.send(invoice.value.id)
    successMessage.value = 'Invoice sent successfully.'
    await loadInvoice()
  } catch (err) {
    console.error('Failed to send invoice:', err)
    errorMessage.value = 'Failed to send invoice. Please try again.'
  }
}

function editInvoice() {
  router.push(`/invoices/${invoice.value.id}/edit`)
}

function recordPayment() {
  paymentForm.value.amount = (invoice.value.total_amount || 0) - (invoice.value.paid_amount || 0)
  showPaymentModal.value = true
}

function processPayment() {
  // This would open a payment processing modal
  router.push(`/invoices/${invoice.value.id}/pay`)
}

async function submitPayment() {
  try {
    processingPayment.value = true
    await invoiceService.processPayment(invoice.value.id, paymentForm.value)
    successMessage.value = 'Payment recorded successfully.'
    showPaymentModal.value = false
    await loadInvoice()
  } catch (err) {
    console.error('Failed to record payment:', err)
    errorMessage.value = 'Failed to record payment. Please try again.'
  } finally {
    processingPayment.value = false
  }
}

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
</script>
