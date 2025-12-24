<template>
  <div class="space-y-6">
    <div>
      <h1 class="text-2xl font-bold text-gray-900">Warranty Claims</h1>
      <p class="mt-1 text-sm text-gray-500">Submit and track your warranty claims.</p>
    </div>

    <Card>
      <h2 class="text-lg font-semibold text-gray-900 mb-4">Submit a new claim</h2>
      <form class="grid grid-cols-1 md:grid-cols-2 gap-4" @submit.prevent="submitClaim">
        <Input v-model="form.subject" label="Subject" placeholder="Brake job warranty issue" required />
        <Input v-model="form.invoice_id" label="Related Invoice (optional)" placeholder="Invoice ID" type="number" min="1" />
        <Input v-model="form.vehicle_id" label="Vehicle (optional)" placeholder="Vehicle ID" type="number" min="1" />
        <div class="md:col-span-2">
          <Textarea v-model="form.description" label="Description" placeholder="Describe the issue you're seeing" required />
        </div>
        <div class="md:col-span-2 flex justify-end gap-2">
          <Button variant="primary" type="submit" :disabled="submitting">
            <span v-if="submitting">Submitting...</span>
            <span v-else>Submit Claim</span>
          </Button>
        </div>
      </form>
    </Card>

    <Card>
      <div class="flex items-center justify-between mb-4">
        <h2 class="text-lg font-semibold text-gray-900">My claims</h2>
        <select v-model="filters.status" class="border rounded-md px-3 py-2 text-sm text-gray-700" @change="loadClaims">
          <option value="">All Statuses</option>
          <option value="open">Open</option>
          <option value="in_review">In Review</option>
          <option value="resolved">Resolved</option>
          <option value="rejected">Rejected</option>
        </select>
      </div>

      <div v-if="loading" class="py-10 flex justify-center">
        <Loading label="Loading claims..." />
      </div>
      <div v-else-if="claims.length === 0" class="text-center py-12">
        <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
        </svg>
        <h3 class="mt-2 text-sm font-medium text-gray-900">No warranty claims</h3>
        <p class="mt-1 text-sm text-gray-500">Submit a claim using the form above.</p>
      </div>
      <div v-else class="divide-y divide-gray-200">
        <div
          v-for="claim in claims"
          :key="claim.id"
          class="py-4 flex items-start justify-between cursor-pointer hover:bg-gray-50 px-2 rounded"
          @click="$router.push(`/portal/warranty-claims/${claim.id}`)"
        >
          <div>
            <p class="text-sm font-medium text-gray-900">{{ claim.subject }}</p>
            <p class="text-xs text-gray-500 mt-1">Updated {{ formatDate(claim.updated_at) }}</p>
            <p class="text-xs text-gray-500">Invoice: {{ claim.invoice_id || '—' }} · Vehicle: {{ claim.vehicle_id || '—' }}</p>
          </div>
          <span class="px-3 py-1 text-xs rounded-full" :class="statusClass(claim.status)">{{ claim.status }}</span>
        </div>
      </div>
    </Card>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'

import Button from '@/components/ui/Button.vue'
import Card from '@/components/ui/Card.vue'
import Input from '@/components/ui/Input.vue'
import Loading from '@/components/ui/Loading.vue'
import Textarea from '@/components/ui/Textarea.vue'
import { warrantyService } from '@/services/warranty.service'

const claims = ref([])
const loading = ref(false)
const submitting = ref(false)
const filters = reactive({ status: '' })
const form = reactive({ subject: '', description: '', invoice_id: '', vehicle_id: '' })

const loadClaims = async () => {
  loading.value = true
  try {
    const response = await warrantyService.listCustomerClaims({ status: filters.status || undefined })
    claims.value = response
  } finally {
    loading.value = false
  }
}

const resetForm = () => {
  form.subject = ''
  form.description = ''
  form.invoice_id = ''
  form.vehicle_id = ''
}

const submitClaim = async () => {
  submitting.value = true
  try {
    await warrantyService.submitClaim({
      subject: form.subject,
      description: form.description,
      invoice_id: form.invoice_id || null,
      vehicle_id: form.vehicle_id || null,
    })
    resetForm()
    await loadClaims()
  } finally {
    submitting.value = false
  }
}

const formatDate = (value) => {
  if (!value) return '—'
  return new Date(value).toLocaleString()
}

const statusClass = (status) => {
  switch (status) {
    case 'resolved':
      return 'bg-green-100 text-green-800'
    case 'rejected':
      return 'bg-red-100 text-red-800'
    case 'in_review':
      return 'bg-yellow-100 text-yellow-800'
    default:
      return 'bg-blue-100 text-blue-800'
  }
}

onMounted(() => {
  loadClaims()
})
</script>
