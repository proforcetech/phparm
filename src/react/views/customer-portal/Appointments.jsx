import { useCallback, useState } from 'react'

import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import appointmentService from '../../../services/appointment.service'

const formatTime = (value) => {
  const date = new Date(value)
  return date.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })
}

const today = new Date().toISOString().substring(0, 10)

export default function Appointments() {
  const [loading, setLoading] = useState(false)
  const [availability, setAvailability] = useState({ slots: [], closed: false, reason: '' })
  const [form, setForm] = useState({ date: today, technician_id: '' })

  const loadAvailability = useCallback(async () => {
    setLoading(true)
    try {
      const params = { date: form.date }
      if (form.technician_id) {
        params.technician_id = form.technician_id
      }
      const response = await appointmentService.fetchPublicAvailability(params)
      const data = response.data
      setAvailability({
        slots: data.slots || [],
        closed: Boolean(data.closed),
        reason: data.reason || '',
      })
    } finally {
      setLoading(false)
    }
  }, [form.date, form.technician_id])

  return (
    <div>
      <div className="mb-6 flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">My Appointments</h1>
          <p className="mt-1 text-sm text-gray-500">View and manage your appointments</p>
        </div>
        <Button onClick={loadAvailability}>
          <svg className="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
          </svg>
          Check Availability
        </Button>
      </div>

      <Card>
        <div className="space-y-4">
          <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
              <label className="block text-sm font-medium text-gray-700">Date</label>
              <Input
                modelValue={form.date}
                type="date"
                className="mt-1"
                onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, date: value }))}
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700">Preferred technician (optional)</label>
              <Input
                modelValue={form.technician_id}
                type="number"
                min="0"
                className="mt-1"
                placeholder="Technician ID"
                onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, technician_id: value }))}
              />
            </div>
            <div className="flex items-end">
              <Button className="w-full" loading={loading} onClick={loadAvailability}>
                Find slots
              </Button>
            </div>
          </div>

          {loading ? (
            <div className="text-sm text-gray-500">Loading availability...</div>
          ) : availability.closed ? (
            <div className="rounded-md bg-amber-50 p-4 text-amber-800">
              {availability.reason || 'We are closed on this date.'}
            </div>
          ) : (
            <div>
              <h3 className="text-sm font-semibold text-gray-700">Available slots</h3>
              {availability.slots.length ? (
                <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                  {availability.slots.map((slot) => (
                    <div
                      key={slot.start}
                      className="rounded-lg border border-gray-200 p-3 flex items-center justify-between"
                    >
                      <div>
                        <p className="font-semibold text-gray-900">
                          {formatTime(slot.start)} - {formatTime(slot.end)}
                        </p>
                        <p className="text-xs text-gray-500">{form.date}</p>
                      </div>
                      {slot.available ? <Badge variant="success">Open</Badge> : <Badge variant="warning">Booked</Badge>}
                    </div>
                  ))}
                </div>
              ) : (
                <p className="text-sm text-gray-500">No open slots for this date.</p>
              )}
            </div>
          )}
        </div>
      </Card>
    </div>
  )
}
