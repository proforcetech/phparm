import { useCallback, useEffect, useState } from 'react'

import Alert from '../../components/ui/Alert'
import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Modal from '../../components/ui/Modal'
import Select from '../../components/ui/Select'
import Textarea from '../../components/ui/Textarea'
import vendorsService from '../../../services/vendors.service'

/**
 * Phase 18 / S5 — vendor master CRUD.
 *
 * Distinct from inventory_lookups (type='vendors') legacy rows used by the
 * parts-supplier dropdown — those stay in place for backward compatibility.
 *
 * Server enforces procurement.view (reads) and procurement.manage (writes).
 */

const STATUS_OPTIONS = [
  { value: 'active', label: 'Active' },
  { value: 'inactive', label: 'Inactive' },
  { value: 'blocked', label: 'Blocked' },
]

const STATUS_VARIANT = {
  active: 'success',
  inactive: 'secondary',
  blocked: 'danger',
}

const EMPTY_VENDOR = {
  name: '',
  code: '',
  status: 'active',
  primary_contact_name: '',
  email: '',
  phone: '',
  website: '',
  street: '',
  city: '',
  state: '',
  postal_code: '',
  country: '',
  payment_terms: '',
  currency: 'USD',
  tax_id: '',
  requires_1099: false,
  is_consignment_partner: false,
  notes: '',
}

export default function Vendors() {
  const [loading, setLoading] = useState(true)
  const [vendors, setVendors] = useState([])
  const [total, setTotal] = useState(0)
  const [filters, setFilters] = useState({ status: '', query: '', consignment_only: false })
  const [error, setError] = useState('')
  const [editing, setEditing] = useState(null)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const params = {}
      if (filters.status) params.status = filters.status
      if (filters.query) params.query = filters.query
      if (filters.consignment_only) params.consignment_only = '1'
      const res = await vendorsService.list(params)
      setVendors(res?.data || [])
      setTotal(res?.total || 0)
    } catch (err) {
      setError(err?.response?.data?.message || err?.message || 'Failed to load vendors')
    } finally {
      setLoading(false)
    }
  }, [filters])

  useEffect(() => {
    load()
  }, [load])

  const handleSave = async (payload) => {
    try {
      if (editing?.id) {
        await vendorsService.update(editing.id, payload)
      } else {
        await vendorsService.create(payload)
      }
      setEditing(null)
      await load()
    } catch (err) {
      setError(err?.response?.data?.message || err?.message || 'Save failed')
    }
  }

  const handleDelete = async (vendor) => {
    if (!window.confirm(`Delete vendor "${vendor.name}"? This will fail if any POs reference it.`)) {
      return
    }
    try {
      await vendorsService.delete(vendor.id)
      await load()
    } catch (err) {
      setError(err?.response?.data?.message || err?.message || 'Delete failed')
    }
  }

  return (
    <div className="space-y-4 p-4">
      <header className="flex flex-wrap items-start justify-between gap-2">
        <div>
          <h1 className="text-xl font-semibold">Vendors</h1>
          <p className="text-sm text-gray-500">
            Procurement vendor master ({total} total). Used as the supplier on purchase orders.
          </p>
        </div>
        <Button onClick={() => setEditing({ ...EMPTY_VENDOR })}>+ New Vendor</Button>
      </header>

      {error && <Alert variant="danger" onClose={() => setError('')}>{error}</Alert>}

      <Card padding={false}>
        <div className="grid grid-cols-1 sm:grid-cols-4 gap-3 p-4 border-b border-gray-200">
          <Input
            placeholder="Search name, code, email"
            value={filters.query}
            onChange={(e) => setFilters((f) => ({ ...f, query: e.target.value }))}
          />
          <Select
            value={filters.status}
            onChange={(e) => setFilters((f) => ({ ...f, status: e?.target?.value ?? e }))}
            placeholder="All statuses"
            options={[{ value: '', label: 'All statuses' }, ...STATUS_OPTIONS]}
          />
          <label className="flex items-center gap-2 text-sm">
            <input
              type="checkbox"
              checked={filters.consignment_only}
              onChange={(e) => setFilters((f) => ({ ...f, consignment_only: e.target.checked }))}
            />
            Consignment partners only
          </label>
          <Button variant="secondary" onClick={() => load()}>Refresh</Button>
        </div>

        {loading ? (
          <div className="p-6"><Loading /></div>
        ) : vendors.length === 0 ? (
          <div className="p-6 text-center text-sm text-gray-500">No vendors match the current filters.</div>
        ) : (
          <table className="min-w-full divide-y divide-gray-200">
            <thead className="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
              <tr>
                <th className="px-4 py-2 text-left">Name</th>
                <th className="px-4 py-2 text-left">Code</th>
                <th className="px-4 py-2 text-left">Status</th>
                <th className="px-4 py-2 text-left">Contact</th>
                <th className="px-4 py-2 text-left">Terms</th>
                <th className="px-4 py-2 text-left">Flags</th>
                <th className="px-4 py-2 text-right">Actions</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {vendors.map((v) => (
                <tr key={v.id} className="hover:bg-gray-50">
                  <td className="px-4 py-2 font-medium text-gray-900">{v.name}</td>
                  <td className="px-4 py-2 text-gray-600">{v.code || '—'}</td>
                  <td className="px-4 py-2">
                    <Badge variant={STATUS_VARIANT[v.status] || 'secondary'}>{v.status}</Badge>
                  </td>
                  <td className="px-4 py-2 text-gray-600">
                    <div>{v.primary_contact_name || '—'}</div>
                    <div className="text-xs text-gray-400">{v.email || ''}</div>
                  </td>
                  <td className="px-4 py-2 text-gray-600">{v.payment_terms || '—'}</td>
                  <td className="px-4 py-2 space-x-1">
                    {v.is_consignment_partner && <Badge variant="info">Consignment</Badge>}
                    {v.requires_1099 && <Badge variant="warning">1099</Badge>}
                  </td>
                  <td className="px-4 py-2 text-right space-x-2">
                    <Button size="sm" variant="secondary" onClick={() => setEditing({ ...EMPTY_VENDOR, ...v })}>Edit</Button>
                    <Button size="sm" variant="danger" onClick={() => handleDelete(v)}>Delete</Button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </Card>

      {editing && (
        <VendorEditModal
          vendor={editing}
          onCancel={() => setEditing(null)}
          onSave={handleSave}
        />
      )}
    </div>
  )
}

function VendorEditModal({ vendor, onCancel, onSave }) {
  const [form, setForm] = useState(vendor)
  const [saving, setSaving] = useState(false)
  const [err, setErr] = useState('')

  const set = (k, v) => setForm((f) => ({ ...f, [k]: v }))

  const submit = async (e) => {
    e.preventDefault()
    setErr('')
    if (!form.name?.trim()) {
      setErr('Name is required')
      return
    }
    setSaving(true)
    try {
      await onSave(form)
    } catch (e2) {
      setErr(e2?.message || 'Save failed')
    } finally {
      setSaving(false)
    }
  }

  return (
    <Modal
      open
      size="xl"
      title={form.id ? `Edit Vendor: ${form.name}` : 'New Vendor'}
      onClose={onCancel}
    >
      <form onSubmit={submit} className="space-y-4">
        {err && <Alert variant="danger">{err}</Alert>}
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <Input label="Name *" value={form.name || ''} onChange={(e) => set('name', e.target.value)} required />
          <Input label="Code" value={form.code || ''} onChange={(e) => set('code', e.target.value)} />
          <Select
            label="Status"
            value={form.status}
            onChange={(e) => set('status', e?.target?.value ?? e)}
            options={STATUS_OPTIONS}
          />
          <Input label="Primary Contact" value={form.primary_contact_name || ''} onChange={(e) => set('primary_contact_name', e.target.value)} />
          <Input label="Email" type="email" value={form.email || ''} onChange={(e) => set('email', e.target.value)} />
          <Input label="Phone" value={form.phone || ''} onChange={(e) => set('phone', e.target.value)} />
          <Input label="Website" value={form.website || ''} onChange={(e) => set('website', e.target.value)} />
          <Input label="Tax ID" value={form.tax_id || ''} onChange={(e) => set('tax_id', e.target.value)} />
          <Input label="Payment Terms" value={form.payment_terms || ''} onChange={(e) => set('payment_terms', e.target.value)} placeholder="Net 30" />
          <Input label="Currency" value={form.currency || 'USD'} onChange={(e) => set('currency', e.target.value)} />
          <Input label="Street" value={form.street || ''} onChange={(e) => set('street', e.target.value)} />
          <Input label="City" value={form.city || ''} onChange={(e) => set('city', e.target.value)} />
          <Input label="State / Region" value={form.state || ''} onChange={(e) => set('state', e.target.value)} />
          <Input label="Postal Code" value={form.postal_code || ''} onChange={(e) => set('postal_code', e.target.value)} />
          <Input label="Country" value={form.country || ''} onChange={(e) => set('country', e.target.value)} />
          <div className="flex items-center gap-4">
            <label className="flex items-center gap-2 text-sm">
              <input
                type="checkbox"
                checked={!!form.requires_1099}
                onChange={(e) => set('requires_1099', e.target.checked)}
              />
              Requires 1099
            </label>
            <label className="flex items-center gap-2 text-sm">
              <input
                type="checkbox"
                checked={!!form.is_consignment_partner}
                onChange={(e) => set('is_consignment_partner', e.target.checked)}
              />
              Consignment partner
            </label>
          </div>
        </div>
        <Textarea label="Notes" rows={3} value={form.notes || ''} onChange={(e) => set('notes', e.target.value)} />
        <div className="flex justify-end gap-2 pt-2 border-t border-gray-100">
          <Button variant="secondary" type="button" onClick={onCancel} disabled={saving}>Cancel</Button>
          <Button type="submit" disabled={saving}>{saving ? 'Saving…' : 'Save Vendor'}</Button>
        </div>
      </form>
    </Modal>
  )
}
