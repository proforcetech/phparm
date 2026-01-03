<template>
  <div>
    <div class="mb-6">
      <div class="flex items-center justify-between">
        <div class="flex items-center gap-4">
          <Button variant="ghost" @click="$router.push('/cp/vehicles')">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
            </svg>
          </Button>
          <div>
            <h1 class="text-2xl font-bold text-gray-900">Vehicle Details</h1>
            <p class="mt-1 text-sm text-gray-500">View vehicle information</p>
          </div>
        </div>
        <Button v-if="vehicle" @click="$router.push(`/vehicles/${route.params.id}/edit`)">
          <svg class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
          </svg>
          Edit Vehicle
        </Button>
      </div>
    </div>

    <Card>
      <div v-if="loading" class="py-6 text-center text-sm text-gray-500">Loading vehicle...</div>
      <div v-else-if="vehicle" class="space-y-4">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div>
            <p class="text-xs text-gray-500">Year</p>
            <p class="text-lg font-semibold text-gray-900">{{ vehicle.year }}</p>
          </div>
          <div>
            <p class="text-xs text-gray-500">Make</p>
            <p class="text-lg font-semibold text-gray-900">{{ vehicle.make }}</p>
          </div>
          <div>
            <p class="text-xs text-gray-500">Model</p>
            <p class="text-lg font-semibold text-gray-900">{{ vehicle.model }}</p>
          </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
          <div>
            <p class="text-xs text-gray-500">Engine</p>
            <p class="text-sm text-gray-900">{{ vehicle.engine || 'Unknown' }}</p>
          </div>
          <div>
            <p class="text-xs text-gray-500">Transmission</p>
            <p class="text-sm text-gray-900">{{ vehicle.transmission || 'Unknown' }}</p>
          </div>
          <div>
            <p class="text-xs text-gray-500">Drive</p>
            <p class="text-sm text-gray-900">{{ vehicle.drive || 'Unknown' }}</p>
          </div>
          <div>
            <p class="text-xs text-gray-500">Trim</p>
            <p class="text-sm text-gray-900">{{ vehicle.trim || 'N/A' }}</p>
          </div>
        </div>

        <div class="rounded-md bg-gray-50 p-4">
          <p class="text-sm font-semibold text-gray-800">Raw payload</p>
          <pre class="mt-2 text-xs text-gray-700 whitespace-pre-wrap">{{ pretty(vehicle) }}</pre>
        </div>
      </div>
      <div v-else class="py-6 text-center text-sm text-gray-500">Vehicle not found.</div>
    </Card>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Button from '@/components/ui/Button.vue'
import Card from '@/components/ui/Card.vue'
import { getVehicle } from '@/services/vehicle.service'

const route = useRoute()
const router = useRouter()
const loading = ref(true)
const vehicle = ref(null)

const pretty = (value) => JSON.stringify(value, null, 2)

const loadVehicle = async () => {
  loading.value = true
  try {
    vehicle.value = await getVehicle(route.params.id)
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  if (!route.params.id) {
    router.push('/cp/vehicles')
    return
  }
  loadVehicle()
})
</script>
