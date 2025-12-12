<template>
  <div>
    <div class="mb-6 flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Customers</h1>
        <p class="mt-1 text-sm text-gray-500">Search and view customer profiles</p>
      </div>
      <Button @click="$router.push('/cp/customers/create')">
        <svg class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
        </svg>
        New Customer
      </Button>
    </div>

    <Card>
      <div class="flex flex-col gap-4">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
          <div class="md:col-span-2">
            <label class="block text-sm font-medium text-gray-700">Search</label>
            <Input v-model="filters.query" placeholder="Name, email, phone" @input="loadCustomers" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700">Commercial</label>
            <Select v-model="filters.commercial" :options="booleanOptions" placeholder="Any" @change="loadCustomers" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700">Tax Exempt</label>
            <Select v-model="filters.tax_exempt" :options="booleanOptions" placeholder="Any" @change="loadCustomers" />
          </div>
        </div>

        <Table :columns="columns" :data="customers" :loading="loading" hoverable @row-click="openDetail">
          <template #cell(is_commercial)="{ value }">
            <Badge :variant="value ? 'primary' : 'secondary'">{{ value ? 'Commercial' : 'Consumer' }}</Badge>
          </template>
          <template #cell(tax_exempt)="{ value }">
            <Badge :variant="value ? 'success' : 'secondary'">{{ value ? 'Tax exempt' : 'Taxed' }}</Badge>
          </template>
          <template #cell(email)="{ value }">
            <span class="text-sm text-gray-800">{{ value }}</span>
          </template>
          <template #actions="{ row }">
            <Button size="sm" variant="secondary" @click.stop="openDetail(row)">Open</Button>
          </template>
          <template #empty>
            <p class="text-sm text-gray-500">No customers match the selected filters.</p>
          </template>
        </Table>
      </div>
    </Card>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import Badge from '@/components/ui/Badge.vue'
import Button from '@/components/ui/Button.vue'
import Card from '@/components/ui/Card.vue'
import Input from '@/components/ui/Input.vue'
import Select from '@/components/ui/Select.vue'
import Table from '@/components/ui/Table.vue'
import { listCustomers } from '@/services/customer.service'

const router = useRouter()
const customers = ref([])
const loading = ref(false)
const filters = reactive({ query: '', commercial: '', tax_exempt: '' })

const booleanOptions = [
  { label: 'Yes', value: true },
  { label: 'No', value: false }
]

const columns = [
  { key: 'id', label: 'ID' },
  { key: 'name', label: 'Name' },
  { key: 'email', label: 'Email' },
  { key: 'phone', label: 'Phone' },
  { key: 'is_commercial', label: 'Type' },
  { key: 'tax_exempt', label: 'Tax' }
]

const loadCustomers = async () => {
  loading.value = true
  const params = {}
  Object.entries(filters).forEach(([key, value]) => {
    if (value !== '' && value !== null) params[key] = value
  })
  try {
    customers.value = await listCustomers(params)
  } finally {
    loading.value = false
  }
}

const openDetail = (row) => {
  if (!row?.id) return
  router.push(`/customers/${row.id}`)
}

onMounted(loadCustomers)
</script>
