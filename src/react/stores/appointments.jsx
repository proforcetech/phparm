import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react'

import appointmentService from '../../services/appointment.service'

const AppointmentContext = createContext(null)

const defaultFilters = {
  status: '',
  customer_id: '',
  vehicle_id: '',
  technician_id: '',
  date: '',
  date_from: '',
  date_to: '',
}

export function AppointmentProvider({ children }) {
  const [appointments, setAppointments] = useState([])
  const [currentAppointment, setCurrentAppointment] = useState(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState(null)
  const [filters, setFilters] = useState(defaultFilters)
  const [calendarEvents, setCalendarEvents] = useState([])
  const [calendarView, setCalendarView] = useState('timeGridWeek')

  const filteredAppointments = useMemo(() => {
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
      result = result.filter((apt) => String(apt.technician_id) === String(filters.technician_id))
    }

    if (filters.date) {
      result = result.filter((apt) => {
        const aptDate = apt.start_time?.split('T')[0]
        return aptDate === filters.date
      })
    }

    return result
  }, [appointments, filters])

  const upcomingAppointments = useMemo(() => {
    const now = new Date()
    return appointments
      .filter((apt) => new Date(apt.start_time) >= now)
      .sort((a, b) => new Date(a.start_time) - new Date(b.start_time))
  }, [appointments])

  const todayAppointments = useMemo(() => {
    const today = new Date().toISOString().split('T')[0]
    return appointments.filter((apt) => {
      const aptDate = apt.start_time?.split('T')[0]
      return aptDate === today
    })
  }, [appointments])

  const hasFilters = useMemo(() => {
    return !!(
      filters.status ||
      filters.customer_id ||
      filters.vehicle_id ||
      filters.technician_id ||
      filters.date ||
      filters.date_from ||
      filters.date_to
    )
  }, [filters])

  const getEventTitle = useCallback((appointment) => {
    const parts = []
    if (appointment.customer_id) parts.push(`Customer #${appointment.customer_id}`)
    if (appointment.service_type) parts.push(appointment.service_type)
    if (appointment.technician_id) parts.push(`(Tech ${appointment.technician_id})`)
    return parts.length > 0 ? parts.join(' - ') : 'Appointment'
  }, [])

  const getStatusColor = useCallback((status) => {
    const colors = {
      pending: '#F59E0B',
      confirmed: '#3B82F6',
      in_progress: '#8B5CF6',
      completed: '#10B981',
      cancelled: '#EF4444',
      no_show: '#6B7280',
    }
    return colors[status?.toLowerCase()] || '#6B7280'
  }, [])

  const updateCalendarEvents = useCallback(() => {
    setCalendarEvents(
      appointments.map((appointment) => ({
        id: appointment.id,
        title: getEventTitle(appointment),
        start: appointment.start_time,
        end: appointment.end_time,
        backgroundColor: getStatusColor(appointment.status),
        borderColor: getStatusColor(appointment.status),
        extendedProps: appointment,
      }))
    )
  }, [appointments, getEventTitle, getStatusColor])

  useEffect(() => {
    updateCalendarEvents()
  }, [updateCalendarEvents])

  const fetchAppointments = useCallback(
    async (params = {}) => {
      try {
        setLoading(true)
        setError(null)

        const queryParams = {
          ...filters,
          ...params,
        }

        Object.keys(queryParams).forEach((key) => {
          if (!queryParams[key]) delete queryParams[key]
        })

        const response = await appointmentService.getAppointments(queryParams)
        const data = response.data || []
        setAppointments(data)
        return data
      } catch (err) {
        setError(err.message || 'Failed to fetch appointments')
        throw err
      } finally {
        setLoading(false)
      }
    },
    [filters]
  )

  const fetchAppointment = useCallback(async (id) => {
    try {
      setLoading(true)
      setError(null)

      const response = await appointmentService.getAppointment(id)
      setCurrentAppointment(response.data)

      return response.data
    } catch (err) {
      setError(err.message || 'Failed to fetch appointment')
      throw err
    } finally {
      setLoading(false)
    }
  }, [])

  const createAppointment = useCallback(async (data) => {
    try {
      setLoading(true)
      setError(null)

      const response = await appointmentService.createAppointment(data)
      const newAppointment = response.data

      setAppointments((prev) => [...prev, newAppointment])
      setCurrentAppointment(newAppointment)
      return newAppointment
    } catch (err) {
      setError(err.message || 'Failed to create appointment')
      throw err
    } finally {
      setLoading(false)
    }
  }, [])

  const updateAppointment = useCallback(async (id, data) => {
    try {
      setLoading(true)
      setError(null)

      const response = await appointmentService.updateAppointment(id, data)
      const updatedAppointment = response.data

      setAppointments((prev) =>
        prev.map((appointment) => (appointment.id === id ? updatedAppointment : appointment))
      )

      setCurrentAppointment((prev) => (prev?.id === id ? updatedAppointment : prev))

      return updatedAppointment
    } catch (err) {
      setError(err.message || 'Failed to update appointment')
      throw err
    } finally {
      setLoading(false)
    }
  }, [])

  const updateStatus = useCallback(async (id, status) => {
    try {
      setLoading(true)
      setError(null)

      const response = await appointmentService.updateAppointmentStatus(id, status)
      const updatedAppointment = response.data

      setAppointments((prev) =>
        prev.map((appointment) => (appointment.id === id ? updatedAppointment : appointment))
      )

      setCurrentAppointment((prev) => (prev?.id === id ? updatedAppointment : prev))

      return updatedAppointment
    } catch (err) {
      setError(err.message || 'Failed to update appointment status')
      throw err
    } finally {
      setLoading(false)
    }
  }, [])

  const setFilter = useCallback((key, value) => {
    setFilters((prev) => ({ ...prev, [key]: value }))
  }, [])

  const clearFilters = useCallback(() => {
    setFilters(defaultFilters)
  }, [])

  const reset = useCallback(() => {
    setAppointments([])
    setCurrentAppointment(null)
    setCalendarEvents([])
    setLoading(false)
    setError(null)
    setFilters(defaultFilters)
  }, [])

  const value = useMemo(
    () => ({
      appointments,
      currentAppointment,
      loading,
      error,
      filters,
      calendarEvents,
      calendarView,
      filteredAppointments,
      upcomingAppointments,
      todayAppointments,
      hasFilters,
      fetchAppointments,
      fetchAppointment,
      createAppointment,
      updateAppointment,
      updateStatus,
      setFilter,
      clearFilters,
      setCalendarView,
      updateCalendarEvents,
      reset,
    }),
    [
      appointments,
      calendarEvents,
      calendarView,
      clearFilters,
      createAppointment,
      currentAppointment,
      error,
      fetchAppointment,
      fetchAppointments,
      filters,
      filteredAppointments,
      hasFilters,
      loading,
      reset,
      setCalendarView,
      setFilter,
      todayAppointments,
      upcomingAppointments,
      updateAppointment,
      updateCalendarEvents,
      updateStatus,
    ]
  )

  return <AppointmentContext.Provider value={value}>{children}</AppointmentContext.Provider>
}

export function useAppointmentStore() {
  const context = useContext(AppointmentContext)

  if (!context) {
    throw new Error('useAppointmentStore must be used within an AppointmentProvider.')
  }

  return context
}
