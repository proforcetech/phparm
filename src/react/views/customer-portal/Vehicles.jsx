// @deprecated Phase 2a — frozen for legacy `customer` role. New portal lives at src/react/views/portal/*.
import { useCallback, useEffect, useState } from 'react'

import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Modal from '../../components/ui/Modal'
import Textarea from '../../components/ui/Textarea'
import { portalService } from '../../../services/portal.service'

const emptyForm = { vin: '', plate: '', notes: '' }

export default function Vehicles() {
  const [vehicles, setVehicles] = useState([])
  const [loading, setLoading] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const [showModal, setShowModal] = useState(false)
  const [form, setForm] = useState(emptyForm)

  const loadVehicles = useCallback(async () => {
    setLoading(true)
    try {
      const response = await portalService.getVehicles()
      setVehicles(response || [])
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    loadVehicles()
  }, [loadVehicles])

  const resetModal = () => {
    setShowModal(false)
    setForm(emptyForm)
  }

  const submitVehicle = async (event) => {
    event.preventDefault()
    setSubmitting(true)
    try {
      await portalService.addVehicle({
        vin: form.vin,
        plate: form.plate,
        notes: form.notes,
      })
      await loadVehicles()
      resetModal()
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-gray-900">My Vehicles</h1>
        <p className="mt-1 text-sm text-gray-500">Manage vehicles linked to your account</p>
      </div>

      <div className="flex justify-end mb-4">
        <Button variant="primary" onClick={() => setShowModal(true)}>
          <svg className="h-4 w-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
          </svg>
          Add Vehicle
        </Button>
      </div>

      <Card>
        {loading ? (
          <div className="py-10 flex justify-center">
            <Loading text="Loading vehicles..." />
          </div>
        ) : vehicles.length === 0 ? (
          <div className="text-center py-12">
            <svg className="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
            </svg>
            <h3 className="mt-2 text-sm font-medium text-gray-900">No Vehicles</h3>
            <p className="mt-1 text-sm text-gray-500">No vehicles are associated with your account.</p>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">VIN</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Plate</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Notes</th>
                </tr>
              </thead>
              <tbody className="bg-white divide-y divide-gray-200">
                {vehicles.map((vehicle) => (
                  <tr key={vehicle.id}>
                    <td className="px-4 py-3 text-sm text-gray-900">{vehicle.vin || '—'}</td>
                    <td className="px-4 py-3 text-sm text-gray-900">{vehicle.plate || '—'}</td>
                    <td className="px-4 py-3 text-sm text-gray-500">{vehicle.notes || '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      <Modal open={showModal} title="Add Vehicle" onClose={resetModal}>
        <form className="space-y-4" onSubmit={submitVehicle}>
          <Input
            modelValue={form.vin}
            label="VIN"
            placeholder="1HGCM82633A123456"
            onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, vin: value }))}
          />
          <Input
            modelValue={form.plate}
            label="License Plate"
            placeholder="ABC123"
            onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, plate: value }))}
          />
          <Textarea
            modelValue={form.notes}
            label="Notes"
            placeholder="Color, trim, or other details"
            onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, notes: value }))}
          />

          <div className="flex justify-end gap-2 pt-2">
            <Button variant="ghost" type="button" onClick={resetModal}>Cancel</Button>
            <Button variant="primary" type="submit" disabled={submitting}>
              {submitting ? 'Saving...' : 'Save Vehicle'}
            </Button>
          </div>
        </form>
      </Modal>
    </div>
  )
}
