import { useCallback, useEffect, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'

import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import { getVehicle } from '../../../services/vehicle.service'

const pretty = (value) => JSON.stringify(value, null, 2)

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
          <div className="space-y-4">
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

            <div className="rounded-md bg-gray-50 p-4">
              <p className="text-sm font-semibold text-gray-800">Raw payload</p>
              <pre className="mt-2 text-xs text-gray-700 whitespace-pre-wrap">{pretty(vehicle)}</pre>
            </div>
          </div>
        ) : (
          <div className="py-6 text-center text-sm text-gray-500">Vehicle not found.</div>
        )}
      </Card>
    </div>
  )
}
