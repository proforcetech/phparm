import { useCallback, useEffect, useMemo, useState } from 'react'

import Alert from '../../components/ui/Alert'
import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Modal from '../../components/ui/Modal'
import Select from '../../components/ui/Select'
import Table from '../../components/ui/Table'
import Textarea from '../../components/ui/Textarea'
import { unitService, tenantService, tenantLeaseService } from '../../../services/propertyManagement.service'

const SECTIONS = [
  { key: 'units', label: 'Units' },
  { key: 'tenants', label: 'Tenants' },
  { key: 'leases', label: 'Leases' },
]

const UNIT_TYPE_OPTIONS = [
  { value: 'commercial', label: 'Commercial' },
  { value: 'residential', label: 'Residential' },
  { value: 'office', label: 'Office' },
  { value: 'retail', label: 'Retail' },
  { value: 'storage', label: 'Storage' },
  { value: 'industrial', label: 'Industrial' },
]

const STATUS_OPTIONS = [
  { value: 'active', label: 'Active' },
  { value: 'vacant', label: 'Vacant' },
  { value: 'under_renovation', label: 'Under Renovation' },
  { value: 'inactive', label: 'Inactive' },
]

const TENANT_ENTITY_TYPES = [
  { value: 'individual', label: 'Individual' },
  { value: 'business', label: 'Business' },
]

const TENANT_STATUS_OPTIONS = [
  { value: 'active', label: 'Active' },
  { value: 'inactive', label: 'Inactive' },
  { value: 'former', label: 'Former' },
]

const LEASE_BILLING_OPTIONS = [
  { value: 'landlord', label: 'Landlord pays' },
  { value: 'tenant', label: 'Tenant pays' },
  { value: 'split', label: 'Split per terms' },
]

const LEASE_STATUS_OPTIONS = [
  { value: 'active', label: 'Active' },
  { value: 'expired', label: 'Expired' },
  { value: 'terminated', label: 'Terminated' },
  { value: 'renewed', label: 'Renewed' },
]

export default function PropertyManagement() {
  const [section, setSection] = useState('units')

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-gray-900">Property Management</h1>
        <p className="mt-1 text-sm text-gray-500">
          Manage units, tenants, and leases for property-management customers. WO billing routing reads
          from the active lease for each unit.
        </p>
      </div>

      <div className="mb-4 flex flex-wrap gap-2 border-b border-gray-200">
        {SECTIONS.map((entry) => (
          <button
            key={entry.key}
            type="button"
            onClick={() => setSection(entry.key)}
            className={`px-4 py-2 -mb-px border-b-2 text-sm font-medium transition-colors ${
              section === entry.key
                ? 'border-indigo-600 text-indigo-700'
                : 'border-transparent text-gray-500 hover:text-gray-700'
            }`}
          >
            {entry.label}
          </button>
        ))}
      </div>

      {section === 'units' ? <UnitsSection /> : null}
      {section === 'tenants' ? <TenantsSection /> : null}
      {section === 'leases' ? <LeasesSection /> : null}
    </div>
  )
}

// -----------------------------------------------------------------------------
// Units
// -----------------------------------------------------------------------------
const initialUnitForm = {
  site_id: '',
  code: '',
  name: '',
  unit_type: 'commercial',
  floor: '',
  square_feet: '',
  bedrooms: '',
  bathrooms: '',
  status: 'active',
  notes: '',
}

function UnitsSection() {
  const [units, setUnits] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [message, setMessage] = useState('')
  const [search, setSearch] = useState('')
  const [siteFilter, setSiteFilter] = useState('')
  const [showModal, setShowModal] = useState(false)
  const [editingId, setEditingId] = useState(null)
  const [form, setForm] = useState(initialUnitForm)
  const [saving, setSaving] = useState(false)

  const load = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      const params = {}
      if (search) params.search = search
      if (siteFilter) params.site_id = siteFilter
      const result = await unitService.list(params)
      setUnits(result.units)
    } catch (err) {
      setError(err.response?.data?.error || err.message || 'Unable to load units')
    } finally {
      setLoading(false)
    }
  }, [search, siteFilter])

  useEffect(() => {
    load()
  }, [load])

  const openCreate = () => {
    setEditingId(null)
    setForm(initialUnitForm)
    setShowModal(true)
  }

  const openEdit = (unit) => {
    setEditingId(unit.id)
    setForm({
      site_id: unit.site_id ?? '',
      code: unit.code ?? '',
      name: unit.name ?? '',
      unit_type: unit.unit_type ?? 'commercial',
      floor: unit.floor ?? '',
      square_feet: unit.square_feet ?? '',
      bedrooms: unit.bedrooms ?? '',
      bathrooms: unit.bathrooms ?? '',
      status: unit.status ?? 'active',
      notes: unit.notes ?? '',
    })
    setShowModal(true)
  }

  const handleSave = async (event) => {
    event.preventDefault()
    setSaving(true)
    setError('')
    try {
      const payload = {
        site_id: Number(form.site_id),
        code: form.code,
        name: form.name || null,
        unit_type: form.unit_type,
        floor: form.floor || null,
        square_feet: form.square_feet === '' ? null : Number(form.square_feet),
        bedrooms: form.bedrooms === '' ? null : Number(form.bedrooms),
        bathrooms: form.bathrooms === '' ? null : Number(form.bathrooms),
        status: form.status,
        notes: form.notes || null,
      }
      if (editingId) {
        await unitService.update(editingId, payload)
        setMessage('Unit updated')
      } else {
        await unitService.create(payload)
        setMessage('Unit created')
      }
      setShowModal(false)
      load()
    } catch (err) {
      setError(err.response?.data?.error || err.message || 'Save failed')
    } finally {
      setSaving(false)
    }
  }

  const handleDelete = async (id) => {
    if (!window.confirm('Delete this unit? This cannot be undone.')) return
    try {
      await unitService.delete(id)
      setMessage('Unit deleted')
      load()
    } catch (err) {
      setError(err.response?.data?.error || err.message || 'Delete failed')
    }
  }

  const columns = useMemo(() => [
    { key: 'site_id', label: 'Site' },
    { key: 'code', label: 'Code' },
    { key: 'name', label: 'Name' },
    { key: 'unit_type', label: 'Type' },
    { key: 'floor', label: 'Floor' },
    { key: 'square_feet', label: 'Sq Ft' },
    { key: 'status', label: 'Status' },
    { key: 'actions', label: '', sortable: false },
  ], [])

  const cellRenderers = useMemo(() => ({
    status: ({ value }) => <Badge>{value}</Badge>,
    actions: ({ row }) => (
      <div className="flex justify-end gap-2">
        <Button variant="outline" size="sm" onClick={() => openEdit(row)}>Edit</Button>
        <Button variant="danger" size="sm" onClick={() => handleDelete(row.id)}>Delete</Button>
      </div>
    ),
  }), [])

  return (
    <div>
      {message ? <Alert variant="success" className="mb-4" onClose={() => setMessage('')}>{message}</Alert> : null}
      {error ? <Alert variant="danger" className="mb-4" onClose={() => setError('')}>{error}</Alert> : null}

      <Card>
        <div className="flex flex-wrap items-center justify-between gap-3 mb-4">
          <div className="flex flex-wrap gap-2">
            <Input
              placeholder="Search code or name"
              value={search}
              onChange={(event) => setSearch(event.target.value)}
              className="w-60"
            />
            <Input
              type="number"
              placeholder="Site ID"
              value={siteFilter}
              onChange={(event) => setSiteFilter(event.target.value)}
              className="w-32"
            />
            <Button variant="outline" onClick={load}>Refresh</Button>
          </div>
          <Button onClick={openCreate}>Add Unit</Button>
        </div>

        <Table
          columns={columns}
          data={units}
          loading={loading}
          cellRenderers={cellRenderers}
          renderEmpty="No units yet."
        />
      </Card>

      <Modal
        open={showModal}
        title={editingId ? 'Edit Unit' : 'Add Unit'}
        size="lg"
        onClose={() => setShowModal(false)}
      >
        <form onSubmit={handleSave} className="space-y-4">
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label className="block text-sm font-medium text-gray-700">Site ID *</label>
              <Input
                type="number"
                required
                value={form.site_id}
                onChange={(event) => setForm({ ...form, site_id: event.target.value })}
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700">Code *</label>
              <Input
                required
                placeholder="3B, Suite 200, etc."
                value={form.code}
                onChange={(event) => setForm({ ...form, code: event.target.value })}
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700">Name</label>
              <Input value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700">Type</label>
              <Select
                value={form.unit_type}
                options={UNIT_TYPE_OPTIONS}
                onChange={(event) => setForm({ ...form, unit_type: event.target.value })}
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700">Floor</label>
              <Input value={form.floor} onChange={(event) => setForm({ ...form, floor: event.target.value })} />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700">Square Feet</label>
              <Input
                type="number"
                value={form.square_feet}
                onChange={(event) => setForm({ ...form, square_feet: event.target.value })}
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700">Bedrooms</label>
              <Input
                type="number"
                value={form.bedrooms}
                onChange={(event) => setForm({ ...form, bedrooms: event.target.value })}
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700">Bathrooms</label>
              <Input
                type="number"
                step="0.5"
                value={form.bathrooms}
                onChange={(event) => setForm({ ...form, bathrooms: event.target.value })}
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700">Status</label>
              <Select
                value={form.status}
                options={STATUS_OPTIONS}
                onChange={(event) => setForm({ ...form, status: event.target.value })}
              />
            </div>
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700">Notes</label>
            <Textarea
              rows={3}
              value={form.notes}
              onChange={(event) => setForm({ ...form, notes: event.target.value })}
            />
          </div>
          <div className="flex justify-end gap-3">
            <Button type="button" variant="outline" onClick={() => setShowModal(false)}>Cancel</Button>
            <Button type="submit" loading={saving} disabled={saving}>
              {editingId ? 'Save Changes' : 'Create Unit'}
            </Button>
          </div>
        </form>
      </Modal>
    </div>
  )
}

// -----------------------------------------------------------------------------
// Tenants
// -----------------------------------------------------------------------------
const initialTenantForm = {
  display_name: '',
  entity_type: 'individual',
  primary_email: '',
  primary_phone: '',
  secondary_phone: '',
  status: 'active',
  move_in_date: '',
  company_id: '',
  portal_user_id: '',
  notes: '',
}

function TenantsSection() {
  const [tenants, setTenants] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [message, setMessage] = useState('')
  const [search, setSearch] = useState('')
  const [showModal, setShowModal] = useState(false)
  const [editingId, setEditingId] = useState(null)
  const [form, setForm] = useState(initialTenantForm)
  const [saving, setSaving] = useState(false)

  const load = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      const params = {}
      if (search) params.search = search
      const result = await tenantService.list(params)
      setTenants(result.tenants)
    } catch (err) {
      setError(err.response?.data?.error || err.message || 'Unable to load tenants')
    } finally {
      setLoading(false)
    }
  }, [search])

  useEffect(() => { load() }, [load])

  const openCreate = () => {
    setEditingId(null)
    setForm(initialTenantForm)
    setShowModal(true)
  }

  const openEdit = (tenant) => {
    setEditingId(tenant.id)
    setForm({
      display_name: tenant.display_name ?? '',
      entity_type: tenant.entity_type ?? 'individual',
      primary_email: tenant.primary_email ?? '',
      primary_phone: tenant.primary_phone ?? '',
      secondary_phone: tenant.secondary_phone ?? '',
      status: tenant.status ?? 'active',
      move_in_date: tenant.move_in_date ?? '',
      company_id: tenant.company_id ?? '',
      portal_user_id: tenant.portal_user_id ?? '',
      notes: tenant.notes ?? '',
    })
    setShowModal(true)
  }

  const handleSave = async (event) => {
    event.preventDefault()
    setSaving(true)
    setError('')
    try {
      const payload = {
        display_name: form.display_name,
        entity_type: form.entity_type,
        primary_email: form.primary_email || null,
        primary_phone: form.primary_phone || null,
        secondary_phone: form.secondary_phone || null,
        status: form.status,
        move_in_date: form.move_in_date || null,
        company_id: form.company_id === '' ? null : Number(form.company_id),
        portal_user_id: form.portal_user_id === '' ? null : Number(form.portal_user_id),
        notes: form.notes || null,
      }
      if (editingId) {
        await tenantService.update(editingId, payload)
        setMessage('Tenant updated')
      } else {
        await tenantService.create(payload)
        setMessage('Tenant created')
      }
      setShowModal(false)
      load()
    } catch (err) {
      setError(err.response?.data?.error || err.message || 'Save failed')
    } finally {
      setSaving(false)
    }
  }

  const columns = useMemo(() => [
    { key: 'display_name', label: 'Name' },
    { key: 'entity_type', label: 'Type' },
    { key: 'primary_email', label: 'Email' },
    { key: 'primary_phone', label: 'Phone' },
    { key: 'status', label: 'Status' },
    { key: 'move_in_date', label: 'Move-in' },
    { key: 'actions', label: '', sortable: false },
  ], [])

  const cellRenderers = useMemo(() => ({
    status: ({ value }) => <Badge>{value}</Badge>,
    actions: ({ row }) => (
      <div className="flex justify-end gap-2">
        <Button variant="outline" size="sm" onClick={() => openEdit(row)}>Edit</Button>
      </div>
    ),
  }), [])

  return (
    <div>
      {message ? <Alert variant="success" className="mb-4" onClose={() => setMessage('')}>{message}</Alert> : null}
      {error ? <Alert variant="danger" className="mb-4" onClose={() => setError('')}>{error}</Alert> : null}

      <Card>
        <div className="flex flex-wrap items-center justify-between gap-3 mb-4">
          <div className="flex gap-2">
            <Input
              placeholder="Search name, email, or phone"
              value={search}
              onChange={(event) => setSearch(event.target.value)}
              className="w-72"
            />
            <Button variant="outline" onClick={load}>Refresh</Button>
          </div>
          <Button onClick={openCreate}>Add Tenant</Button>
        </div>

        <Table
          columns={columns}
          data={tenants}
          loading={loading}
          cellRenderers={cellRenderers}
          renderEmpty="No tenants yet."
        />
      </Card>

      <Modal
        open={showModal}
        title={editingId ? 'Edit Tenant' : 'Add Tenant'}
        size="lg"
        onClose={() => setShowModal(false)}
      >
        <form onSubmit={handleSave} className="space-y-4">
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label className="block text-sm font-medium text-gray-700">Display Name *</label>
              <Input
                required
                value={form.display_name}
                onChange={(event) => setForm({ ...form, display_name: event.target.value })}
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700">Entity Type</label>
              <Select
                value={form.entity_type}
                options={TENANT_ENTITY_TYPES}
                onChange={(event) => setForm({ ...form, entity_type: event.target.value })}
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700">Primary Email</label>
              <Input
                type="email"
                value={form.primary_email}
                onChange={(event) => setForm({ ...form, primary_email: event.target.value })}
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700">Primary Phone</label>
              <Input
                value={form.primary_phone}
                onChange={(event) => setForm({ ...form, primary_phone: event.target.value })}
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700">Secondary Phone</label>
              <Input
                value={form.secondary_phone}
                onChange={(event) => setForm({ ...form, secondary_phone: event.target.value })}
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700">Status</label>
              <Select
                value={form.status}
                options={TENANT_STATUS_OPTIONS}
                onChange={(event) => setForm({ ...form, status: event.target.value })}
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700">Move-in Date</label>
              <Input
                type="date"
                value={form.move_in_date}
                onChange={(event) => setForm({ ...form, move_in_date: event.target.value })}
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700">Company ID</label>
              <Input
                type="number"
                value={form.company_id}
                onChange={(event) => setForm({ ...form, company_id: event.target.value })}
              />
              <p className="text-xs text-gray-500 mt-1">Optional: link to a customer company.</p>
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700">Portal User ID</label>
              <Input
                type="number"
                value={form.portal_user_id}
                onChange={(event) => setForm({ ...form, portal_user_id: event.target.value })}
              />
              <p className="text-xs text-gray-500 mt-1">Optional: link to a `users` row for portal access.</p>
            </div>
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700">Notes</label>
            <Textarea
              rows={3}
              value={form.notes}
              onChange={(event) => setForm({ ...form, notes: event.target.value })}
            />
          </div>
          <div className="flex justify-end gap-3">
            <Button type="button" variant="outline" onClick={() => setShowModal(false)}>Cancel</Button>
            <Button type="submit" loading={saving} disabled={saving}>
              {editingId ? 'Save Changes' : 'Create Tenant'}
            </Button>
          </div>
        </form>
      </Modal>
    </div>
  )
}

// -----------------------------------------------------------------------------
// Leases
// -----------------------------------------------------------------------------
const initialLeaseForm = {
  tenant_id: '',
  unit_id: '',
  start_date: '',
  end_date: '',
  monthly_rent: '',
  deposit_amount: '',
  billing_responsibility: 'landlord',
  status: 'active',
  terms: '',
  notes: '',
}

function LeasesSection() {
  const [leases, setLeases] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [message, setMessage] = useState('')
  const [filters, setFilters] = useState({ status: '', tenant_id: '', unit_id: '' })
  const [showModal, setShowModal] = useState(false)
  const [editingId, setEditingId] = useState(null)
  const [form, setForm] = useState(initialLeaseForm)
  const [saving, setSaving] = useState(false)

  const load = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      const params = {}
      if (filters.status) params.status = filters.status
      if (filters.tenant_id) params.tenant_id = filters.tenant_id
      if (filters.unit_id) params.unit_id = filters.unit_id
      const result = await tenantLeaseService.list(params)
      setLeases(result.leases)
    } catch (err) {
      setError(err.response?.data?.error || err.message || 'Unable to load leases')
    } finally {
      setLoading(false)
    }
  }, [filters])

  useEffect(() => { load() }, [load])

  const openCreate = () => {
    setEditingId(null)
    setForm(initialLeaseForm)
    setShowModal(true)
  }

  const openEdit = (lease) => {
    setEditingId(lease.id)
    setForm({
      tenant_id: lease.tenant_id ?? '',
      unit_id: lease.unit_id ?? '',
      start_date: lease.start_date ?? '',
      end_date: lease.end_date ?? '',
      monthly_rent: lease.monthly_rent ?? '',
      deposit_amount: lease.deposit_amount ?? '',
      billing_responsibility: lease.billing_responsibility ?? 'landlord',
      status: lease.status ?? 'active',
      terms: lease.terms ?? '',
      notes: lease.notes ?? '',
    })
    setShowModal(true)
  }

  const handleSave = async (event) => {
    event.preventDefault()
    setSaving(true)
    setError('')
    try {
      const payload = {
        tenant_id: Number(form.tenant_id),
        unit_id: Number(form.unit_id),
        start_date: form.start_date,
        end_date: form.end_date || null,
        monthly_rent: form.monthly_rent === '' ? null : Number(form.monthly_rent),
        deposit_amount: form.deposit_amount === '' ? null : Number(form.deposit_amount),
        billing_responsibility: form.billing_responsibility,
        status: form.status,
        terms: form.terms || null,
        notes: form.notes || null,
      }
      if (editingId) {
        await tenantLeaseService.update(editingId, payload)
        setMessage('Lease updated')
      } else {
        await tenantLeaseService.create(payload)
        setMessage('Lease created')
      }
      setShowModal(false)
      load()
    } catch (err) {
      setError(err.response?.data?.error || err.message || 'Save failed')
    } finally {
      setSaving(false)
    }
  }

  const columns = useMemo(() => [
    { key: 'id', label: 'ID' },
    { key: 'tenant_id', label: 'Tenant' },
    { key: 'unit_id', label: 'Unit' },
    { key: 'start_date', label: 'Start' },
    { key: 'end_date', label: 'End' },
    { key: 'monthly_rent', label: 'Rent' },
    { key: 'billing_responsibility', label: 'Billing' },
    { key: 'status', label: 'Status' },
    { key: 'actions', label: '', sortable: false },
  ], [])

  const cellRenderers = useMemo(() => ({
    end_date: ({ value }) => value ?? <span className="text-gray-400">month-to-month</span>,
    monthly_rent: ({ value }) => (value == null ? '—' : `$${Number(value).toFixed(2)}`),
    billing_responsibility: ({ value }) => <Badge>{value}</Badge>,
    status: ({ value }) => <Badge>{value}</Badge>,
    actions: ({ row }) => (
      <div className="flex justify-end gap-2">
        <Button variant="outline" size="sm" onClick={() => openEdit(row)}>Edit</Button>
      </div>
    ),
  }), [])

  return (
    <div>
      {message ? <Alert variant="success" className="mb-4" onClose={() => setMessage('')}>{message}</Alert> : null}
      {error ? <Alert variant="danger" className="mb-4" onClose={() => setError('')}>{error}</Alert> : null}

      <Card>
        <div className="flex flex-wrap items-center justify-between gap-3 mb-4">
          <div className="flex flex-wrap gap-2">
            <Select
              value={filters.status}
              options={[{ value: '', label: 'All statuses' }, ...LEASE_STATUS_OPTIONS]}
              onChange={(event) => setFilters({ ...filters, status: event.target.value })}
            />
            <Input
              type="number"
              placeholder="Tenant ID"
              value={filters.tenant_id}
              onChange={(event) => setFilters({ ...filters, tenant_id: event.target.value })}
              className="w-32"
            />
            <Input
              type="number"
              placeholder="Unit ID"
              value={filters.unit_id}
              onChange={(event) => setFilters({ ...filters, unit_id: event.target.value })}
              className="w-32"
            />
            <Button variant="outline" onClick={load}>Refresh</Button>
          </div>
          <Button onClick={openCreate}>Add Lease</Button>
        </div>

        <Table
          columns={columns}
          data={leases}
          loading={loading}
          cellRenderers={cellRenderers}
          renderEmpty="No leases yet."
        />
      </Card>

      <Modal
        open={showModal}
        title={editingId ? 'Edit Lease' : 'Add Lease'}
        size="lg"
        onClose={() => setShowModal(false)}
      >
        <form onSubmit={handleSave} className="space-y-4">
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label className="block text-sm font-medium text-gray-700">Tenant ID *</label>
              <Input
                type="number"
                required
                value={form.tenant_id}
                onChange={(event) => setForm({ ...form, tenant_id: event.target.value })}
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700">Unit ID *</label>
              <Input
                type="number"
                required
                value={form.unit_id}
                onChange={(event) => setForm({ ...form, unit_id: event.target.value })}
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700">Start Date *</label>
              <Input
                type="date"
                required
                value={form.start_date}
                onChange={(event) => setForm({ ...form, start_date: event.target.value })}
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700">End Date</label>
              <Input
                type="date"
                value={form.end_date}
                onChange={(event) => setForm({ ...form, end_date: event.target.value })}
              />
              <p className="text-xs text-gray-500 mt-1">Leave blank for month-to-month.</p>
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700">Monthly Rent</label>
              <Input
                type="number"
                step="0.01"
                value={form.monthly_rent}
                onChange={(event) => setForm({ ...form, monthly_rent: event.target.value })}
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700">Deposit Amount</label>
              <Input
                type="number"
                step="0.01"
                value={form.deposit_amount}
                onChange={(event) => setForm({ ...form, deposit_amount: event.target.value })}
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700">Billing Responsibility</label>
              <Select
                value={form.billing_responsibility}
                options={LEASE_BILLING_OPTIONS}
                onChange={(event) => setForm({ ...form, billing_responsibility: event.target.value })}
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700">Status</label>
              <Select
                value={form.status}
                options={LEASE_STATUS_OPTIONS}
                onChange={(event) => setForm({ ...form, status: event.target.value })}
              />
            </div>
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700">Terms</label>
            <Textarea
              rows={3}
              value={form.terms}
              onChange={(event) => setForm({ ...form, terms: event.target.value })}
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700">Notes</label>
            <Textarea
              rows={2}
              value={form.notes}
              onChange={(event) => setForm({ ...form, notes: event.target.value })}
            />
          </div>
          <div className="flex justify-end gap-3">
            <Button type="button" variant="outline" onClick={() => setShowModal(false)}>Cancel</Button>
            <Button type="submit" loading={saving} disabled={saving}>
              {editingId ? 'Save Changes' : 'Create Lease'}
            </Button>
          </div>
        </form>
      </Modal>
    </div>
  )
}
