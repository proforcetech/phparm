import { useCallback, useEffect, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'

import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import { createVehicleMaster, getVehicleMaster, updateVehicleMaster } from '../../../services/vehicle-master.service'
import { useToast } from '../../stores/toast'

const maxYear = new Date().getFullYear() + 1

export default function VehicleMasterForm() {
  const navigate = useNavigate()
  const { id } = useParams()
  const { success, error: toastError } = useToast()

  const [saving, setSaving] = useState(false)
  const [successMessage, setSuccessMessage] = useState('')
  const [errorMessage, setErrorMessage] = useState('')
  const [form, setForm] = useState({
    year: null,
    make: '',
    model: '',
    engine: '',
    transmission: '',
    drive: '',
    trim: '',
  })

  const isEditing = Boolean(id)

  const loadVehicle = useCallback(async () => {
    if (!id) return
    try {
      const vehicle = await getVehicleMaster(id)
      setForm({
        year: vehicle.year,
        make: vehicle.make,
        model: vehicle.model,
        engine: vehicle.engine || '',
        transmission: vehicle.transmission || '',
        drive: vehicle.drive || '',
        trim: vehicle.trim || '',
      })
    } catch (err) {
      setErrorMessage('Failed to load vehicle')
      console.error(err)
    }
  }, [id])

  useEffect(() => {
    if (isEditing) {
      loadVehicle()
    }
  }, [isEditing, loadVehicle])

  const save = async (event) => {
    event.preventDefault()
    setSaving(true)
    setErrorMessage('')
    setSuccessMessage('')

    try {
      if (isEditing) {
        await updateVehicleMaster(id, form)
        setSuccessMessage('Vehicle updated successfully!')
        success('Vehicle updated successfully!')
      } else {
        await createVehicleMaster(form)
        setSuccessMessage('Vehicle added to database successfully!')
        success('Vehicle added to database successfully!')
      }

      setTimeout(() => {
        navigate('/cp/vehicle-master')
      }, 1500)
    } catch (err) {
      setErrorMessage(err.response?.data?.message || 'Failed to save vehicle')
      toastError('Failed to save vehicle')
      console.error(err)
    } finally {
      setSaving(false)
    }
  }

  return (
    <div>
      <div className="mb-6 flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">{isEditing ? 'Edit Vehicle' : 'Add Vehicle to Database'}</h1>
          <p className="mt-1 text-sm text-gray-500">
            {isEditing ? 'Update vehicle specifications' : 'Add a new vehicle to the master database'}
          </p>
        </div>
        <Button variant="secondary" onClick={() => navigate('/cp/vehicle-master')}>Back to list</Button>
      </div>

      <Card className="max-w-5xl">
        <form className="space-y-6" onSubmit={save}>
          <div>
            <h3 className="text-lg font-medium text-gray-900 mb-4">Vehicle Specifications</h3>
            <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
              <div>
                <label className="block text-sm font-medium text-gray-700">Year *</label>
                <Input
                  value={form.year ?? ''}
                  type="number"
                  required
                  placeholder="2024"
                  min="1950"
                  max={maxYear}
                  onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, year: value }))}
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700">Make *</label>
                <Input
                  value={form.make}
                  required
                  placeholder="Ford"
                  maxLength={120}
                  onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, make: value }))}
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700">Model *</label>
                <Input
                  value={form.model}
                  required
                  placeholder="F-150"
                  maxLength={120}
                  onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, model: value }))}
                />
              </div>
            </div>

            <div className="grid grid-cols-1 gap-4 md:grid-cols-4 mt-4">
              <div>
                <label className="block text-sm font-medium text-gray-700">Engine *</label>
                <Input
                  value={form.engine}
                  required
                  placeholder="5.0L V8"
                  maxLength={120}
                  onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, engine: value }))}
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700">Transmission *</label>
                <Input
                  value={form.transmission}
                  required
                  placeholder="10-Speed Automatic"
                  maxLength={120}
                  onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, transmission: value }))}
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700">Drive *</label>
                <Input
                  value={form.drive}
                  required
                  placeholder="4WD"
                  maxLength={20}
                  onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, drive: value }))}
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700">Trim</label>
                <Input
                  value={form.trim}
                  placeholder="Lariat"
                  maxLength={120}
                  onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, trim: value }))}
                />
                <p className="mt-1 text-xs text-gray-500">Optional</p>
              </div>
            </div>
          </div>

          <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between border-t border-gray-200 pt-6">
            <div>
              <p className="text-sm text-gray-600">Fields marked with * are required.</p>
              {errorMessage ? <p className="text-sm text-red-600 mt-1">{errorMessage}</p> : null}
              {successMessage ? <p className="text-sm text-green-600 mt-1">{successMessage}</p> : null}
            </div>
            <div className="flex gap-3">
              <Button type="button" variant="secondary" onClick={() => navigate('/cp/vehicle-master')}>Cancel</Button>
              <Button type="submit" loading={saving}>{isEditing ? 'Update Vehicle' : 'Add to Database'}</Button>
            </div>
          </div>
        </form>
      </Card>
    </div>
  )
}
