import { useCallback, useEffect, useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'

import Autocomplete from '../../components/ui/Autocomplete'
import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Table from '../../components/ui/Table'
import { searchCustomers } from '../../../services/customer.service'
import { decodeVin, listVehicles, validateVin } from '../../../services/vehicle.service'
import { normalizeVinData } from '../../../utils/vin'

const columns = [
  { key: 'customer_id', label: 'Customer' },
  { key: 'year', label: 'Year' },
  { key: 'make', label: 'Make' },
  { key: 'model', label: 'Model' },
  { key: 'vin', label: 'VIN' },
  { key: 'license_plate', label: 'License Plate' },
  { key: 'mileage_in', label: 'Mileage In' },
  { key: 'mileage_out', label: 'Mileage Out' },
]

export default function VehicleList() {
  const navigate = useNavigate()
  const [loading, setLoading] = useState(false)
  const [vehicles, setVehicles] = useState([])
  const [filters, setFilters] = useState({
    customer_id: null,
    customer_query: '',
    year: '',
    make: '',
    model: '',
    term: '',
  })
  const [vin, setVin] = useState('')
  const [vinResult, setVinResult] = useState(null)
  const [vinLoading, setVinLoading] = useState(false)

  const searchCustomerOptions = useCallback(async (query) => {
    if (!query) return []
    try {
      const data = await searchCustomers(query)
      return Array.isArray(data) ? data : data?.data || []
    } catch (error) {
      console.error('Failed to search customers:', error)
      return []
    }
  }, [])

  const loadVehicles = useCallback(async () => {
    setLoading(true)
    const params = {}
    Object.entries(filters).forEach(([key, value]) => {
      if (value) params[key] = value
    })

    try {
      const data = await listVehicles(params)
      setVehicles(Array.isArray(data) ? data : [])
    } finally {
      setLoading(false)
    }
  }, [filters])

  useEffect(() => {
    loadVehicles()
  }, [loadVehicles])

  const goToDetail = (row) => {
    if (!row?.id) return
    navigate(`/cp/vehicles/${row.id}`)
  }

  const decode = async () => {
    setVinLoading(true)
    try {
      const result = await decodeVin(vin)
      setVinResult(result)
    } finally {
      setVinLoading(false)
    }
  }

  const validate = async () => {
    setVinLoading(true)
    try {
      const result = await validateVin(vin)
      setVinResult(result)
    } finally {
      setVinLoading(false)
    }
  }

  const vinDetails = useMemo(() => {
    if (!vinResult) return []

    const data = normalizeVinData(vinResult)

    const fields = [
      { key: 'vin', label: 'VIN' },
      { key: 'year', label: 'Year' },
      { key: 'make', label: 'Make' },
      { key: 'model', label: 'Model' },
      { key: 'trim', label: 'Trim' },
      { key: 'engine', label: 'Engine' },
      { key: 'transmission', label: 'Transmission' },
      { key: 'drive', label: 'Drive' },
      { key: 'fuel', label: 'Fuel' },
      { key: 'bodyStyle', label: 'Body Style' },
      { key: 'vehicleType', label: 'Vehicle Type' },
      { key: 'plantCountry', label: 'Assembly' },
      { key: 'manufacturer', label: 'Manufacturer' },
    ]

    return fields
      .map(({ key, label }) => ({ label, value: data[key] }))
      .filter((entry) => Boolean(entry.value))
  }, [vinResult])

  const vinMessage = useMemo(() => {
    if (!vinResult) return ''
    if (vinResult.message) return vinResult.message
    if (vinResult.valid === true) return 'VIN is valid'
    if (vinResult.valid === false) return 'VIN is invalid'
    return 'VIN details'
  }, [vinResult])

  const vinSummary = useMemo(() => {
    if (!vinResult) return ''
    const data = normalizeVinData(vinResult)
    const summaryParts = [data.year, data.make, data.model].filter(Boolean)
    const detailParts = [data.trim, data.bodyStyle, data.vehicleType].filter(Boolean)

    return [summaryParts.join(' • '), detailParts.join(' · ')].filter(Boolean).join(' — ')
  }, [vinResult])

  return (
    <div>
      <div className="mb-6 flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Customer Vehicles</h1>
          <p className="mt-1 text-sm text-gray-500">Manage vehicles in customer garages</p>
        </div>
        <div className="flex gap-3">
          <Button variant="secondary" onClick={() => navigate('/cp/vehicle-master')}>
            <svg className="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4" />
            </svg>
            Vehicle Database
          </Button>
          <Button onClick={() => navigate('/cp/vehicles/create')}>
            <svg className="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 4v16m8-8H4" />
            </svg>
            Add to Customer Garage
          </Button>
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <Card className="lg:col-span-2">
          <div className="flex flex-col gap-4">
            <div className="grid grid-cols-1 md:grid-cols-5 gap-4">
              <div>
                <Autocomplete
                  modelValue={filters.customer_id}
                  label="Customer"
                  placeholder="Search by name, email, phone, or ID"
                  searchFn={searchCustomerOptions}
                  itemValue={(item) => item.id}
                  itemLabel={(item) => item.name || `Customer #${item.id}`}
                  itemSubtext={(item) => `${item.email || 'No email'} • ${item.phone || 'No phone'}`}
                  helperText="Select a customer to filter their vehicles"
                  onSearchChange={(value) =>
                    setFilters((prev) => ({
                      ...prev,
                      customer_id: null,
                      customer_query: value,
                    }))
                  }
                  onUpdateModelValue={(value) =>
                    setFilters((prev) => ({
                      ...prev,
                      customer_id: value,
                      customer_query: value ? '' : prev.customer_query,
                    }))
                  }
                  onSelect={() =>
                    setFilters((prev) => ({
                      ...prev,
                      customer_query: '',
                    }))
                  }
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700">Year</label>
                <Input
                  value={filters.year}
                  type="number"
                  placeholder="2024"
                  onUpdateModelValue={(value) => setFilters((prev) => ({ ...prev, year: value }))}
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700">Make</label>
                <Input
                  value={filters.make}
                  placeholder="Ford"
                  onUpdateModelValue={(value) => setFilters((prev) => ({ ...prev, make: value }))}
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700">Model</label>
                <Input
                  value={filters.model}
                  placeholder="F-150"
                  onUpdateModelValue={(value) => setFilters((prev) => ({ ...prev, model: value }))}
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700">Search term</label>
                <Input
                  value={filters.term}
                  placeholder="Engine, transmission..."
                  onUpdateModelValue={(value) => setFilters((prev) => ({ ...prev, term: value }))}
                />
              </div>
            </div>

            <Table
              columns={columns}
              data={vehicles}
              loading={loading}
              hoverable
              onRowClick={goToDetail}
              cellRenderers={{
                year: ({ value }) => <span className="font-semibold">{value}</span>,
                engine: ({ value }) => <span className="text-sm text-gray-700">{value || 'N/A'}</span>,
                transmission: ({ value }) => <Badge variant="secondary">{value || 'Unknown'}</Badge>,
                drive: ({ value }) => <span className="text-sm">{value || '—'}</span>,
              }}
              renderActions={(row) => (
                <div className="flex gap-2 justify-end">
                  <Button
                    size="sm"
                    variant="secondary"
                    onClick={(event) => {
                      event.stopPropagation()
                      goToDetail(row)
                    }}
                  >
                    View
                  </Button>
                </div>
              )}
              renderEmpty={<p className="text-sm text-gray-500">No vehicles found for the current filters.</p>}
            />
          </div>
        </Card>

        <Card>
          <h3 className="text-lg font-semibold text-gray-900 mb-3">VIN decoder</h3>
          <div className="space-y-3">
            <div>
              <label className="block text-sm font-medium text-gray-700">VIN</label>
              <Input
                value={vin}
                maxlength={17}
                placeholder="Enter 17-character VIN"
                onUpdateModelValue={(value) => setVin(value)}
              />
            </div>
            <div className="flex gap-2">
              <Button className="flex-1" loading={vinLoading} onClick={decode}>
                Decode
              </Button>
              <Button className="flex-1" variant="secondary" loading={vinLoading} onClick={validate}>
                Validate
              </Button>
            </div>
            {vinResult ? (
              <div className="rounded-md bg-gray-50 p-3 text-sm text-gray-800">
                <p className="font-semibold">{vinMessage}</p>
                {vinSummary ? (
                  <p className="mt-1 text-xs text-gray-600">{vinSummary}</p>
                ) : null}
                <div className="mt-3 grid grid-cols-2 gap-x-4 gap-y-2">
                  {vinDetails.map((field) => (
                    <div key={field.label}>
                      <p className="text-xs text-gray-500">{field.label}</p>
                      <p className="text-sm font-medium text-gray-900">{field.value}</p>
                    </div>
                  ))}
                </div>
              </div>
            ) : null}
          </div>
        </Card>
      </div>
    </div>
  )
}
