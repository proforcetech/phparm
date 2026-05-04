import { useCallback, useEffect, useMemo, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'

import Alert from '../../components/ui/Alert'
import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Modal from '../../components/ui/Modal'
import Select from '../../components/ui/Select'
import Textarea from '../../components/ui/Textarea'
import purchaseOrdersService from '../../../services/purchase-orders.service'
import vendorsService from '../../../services/vendors.service'

/**
 * Phase 18 / S5 — purchase order list + draft creation modal.
 *
 * Receiving + line editing happens on the detail page (PurchaseOrderDetail).
 * This page is the worklist: filter by status/vendor/WO, jump to detail.
 */

const STATUS_OPTIONS = [
  { value: '', label: 'All statuses' },
  { value: 'draft', label: 'Draft' },
  { value: 'sent', label: 'Sent' },
  { value: 'partial', label: 'Partially Received' },
  { value: 'received', label: 'Received' },
  { value: 'closed', label: 'Closed' },
  { value: 'cancelled', label: 'Cancelled' },
]

const STATUS_VARIANT = {
  draft: 'secondary',
  sent: 'info',
  partial: 'warning',
  received: 'success',
  closed: 'success',
  cancelled: 'danger',
}

const KIND_OPTIONS = [
  { value: 'internal', label: 'Internal (own stock)' },
  { value: 'customer_billable', label: 'Customer-billable' },
]

function formatCurrency(cents, currency = 'USD') {
  if (cents == null) return '—'
  try {
    return new Intl.NumberFormat(undefined, { style: 'currency', currency }).format((cents || 0) / 100)
  } catch {
    return `${(cents / 100).toFixed(2)} ${currency}`
  }
}

function formatDate(s) {
  if (!s) return '—'
  try {
    return new Date(String(s).replace(' ', 'T')).toLocaleDateString()
  } catch {
    return s
  }
}

export default function PurchaseOrders() {
  const navigate = useNavigate()
  const [loading, setLoading] = useState(true)
  const [orders, setOrders] = useState([])
  const [total, setTotal] = useState(0)
  const [filters, setFilters] = useState({ status: '', query: '', vendor_id: '' })
  const [vendors, setVendors] = useState([])
  const [error, setError] = useState('')
  const [creating, setCreating] = useState(false)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const params = {}
      if (filters.status) params.status = filters.status
      if (filters.query) params.query = filters.query
      if (filters.vendor_id) params.vendor_id = filters.vendor_id
      const res = await purchaseOrdersService.list(params)
      setOrders(res?.data || [])
      setTotal(res?.total || 0)
    } catch (err) {
      setError(err?.response?.data?.message || err?.message || 'Failed to load purchase orders')
    } finally {
      setLoading(false)
    }
  }, [filters])

  useEffect(() => {
    load()
  }, [load])

  useEffect(() => {
    vendorsService.list({ status: 'active', limit: 500 })
      .then((res) => setVendors(res?.data || []))
      .catch(() => {})
  }, [])

  const vendorById = useMemo(() => {
    const map = {}
    vendors.forEach((v) => { map[v.id] = v })
    return map
  }, [vendors])

  return (
    <div className="space-y-4 p-4">
      <header className="flex flex-wrap items-start justify-between gap-2">
        <div>
          <h1 className="text-xl font-semibold">Purchase Orders</h1>
          <p className="text-sm text-gray-500">
            {total} total. Track procurement from draft through receipt and close.
          </p>
        </div>
        <div className="flex gap-2">
          <Link to="/cp/procurement/vendors">
            <Button variant="secondary">Manage Vendors</Button>
          </Link>
          <Button onClick={() => setCreating(true)}>+ New PO</Button>
        </div>
      </header>

      {error && <Alert variant="danger" onClose={() => setError('')}>{error}</Alert>}

      <Card padding={false}>
        <div className="grid grid-cols-1 sm:grid-cols-4 gap-3 p-4 border-b border-gray-200">
          <Input
            placeholder="Search PO# or notes"
            value={filters.query}
            onChange={(e) => setFilters((f) => ({ ...f, query: e.target.value }))}
          />
          <Select
            value={filters.status}
            onChange={(e) => setFilters((f) => ({ ...f, status: e?.target?.value ?? e }))}
            placeholder="All statuses"
            options={STATUS_OPTIONS}
          />
          <Select
            value={filters.vendor_id}
            onChange={(e) => setFilters((f) => ({ ...f, vendor_id: e?.target?.value ?? e }))}
            placeholder="All vendors"
            options={[{ value: '', label: 'All vendors' }, ...vendors.map((v) => ({ value: String(v.id), label: v.name }))]}
          />
          <Button variant="secondary" onClick={() => load()}>Refresh</Button>
        </div>

        {loading ? (
          <div className="p-6"><Loading /></div>
        ) : orders.length === 0 ? (
          <div className="p-6 text-center text-sm text-gray-500">No purchase orders match the current filters.</div>
        ) : (
          <table className="min-w-full divide-y divide-gray-200">
            <thead className="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
              <tr>
                <th className="px-4 py-2 text-left">PO #</th>
                <th className="px-4 py-2 text-left">Vendor</th>
                <th className="px-4 py-2 text-left">Status</th>
                <th className="px-4 py-2 text-left">Kind</th>
                <th className="px-4 py-2 text-left">Ordered</th>
                <th className="px-4 py-2 text-left">Expected</th>
                <th className="px-4 py-2 text-right">Total</th>
                <th className="px-4 py-2 text-right">Actions</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {orders.map((po) => (
                <tr key={po.id} className="hover:bg-gray-50">
                  <td className="px-4 py-2 font-mono text-sm text-gray-900">{po.po_number}</td>
                  <td className="px-4 py-2 text-gray-700">{vendorById[po.vendor_id]?.name || `#${po.vendor_id}`}</td>
                  <td className="px-4 py-2">
                    <Badge variant={STATUS_VARIANT[po.status] || 'secondary'}>{po.status}</Badge>
                  </td>
                  <td className="px-4 py-2 text-xs text-gray-600">
                    {po.kind === 'customer_billable' ? 'Customer-billable' : 'Internal'}
                    {po.is_consigned ? <Badge className="ml-1" variant="info">Consigned</Badge> : null}
                  </td>
                  <td className="px-4 py-2 text-sm text-gray-600">{formatDate(po.ordered_at)}</td>
                  <td className="px-4 py-2 text-sm text-gray-600">{formatDate(po.expected_at)}</td>
                  <td className="px-4 py-2 text-right text-sm">{formatCurrency(po.total_cents, po.currency)}</td>
                  <td className="px-4 py-2 text-right">
                    <Button size="sm" variant="secondary" onClick={() => navigate(`/cp/procurement/purchase-orders/${po.id}`)}>
                      Open
                    </Button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </Card>

      {creating && (
        <NewPurchaseOrderModal
          vendors={vendors}
          onCancel={() => setCreating(false)}
          onCreated={(po) => {
            setCreating(false)
            navigate(`/cp/procurement/purchase-orders/${po.id}`)
          }}
        />
      )}
    </div>
  )
}

function NewPurchaseOrderModal({ vendors, onCancel, onCreated }) {
  const [form, setForm] = useState({
    vendor_id: '',
    kind: 'internal',
    customer_id: '',
    workorder_id: '',
    expected_at: '',
    notes: '',
  })
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')

  const set = (k, v) => setForm((f) => ({ ...f, [k]: v }))

  const submit = async (e) => {
    e.preventDefault()
    setErr('')
    if (!form.vendor_id) {
      setErr('Vendor is required')
      return
    }
    if (form.kind === 'customer_billable' && !form.customer_id) {
      setErr('Customer ID is required for customer-billable POs')
      return
    }
    setBusy(true)
    try {
      const payload = {
        vendor_id: Number(form.vendor_id),
        kind: form.kind,
      }
      if (form.customer_id) payload.customer_id = Number(form.customer_id)
      if (form.workorder_id) payload.workorder_id = Number(form.workorder_id)
      if (form.expected_at) payload.expected_at = form.expected_at
      if (form.notes) payload.notes = form.notes
      const res = await purchaseOrdersService.create(payload)
      onCreated(res?.data || res)
    } catch (e2) {
      setErr(e2?.response?.data?.message || e2?.message || 'Create failed')
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal open size="lg" title="New Purchase Order" onClose={onCancel}>
      <form onSubmit={submit} className="space-y-3">
        {err && <Alert variant="danger">{err}</Alert>}
        <Select
          label="Vendor *"
          value={form.vendor_id}
          onChange={(e) => set('vendor_id', e?.target?.value ?? e)}
          placeholder="Select a vendor"
          options={vendors.map((v) => ({ value: String(v.id), label: v.name }))}
        />
        <Select
          label="Kind"
          value={form.kind}
          onChange={(e) => set('kind', e?.target?.value ?? e)}
          options={KIND_OPTIONS}
        />
        {form.kind === 'customer_billable' && (
          <Input
            label="Customer ID *"
            type="number"
            value={form.customer_id}
            onChange={(e) => set('customer_id', e.target.value)}
          />
        )}
        <Input
          label="Workorder ID (optional)"
          type="number"
          value={form.workorder_id}
          onChange={(e) => set('workorder_id', e.target.value)}
        />
        <Input
          label="Expected Date (optional)"
          type="date"
          value={form.expected_at}
          onChange={(e) => set('expected_at', e.target.value)}
        />
        <Textarea
          label="Notes"
          rows={2}
          value={form.notes}
          onChange={(e) => set('notes', e.target.value)}
        />
        <div className="flex justify-end gap-2 pt-2 border-t border-gray-100">
          <Button variant="secondary" type="button" onClick={onCancel} disabled={busy}>Cancel</Button>
          <Button type="submit" disabled={busy}>{busy ? 'Creating…' : 'Create Draft'}</Button>
        </div>
      </form>
    </Modal>
  )
}
