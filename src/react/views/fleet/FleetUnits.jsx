import { useCallback, useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'

import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Modal from '../../components/ui/Modal'
import Select from '../../components/ui/Select'
import fleetService from '../../../services/fleet.service'
import { useToast } from '../../stores/toast.jsx'

const PER_PAGE = 20

const STATUS_OPTIONS = [
  { value: '', label: 'All statuses' },
  { value: 'active', label: 'Active' },
  { value: 'in_service', label: 'In Service' },
  { value: 'out_of_service', label: 'Out of Service' },
  { value: 'sold', label: 'Sold' },
]

const CATEGORY_OPTIONS = [
  { value: '', label: 'All categories' },
  { value: 'vehicle', label: 'Vehicle' },
  { value: 'trailer', label: 'Trailer' },
  { value: 'equipment', label: 'Equipment' },
]

const CREATE_CATEGORY_OPTIONS = CATEGORY_OPTIONS.filter((opt) => opt.value !== '')
const CREATE_STATUS_OPTIONS = STATUS_OPTIONS.filter((opt) => opt.value !== '')

const STATUS_VARIANT = {
  active: 'success',
  in_service: 'info',
  out_of_service: 'warning',
  sold: 'default',
}

const CATEGORY_VARIANT = {
  vehicle: 'primary',
  trailer: 'info',
  equipment: 'secondary',
}

function unwrap(response) {
  if (Array.isArray(response)) return response
  if (Array.isArray(response?.data)) return response.data
  if (Array.isArray(response?.data?.items)) return response.data.items
  return []
}

function getIdentifier(unit) {
  return (
    unit?.identifier ||
    unit?.license_plate ||
    unit?.vin ||
    unit?.asset_tag ||
    unit?.name ||
    `Unit #${unit?.id ?? ''}`
  )
}

function getDriver(unit) {
  return (
    unit?.current_driver_name ||
    unit?.current_driver ||
    unit?.driver_name ||
    unit?.assigned_to_name ||
    null
  )
}

function getLatestReading(unit) {
  const reading =
    unit?.latest_reading ||
    unit?.last_reading ||
    (unit?.last_mileage ? { value: unit.last_mileage, reading_type: 'mileage' } : null) ||
    (unit?.last_hours ? { value: unit.last_hours, reading_type: 'hours' } : null)

  if (!reading) return null
  const value = reading.value ?? reading.amount ?? reading.reading ?? null
  if (value === null || value === undefined) return null
  const type = reading.reading_type || reading.type || ''
  const suffix = type === 'mileage' ? 'mi' : type === 'hours' ? 'hr' : ''
  return `${Number(value).toLocaleString()}${suffix ? ` ${suffix}` : ''}`
}

const EMPTY_FORM = {
  identifier: '',
  category: 'vehicle',
  make: '',
  model: '',
  year: '',
  vin: '',
  license_plate: '',
  purchase_date: '',
  status: 'active',
}

export default function FleetUnits() {
  const navigate = useNavigate()
  const { success, error } = useToast()

  const [units, setUnits] = useState([])
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')
  const [statusFilter, setStatusFilter] = useState('')
  const [categoryFilter, setCategoryFilter] = useState('')
  const [page, setPage] = useState(1)
  const [total, setTotal] = useState(0)

  const [createOpen, setCreateOpen] = useState(false)
  const [createForm, setCreateForm] = useState(EMPTY_FORM)
  const [creating, setCreating] = useState(false)

  const loadUnits = useCallback(async () => {
    setLoading(true)
    try {
      const params = {
        limit: PER_PAGE,
        offset: (page - 1) * PER_PAGE,
      }
      if (search.trim()) params.query = search.trim()
      if (statusFilter) params.status = statusFilter
      if (categoryFilter) params.category = categoryFilter
      const response = await fleetService.listUnits(params)
      setUnits(unwrap(response))
      setTotal(response?.total ?? response?.data?.total ?? 0)
    } catch {
      error('Failed to load fleet units')
      setUnits([])
    } finally {
      setLoading(false)
    }
  }, [page, search, statusFilter, categoryFilter, error])

  useEffect(() => {
    loadUnits()
  }, [loadUnits])

  const handleSearch = (event) => {
    event.preventDefault()
    setPage(1)
    loadUnits()
  }

  const handleFilterChange = (setter) => (value) => {
    setter(value)
    setPage(1)
  }

  const updateForm = (field) => (value) => {
    setCreateForm((prev) => ({ ...prev, [field]: value }))
  }

  const resetCreateForm = () => {
    setCreateForm(EMPTY_FORM)
  }

  const handleCreate = async (event) => {
    event.preventDefault()
    if (!createForm.identifier.trim()) {
      error('Identifier is required')
      return
    }
    setCreating(true)
    try {
      const payload = {
        identifier: createForm.identifier.trim(),
        category: createForm.category,
        make: createForm.make.trim() || null,
        model: createForm.model.trim() || null,
        year: createForm.year ? Number(createForm.year) : null,
        vin: createForm.vin.trim() || null,
        license_plate: createForm.license_plate.trim() || null,
        purchase_date: createForm.purchase_date || null,
        status: createForm.status,
      }
      const response = await fleetService.createUnit(payload)
      success('Fleet unit created')
      setCreateOpen(false)
      resetCreateForm()
      const newId = response?.data?.id ?? response?.id
      if (newId) {
        navigate(`/cp/fleet/units/${newId}`)
      } else {
        loadUnits()
      }
    } catch (e) {
      error(e?.response?.data?.message || 'Failed to create unit')
    } finally {
      setCreating(false)
    }
  }

  const hasNext = units.length === PER_PAGE

  return (
    <div className="space-y-6">
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Fleet Units</h1>
          <p className="mt-1 text-sm text-gray-500">
            Vehicles, trailers, and equipment in your fleet.
          </p>
        </div>
        <div className="flex flex-wrap gap-2">
          <Button onClick={() => setCreateOpen(true)}>New Unit</Button>
        </div>
      </div>

      <Card>
        <form onSubmit={handleSearch} className="mb-4 grid grid-cols-1 sm:grid-cols-4 gap-3">
          <Input
            value={search}
            onUpdateModelValue={setSearch}
            placeholder="Search VIN, plate, or name..."
            className="sm:col-span-2"
          />
          <Select
            value={statusFilter}
            onUpdateModelValue={handleFilterChange(setStatusFilter)}
            options={STATUS_OPTIONS}
            placeholder=""
          />
          <Select
            value={categoryFilter}
            onUpdateModelValue={handleFilterChange(setCategoryFilter)}
            options={CATEGORY_OPTIONS}
            placeholder=""
          />
          <div className="sm:col-span-4 flex justify-end">
            <Button type="submit" variant="secondary">Search</Button>
          </div>
        </form>

        {loading ? (
          <div className="py-10 flex justify-center">
            <Loading text="Loading fleet units..." />
          </div>
        ) : units.length === 0 ? (
          <div className="text-center py-12">
            <h3 className="text-sm font-medium text-gray-900">No fleet units found</h3>
            <p className="mt-1 text-sm text-gray-500">
              Add your first vehicle, trailer, or equipment item to get started.
            </p>
            <div className="mt-4">
              <Button onClick={() => setCreateOpen(true)}>New Unit</Button>
            </div>
          </div>
        ) : (
          <>
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-gray-200">
                <thead className="bg-gray-50">
                  <tr>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Identifier</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Category</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Make / Model</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Year</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Driver</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Latest Reading</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-200 bg-white">
                  {units.map((unit) => {
                    const driver = getDriver(unit)
                    const reading = getLatestReading(unit)
                    return (
                      <tr
                        key={unit.id}
                        className="hover:bg-gray-50 cursor-pointer"
                        onClick={() => navigate(`/cp/fleet/units/${unit.id}`)}
                      >
                        <td className="px-4 py-3 text-sm">
                          <Link
                            to={`/cp/fleet/units/${unit.id}`}
                            onClick={(e) => e.stopPropagation()}
                            className="text-primary-600 hover:text-primary-500 font-medium"
                          >
                            {getIdentifier(unit)}
                          </Link>
                        </td>
                        <td className="px-4 py-3">
                          <Badge size="sm" variant={CATEGORY_VARIANT[unit.category] || 'default'}>
                            {unit.category || '—'}
                          </Badge>
                        </td>
                        <td className="px-4 py-3 text-sm text-gray-700">
                          {[unit.make, unit.model].filter(Boolean).join(' ') || '—'}
                        </td>
                        <td className="px-4 py-3 text-sm text-gray-500">{unit.year || '—'}</td>
                        <td className="px-4 py-3 text-sm text-gray-500">{driver || '—'}</td>
                        <td className="px-4 py-3 text-sm text-gray-500">{reading || '—'}</td>
                        <td className="px-4 py-3">
                          <Badge size="sm" variant={STATUS_VARIANT[unit.status] || 'default'}>
                            {unit.status || '—'}
                          </Badge>
                        </td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>

            <div className="flex justify-between items-center mt-4 pt-4 border-t">
              <span className="text-sm text-gray-500">
                Showing {(page - 1) * PER_PAGE + 1} - {(page - 1) * PER_PAGE + units.length}
                {total ? ` of ${total}` : ''}
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
        open={createOpen}
        title="New Fleet Unit"
        onClose={() => {
          setCreateOpen(false)
          resetCreateForm()
        }}
      >
        <form onSubmit={handleCreate} className="space-y-3">
          <Input
            label="Identifier"
            required
            value={createForm.identifier}
            onUpdateModelValue={updateForm('identifier')}
            placeholder="Plate, VIN, or asset tag"
          />
          <Select
            label="Category"
            value={createForm.category}
            onUpdateModelValue={updateForm('category')}
            options={CREATE_CATEGORY_OPTIONS}
            placeholder=""
          />
          <div className="grid grid-cols-2 gap-3">
            <Input label="Make" value={createForm.make} onUpdateModelValue={updateForm('make')} />
            <Input label="Model" value={createForm.model} onUpdateModelValue={updateForm('model')} />
          </div>
          <div className="grid grid-cols-2 gap-3">
            <Input
              label="Year"
              type="number"
              value={createForm.year}
              onUpdateModelValue={updateForm('year')}
            />
            <Input
              label="License plate"
              value={createForm.license_plate}
              onUpdateModelValue={updateForm('license_plate')}
            />
          </div>
          <Input label="VIN" value={createForm.vin} onUpdateModelValue={updateForm('vin')} />
          <Input
            label="Purchase date"
            type="date"
            value={createForm.purchase_date}
            onUpdateModelValue={updateForm('purchase_date')}
          />
          <Select
            label="Status"
            value={createForm.status}
            onUpdateModelValue={updateForm('status')}
            options={CREATE_STATUS_OPTIONS}
            placeholder=""
          />
          <div className="flex justify-end gap-2 pt-2">
            <Button
              type="button"
              variant="ghost"
              onClick={() => {
                setCreateOpen(false)
                resetCreateForm()
              }}
            >
              Cancel
            </Button>
            <Button type="submit" loading={creating}>
              Create Unit
            </Button>
          </div>
        </form>
      </Modal>
    </div>
  )
}
