<template>
  <div>
    <div class="mb-6">
      <div class="flex items-center gap-4">
        <Button variant="ghost" @click="$router.push('/cp/appointments')">
          <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
          </svg>
        </Button>
        <div>
          <h1 class="text-2xl font-bold text-gray-900">Book Appointment</h1>
          <p class="mt-1 text-sm text-gray-500">Schedule a new appointment</p>
        </div>
      </div>
    </div>

    <Card>
      <div class="space-y-6">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700">Date *</label>
            <Input v-model="form.date" type="date" class="mt-1" required @change="loadAvailability" />
          </div>
          <div>
            <Autocomplete
              v-model="form.technician_id"
              label="Technician *"
              placeholder="Search technician by name or email..."
              :search-fn="searchTechnicians"
              :item-value="(item) => item.id"
              :item-label="(item) => item.name"
              :item-subtext="(item) => item.email"
              required
              @select="onTechnicianSelect"
            />
          </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <Autocomplete
              v-model="form.customer_id"
              label="Customer *"
              placeholder="Search by name, email, phone, or ID..."
              :search-fn="searchCustomers"
              :item-value="(item) => item.id"
              :item-label="(item) => item.name"
              :item-subtext="(item) => `${item.email || ''} ${item.phone ? '• ' + item.phone : ''}`"
              required
              @select="onCustomerSelect"
            />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700">Vehicle *</label>
            <Select
              v-model="form.vehicle_id"
              :options="vehicleOptions"
              :disabled="!form.customer_id || loadingVehicles"
              required
              class="mt-1"
            />
            <p v-if="loadingVehicles" class="mt-1 text-xs text-gray-500">Loading vehicles...</p>
            <p v-else-if="form.customer_id && vehicleOptions.length === 0" class="mt-1 text-xs text-amber-600">No vehicles found for this customer</p>
          </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700">Status</label>
            <Select v-model="form.status" :options="statusOptions" />
          </div>
          <div class="md:col-span-2">
            <label class="block text-sm font-medium text-gray-700">Notes</label>
            <Textarea v-model="form.notes" :rows="2" placeholder="Add visit notes or context" />
          </div>
        </div>

        <div class="flex items-center justify-between">
          <p class="text-sm text-gray-600">Choose an available slot to book the appointment.</p>
          <div class="flex gap-2">
            <Button variant="secondary" :loading="loading" @click="loadAvailability">Refresh</Button>
            <Button :disabled="!selectedSlot" :loading="saving" @click="bookAppointment">Book appointment</Button>
          </div>
        </div>

        <div v-if="loading" class="text-sm text-gray-500">Loading slots...</div>
        <div v-else-if="availability.closed" class="rounded-md bg-amber-50 p-4 text-amber-800">
          {{ availability.reason || 'No availability for the selected date.' }}
        </div>
        <div v-else>
          <h3 class="text-sm font-semibold text-gray-700">Available Slots</h3>
          <div v-if="availability.slots.length" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
            <button
              v-for="slot in availability.slots"
              :key="slot.start"
              class="rounded-lg border border-gray-200 p-3 shadow-sm flex items-center justify-between text-left"
              :class="[
                selectedSlot?.start === slot.start ? 'ring-2 ring-primary-500' : '',
                slot.available ? 'bg-white' : 'bg-gray-50'
              ]"
              :disabled="!slot.available"
              @click="selectSlot(slot)"
            >
              <div>
                <p class="font-semibold text-gray-900">{{ formatTime(slot.start) }} - {{ formatTime(slot.end) }}</p>
                <p class="text-xs text-gray-500">{{ form.date }}</p>
              </div>
              <Badge :variant="slot.available ? 'success' : 'warning'" >
                {{ slot.available ? 'Open' : 'Booked' }}
              </Badge>
            </button>
          </div>
          <div v-else class="text-sm text-gray-500">No slots available for the selected date.</div>
        </div>
      </div>
    </Card>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import Card from '@/components/ui/Card.vue'
import Button from '@/components/ui/Button.vue'
import Input from '@/components/ui/Input.vue'
import Badge from '@/components/ui/Badge.vue'
import Select from '@/components/ui/Select.vue'
import Textarea from '@/components/ui/Textarea.vue'
import Autocomplete from '@/components/ui/Autocomplete.vue'
import appointmentService from '@/services/appointment.service'
import technicianService from '@/services/technician.service'
import customerService from '@/services/customer.service'

const route = useRoute()
const loading = ref(false)
const saving = ref(false)
const loadingVehicles = ref(false)
const availability = reactive({ slots: [], closed: false, reason: '' })
const vehicleOptions = ref([])
const form = reactive({
  date: new Date().toISOString().substring(0, 10),
  technician_id: null,
  customer_id: null,
  vehicle_id: null,
  status: 'scheduled',
  notes: ''
})
const selectedSlot = ref(null)

const statusOptions = [
  { label: 'Scheduled', value: 'scheduled' },
  { label: 'Confirmed', value: 'confirmed' },
  { label: 'In progress', value: 'in_progress' },
  { label: 'Completed', value: 'completed' },
  { label: 'Cancelled', value: 'cancelled' }
]

const formatTime = (value) => {
  const date = new Date(value)
  return date.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })
}

const loadAvailability = async () => {
  loading.value = true
  try {
    const params = { date: form.date }
    if (form.technician_id) {
      params.technician_id = form.technician_id
    }
    const response = await appointmentService.fetchAvailability(params)
    const data = response.data
    availability.slots = data.slots || []
    availability.closed = Boolean(data.closed)
    availability.reason = data.reason || ''
    if (!availability.slots.find((slot) => selectedSlot.value?.start === slot.start)) {
      selectedSlot.value = null
    }
  } finally {
    loading.value = false
  }
}

const selectSlot = (slot) => {
  if (!slot.available) return
  selectedSlot.value = slot
}

const bookAppointment = async () => {
  if (!selectedSlot.value) return
  saving.value = true
  try {
    await appointmentService.createAppointment({
      start_time: selectedSlot.value.start,
      end_time: selectedSlot.value.end,
      technician_id: form.technician_id || null,
      customer_id: form.customer_id || null,
      vehicle_id: form.vehicle_id || null,
      status: form.status,
      notes: form.notes
    })
    selectedSlot.value = null
    await loadAvailability()
  } finally {
    saving.value = false
  }
}

const preloadFromClone = async () => {
  const cloneId = route.query.clone
  if (!cloneId) return
  const response = await appointmentService.getAppointment(cloneId)
  const existing = response.data
  form.date = existing.start_time.substring(0, 10)
  form.technician_id = existing.technician_id || null
  form.customer_id = existing.customer_id || null
  form.vehicle_id = existing.vehicle_id || null
  form.status = existing.status
  form.notes = existing.notes || ''
  await loadAvailability()
}

async function searchTechnicians(query) {
  try {
    return await technicianService.searchTechnicians(query)
  } catch (error) {
    console.error('Technician search failed:', error)
    return []
  }
}

async function searchCustomers(query) {
  try {
    return await customerService.searchCustomers(query)
  } catch (error) {
    console.error('Customer search failed:', error)
    return []
  }
}

function onTechnicianSelect(technician) {
  console.log('Selected technician:', technician)
  loadAvailability()
}

async function onCustomerSelect(customer) {
  console.log('Selected customer:', customer)
  await loadCustomerVehicles(customer.id)
}

async function loadCustomerVehicles(customerId) {
  if (!customerId) {
    vehicleOptions.value = []
    return
  }

  loadingVehicles.value = true
  try {
    const vehicles = await customerService.getCustomerVehicles(customerId)
    vehicleOptions.value = vehicles.map(v => ({
      value: v.id,
      label: `${v.year || ''} ${v.make || ''} ${v.model || ''} ${v.vin ? `(${v.vin.substring(0, 8)}...)` : ''}`.trim()
    }))

    // Clear vehicle selection if it's not in the new list
    if (form.vehicle_id && !vehicleOptions.value.find(v => v.value === form.vehicle_id)) {
      form.vehicle_id = null
    }
  } catch (error) {
    console.error('Failed to load vehicles:', error)
    vehicleOptions.value = []
  } finally {
    loadingVehicles.value = false
  }
}

// Watch for customer_id changes
watch(() => form.customer_id, (newCustomerId) => {
  if (newCustomerId) {
    loadCustomerVehicles(newCustomerId)
  } else {
    vehicleOptions.value = []
    form.vehicle_id = null
  }
})

onMounted(loadAvailability)
onMounted(preloadFromClone)
</script>
