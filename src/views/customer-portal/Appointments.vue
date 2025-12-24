<template>
  <div>
    <div class="mb-6 flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">My Appointments</h1>
        <p class="mt-1 text-sm text-gray-500">View and manage your appointments</p>
      </div>
      <Button @click="loadAvailability">
        <svg class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
        </svg>
        Check Availability
      </Button>
    </div>

    <Card>
      <div class="space-y-4">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700">Date</label>
            <Input v-model="form.date" type="date" class="mt-1" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700">Preferred technician (optional)</label>
            <Input v-model="form.technician_id" type="number" min="0" class="mt-1" placeholder="Technician ID" />
          </div>
          <div class="flex items-end">
            <Button class="w-full" :loading="loading" @click="loadAvailability">Find slots</Button>
          </div>
        </div>

        <div v-if="loading" class="text-sm text-gray-500">Loading availability...</div>
        <div v-else-if="availability.closed" class="rounded-md bg-amber-50 p-4 text-amber-800">
          {{ availability.reason || 'We are closed on this date.' }}
        </div>
        <div v-else>
          <h3 class="text-sm font-semibold text-gray-700">Available slots</h3>
          <div v-if="availability.slots.length" class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div
              v-for="slot in availability.slots"
              :key="slot.start"
              class="rounded-lg border border-gray-200 p-3 flex items-center justify-between"
            >
              <div>
                <p class="font-semibold text-gray-900">{{ formatTime(slot.start) }} - {{ formatTime(slot.end) }}</p>
                <p class="text-xs text-gray-500">{{ form.date }}</p>
              </div>
              <Badge v-if="slot.available" variant="success">Open</Badge>
              <Badge v-else variant="warning">Booked</Badge>
            </div>
          </div>
          <p v-else class="text-sm text-gray-500">No open slots for this date.</p>
        </div>
      </div>
    </Card>
  </div>
</template>

<script setup>
import { reactive, ref } from 'vue'
import Card from '@/components/ui/Card.vue'
import Button from '@/components/ui/Button.vue'
import Input from '@/components/ui/Input.vue'
import Badge from '@/components/ui/Badge.vue'
import appointmentService from '@/services/appointment.service'

const loading = ref(false)
const availability = reactive({ slots: [], closed: false, reason: '' })
const form = reactive({
  date: new Date().toISOString().substring(0, 10),
  technician_id: ''
})

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
    const response = await appointmentService.fetchPublicAvailability(params)
    const data = response.data
    availability.slots = data.slots || []
    availability.closed = Boolean(data.closed)
    availability.reason = data.reason || ''
  } finally {
    loading.value = false
  }
}
</script>
