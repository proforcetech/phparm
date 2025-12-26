<template>
  <div>
    <!-- Page Header -->
    <div class="mb-6">
      <div class="flex items-center gap-4 mb-2">
        <Button variant="ghost" @click="$router.push('/cp/estimates')">
          <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
          </svg>
        </Button>
        <div>
          <h1 class="text-2xl font-bold text-gray-900">{{ isEditing ? 'Edit Estimate' : 'Create Estimate' }}</h1>
          <p class="mt-1 text-sm text-gray-500">{{ isEditing ? 'Update estimate details' : 'Create a new estimate for a customer' }}</p>
        </div>
      </div>
    </div>

    <!-- Loading State -->
    <div v-if="loading" class="flex justify-center py-12">
      <Loading size="xl" text="Loading..." />
    </div>

    <!-- Form -->
    <form v-else @submit.prevent="saveEstimate">
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Main Form -->
        <div class="lg:col-span-2 space-y-6">
          <!-- Customer & Vehicle Info -->
          <Card>
            <template #header>
              <h3 class="text-lg font-medium text-gray-900">Customer Information</h3>
            </template>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <Autocomplete
                  v-model="form.customer_id"
                  label="Customer"
                  placeholder="Search by name, email, phone, or ID..."
                  :search-fn="searchCustomers"
                  :item-value="(item) => item.id"
                  :item-label="(item) => `${item.first_name} ${item.last_name}`"
                  :item-subtext="(item) => `${item.email || ''} ${item.phone ? '• ' + item.phone : ''}`"
                  required
                />
              </div>

              <div>
                <Autocomplete
                  v-model="form.vehicle_id"
                  label="Vehicle"
                  placeholder="Select a vehicle..."
                  :search-fn="searchVehicles"
                  :item-value="(item) => item.id"
                  :item-label="(item) => `${item.year} ${item.make} ${item.model}`"
                  :item-subtext="(item) => item.vin || item.license_plate || ''"
                  required
                  :disabled="!form.customer_id"
                />
                <p v-if="!form.customer_id" class="mt-1 text-xs text-gray-500">
                  Select a customer first
                </p>
              </div>

              <div>
                <label class="block text-sm font-medium text-gray-700">Repair Type</label>
                <div class="mt-2 flex flex-wrap gap-3 text-sm text-gray-700">
                  <label class="inline-flex items-center gap-2">
                    <input v-model="form.is_mobile" type="radio" :value="false" class="h-4 w-4 text-indigo-600" />
                    In shop
                  </label>
                  <label class="inline-flex items-center gap-2">
                    <input v-model="form.is_mobile" type="radio" :value="true" class="h-4 w-4 text-indigo-600" />
                    Mobile (location required for time tracking)
                  </label>
                </div>
              </div>

              <div>
                <Autocomplete
                  v-model="form.technician_id"
                  label="Technician"
                  placeholder="Search by name or email..."
                  :search-fn="searchTechnicians"
                  :item-value="(item) => item.id"
                  :item-label="(item) => item.name"
                  :item-subtext="(item) => item.email"
                />
              </div>

              <div>
                <label class="block text-sm font-medium text-gray-700">Expiration Date</label>
                <Input
                  v-model="form.expiration_date"
                  type="date"
                  :min="today"
                  class="mt-1"
                />
              </div>
            </div>
          </Card>

          <!-- Line Items -->
          <Card>
            <template #header>
              <h3 class="text-lg font-medium text-gray-900">Line Items</h3>
            </template>

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
                      placeholder="Service or part description"
                      label="Description"
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
                      @input="calculateGrandTotal"
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
                      @input="calculateGrandTotal"
                    />
                  </div>

                  <div class="col-span-4 md:col-span-2">
                    <Input
                      v-model.number="item.list_price"
                      type="number"
                      label="List Price"
                      min="0"
                      step="0.01"
                      @input="calculateGrandTotal"
                    />
                  </div>

                  <div class="col-span-4 md:col-span-2 flex items-end">
                    <div>
                      <p class="text-xs text-gray-500">Amount</p>
                      <p class="text-sm font-semibold">{{ formatCurrency(item.quantity * item.unit_price) }}</p>
                    </div>
                  </div>

                  <div class="col-span-12 md:col-span-1 flex items-end justify-end">
                    <Button
                      variant="ghost"
                      size="sm"
                      @click="removeLineItem(index)"
                      :disabled="form.line_items.length === 1"
                    >
                      <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path
                          stroke-linecap="round"
                          stroke-linejoin="round"
                          stroke-width="2"
                          d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"
                        />
                      </svg>
                    </Button>
                  </div>
                </div>

                <div class="mt-3">
                  <Textarea
                    v-model="item.notes"
                    placeholder="Additional notes (optional)"
                    :rows="2"
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

          <!-- Pricing -->
          <Card>
            <template #header>
              <h3 class="text-lg font-medium text-gray-900">Pricing Details</h3>
            </template>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-gray-700">Subtotal *</label>
                <Input
                  :model-value="form.subtotal"
                  type="number"
                  step="0.01"
                  min="0"
                  placeholder="0.00"
                  class="mt-1 bg-gray-50"
                  required
                  readonly
                />
              </div>

              <div>
                <label class="block text-sm font-medium text-gray-700">Tax</label>
                <Input
                  v-model.number="form.tax"
                  type="number"
                  step="0.01"
                  min="0"
                  placeholder="0.00"
                  class="mt-1"
                  @input="calculateGrandTotal"
                />
              </div>

              <div>
                <label class="block text-sm font-medium text-gray-700">Call-out Fee</label>
                <Input
                  v-model.number="form.call_out_fee"
                  type="number"
                  step="0.01"
                  min="0"
                  placeholder="0.00"
                  class="mt-1"
                  @input="calculateGrandTotal"
                />
              </div>

              <div>
                <label class="block text-sm font-medium text-gray-700">Mileage Total</label>
                <Input
                  v-model.number="form.mileage_total"
                  type="number"
                  step="0.01"
                  min="0"
                  placeholder="0.00"
                  class="mt-1"
                  @input="calculateGrandTotal"
                />
              </div>

              <div>
                <label class="block text-sm font-medium text-gray-700">Discounts</label>
                <Input
                  v-model.number="form.discounts"
                  type="number"
                  step="0.01"
                  min="0"
                  placeholder="0.00"
                  class="mt-1"
                  @input="calculateGrandTotal"
                />
              </div>

              <div>
                <label class="block text-sm font-medium text-gray-700">Grand Total</label>
                <Input
                  :model-value="form.grand_total"
                  type="number"
                  step="0.01"
                  class="mt-1 bg-gray-50"
                  readonly
                />
              </div>
            </div>
          </Card>

          <!-- Notes -->
          <Card>
            <template #header>
              <h3 class="text-lg font-medium text-gray-900">Notes</h3>
            </template>
            <div class="space-y-4">
              <div>
                <label class="block text-sm font-medium text-gray-700">Customer Notes</label>
                <Textarea
                  v-model="form.customer_notes"
                  :rows="3"
                  placeholder="Notes visible to customer"
                  class="mt-1"
                />
              </div>

              <div>
                <label class="block text-sm font-medium text-gray-700">Internal Notes</label>
                <Textarea
                  v-model="form.internal_notes"
                  :rows="3"
                  placeholder="Internal notes (not visible to customer)"
                  class="mt-1"
                />
              </div>
            </div>
          </Card>
        </div>

        <!-- Right Column - Summary & Actions -->
        <div class="space-y-6">
          <!-- Summary Card -->
          <Card>
            <template #header>
              <h3 class="text-lg font-medium text-gray-900">Summary</h3>
            </template>
            <div class="space-y-3">
              <div class="flex justify-between text-sm">
                <span class="text-gray-600">Subtotal</span>
                <span class="font-medium">{{ formatCurrency(form.subtotal) }}</span>
              </div>
              <div v-if="form.call_out_fee > 0" class="flex justify-between text-sm">
                <span class="text-gray-600">Call-out Fee</span>
                <span class="font-medium">{{ formatCurrency(form.call_out_fee) }}</span>
              </div>
              <div v-if="form.mileage_total > 0" class="flex justify-between text-sm">
                <span class="text-gray-600">Mileage</span>
                <span class="font-medium">{{ formatCurrency(form.mileage_total) }}</span>
              </div>
              <div v-if="form.discounts > 0" class="flex justify-between text-sm text-green-600">
                <span>Discounts</span>
                <span class="font-medium">-{{ formatCurrency(form.discounts) }}</span>
              </div>
              <div class="flex justify-between text-sm">
                <span class="text-gray-600">Tax</span>
                <span class="font-medium">{{ formatCurrency(form.tax) }}</span>
              </div>
              <div class="border-t border-gray-200 pt-3 flex justify-between">
                <span class="font-medium">Grand Total</span>
                <span class="text-lg font-bold text-primary-600">{{ formatCurrency(form.grand_total) }}</span>
              </div>
            </div>
          </Card>

          <!-- Status Selection (for editing) -->
          <Card v-if="isEditing">
            <template #header>
              <h3 class="text-lg font-medium text-gray-900">Status</h3>
            </template>
            <Select
              v-model="form.status"
              :options="statusOptions"
              label="Estimate Status"
              required
            />
          </Card>

          <!-- Actions -->
          <Card>
            <template #header>
              <h3 class="text-lg font-medium text-gray-900">Actions</h3>
            </template>
            <div class="space-y-3">
              <Button
                type="submit"
                class="w-full"
                :disabled="saving"
              >
                {{ saving ? 'Saving...' : (isEditing ? 'Update Estimate' : 'Create Estimate') }}
              </Button>
              <Button
                variant="outline"
                class="w-full"
                @click="$router.push('/cp/estimates')"
                :disabled="saving"
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
import { ref, reactive, onMounted, computed, watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import Card from '@/components/ui/Card.vue'
import Button from '@/components/ui/Button.vue'
import Input from '@/components/ui/Input.vue'
import Select from '@/components/ui/Select.vue'
import Textarea from '@/components/ui/Textarea.vue'
import Loading from '@/components/ui/Loading.vue'
import Autocomplete from '@/components/ui/Autocomplete.vue'
import estimateService from '@/services/estimate.service'
import customerService from '@/services/customer.service'
import technicianService from '@/services/technician.service'
import { useToast } from '@/stores/toast'

const router = useRouter()
const route = useRoute()
const toast = useToast()

const loading = ref(false)
const saving = ref(false)
const today = new Date().toISOString().substring(0, 10)

const form = reactive({
  customer_id: null,
  vehicle_id: null,
  is_mobile: false,
  technician_id: null,
  expiration_date: new Date(Date.now() + 14 * 24 * 60 * 60 * 1000).toISOString().substring(0, 10),
  subtotal: 0,
  tax: 0,
  call_out_fee: 0,
  mileage_total: 0,
  discounts: 0,
  grand_total: 0,
  customer_notes: '',
  internal_notes: '',
  status: 'pending',
  line_items: [
    {
      description: '',
      quantity: 1,
      unit_price: 0,
      list_price: 0,
      notes: ''
    }
  ]
})

const statusOptions = [
  { value: 'sent', label: 'Sent' },
  { value: 'pending', label: 'Pending' },
  { value: 'approved', label: 'Approved' },
  { value: 'rejected', label: 'Rejected' }
]

const isEditing = computed(() => !!route.params.id)

onMounted(() => {
  if (isEditing.value) {
    loadEstimate()
  }
})

function addLineItem() {
  form.line_items.push({
    description: '',
    quantity: 1,
    unit_price: 0,
    list_price: 0,
    notes: ''
  })
}

function removeLineItem(index) {
  if (form.line_items.length > 1) {
    form.line_items.splice(index, 1)
    calculateGrandTotal()
  }
}

async function loadEstimate() {
  try {
    loading.value = true
    const response = await estimateService.getEstimate(route.params.id)
    Object.assign(form, response.data, {
      is_mobile: !!response.data.is_mobile,
      line_items: response.data.line_items?.length
        ? response.data.line_items.map(item => ({
            description: item.description || '',
            quantity: Number(item.quantity) || 1,
            unit_price: Number(item.unit_price) || 0,
            list_price: Number(item.list_price) || 0,
            notes: item.notes || ''
          }))
        : [
            {
              description: '',
              quantity: 1,
              unit_price: 0,
              list_price: 0,
              notes: ''
            }
          ]
    })
    calculateGrandTotal()
  } catch (error) {
    console.error('Failed to load estimate:', error)
    toast.error('Failed to load estimate')
    router.push('/cp/estimates')
  } finally {
    loading.value = false
  }
}

function calculateGrandTotal() {
  const subtotal = form.line_items.reduce((sum, item) => {
    const quantity = Number(item.quantity) || 0
    const unitPrice = Number(item.unit_price) || 0
    return sum + quantity * unitPrice
  }, 0)
  const tax = parseFloat(form.tax) || 0
  const callOutFee = parseFloat(form.call_out_fee) || 0
  const mileageTotal = parseFloat(form.mileage_total) || 0
  const discounts = parseFloat(form.discounts) || 0

  form.subtotal = subtotal
  form.grand_total = subtotal + tax + callOutFee + mileageTotal - discounts
}

async function saveEstimate() {
  try {
    saving.value = true

    if (form.expiration_date && form.expiration_date < today) {
      toast.error('Expiration date cannot be in the past')
      return
    }

    // Prepare data
      const data = {
        customer_id: parseInt(form.customer_id),
        vehicle_id: parseInt(form.vehicle_id),
        is_mobile: !!form.is_mobile,
        technician_id: form.technician_id ? parseInt(form.technician_id) : null,
        expiration_date: form.expiration_date || null,
        subtotal: parseFloat(form.subtotal),
        tax: parseFloat(form.tax) || 0,
        call_out_fee: parseFloat(form.call_out_fee) || 0,
        mileage_total: parseFloat(form.mileage_total) || 0,
        discounts: parseFloat(form.discounts) || 0,
        grand_total: parseFloat(form.grand_total),
        customer_notes: form.customer_notes || null,
        internal_notes: form.internal_notes || null,
        status: form.status || 'pending',
        line_items: form.line_items.map(item => ({
          description: item.description,
          quantity: Number(item.quantity) || 0,
          unit_price: Number(item.unit_price) || 0,
          notes: item.notes || null
        }))
      }

    let response
    if (isEditing.value) {
      response = await estimateService.updateEstimate(route.params.id, data)
      toast.success('Estimate updated successfully')
    } else {
      response = await estimateService.createEstimate(data)
      toast.success('Estimate created successfully')
    }

    // Redirect to estimate detail
    if (response.data?.id) {
      router.push(`/estimates/${response.data.id}`)
    } else {
      router.push('/cp/estimates')
    }
  } catch (error) {
    console.error('Failed to save estimate:', error)
    toast.error(error.response?.data?.message || 'Failed to save estimate')
  } finally {
    saving.value = false
  }
}

function formatCurrency(amount) {
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD'
  }).format(amount || 0)
}

async function searchCustomers(query) {
  try {
    return await customerService.searchCustomers(query)
  } catch (error) {
    console.error('Customer search failed:', error)
    return []
  }
}

async function searchVehicles(query) {
  if (!form.customer_id) return []

  try {
    const vehicles = await customerService.getCustomerVehicles(form.customer_id)

    // Filter by query if provided
    if (query) {
      const lowerQuery = query.toLowerCase()
      return vehicles.filter(v =>
        `${v.year} ${v.make} ${v.model}`.toLowerCase().includes(lowerQuery) ||
        (v.vin && v.vin.toLowerCase().includes(lowerQuery)) ||
        (v.license_plate && v.license_plate.toLowerCase().includes(lowerQuery))
      )
    }

    return vehicles
  } catch (error) {
    console.error('Failed to load vehicles:', error)
    return []
  }
}

async function searchTechnicians(query) {
  try {
    const technicians = await technicianService.searchTechnicians(query || '')
    return technicians || []
  } catch (error) {
    console.error('Technician search failed:', error)
    return []
  }
}

// Watch for customer changes and clear vehicle selection
watch(() => form.customer_id, (newCustomerId, oldCustomerId) => {
  // Only clear vehicle if customer actually changed (not initial load)
  if (oldCustomerId !== undefined && newCustomerId !== oldCustomerId) {
    form.vehicle_id = null
  }
})
</script>
