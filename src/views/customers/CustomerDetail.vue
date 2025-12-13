<template>
  <div>
    <div class="mb-6">
      <div class="flex items-center gap-4">
        <Button variant="ghost" @click="$router.push('/cp/customers')">
          <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
          </svg>
        </Button>
        <div>
          <h1 class="text-2xl font-bold text-gray-900">Customer Details</h1>
          <p class="mt-1 text-sm text-gray-500">View customer information</p>
        </div>
      </div>
    </div>

    <Card>
      <div v-if="loading" class="py-6 text-center text-sm text-gray-500">Loading customer...</div>
      <div v-else-if="customer" class="space-y-6">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <p class="text-xs text-gray-500">Name</p>
            <p class="text-lg font-semibold text-gray-900">{{ customer.name }}</p>
          </div>
          <div class="flex items-center gap-2">
            <Badge :variant="customer.is_commercial ? 'primary' : 'secondary'">
              {{ customer.is_commercial ? 'Commercial' : 'Consumer' }}
            </Badge>
            <Badge :variant="customer.tax_exempt ? 'success' : 'secondary'">
              {{ customer.tax_exempt ? 'Tax exempt' : 'Taxed' }}
            </Badge>
          </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div>
            <p class="text-xs text-gray-500">Email</p>
            <p class="text-sm text-gray-900">{{ customer.email }}</p>
          </div>
          <div>
            <p class="text-xs text-gray-500">Phone</p>
            <p class="text-sm text-gray-900">{{ customer.phone }}</p>
          </div>
          <div>
            <p class="text-xs text-gray-500">External reference</p>
            <p class="text-sm text-gray-900">{{ customer.external_reference || '—' }}</p>
          </div>
        </div>

        <div class="rounded-md bg-gray-50 p-4">
          <p class="text-sm font-semibold text-gray-800">Notes</p>
          <p class="text-sm text-gray-700 mt-1">{{ customer.notes || 'No notes on file.' }}</p>
        </div>

        <div class="rounded-md bg-gray-50 p-4">
          <p class="text-sm font-semibold text-gray-800">Raw data</p>
          <pre class="mt-2 text-xs text-gray-700 whitespace-pre-wrap">{{ pretty(customer) }}</pre>
        </div>
      </div>
      <div v-else class="py-6 text-center text-sm text-gray-500">Customer not found.</div>
    </Card>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Badge from '@/components/ui/Badge.vue'
import Button from '@/components/ui/Button.vue'
import Card from '@/components/ui/Card.vue'
import { getCustomer } from '@/services/customer.service'

const route = useRoute()
const router = useRouter()
const customer = ref(null)
const loading = ref(true)

const pretty = (value) => JSON.stringify(value, null, 2)

const loadCustomer = async () => {
  loading.value = true
  try {
    customer.value = await getCustomer(route.params.id)
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  if (!route.params.id || route.params.id === 'create') {
    router.push('/cp/customers')
    return
  }
  loadCustomer()
})
</script>
