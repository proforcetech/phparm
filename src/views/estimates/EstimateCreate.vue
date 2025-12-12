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
                <label class="block text-sm font-medium text-gray-700">Customer ID *</label>
                <Input
                  v-model.number="form.customer_id"
                  type="number"
                  placeholder="Customer ID"
                  class="mt-1"
                  required
                />
              </div>

              <div>
                <label class="block text-sm font-medium text-gray-700">Vehicle ID *</label>
                <Input
                  v-model.number="form.vehicle_id"
                  type="number"
                  placeholder="Vehicle ID"
                  class="mt-1"
                  required
                />
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
                <label class="block text-sm font-medium text-gray-700">Technician ID</label>
                <Input
                  v-model.number="form.technician_id"
                  type="number"
                  placeholder="Assign technician (optional)"
                  class="mt-1"
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

          <!-- Pricing -->
          <Card>
            <template #header>
              <h3 class="text-lg font-medium text-gray-900">Pricing Details</h3>
            </template>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-gray-700">Subtotal *</label>
                <Input
                  v-model.number="form.subtotal"
                  type="number"
                  step="0.01"
                  min="0"
                  placeholder="0.00"
                  class="mt-1"
                  required
                  @input="calculateGrandTotal"
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
                  rows="3"
                  placeholder="Notes visible to customer"
                  class="mt-1"
                />
              </div>

              <div>
                <label class="block text-sm font-medium text-gray-700">Internal Notes</label>
                <Textarea
                  v-model="form.internal_notes"
                  rows="3"
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
import { ref, reactive, onMounted, computed } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import Card from '@/components/ui/Card.vue'
import Button from '@/components/ui/Button.vue'
import Input from '@/components/ui/Input.vue'
import Select from '@/components/ui/Select.vue'
import Textarea from '@/components/ui/Textarea.vue'
import Loading from '@/components/ui/Loading.vue'
import estimateService from '@/services/estimate.service'
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
  status: 'pending'
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

async function loadEstimate() {
  try {
    loading.value = true
    const response = await estimateService.getEstimate(route.params.id)
    Object.assign(form, response.data, { is_mobile: !!response.data.is_mobile })
  } catch (error) {
    console.error('Failed to load estimate:', error)
    toast.error('Failed to load estimate')
    router.push('/cp/estimates')
  } finally {
    loading.value = false
  }
}

function calculateGrandTotal() {
  const subtotal = parseFloat(form.subtotal) || 0
  const tax = parseFloat(form.tax) || 0
  const callOutFee = parseFloat(form.call_out_fee) || 0
  const mileageTotal = parseFloat(form.mileage_total) || 0
  const discounts = parseFloat(form.discounts) || 0

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
        status: form.status || 'pending'
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
</script>
