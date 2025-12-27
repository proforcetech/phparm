<template>
  <div>
    <!-- Loading State -->
    <div v-if="loading" class="flex justify-center py-12">
      <Loading size="xl" text="Loading estimate..." />
    </div>

    <!-- Error State -->
    <Alert v-else-if="error" variant="danger" class="mb-6">
      {{ error }}
    </Alert>

    <!-- Estimate Details -->
    <div v-else-if="estimate">
      <!-- Header -->
      <div class="mb-6">
        <div class="flex items-center justify-between mb-2">
          <div class="flex items-center gap-4">
            <Button variant="ghost" @click="$router.back()">
              <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
              </svg>
            </Button>
            <div>
              <h1 class="text-2xl font-bold text-gray-900">Estimate {{ estimate.number }}</h1>
              <p class="text-sm text-gray-500">Created {{ formatDate(estimate.created_at) }}</p>
            </div>
          </div>
          <div class="flex items-center gap-2">
            <Badge v-if="estimate.is_mobile" variant="warning" size="lg">Mobile repair</Badge>
            <Badge :variant="getStatusVariant(estimate.status)" size="lg">
              {{ formatStatus(estimate.status) }}
            </Badge>
          </div>
        </div>

        <!-- Actions -->
        <div class="flex gap-2 mt-4">
          <Button
            v-if="['pending', 'sent'].includes(estimate.status)"
            variant="primary"
            @click="approveEstimate"
          >
            Approve
          </Button>
          <Button
            v-if="['pending', 'sent'].includes(estimate.status)"
            variant="danger"
            @click="declineEstimate"
          >
            Reject
          </Button>
          <Button
            v-if="estimate.status === 'approved'"
            variant="primary"
            @click="showWorkorderModal = true"
          >
            Create Workorder
          </Button>
          <Button
            v-if="estimate.status === 'approved'"
            @click="showConvertModal = true"
          >
            Convert to Invoice
          </Button>
          <Button
            v-if="['pending', 'sent'].includes(estimate.status)"
            variant="outline"
            @click="expireEstimate"
          >
            Mark as Expired
          </Button>
          <Button
            variant="outline"
            @click="$router.push(`/estimates/${estimate.id}/edit`)"
          >
            <svg class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
            </svg>
            Edit
          </Button>
          <Button
            variant="danger"
            @click="deleteEstimate"
          >
            <svg class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
            </svg>
            Delete
          </Button>
        </div>
      </div>

      <!-- Main Content Grid -->
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Left Column - Estimate Details -->
        <div class="lg:col-span-2 space-y-6">
          <!-- Customer & Vehicle Info -->
          <Card>
            <template #header>
              <h3 class="text-lg font-medium text-gray-900">Customer & Vehicle</h3>
            </template>
            <div class="grid grid-cols-2 gap-4">
              <div>
                <label class="text-sm font-medium text-gray-500">Customer</label>
                <p class="mt-1 text-sm text-gray-900">
                  <router-link
                    :to="`/customers/${estimate.customer_id}`"
                    class="text-primary-600 hover:text-primary-800"
                  >
                    Customer #{{ estimate.customer_id }}
                  </router-link>
                </p>
              </div>
              <div>
                <label class="text-sm font-medium text-gray-500">Vehicle</label>
                <p class="mt-1 text-sm text-gray-900">
                  <router-link
                    :to="`/vehicles/${estimate.vehicle_id}`"
                    class="text-primary-600 hover:text-primary-800"
                  >
                    Vehicle #{{ estimate.vehicle_id }}
                  </router-link>
                </p>
              </div>
              <div v-if="estimate.technician_id">
                <label class="text-sm font-medium text-gray-500">Technician</label>
                <p class="mt-1 text-sm text-gray-900">Technician #{{ estimate.technician_id }}</p>
              </div>
              <div v-if="estimate.expiration_date">
                <label class="text-sm font-medium text-gray-500">Expiration Date</label>
                <p
                  class="mt-1 text-sm"
                  :class="isExpired(estimate.expiration_date) ? 'text-red-600 font-medium' : 'text-gray-900'"
                >
                  {{ formatDate(estimate.expiration_date) }}
                  <span v-if="isExpired(estimate.expiration_date)" class="text-xs">(Expired)</span>
                  <span v-else-if="isExpiringSoon(estimate.expiration_date)" class="text-xs text-amber-600">(Expiring soon)</span>
                </p>
              </div>
            </div>
          </Card>

          <!-- Notes -->
          <Card v-if="estimate.customer_notes || estimate.internal_notes">
            <template #header>
              <h3 class="text-lg font-medium text-gray-900">Notes</h3>
            </template>
            <div class="space-y-4">
              <div v-if="estimate.customer_notes">
                <label class="text-sm font-medium text-gray-500">Customer Notes</label>
                <p class="mt-1 text-sm text-gray-900 whitespace-pre-wrap">{{ estimate.customer_notes }}</p>
              </div>
              <div v-if="estimate.internal_notes">
                <label class="text-sm font-medium text-gray-500">Internal Notes</label>
                <p class="mt-1 text-sm text-gray-900 whitespace-pre-wrap">{{ estimate.internal_notes }}</p>
              </div>
            </div>
          </Card>
        </div>

        <!-- Right Column - Financial Summary -->
        <div>
          <Card>
            <template #header>
              <h3 class="text-lg font-medium text-gray-900">Summary</h3>
            </template>
            <div class="space-y-3">
              <div class="flex justify-between">
                <span class="text-sm text-gray-600">Subtotal</span>
                <span class="text-sm font-medium text-gray-900">{{ formatCurrency(estimate.subtotal) }}</span>
              </div>
              <div v-if="estimate.call_out_fee > 0" class="flex justify-between">
                <span class="text-sm text-gray-600">Call-out Fee</span>
                <span class="text-sm font-medium text-gray-900">{{ formatCurrency(estimate.call_out_fee) }}</span>
              </div>
              <div v-if="estimate.mileage_total > 0" class="flex justify-between">
                <span class="text-sm text-gray-600">Mileage</span>
                <span class="text-sm font-medium text-gray-900">{{ formatCurrency(estimate.mileage_total) }}</span>
              </div>
              <div v-if="estimate.discounts > 0" class="flex justify-between text-green-600">
                <span class="text-sm">Discounts</span>
                <span class="text-sm font-medium">-{{ formatCurrency(estimate.discounts) }}</span>
              </div>
              <div class="flex justify-between">
                <span class="text-sm text-gray-600">Tax</span>
                <span class="text-sm font-medium text-gray-900">{{ formatCurrency(estimate.tax) }}</span>
              </div>
              <div class="border-t border-gray-200 pt-3 flex justify-between">
                <span class="text-base font-medium text-gray-900">Grand Total</span>
                <span class="text-base font-bold text-gray-900">{{ formatCurrency(estimate.grand_total) }}</span>
              </div>
            </div>
          </Card>
        </div>
      </div>
    </div>

    <!-- Convert to Invoice Modal -->
    <Modal v-if="showConvertModal" @close="showConvertModal = false">
      <template #title>Convert to Invoice</template>
      <template #content>
        <div class="space-y-4">
          <p class="text-sm text-gray-600">
            Convert estimate #{{ estimate?.number }} to an invoice?
          </p>
          <div>
            <label class="block text-sm font-medium text-gray-700">Issue Date *</label>
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
        <Button @click="confirmConvert" :disabled="!convertForm.issue_date || converting">
          {{ converting ? 'Converting...' : 'Convert to Invoice' }}
        </Button>
      </template>
    </Modal>

    <!-- Create Workorder Modal -->
    <Modal v-if="showWorkorderModal" @close="showWorkorderModal = false">
      <template #title>Create Workorder</template>
      <template #content>
        <div class="space-y-4">
          <p class="text-sm text-gray-600">
            Create a workorder from estimate #{{ estimate?.number }}?
          </p>
          <Alert variant="info">
            A workorder will be created with all approved jobs from this estimate.
            You can then track work progress and assign technicians.
          </Alert>
          <div>
            <label class="block text-sm font-medium text-gray-700">Assign Technician (Optional)</label>
            <Select
              v-model="workorderForm.technician_id"
              :options="technicianOptions"
              placeholder="Select technician"
              class="mt-1"
            />
          </div>
        </div>
      </template>
      <template #actions>
        <Button variant="outline" @click="showWorkorderModal = false">Cancel</Button>
        <Button @click="confirmCreateWorkorder" :disabled="creatingWorkorder">
          {{ creatingWorkorder ? 'Creating...' : 'Create Workorder' }}
        </Button>
      </template>
    </Modal>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import Card from '@/components/ui/Card.vue'
import Button from '@/components/ui/Button.vue'
import Badge from '@/components/ui/Badge.vue'
import Input from '@/components/ui/Input.vue'
import Select from '@/components/ui/Select.vue'
import Alert from '@/components/ui/Alert.vue'
import Loading from '@/components/ui/Loading.vue'
import Modal from '@/components/ui/Modal.vue'
import estimateService from '@/services/estimate.service'
import workorderService from '@/services/workorder.service'
import userService from '@/services/user.service'
import { useToast } from '@/stores/toast'

const router = useRouter()
const route = useRoute()
const toast = useToast()

const loading = ref(true)
const converting = ref(false)
const creatingWorkorder = ref(false)
const error = ref(null)
const estimate = ref(null)
const technicians = ref([])
const showConvertModal = ref(false)
const showWorkorderModal = ref(false)

const convertForm = reactive({
  issue_date: new Date().toISOString().split('T')[0],
  due_date: ''
})

const workorderForm = reactive({
  technician_id: ''
})

const technicianOptions = ref([{ value: '', label: 'Unassigned' }])

onMounted(() => {
  loadEstimate()
  loadTechnicians()
})

async function loadEstimate() {
  try {
    loading.value = true
    error.value = null
    const response = await estimateService.getEstimate(route.params.id)
    estimate.value = response.data
  } catch (err) {
    console.error('Failed to load estimate:', err)
    error.value = err.response?.data?.message || 'Failed to load estimate'
  } finally {
    loading.value = false
  }
}

async function loadTechnicians() {
  try {
    const response = await userService.getUsers({ role: 'technician' })
    const users = response.data || []
    technicians.value = users
    technicianOptions.value = [
      { value: '', label: 'Unassigned' },
      ...users.map(u => ({ value: u.id, label: u.name }))
    ]
  } catch (err) {
    console.error('Failed to load technicians:', err)
  }
}

async function confirmCreateWorkorder() {
  try {
    creatingWorkorder.value = true
    const response = await workorderService.createFromEstimate(
      estimate.value.id,
      workorderForm.technician_id || null
    )

    toast.success('Workorder created successfully')
    showWorkorderModal.value = false

    // Redirect to the new workorder
    if (response.data?.id) {
      router.push(`/cp/workorders/${response.data.id}`)
    }
  } catch (err) {
    console.error('Failed to create workorder:', err)
    toast.error(err.response?.data?.error || 'Failed to create workorder')
  } finally {
    creatingWorkorder.value = false
  }
}

async function approveEstimate() {
  try {
    await estimateService.approveEstimate(estimate.value.id)
    toast.success('Estimate approved successfully')
    loadEstimate()
  } catch (err) {
    console.error('Failed to approve estimate:', err)
    toast.error(err.response?.data?.message || 'Failed to approve estimate')
  }
}

async function declineEstimate() {
  if (!confirm('Are you sure you want to reject this estimate?')) return

  try {
    await estimateService.declineEstimate(estimate.value.id)
    toast.success('Estimate rejected')
    loadEstimate()
  } catch (err) {
    console.error('Failed to reject estimate:', err)
    toast.error(err.response?.data?.message || 'Failed to reject estimate')
  }
}

async function requestReapproval() {
  try {
    await estimateService.requestReapproval(estimate.value.id)
    toast.success('Reapproval requested')
    loadEstimate()
  } catch (err) {
    console.error('Failed to request reapproval:', err)
    toast.error(err.response?.data?.message || 'Failed to request reapproval')
  }
}

async function expireEstimate() {
  if (!confirm('Mark this estimate as expired?')) return

  try {
    await estimateService.expireEstimate(estimate.value.id)
    toast.success('Estimate marked as expired')
    loadEstimate()
  } catch (err) {
    console.error('Failed to expire estimate:', err)
    toast.error(err.response?.data?.message || 'Failed to expire estimate')
  }
}

async function confirmConvert() {
  try {
    converting.value = true
    const response = await estimateService.convertToInvoice(estimate.value.id, {
      issue_date: convertForm.issue_date,
      due_date: convertForm.due_date || null
    })

    toast.success('Estimate converted to invoice successfully')
    showConvertModal.value = false

    // Redirect to the new invoice
    if (response.data?.id) {
      router.push(`/invoices/${response.data.id}`)
    }
  } catch (err) {
    console.error('Failed to convert estimate:', err)
    toast.error(err.response?.data?.message || 'Failed to convert estimate')
  } finally {
    converting.value = false
  }
}

async function deleteEstimate() {
  if (!confirm('Are you sure you want to delete this estimate? This action cannot be undone.')) return

  try {
    await estimateService.deleteEstimate(estimate.value.id)
    toast.success('Estimate deleted successfully')
    router.push('/cp/estimates')
  } catch (err) {
    console.error('Failed to delete estimate:', err)
    toast.error(err.response?.data?.message || 'Failed to delete estimate')
  }
}

function getStatusVariant(status) {
  const variants = {
    sent: 'info',
    pending: 'default',
    approved: 'success',
    rejected: 'danger',
    expired: 'warning',
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

function isExpired(expirationDate) {
  if (!expirationDate) return false
  return new Date(expirationDate) < new Date()
}

function isExpiringSoon(expirationDate) {
  if (!expirationDate) return false
  const expiry = new Date(expirationDate)
  const now = new Date()
  const daysUntilExpiry = (expiry - now) / (1000 * 60 * 60 * 24)
  return daysUntilExpiry > 0 && daysUntilExpiry <= 7
}
</script>
