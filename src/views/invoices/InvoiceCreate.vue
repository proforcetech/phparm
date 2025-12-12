<template>
  <div>
    <!-- Page Header -->
    <div class="mb-6">
      <div class="flex items-center gap-4 mb-2">
        <Button variant="ghost" @click="$router.push('/cp/invoices')">
          <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
          </svg>
        </Button>
        <div>
          <h1 class="text-2xl font-bold text-gray-900">Create Invoice</h1>
          <p class="mt-1 text-sm text-gray-500">Create a new invoice for a customer</p>
        </div>
      </div>
    </div>

    <!-- Alert Messages -->
    <Alert v-if="successMessage" variant="success" class="mb-6">
      {{ successMessage }}
    </Alert>

    <Alert v-if="errorMessage" variant="danger" class="mb-6">
      {{ errorMessage }}
    </Alert>

    <!-- Invoice Form -->
    <form @submit.prevent="saveInvoice">
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Main Form -->
        <div class="lg:col-span-2 space-y-6">
          <!-- Customer & Vehicle Info -->
          <Card title="Customer Information">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <Select
                v-model="form.customer_id"
                :options="customers"
                label="Customer *"
                placeholder="Select customer"
                value-key="id"
                label-key="name"
                required
                @change="loadCustomerVehicles"
              />

              <Select
                v-model="form.vehicle_id"
                :options="vehicles"
                label="Vehicle"
                placeholder="Select vehicle"
                value-key="id"
                label-key="display_name"
                :disabled="!form.customer_id"
              />

              <Input
                v-model="form.invoice_date"
                type="date"
                label="Invoice Date *"
                required
              />

              <Input
                v-model="form.due_date"
                type="date"
                label="Due Date *"
                required
              />
            </div>

            <div class="mt-2 flex flex-wrap gap-4 text-sm text-gray-700">
              <label class="inline-flex items-center gap-2">
                <input v-model="form.is_mobile" type="radio" :value="false" class="h-4 w-4 text-indigo-600" />
                In shop
              </label>
              <label class="inline-flex items-center gap-2">
                <input v-model="form.is_mobile" type="radio" :value="true" class="h-4 w-4 text-indigo-600" />
                Mobile repair
              </label>
              <span class="text-xs text-gray-500">Mobile invoices require location capture on time entries.</span>
            </div>
          </Card>

          <!-- Line Items -->
          <Card title="Line Items">
            <div class="space-y-4">
              <div
                v-for="(item, index) in form.line_items"
                :key="index"
                class="border border-gray-200 rounded-lg p-4"
              >
                <div class="grid grid-cols-12 gap-3">
                  <div class="col-span-12 md:col-span-5">
                    <Input
                      v-model="item.description"
                      label="Description"
                      placeholder="Service or part description"
                      required
                    />
                  </div>

                  <div class="col-span-4 md:col-span-2">
                    <Input
                      v-model.number="item.quantity"
                      type="number"
                      label="Quantity"
                      min="1"
                      step="1"
                      required
                      @input="calculateTotals"
                    />
                  </div>

                  <div class="col-span-4 md:col-span-2">
                    <Input
                      v-model.number="item.unit_price"
                      type="number"
                      label="Unit Price"
                      min="0"
                      step="0.01"
                      required
                      @input="calculateTotals"
                    />
                  </div>

                  <div class="col-span-3 md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                      Amount
                    </label>
                    <div class="mt-1 text-sm font-semibold text-gray-900 py-2">
                      {{ formatCurrency(item.quantity * item.unit_price) }}
                    </div>
                  </div>

                  <div class="col-span-1 flex items-end">
                    <Button
                      variant="danger"
                      size="sm"
                      @click="removeLineItem(index)"
                      :disabled="form.line_items.length === 1"
                    >
                      <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                      </svg>
                    </Button>
                  </div>
                </div>

                <div class="mt-3">
                  <Textarea
                    v-model="item.notes"
                    placeholder="Additional notes (optional)"
                    rows="2"
                  />
                </div>
              </div>

              <Button variant="outline" @click="addLineItem" class="w-full">
                <svg class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                </svg>
                Add Line Item
              </Button>
            </div>
          </Card>

          <!-- Additional Information -->
          <Card title="Additional Information">
            <div class="space-y-4">
              <Textarea
                v-model="form.notes"
                label="Notes"
                placeholder="Additional notes for the invoice..."
                rows="4"
              />

              <Textarea
                v-model="form.terms"
                label="Terms & Conditions"
                placeholder="Payment terms and conditions..."
                rows="3"
              />
            </div>
          </Card>
        </div>

        <!-- Summary Sidebar -->
        <div class="space-y-6">
          <!-- Calculations -->
          <Card title="Summary">
            <dl class="space-y-3">
              <div class="flex justify-between text-sm">
                <dt class="text-gray-600">Subtotal</dt>
                <dd class="font-medium text-gray-900">{{ formatCurrency(calculations.subtotal) }}</dd>
              </div>

              <div>
                <div class="flex justify-between text-sm mb-2">
                  <dt class="text-gray-600">Tax</dt>
                  <dd class="font-medium text-gray-900">{{ formatCurrency(calculations.tax) }}</dd>
                </div>
                <Input
                  v-model.number="form.tax_rate"
                  type="number"
                  placeholder="Tax rate %"
                  step="0.01"
                  min="0"
                  max="100"
                  @input="calculateTotals"
                />
              </div>

              <div>
                <div class="flex justify-between text-sm mb-2">
                  <dt class="text-gray-600">Discount</dt>
                  <dd class="font-medium text-gray-900">-{{ formatCurrency(calculations.discount) }}</dd>
                </div>
                <Input
                  v-model.number="form.discount_amount"
                  type="number"
                  placeholder="Discount amount"
                  step="0.01"
                  min="0"
                  @input="calculateTotals"
                />
              </div>

              <div class="pt-3 border-t border-gray-200 flex justify-between">
                <dt class="text-base font-semibold text-gray-900">Total</dt>
                <dd class="text-base font-semibold text-gray-900">
                  {{ formatCurrency(calculations.total) }}
                </dd>
              </div>
            </dl>
          </Card>

          <!-- Actions -->
          <Card title="Actions">
            <div class="space-y-3">
              <Select
                v-model="form.status"
                :options="statusOptions"
                label="Status"
              />

              <Button type="submit" class="w-full" :loading="saving">
                <svg class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                </svg>
                Create Invoice
              </Button>

              <Button
                variant="outline"
                class="w-full"
                @click="saveDraft"
                :loading="saving"
              >
                Save as Draft
              </Button>

              <Button
                variant="ghost"
                class="w-full"
                @click="$router.push('/cp/invoices')"
              >
                Cancel
              </Button>
            </div>
          </Card>
        </div>
      </div>
    </form>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import Card from '@/components/ui/Card.vue'
import Button from '@/components/ui/Button.vue'
import Input from '@/components/ui/Input.vue'
import Select from '@/components/ui/Select.vue'
import Textarea from '@/components/ui/Textarea.vue'
import Alert from '@/components/ui/Alert.vue'
import invoiceService from '@/services/invoice.service'

const router = useRouter()

const saving = ref(false)
const successMessage = ref('')
const errorMessage = ref('')

const customers = ref([])
const vehicles = ref([])

const form = reactive({
  customer_id: '',
  vehicle_id: '',
  is_mobile: false,
  invoice_date: new Date().toISOString().split('T')[0],
  due_date: new Date(Date.now() + 30 * 24 * 60 * 60 * 1000).toISOString().split('T')[0],
  status: 'draft',
  tax_rate: 8.75,
  discount_amount: 0,
  notes: '',
  terms: '',
  line_items: [
    {
      description: '',
      quantity: 1,
      unit_price: 0,
      notes: '',
    },
  ],
})

const statusOptions = [
  { value: 'draft', label: 'Draft' },
  { value: 'pending', label: 'Pending' },
]

const calculations = computed(() => {
  const subtotal = form.line_items.reduce((sum, item) => {
    return sum + (item.quantity * item.unit_price)
  }, 0)

  const tax = subtotal * (form.tax_rate / 100)
  const discount = form.discount_amount || 0
  const total = subtotal + tax - discount

  return {
    subtotal,
    tax,
    discount,
    total,
  }
})

onMounted(async () => {
  await loadCustomers()
})

async function loadCustomers() {
  try {
    // In a real app, this would load from the API
    customers.value = []
  } catch (error) {
    console.error('Failed to load customers:', error)
  }
}

async function loadCustomerVehicles() {
  if (!form.customer_id) {
    vehicles.value = []
    form.vehicle_id = ''
    return
  }

  try {
    // In a real app, this would load vehicles for the selected customer
    vehicles.value = []
  } catch (error) {
    console.error('Failed to load vehicles:', error)
  }
}

function addLineItem() {
  form.line_items.push({
    description: '',
    quantity: 1,
    unit_price: 0,
    notes: '',
  })
}

function removeLineItem(index) {
  if (form.line_items.length > 1) {
    form.line_items.splice(index, 1)
    calculateTotals()
  }
}

function calculateTotals() {
  // Trigger reactivity
}

async function saveInvoice() {
  try {
    saving.value = true
    errorMessage.value = ''

    const invoiceData = {
      ...form,
      subtotal: calculations.value.subtotal,
      tax_amount: calculations.value.tax,
      discount_amount: calculations.value.discount,
      total_amount: calculations.value.total,
    }

    const response = await invoiceService.create(invoiceData)

    successMessage.value = 'Invoice created successfully!'

    // Redirect to invoice detail page after a short delay
    setTimeout(() => {
      router.push(`/invoices/${response.id}`)
    }, 1500)
  } catch (error) {
    console.error('Failed to create invoice:', error)
    errorMessage.value = error.response?.data?.message || 'Failed to create invoice. Please try again.'
  } finally {
    saving.value = false
  }
}

async function saveDraft() {
  form.status = 'draft'
  await saveInvoice()
}

function formatCurrency(amount) {
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
  }).format(amount || 0)
}
</script>
