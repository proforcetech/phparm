import { useCallback, useEffect, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'

import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import { getVehicle } from '../../../services/vehicle.service'

export default function VehicleDetail() {
  const { id } = useParams()
  const navigate = useNavigate()
  const [loading, setLoading] = useState(true)
  const [vehicle, setVehicle] = useState(null)

  const loadVehicle = useCallback(async () => {
    if (!id) {
      navigate('/cp/vehicles')
      return
    }

    setLoading(true)
    try {
      const data = await getVehicle(id)
      setVehicle(data)
    } finally {
      setLoading(false)
    }
  }, [id, navigate])

  useEffect(() => {
    loadVehicle()
  }, [loadVehicle])

  const formatMileage = (value) => (value !== null && value !== undefined ? `${value} mi` : null)

  const renderSection = (title, items, emptyMessage) => {
    const filteredItems = items.filter((item) => item.value !== null && item.value !== undefined && item.value !== '')

    return (
      <div className="rounded-md border border-gray-100 bg-gray-50 p-4">
        <h2 className="text-sm font-semibold text-gray-800">{title}</h2>
        {filteredItems.length ? (
          <dl className="mt-3 grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
            {filteredItems.map((item) => (
              <div key={item.label}>
                <dt className="text-xs uppercase text-gray-500">{item.label}</dt>
                <dd className="text-sm font-medium text-gray-900">{item.value}</dd>
              </div>
            ))}
          </dl>
        ) : (
          <p className="mt-3 text-sm text-gray-500">{emptyMessage}</p>
        )}
      </div>
    )
  }

  return (
    <div>
      <div className="mb-6">
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-4">
            <Button variant="ghost" onClick={() => navigate('/cp/vehicles')}>
              <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
              </svg>
            </Button>
            <div>
              <h1 className="text-2xl font-bold text-gray-900">Vehicle Details</h1>
              <p className="mt-1 text-sm text-gray-500">View vehicle information</p>
            </div>
          </div>
          {vehicle ? (
            <Button onClick={() => navigate(`/cp/vehicles/${id}/edit`)}>
              <svg className="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
              </svg>
              Edit Vehicle
            </Button>
          ) : null}
        </div>
      </div>

      <Card>
        {loading ? (
          <div className="py-6 text-center text-sm text-gray-500">Loading vehicle...</div>
        ) : vehicle ? (
          <div className="space-y-6">
            <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
              <div>
                <p className="text-xs text-gray-500">Year</p>
                <p className="text-lg font-semibold text-gray-900">{vehicle.year}</p>
              </div>
              <div>
                <p className="text-xs text-gray-500">Make</p>
                <p className="text-lg font-semibold text-gray-900">{vehicle.make}</p>
              </div>
              <div>
                <p className="text-xs text-gray-500">Model</p>
                <p className="text-lg font-semibold text-gray-900">{vehicle.model}</p>
              </div>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
              <div>
                <p className="text-xs text-gray-500">Engine</p>
                <p className="text-sm text-gray-900">{vehicle.engine || 'Unknown'}</p>
              </div>
              <div>
                <p className="text-xs text-gray-500">Transmission</p>
                <p className="text-sm text-gray-900">{vehicle.transmission || 'Unknown'}</p>
              </div>
              <div>
                <p className="text-xs text-gray-500">Drive</p>
                <p className="text-sm text-gray-900">{vehicle.drive || 'Unknown'}</p>
              </div>
              <div>
                <p className="text-xs text-gray-500">Trim</p>
                <p className="text-sm text-gray-900">{vehicle.trim || 'N/A'}</p>
              </div>
            </div>

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
              {renderSection(
                'Customer Info',
                [
                  { label: 'Customer ID', value: vehicle.customer_id ? `#${vehicle.customer_id}` : null },
                  { label: 'Vehicle ID', value: vehicle.id ? `#${vehicle.id}` : null },
                  { label: 'Last Service Date', value: vehicle.last_service_date },
                  { label: 'Last Service Mileage', value: formatMileage(vehicle.last_service_mileage) },
                ],
                'No customer details available.'
              )}

              {renderSection(
                'VIN Details',
                [
                  { label: 'VIN', value: vehicle.vin },
                  { label: 'Trim', value: vehicle.trim },
                  { label: 'Engine', value: vehicle.engine },
                  { label: 'Transmission', value: vehicle.transmission },
                  { label: 'Drive', value: vehicle.drive },
                ],
                'No VIN details available.'
              )}

              {renderSection(
                'Registration & Insurance',
                [
                  { label: 'License Plate', value: vehicle.license_plate },
                  { label: 'Mileage In', value: formatMileage(vehicle.mileage_in) },
                  { label: 'Mileage Out', value: formatMileage(vehicle.mileage_out) },
                  { label: 'Registration Expires', value: vehicle.registration_expires },
                  { label: 'Insurance Provider', value: vehicle.insurance_provider },
                  { label: 'Policy Number', value: vehicle.insurance_policy_number },
                  { label: 'Insurance Expires', value: vehicle.insurance_expires },
                ],
                'No registration or insurance details available.'
              )}

              <div className="rounded-md border border-gray-100 bg-gray-50 p-4">
                <h2 className="text-sm font-semibold text-gray-800">Notes</h2>
                {vehicle.notes ? (
                  <p className="mt-3 text-sm text-gray-700 whitespace-pre-wrap">{vehicle.notes}</p>
                ) : (
                  <p className="mt-3 text-sm text-gray-500">No notes available.</p>
                )}
              </div>
            </div>
          </div>
        ) : (
          <div className="py-6 text-center text-sm text-gray-500">Vehicle not found.</div>
        )}
      </Card>
    </div>
  )
}
