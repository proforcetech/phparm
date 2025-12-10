<template>
  <div>
    <div class="mb-6 flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">{{ isEditing ? 'Edit Vehicle' : 'New Vehicle' }}</h1>
        <p class="mt-1 text-sm text-gray-500">{{ isEditing ? 'Update vehicle information' : 'Add a new customer vehicle' }}</p>
      </div>
      <Button variant="secondary" @click="goBack">Back to list</Button>
    </div>

    <Card class="max-w-5xl">
      <form class="space-y-6" @submit.prevent="save">
        <!-- Customer Selection -->
        <div>
          <label class="block text-sm font-medium text-gray-700">Customer *</label>
          <Input v-model.number="form.customer_id" type="number" required placeholder="Customer ID" />
          <p class="mt-1 text-xs text-gray-500">Enter the customer ID who owns this vehicle</p>
        </div>

        <!-- VIN Decoder Section -->
        <div class="border-t border-gray-200 pt-6">
          <h3 class="text-lg font-medium text-gray-900 mb-4">VIN Decoder</h3>
          <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
            <div class="md:col-span-2">
              <label class="block text-sm font-medium text-gray-700">VIN</label>
              <Input v-model="form.vin" placeholder="1HGBH41JXMN109186" maxlength="30" />
            </div>
            <div class="flex items-end">
              <Button type="button" variant="secondary" @click="decodeVinNumber" :loading="decoding" :disabled="!form.vin || form.vin.length < 17" class="w-full">
                <svg class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                </svg>
                Decode VIN
              </Button>
            </div>
          </div>
          <p v-if="vinError" class="mt-2 text-sm text-red-600">{{ vinError }}</p>
          <p v-if="vinSuccess" class="mt-2 text-sm text-green-600">{{ vinSuccess }}</p>
        </div>

        <!-- Vehicle Information -->
        <div class="border-t border-gray-200 pt-6">
          <h3 class="text-lg font-medium text-gray-900 mb-4">Vehicle Information</h3>
          <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
            <div>
              <label class="block text-sm font-medium text-gray-700">Year *</label>
              <Input v-model.number="form.year" type="number" required placeholder="2024" min="1900" max="2100" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700">Make *</label>
              <Input v-model="form.make" required placeholder="Ford" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700">Model *</label>
              <Input v-model="form.model" required placeholder="F-150" />
            </div>
          </div>

          <div class="grid grid-cols-1 gap-4 md:grid-cols-4 mt-4">
            <div>
              <label class="block text-sm font-medium text-gray-700">Engine *</label>
              <Input v-model="form.engine" required placeholder="5.0L V8" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700">Transmission *</label>
              <Input v-model="form.transmission" required placeholder="Automatic" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700">Drive *</label>
              <Input v-model="form.drive" required placeholder="4WD" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700">Trim</label>
              <Input v-model="form.trim" placeholder="Lariat" />
            </div>
          </div>
        </div>

        <!-- Additional Information -->
        <div class="border-t border-gray-200 pt-6">
          <h3 class="text-lg font-medium text-gray-900 mb-4">Additional Information</h3>
          <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <div>
              <label class="block text-sm font-medium text-gray-700">License Plate</label>
              <Input v-model="form.license_plate" placeholder="ABC-1234" maxlength="30" />
            </div>
          </div>

          <div class="mt-4">
            <label class="block text-sm font-medium text-gray-700">Notes</label>
            <textarea
              v-model="form.notes"
              rows="3"
              class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
              placeholder="Additional notes about this vehicle"
            ></textarea>
          </div>
        </div>

        <!-- Form Actions -->
        <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between border-t border-gray-200 pt-6">
          <div>
            <p class="text-sm text-gray-600">Fields marked with * are required.</p>
            <p v-if="error" class="text-sm text-red-600">{{ error }}</p>
            <p v-if="success" class="text-sm text-green-600">{{ success }}</p>
          </div>
          <div class="flex gap-3">
            <Button type="button" variant="secondary" @click="goBack">Cancel</Button>
            <Button type="submit" :loading="saving">{{ isEditing ? 'Update Vehicle' : 'Create Vehicle' }}</Button>
          </div>
        </div>
      </form>
    </Card>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Button from '@/components/ui/Button.vue'
import Card from '@/components/ui/Card.vue'
import Input from '@/components/ui/Input.vue'
import { getVehicle, createVehicle, decodeVin } from '@/services/vehicle.service'
import { useToast } from '@/stores/toast'

const router = useRouter()
const route = useRoute()
const toast = useToast()

const saving = ref(false)
const decoding = ref(false)
const success = ref('')
const error = ref('')
const vinError = ref('')
const vinSuccess = ref('')

const form = reactive({
  customer_id: null,
  year: null,
  make: '',
  model: '',
  engine: '',
  transmission: '',
  drive: '',
  trim: '',
  vin: '',
  license_plate: '',
  notes: '',
})

const isEditing = computed(() => !!route.params.id)

onMounted(async () => {
  if (isEditing.value) {
    await loadVehicle()
  }
})

async function loadVehicle() {
  try {
    const vehicle = await getVehicle(route.params.id)
    Object.assign(form, {
      customer_id: vehicle.customer_id,
      year: vehicle.year,
      make: vehicle.make,
      model: vehicle.model,
      engine: vehicle.engine || '',
      transmission: vehicle.transmission || '',
      drive: vehicle.drive || '',
      trim: vehicle.trim || '',
      vin: vehicle.vin || '',
      license_plate: vehicle.license_plate || '',
      notes: vehicle.notes || '',
    })
  } catch (err) {
    error.value = 'Failed to load vehicle'
    console.error(err)
  }
}

async function decodeVinNumber() {
  if (!form.vin || form.vin.length < 17) {
    vinError.value = 'VIN must be at least 17 characters'
    return
  }

  decoding.value = true
  vinError.value = ''
  vinSuccess.value = ''

  try {
    const decoded = await decodeVin(form.vin)

    if (decoded.year) form.year = decoded.year
    if (decoded.make) form.make = decoded.make
    if (decoded.model) form.model = decoded.model
    if (decoded.engine) form.engine = decoded.engine
    if (decoded.transmission) form.transmission = decoded.transmission
    if (decoded.drive) form.drive = decoded.drive
    if (decoded.trim) form.trim = decoded.trim

    vinSuccess.value = 'VIN decoded successfully!'
    toast.success('VIN decoded successfully!')
  } catch (err) {
    vinError.value = err.response?.data?.message || 'Failed to decode VIN'
    toast.error('Failed to decode VIN')
    console.error(err)
  } finally {
    decoding.value = false
  }
}

async function save() {
  saving.value = true
  error.value = ''
  success.value = ''

  try {
    if (isEditing.value) {
      // TODO: Add update functionality when API endpoint is available
      error.value = 'Update functionality not yet implemented'
      return
    } else {
      await createVehicle(form)
      success.value = 'Vehicle created successfully!'
      toast.success('Vehicle created successfully!')

      setTimeout(() => {
        router.push('/vehicles')
      }, 1500)
    }
  } catch (err) {
    error.value = err.response?.data?.message || 'Failed to save vehicle'
    toast.error('Failed to save vehicle')
    console.error(err)
  } finally {
    saving.value = false
  }
}

function goBack() {
  router.push('/vehicles')
}
</script>
