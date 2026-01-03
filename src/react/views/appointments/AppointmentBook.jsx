import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'

import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Select from '../../components/ui/Select'
import Textarea from '../../components/ui/Textarea'
import Autocomplete from '../../components/ui/Autocomplete'
import appointmentService from '../../../services/appointment.service'
import technicianService from '../../../services/technician.service'
import customerService from '../../../services/customer.service'

const statusOptions = [
  { label: 'Scheduled', value: 'scheduled' },
  { label: 'Confirmed', value: 'confirmed' },
  { label: 'In progress', value: 'in_progress' },
  { label: 'Completed', value: 'completed' },
  { label: 'Cancelled', value: 'cancelled' },
]

const defaultFormState = {
  date: new Date().toISOString().substring(0, 10),
  technician_id: null,
  customer_id: null,
  vehicle_id: null,
  status: 'scheduled',
  notes: '',
}

export default function AppointmentBook() {
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()
  const [loading, setLoading] = useState(false)
  const [saving, setSaving] = useState(false)
  const [loadingVehicles, setLoadingVehicles] = useState(false)
  const [availability, setAvailability] = useState({ slots: [], closed: false, reason: '' })
  const [vehicleOptions, setVehicleOptions] = useState([])
  const [form, setForm] = useState(defaultFormState)
  const [selectedSlot, setSelectedSlot] = useState(null)
  const selectedSlotRef = useRef(selectedSlot)

  const formatTime = useCallback((value) => {
    const date = new Date(value)
    return date.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })
  }, [])

  const loadAvailability = useCallback(async (overrides = {}) => {
    setLoading(true)
    try {
      const params = { date: overrides.date ?? form.date }
      const technicianId = overrides.technician_id ?? form.technician_id
      if (technicianId) {
        params.technician_id = technicianId
      }
      const response = await appointmentService.fetchAvailability(params)
      const data = response.data
      const slots = data.slots || []

      setAvailability({
        slots,
        closed: Boolean(data.closed),
        reason: data.reason || '',
      })

      if (!slots.find((slot) => selectedSlotRef.current?.start === slot.start)) {
        setSelectedSlot(null)
      }
    } finally {
      setLoading(false)
    }
  }, [form.date, form.technician_id])

  const selectSlot = (slot) => {
    if (!slot.available) return
    setSelectedSlot(slot)
  }

  const bookAppointment = async () => {
    if (!selectedSlot) return
    setSaving(true)
    try {
      await appointmentService.createAppointment({
        start_time: selectedSlot.start,
        end_time: selectedSlot.end,
        technician_id: form.technician_id || null,
        customer_id: form.customer_id || null,
        vehicle_id: form.vehicle_id || null,
        status: form.status,
        notes: form.notes,
      })
      setSelectedSlot(null)
      await loadAvailability()
    } finally {
      setSaving(false)
    }
  }

  const loadCustomerVehicles = useCallback(async (customerId) => {
    if (!customerId) {
      setVehicleOptions([])
      return
    }

    setLoadingVehicles(true)
    try {
      const vehicles = await customerService.getCustomerVehicles(customerId)
      const options = vehicles.map((vehicle) => ({
        value: vehicle.id,
        label: `${vehicle.year || ''} ${vehicle.make || ''} ${vehicle.model || ''} ${
          vehicle.vin ? `(${vehicle.vin.substring(0, 8)}...)` : ''
        }`.trim(),
      }))
      setVehicleOptions(options)
      setForm((prev) => {
        if (prev.vehicle_id && !options.find((option) => option.value === prev.vehicle_id)) {
          return { ...prev, vehicle_id: null }
        }
        return prev
      })
    } catch (error) {
      console.error('Failed to load vehicles:', error)
      setVehicleOptions([])
    } finally {
      setLoadingVehicles(false)
    }
  }, [])

  const onCustomerSelect = async (customer) => {
    console.log('Selected customer:', customer)
    await loadCustomerVehicles(customer.id)
  }

  const onTechnicianSelect = (technician) => {
    loadAvailability({ technician_id: technician.id })
  }

  const searchTechnicians = async (query) => {
    try {
      return await technicianService.searchTechnicians(query)
    } catch (error) {
      console.error('Technician search failed:', error)
      return []
    }
  }

  const searchCustomers = async (query) => {
    try {
      return await customerService.searchCustomers(query)
    } catch (error) {
      console.error('Customer search failed:', error)
      return []
    }
  }

  useEffect(() => {
    selectedSlotRef.current = selectedSlot
  }, [selectedSlot])

  useEffect(() => {
    loadAvailability()
  }, [loadAvailability])

  useEffect(() => {
    const preloadFromClone = async () => {
      const cloneId = searchParams.get('clone')
      if (!cloneId) return
      const response = await appointmentService.getAppointment(cloneId)
      const existing = response.data
      setForm((prev) => ({
        ...prev,
        date: existing.start_time.substring(0, 10),
        technician_id: existing.technician_id || null,
        customer_id: existing.customer_id || null,
        vehicle_id: existing.vehicle_id || null,
        status: existing.status,
        notes: existing.notes || '',
      }))
      await loadAvailability()
    }

    preloadFromClone()
  }, [loadAvailability, searchParams])

  useEffect(() => {
    if (form.customer_id) {
      loadCustomerVehicles(form.customer_id)
    } else {
      setVehicleOptions([])
      setForm((prev) => ({ ...prev, vehicle_id: null }))
    }
  }, [form.customer_id, loadCustomerVehicles])

  const slotClasses = useMemo(
    () =>
      (slot) =>
        [
          'rounded-lg border border-gray-200 p-3 shadow-sm flex items-center justify-between text-left',
          selectedSlot?.start === slot.start ? 'ring-2 ring-primary-500' : '',
          slot.available ? 'bg-white' : 'bg-gray-50',
        ]
          .filter(Boolean)
          .join(' '),
    [selectedSlot]
  )

  return (
    <div>
      <div className="mb-6">
        <div className="flex items-center gap-4">
          <Button variant="ghost" onClick={() => navigate('/cp/appointments')}>
            <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
            </svg>
          </Button>
          <div>
            <h1 className="text-2xl font-bold text-gray-900">Book Appointment</h1>
            <p className="mt-1 text-sm text-gray-500">Schedule a new appointment</p>
          </div>
        </div>
      </div>

      <Card>
        <div className="space-y-6">
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label className="block text-sm font-medium text-gray-700">Date *</label>
              <Input
                modelValue={form.date}
                type="date"
                className="mt-1"
                required
                onUpdateModelValue={(value) => {
                  setForm((prev) => ({ ...prev, date: value }))
                }}
              />
            </div>
            <div>
              <Autocomplete
                modelValue={form.technician_id}
                label="Technician *"
                placeholder="Search technician by name or email..."
                searchFn={searchTechnicians}
                itemValue={(item) => item.id}
                itemLabel={(item) => item.name}
                itemSubtext={(item) => item.email}
                required
                onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, technician_id: value }))}
                onSelect={onTechnicianSelect}
              />
            </div>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <Autocomplete
                modelValue={form.customer_id}
                label="Customer *"
                placeholder="Search by name, email, phone, or ID..."
                searchFn={searchCustomers}
                itemValue={(item) => item.id}
                itemLabel={(item) => item.name}
                itemSubtext={(item) => `${item.email || ''} ${item.phone ? `• ${item.phone}` : ''}`}
                required
                onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, customer_id: value }))}
                onSelect={onCustomerSelect}
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700">Vehicle *</label>
              <Select
                modelValue={form.vehicle_id}
                options={vehicleOptions}
                disabled={!form.customer_id || loadingVehicles}
                required
                className="mt-1"
                onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, vehicle_id: value }))}
              />
              {loadingVehicles ? (
                <p className="mt-1 text-xs text-gray-500">Loading vehicles...</p>
              ) : null}
              {!loadingVehicles && form.customer_id && vehicleOptions.length === 0 ? (
                <p className="mt-1 text-xs text-amber-600">No vehicles found for this customer</p>
              ) : null}
            </div>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
              <label className="block text-sm font-medium text-gray-700">Status</label>
              <Select
                modelValue={form.status}
                options={statusOptions}
                onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, status: value }))}
              />
            </div>
            <div className="md:col-span-2">
              <label className="block text-sm font-medium text-gray-700">Notes</label>
              <Textarea
                modelValue={form.notes}
                rows={2}
                placeholder="Add visit notes or context"
                onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, notes: value }))}
              />
            </div>
          </div>

          <div className="flex items-center justify-between">
            <p className="text-sm text-gray-600">Choose an available slot to book the appointment.</p>
            <div className="flex gap-2">
              <Button variant="secondary" loading={loading} onClick={loadAvailability}>
                Refresh
              </Button>
              <Button disabled={!selectedSlot} loading={saving} onClick={bookAppointment}>
                Book appointment
              </Button>
            </div>
          </div>

          {loading ? <div className="text-sm text-gray-500">Loading slots...</div> : null}
          {!loading && availability.closed ? (
            <div className="rounded-md bg-amber-50 p-4 text-amber-800">
              {availability.reason || 'No availability for the selected date.'}
            </div>
          ) : null}
          {!loading && !availability.closed ? (
            <div>
              <h3 className="text-sm font-semibold text-gray-700">Available Slots</h3>
              {availability.slots.length ? (
                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                  {availability.slots.map((slot) => (
                    <button
                      key={slot.start}
                      className={slotClasses(slot)}
                      disabled={!slot.available}
                      onClick={() => selectSlot(slot)}
                      type="button"
                    >
                      <div>
                        <p className="font-semibold text-gray-900">
                          {formatTime(slot.start)} - {formatTime(slot.end)}
                        </p>
                        <p className="text-xs text-gray-500">{form.date}</p>
                      </div>
                      <Badge variant={slot.available ? 'success' : 'warning'}>
                        {slot.available ? 'Open' : 'Booked'}
                      </Badge>
                    </button>
                  ))}
                </div>
              ) : (
                <div className="text-sm text-gray-500">No slots available for the selected date.</div>
              )}
            </div>
          ) : null}
        </div>
      </Card>
    </div>
  )
}
