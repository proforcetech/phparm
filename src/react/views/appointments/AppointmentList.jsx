import { useCallback, useEffect, useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'

import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Select from '../../components/ui/Select'
import Table from '../../components/ui/Table'
import appointmentService from '../../../services/appointment.service'

const statusOptions = [
  { label: 'Scheduled', value: 'scheduled' },
  { label: 'Confirmed', value: 'confirmed' },
  { label: 'In progress', value: 'in_progress' },
  { label: 'Completed', value: 'completed' },
  { label: 'Cancelled', value: 'cancelled' },
]

const columns = [
  { key: 'id', label: 'ID' },
  { key: 'start_time', label: 'When' },
  { key: 'customer_id', label: 'Customer' },
  { key: 'vehicle_id', label: 'Vehicle' },
  { key: 'technician_id', label: 'Technician' },
  { key: 'status', label: 'Status' },
]

const statusVariant = (status) => {
  switch (status) {
    case 'confirmed':
    case 'in_progress':
      return 'primary'
    case 'completed':
      return 'success'
    case 'cancelled':
      return 'danger'
    default:
      return 'secondary'
  }
}

const formatTime = (value) => new Date(value).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })
const formatDate = (value) => new Date(value).toLocaleDateString()

export default function AppointmentList() {
  const navigate = useNavigate()
  const [loading, setLoading] = useState(false)
  const [appointments, setAppointments] = useState([])
  const [filters, setFilters] = useState({
    status: '',
    date: '',
    customer_id: '',
    vehicle_id: '',
    technician_id: '',
  })

  const loadAppointments = useCallback(async () => {
    setLoading(true)
    const params = {}
    if (filters.status) params.status = filters.status
    if (filters.date) params.date = filters.date
    if (filters.customer_id) params.customer_id = filters.customer_id
    if (filters.vehicle_id) params.vehicle_id = filters.vehicle_id
    if (filters.technician_id) params.technician_id = filters.technician_id

    try {
      const response = await appointmentService.getAppointments(params)
      setAppointments(response.data?.data || [])
    } finally {
      setLoading(false)
    }
  }, [filters])

  const changeStatus = async (id, status) => {
    await appointmentService.updateAppointmentStatus(id, status)
    await loadAppointments()
  }

  const cancelAppointment = async (id) => {
    await changeStatus(id, 'cancelled')
  }

  const calendarSummary = useMemo(() => {
    const days = []
    const today = new Date()
    for (let i = 0; i < 7; i += 1) {
      const date = new Date(today)
      date.setDate(today.getDate() + i)
      const key = date.toISOString().substring(0, 10)
      const items = appointments.filter((appt) => appt.start_time.startsWith(key))
      days.push({
        date: key,
        label: date.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' }),
        count: items.length,
        items: items.slice(0, 3),
      })
    }
    return days
  }, [appointments])

  useEffect(() => {
    loadAppointments()
  }, [filters, loadAppointments])

  return (
    <div>
      <div className="mb-6 flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Appointments</h1>
          <p className="mt-1 text-sm text-gray-500">Track booked work and availability</p>
        </div>
        <div className="flex gap-2">
          <Button variant="ghost" onClick={() => navigate('/cp/appointments/calendar')}>
            <svg className="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path
                strokeLinecap="round"
                strokeLinejoin="round"
                strokeWidth="2"
                d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"
              />
            </svg>
            Calendar View
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
          <Button onClick={() => navigate('/cp/appointments/create')}>
            <svg className="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 4v16m8-8H4" />
            </svg>
            New Appointment
          </Button>
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
        <Card className="lg:col-span-2">
          <div className="flex flex-col gap-4">
            <div className="grid grid-cols-1 md:grid-cols-5 gap-4">
              <div>
                <label className="block text-sm font-medium text-gray-700">Date</label>
                <Input
                  modelValue={filters.date}
                  type="date"
                  className="mt-1"
                  onUpdateModelValue={(value) => {
                    setFilters((prev) => ({ ...prev, date: value }))
                  }}
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700">Customer ID</label>
                <Input
                  modelValue={filters.customer_id}
                  type="number"
                  min="0"
                  placeholder="#"
                  onUpdateModelValue={(value) => {
                    setFilters((prev) => ({ ...prev, customer_id: value }))
                  }}
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700">Vehicle ID</label>
                <Input
                  modelValue={filters.vehicle_id}
                  type="number"
                  min="0"
                  placeholder="#"
                  onUpdateModelValue={(value) => {
                    setFilters((prev) => ({ ...prev, vehicle_id: value }))
                  }}
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700">Technician ID</label>
                <Input
                  modelValue={filters.technician_id}
                  type="number"
                  min="0"
                  placeholder="#"
                  onUpdateModelValue={(value) => {
                    setFilters((prev) => ({ ...prev, technician_id: value }))
                  }}
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700">Status</label>
                <Select
                  modelValue={filters.status}
                  options={statusOptions}
                  placeholder="Any"
                  onUpdateModelValue={(value) => {
                    setFilters((prev) => ({ ...prev, status: value }))
                  }}
                />
              </div>
            </div>

            <Table
              columns={columns}
              data={appointments}
              loading={loading}
              hoverable
              cellRenderers={{
                status: ({ value }) => <Badge variant={statusVariant(value)}>{value}</Badge>,
                start_time: ({ row }) => (
                  <div className="flex flex-col">
                    <span className="font-semibold">{formatDate(row.start_time)}</span>
                    <span className="text-xs text-gray-500">
                      {formatTime(row.start_time)} - {formatTime(row.end_time)}
                    </span>
                  </div>
                ),
                customer_id: ({ value }) =>
                  value ? <span className="text-sm">#{value}</span> : <span className="text-xs text-gray-500">Unassigned</span>,
                vehicle_id: ({ value }) =>
                  value ? <span className="text-sm">#{value}</span> : <span className="text-xs text-gray-500">N/A</span>,
                technician_id: ({ value }) =>
                  value ? <span>Tech {value}</span> : <span className="text-xs text-gray-500">Unassigned</span>,
              }}
              renderActions={(row) => (
                <div className="flex items-center gap-2" onClick={(event) => event.stopPropagation()}>
                  <Select
                    modelValue={row.status}
                    options={statusOptions}
                    className="w-36"
                    onUpdateModelValue={(value) => changeStatus(row.id, value)}
                  />
                  <Button
                    size="sm"
                    variant="secondary"
                    onClick={(event) => {
                      event.stopPropagation()
                      navigate(`/cp/appointments/create?clone=${row.id}`)
                    }}
                  >
                    Clone
                  </Button>
                  <Button
                    size="sm"
                    variant="danger"
                    onClick={(event) => {
                      event.stopPropagation()
                      cancelAppointment(row.id)
                    }}
                  >
                    Cancel
                  </Button>
                </div>
              )}
              renderEmpty={<p className="text-sm text-gray-500">No appointments match the current filters.</p>}
            />
          </div>
        </Card>

        <Card>
          <h3 className="text-lg font-semibold text-gray-900 mb-3">Upcoming calendar</h3>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            {calendarSummary.map((day) => (
              <div key={day.date} className="border rounded-lg p-3 flex flex-col gap-1">
                <div className="flex items-center justify-between">
                  <div>
                    <p className="text-sm font-semibold text-gray-900">{day.label}</p>
                    <p className="text-xs text-gray-500">{day.date}</p>
                  </div>
                  <Badge variant={day.count ? 'primary' : 'secondary'}>{day.count}</Badge>
                </div>
                {day.items.length ? (
                  <ul className="text-xs text-gray-700 space-y-1">
                    {day.items.map((item) => (
                      <li key={item.id} className="flex items-center justify-between">
                        <span>
                          {formatTime(item.start_time)} • #{item.id}
                        </span>
                        <Badge size="sm" variant={statusVariant(item.status)}>
                          {item.status}
                        </Badge>
                      </li>
                    ))}
                  </ul>
                ) : (
                  <p className="text-xs text-gray-500">No bookings</p>
                )}
              </div>
            ))}
          </div>
        </Card>
      </div>
    </div>
  )
}
