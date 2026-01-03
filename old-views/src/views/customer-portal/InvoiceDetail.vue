<template>
  <div>
    <div class="mb-6">
      <div class="flex items-center gap-4">
        <Button variant="ghost" @click="$router.push('/portal/invoices')">
          <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
          </svg>
        </Button>
        <div>
          <h1 class="text-2xl font-bold text-gray-900">Invoice Details</h1>
          <p class="mt-1 text-sm text-gray-500">View invoice and payment options</p>
        </div>
      </div>
    </div>

    <Card>
      <div v-if="loading" class="py-10 flex justify-center">
        <Loading label="Loading invoice..." />
      </div>
      <div v-else-if="!invoice" class="py-10 text-center text-sm text-red-600">Unable to load invoice.</div>
      <div v-else class="space-y-6">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between">
          <div>
            <p class="text-sm text-gray-500">Invoice #</p>
            <p class="text-xl font-semibold text-gray-900">{{ invoice.number }}</p>
          </div>
          <div class="flex items-center gap-3">
            <span class="px-3 py-1 rounded-full text-sm font-medium" :class="statusClasses(invoice.status)">
              {{ invoice.status }}
            </span>
            <Button variant="ghost" @click="downloadPdf">Download PDF</Button>
            <Button variant="primary" :disabled="paying" @click="startCheckout">
              <span v-if="paying">Redirecting...</span>
              <span v-else>Pay Now</span>
            </Button>
          </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div>
            <p class="text-sm text-gray-500">Issued</p>
            <p class="text-base text-gray-900">{{ formatDate(invoice.issue_date) }}</p>
          </div>
          <div>
            <p class="text-sm text-gray-500">Due</p>
            <p class="text-base text-gray-900">{{ formatDate(invoice.due_date) }}</p>
          </div>
          <div>
            <p class="text-sm text-gray-500">Balance Due</p>
            <p class="text-base text-gray-900">${{ Number(invoice.balance_due ?? invoice.total ?? 0).toFixed(2) }}</p>
          </div>
        </div>
      </div>
    </Card>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'

import Card from '@/components/ui/Card.vue'
import Button from '@/components/ui/Button.vue'
import Loading from '@/components/ui/Loading.vue'
import invoiceService from '@/services/invoice.service'
import { portalService } from '@/services/portal.service'

const route = useRoute()
const invoiceId = Number(route.params.id)

const invoice = ref(null)
const loading = ref(false)
const paying = ref(false)

const formatDate = (date) => {
  if (!date) return '—'
  return new Date(date).toLocaleDateString()
}

const statusClasses = (status) => {
  switch (status) {
    case 'paid':
      return 'bg-green-100 text-green-800'
    case 'pending':
      return 'bg-yellow-100 text-yellow-800'
    case 'sent':
      return 'bg-blue-100 text-blue-800'
    default:
      return 'bg-gray-100 text-gray-800'
  }
}

const loadInvoice = async () => {
  loading.value = true
  try {
    invoice.value = await portalService.getInvoiceById(invoiceId)
  } finally {
    loading.value = false
  }
}

const downloadPdf = () => {
  window.open(`/api/invoices/${invoiceId}/pdf`, '_blank')
}

const startCheckout = async () => {
  paying.value = true
  try {
    const response = await invoiceService.createCheckout(invoiceId, 'stripe')
    if (response.checkout_url) {
      window.location.href = response.checkout_url
    }
  } finally {
    paying.value = false
  }
}

onMounted(() => {
  loadInvoice()
})
</script>
