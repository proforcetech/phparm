<template>
  <div>
    <div class="mb-6 flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Customer Vehicles</h1>
        <p class="mt-1 text-sm text-gray-500">Manage vehicles in customer garages</p>
      </div>
      <div class="flex gap-3">
        <Button variant="secondary" @click="$router.push('/cp/vehicle-master')">
          <svg class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4" />
          </svg>
          Vehicle Database
        </Button>
        <Button @click="$router.push('/cp/vehicles/create')">
          <svg class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
          </svg>
          Add to Customer Garage
        </Button>
      </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
      <Card class="lg:col-span-2">
        <div class="flex flex-col gap-4">
          <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div>
              <label class="block text-sm font-medium text-gray-700">Year</label>
              <Input v-model="filters.year" type="number" placeholder="2024" @input="loadVehicles" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700">Make</label>
              <Input v-model="filters.make" placeholder="Ford" @input="loadVehicles" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700">Model</label>
              <Input v-model="filters.model" placeholder="F-150" @input="loadVehicles" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700">Search term</label>
              <Input v-model="filters.term" placeholder="Engine, transmission..." @input="loadVehicles" />
            </div>
          </div>

          <Table :columns="columns" :data="vehicles" :loading="loading" hoverable @row-click="goToDetail">
            <template #cell(year)="{ value }">
              <span class="font-semibold">{{ value }}</span>
            </template>
            <template #cell(engine)="{ value }">
              <span class="text-sm text-gray-700">{{ value || 'N/A' }}</span>
            </template>
            <template #cell(transmission)="{ value }">
              <Badge variant="secondary">{{ value || 'Unknown' }}</Badge>
            </template>
            <template #cell(drive)="{ value }">
              <span class="text-sm">{{ value || '—' }}</span>
            </template>
            <template #actions="{ row }">
              <div class="flex gap-2">
                <Button size="sm" variant="secondary" @click.stop="goToDetail(row)">View</Button>
              </div>
            </template>
            <template #empty>
              <p class="text-sm text-gray-500">No vehicles found for the current filters.</p>
            </template>
          </Table>
        </div>
      </Card>

      <Card>
        <h3 class="text-lg font-semibold text-gray-900 mb-3">VIN decoder</h3>
        <div class="space-y-3">
          <div>
            <label class="block text-sm font-medium text-gray-700">VIN</label>
            <Input v-model="vin" maxlength="17" placeholder="Enter 17-character VIN" />
          </div>
          <div class="flex gap-2">
            <Button class="flex-1" :loading="vinLoading" @click="decode">Decode</Button>
            <Button class="flex-1" variant="secondary" :loading="vinLoading" @click="validate">Validate</Button>
          </div>
          <div v-if="vinResult" class="rounded-md bg-gray-50 p-3 text-sm text-gray-800">
            <p class="font-semibold">{{ vinResult.message || 'VIN details' }}</p>
            <pre class="mt-2 whitespace-pre-wrap text-xs text-gray-700">{{ pretty(vinResult.decoded || vinResult) }}</pre>
          </div>
        </div>
      </Card>
    </div>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import Badge from '@/components/ui/Badge.vue'
import Button from '@/components/ui/Button.vue'
import Card from '@/components/ui/Card.vue'
import Input from '@/components/ui/Input.vue'
import Table from '@/components/ui/Table.vue'
import { decodeVin, listVehicles, validateVin } from '@/services/vehicle.service'

const loading = ref(false)
const vehicles = ref([])
const filters = reactive({ year: '', make: '', model: '', term: '' })
const vin = ref('')
const vinResult = ref(null)
const vinLoading = ref(false)
const router = useRouter()

const columns = [
  { key: 'customer_id', label: 'Customer' },
  { key: 'year', label: 'Year' },
  { key: 'make', label: 'Make' },
  { key: 'model', label: 'Model' },
  { key: 'vin', label: 'VIN' },
  { key: 'license_plate', label: 'License Plate' },
  { key: 'mileage_in', label: 'Mileage In' },
  { key: 'mileage_out', label: 'Mileage Out' }
]

const loadVehicles = async () => {
  loading.value = true
  const params = {}
  Object.entries(filters).forEach(([key, value]) => {
    if (value) params[key] = value
  })
  try {
    vehicles.value = await listVehicles(params)
  } finally {
    loading.value = false
  }
}

const goToDetail = (row) => {
  if (!row?.id) return
  router.push(`/vehicles/${row.id}`)
}

const decode = async () => {
  vinLoading.value = true
  try {
    vinResult.value = await decodeVin(vin.value)
  } finally {
    vinLoading.value = false
  }
}

const validate = async () => {
  vinLoading.value = true
  try {
    vinResult.value = await validateVin(vin.value)
  } finally {
    vinLoading.value = false
  }
}

const pretty = (value) => JSON.stringify(value, null, 2)

onMounted(loadVehicles)
</script>
