<template>
  <div>
    <div class="mb-6">
      <h1 class="text-2xl font-bold text-gray-900">My Invoices</h1>
      <p class="mt-1 text-sm text-gray-500">View and pay your invoices</p>
    </div>

    <Card>
      <div v-if="loading" class="py-10 flex justify-center">
        <Loading label="Loading invoices..." />
      </div>
      <div v-else-if="invoices.length === 0" class="text-center py-12">
        <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
        </svg>
        <h3 class="mt-2 text-sm font-medium text-gray-900">No Invoices</h3>
        <p class="mt-1 text-sm text-gray-500">You don't have any invoices yet.</p>
      </div>
      <div v-else class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
          <thead class="bg-gray-50">
            <tr>
              <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Invoice #</th>
              <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
              <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Issued</th>
              <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total</th>
              <th class="px-4 py-3"></th>
            </tr>
          </thead>
          <tbody class="bg-white divide-y divide-gray-200">
            <tr v-for="invoice in invoices" :key="invoice.id">
              <td class="px-4 py-3 text-sm text-gray-900">{{ invoice.number }}</td>
              <td class="px-4 py-3 text-sm">
                <span
                  class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full"
                  :class="statusClasses(invoice.status)"
                >
                  {{ invoice.status }}
                </span>
              </td>
              <td class="px-4 py-3 text-sm text-gray-500">{{ formatDate(invoice.issue_date) }}</td>
              <td class="px-4 py-3 text-sm text-gray-900">${{ Number(invoice.total || 0).toFixed(2) }}</td>
              <td class="px-4 py-3 text-right text-sm">
                <Button variant="ghost" size="sm" @click="$router.push(`/portal/invoices/${invoice.id}`)">View</Button>
              </td>
            </tr>
          </tbody>
        </table>

        <div class="flex justify-end items-center gap-3 px-4 py-3 border-t">
          <Button variant="ghost" size="sm" :disabled="page === 1" @click="changePage(page - 1)">Previous</Button>
          <span class="text-sm text-gray-600">Page {{ page }}</span>
          <Button variant="ghost" size="sm" :disabled="!hasNext" @click="changePage(page + 1)">Next</Button>
        </div>
      </div>
    </Card>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'

import Button from '@/components/ui/Button.vue'
import Card from '@/components/ui/Card.vue'
import Loading from '@/components/ui/Loading.vue'
import { portalService } from '@/services/portal.service'

const invoices = ref([])
const loading = ref(false)
const page = ref(1)
const perPage = 10

const hasNext = computed(() => invoices.value.length === perPage)

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

const formatDate = (date) => {
  if (!date) return '—'
  return new Date(date).toLocaleDateString()
}

const loadInvoices = async () => {
  loading.value = true
  try {
    const response = await portalService.getInvoices({ limit: perPage, offset: (page.value - 1) * perPage })
    invoices.value = response || []
  } finally {
    loading.value = false
  }
}

const changePage = async (nextPage) => {
  page.value = Math.max(1, nextPage)
  await loadInvoices()
}

onMounted(() => {
  loadInvoices()
})
</script>
