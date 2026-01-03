import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import appointmentService from '@/services/appointment.service'

export const useAppointmentStore = defineStore('appointments', () => {
  const appointments = ref([])
  const currentAppointment = ref(null)
  const loading = ref(false)
  const error = ref(null)

  // Filters
  const filters = ref({
    status: '',
    customer_id: '',
    vehicle_id: '',
    technician_id: '',
    date: '',
    date_from: '',
    date_to: ''
  })

  // Calendar data
  const calendarEvents = ref([])
  const calendarView = ref('timeGridWeek') // 'dayGridMonth', 'timeGridWeek', 'timeGridDay'

  // Computed
  const filteredAppointments = computed(() => {
    let result = appointments.value

    if (filters.value.status) {
      result = result.filter(apt => apt.status === filters.value.status)
    }

    if (filters.value.customer_id) {
      result = result.filter(apt => apt.customer_id == filters.value.customer_id)
    }

    if (filters.value.vehicle_id) {
      result = result.filter(apt => apt.vehicle_id == filters.value.vehicle_id)
    }

    if (filters.value.technician_id) {
      result = result.filter(apt => apt.technician_id == filters.value.technician_id)
    }

    if (filters.value.date) {
      result = result.filter(apt => {
        const aptDate = apt.start_time?.split('T')[0]
        return aptDate === filters.value.date
      })
    }

    return result
  })

  const upcomingAppointments = computed(() => {
    const now = new Date()
    return appointments.value
      .filter(apt => new Date(apt.start_time) >= now)
      .sort((a, b) => new Date(a.start_time) - new Date(b.start_time))
  })

  const todayAppointments = computed(() => {
    const today = new Date().toISOString().split('T')[0]
    return appointments.value.filter(apt => {
      const aptDate = apt.start_time?.split('T')[0]
      return aptDate === today
    })
  })

  const hasFilters = computed(() => {
    return !!(filters.value.status || filters.value.customer_id ||
              filters.value.vehicle_id || filters.value.technician_id ||
              filters.value.date || filters.value.date_from || filters.value.date_to)
  })

  // Actions
  async function fetchAppointments(params = {}) {
    try {
      loading.value = true
      error.value = null

      const queryParams = {
        ...filters.value,
        ...params
      }

      // Remove empty params
      Object.keys(queryParams).forEach(key => {
        if (!queryParams[key]) delete queryParams[key]
      })

      const response = await appointmentService.getAppointments(queryParams)
      appointments.value = response.data || []

      // Update calendar events
      updateCalendarEvents()

      return appointments.value
    } catch (err) {
      error.value = err.message || 'Failed to fetch appointments'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function fetchAppointment(id) {
    try {
      loading.value = true
      error.value = null

      const response = await appointmentService.getAppointment(id)
      currentAppointment.value = response.data

      return currentAppointment.value
    } catch (err) {
      error.value = err.message || 'Failed to fetch appointment'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function createAppointment(data) {
    try {
      loading.value = true
      error.value = null

      const response = await appointmentService.createAppointment(data)
      const newAppointment = response.data

      appointments.value.push(newAppointment)
      currentAppointment.value = newAppointment
      updateCalendarEvents()

      return newAppointment
    } catch (err) {
      error.value = err.message || 'Failed to create appointment'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function updateAppointment(id, data) {
    try {
      loading.value = true
      error.value = null

      const response = await appointmentService.updateAppointment(id, data)
      const updatedAppointment = response.data

      // Update in list
      const index = appointments.value.findIndex(apt => apt.id === id)
      if (index !== -1) {
        appointments.value[index] = updatedAppointment
      }

      // Update current
      if (currentAppointment.value?.id === id) {
        currentAppointment.value = updatedAppointment
      }

      updateCalendarEvents()

      return updatedAppointment
    } catch (err) {
      error.value = err.message || 'Failed to update appointment'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function updateStatus(id, status) {
    try {
      loading.value = true
      error.value = null

      const response = await appointmentService.updateAppointmentStatus(id, status)
      const updatedAppointment = response.data

      // Update in list
      const index = appointments.value.findIndex(apt => apt.id === id)
      if (index !== -1) {
        appointments.value[index] = updatedAppointment
      }

      // Update current
      if (currentAppointment.value?.id === id) {
        currentAppointment.value = updatedAppointment
      }

      updateCalendarEvents()

      return updatedAppointment
    } catch (err) {
      error.value = err.message || 'Failed to update appointment status'
      throw err
    } finally {
      loading.value = false
    }
  }

  function updateCalendarEvents() {
    calendarEvents.value = appointments.value.map(appointment => ({
      id: appointment.id,
      title: getEventTitle(appointment),
      start: appointment.start_time,
      end: appointment.end_time,
      backgroundColor: getStatusColor(appointment.status),
      borderColor: getStatusColor(appointment.status),
      extendedProps: appointment
    }))
  }

  function getEventTitle(appointment) {
    const parts = []
    if (appointment.customer_id) parts.push(`Customer #${appointment.customer_id}`)
    if (appointment.service_type) parts.push(appointment.service_type)
    if (appointment.technician_id) parts.push(`(Tech ${appointment.technician_id})`)
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

  function setFilter(key, value) {
    filters.value[key] = value
  }

  function clearFilters() {
    filters.value = {
      status: '',
      customer_id: '',
      vehicle_id: '',
      technician_id: '',
      date: '',
      date_from: '',
      date_to: ''
    }
  }

  function setCalendarView(view) {
    calendarView.value = view
  }

  function reset() {
    appointments.value = []
    currentAppointment.value = null
    calendarEvents.value = []
    loading.value = false
    error.value = null
    clearFilters()
  }

  return {
    // State
    appointments,
    currentAppointment,
    loading,
    error,
    filters,
    calendarEvents,
    calendarView,

    // Computed
    filteredAppointments,
    upcomingAppointments,
    todayAppointments,
    hasFilters,

    // Actions
    fetchAppointments,
    fetchAppointment,
    createAppointment,
    updateAppointment,
    updateStatus,
    setFilter,
    clearFilters,
    setCalendarView,
    updateCalendarEvents,
    reset
  }
})
