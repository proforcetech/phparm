import { create } from 'zustand'

import appointmentService from '../services/appointment.service'

type Appointment = Record<string, any>

type AppointmentFilters = {
  status: string
  customer_id: string
  vehicle_id: string
  technician_id: string
  date: string
  date_from: string
  date_to: string
}

type CalendarEvent = {
  id: string | number
  title: string
  start: string
  end: string
  backgroundColor: string
  borderColor: string
  extendedProps: Appointment
}

type AppointmentState = {
  appointments: Appointment[]
  currentAppointment: Appointment | null
  loading: boolean
  error: string | null
  filters: AppointmentFilters
  calendarEvents: CalendarEvent[]
  calendarView: string
  filteredAppointments: () => Appointment[]
  upcomingAppointments: () => Appointment[]
  todayAppointments: () => Appointment[]
  hasFilters: () => boolean
  fetchAppointments: (params?: Record<string, unknown>) => Promise<Appointment[]>
  fetchAppointment: (id: number | string) => Promise<Appointment>
  createAppointment: (data: Record<string, unknown>) => Promise<Appointment>
  updateAppointment: (id: number | string, data: Record<string, unknown>) => Promise<Appointment>
  deleteAppointment: (id: number | string) => Promise<void>
  updateCalendarEvents: () => void
  setFilter: (key: keyof AppointmentFilters, value: string) => void
  clearFilters: () => void
  setCalendarView: (view: string) => void
  reset: () => void
}

const defaultFilters: AppointmentFilters = {
  status: '',
  customer_id: '',
  vehicle_id: '',
  technician_id: '',
  date: '',
  date_from: '',
  date_to: '',
}

const getEventTitle = (appointment: Appointment) => {
  const parts: string[] = []
  if (appointment.customer_id) parts.push(`Customer #${appointment.customer_id}`)
  if (appointment.service_type) parts.push(appointment.service_type)
  if (appointment.technician_id) parts.push(`(Tech ${appointment.technician_id})`)
  return parts.length > 0 ? parts.join(' - ') : 'Appointment'
}

const getStatusColor = (status: string) => {
  const colors: Record<string, string> = {
    pending: '#F59E0B',
    confirmed: '#3B82F6',
    in_progress: '#8B5CF6',
    completed: '#10B981',
    cancelled: '#EF4444',
    no_show: '#6B7280',
  }
  return colors[status?.toLowerCase()] || '#6B7280'
}

const buildCalendarEvents = (appointments: Appointment[]): CalendarEvent[] =>
  appointments.map((appointment) => ({
    id: appointment.id,
    title: getEventTitle(appointment),
    start: appointment.start_time,
    end: appointment.end_time,
    backgroundColor: getStatusColor(appointment.status),
    borderColor: getStatusColor(appointment.status),
    extendedProps: appointment,
  }))

export const useAppointmentStore = create<AppointmentState>((set, get) => ({
  appointments: [],
  currentAppointment: null,
  loading: false,
  error: null,
  filters: defaultFilters,
  calendarEvents: [],
  calendarView: 'timeGridWeek',
  filteredAppointments: () => {
    const { appointments, filters } = get()
    let result = appointments

    if (filters.status) {
      result = result.filter((apt) => apt.status === filters.status)
    }

    if (filters.customer_id) {
      result = result.filter((apt) => String(apt.customer_id) === String(filters.customer_id))
    }

    if (filters.vehicle_id) {
      result = result.filter((apt) => String(apt.vehicle_id) === String(filters.vehicle_id))
    }

    if (filters.technician_id) {
      result = result.filter(
        (apt) => String(apt.technician_id) === String(filters.technician_id)
      )
    }

    if (filters.date) {
      result = result.filter((apt) => {
        const aptDate = apt.start_time?.split('T')[0]
        return aptDate === filters.date
      })
    }

    return result
  },
  upcomingAppointments: () => {
    const { appointments } = get()
    const now = new Date()
    return appointments
      .filter((apt) => new Date(apt.start_time) >= now)
      .sort((a, b) => new Date(a.start_time).getTime() - new Date(b.start_time).getTime())
  },
  todayAppointments: () => {
    const { appointments } = get()
    const today = new Date().toISOString().split('T')[0]
    return appointments.filter((apt) => {
      const aptDate = apt.start_time?.split('T')[0]
      return aptDate === today
    })
  },
  hasFilters: () => {
    const { filters } = get()
    return Boolean(
      filters.status ||
        filters.customer_id ||
        filters.vehicle_id ||
        filters.technician_id ||
        filters.date ||
        filters.date_from ||
        filters.date_to
    )
  },
  fetchAppointments: async (params = {}) => {
    try {
      set({ loading: true, error: null })

      const queryParams = {
        ...get().filters,
        ...params,
      }

      Object.keys(queryParams).forEach((key) => {
        if (!queryParams[key as keyof typeof queryParams]) delete queryParams[key as keyof typeof queryParams]
      })

      const response = await appointmentService.getAppointments(queryParams)
      const data = response.data || []
      set({ appointments: data, calendarEvents: buildCalendarEvents(data) })
      return data
    } catch (err: any) {
      set({ error: err.message || 'Failed to fetch appointments' })
      throw err
    } finally {
      set({ loading: false })
    }
  },
  fetchAppointment: async (id) => {
    try {
      set({ loading: true, error: null })

      const response = await appointmentService.getAppointment(id)
      const data = response.data
      set({ currentAppointment: data })
      return data
    } catch (err: any) {
      set({ error: err.message || 'Failed to fetch appointment' })
      throw err
    } finally {
      set({ loading: false })
    }
  },
  createAppointment: async (data) => {
    try {
      set({ loading: true, error: null })

      const response = await appointmentService.createAppointment(data)
      const newAppointment = response.data
      const nextAppointments = [...get().appointments, newAppointment]
      set({
        appointments: nextAppointments,
        currentAppointment: newAppointment,
        calendarEvents: buildCalendarEvents(nextAppointments),
      })
      return newAppointment
    } catch (err: any) {
      set({ error: err.message || 'Failed to create appointment' })
      throw err
    } finally {
      set({ loading: false })
    }
  },
  updateAppointment: async (id, data) => {
    try {
      set({ loading: true, error: null })

      const response = await appointmentService.updateAppointment(id, data)
      const updatedAppointment = response.data
      const nextAppointments = get().appointments.map((appointment) =>
        appointment.id === id ? updatedAppointment : appointment
      )

      set({
        appointments: nextAppointments,
        currentAppointment:
          get().currentAppointment?.id === id ? updatedAppointment : get().currentAppointment,
        calendarEvents: buildCalendarEvents(nextAppointments),
      })

      return updatedAppointment
    } catch (err: any) {
      set({ error: err.message || 'Failed to update appointment' })
      throw err
    } finally {
      set({ loading: false })
    }
  },
  deleteAppointment: async (id) => {
    try {
      set({ loading: true, error: null })

      await appointmentService.deleteAppointment(id)
      const nextAppointments = get().appointments.filter((appointment) => appointment.id !== id)
      set({
        appointments: nextAppointments,
        currentAppointment: get().currentAppointment?.id === id ? null : get().currentAppointment,
        calendarEvents: buildCalendarEvents(nextAppointments),
      })
    } catch (err: any) {
      set({ error: err.message || 'Failed to delete appointment' })
      throw err
    } finally {
      set({ loading: false })
    }
  },
  updateCalendarEvents: () => {
    const nextEvents = buildCalendarEvents(get().appointments)
    set({ calendarEvents: nextEvents })
  },
  setFilter: (key, value) => {
    set((state) => ({ filters: { ...state.filters, [key]: value } }))
  },
  clearFilters: () => {
    set({ filters: defaultFilters })
  },
  setCalendarView: (view) => {
    set({ calendarView: view })
  },
  reset: () => {
    set({
      appointments: [],
      currentAppointment: null,
      loading: false,
      error: null,
      filters: defaultFilters,
      calendarEvents: [],
      calendarView: 'timeGridWeek',
    })
  },
}))
