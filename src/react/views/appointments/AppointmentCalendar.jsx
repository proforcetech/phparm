import { useCallback, useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import FullCalendar from '@fullcalendar/react'
import dayGridPlugin from '@fullcalendar/daygrid'
import timeGridPlugin from '@fullcalendar/timegrid'
import interactionPlugin from '@fullcalendar/interaction'

import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Loading from '../../components/ui/Loading'
import appointmentService from '../../../services/appointment.service'
import { useToast } from '../../stores/toast.jsx'

const statusColors = {
  scheduled: '#3b82f6',
  confirmed: '#10b981',
  pending: '#f59e0b',
  completed: '#6b7280',
  cancelled: '#ef4444',
  'no-show': '#ef4444',
}

export default function AppointmentCalendar() {
  const navigate = useNavigate()
  const { error } = useToast()
  const [events, setEvents] = useState([])
  const [loading, setLoading] = useState(true)

  const loadAppointments = useCallback(async (start, end) => {
    setLoading(true)
    try {
      const params = {
        start_date: start?.toISOString(),
        end_date: end?.toISOString(),
        limit: 500,
      }
      const response = await appointmentService.getAppointments(params)
      const data = response?.data
      const appointments = Array.isArray(data) ? data : data?.data || []

      const calendarEvents = appointments.map((apt) => ({
        id: apt.id,
        title: apt.customer?.name || 'Appointment',
        start: apt.scheduled_date,
        end: apt.end_date || undefined,
        backgroundColor: statusColors[apt.status] || statusColors.scheduled,
        borderColor: statusColors[apt.status] || statusColors.scheduled,
        extendedProps: {
          status: apt.status,
          customer: apt.customer,
          service: apt.service_type,
        },
      }))

      setEvents(calendarEvents)
    } catch {
      error('Failed to load appointments')
    } finally {
      setLoading(false)
    }
  }, [error])

  useEffect(() => {
    const now = new Date()
    const start = new Date(now.getFullYear(), now.getMonth(), 1)
    const end = new Date(now.getFullYear(), now.getMonth() + 1, 0)
    loadAppointments(start, end)
  }, [loadAppointments])

  const handleDateChange = (info) => {
    loadAppointments(info.start, info.end)
  }

  const handleEventClick = (info) => {
    navigate(`/cp/appointments/${info.event.id}`)
  }

  const handleDateClick = (info) => {
    navigate(`/cp/appointments/book?date=${info.dateStr}`)
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Appointment Calendar</h1>
          <p className="mt-1 text-sm text-gray-500">View and manage appointments</p>
        </div>
        <div className="flex gap-2">
          <Button variant="secondary" onClick={() => navigate('/cp/appointments')}>
            <svg className="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 6h16M4 10h16M4 14h16M4 18h16" />
            </svg>
            List View
          </Button>
          <Button onClick={() => navigate('/cp/appointments/book')}>
            <svg className="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
            </svg>
            Book Appointment
          </Button>
        </div>
      </div>

      <Card className="p-0 overflow-hidden">
        {loading ? (
          <div className="py-20 flex justify-center">
            <Loading text="Loading calendar..." />
          </div>
        ) : (
          <div className="p-4">
            <FullCalendar
              plugins={[dayGridPlugin, timeGridPlugin, interactionPlugin]}
              initialView="dayGridMonth"
              headerToolbar={{
                left: 'prev,next today',
                center: 'title',
                right: 'dayGridMonth,timeGridWeek,timeGridDay',
              }}
              events={events}
              eventClick={handleEventClick}
              dateClick={handleDateClick}
              datesSet={handleDateChange}
              height="auto"
              eventTimeFormat={{
                hour: '2-digit',
                minute: '2-digit',
                meridiem: 'short',
              }}
            />
          </div>
        )}
      </Card>

      <Card>
        <h3 className="text-sm font-medium text-gray-900 mb-3">Legend</h3>
        <div className="flex flex-wrap gap-4">
          {Object.entries(statusColors).map(([status, color]) => (
            <div key={status} className="flex items-center gap-2">
              <div className="w-3 h-3 rounded-full" style={{ backgroundColor: color }} />
              <span className="text-sm text-gray-600 capitalize">{status}</span>
            </div>
          ))}
        </div>
      </Card>
    </div>
  )
}
