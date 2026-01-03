import { useCallback, useEffect, useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import FullCalendar from '@fullcalendar/react'
import dayGridPlugin from '@fullcalendar/daygrid'
import timeGridPlugin from '@fullcalendar/timegrid'
import interactionPlugin from '@fullcalendar/interaction'

import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Loading from '../../components/ui/Loading'
import Modal from '../../components/ui/Modal'
import appointmentService from '../../../services/appointment.service'
import { useToast } from '../../stores/toast'
import './AppointmentCalendar.css'

const statusColors = {
  pending: '#F59E0B',
  confirmed: '#3B82F6',
  in_progress: '#8B5CF6',
  completed: '#10B981',
  cancelled: '#EF4444',
  no_show: '#6B7280',
}

const statusVariants = {
  pending: 'warning',
  confirmed: 'info',
  in_progress: 'default',
  completed: 'success',
  cancelled: 'danger',
  no_show: 'default',
}

export default function AppointmentCalendar() {
  const navigate = useNavigate()
  const toast = useToast()
  const [loading, setLoading] = useState(true)
  const [appointments, setAppointments] = useState([])
  const [selectedEvent, setSelectedEvent] = useState(null)

  const getEventTitle = useCallback((appointment) => {
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
  }, [])

  const getStatusColor = useCallback((status) => {
    if (!status) return statusColors.no_show
    return statusColors[status.toLowerCase()] || statusColors.no_show
  }, [])

  const getStatusVariant = useCallback((status) => {
    if (!status) return statusVariants.no_show
    return statusVariants[status.toLowerCase()] || statusVariants.no_show
  }, [])

  const loadAppointments = useCallback(async () => {
    try {
      setLoading(true)
      const response = await appointmentService.getAppointments({ limit: 1000 })
      const data = response.data
      const nextAppointments = Array.isArray(data) ? data : data?.data || []
      setAppointments(nextAppointments)
    } catch (error) {
      console.error('Failed to load appointments:', error)
      toast.error('Failed to load appointments')
    } finally {
      setLoading(false)
    }
  }, [toast])

  useEffect(() => {
    loadAppointments()
  }, [loadAppointments])

  const events = useMemo(
    () =>
      appointments.map((appointment) => ({
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
          notes: appointment.notes,
        },
      })),
    [appointments, getEventTitle, getStatusColor]
  )

  const handleEventClick = (info) => {
    setSelectedEvent(info.event)
  }

  const handleDateClick = (info) => {
    const [date, time] = info.dateStr.split('T')
    const query = new URLSearchParams({ date })
    if (time) {
      query.set('time', time.substring(0, 5))
    }
    navigate(`/cp/appointments/create?${query.toString()}`)
  }

  const createAppointment = () => {
    navigate('/cp/appointments/create')
  }

  const editAppointment = (id) => {
    navigate(`/cp/appointments/create?edit=${id}`)
  }

  const formatDateTime = (date) => {
    if (!date) return ''
    return new Intl.DateTimeFormat('en-US', {
      month: 'short',
      day: 'numeric',
      year: 'numeric',
      hour: 'numeric',
      minute: '2-digit',
    }).format(new Date(date))
  }

  const formatTime = (date) => {
    if (!date) return ''
    return new Intl.DateTimeFormat('en-US', {
      hour: 'numeric',
      minute: '2-digit',
    }).format(new Date(date))
  }

  const calendarOptions = useMemo(
    () => ({
      plugins: [dayGridPlugin, timeGridPlugin, interactionPlugin],
      initialView: 'timeGridWeek',
      headerToolbar: {
        left: 'prev,next today',
        center: 'title',
        right: 'dayGridMonth,timeGridWeek,timeGridDay',
      },
      slotMinTime: '07:00:00',
      slotMaxTime: '19:00:00',
      allDaySlot: false,
      height: 'auto',
      events,
      eventClick: handleEventClick,
      dateClick: handleDateClick,
      eventDidMount: (info) => {
        info.el.style.cursor = 'pointer'
      },
      eventTimeFormat: {
        hour: 'numeric',
        minute: '2-digit',
        meridiem: 'short',
      },
    }),
    [events]
  )

  return (
    <div>
      <div className="mb-6 flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Appointment Calendar</h1>
          <p className="mt-1 text-sm text-gray-500">Visual calendar view of scheduled appointments</p>
        </div>
        <div className="flex gap-2">
          <Button variant="ghost" onClick={() => navigate('/cp/appointments')}>
            <svg className="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M4 6h16M4 10h16M4 14h16M4 18h16" />
            </svg>
            List View
          </Button>
          <Button variant="ghost" onClick={() => navigate('/cp/appointments/availability-settings')}>
            <svg className="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path
                strokeLinecap="round"
                strokeLinejoin="round"
                strokeWidth="2"
                d="M12 8c-1.657 0-3 1.343-3 3s1.343 3 3 3 3-1.343 3-3-1.343-3-3-3z M19.4 15a1.65 1.65 0 01-.33.5l1.5 2.6-1.73 1-1.5-2.6a6.99 6.99 0 01-1.7.7V20h-2v-2.8a6.99 6.99 0 01-1.7-.7l-1.5 2.6-1.73-1 1.5-2.6a1.65 1.65 0 01-.33-.5L4 14v-2l2.2-.4c.09-.25.2-.49.33-.72l-1.5-2.6 1.73-1 1.5 2.6c.54-.29 1.11-.51 1.7-.65V4h2v2.63c.59.14 1.16.36 1.7.65l1.5-2.6 1.73 1-1.5 2.6c.13.23.24.47.33.72L20 12v2z"
              />
            </svg>
            Availability
          </Button>
          <Button onClick={createAppointment}>
            <svg className="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 4v16m8-8H4" />
            </svg>
            New Appointment
          </Button>
        </div>
      </div>

      {loading ? (
        <div className="flex justify-center py-12">
          <Loading size="xl" text="Loading calendar..." />
        </div>
      ) : (
        <Card className="p-4">
          <FullCalendar {...calendarOptions} />
        </Card>
      )}

      <Modal
        open={!!selectedEvent}
        title="Appointment Details"
        onClose={() => setSelectedEvent(null)}
        footer={
          <div className="flex justify-end gap-2">
            <Button variant="outline" onClick={() => setSelectedEvent(null)}>
              Close
            </Button>
            {selectedEvent ? (
              <Button onClick={() => editAppointment(selectedEvent.extendedProps.id)}>Edit Appointment</Button>
            ) : null}
          </div>
        }
      >
        {selectedEvent ? (
          <div className="space-y-4">
            <div>
              <label className="text-sm font-medium text-gray-500">Status</label>
              <div className="mt-1">
                <Badge variant={getStatusVariant(selectedEvent.extendedProps.status)}>
                  {selectedEvent.extendedProps.status}
                </Badge>
              </div>
            </div>
            <div>
              <label className="text-sm font-medium text-gray-500">Date &amp; Time</label>
              <p className="mt-1 text-sm text-gray-900">
                {formatDateTime(selectedEvent.start)} - {formatTime(selectedEvent.end)}
              </p>
            </div>
            {selectedEvent.extendedProps.customer_id ? (
              <div>
                <label className="text-sm font-medium text-gray-500">Customer</label>
                <p className="mt-1 text-sm text-gray-900">
                  Customer #{selectedEvent.extendedProps.customer_id}
                </p>
              </div>
            ) : null}
            {selectedEvent.extendedProps.vehicle_id ? (
              <div>
                <label className="text-sm font-medium text-gray-500">Vehicle</label>
                <p className="mt-1 text-sm text-gray-900">
                  Vehicle #{selectedEvent.extendedProps.vehicle_id}
                </p>
              </div>
            ) : null}
            {selectedEvent.extendedProps.technician_id ? (
              <div>
                <label className="text-sm font-medium text-gray-500">Technician</label>
                <p className="mt-1 text-sm text-gray-900">Tech {selectedEvent.extendedProps.technician_id}</p>
              </div>
            ) : null}
            {selectedEvent.extendedProps.service_type ? (
              <div>
                <label className="text-sm font-medium text-gray-500">Service Type</label>
                <p className="mt-1 text-sm text-gray-900">{selectedEvent.extendedProps.service_type}</p>
              </div>
            ) : null}
            {selectedEvent.extendedProps.notes ? (
              <div>
                <label className="text-sm font-medium text-gray-500">Notes</label>
                <p className="mt-1 text-sm text-gray-900">{selectedEvent.extendedProps.notes}</p>
              </div>
            ) : null}
          </div>
        ) : null}
      </Modal>
    </div>
  )
}
