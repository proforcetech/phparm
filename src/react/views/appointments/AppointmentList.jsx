import { useCallback, useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'

import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Modal from '../../components/ui/Modal'
import Select from '../../components/ui/Select'
import appointmentService from '../../../services/appointment.service'
import { useToast } from '../../stores/toast.jsx'

const perPage = 20

const formatDate = (date) => {
  if (!date) return '—'
  return new Date(date).toLocaleDateString()
}

const formatTime = (date) => {
  if (!date) return ''
  return new Date(date).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
}

const statusVariant = (status) => {
  switch (status) {
    case 'completed':
      return 'success'
    case 'scheduled':
    case 'confirmed':
      return 'info'
    case 'pending':
      return 'warning'
    case 'cancelled':
    case 'no-show':
      return 'danger'
    default:
      return 'default'
  }
}

const statusOptions = [
  { value: '', label: 'All Statuses' },
  { value: 'scheduled', label: 'Scheduled' },
  { value: 'confirmed', label: 'Confirmed' },
  { value: 'pending', label: 'Pending' },
  { value: 'completed', label: 'Completed' },
  { value: 'cancelled', label: 'Cancelled' },
]

export default function AppointmentList() {
  const navigate = useNavigate()
  const { success, error } = useToast()
  const [appointments, setAppointments] = useState([])
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('')
  const [page, setPage] = useState(1)
  const [deleteModal, setDeleteModal] = useState({ open: false, appointment: null })
  const [deleting, setDeleting] = useState(false)

  const loadAppointments = useCallback(async () => {
    setLoading(true)
    try {
      const params = {
        limit: perPage,
        offset: (page - 1) * perPage,
      }
      if (search.trim()) {
        params.query = search.trim()
      }
      if (status) {
        params.status = status
      }
      const response = await appointmentService.getAppointments(params)
      const data = response?.data
      setAppointments(Array.isArray(data) ? data : data?.data || [])
    } catch {
      error('Failed to load appointments')
      setAppointments([])
    } finally {
      setLoading(false)
    }
  }, [page, search, status, error])

  useEffect(() => {
    loadAppointments()
  }, [loadAppointments])

  const handleSearch = (e) => {
    e.preventDefault()
    setPage(1)
    loadAppointments()
  }

  const handleDelete = async () => {
    if (!deleteModal.appointment) return
    setDeleting(true)
    try {
      await appointmentService.deleteAppointment(deleteModal.appointment.id)
      success('Appointment deleted successfully')
      setDeleteModal({ open: false, appointment: null })
      loadAppointments()
    } catch {
      error('Failed to delete appointment')
    } finally {
      setDeleting(false)
    }
  }

  const hasNext = appointments.length === perPage

  return (
    <div className="space-y-6">
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Appointments</h1>
          <p className="mt-1 text-sm text-gray-500">Manage scheduled appointments</p>
        </div>
        <div className="flex gap-2">
          <Button variant="secondary" onClick={() => navigate('/cp/appointments/calendar')}>
            <svg className="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
            </svg>
            Calendar
          </Button>
          <Button onClick={() => navigate('/cp/appointments/book')}>
            <svg className="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
            </svg>
            Book Appointment
          </Button>
        </div>
      </div>

      <Card>
        <form onSubmit={handleSearch} className="mb-4">
          <div className="flex flex-col sm:flex-row gap-2">
            <Input
              value={search}
              onUpdateModelValue={setSearch}
              placeholder="Search by customer name..."
              className="flex-1"
            />
            <Select
              value={status}
              onChange={(e) => setStatus(e.target.value)}
              options={statusOptions}
            />
            <Button type="submit" variant="secondary">Search</Button>
          </div>
        </form>

        {loading ? (
          <div className="py-10 flex justify-center">
            <Loading text="Loading appointments..." />
          </div>
        ) : appointments.length === 0 ? (
          <div className="text-center py-12">
            <svg className="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
            </svg>
            <h3 className="mt-2 text-sm font-medium text-gray-900">No appointments found</h3>
            <p className="mt-1 text-sm text-gray-500">Get started by booking a new appointment.</p>
            <div className="mt-4">
              <Button onClick={() => navigate('/cp/appointments/book')}>Book Appointment</Button>
            </div>
          </div>
        ) : (
          <>
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-gray-200">
                <thead className="bg-gray-50">
                  <tr>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Customer</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Time</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Service</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                    <th className="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-200 bg-white">
                  {appointments.map((appointment) => (
                    <tr key={appointment.id} className="hover:bg-gray-50">
                      <td className="px-4 py-3">
                        <Link to={`/cp/appointments/${appointment.id}`} className="text-primary-600 hover:text-primary-500 font-medium">
                          {appointment.customer?.name || 'Unknown Customer'}
                        </Link>
                      </td>
                      <td className="px-4 py-3 text-sm text-gray-500">
                        {formatDate(appointment.scheduled_date)}
                      </td>
                      <td className="px-4 py-3 text-sm text-gray-500">
                        {formatTime(appointment.scheduled_date)}
                      </td>
                      <td className="px-4 py-3 text-sm text-gray-500">
                        {appointment.service_type || appointment.notes || '—'}
                      </td>
                      <td className="px-4 py-3">
                        <Badge size="sm" variant={statusVariant(appointment.status)}>
                          {appointment.status}
                        </Badge>
                      </td>
                      <td className="px-4 py-3 text-right">
                        <div className="flex justify-end gap-2">
                          <Button size="sm" variant="ghost" onClick={() => navigate(`/cp/appointments/${appointment.id}`)}>
                            View
                          </Button>
                          <Button size="sm" variant="ghost" className="text-red-600 hover:text-red-700" onClick={() => setDeleteModal({ open: true, appointment })}>
                            Cancel
                          </Button>
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            <div className="flex justify-between items-center mt-4 pt-4 border-t">
              <span className="text-sm text-gray-500">
                Page {page}
              </span>
              <div className="flex gap-2">
                <Button variant="ghost" size="sm" disabled={page === 1} onClick={() => setPage(page - 1)}>
                  Previous
                </Button>
                <Button variant="ghost" size="sm" disabled={!hasNext} onClick={() => setPage(page + 1)}>
                  Next
                </Button>
              </div>
            </div>
          </>
        )}
      </Card>

      <Modal
        open={deleteModal.open}
        title="Cancel Appointment"
        onClose={() => setDeleteModal({ open: false, appointment: null })}
      >
        <p className="text-sm text-gray-600 mb-4">
          Are you sure you want to cancel this appointment for <strong>{deleteModal.appointment?.customer?.name}</strong>?
        </p>
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={() => setDeleteModal({ open: false, appointment: null })}>
            Keep
          </Button>
          <Button variant="danger" loading={deleting} onClick={handleDelete}>
            Cancel Appointment
          </Button>
        </div>
      </Modal>
    </div>
  )
}
