<template>
  <div class="min-h-screen bg-gray-50 py-8 px-4 sm:px-6 lg:px-8">
    <div class="max-w-4xl mx-auto">
      <!-- Loading State -->
      <div v-if="loading" class="text-center py-12">
        <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600 mx-auto"></div>
        <p class="mt-4 text-gray-600">Loading estimate...</p>
      </div>

      <!-- Error State -->
      <div v-else-if="error" class="bg-red-50 border border-red-200 rounded-lg p-6 text-center">
        <h2 class="text-xl font-semibold text-red-800">Unable to Load Estimate</h2>
        <p class="mt-2 text-red-600">{{ error }}</p>
      </div>

      <!-- Estimate Content -->
      <div v-else-if="estimate">
        <!-- Header -->
        <div class="bg-white shadow rounded-lg p-6 mb-6">
          <div class="flex justify-between items-start">
            <div>
              <h1 class="text-2xl font-bold text-gray-900">Estimate #{{ estimate.number }}</h1>
              <p class="mt-1 text-gray-600">
                Status:
                <span :class="statusClass">{{ estimate.status }}</span>
              </p>
            </div>
            <div class="text-right">
              <p class="text-sm text-gray-500">Created</p>
              <p class="font-medium">{{ formatDate(estimate.created_at) }}</p>
              <p v-if="estimate.expiration_date" class="mt-2 text-sm text-gray-500">Expires</p>
              <p v-if="estimate.expiration_date" class="font-medium">{{ formatDate(estimate.expiration_date) }}</p>
            </div>
          </div>
        </div>

        <!-- Customer & Vehicle Info -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
          <div class="bg-white shadow rounded-lg p-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-3">Customer</h2>
            <p class="font-medium">{{ customer?.name || 'N/A' }}</p>
            <p v-if="customer?.email" class="text-gray-600">{{ customer.email }}</p>
            <p v-if="customer?.phone" class="text-gray-600">{{ customer.phone }}</p>
          </div>
          <div class="bg-white shadow rounded-lg p-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-3">Vehicle</h2>
            <p class="font-medium">{{ vehicleDescription }}</p>
            <p v-if="vehicle?.vin" class="text-gray-600">VIN: {{ vehicle.vin }}</p>
            <p v-if="vehicle?.license_plate" class="text-gray-600">License: {{ vehicle.license_plate }}</p>
          </div>
        </div>

        <!-- Jobs -->
        <div class="bg-white shadow rounded-lg p-6 mb-6">
          <h2 class="text-lg font-semibold text-gray-900 mb-4">Services</h2>

          <div v-for="job in jobs" :key="job.id" class="border-b last:border-b-0 py-4">
            <div class="flex justify-between items-start">
              <div class="flex-1">
                <h3 class="font-medium text-gray-900">{{ job.name || job.description }}</h3>
                <p v-if="job.description && job.name" class="text-sm text-gray-600 mt-1">{{ job.description }}</p>
              </div>
              <div class="text-right ml-4">
                <p class="font-semibold">${{ formatNumber(job.total || 0) }}</p>
                <span v-if="job.customer_status" :class="jobStatusClass(job.customer_status)" class="text-xs px-2 py-1 rounded">
                  {{ job.customer_status }}
                </span>
              </div>
            </div>

            <!-- Job Items -->
            <div v-if="job.items && job.items.length" class="mt-3 pl-4 border-l-2 border-gray-200">
              <div v-for="item in job.items" :key="item.id" class="flex justify-between text-sm py-1">
                <span class="text-gray-600">{{ item.description }} (x{{ item.quantity }})</span>
                <span class="text-gray-900">${{ formatNumber(item.total || 0) }}</span>
              </div>
            </div>
          </div>
        </div>

        <!-- Totals -->
        <div class="bg-white shadow rounded-lg p-6 mb-6">
          <div class="space-y-2">
            <div class="flex justify-between">
              <span class="text-gray-600">Subtotal</span>
              <span>${{ formatNumber(estimate.subtotal) }}</span>
            </div>
            <div v-if="estimate.discounts > 0" class="flex justify-between text-green-600">
              <span>Discounts</span>
              <span>-${{ formatNumber(estimate.discounts) }}</span>
            </div>
            <div v-if="estimate.tax > 0" class="flex justify-between">
              <span class="text-gray-600">Tax</span>
              <span>${{ formatNumber(estimate.tax) }}</span>
            </div>
            <div class="flex justify-between text-lg font-bold border-t pt-2">
              <span>Total</span>
              <span>${{ formatNumber(estimate.grand_total) }}</span>
            </div>
          </div>
        </div>

        <!-- Notes -->
        <div v-if="estimate.customer_notes" class="bg-white shadow rounded-lg p-6 mb-6">
          <h2 class="text-lg font-semibold text-gray-900 mb-3">Notes</h2>
          <p class="text-gray-600 whitespace-pre-wrap">{{ estimate.customer_notes }}</p>
        </div>

        <!-- Actions (if estimate is pending/sent) -->
        <div v-if="canTakeAction" class="bg-white shadow rounded-lg p-6">
          <h2 class="text-lg font-semibold text-gray-900 mb-4">Your Response</h2>
          <p class="text-gray-600 mb-4">Please review the services above and approve or decline this estimate.</p>

          <div class="flex gap-4">
            <button
              @click="approveEstimate"
              :disabled="submitting"
              class="flex-1 bg-green-600 text-white py-3 px-6 rounded-lg font-medium hover:bg-green-700 disabled:opacity-50"
            >
              {{ submitting ? 'Processing...' : 'Approve Estimate' }}
            </button>
            <button
              @click="showDeclineModal = true"
              :disabled="submitting"
              class="flex-1 bg-red-600 text-white py-3 px-6 rounded-lg font-medium hover:bg-red-700 disabled:opacity-50"
            >
              Decline
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Decline Modal -->
    <div v-if="showDeclineModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 z-50">
      <div class="bg-white rounded-lg p-6 max-w-md w-full">
        <h3 class="text-lg font-semibold mb-4">Decline Estimate</h3>
        <textarea
          v-model="declineReason"
          placeholder="Please let us know why you're declining (optional)"
          class="w-full border rounded-lg p-3 h-32"
        ></textarea>
        <div class="flex gap-4 mt-4">
          <button
            @click="showDeclineModal = false"
            class="flex-1 border border-gray-300 py-2 px-4 rounded-lg hover:bg-gray-50"
          >
            Cancel
          </button>
          <button
            @click="declineEstimate"
            :disabled="submitting"
            class="flex-1 bg-red-600 text-white py-2 px-4 rounded-lg hover:bg-red-700 disabled:opacity-50"
          >
            {{ submitting ? 'Processing...' : 'Confirm Decline' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import api from '@/services/api'

const route = useRoute()

const loading = ref(true)
const error = ref(null)
const estimate = ref(null)
const customer = ref(null)
const vehicle = ref(null)
const jobs = ref([])
const token = ref('')
const shortCode = ref('')

const submitting = ref(false)
const showDeclineModal = ref(false)
const declineReason = ref('')

const vehicleDescription = computed(() => {
  if (!vehicle.value) return 'N/A'
  const parts = [vehicle.value.year, vehicle.value.make, vehicle.value.model].filter(Boolean)
  return parts.join(' ') || 'N/A'
})

const statusClass = computed(() => {
  const status = estimate.value?.status
  const classes = {
    pending: 'text-yellow-600',
    sent: 'text-blue-600',
    approved: 'text-green-600',
    rejected: 'text-red-600',
    expired: 'text-gray-600',
    converted: 'text-purple-600'
  }
  return classes[status] || 'text-gray-600'
})

const canTakeAction = computed(() => {
  const status = estimate.value?.status
  return status === 'pending' || status === 'sent'
})

function jobStatusClass(status) {
  const classes = {
    approved: 'bg-green-100 text-green-800',
    rejected: 'bg-red-100 text-red-800',
    pending: 'bg-yellow-100 text-yellow-800'
  }
  return classes[status] || 'bg-gray-100 text-gray-800'
}

function formatDate(dateStr) {
  if (!dateStr) return 'N/A'
  return new Date(dateStr).toLocaleDateString('en-US', {
    year: 'numeric',
    month: 'short',
    day: 'numeric'
  })
}

function formatNumber(num) {
  return Number(num || 0).toFixed(2)
}

async function loadEstimate() {
  loading.value = true
  error.value = null

  try {
    token.value = route.query.token || ''
    shortCode.value = route.query.code || ''

    let response
    if (token.value) {
      response = await api.get('/public/estimate', { params: { token: token.value } })
    } else if (shortCode.value) {
      response = await api.get(`/public/estimate/by-code/${shortCode.value}`)
    } else {
      throw new Error('No token or code provided')
    }

    estimate.value = response.data.estimate
    customer.value = response.data.customer
    vehicle.value = response.data.vehicle
    jobs.value = response.data.jobs || []
  } catch (err) {
    console.error('Failed to load estimate:', err)
    error.value = err.response?.data?.error || err.message || 'Failed to load estimate'
  } finally {
    loading.value = false
  }
}

async function approveEstimate() {
  if (!token.value || submitting.value) return

  submitting.value = true
  try {
    // Approve all jobs
    for (const job of jobs.value) {
      await api.post('/public/estimate/approve-job', {
        token: token.value,
        job_id: job.id
      })
    }
    // Reload to show updated status
    await loadEstimate()
  } catch (err) {
    console.error('Failed to approve:', err)
    error.value = err.response?.data?.error || 'Failed to approve estimate'
  } finally {
    submitting.value = false
  }
}

async function declineEstimate() {
  if (!token.value || submitting.value) return

  submitting.value = true
  try {
    // Reject all jobs
    for (const job of jobs.value) {
      await api.post('/public/estimate/reject-job', {
        token: token.value,
        job_id: job.id,
        rejection_reason: declineReason.value
      })
    }
    showDeclineModal.value = false
    // Reload to show updated status
    await loadEstimate()
  } catch (err) {
    console.error('Failed to decline:', err)
    error.value = err.response?.data?.error || 'Failed to decline estimate'
  } finally {
    submitting.value = false
  }
}

onMounted(() => {
  loadEstimate()
})
</script>
