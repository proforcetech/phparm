<template>
  <div>
    <div class="mb-6 flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Credit Account</h1>
        <p class="mt-1 text-sm text-gray-500">View your credit balance, history, and submit payments for review.</p>
      </div>
    </div>

    <Alert v-if="errorMessage" variant="danger" class="mb-4" @close="errorMessage = ''">
      {{ errorMessage }}
    </Alert>

    <Alert v-if="successMessage" variant="success" class="mb-4" @close="successMessage = ''">
      {{ successMessage }}
    </Alert>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
      <Card>
        <div class="p-4">
          <p class="text-sm text-gray-500">Current Balance</p>
          <p class="mt-2 text-2xl font-semibold text-gray-900">{{ formatCurrency(history?.balance || 0) }}</p>
        </div>
      </Card>
      <Card>
        <div class="p-4">
          <p class="text-sm text-gray-500">Available Credit</p>
          <p class="mt-2 text-2xl font-semibold text-gray-900">{{ formatCurrency(history?.available_credit || 0) }}</p>
        </div>
      </Card>
      <Card>
        <div class="p-4">
          <p class="text-sm text-gray-500">Credit Limit</p>
          <p class="mt-2 text-2xl font-semibold text-gray-900">{{ formatCurrency(history?.account?.credit_limit || 0) }}</p>
        </div>
      </Card>
      <Card>
        <div class="p-4">
          <p class="text-sm text-gray-500">Status</p>
          <Badge :variant="history?.account?.status === 'active' ? 'success' : 'secondary'">
            {{ history?.account?.status || 'inactive' }}
          </Badge>
        </div>
      </Card>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
      <Card class="lg:col-span-2">
        <div class="p-4">
          <div class="flex items-center justify-between mb-4">
            <h2 class="text-lg font-semibold text-gray-900">Recent Transactions</h2>
            <span class="text-sm text-gray-500">Showing last {{ history?.transactions?.length || 0 }}</span>
          </div>
          <Table
            :columns="transactionColumns"
            :data="history?.transactions || []"
            :loading="loading"
            :pagination="false"
            :selectable="false"
            :hoverable="false"
          >
            <template #cell(amount)="{ value, row }">
              <span :class="row.transaction_type === 'payment' ? 'text-green-700' : 'text-red-700'">
                {{ formatCurrency(value) }}
              </span>
            </template>
            <template #cell(balance_after)="{ value }">
              {{ formatCurrency(value) }}
            </template>
            <template #cell(occurred_at)="{ value }">
              {{ formatDate(value) }}
            </template>
          </Table>
        </div>
      </Card>

      <Card>
        <div class="p-4 space-y-4">
          <div>
            <h2 class="text-lg font-semibold text-gray-900">Pending & Completed Payments</h2>
            <p class="text-sm text-gray-500">Submitted payments remain pending until processed by staff.</p>
          </div>
          <div class="space-y-3 max-h-80 overflow-y-auto">
            <div v-for="payment in history?.payments || []" :key="payment.id" class="border border-gray-200 rounded-lg p-3">
              <div class="flex items-center justify-between">
                <div class="text-sm text-gray-500">{{ formatDate(payment.payment_date) }}</div>
                <Badge :variant="payment.status === 'completed' ? 'success' : 'warning'">
                  {{ payment.status }}
                </Badge>
              </div>
              <div class="mt-2 text-lg font-semibold text-gray-900">{{ formatCurrency(payment.amount) }}</div>
              <p class="text-sm text-gray-600">{{ payment.payment_method }}</p>
              <p v-if="payment.reference_number" class="text-xs text-gray-500">Ref: {{ payment.reference_number }}</p>
            </div>
            <p v-if="!history?.payments?.length && !loading" class="text-sm text-gray-500">No payments yet.</p>
          </div>
        </div>
      </Card>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
      <Card class="lg:col-span-2">
        <div class="p-4">
          <div class="flex items-center justify-between mb-4">
            <h2 class="text-lg font-semibold text-gray-900">Reminder History</h2>
            <span class="text-sm text-gray-500">Automated notices for upcoming/past-due payments.</span>
          </div>
          <Table
            :columns="reminderColumns"
            :data="history?.reminders || []"
            :loading="loading"
            :pagination="false"
            :selectable="false"
            :hoverable="false"
          >
            <template #cell(sent_at)="{ value }">
              {{ formatDate(value) }}
            </template>
            <template #cell(message)="{ value }">
              <span class="line-clamp-2">{{ value || 'N/A' }}</span>
            </template>
          </Table>
        </div>
      </Card>

      <Card>
        <div class="p-4 space-y-4">
          <div>
            <h2 class="text-lg font-semibold text-gray-900">Submit a Payment</h2>
            <p class="text-sm text-gray-500">Send a payment for staff review. Approved payments are applied to your balance.</p>
          </div>

          <form class="space-y-3" @submit.prevent="submitPayment">
            <Input
              v-model.number="paymentForm.amount"
              type="number"
              step="0.01"
              label="Payment Amount"
              placeholder="Enter amount"
              required
            />

            <Select
              v-model="paymentForm.payment_method"
              :options="paymentMethods"
              label="Payment Method"
            />

            <Input
              v-model="paymentForm.reference_number"
              label="Reference Number"
              placeholder="Optional reference"
            />

            <Textarea
              v-model="paymentForm.notes"
              label="Notes"
              placeholder="Any additional context"
              :rows="3"
            />

            <Button :disabled="submitting" type="submit" class="w-full">
              <template v-if="submitting">
                <svg class="animate-spin -ml-1 mr-3 h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                  <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                  <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4l3-3-3-3v4a12 12 0 00-12 12h4z"></path>
                </svg>
                Submitting
              </template>
              <template v-else>
                Submit Payment
              </template>
            </Button>
          </form>
        </div>
      </Card>
    </div>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import Alert from '@/components/ui/Alert.vue'
import Badge from '@/components/ui/Badge.vue'
import Button from '@/components/ui/Button.vue'
import Card from '@/components/ui/Card.vue'
import Input from '@/components/ui/Input.vue'
import Select from '@/components/ui/Select.vue'
import Table from '@/components/ui/Table.vue'
import Textarea from '@/components/ui/Textarea.vue'
import { creditService } from '@/services/credit.service'

const loading = ref(true)
const submitting = ref(false)
const history = ref(null)
const errorMessage = ref('')
const successMessage = ref('')

const paymentForm = ref({
  amount: null,
  payment_method: 'credit_card',
  reference_number: '',
  notes: '',
})

const paymentMethods = [
  { label: 'Credit Card', value: 'credit_card' },
  { label: 'ACH/Bank Transfer', value: 'ach' },
  { label: 'Cash', value: 'cash' },
  { label: 'Check', value: 'check' },
  { label: 'Other', value: 'other' },
]

const transactionColumns = [
  { key: 'transaction_type', label: 'Type' },
  { key: 'description', label: 'Description' },
  { key: 'amount', label: 'Amount' },
  { key: 'balance_after', label: 'Balance After' },
  { key: 'occurred_at', label: 'Date' },
]

const reminderColumns = [
  { key: 'reminder_type', label: 'Type' },
  { key: 'sent_via', label: 'Channel' },
  { key: 'status', label: 'Status' },
  { key: 'sent_at', label: 'Sent At' },
  { key: 'message', label: 'Message' },
]

onMounted(async () => {
  await loadHistory()
})

async function loadHistory() {
  loading.value = true
  errorMessage.value = ''
  try {
    history.value = await creditService.getCustomerHistory()
  } catch (error) {
    errorMessage.value = error.response?.data?.message || 'Unable to load credit history.'
  } finally {
    loading.value = false
  }
}

async function submitPayment() {
  submitting.value = true
  successMessage.value = ''
  errorMessage.value = ''

  try {
    await creditService.submitPayment(paymentForm.value)
    successMessage.value = 'Payment submitted for review.'
    paymentForm.value = { ...paymentForm.value, amount: null, reference_number: '', notes: '' }
    await loadHistory()
  } catch (error) {
    errorMessage.value = error.response?.data?.message || 'Unable to submit payment.'
  } finally {
    submitting.value = false
  }
}

function formatCurrency(value) {
  const amount = Number(value) || 0
  return amount.toLocaleString('en-US', { style: 'currency', currency: 'USD' })
}

function formatDate(value) {
  if (!value) return '—'
  return new Date(value).toLocaleString()
}
</script>
