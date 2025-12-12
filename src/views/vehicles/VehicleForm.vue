<template>
  <div>
    <div class="mb-6 flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">{{ isEditing ? 'Edit Customer Vehicle' : 'Add Vehicle to Customer Garage' }}</h1>
        <p class="mt-1 text-sm text-gray-500">{{ isEditing ? 'Update vehicle information' : 'Add a vehicle to a customer\'s garage' }}</p>
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

        <!-- VIN Section -->
        <div class="border-t border-gray-200 pt-6">
          <h3 class="text-lg font-medium text-gray-900 mb-4">VIN Information</h3>
          <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
            <div class="md:col-span-2">
              <label class="block text-sm font-medium text-gray-700">VIN</label>
              <Input v-model="form.vin" placeholder="1HGBH41JXMN109186" maxlength="30" />
              <p class="mt-1 text-xs text-gray-500">Optional - Enter vehicle identification number</p>
            </div>
            <div class="flex items-end gap-2">
              <Button
                type="button"
                variant="secondary"
                @click="decodeVinNumber"
                :loading="decoding"
                :disabled="!form.vin || form.vin.length < 17"
                class="flex-1"
              >
                <svg class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                </svg>
                Decode
              </Button>
            </div>
          </div>
          <p v-if="vinError" class="mt-2 text-sm text-red-600">{{ vinError }}</p>
          <p v-if="vinSuccess" class="mt-2 text-sm text-green-600">{{ vinSuccess }}</p>
        </div>

        <!-- Vehicle Selection from Database -->
        <div class="border-t border-gray-200 pt-6">
          <h3 class="text-lg font-medium text-gray-900 mb-4">Vehicle Specifications</h3>
          <p class="text-sm text-gray-600 mb-4">Select vehicle from database or VIN decoder will populate these fields</p>

          <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
            <div>
              <label class="block text-sm font-medium text-gray-700">Year *</label>
              <select
                v-model.number="form.year"
                required
                @change="onYearChange"
                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
              >
                <option value="">Select Year</option>
                <option v-for="year in years" :key="year" :value="year">{{ year }}</option>
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700">Make *</label>
              <select
                v-model="form.make"
                required
                @change="onMakeChange"
                :disabled="!form.year"
                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
              >
                <option value="">Select Make</option>
                <option v-for="make in makes" :key="make" :value="make">{{ make }}</option>
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700">Model *</label>
              <select
                v-model="form.model"
                required
                @change="onModelChange"
                :disabled="!form.make"
                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
              >
                <option value="">Select Model</option>
                <option v-for="model in models" :key="model" :value="model">{{ model }}</option>
              </select>
            </div>
          </div>

          <div class="grid grid-cols-1 gap-4 md:grid-cols-4 mt-4">
            <div>
              <label class="block text-sm font-medium text-gray-700">Engine *</label>
              <select
                v-model="form.engine"
                required
                @change="onEngineChange"
                :disabled="!form.model"
                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
              >
                <option value="">Select Engine</option>
                <option v-for="engine in engines" :key="engine" :value="engine">{{ engine }}</option>
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700">Transmission *</label>
              <select
                v-model="form.transmission"
                required
                @change="onTransmissionChange"
                :disabled="!form.engine"
                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
              >
                <option value="">Select Transmission</option>
                <option v-for="transmission in transmissions" :key="transmission" :value="transmission">{{ transmission }}</option>
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700">Drive *</label>
              <select
                v-model="form.drive"
                required
                @change="onDriveChange"
                :disabled="!form.transmission"
                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
              >
                <option value="">Select Drive</option>
                <option v-for="drive in drives" :key="drive" :value="drive">{{ drive }}</option>
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700">Trim</label>
              <select
                v-model="form.trim"
                :disabled="!form.drive"
                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
              >
                <option value="">Select Trim (Optional)</option>
                <option v-for="trim in trims" :key="trim" :value="trim">{{ trim }}</option>
              </select>
            </div>
          </div>
        </div>

        <!-- Additional Information -->
        <div class="border-t border-gray-200 pt-6">
          <h3 class="text-lg font-medium text-gray-900 mb-4">Additional Information</h3>
          <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
            <div>
              <label class="block text-sm font-medium text-gray-700">License Plate</label>
              <Input v-model="form.license_plate" placeholder="ABC-1234" maxlength="30" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700">Mileage In</label>
              <Input v-model.number="form.mileage_in" type="number" placeholder="50000" min="0" />
              <p class="mt-1 text-xs text-gray-500">Mileage when vehicle arrives</p>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700">Mileage Out</label>
              <Input v-model.number="form.mileage_out" type="number" placeholder="50100" min="0" />
              <p class="mt-1 text-xs text-gray-500">Mileage when vehicle leaves</p>
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
            <Button type="submit" :loading="saving">{{ isEditing ? 'Update Vehicle' : 'Add to Garage' }}</Button>
          </div>
        </div>
      </form>
    </Card>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref, computed, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Button from '@/components/ui/Button.vue'
import Card from '@/components/ui/Card.vue'
import Input from '@/components/ui/Input.vue'
import {
  getYears,
  getMakes,
  getModels,
  getEngines,
  getTransmissions,
  getDrives,
  getTrims,
  decodeVin
} from '@/services/vehicle-master.service'
import { createCustomerVehicle, updateCustomerVehicle, getCustomerVehicle } from '@/services/customer-vehicle.service'
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

const years = ref([])
const makes = ref([])
const models = ref([])
const engines = ref([])
const transmissions = ref([])
const drives = ref([])
const trims = ref([])

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
  mileage_in: null,
  mileage_out: null,
  notes: '',
})

const isEditing = computed(() => !!route.params.id)

onMounted(async () => {
  await loadYears()
  if (isEditing.value) {
    await loadVehicle()
  }
})

async function loadYears() {
  try {
    years.value = await getYears()
  } catch (err) {
    console.error('Failed to load years:', err)
  }
}

async function onYearChange() {
  form.make = ''
  form.model = ''
  form.engine = ''
  form.transmission = ''
  form.drive = ''
  form.trim = ''
  makes.value = []
  models.value = []
  engines.value = []
  transmissions.value = []
  drives.value = []
  trims.value = []

  if (form.year) {
    try {
      makes.value = await getMakes(form.year)
    } catch (err) {
      console.error('Failed to load makes:', err)
    }
  }
}

async function onMakeChange() {
  form.model = ''
  form.engine = ''
  form.transmission = ''
  form.drive = ''
  form.trim = ''
  models.value = []
  engines.value = []
  transmissions.value = []
  drives.value = []
  trims.value = []

  if (form.year && form.make) {
    try {
      models.value = await getModels(form.year, form.make)
    } catch (err) {
      console.error('Failed to load models:', err)
    }
  }
}

async function onModelChange() {
  form.engine = ''
  form.transmission = ''
  form.drive = ''
  form.trim = ''
  engines.value = []
  transmissions.value = []
  drives.value = []
  trims.value = []

  if (form.year && form.make && form.model) {
    try {
      engines.value = await getEngines(form.year, form.make, form.model)
    } catch (err) {
      console.error('Failed to load engines:', err)
    }
  }
}

async function onEngineChange() {
  form.transmission = ''
  form.drive = ''
  form.trim = ''
  transmissions.value = []
  drives.value = []
  trims.value = []

  if (form.year && form.make && form.model && form.engine) {
    try {
      transmissions.value = await getTransmissions(form.year, form.make, form.model, form.engine)
    } catch (err) {
      console.error('Failed to load transmissions:', err)
    }
  }
}

async function onTransmissionChange() {
  form.drive = ''
  form.trim = ''
  drives.value = []
  trims.value = []

  if (form.year && form.make && form.model && form.engine && form.transmission) {
    try {
      drives.value = await getDrives(form.year, form.make, form.model, form.engine, form.transmission)
    } catch (err) {
      console.error('Failed to load drives:', err)
    }
  }
}

async function onDriveChange() {
  form.trim = ''
  trims.value = []

  if (form.year && form.make && form.model && form.engine && form.transmission && form.drive) {
    try {
      trims.value = await getTrims(form.year, form.make, form.model, form.engine, form.transmission, form.drive)
    } catch (err) {
      console.error('Failed to load trims:', err)
    }
  }
}

async function loadVehicle() {
  try {
    const vehicle = await getCustomerVehicle(route.params.customerId, route.params.id)
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
      mileage_in: vehicle.mileage_in || null,
      mileage_out: vehicle.mileage_out || null,
      notes: vehicle.notes || '',
    })

    // Load cascade dropdowns based on loaded values
    if (form.year) await onYearChange()
    if (form.make) {
      form.make = vehicle.make
      await onMakeChange()
    }
    if (form.model) {
      form.model = vehicle.model
      await onModelChange()
    }
    if (form.engine) {
      form.engine = vehicle.engine
      await onEngineChange()
    }
    if (form.transmission) {
      form.transmission = vehicle.transmission
      await onTransmissionChange()
    }
    if (form.drive) {
      form.drive = vehicle.drive
      await onDriveChange()
    }
    if (form.trim) {
      form.trim = vehicle.trim
    }
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

    if (decoded.year) {
      form.year = decoded.year
      await onYearChange()
    }
    if (decoded.make) {
      form.make = decoded.make
      await onMakeChange()
    }
    if (decoded.model) {
      form.model = decoded.model
      await onModelChange()
    }
    if (decoded.engine) {
      form.engine = decoded.engine
      await onEngineChange()
    }
    if (decoded.transmission) {
      form.transmission = decoded.transmission
      await onTransmissionChange()
    }
    if (decoded.drive) {
      form.drive = decoded.drive
      await onDriveChange()
    }
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
      await updateCustomerVehicle(form.customer_id, route.params.id, form)
      success.value = 'Vehicle updated successfully!'
      toast.success('Vehicle updated successfully!')
    } else {
      await createCustomerVehicle(form.customer_id, form)
      success.value = 'Vehicle added to customer garage successfully!'
      toast.success('Vehicle added to customer garage successfully!')
    }

    setTimeout(() => {
      router.push('/cp/vehicles')
    }, 1500)
  } catch (err) {
    error.value = err.response?.data?.message || 'Failed to save vehicle'
    toast.error('Failed to save vehicle')
    console.error(err)
  } finally {
    saving.value = false
  }
}

function goBack() {
  router.push('/cp/vehicles')
}
</script>
