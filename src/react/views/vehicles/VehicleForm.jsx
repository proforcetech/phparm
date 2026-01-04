import { useCallback, useEffect, useMemo, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'

import Autocomplete from '../../components/ui/Autocomplete'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Textarea from '../../components/ui/Textarea'
import {
  decodeVin,
  getDrives,
  getEngines,
  getMakes,
  getModels,
  getTransmissions,
  getTrims,
  getYears,
} from '../../../services/vehicle-master.service'
import { createCustomerVehicle, updateCustomerVehicle } from '../../../services/customer-vehicle.service'
import { getVehicle } from '../../../services/vehicle.service'
import { getCustomer, listCustomers } from '../../../services/customer.service'
import { useToast } from '../../stores/toast'
import { normalizeVinData } from '../../../utils/vin'

export default function VehicleForm() {
  const navigate = useNavigate()
  const { id } = useParams()
  const { success: toastSuccess, error: toastError } = useToast()

  const [saving, setSaving] = useState(false)
  const [decoding, setDecoding] = useState(false)
  const [success, setSuccess] = useState('')
  const [error, setError] = useState('')
  const [vinError, setVinError] = useState('')
  const [vinSuccess, setVinSuccess] = useState('')

  const [years, setYears] = useState([])
  const [makes, setMakes] = useState([])
  const [models, setModels] = useState([])
  const [engines, setEngines] = useState([])
  const [transmissions, setTransmissions] = useState([])
  const [drives, setDrives] = useState([])
  const [trims, setTrims] = useState([])

  const [form, setForm] = useState({
    customer_id: null,
    year: '',
    make: '',
    model: '',
    engine: '',
    transmission: '',
    drive: '',
    trim: '',
    vin: '',
    license_plate: '',
    mileage_in: null,
    mileage_out: null,
    notes: '',
  })

  const isEditing = Boolean(id)

  const loadYears = useCallback(async () => {
    try {
      const data = await getYears()
      setYears(Array.isArray(data) ? data : [])
    } catch (err) {
      console.error('Failed to load years:', err)
    }
  }, [])

  const loadMakes = useCallback(async (year) => {
    if (!year) {
      setMakes([])
      return
    }
    try {
      const data = await getMakes(year)
      setMakes(Array.isArray(data) ? data : [])
    } catch (err) {
      console.error('Failed to load makes:', err)
    }
  }, [])

  const loadModels = useCallback(async (year, make) => {
    if (!year || !make) {
      setModels([])
      return
    }
    try {
      const data = await getModels(year, make)
      setModels(Array.isArray(data) ? data : [])
    } catch (err) {
      console.error('Failed to load models:', err)
    }
  }, [])

  const loadEngines = useCallback(async (year, make, model) => {
    if (!year || !make || !model) {
      setEngines([])
      return
    }
    try {
      const data = await getEngines(year, make, model)
      setEngines(Array.isArray(data) ? data : [])
    } catch (err) {
      console.error('Failed to load engines:', err)
    }
  }, [])

  const loadTransmissions = useCallback(async (year, make, model, engine) => {
    if (!year || !make || !model || !engine) {
      setTransmissions([])
      return
    }
    try {
      const data = await getTransmissions(year, make, model, engine)
      setTransmissions(Array.isArray(data) ? data : [])
    } catch (err) {
      console.error('Failed to load transmissions:', err)
      setTransmissions([])
      setDrives([])
      setTrims([])
      toastError('Unable to load transmissions for the selected engine. Please try again.')
    }
  }, [toastError])

  const loadDrives = useCallback(async (year, make, model, engine, transmission) => {
    if (!year || !make || !model || !engine || !transmission) {
      setDrives([])
      return
    }
    try {
      const data = await getDrives(year, make, model, engine, transmission)
      setDrives(Array.isArray(data) ? data : [])
    } catch (err) {
      console.error('Failed to load drives:', err)
    }
  }, [])

  const loadTrims = useCallback(async (year, make, model, engine, transmission, drive) => {
    if (!year || !make || !model || !engine || !transmission || !drive) {
      setTrims([])
      return
    }
    try {
      const data = await getTrims(year, make, model, engine, transmission, drive)
      setTrims(Array.isArray(data) ? data : [])
    } catch (err) {
      console.error('Failed to load trims:', err)
    }
  }, [])

  useEffect(() => {
    loadYears()
  }, [loadYears])

  const onYearChange = async (value) => {
    setForm((prev) => ({
      ...prev,
      year: value ? Number(value) : '',
      make: '',
      model: '',
      engine: '',
      transmission: '',
      drive: '',
      trim: '',
    }))
    setModels([])
    setEngines([])
    setTransmissions([])
    setDrives([])
    setTrims([])
    await loadMakes(value)
  }

  const onMakeChange = async (value, yearOverride) => {
    const yearValue = yearOverride ?? form.year
    setForm((prev) => ({
      ...prev,
      make: value,
      model: '',
      engine: '',
      transmission: '',
      drive: '',
      trim: '',
    }))
    setEngines([])
    setTransmissions([])
    setDrives([])
    setTrims([])
    await loadModels(yearValue, value)
  }

  const onModelChange = async (value, yearOverride, makeOverride) => {
    const yearValue = yearOverride ?? form.year
    const makeValue = makeOverride ?? form.make
    setForm((prev) => ({
      ...prev,
      model: value,
      engine: '',
      transmission: '',
      drive: '',
      trim: '',
    }))
    setTransmissions([])
    setDrives([])
    setTrims([])
    await loadEngines(yearValue, makeValue, value)
  }

  const onEngineChange = async (value, yearOverride, makeOverride, modelOverride) => {
    const yearValue = yearOverride ?? form.year
    const makeValue = makeOverride ?? form.make
    const modelValue = modelOverride ?? form.model
    setForm((prev) => ({
      ...prev,
      engine: value,
      transmission: '',
      drive: '',
      trim: '',
    }))
    setDrives([])
    setTrims([])
    await loadTransmissions(yearValue, makeValue, modelValue, value)
  }

  const onTransmissionChange = async (value, yearOverride, makeOverride, modelOverride, engineOverride) => {
    const yearValue = yearOverride ?? form.year
    const makeValue = makeOverride ?? form.make
    const modelValue = modelOverride ?? form.model
    const engineValue = engineOverride ?? form.engine
    setForm((prev) => ({
      ...prev,
      transmission: value,
      drive: '',
      trim: '',
    }))
    setTrims([])
    await loadDrives(yearValue, makeValue, modelValue, engineValue, value)
  }

  const onDriveChange = async (value, yearOverride, makeOverride, modelOverride, engineOverride, transmissionOverride) => {
    const yearValue = yearOverride ?? form.year
    const makeValue = makeOverride ?? form.make
    const modelValue = modelOverride ?? form.model
    const engineValue = engineOverride ?? form.engine
    const transmissionValue = transmissionOverride ?? form.transmission
    setForm((prev) => ({
      ...prev,
      drive: value,
      trim: '',
    }))
    await loadTrims(yearValue, makeValue, modelValue, engineValue, transmissionValue, value)
  }

  const loadVehicle = useCallback(async () => {
    if (!id) return
    try {
      const vehicle = await getVehicle(id)
      setForm({
        customer_id: vehicle.customer_id,
        year: vehicle.year,
        make: vehicle.make,
        model: vehicle.model,
        engine: vehicle.engine || '',
        transmission: vehicle.transmission || '',
        drive: vehicle.drive || '',
        trim: vehicle.trim || '',
        vin: vehicle.vin || '',
        license_plate: vehicle.license_plate || '',
        mileage_in: vehicle.mileage_in || null,
        mileage_out: vehicle.mileage_out || null,
        notes: vehicle.notes || '',
      })

      if (vehicle.year) await loadMakes(vehicle.year)
      if (vehicle.make) await loadModels(vehicle.year, vehicle.make)
      if (vehicle.model) await loadEngines(vehicle.year, vehicle.make, vehicle.model)
      if (vehicle.engine) await loadTransmissions(vehicle.year, vehicle.make, vehicle.model, vehicle.engine)
      if (vehicle.transmission) await loadDrives(vehicle.year, vehicle.make, vehicle.model, vehicle.engine, vehicle.transmission)
      if (vehicle.drive) await loadTrims(vehicle.year, vehicle.make, vehicle.model, vehicle.engine, vehicle.transmission, vehicle.drive)
    } catch (err) {
      setError('Failed to load vehicle')
      console.error(err)
    }
  }, [id, loadDrives, loadEngines, loadMakes, loadModels, loadTransmissions, loadTrims])

  useEffect(() => {
    if (isEditing) {
      loadVehicle()
    }
  }, [isEditing, loadVehicle])

  const decodeVinNumber = async () => {
    if (!form.vin || form.vin.length < 17) {
      setVinError('VIN must be at least 17 characters')
      return
    }

    setDecoding(true)
    setVinError('')
    setVinSuccess('')

    try {
      const decoded = normalizeVinData(await decodeVin(form.vin))

      if (decoded.year) {
        await onYearChange(decoded.year)
      }
      if (decoded.make) {
        await onMakeChange(decoded.make, decoded.year ?? form.year)
      }
      if (decoded.model) {
        await onModelChange(decoded.model, decoded.year ?? form.year, decoded.make ?? form.make)
      }
      if (decoded.engine) {
        await onEngineChange(decoded.engine, decoded.year ?? form.year, decoded.make ?? form.make, decoded.model ?? form.model)
      }
      if (decoded.transmission) {
        await onTransmissionChange(
          decoded.transmission,
          decoded.year ?? form.year,
          decoded.make ?? form.make,
          decoded.model ?? form.model,
          decoded.engine ?? form.engine
        )
      }
      if (decoded.drive) {
        await onDriveChange(
          decoded.drive,
          decoded.year ?? form.year,
          decoded.make ?? form.make,
          decoded.model ?? form.model,
          decoded.engine ?? form.engine,
          decoded.transmission ?? form.transmission
        )
      }
      if (decoded.trim) {
        setForm((prev) => ({
          ...prev,
          trim: decoded.trim,
        }))
      }

      setVinSuccess('VIN decoded successfully!')
      toastSuccess('VIN decoded successfully!')
    } catch (err) {
      setVinError(err.response?.data?.message || 'Failed to decode VIN')
      toastError('Failed to decode VIN')
      console.error(err)
    } finally {
      setDecoding(false)
    }
  }

  const save = async (event) => {
    event.preventDefault()
    setSaving(true)
    setError('')
    setSuccess('')

    if (!form.customer_id) {
      setError('Please select a customer before saving.')
      setSaving(false)
      return
    }

    try {
      if (isEditing) {
        await updateCustomerVehicle(form.customer_id, id, form)
        setSuccess('Vehicle updated successfully!')
        toastSuccess('Vehicle updated successfully!')
      } else {
        await createCustomerVehicle(form.customer_id, form)
        setSuccess('Vehicle added to customer garage successfully!')
        toastSuccess('Vehicle added to customer garage successfully!')
      }

      setTimeout(() => {
        navigate('/cp/vehicles')
      }, 1500)
    } catch (err) {
      setError(err.response?.data?.message || 'Failed to save vehicle')
      toastError('Failed to save vehicle')
      console.error(err)
    } finally {
      setSaving(false)
    }
  }

  const searchCustomers = useCallback(async (query) => {
    if (!query) return []
    try {
      const results = await listCustomers({ query })
      const list = Array.isArray(results) ? results : results?.data || []
      if (list.length === 0 && /^\d+$/.test(query)) {
        const customer = await getCustomer(query)
        return customer ? [customer] : []
      }
      return list
    } catch (err) {
      console.error('Failed to search customers:', err)
      return []
    }
  }, [])

  const customerLabel = useMemo(() => {
    if (!form.customer_id) return ''
    return `Customer #${form.customer_id}`
  }, [form.customer_id])

  return (
    <div>
      <div className="mb-6 flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">{isEditing ? 'Edit Customer Vehicle' : 'Add Vehicle to Customer Garage'}</h1>
          <p className="mt-1 text-sm text-gray-500">
            {isEditing ? 'Update vehicle information' : 'Add a vehicle to a customer\'s garage'}
          </p>
        </div>
        <Button variant="secondary" onClick={() => navigate('/cp/vehicles')}>Back to list</Button>
      </div>

      <Card className="max-w-5xl">
        <form className="space-y-6" onSubmit={save}>
          <div>
            <Autocomplete
              modelValue={form.customer_id}
              label="Customer"
              placeholder="Search by name, email, phone, or ID"
              searchFn={searchCustomers}
              itemValue={(item) => item.id}
              itemLabel={(item) => item.name || `Customer #${item.id}`}
              itemSubtext={(item) => `${item.email || 'No email'} • ${item.phone || 'No phone'}`}
              required
              helperText="Search for a customer by name, email, phone, or ID"
              onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, customer_id: value }))}
            />
            {customerLabel ? (
              <input type="hidden" value={customerLabel} readOnly />
            ) : null}
          </div>

          <div className="border-t border-gray-200 pt-6">
            <h3 className="text-lg font-medium text-gray-900 mb-4">VIN Information</h3>
            <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
              <div className="md:col-span-2">
                <label className="block text-sm font-medium text-gray-700">VIN</label>
                <Input
                  value={form.vin}
                  placeholder="1HGBH41JXMN109186"
                  maxlength={30}
                  onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, vin: value }))}
                />
                <p className="mt-1 text-xs text-gray-500">Optional - Enter vehicle identification number</p>
              </div>
              <div className="flex items-end gap-2">
                <Button
                  type="button"
                  variant="secondary"
                  onClick={decodeVinNumber}
                  loading={decoding}
                  disabled={!form.vin || form.vin.length < 17}
                  className="flex-1"
                >
                  <svg className="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                  </svg>
                  Decode
                </Button>
              </div>
            </div>
            {vinError ? <p className="mt-2 text-sm text-red-600">{vinError}</p> : null}
            {vinSuccess ? <p className="mt-2 text-sm text-green-600">{vinSuccess}</p> : null}
          </div>

          <div className="border-t border-gray-200 pt-6">
            <h3 className="text-lg font-medium text-gray-900 mb-4">Vehicle Specifications</h3>
            <p className="text-sm text-gray-600 mb-4">Select vehicle from database or VIN decoder will populate these fields</p>

            <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
              <div>
                <label className="block text-sm font-medium text-gray-700">Year *</label>
                <select
                  value={form.year || ''}
                  required
                  onChange={(event) => onYearChange(event.target.value)}
                  className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                >
                  <option value="">Select Year</option>
                  {years.map((year) => (
                    <option key={year} value={year}>{year}</option>
                  ))}
                </select>
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700">Make *</label>
                <select
                  value={form.make}
                  required
                  onChange={(event) => onMakeChange(event.target.value)}
                  disabled={!form.year}
                  className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                >
                  <option value="">Select Make</option>
                  {makes.map((make) => (
                    <option key={make} value={make}>{make}</option>
                  ))}
                </select>
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700">Model *</label>
                <select
                  value={form.model}
                  required
                  onChange={(event) => onModelChange(event.target.value)}
                  disabled={!form.make}
                  className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                >
                  <option value="">Select Model</option>
                  {models.map((model) => (
                    <option key={model} value={model}>{model}</option>
                  ))}
                </select>
              </div>
            </div>

            <div className="grid grid-cols-1 gap-4 md:grid-cols-4 mt-4">
              <div>
                <label className="block text-sm font-medium text-gray-700">Engine *</label>
                <select
                  value={form.engine}
                  required
                  onChange={(event) => onEngineChange(event.target.value)}
                  disabled={!form.model}
                  className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                >
                  <option value="">Select Engine</option>
                  {engines.map((engine) => (
                    <option key={engine} value={engine}>{engine}</option>
                  ))}
                </select>
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700">Transmission *</label>
                <select
                  value={form.transmission}
                  required
                  onChange={(event) => onTransmissionChange(event.target.value)}
                  disabled={!form.engine}
                  className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                >
                  <option value="">Select Transmission</option>
                  {transmissions.map((transmission) => (
                    <option key={transmission} value={transmission}>{transmission}</option>
                  ))}
                </select>
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700">Drive *</label>
                <select
                  value={form.drive}
                  required
                  onChange={(event) => onDriveChange(event.target.value)}
                  disabled={!form.transmission}
                  className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                >
                  <option value="">Select Drive</option>
                  {drives.map((drive) => (
                    <option key={drive} value={drive}>{drive}</option>
                  ))}
                </select>
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700">Trim</label>
                <select
                  value={form.trim}
                  onChange={(event) => setForm((prev) => ({ ...prev, trim: event.target.value }))}
                  disabled={!form.drive}
                  className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                >
                  <option value="">Select Trim (Optional)</option>
                  {trims.map((trim) => (
                    <option key={trim} value={trim}>{trim}</option>
                  ))}
                </select>
              </div>
            </div>
          </div>

          <div className="border-t border-gray-200 pt-6">
            <h3 className="text-lg font-medium text-gray-900 mb-4">Additional Information</h3>
            <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
              <div>
                <label className="block text-sm font-medium text-gray-700">License Plate</label>
                <Input
                  value={form.license_plate}
                  placeholder="ABC-1234"
                  maxlength={30}
                  onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, license_plate: value }))}
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700">Mileage In</label>
                <Input
                  value={form.mileage_in ?? ''}
                  type="number"
                  placeholder="50000"
                  min="0"
                  onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, mileage_in: value }))}
                />
                <p className="mt-1 text-xs text-gray-500">Mileage when vehicle arrives</p>
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700">Mileage Out</label>
                <Input
                  value={form.mileage_out ?? ''}
                  type="number"
                  placeholder="50100"
                  min="0"
                  onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, mileage_out: value }))}
                />
                <p className="mt-1 text-xs text-gray-500">Mileage when vehicle leaves</p>
              </div>
            </div>

            <div className="mt-4">
              <label className="block text-sm font-medium text-gray-700">Notes</label>
              <Textarea
                value={form.notes}
                rows={3}
                className="mt-1"
                placeholder="Additional notes about this vehicle"
                onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, notes: value }))}
              />
            </div>
          </div>

          <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between border-t border-gray-200 pt-6">
            <div>
              <p className="text-sm text-gray-600">Fields marked with * are required.</p>
              {error ? <p className="text-sm text-red-600">{error}</p> : null}
              {success ? <p className="text-sm text-green-600">{success}</p> : null}
            </div>
            <div className="flex gap-3">
              <Button type="button" variant="secondary" onClick={() => navigate('/cp/vehicles')}>Cancel</Button>
              <Button type="submit" loading={saving}>{isEditing ? 'Update Vehicle' : 'Add to Garage'}</Button>
            </div>
          </div>
        </form>
      </Card>
    </div>
  )
}
