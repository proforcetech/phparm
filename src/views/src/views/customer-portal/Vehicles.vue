<template>
  <div>
    <div class="mb-6">
      <h1 class="text-2xl font-bold text-gray-900">My Vehicles</h1>
      <p class="mt-1 text-sm text-gray-500">Manage vehicles linked to your account</p>
    </div>

    <div class="flex justify-end mb-4">
      <Button variant="primary" @click="showModal = true">
        <svg class="h-4 w-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
        </svg>
        Add Vehicle
      </Button>
    </div>

    <Card>
      <div v-if="loading" class="py-10 flex justify-center">
        <Loading label="Loading vehicles..." />
      </div>
      <div v-else-if="vehicles.length === 0" class="text-center py-12">
        <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
        </svg>
        <h3 class="mt-2 text-sm font-medium text-gray-900">No Vehicles</h3>
        <p class="mt-1 text-sm text-gray-500">No vehicles are associated with your account.</p>
      </div>
      <div v-else class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
          <thead class="bg-gray-50">
            <tr>
              <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">VIN</th>
              <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Plate</th>
              <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Notes</th>
            </tr>
          </thead>
          <tbody class="bg-white divide-y divide-gray-200">
            <tr v-for="vehicle in vehicles" :key="vehicle.id">
              <td class="px-4 py-3 text-sm text-gray-900">{{ vehicle.vin || '—' }}</td>
              <td class="px-4 py-3 text-sm text-gray-900">{{ vehicle.plate || '—' }}</td>
              <td class="px-4 py-3 text-sm text-gray-500">{{ vehicle.notes || '—' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </Card>

    <Modal :open="showModal" title="Add Vehicle" @close="resetModal">
      <form class="space-y-4" @submit.prevent="submitVehicle">
        <Input v-model="form.vin" label="VIN" placeholder="1HGCM82633A123456" />
        <Input v-model="form.plate" label="License Plate" placeholder="ABC123" />
        <Textarea v-model="form.notes" label="Notes" placeholder="Color, trim, or other details" />

        <div class="flex justify-end gap-2 pt-2">
          <Button variant="ghost" type="button" @click="resetModal">Cancel</Button>
          <Button variant="primary" type="submit" :disabled="submitting">
            <span v-if="submitting">Saving...</span>
            <span v-else>Save Vehicle</span>
          </Button>
        </div>
      </form>
    </Modal>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'

import Card from '@/components/ui/Card.vue'
import Button from '@/components/ui/Button.vue'
import Input from '@/components/ui/Input.vue'
import Loading from '@/components/ui/Loading.vue'
import Modal from '@/components/ui/Modal.vue'
import Textarea from '@/components/ui/Textarea.vue'
import { portalService } from '@/services/portal.service'

const vehicles = ref([])
const loading = ref(false)
const submitting = ref(false)
const showModal = ref(false)
const form = reactive({ vin: '', plate: '', notes: '' })

const loadVehicles = async () => {
  loading.value = true
  try {
    const response = await portalService.getVehicles()
    vehicles.value = response.data || []
  } finally {
    loading.value = false
  }
}

const resetModal = () => {
  showModal.value = false
  form.vin = ''
  form.plate = ''
  form.notes = ''
}

const submitVehicle = async () => {
  submitting.value = true
  try {
    await portalService.addVehicle({
      vin: form.vin,
      plate: form.plate,
      notes: form.notes,
    })
    await loadVehicles()
    resetModal()
  } finally {
    submitting.value = false
  }
}

onMounted(() => {
  loadVehicles()
})
</script>
