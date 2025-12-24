<template>
  <div>
    <div class="mb-6 flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Appointment Calendar</h1>
        <p class="mt-1 text-sm text-gray-500">Visual calendar view of scheduled appointments</p>
      </div>
      <div class="flex gap-2">
        <Button variant="ghost" @click="$router.push('/cp/appointments')">
          <svg class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16" />
          </svg>
          List View
        </Button>
        <Button variant="ghost" @click="$router.push('/cp/appointments/availability-settings')">
          <svg class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 1.343-3 3s1.343 3 3 3 3-1.343 3-3-1.343-3-3-3z M19.4 15a1.65 1.65 0 01-.33.5l1.5 2.6-1.73 1-1.5-2.6a6.99 6.99 0 01-1.7.7V20h-2v-2.8a6.99 6.99 0 01-1.7-.7l-1.5 2.6-1.73-1 1.5-2.6a1.65 1.65 0 01-.33-.5L4 14v-2l2.2-.4c.09-.25.2-.49.33-.72l-1.5-2.6 1.73-1 1.5 2.6c.54-.29 1.11-.51 1.7-.65V4h2v2.63c.59.14 1.16.36 1.7.65l1.5-2.6 1.73 1-1.5 2.6c.13.23.24.47.33.72L20 12v2z" />
          </svg>
          Availability
        </Button>
        <Button @click="createAppointment">
          <svg class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
          </svg>
          New Appointment
        </Button>
      </div>
    </div>

    <!-- Loading State -->
    <div v-if="loading" class="flex justify-center py-12">
      <Loading size="xl" text="Loading calendar..." />
    </div>

    <!-- Calendar -->
    <Card v-else class="p-4">
      <FullCalendar :options="calendarOptions" ref="calendarRef" />
    </Card>

    <!-- Event Details Modal -->
    <Modal v-if="selectedEvent" @close="selectedEvent = null">
      <template #title>Appointment Details</template>
      <template #content>
        <div class="space-y-4">
          <div>
            <label class="text-sm font-medium text-gray-500">Status</label>
            <div class="mt-1">
              <Badge :variant="getStatusVariant(selectedEvent.extendedProps.status)">
                {{ selectedEvent.extendedProps.status }}
              </Badge>
            </div>
          </div>
          <div>
            <label class="text-sm font-medium text-gray-500">Date & Time</label>
            <p class="mt-1 text-sm text-gray-900">
              {{ formatDateTime(selectedEvent.start) }} - {{ formatTime(selectedEvent.end) }}
            </p>
          </div>
          <div v-if="selectedEvent.extendedProps.customer_id">
            <label class="text-sm font-medium text-gray-500">Customer</label>
            <p class="mt-1 text-sm text-gray-900">
              Customer #{{ selectedEvent.extendedProps.customer_id }}
            </p>
          </div>
          <div v-if="selectedEvent.extendedProps.vehicle_id">
            <label class="text-sm font-medium text-gray-500">Vehicle</label>
            <p class="mt-1 text-sm text-gray-900">
              Vehicle #{{ selectedEvent.extendedProps.vehicle_id }}
            </p>
          </div>
          <div v-if="selectedEvent.extendedProps.technician_id">
            <label class="text-sm font-medium text-gray-500">Technician</label>
            <p class="mt-1 text-sm text-gray-900">
              Tech {{ selectedEvent.extendedProps.technician_id }}
            </p>
          </div>
          <div v-if="selectedEvent.extendedProps.service_type">
            <label class="text-sm font-medium text-gray-500">Service Type</label>
            <p class="mt-1 text-sm text-gray-900">{{ selectedEvent.extendedProps.service_type }}</p>
          </div>
          <div v-if="selectedEvent.extendedProps.notes">
            <label class="text-sm font-medium text-gray-500">Notes</label>
            <p class="mt-1 text-sm text-gray-900">{{ selectedEvent.extendedProps.notes }}</p>
          </div>
        </div>
      </template>
      <template #actions>
        <Button variant="outline" @click="selectedEvent = null">Close</Button>
        <Button @click="editAppointment(selectedEvent.extendedProps.id)">
          Edit Appointment
        </Button>
      </template>
    </Modal>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import FullCalendar from '@fullcalendar/vue3'
import dayGridPlugin from '@fullcalendar/daygrid'
import timeGridPlugin from '@fullcalendar/timegrid'
import interactionPlugin from '@fullcalendar/interaction'
import Card from '@/components/ui/Card.vue'
import Button from '@/components/ui/Button.vue'
import Badge from '@/components/ui/Badge.vue'
import Loading from '@/components/ui/Loading.vue'
import Modal from '@/components/ui/Modal.vue'
import appointmentService from '@/services/appointment.service'
import { useToast } from '@/stores/toast'

const router = useRouter()
const toast = useToast()

const loading = ref(true)
const calendarRef = ref(null)
const selectedEvent = ref(null)
const appointments = ref([])

const calendarOptions = ref({
  plugins: [dayGridPlugin, timeGridPlugin, interactionPlugin],
  initialView: 'timeGridWeek',
  headerToolbar: {
    left: 'prev,next today',
    center: 'title',
    right: 'dayGridMonth,timeGridWeek,timeGridDay'
  },
  slotMinTime: '07:00:00',
  slotMaxTime: '19:00:00',
  allDaySlot: false,
  height: 'auto',
  events: [],
  eventClick: handleEventClick,
  dateClick: handleDateClick,
  eventDidMount: styleEvent,
  eventTimeFormat: {
    hour: 'numeric',
    minute: '2-digit',
    meridiem: 'short'
  }
})

onMounted(async () => {
  await loadAppointments()
})

async function loadAppointments() {
  try {
    loading.value = true
    const response = await appointmentService.getAppointments({
      limit: 1000 // Load all appointments for calendar
    })

    appointments.value = response.data || []

    // Convert appointments to FullCalendar events
    calendarOptions.value.events = appointments.value.map(appointment => ({
      id: appointment.id,
      title: getEventTitle(appointment),
      start: appointment.start_time,
      end: appointment.end_time,
      backgroundColor: getStatusColor(appointment.status),
      borderColor: getStatusColor(appointment.status),
      extendedProps: {
        id: appointment.id,
        status: appointment.status,
        customer_id: appointment.customer_id,
        vehicle_id: appointment.vehicle_id,
        technician_id: appointment.technician_id,
        service_type: appointment.service_type,
        notes: appointment.notes
      }
    }))
  } catch (error) {
    console.error('Failed to load appointments:', error)
    toast.error('Failed to load appointments')
  } finally {
    loading.value = false
  }
}

function getEventTitle(appointment) {
  const parts = []

  if (appointment.customer_id) {
    parts.push(`Customer #${appointment.customer_id}`)
  }

  if (appointment.service_type) {
    parts.push(appointment.service_type)
  }

  if (appointment.technician_id) {
    parts.push(`(Tech ${appointment.technician_id})`)
  }

  return parts.length > 0 ? parts.join(' - ') : 'Appointment'
}

function getStatusColor(status) {
  const colors = {
    'pending': '#F59E0B',
    'confirmed': '#3B82F6',
    'in_progress': '#8B5CF6',
    'completed': '#10B981',
    'cancelled': '#EF4444',
    'no_show': '#6B7280'
  }
  return colors[status?.toLowerCase()] || '#6B7280'
}

function getStatusVariant(status) {
  const variants = {
    'pending': 'warning',
    'confirmed': 'info',
    'in_progress': 'default',
    'completed': 'success',
    'cancelled': 'danger',
    'no_show': 'default'
  }
  return variants[status?.toLowerCase()] || 'default'
}

function styleEvent(info) {
  info.el.style.cursor = 'pointer'
}

function handleEventClick(info) {
  selectedEvent.value = info.event
}

function handleDateClick(info) {
  // Navigate to booking page with pre-filled date
  const date = info.dateStr.split('T')[0]
  const time = info.dateStr.split('T')[1]?.substring(0, 5)

  let query = { date }
  if (time) {
    query.time = time
  }

  router.push({ path: '/cp/appointments/create', query })
}

function createAppointment() {
  router.push('/cp/appointments/create')
}

function editAppointment(id) {
  router.push(`/cp/appointments/create?edit=${id}`)
}

function formatDateTime(date) {
  if (!date) return ''
  return new Intl.DateTimeFormat('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
    hour: 'numeric',
    minute: '2-digit'
  }).format(new Date(date))
}

function formatTime(date) {
  if (!date) return ''
  return new Intl.DateTimeFormat('en-US', {
    hour: 'numeric',
    minute: '2-digit'
  }).format(new Date(date))
}
</script>

<style>
/* FullCalendar custom styling */
.fc {
  font-family: inherit;
}

.fc .fc-button {
  background-color: #3B82F6;
  border-color: #3B82F6;
  text-transform: capitalize;
}

.fc .fc-button:hover {
  background-color: #2563EB;
  border-color: #2563EB;
}

.fc .fc-button-primary:not(:disabled):active,
.fc .fc-button-primary:not(:disabled).fc-button-active {
  background-color: #1D4ED8;
  border-color: #1D4ED8;
}

.fc .fc-daygrid-day-number,
.fc .fc-col-header-cell-cushion {
  color: #374151;
  text-decoration: none;
}

.fc .fc-daygrid-day.fc-day-today {
  background-color: #EFF6FF;
}

.fc .fc-timegrid-slot {
  height: 3em;
}

.fc-event {
  border-radius: 4px;
  padding: 2px 4px;
  font-size: 0.875rem;
}

.fc-event-title {
  font-weight: 500;
}
</style>
