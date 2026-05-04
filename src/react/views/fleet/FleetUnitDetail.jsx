import { useCallback, useEffect, useMemo, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'

import Alert from '../../components/ui/Alert'
import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Modal from '../../components/ui/Modal'
import Select from '../../components/ui/Select'
import Textarea from '../../components/ui/Textarea'
import fleetService from '../../../services/fleet.service'
import { useToast } from '../../stores/toast.jsx'

const TABS = [
  { key: 'overview', label: 'Overview' },
  { key: 'readings', label: 'Readings' },
  { key: 'assignments', label: 'Assignments' },
  { key: 'downtime', label: 'Downtime' },
  { key: 'external', label: 'External Repairs' },
  { key: 'pm', label: 'PM Bindings' },
]

const CATEGORY_OPTIONS = [
  { value: 'vehicle', label: 'Vehicle' },
  { value: 'trailer', label: 'Trailer' },
  { value: 'equipment', label: 'Equipment' },
]

const STATUS_OPTIONS = [
  { value: 'active', label: 'Active' },
  { value: 'in_service', label: 'In Service' },
  { value: 'out_of_service', label: 'Out of Service' },
  { value: 'sold', label: 'Sold' },
]

const READING_TYPE_OPTIONS = [
  { value: 'mileage', label: 'Mileage' },
  { value: 'hours', label: 'Hours' },
]

const DOWNTIME_CATEGORIES = [
  { value: 'maintenance', label: 'Maintenance' },
  { value: 'repair', label: 'Repair' },
  { value: 'inspection', label: 'Inspection' },
  { value: 'accident', label: 'Accident' },
  { value: 'other', label: 'Other' },
]

const STATUS_VARIANT = {
  active: 'success',
  in_service: 'info',
  out_of_service: 'warning',
  sold: 'default',
}

function unwrapList(response) {
  if (Array.isArray(response)) return response
  if (Array.isArray(response?.data)) return response.data
  if (Array.isArray(response?.data?.items)) return response.data.items
  return []
}

function unwrapItem(response) {
  if (response && typeof response === 'object' && 'data' in response) {
    return response.data ?? null
  }
  return response ?? null
}

function formatDateTime(value) {
  if (!value) return '—'
  try {
    return new Date(value).toLocaleString()
  } catch {
    return value
  }
}

function formatDate(value) {
  if (!value) return '—'
  try {
    return new Date(value).toLocaleDateString()
  } catch {
    return value
  }
}

function formatMoney(value) {
  if (value === null || value === undefined || value === '') return '—'
  const num = Number(value)
  if (Number.isNaN(num)) return value
  return num.toLocaleString(undefined, { style: 'currency', currency: 'USD' })
}

function getIdentifier(unit) {
  if (!unit) return ''
  return (
    unit.identifier ||
    unit.license_plate ||
    unit.vin ||
    unit.asset_tag ||
    unit.name ||
    `Unit #${unit.id}`
  )
}

export default function FleetUnitDetail() {
  const { id } = useParams()
  const navigate = useNavigate()
  const { success, error } = useToast()

  const [unit, setUnit] = useState(null)
  const [loading, setLoading] = useState(true)
  const [activeTab, setActiveTab] = useState('overview')
  const [editing, setEditing] = useState(false)
  const [editForm, setEditForm] = useState({})
  const [savingEdit, setSavingEdit] = useState(false)
  const [deleteOpen, setDeleteOpen] = useState(false)
  const [deleting, setDeleting] = useState(false)

  // Readings
  const [readings, setReadings] = useState([])
  const [readingsLoading, setReadingsLoading] = useState(false)
  const [readingOpen, setReadingOpen] = useState(false)
  const [readingForm, setReadingForm] = useState({ reading_type: 'mileage', value: '', recorded_at: '' })
  const [readingSaving, setReadingSaving] = useState(false)

  // Assignments
  const [assignments, setAssignments] = useState([])
  const [assignmentsLoading, setAssignmentsLoading] = useState(false)
  const [assignmentOpen, setAssignmentOpen] = useState(false)
  const [assignmentForm, setAssignmentForm] = useState({ driver_user_id: '', notes: '' })
  const [assignmentSaving, setAssignmentSaving] = useState(false)
  const [endingAssignment, setEndingAssignment] = useState(false)

  // Downtime
  const [downtimes, setDowntimes] = useState([])
  const [downtimeCurrent, setDowntimeCurrent] = useState(null)
  const [downtimeLoading, setDowntimeLoading] = useState(false)
  const [downtimeOpen, setDowntimeOpen] = useState(false)
  const [downtimeForm, setDowntimeForm] = useState({ reason: '', category: 'maintenance' })
  const [downtimeSaving, setDowntimeSaving] = useState(false)
  const [endingDowntime, setEndingDowntime] = useState(false)

  // External repairs
  const [external, setExternal] = useState([])
  const [externalLoading, setExternalLoading] = useState(false)
  const [externalOpen, setExternalOpen] = useState(false)
  const [externalForm, setExternalForm] = useState({
    shop_name: '',
    in_date: '',
    out_date: '',
    description: '',
    cost: '',
  })
  const [externalSaving, setExternalSaving] = useState(false)

  // PM bindings
  const [pmBindings, setPmBindings] = useState([])
  const [pmLoading, setPmLoading] = useState(false)
  const [pmOpen, setPmOpen] = useState(false)
  const [pmForm, setPmForm] = useState({ pm_schedule_id: '' })
  const [pmSaving, setPmSaving] = useState(false)

  const loadUnit = useCallback(async () => {
    setLoading(true)
    try {
      const response = await fleetService.getUnit(id)
      const data = unwrapItem(response)
      setUnit(data)
      if (data) {
        setEditForm({
          identifier: data.identifier ?? '',
          category: data.category ?? 'vehicle',
          make: data.make ?? '',
          model: data.model ?? '',
          year: data.year ?? '',
          vin: data.vin ?? '',
          license_plate: data.license_plate ?? '',
          purchase_date: data.purchase_date ?? '',
          status: data.status ?? 'active',
        })
      }
    } catch {
      error('Failed to load fleet unit')
      navigate('/cp/fleet/units')
    } finally {
      setLoading(false)
    }
  }, [id, error, navigate])

  useEffect(() => {
    loadUnit()
  }, [loadUnit])

  const loadReadings = useCallback(async () => {
    setReadingsLoading(true)
    try {
      setReadings(unwrapList(await fleetService.listReadings(id)))
    } catch {
      error('Failed to load readings')
      setReadings([])
    } finally {
      setReadingsLoading(false)
    }
  }, [id, error])

  const loadAssignments = useCallback(async () => {
    setAssignmentsLoading(true)
    try {
      setAssignments(unwrapList(await fleetService.listAssignments(id)))
    } catch {
      error('Failed to load assignments')
      setAssignments([])
    } finally {
      setAssignmentsLoading(false)
    }
  }, [id, error])

  const loadDowntime = useCallback(async () => {
    setDowntimeLoading(true)
    try {
      const [list, current] = await Promise.all([
        fleetService.listDowntime(id),
        fleetService.currentDowntime(id).catch(() => null),
      ])
      setDowntimes(unwrapList(list))
      setDowntimeCurrent(unwrapItem(current))
    } catch {
      error('Failed to load downtime')
      setDowntimes([])
      setDowntimeCurrent(null)
    } finally {
      setDowntimeLoading(false)
    }
  }, [id, error])

  const loadExternal = useCallback(async () => {
    setExternalLoading(true)
    try {
      setExternal(unwrapList(await fleetService.listExternalRepairsForUnit(id)))
    } catch {
      error('Failed to load external repairs')
      setExternal([])
    } finally {
      setExternalLoading(false)
    }
  }, [id, error])

  const loadPm = useCallback(async () => {
    setPmLoading(true)
    try {
      setPmBindings(unwrapList(await fleetService.listPmBindings({ unit_id: id })))
    } catch {
      error('Failed to load PM bindings')
      setPmBindings([])
    } finally {
      setPmLoading(false)
    }
  }, [id, error])

  useEffect(() => {
    if (!unit) return
    if (activeTab === 'readings') loadReadings()
    else if (activeTab === 'assignments') loadAssignments()
    else if (activeTab === 'downtime') loadDowntime()
    else if (activeTab === 'external') loadExternal()
    else if (activeTab === 'pm') loadPm()
  }, [activeTab, unit, loadReadings, loadAssignments, loadDowntime, loadExternal, loadPm])

  const updateEditForm = (field) => (value) => {
    setEditForm((prev) => ({ ...prev, [field]: value }))
  }

  const handleSaveEdit = async (event) => {
    event.preventDefault()
    setSavingEdit(true)
    try {
      const payload = {
        identifier: editForm.identifier?.trim() || null,
        category: editForm.category,
        make: editForm.make?.trim() || null,
        model: editForm.model?.trim() || null,
        year: editForm.year ? Number(editForm.year) : null,
        vin: editForm.vin?.trim() || null,
        license_plate: editForm.license_plate?.trim() || null,
        purchase_date: editForm.purchase_date || null,
        status: editForm.status,
      }
      await fleetService.updateUnit(id, payload)
      success('Unit updated')
      setEditing(false)
      loadUnit()
    } catch (e) {
      error(e?.response?.data?.message || 'Failed to update unit')
    } finally {
      setSavingEdit(false)
    }
  }

  const handleDelete = async () => {
    setDeleting(true)
    try {
      await fleetService.deleteUnit(id)
      success('Unit deleted')
      navigate('/cp/fleet/units')
    } catch (e) {
      error(e?.response?.data?.message || 'Failed to delete unit')
    } finally {
      setDeleting(false)
    }
  }

  const submitReading = async (event) => {
    event.preventDefault()
    if (!readingForm.value) {
      error('Reading value is required')
      return
    }
    setReadingSaving(true)
    try {
      await fleetService.createReading(id, {
        reading_type: readingForm.reading_type,
        value: Number(readingForm.value),
        recorded_at: readingForm.recorded_at || null,
      })
      success('Reading recorded')
      setReadingOpen(false)
      setReadingForm({ reading_type: 'mileage', value: '', recorded_at: '' })
      loadReadings()
    } catch (e) {
      error(e?.response?.data?.message || 'Failed to add reading')
    } finally {
      setReadingSaving(false)
    }
  }

  const submitAssignment = async (event) => {
    event.preventDefault()
    if (!assignmentForm.driver_user_id) {
      error('Driver user id is required')
      return
    }
    setAssignmentSaving(true)
    try {
      await fleetService.startAssignment(id, {
        driver_user_id: Number(assignmentForm.driver_user_id),
        notes: assignmentForm.notes || null,
      })
      success('Assignment started')
      setAssignmentOpen(false)
      setAssignmentForm({ driver_user_id: '', notes: '' })
      loadAssignments()
    } catch (e) {
      error(e?.response?.data?.message || 'Failed to start assignment')
    } finally {
      setAssignmentSaving(false)
    }
  }

  const handleEndAssignment = async () => {
    setEndingAssignment(true)
    try {
      await fleetService.endAssignment(id, { ended_at: new Date().toISOString() })
      success('Assignment ended')
      loadAssignments()
    } catch (e) {
      error(e?.response?.data?.message || 'Failed to end assignment')
    } finally {
      setEndingAssignment(false)
    }
  }

  const submitDowntime = async (event) => {
    event.preventDefault()
    if (!downtimeForm.reason.trim()) {
      error('Reason is required')
      return
    }
    setDowntimeSaving(true)
    try {
      await fleetService.startDowntime(id, {
        reason: downtimeForm.reason.trim(),
        category: downtimeForm.category,
      })
      success('Downtime started')
      setDowntimeOpen(false)
      setDowntimeForm({ reason: '', category: 'maintenance' })
      loadDowntime()
    } catch (e) {
      error(e?.response?.data?.message || 'Failed to start downtime')
    } finally {
      setDowntimeSaving(false)
    }
  }

  const handleEndDowntime = async () => {
    setEndingDowntime(true)
    try {
      await fleetService.endDowntime(id, { ended_at: new Date().toISOString() })
      success('Downtime ended')
      loadDowntime()
    } catch (e) {
      error(e?.response?.data?.message || 'Failed to end downtime')
    } finally {
      setEndingDowntime(false)
    }
  }

  const submitExternal = async (event) => {
    event.preventDefault()
    if (!externalForm.shop_name.trim()) {
      error('Shop name is required')
      return
    }
    setExternalSaving(true)
    try {
      await fleetService.createExternalRepair(id, {
        shop_name: externalForm.shop_name.trim(),
        in_date: externalForm.in_date || null,
        out_date: externalForm.out_date || null,
        description: externalForm.description || null,
        cost: externalForm.cost ? Number(externalForm.cost) : null,
      })
      success('External repair created')
      setExternalOpen(false)
      setExternalForm({ shop_name: '', in_date: '', out_date: '', description: '', cost: '' })
      loadExternal()
    } catch (e) {
      error(e?.response?.data?.message || 'Failed to create external repair')
    } finally {
      setExternalSaving(false)
    }
  }

  const submitPm = async (event) => {
    event.preventDefault()
    if (!pmForm.pm_schedule_id) {
      error('PM schedule id is required')
      return
    }
    setPmSaving(true)
    try {
      await fleetService.createPmBinding({
        pm_schedule_id: Number(pmForm.pm_schedule_id),
        unit_id: Number(id),
      })
      success('PM binding added')
      setPmOpen(false)
      setPmForm({ pm_schedule_id: '' })
      loadPm()
    } catch (e) {
      error(e?.response?.data?.message || 'Failed to add PM binding')
    } finally {
      setPmSaving(false)
    }
  }

  const handleDeletePmBinding = async (bindingId) => {
    try {
      await fleetService.deletePmBinding(bindingId)
      success('PM binding removed')
      loadPm()
    } catch (e) {
      error(e?.response?.data?.message || 'Failed to remove binding')
    }
  }

  const activeAssignment = useMemo(
    () => assignments.find((a) => !a.ended_at) || null,
    [assignments]
  )

  if (loading) {
    return (
      <div className="flex justify-center items-center min-h-96">
        <Loading text="Loading fleet unit..." />
      </div>
    )
  }

  if (!unit) {
    return (
      <div className="text-center py-12">
        <h3 className="text-lg font-medium text-gray-900">Fleet unit not found</h3>
        <div className="mt-4">
          <Link to="/cp/fleet/units">
            <Button>Back to Fleet</Button>
          </Link>
        </div>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div className="flex items-center gap-4">
          <Link to="/cp/fleet/units" className="text-gray-500 hover:text-gray-700">
            <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
            </svg>
          </Link>
          <div>
            <h1 className="text-2xl font-bold text-gray-900">{getIdentifier(unit)}</h1>
            <div className="mt-1 flex items-center gap-2 text-sm text-gray-500">
              <Badge size="sm" variant="secondary">{unit.category || '—'}</Badge>
              <Badge size="sm" variant={STATUS_VARIANT[unit.status] || 'default'}>
                {unit.status || '—'}
              </Badge>
            </div>
          </div>
        </div>
      </div>

      <div className="border-b border-gray-200">
        <nav className="-mb-px flex flex-wrap gap-x-6 gap-y-2">
          {TABS.map((tab) => (
            <button
              key={tab.key}
              type="button"
              onClick={() => setActiveTab(tab.key)}
              className={`whitespace-nowrap py-3 px-1 border-b-2 text-sm font-medium ${
                activeTab === tab.key
                  ? 'border-primary-500 text-primary-600'
                  : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
              }`}
            >
              {tab.label}
            </button>
          ))}
        </nav>
      </div>

      {activeTab === 'overview' ? (
        <Card>
          {!editing ? (
            <>
              <div className="flex justify-between items-center mb-4">
                <h3 className="text-lg font-medium text-gray-900">Overview</h3>
                <div className="flex gap-2">
                  <Button variant="secondary" onClick={() => setEditing(true)}>Edit</Button>
                  <Button variant="danger" onClick={() => setDeleteOpen(true)}>Delete</Button>
                </div>
              </div>
              <dl className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                  <dt className="text-sm font-medium text-gray-500">Identifier</dt>
                  <dd className="mt-1 text-sm text-gray-900">{unit.identifier || '—'}</dd>
                </div>
                <div>
                  <dt className="text-sm font-medium text-gray-500">Category</dt>
                  <dd className="mt-1 text-sm text-gray-900">{unit.category || '—'}</dd>
                </div>
                <div>
                  <dt className="text-sm font-medium text-gray-500">Make / Model</dt>
                  <dd className="mt-1 text-sm text-gray-900">
                    {[unit.make, unit.model].filter(Boolean).join(' ') || '—'}
                  </dd>
                </div>
                <div>
                  <dt className="text-sm font-medium text-gray-500">Year</dt>
                  <dd className="mt-1 text-sm text-gray-900">{unit.year || '—'}</dd>
                </div>
                <div>
                  <dt className="text-sm font-medium text-gray-500">VIN</dt>
                  <dd className="mt-1 text-sm text-gray-900 font-mono">{unit.vin || '—'}</dd>
                </div>
                <div>
                  <dt className="text-sm font-medium text-gray-500">License plate</dt>
                  <dd className="mt-1 text-sm text-gray-900">{unit.license_plate || '—'}</dd>
                </div>
                <div>
                  <dt className="text-sm font-medium text-gray-500">Purchase date</dt>
                  <dd className="mt-1 text-sm text-gray-900">{formatDate(unit.purchase_date)}</dd>
                </div>
                <div>
                  <dt className="text-sm font-medium text-gray-500">Status</dt>
                  <dd className="mt-1">
                    <Badge size="sm" variant={STATUS_VARIANT[unit.status] || 'default'}>
                      {unit.status || '—'}
                    </Badge>
                  </dd>
                </div>
              </dl>
            </>
          ) : (
            <form onSubmit={handleSaveEdit} className="space-y-3">
              <div className="flex justify-between items-center mb-2">
                <h3 className="text-lg font-medium text-gray-900">Edit Unit</h3>
              </div>
              <Input
                label="Identifier"
                value={editForm.identifier}
                onUpdateModelValue={updateEditForm('identifier')}
              />
              <Select
                label="Category"
                value={editForm.category}
                onUpdateModelValue={updateEditForm('category')}
                options={CATEGORY_OPTIONS}
                placeholder=""
              />
              <div className="grid grid-cols-2 gap-3">
                <Input label="Make" value={editForm.make} onUpdateModelValue={updateEditForm('make')} />
                <Input label="Model" value={editForm.model} onUpdateModelValue={updateEditForm('model')} />
              </div>
              <div className="grid grid-cols-2 gap-3">
                <Input label="Year" type="number" value={editForm.year} onUpdateModelValue={updateEditForm('year')} />
                <Input
                  label="License plate"
                  value={editForm.license_plate}
                  onUpdateModelValue={updateEditForm('license_plate')}
                />
              </div>
              <Input label="VIN" value={editForm.vin} onUpdateModelValue={updateEditForm('vin')} />
              <Input
                label="Purchase date"
                type="date"
                value={editForm.purchase_date}
                onUpdateModelValue={updateEditForm('purchase_date')}
              />
              <Select
                label="Status"
                value={editForm.status}
                onUpdateModelValue={updateEditForm('status')}
                options={STATUS_OPTIONS}
                placeholder=""
              />
              <div className="flex justify-end gap-2 pt-2">
                <Button type="button" variant="ghost" onClick={() => setEditing(false)}>Cancel</Button>
                <Button type="submit" loading={savingEdit}>Save</Button>
              </div>
            </form>
          )}
        </Card>
      ) : null}

      {activeTab === 'readings' ? (
        <Card>
          <div className="flex justify-between items-center mb-4">
            <h3 className="text-lg font-medium text-gray-900">Readings</h3>
            <Button size="sm" onClick={() => setReadingOpen(true)}>Add reading</Button>
          </div>
          {readingsLoading ? (
            <div className="py-8 flex justify-center"><Loading /></div>
          ) : readings.length === 0 ? (
            <p className="text-sm text-gray-500 py-4">No readings recorded.</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-gray-200">
                <thead className="bg-gray-50">
                  <tr>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Recorded</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Value</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Source</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-200 bg-white">
                  {readings.map((r) => (
                    <tr key={r.id}>
                      <td className="px-4 py-3 text-sm text-gray-700">{formatDateTime(r.recorded_at)}</td>
                      <td className="px-4 py-3 text-sm text-gray-700">{r.reading_type || '—'}</td>
                      <td className="px-4 py-3 text-sm text-gray-700">
                        {r.value !== null && r.value !== undefined
                          ? Number(r.value).toLocaleString()
                          : '—'}
                      </td>
                      <td className="px-4 py-3 text-sm text-gray-500">{r.source || '—'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Card>
      ) : null}

      {activeTab === 'assignments' ? (
        <Card>
          <div className="flex justify-between items-center mb-4">
            <h3 className="text-lg font-medium text-gray-900">Assignments</h3>
            <div className="flex gap-2">
              {activeAssignment ? (
                <Button
                  size="sm"
                  variant="secondary"
                  loading={endingAssignment}
                  onClick={handleEndAssignment}
                >
                  End current
                </Button>
              ) : null}
              <Button size="sm" onClick={() => setAssignmentOpen(true)}>Start assignment</Button>
            </div>
          </div>
          {assignmentsLoading ? (
            <div className="py-8 flex justify-center"><Loading /></div>
          ) : assignments.length === 0 ? (
            <p className="text-sm text-gray-500 py-4">No assignments recorded.</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-gray-200">
                <thead className="bg-gray-50">
                  <tr>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Driver</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Started</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Ended</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Notes</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-200 bg-white">
                  {assignments.map((a) => (
                    <tr key={a.id}>
                      <td className="px-4 py-3 text-sm text-gray-700">
                        {a.driver_name || a.driver_user_name || a.driver_user_id || '—'}
                      </td>
                      <td className="px-4 py-3 text-sm text-gray-700">{formatDateTime(a.started_at)}</td>
                      <td className="px-4 py-3 text-sm text-gray-700">
                        {a.ended_at ? formatDateTime(a.ended_at) : <Badge size="sm" variant="success">Active</Badge>}
                      </td>
                      <td className="px-4 py-3 text-sm text-gray-500">{a.notes || '—'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Card>
      ) : null}

      {activeTab === 'downtime' ? (
        <div className="space-y-4">
          {downtimeCurrent ? (
            <Alert variant="warning" closable={false}>
              <div className="flex items-center justify-between gap-3">
                <div>
                  <div className="font-medium">Currently in downtime</div>
                  <div className="text-sm">
                    {downtimeCurrent.reason || '—'}
                    {downtimeCurrent.category ? ` (${downtimeCurrent.category})` : ''} · started{' '}
                    {formatDateTime(downtimeCurrent.started_at)}
                  </div>
                </div>
                <Button
                  size="sm"
                  variant="secondary"
                  loading={endingDowntime}
                  onClick={handleEndDowntime}
                >
                  End downtime
                </Button>
              </div>
            </Alert>
          ) : null}

          <Card>
            <div className="flex justify-between items-center mb-4">
              <h3 className="text-lg font-medium text-gray-900">Downtime History</h3>
              <Button size="sm" onClick={() => setDowntimeOpen(true)} disabled={!!downtimeCurrent}>
                Start downtime
              </Button>
            </div>
            {downtimeLoading ? (
              <div className="py-8 flex justify-center"><Loading /></div>
            ) : downtimes.length === 0 ? (
              <p className="text-sm text-gray-500 py-4">No downtime recorded.</p>
            ) : (
              <div className="overflow-x-auto">
                <table className="min-w-full divide-y divide-gray-200">
                  <thead className="bg-gray-50">
                    <tr>
                      <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Started</th>
                      <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Ended</th>
                      <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Reason</th>
                      <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Category</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-gray-200 bg-white">
                    {downtimes.map((d) => (
                      <tr key={d.id}>
                        <td className="px-4 py-3 text-sm text-gray-700">{formatDateTime(d.started_at)}</td>
                        <td className="px-4 py-3 text-sm text-gray-700">
                          {d.ended_at ? formatDateTime(d.ended_at) : <Badge size="sm" variant="warning">Open</Badge>}
                        </td>
                        <td className="px-4 py-3 text-sm text-gray-700">{d.reason || '—'}</td>
                        <td className="px-4 py-3 text-sm text-gray-500">{d.category || '—'}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </Card>
        </div>
      ) : null}

      {activeTab === 'external' ? (
        <Card>
          <div className="flex justify-between items-center mb-4">
            <h3 className="text-lg font-medium text-gray-900">External Repairs</h3>
            <Button size="sm" onClick={() => setExternalOpen(true)}>New external repair</Button>
          </div>
          {externalLoading ? (
            <div className="py-8 flex justify-center"><Loading /></div>
          ) : external.length === 0 ? (
            <p className="text-sm text-gray-500 py-4">No external repairs.</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-gray-200">
                <thead className="bg-gray-50">
                  <tr>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Shop</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">In</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Out</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Cost</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-200 bg-white">
                  {external.map((r) => (
                    <tr key={r.id} className="hover:bg-gray-50">
                      <td className="px-4 py-3 text-sm">
                        <Link
                          to={`/cp/fleet/external-repairs?id=${r.id}`}
                          className="text-primary-600 hover:text-primary-500 font-medium"
                        >
                          {r.shop_name || '—'}
                        </Link>
                      </td>
                      <td className="px-4 py-3 text-sm text-gray-700">{formatDate(r.in_date)}</td>
                      <td className="px-4 py-3 text-sm text-gray-700">{formatDate(r.out_date)}</td>
                      <td className="px-4 py-3">
                        <Badge size="sm" variant="default">{r.status || '—'}</Badge>
                      </td>
                      <td className="px-4 py-3 text-sm text-gray-700">{formatMoney(r.cost ?? r.total_cost)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Card>
      ) : null}

      {activeTab === 'pm' ? (
        <Card>
          <div className="flex justify-between items-center mb-4">
            <h3 className="text-lg font-medium text-gray-900">PM Bindings</h3>
            <Button size="sm" onClick={() => setPmOpen(true)}>Add binding</Button>
          </div>
          {pmLoading ? (
            <div className="py-8 flex justify-center"><Loading /></div>
          ) : pmBindings.length === 0 ? (
            <p className="text-sm text-gray-500 py-4">No PM bindings.</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-gray-200">
                <thead className="bg-gray-50">
                  <tr>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Binding ID</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">PM Schedule</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Created</th>
                    <th className="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-200 bg-white">
                  {pmBindings.map((b) => (
                    <tr key={b.id}>
                      <td className="px-4 py-3 text-sm text-gray-700">{b.id}</td>
                      <td className="px-4 py-3 text-sm text-gray-700">
                        {b.pm_schedule_name || b.pm_schedule_id || '—'}
                      </td>
                      <td className="px-4 py-3 text-sm text-gray-700">{formatDateTime(b.created_at)}</td>
                      <td className="px-4 py-3 text-right">
                        <Button
                          size="sm"
                          variant="ghost"
                          className="text-red-600 hover:text-red-700"
                          onClick={() => handleDeletePmBinding(b.id)}
                        >
                          Delete
                        </Button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Card>
      ) : null}

      {/* Modals */}
      <Modal open={deleteOpen} title="Delete Fleet Unit" onClose={() => setDeleteOpen(false)}>
        <p className="text-sm text-gray-600 mb-4">
          Delete <strong>{getIdentifier(unit)}</strong>? This action cannot be undone.
        </p>
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={() => setDeleteOpen(false)}>Cancel</Button>
          <Button variant="danger" loading={deleting} onClick={handleDelete}>Delete</Button>
        </div>
      </Modal>

      <Modal open={readingOpen} title="Add Reading" onClose={() => setReadingOpen(false)}>
        <form onSubmit={submitReading} className="space-y-3">
          <Select
            label="Type"
            value={readingForm.reading_type}
            onUpdateModelValue={(v) => setReadingForm((p) => ({ ...p, reading_type: v }))}
            options={READING_TYPE_OPTIONS}
            placeholder=""
          />
          <Input
            label="Value"
            type="number"
            required
            value={readingForm.value}
            onUpdateModelValue={(v) => setReadingForm((p) => ({ ...p, value: v }))}
          />
          <Input
            label="Recorded at"
            type="datetime-local"
            value={readingForm.recorded_at}
            onUpdateModelValue={(v) => setReadingForm((p) => ({ ...p, recorded_at: v }))}
          />
          <div className="flex justify-end gap-2 pt-2">
            <Button type="button" variant="ghost" onClick={() => setReadingOpen(false)}>Cancel</Button>
            <Button type="submit" loading={readingSaving}>Add</Button>
          </div>
        </form>
      </Modal>

      <Modal open={assignmentOpen} title="Start Assignment" onClose={() => setAssignmentOpen(false)}>
        <form onSubmit={submitAssignment} className="space-y-3">
          <Input
            label="Driver user ID"
            type="number"
            required
            value={assignmentForm.driver_user_id}
            onUpdateModelValue={(v) => setAssignmentForm((p) => ({ ...p, driver_user_id: v }))}
          />
          <Textarea
            label="Notes"
            value={assignmentForm.notes}
            onUpdateModelValue={(v) => setAssignmentForm((p) => ({ ...p, notes: v }))}
            rows={3}
          />
          <div className="flex justify-end gap-2 pt-2">
            <Button type="button" variant="ghost" onClick={() => setAssignmentOpen(false)}>Cancel</Button>
            <Button type="submit" loading={assignmentSaving}>Start</Button>
          </div>
        </form>
      </Modal>

      <Modal open={downtimeOpen} title="Start Downtime" onClose={() => setDowntimeOpen(false)}>
        <form onSubmit={submitDowntime} className="space-y-3">
          <Input
            label="Reason"
            required
            value={downtimeForm.reason}
            onUpdateModelValue={(v) => setDowntimeForm((p) => ({ ...p, reason: v }))}
          />
          <Select
            label="Category"
            value={downtimeForm.category}
            onUpdateModelValue={(v) => setDowntimeForm((p) => ({ ...p, category: v }))}
            options={DOWNTIME_CATEGORIES}
            placeholder=""
          />
          <div className="flex justify-end gap-2 pt-2">
            <Button type="button" variant="ghost" onClick={() => setDowntimeOpen(false)}>Cancel</Button>
            <Button type="submit" loading={downtimeSaving}>Start</Button>
          </div>
        </form>
      </Modal>

      <Modal open={externalOpen} title="New External Repair" onClose={() => setExternalOpen(false)}>
        <form onSubmit={submitExternal} className="space-y-3">
          <Input
            label="Shop name"
            required
            value={externalForm.shop_name}
            onUpdateModelValue={(v) => setExternalForm((p) => ({ ...p, shop_name: v }))}
          />
          <div className="grid grid-cols-2 gap-3">
            <Input
              label="In date"
              type="date"
              value={externalForm.in_date}
              onUpdateModelValue={(v) => setExternalForm((p) => ({ ...p, in_date: v }))}
            />
            <Input
              label="Out date"
              type="date"
              value={externalForm.out_date}
              onUpdateModelValue={(v) => setExternalForm((p) => ({ ...p, out_date: v }))}
            />
          </div>
          <Textarea
            label="Description"
            value={externalForm.description}
            onUpdateModelValue={(v) => setExternalForm((p) => ({ ...p, description: v }))}
            rows={3}
          />
          <Input
            label="Cost"
            type="number"
            value={externalForm.cost}
            onUpdateModelValue={(v) => setExternalForm((p) => ({ ...p, cost: v }))}
          />
          <div className="flex justify-end gap-2 pt-2">
            <Button type="button" variant="ghost" onClick={() => setExternalOpen(false)}>Cancel</Button>
            <Button type="submit" loading={externalSaving}>Create</Button>
          </div>
        </form>
      </Modal>

      <Modal open={pmOpen} title="Add PM Binding" onClose={() => setPmOpen(false)}>
        <form onSubmit={submitPm} className="space-y-3">
          <Input
            label="PM schedule ID"
            type="number"
            required
            value={pmForm.pm_schedule_id}
            onUpdateModelValue={(v) => setPmForm((p) => ({ ...p, pm_schedule_id: v }))}
          />
          <div className="flex justify-end gap-2 pt-2">
            <Button type="button" variant="ghost" onClick={() => setPmOpen(false)}>Cancel</Button>
            <Button type="submit" loading={pmSaving}>Add</Button>
          </div>
        </form>
      </Modal>
    </div>
  )
}
