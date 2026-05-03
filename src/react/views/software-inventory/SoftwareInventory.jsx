import { useCallback, useEffect, useMemo, useState } from 'react'

import Alert from '../../components/ui/Alert'
import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Modal from '../../components/ui/Modal'
import softwareInventoryService from '../../../services/software-inventory.service'

/**
 * Phase 14 / M9 admin UI for the software CMDB. Tabs:
 *   1. Catalog       — software_assets (publishers, titles, versions)
 *   2. License pools — license_seats (per-customer pools, capacity, status)
 *   3. Assignments   — license_assignments (active + history)
 *   4. Compliance    — over-allocation + unlicensed installs feed
 *
 * Read perm: software_inventory.view  (server-enforced)
 * Write perm: software_inventory.manage
 */

const TABS = [
  { id: 'catalog', label: 'Catalog' },
  { id: 'pools', label: 'License pools' },
  { id: 'assignments', label: 'Assignments' },
  { id: 'compliance', label: 'Compliance' },
]

const CATEGORIES = ['os', 'productivity', 'security', 'utility', 'development', 'business', 'other']
const PLATFORMS = ['windows', 'macos', 'linux', 'ios', 'android', 'web', 'cross_platform']
const LICENSE_TYPES = ['perpetual', 'subscription', 'volume', 'oem', 'named_user', 'concurrent', 'device', 'trial']
const LICENSE_STATUSES = ['active', 'expired', 'cancelled', 'suspended']
const ASSIGNEE_TYPES = ['user', 'site_asset']

const STATUS_VARIANT = {
  active: 'success',
  expired: 'danger',
  cancelled: 'secondary',
  suspended: 'warning',
}

function titleize(s) {
  if (!s) return ''
  return String(s).replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())
}

export default function SoftwareInventory() {
  const [tab, setTab] = useState('catalog')
  const [error, setError] = useState('')

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Software CMDB</h1>
          <p className="mt-1 text-sm text-gray-500">
            Catalog of software titles, license pools, seat assignments, and the compliance feed.
          </p>
        </div>
      </div>

      <div className="border-b border-gray-200">
        <nav className="-mb-px flex flex-wrap gap-6">
          {TABS.map((t) => (
            <button
              key={t.id}
              onClick={() => setTab(t.id)}
              className={`py-2 px-1 border-b-2 text-sm font-medium ${
                tab === t.id
                  ? 'border-blue-600 text-blue-700'
                  : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
              }`}
            >
              {t.label}
            </button>
          ))}
        </nav>
      </div>

      {error ? <Alert variant="danger">{error}</Alert> : null}

      {tab === 'catalog' && <CatalogTab onError={setError} />}
      {tab === 'pools' && <PoolsTab onError={setError} />}
      {tab === 'assignments' && <AssignmentsTab onError={setError} />}
      {tab === 'compliance' && <ComplianceTab onError={setError} />}
    </div>
  )
}

// ---------------------------------------------------------------------------
// Catalog tab
// ---------------------------------------------------------------------------

function CatalogTab({ onError }) {
  const [titles, setTitles] = useState([])
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')
  const [showForm, setShowForm] = useState(false)
  const [editing, setEditing] = useState(null)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const params = { per_page: 100 }
      if (search) params.search = search
      const res = await softwareInventoryService.listTitles(params)
      setTitles(res?.data?.titles || [])
    } catch (err) {
      console.error('Failed to load titles', err)
      onError('Unable to load software catalog.')
    } finally {
      setLoading(false)
    }
  }, [search, onError])

  useEffect(() => { load() }, [load])

  const remove = async (row) => {
    if (!window.confirm(`Delete "${row.publisher} ${row.title}"? This cannot be undone.`)) return
    try {
      await softwareInventoryService.deleteTitle(row.id)
      await load()
    } catch (err) {
      onError(err?.response?.data?.message || 'Unable to delete title.')
    }
  }

  return (
    <div className="space-y-4">
      <Card>
        <div className="flex flex-wrap items-center gap-3">
          <Input
            label=""
            placeholder="Search publisher, title, SKU…"
            modelValue={search}
            onUpdateModelValue={setSearch}
            fullWidth={false}
          />
          <Button variant="outline" onClick={load}>Search</Button>
          <div className="flex-1" />
          <Button variant="primary" onClick={() => { setEditing(null); setShowForm(true) }}>
            New title
          </Button>
        </div>
      </Card>

      <Card>
        {loading ? (
          <div className="py-10 flex justify-center"><Loading text="Loading titles..." /></div>
        ) : titles.length === 0 ? (
          <div className="py-10 text-center text-gray-500">No software titles found.</div>
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Publisher</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Title</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Version</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Platform</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Category</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Customer</th>
                  <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                </tr>
              </thead>
              <tbody className="bg-white divide-y divide-gray-100">
                {titles.map((row) => (
                  <tr key={row.id} className="hover:bg-gray-50">
                    <td className="px-4 py-2 text-sm text-gray-900">{row.publisher}</td>
                    <td className="px-4 py-2 text-sm text-gray-900">
                      {row.title}
                      {row.edition ? <span className="text-gray-500 ml-1">({row.edition})</span> : null}
                    </td>
                    <td className="px-4 py-2 text-sm text-gray-700">{row.version || '—'}</td>
                    <td className="px-4 py-2 text-sm text-gray-700">{row.platform ? titleize(row.platform) : '—'}</td>
                    <td className="px-4 py-2 text-sm text-gray-700">{row.category ? titleize(row.category) : '—'}</td>
                    <td className="px-4 py-2 text-sm text-gray-700">
                      {row.customer_id ? `#${row.customer_id}` : <span className="text-gray-400">shared</span>}
                    </td>
                    <td className="px-4 py-2 text-sm text-right space-x-2">
                      <button
                        onClick={() => { setEditing(row); setShowForm(true) }}
                        className="text-blue-600 hover:underline"
                      >Edit</button>
                      <button
                        onClick={() => remove(row)}
                        className="text-rose-600 hover:underline"
                      >Delete</button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      {showForm && (
        <TitleFormModal
          row={editing}
          onClose={() => setShowForm(false)}
          onSaved={async () => { setShowForm(false); await load() }}
          onError={onError}
        />
      )}
    </div>
  )
}

function TitleFormModal({ row, onClose, onSaved, onError }) {
  const [form, setForm] = useState({
    publisher: row?.publisher || '',
    title: row?.title || '',
    version: row?.version || '',
    edition: row?.edition || '',
    category: row?.category || '',
    platform: row?.platform || '',
    sku: row?.sku || '',
    description: row?.description || '',
    customer_id: row?.customer_id || '',
    is_active: row ? !!row.is_active : true,
  })
  const [submitting, setSubmitting] = useState(false)

  const submit = async () => {
    setSubmitting(true)
    try {
      const payload = {
        ...form,
        customer_id: form.customer_id === '' ? null : Number(form.customer_id),
        is_active: form.is_active ? 1 : 0,
      }
      if (row?.id) {
        await softwareInventoryService.updateTitle(row.id, payload)
      } else {
        await softwareInventoryService.createTitle(payload)
      }
      await onSaved()
    } catch (err) {
      onError(err?.response?.data?.message || 'Unable to save title.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Modal open title={row?.id ? `Edit title #${row.id}` : 'New software title'} onClose={onClose} size="lg">
      <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <Input label="Publisher" required modelValue={form.publisher} onUpdateModelValue={(v) => setForm((p) => ({ ...p, publisher: v }))} />
        <Input label="Title" required modelValue={form.title} onUpdateModelValue={(v) => setForm((p) => ({ ...p, title: v }))} />
        <Input label="Version" modelValue={form.version} onUpdateModelValue={(v) => setForm((p) => ({ ...p, version: v }))} />
        <Input label="Edition" modelValue={form.edition} onUpdateModelValue={(v) => setForm((p) => ({ ...p, edition: v }))} />

        <label className="block text-sm font-medium text-gray-700">
          Category
          <select
            value={form.category}
            onChange={(e) => setForm((p) => ({ ...p, category: e.target.value }))}
            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
          >
            <option value="">—</option>
            {CATEGORIES.map((c) => <option key={c} value={c}>{titleize(c)}</option>)}
          </select>
        </label>

        <label className="block text-sm font-medium text-gray-700">
          Platform
          <select
            value={form.platform}
            onChange={(e) => setForm((p) => ({ ...p, platform: e.target.value }))}
            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
          >
            <option value="">—</option>
            {PLATFORMS.map((p) => <option key={p} value={p}>{titleize(p)}</option>)}
          </select>
        </label>

        <Input label="SKU" modelValue={form.sku} onUpdateModelValue={(v) => setForm((p) => ({ ...p, sku: v }))} />
        <Input label="Customer ID (blank = shared catalog)" modelValue={form.customer_id} onUpdateModelValue={(v) => setForm((p) => ({ ...p, customer_id: v }))} />
        <div className="sm:col-span-2">
          <Input label="Description" modelValue={form.description} onUpdateModelValue={(v) => setForm((p) => ({ ...p, description: v }))} />
        </div>
        <label className="flex items-center gap-2 text-sm text-gray-700">
          <input
            type="checkbox"
            checked={form.is_active}
            onChange={(e) => setForm((p) => ({ ...p, is_active: e.target.checked }))}
            className="rounded border-gray-300"
          />
          Active
        </label>
      </div>
      <div className="mt-5 flex justify-end gap-2">
        <Button variant="ghost" onClick={onClose} disabled={submitting}>Cancel</Button>
        <Button variant="primary" onClick={submit} disabled={submitting || !form.publisher || !form.title}>
          {submitting ? 'Saving...' : 'Save'}
        </Button>
      </div>
    </Modal>
  )
}

// ---------------------------------------------------------------------------
// License pools tab
// ---------------------------------------------------------------------------

function PoolsTab({ onError }) {
  const [pools, setPools] = useState([])
  const [loading, setLoading] = useState(true)
  const [statusFilter, setStatusFilter] = useState('')
  const [showForm, setShowForm] = useState(false)
  const [editing, setEditing] = useState(null)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const params = { per_page: 100 }
      if (statusFilter) params.status = statusFilter
      const res = await softwareInventoryService.listPools(params)
      setPools(res?.data?.pools || [])
    } catch (err) {
      console.error('Failed to load pools', err)
      onError('Unable to load license pools.')
    } finally {
      setLoading(false)
    }
  }, [statusFilter, onError])

  useEffect(() => { load() }, [load])

  const remove = async (row) => {
    if (row.seats_assigned > 0) {
      onError(`Cannot delete: ${row.seats_assigned} active assignments. Unassign first.`)
      return
    }
    if (!window.confirm(`Delete pool #${row.id}? This cannot be undone.`)) return
    try {
      await softwareInventoryService.deletePool(row.id)
      await load()
    } catch (err) {
      onError(err?.response?.data?.message || 'Unable to delete pool.')
    }
  }

  return (
    <div className="space-y-4">
      <Card>
        <div className="flex flex-wrap items-center gap-3">
          <span className="text-sm font-medium text-gray-700">Status:</span>
          <select
            value={statusFilter}
            onChange={(e) => setStatusFilter(e.target.value)}
            className="border rounded-md px-3 py-2 text-sm"
          >
            <option value="">All</option>
            {LICENSE_STATUSES.map((s) => <option key={s} value={s}>{titleize(s)}</option>)}
          </select>
          <div className="flex-1" />
          <Button variant="primary" onClick={() => { setEditing(null); setShowForm(true) }}>
            New pool
          </Button>
        </div>
      </Card>

      <Card>
        {loading ? (
          <div className="py-10 flex justify-center"><Loading text="Loading pools..." /></div>
        ) : pools.length === 0 ? (
          <div className="py-10 text-center text-gray-500">No license pools found.</div>
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Pool</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Customer</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Seats</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Expires</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                  <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                </tr>
              </thead>
              <tbody className="bg-white divide-y divide-gray-100">
                {pools.map((row) => (
                  <tr key={row.id} className="hover:bg-gray-50">
                    <td className="px-4 py-2 text-sm text-gray-900">
                      #{row.id} · title {row.software_asset_id}
                    </td>
                    <td className="px-4 py-2 text-sm text-gray-700">#{row.customer_id}</td>
                    <td className="px-4 py-2 text-sm text-gray-700">{titleize(row.license_type)}</td>
                    <td className="px-4 py-2 text-sm text-gray-700">
                      <span className={row.over_allocated ? 'text-rose-700 font-semibold' : ''}>
                        {row.seats_assigned} / {row.seats_owned}
                      </span>
                      {row.over_allocated ? <Badge variant="danger" className="ml-2">over</Badge> : null}
                    </td>
                    <td className="px-4 py-2 text-sm text-gray-700">{row.expires_at || '—'}</td>
                    <td className="px-4 py-2 text-sm">
                      <Badge variant={STATUS_VARIANT[row.status] || 'secondary'}>
                        {titleize(row.status)}
                      </Badge>
                    </td>
                    <td className="px-4 py-2 text-sm text-right space-x-2">
                      <button onClick={() => { setEditing(row); setShowForm(true) }} className="text-blue-600 hover:underline">Edit</button>
                      <button onClick={() => remove(row)} className="text-rose-600 hover:underline">Delete</button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      {showForm && (
        <PoolFormModal
          row={editing}
          onClose={() => setShowForm(false)}
          onSaved={async () => { setShowForm(false); await load() }}
          onError={onError}
        />
      )}
    </div>
  )
}

function PoolFormModal({ row, onClose, onSaved, onError }) {
  const [form, setForm] = useState({
    software_asset_id: row?.software_asset_id || '',
    customer_id: row?.customer_id || '',
    license_type: row?.license_type || 'subscription',
    license_key_ref: row?.license_key_ref || '',
    vendor_name: row?.vendor_name || '',
    purchase_order_ref: row?.purchase_order_ref || '',
    purchased_at: row?.purchased_at || '',
    expires_at: row?.expires_at || '',
    seats_owned: row?.seats_owned ?? 1,
    cost_per_seat_cents: row?.cost_per_seat_cents ?? '',
    status: row?.status || 'active',
    notes: row?.notes || '',
  })
  const [submitting, setSubmitting] = useState(false)

  const submit = async () => {
    setSubmitting(true)
    try {
      const payload = {
        ...form,
        software_asset_id: Number(form.software_asset_id),
        customer_id: Number(form.customer_id),
        seats_owned: Number(form.seats_owned),
        cost_per_seat_cents: form.cost_per_seat_cents === '' ? null : Number(form.cost_per_seat_cents),
        purchased_at: form.purchased_at || null,
        expires_at: form.expires_at || null,
      }
      if (row?.id) {
        const { software_asset_id, customer_id, ...rest } = payload
        await softwareInventoryService.updatePool(row.id, rest)
      } else {
        await softwareInventoryService.createPool(payload)
      }
      await onSaved()
    } catch (err) {
      onError(err?.response?.data?.message || 'Unable to save pool.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Modal open title={row?.id ? `Edit pool #${row.id}` : 'New license pool'} onClose={onClose} size="lg">
      <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <Input label="Software title ID" required disabled={!!row} modelValue={form.software_asset_id} onUpdateModelValue={(v) => setForm((p) => ({ ...p, software_asset_id: v }))} />
        <Input label="Customer ID" required disabled={!!row} modelValue={form.customer_id} onUpdateModelValue={(v) => setForm((p) => ({ ...p, customer_id: v }))} />
        <label className="block text-sm font-medium text-gray-700">
          License type
          <select
            value={form.license_type}
            onChange={(e) => setForm((p) => ({ ...p, license_type: e.target.value }))}
            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
          >
            {LICENSE_TYPES.map((t) => <option key={t} value={t}>{titleize(t)}</option>)}
          </select>
        </label>
        <label className="block text-sm font-medium text-gray-700">
          Status
          <select
            value={form.status}
            onChange={(e) => setForm((p) => ({ ...p, status: e.target.value }))}
            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
          >
            {LICENSE_STATUSES.map((s) => <option key={s} value={s}>{titleize(s)}</option>)}
          </select>
        </label>
        <Input label="Seats owned" type="number" required modelValue={form.seats_owned} onUpdateModelValue={(v) => setForm((p) => ({ ...p, seats_owned: v }))} />
        <Input label="Cost per seat (cents)" type="number" modelValue={form.cost_per_seat_cents} onUpdateModelValue={(v) => setForm((p) => ({ ...p, cost_per_seat_cents: v }))} />
        <Input label="Vendor name" modelValue={form.vendor_name} onUpdateModelValue={(v) => setForm((p) => ({ ...p, vendor_name: v }))} />
        <Input label="License key ref" modelValue={form.license_key_ref} onUpdateModelValue={(v) => setForm((p) => ({ ...p, license_key_ref: v }))} />
        <Input label="PO ref" modelValue={form.purchase_order_ref} onUpdateModelValue={(v) => setForm((p) => ({ ...p, purchase_order_ref: v }))} />
        <Input label="Purchased on" type="date" modelValue={form.purchased_at} onUpdateModelValue={(v) => setForm((p) => ({ ...p, purchased_at: v }))} />
        <Input label="Expires on" type="date" modelValue={form.expires_at} onUpdateModelValue={(v) => setForm((p) => ({ ...p, expires_at: v }))} />
        <div className="sm:col-span-2">
          <Input label="Notes" modelValue={form.notes} onUpdateModelValue={(v) => setForm((p) => ({ ...p, notes: v }))} />
        </div>
      </div>
      <div className="mt-5 flex justify-end gap-2">
        <Button variant="ghost" onClick={onClose} disabled={submitting}>Cancel</Button>
        <Button variant="primary" onClick={submit} disabled={submitting}>
          {submitting ? 'Saving...' : 'Save'}
        </Button>
      </div>
    </Modal>
  )
}

// ---------------------------------------------------------------------------
// Assignments tab
// ---------------------------------------------------------------------------

function AssignmentsTab({ onError }) {
  const [rows, setRows] = useState([])
  const [loading, setLoading] = useState(true)
  const [activeOnly, setActiveOnly] = useState(true)
  const [showAssign, setShowAssign] = useState(false)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const params = { per_page: 100 }
      if (activeOnly) params.active_only = 1
      const res = await softwareInventoryService.listAssignments(params)
      setRows(res?.data?.assignments || [])
    } catch (err) {
      console.error('Failed to load assignments', err)
      onError('Unable to load license assignments.')
    } finally {
      setLoading(false)
    }
  }, [activeOnly, onError])

  useEffect(() => { load() }, [load])

  const unassign = async (row) => {
    const reason = window.prompt('Reason for unassigning? (optional)') || ''
    try {
      await softwareInventoryService.unassign(row.id, { reason })
      await load()
    } catch (err) {
      onError(err?.response?.data?.message || 'Unable to unassign.')
    }
  }

  return (
    <div className="space-y-4">
      <Card>
        <div className="flex flex-wrap items-center gap-3">
          <label className="flex items-center gap-2 text-sm text-gray-700">
            <input
              type="checkbox"
              checked={activeOnly}
              onChange={(e) => setActiveOnly(e.target.checked)}
              className="rounded border-gray-300"
            />
            Active only
          </label>
          <div className="flex-1" />
          <Button variant="primary" onClick={() => setShowAssign(true)}>Assign seat</Button>
        </div>
      </Card>

      <Card>
        {loading ? (
          <div className="py-10 flex justify-center"><Loading text="Loading assignments..." /></div>
        ) : rows.length === 0 ? (
          <div className="py-10 text-center text-gray-500">No assignments found.</div>
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">ID</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Pool</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Assignee</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Assigned</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Unassigned</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                  <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                </tr>
              </thead>
              <tbody className="bg-white divide-y divide-gray-100">
                {rows.map((row) => (
                  <tr key={row.id} className="hover:bg-gray-50">
                    <td className="px-4 py-2 text-sm text-gray-900">#{row.id}</td>
                    <td className="px-4 py-2 text-sm text-gray-700">#{row.license_seat_id}</td>
                    <td className="px-4 py-2 text-sm text-gray-700">
                      {row.assignee_type === 'user'
                        ? `User #${row.assignee_user_id}`
                        : `Asset #${row.assignee_site_asset_id}`}
                    </td>
                    <td className="px-4 py-2 text-sm text-gray-700">{row.assigned_at || '—'}</td>
                    <td className="px-4 py-2 text-sm text-gray-700">{row.unassigned_at || '—'}</td>
                    <td className="px-4 py-2 text-sm">
                      {row.is_active
                        ? <Badge variant="success">Active</Badge>
                        : <Badge variant="secondary">Released</Badge>}
                    </td>
                    <td className="px-4 py-2 text-sm text-right">
                      {row.is_active && (
                        <button onClick={() => unassign(row)} className="text-rose-600 hover:underline">
                          Unassign
                        </button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      {showAssign && (
        <AssignModal
          onClose={() => setShowAssign(false)}
          onSaved={async () => { setShowAssign(false); await load() }}
          onError={onError}
        />
      )}
    </div>
  )
}

function AssignModal({ onClose, onSaved, onError }) {
  const [form, setForm] = useState({
    license_seat_id: '',
    assignee_type: 'user',
    assignee_id: '',
    notes: '',
  })
  const [submitting, setSubmitting] = useState(false)

  const submit = async () => {
    setSubmitting(true)
    try {
      await softwareInventoryService.assign({
        license_seat_id: Number(form.license_seat_id),
        assignee_type: form.assignee_type,
        assignee_id: Number(form.assignee_id),
        notes: form.notes || null,
      })
      await onSaved()
    } catch (err) {
      onError(err?.response?.data?.message || 'Unable to assign seat.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Modal open title="Assign a seat" onClose={onClose} size="md">
      <div className="space-y-4">
        <Input label="License pool ID" required modelValue={form.license_seat_id} onUpdateModelValue={(v) => setForm((p) => ({ ...p, license_seat_id: v }))} />
        <label className="block text-sm font-medium text-gray-700">
          Assignee type
          <select
            value={form.assignee_type}
            onChange={(e) => setForm((p) => ({ ...p, assignee_type: e.target.value }))}
            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
          >
            {ASSIGNEE_TYPES.map((t) => <option key={t} value={t}>{titleize(t)}</option>)}
          </select>
        </label>
        <Input
          label={form.assignee_type === 'user' ? 'User ID' : 'Site asset ID'}
          required
          modelValue={form.assignee_id}
          onUpdateModelValue={(v) => setForm((p) => ({ ...p, assignee_id: v }))}
        />
        <Input label="Notes (optional)" modelValue={form.notes} onUpdateModelValue={(v) => setForm((p) => ({ ...p, notes: v }))} />
      </div>
      <div className="mt-5 flex justify-end gap-2">
        <Button variant="ghost" onClick={onClose} disabled={submitting}>Cancel</Button>
        <Button variant="primary" onClick={submit} disabled={submitting || !form.license_seat_id || !form.assignee_id}>
          {submitting ? 'Assigning...' : 'Assign'}
        </Button>
      </div>
    </Modal>
  )
}

// ---------------------------------------------------------------------------
// Compliance tab
// ---------------------------------------------------------------------------

function ComplianceTab({ onError }) {
  const [data, setData] = useState({ summary: [], over_allocated: [], unlicensed_installs: [] })
  const [loading, setLoading] = useState(true)
  const [reconciling, setReconciling] = useState(false)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const res = await softwareInventoryService.compliance()
      setData(res?.data || { summary: [], over_allocated: [], unlicensed_installs: [] })
    } catch (err) {
      console.error('Failed to load compliance', err)
      onError('Unable to load compliance feed.')
    } finally {
      setLoading(false)
    }
  }, [onError])

  useEffect(() => { load() }, [load])

  const reconcile = async () => {
    if (!window.confirm('Rebuild seats_assigned counters from license_assignments? This is an admin action.')) return
    setReconciling(true)
    try {
      const res = await softwareInventoryService.reconcile()
      const fixes = res?.data?.fixes || []
      window.alert(fixes.length === 0
        ? 'All counters were already in sync.'
        : `Fixed ${fixes.length} counter(s). See audit log for details.`)
      await load()
    } catch (err) {
      onError(err?.response?.data?.message || 'Unable to reconcile counters.')
    } finally {
      setReconciling(false)
    }
  }

  const stats = useMemo(() => {
    const summary = data.summary || []
    const total = summary.length
    const over = (data.over_allocated || []).length
    const expiringSoon = summary.filter((r) => r.expires_within_30d).length
    const unlicensed = (data.unlicensed_installs || []).length
    return { total, over, expiringSoon, unlicensed }
  }, [data])

  if (loading) {
    return <Card><div className="py-10 flex justify-center"><Loading text="Loading compliance..." /></div></Card>
  }

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-1 sm:grid-cols-4 gap-4">
        <Card><div className="text-sm text-gray-500">Pools tracked</div><div className="mt-1 text-2xl font-bold text-gray-900">{stats.total}</div></Card>
        <Card><div className="text-sm text-gray-500">Over-allocated</div><div className={`mt-1 text-2xl font-bold ${stats.over > 0 ? 'text-rose-600' : 'text-emerald-600'}`}>{stats.over}</div></Card>
        <Card><div className="text-sm text-gray-500">Expiring &lt; 30d</div><div className={`mt-1 text-2xl font-bold ${stats.expiringSoon > 0 ? 'text-amber-600' : 'text-gray-900'}`}>{stats.expiringSoon}</div></Card>
        <Card><div className="text-sm text-gray-500">Unlicensed installs</div><div className={`mt-1 text-2xl font-bold ${stats.unlicensed > 0 ? 'text-rose-600' : 'text-emerald-600'}`}>{stats.unlicensed}</div></Card>
      </div>

      <Card>
        <div className="flex items-center justify-between">
          <h3 className="text-md font-semibold text-gray-900">Over-allocated pools</h3>
          <Button variant="outline" onClick={reconcile} disabled={reconciling}>
            {reconciling ? 'Reconciling...' : 'Rebuild counters'}
          </Button>
        </div>
        {(data.over_allocated || []).length === 0 ? (
          <div className="py-6 text-center text-emerald-700">All pools within capacity.</div>
        ) : (
          <ul className="divide-y divide-gray-200">
            {data.over_allocated.map((p) => (
              <li key={p.id} className="py-3 flex items-center justify-between">
                <div>
                  <div className="text-sm font-medium text-gray-900">Pool #{p.id} · title {p.software_asset_id} · customer #{p.customer_id}</div>
                  <div className="text-xs text-gray-500">{p.seats_assigned}/{p.seats_owned} ({titleize(p.license_type)})</div>
                </div>
                <Badge variant="danger">+{p.seats_assigned - p.seats_owned} over</Badge>
              </li>
            ))}
          </ul>
        )}
      </Card>

      <Card>
        <h3 className="text-md font-semibold text-gray-900 mb-3">Unlicensed installs</h3>
        {(data.unlicensed_installs || []).length === 0 ? (
          <div className="py-6 text-center text-emerald-700">No installs lack a license assignment.</div>
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">ID</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Site asset</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Software title</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Detected</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Source</th>
                </tr>
              </thead>
              <tbody className="bg-white divide-y divide-gray-100">
                {data.unlicensed_installs.map((i) => (
                  <tr key={i.id}>
                    <td className="px-4 py-2 text-sm text-gray-900">#{i.id}</td>
                    <td className="px-4 py-2 text-sm text-gray-700">#{i.site_asset_id}</td>
                    <td className="px-4 py-2 text-sm text-gray-700">#{i.software_asset_id}{i.installed_version ? ` v${i.installed_version}` : ''}</td>
                    <td className="px-4 py-2 text-sm text-gray-700">{i.detected_at || '—'}</td>
                    <td className="px-4 py-2 text-sm text-gray-700">{titleize(i.source)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      <Card>
        <h3 className="text-md font-semibold text-gray-900 mb-3">Pool summary</h3>
        <div className="overflow-x-auto">
          <table className="min-w-full divide-y divide-gray-200">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Pool</th>
                <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Customer</th>
                <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Seats</th>
                <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Available</th>
                <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Expires</th>
              </tr>
            </thead>
            <tbody className="bg-white divide-y divide-gray-100">
              {(data.summary || []).map((s) => (
                <tr key={s.license_seat_id} className={s.over_allocated ? 'bg-rose-50' : ''}>
                  <td className="px-4 py-2 text-sm text-gray-900">#{s.license_seat_id}</td>
                  <td className="px-4 py-2 text-sm text-gray-700">#{s.customer_id}</td>
                  <td className="px-4 py-2 text-sm text-gray-700">{titleize(s.license_type)}</td>
                  <td className="px-4 py-2 text-sm">
                    <Badge variant={STATUS_VARIANT[s.status] || 'secondary'}>{titleize(s.status)}</Badge>
                  </td>
                  <td className="px-4 py-2 text-sm text-gray-700">{s.seats_assigned} / {s.seats_owned}</td>
                  <td className="px-4 py-2 text-sm text-gray-700">{s.seats_available}</td>
                  <td className="px-4 py-2 text-sm text-gray-700">
                    {s.expires_at || '—'}
                    {s.expires_within_30d ? <Badge variant="warning" className="ml-2">soon</Badge> : null}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Card>
    </div>
  )
}
