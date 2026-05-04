import { useCallback, useEffect, useMemo, useState } from 'react'
import { Link, useParams } from 'react-router-dom'

import Alert from '../../components/ui/Alert'
import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Modal from '../../components/ui/Modal'
import Textarea from '../../components/ui/Textarea'
import purchaseOrdersService from '../../../services/purchase-orders.service'

/**
 * Phase 18 / S5 — purchase order detail page.
 *
 * Surfaces:
 *   - Header summary + transition buttons (send/close/cancel)
 *   - Line editor (add/update/delete) — only enabled while header is mutable
 *   - Receipt history with per-receipt line breakdown
 *   - "Receive shipment" modal (qty per remaining line + packing slip ref)
 *
 * Server enforces all guards (transition legality, qty<=remaining, etc.) so
 * the UI is permissive and we just render the resulting error if it fires.
 */

const STATUS_VARIANT = {
  draft: 'secondary',
  sent: 'info',
  partial: 'warning',
  received: 'success',
  closed: 'success',
  cancelled: 'danger',
}

const LINE_STATUS_VARIANT = {
  pending: 'secondary',
  partial: 'warning',
  received: 'success',
  cancelled: 'danger',
}

const MUTABLE_STATUSES = new Set(['draft', 'sent', 'partial'])
const RECEIVABLE_STATUSES = new Set(['sent', 'partial'])

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
    return new Date(String(s).replace(' ', 'T')).toLocaleString()
  } catch {
    return s
  }
}

export default function PurchaseOrderDetail() {
  const { id } = useParams()
  const [loading, setLoading] = useState(true)
  const [data, setData] = useState({ po: null, lines: [], receipts: [] })
  const [error, setError] = useState('')
  const [info, setInfo] = useState('')
  const [showReceive, setShowReceive] = useState(false)
  const [showCancel, setShowCancel] = useState(false)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const res = await purchaseOrdersService.get(id)
      setData(res?.data || { po: null, lines: [], receipts: [] })
    } catch (err) {
      setError(err?.response?.data?.message || err?.message || 'Failed to load purchase order')
    } finally {
      setLoading(false)
    }
  }, [id])

  useEffect(() => {
    load()
  }, [load])

  const po = data.po
  const isMutable = po && MUTABLE_STATUSES.has(po.status)
  const canReceive = po && RECEIVABLE_STATUSES.has(po.status)
  const hasLines = (data.lines || []).length > 0

  const totals = useMemo(() => {
    const lines = data.lines || []
    return {
      subtotal: lines.reduce((acc, l) => acc + Math.round(l.quantity_ordered * l.unit_cost_cents), 0),
      tax: lines.reduce((acc, l) => acc + (l.tax_cents || 0), 0),
    }
  }, [data.lines])

  const runAction = async (fn, label) => {
    setError('')
    setInfo('')
    try {
      await fn()
      setInfo(`${label} OK`)
      await load()
    } catch (err) {
      setError(err?.response?.data?.message || err?.message || `${label} failed`)
    }
  }

  if (loading && !po) {
    return <div className="p-6"><Loading /></div>
  }
  if (!po) {
    return (
      <div className="p-6">
        <Alert variant="danger">Purchase order not found.</Alert>
        <Link className="text-blue-600 underline mt-2 inline-block" to="/cp/procurement/purchase-orders">Back to list</Link>
      </div>
    )
  }

  return (
    <div className="space-y-4 p-4">
      <div className="flex items-center justify-between">
        <div>
          <Link className="text-sm text-blue-600 hover:underline" to="/cp/procurement/purchase-orders">← Back to POs</Link>
          <h1 className="text-xl font-semibold mt-1">
            <span className="font-mono">{po.po_number}</span>{' '}
            <Badge variant={STATUS_VARIANT[po.status] || 'secondary'}>{po.status}</Badge>
          </h1>
        </div>
        <div className="flex flex-wrap gap-2">
          {po.status === 'draft' && (
            <Button onClick={() => runAction(() => purchaseOrdersService.send(po.id), 'Send')} disabled={!hasLines}>
              Send to Vendor
            </Button>
          )}
          {canReceive && (
            <Button variant="success" onClick={() => setShowReceive(true)}>
              Receive Shipment
            </Button>
          )}
          {po.status === 'received' && (
            <Button onClick={() => runAction(() => purchaseOrdersService.close(po.id), 'Close')}>
              Close PO
            </Button>
          )}
          {['draft', 'sent'].includes(po.status) && (
            <Button variant="danger" onClick={() => setShowCancel(true)}>Cancel PO</Button>
          )}
        </div>
      </div>

      {error && <Alert variant="danger" onClose={() => setError('')}>{error}</Alert>}
      {info && <Alert variant="success" onClose={() => setInfo('')}>{info}</Alert>}

      <Card padding={false}>
        <div className="p-4 grid grid-cols-2 sm:grid-cols-4 gap-4 text-sm">
          <Summary label="Vendor" value={`#${po.vendor_id}`} />
          <Summary label="Kind" value={po.kind === 'customer_billable' ? 'Customer-billable' : 'Internal'} />
          <Summary label="Currency" value={po.currency} />
          <Summary label="Markup %" value={po.markup_pct != null ? `${po.markup_pct}%` : '—'} />
          <Summary label="Customer" value={po.customer_id ? `#${po.customer_id}` : '—'} />
          <Summary label="Site" value={po.site_id ? `#${po.site_id}` : '—'} />
          <Summary label="Workorder" value={po.workorder_id ? `#${po.workorder_id}` : '—'} />
          <Summary label="Consigned" value={po.is_consigned ? 'Yes' : 'No'} />
          <Summary label="Ordered" value={formatDate(po.ordered_at)} />
          <Summary label="Expected" value={formatDate(po.expected_at)} />
          <Summary label="Received" value={formatDate(po.received_at)} />
          <Summary label="Closed" value={formatDate(po.closed_at)} />
        </div>
        {po.notes && (
          <div className="px-4 pb-4 text-sm text-gray-600">
            <div className="text-xs uppercase tracking-wide text-gray-400">Notes</div>
            <p className="whitespace-pre-line">{po.notes}</p>
          </div>
        )}
      </Card>

      <Card padding={false}>
        <div className="p-4 border-b border-gray-200 flex items-center justify-between">
          <h2 className="font-semibold">Line Items ({(data.lines || []).length})</h2>
          {isMutable && (
            <AddLineButton poId={po.id} onAdded={load} onError={setError} />
          )}
        </div>
        {(data.lines || []).length === 0 ? (
          <div className="p-6 text-center text-sm text-gray-500">No lines yet — add at least one before sending.</div>
        ) : (
          <table className="min-w-full divide-y divide-gray-200">
            <thead className="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
              <tr>
                <th className="px-4 py-2 text-left">#</th>
                <th className="px-4 py-2 text-left">Description</th>
                <th className="px-4 py-2 text-left">SKU</th>
                <th className="px-4 py-2 text-right">Qty Ordered</th>
                <th className="px-4 py-2 text-right">Qty Received</th>
                <th className="px-4 py-2 text-right">Unit Cost</th>
                <th className="px-4 py-2 text-right">Tax</th>
                <th className="px-4 py-2 text-right">Total</th>
                <th className="px-4 py-2 text-left">Status</th>
                <th className="px-4 py-2 text-right">Actions</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {(data.lines || []).map((line) => (
                <LineRow
                  key={line.id}
                  line={line}
                  currency={po.currency}
                  editable={isMutable}
                  onChanged={load}
                  onError={setError}
                />
              ))}
              <tr className="bg-gray-50 font-medium">
                <td colSpan={6} className="px-4 py-2 text-right">Subtotal</td>
                <td className="px-4 py-2 text-right">{formatCurrency(totals.tax, po.currency)}</td>
                <td className="px-4 py-2 text-right">{formatCurrency((totals.subtotal || 0) + (totals.tax || 0) + (po.shipping_cents || 0), po.currency)}</td>
                <td colSpan={2} />
              </tr>
            </tbody>
          </table>
        )}
      </Card>

      <Card padding={false}>
        <div className="p-4 border-b border-gray-200">
          <h2 className="font-semibold">Receipt History ({(data.receipts || []).length})</h2>
        </div>
        {(data.receipts || []).length === 0 ? (
          <div className="p-6 text-center text-sm text-gray-500">No receipts yet.</div>
        ) : (
          <ul className="divide-y divide-gray-100">
            {data.receipts.map((r) => (
              <li key={r.id} className="p-4 text-sm">
                <div className="flex items-center justify-between">
                  <div>
                    <span className="font-mono text-gray-700">Receipt #{r.id}</span>
                    {r.packing_slip_ref && <span className="ml-2 text-gray-500">slip: {r.packing_slip_ref}</span>}
                  </div>
                  <div className="text-xs text-gray-500">{formatDate(r.received_at)}</div>
                </div>
                {r.notes && <p className="mt-1 text-gray-600">{r.notes}</p>}
                {(r.lines || []).length > 0 && (
                  <ul className="mt-2 ml-4 list-disc text-xs text-gray-600">
                    {r.lines.map((rl) => (
                      <li key={rl.id}>
                        Line #{rl.purchase_order_line_id} — received {rl.quantity_received}
                        {rl.notes ? ` (${rl.notes})` : ''}
                      </li>
                    ))}
                  </ul>
                )}
              </li>
            ))}
          </ul>
        )}
      </Card>

      {showReceive && (
        <ReceiveShipmentModal
          po={po}
          lines={data.lines || []}
          onCancel={() => setShowReceive(false)}
          onReceived={() => { setShowReceive(false); load() }}
        />
      )}

      {showCancel && (
        <CancelPoModal
          poId={po.id}
          onCancel={() => setShowCancel(false)}
          onConfirmed={() => { setShowCancel(false); load() }}
        />
      )}
    </div>
  )
}

function Summary({ label, value }) {
  return (
    <div>
      <div className="text-xs uppercase tracking-wide text-gray-400">{label}</div>
      <div className="text-gray-700">{value}</div>
    </div>
  )
}

function LineRow({ line, currency, editable, onChanged, onError }) {
  const [editing, setEditing] = useState(false)
  const [form, setForm] = useState(line)
  const [busy, setBusy] = useState(false)

  useEffect(() => { setForm(line) }, [line])

  const save = async () => {
    setBusy(true)
    try {
      await purchaseOrdersService.updateLine(line.id, {
        description: form.description,
        sku: form.sku,
        quantity_ordered: Number(form.quantity_ordered),
        unit_cost_cents: Number(form.unit_cost_cents),
        tax_cents: Number(form.tax_cents),
      })
      setEditing(false)
      await onChanged()
    } catch (err) {
      onError(err?.response?.data?.message || err?.message || 'Update failed')
    } finally {
      setBusy(false)
    }
  }

  const remove = async () => {
    if (!window.confirm('Delete this line?')) return
    setBusy(true)
    try {
      await purchaseOrdersService.removeLine(line.id)
      await onChanged()
    } catch (err) {
      onError(err?.response?.data?.message || err?.message || 'Delete failed')
    } finally {
      setBusy(false)
    }
  }

  if (editing) {
    return (
      <tr className="bg-yellow-50">
        <td className="px-4 py-2">{line.line_number}</td>
        <td className="px-4 py-2">
          <input className="w-full border rounded px-1 py-0.5 text-sm" value={form.description}
            onChange={(e) => setForm((f) => ({ ...f, description: e.target.value }))} />
        </td>
        <td className="px-4 py-2">
          <input className="w-24 border rounded px-1 py-0.5 text-sm" value={form.sku || ''}
            onChange={(e) => setForm((f) => ({ ...f, sku: e.target.value }))} />
        </td>
        <td className="px-4 py-2 text-right">
          <input className="w-20 text-right border rounded px-1 py-0.5 text-sm" type="number" step="0.001"
            value={form.quantity_ordered}
            onChange={(e) => setForm((f) => ({ ...f, quantity_ordered: e.target.value }))} />
        </td>
        <td className="px-4 py-2 text-right">{line.quantity_received}</td>
        <td className="px-4 py-2 text-right">
          <input className="w-24 text-right border rounded px-1 py-0.5 text-sm" type="number"
            value={form.unit_cost_cents}
            onChange={(e) => setForm((f) => ({ ...f, unit_cost_cents: e.target.value }))} />
        </td>
        <td className="px-4 py-2 text-right">
          <input className="w-20 text-right border rounded px-1 py-0.5 text-sm" type="number"
            value={form.tax_cents}
            onChange={(e) => setForm((f) => ({ ...f, tax_cents: e.target.value }))} />
        </td>
        <td className="px-4 py-2 text-right">{formatCurrency(line.line_total_cents, currency)}</td>
        <td className="px-4 py-2"><Badge variant={LINE_STATUS_VARIANT[line.status] || 'secondary'}>{line.status}</Badge></td>
        <td className="px-4 py-2 text-right space-x-1">
          <Button size="sm" onClick={save} disabled={busy}>Save</Button>
          <Button size="sm" variant="secondary" onClick={() => setEditing(false)} disabled={busy}>Cancel</Button>
        </td>
      </tr>
    )
  }

  return (
    <tr className="hover:bg-gray-50">
      <td className="px-4 py-2 text-sm">{line.line_number}</td>
      <td className="px-4 py-2 text-sm">{line.description}</td>
      <td className="px-4 py-2 text-sm">{line.sku || '—'}</td>
      <td className="px-4 py-2 text-right text-sm">{line.quantity_ordered}</td>
      <td className="px-4 py-2 text-right text-sm">{line.quantity_received}</td>
      <td className="px-4 py-2 text-right text-sm">{formatCurrency(line.unit_cost_cents, currency)}</td>
      <td className="px-4 py-2 text-right text-sm">{formatCurrency(line.tax_cents, currency)}</td>
      <td className="px-4 py-2 text-right text-sm">{formatCurrency(line.line_total_cents, currency)}</td>
      <td className="px-4 py-2"><Badge variant={LINE_STATUS_VARIANT[line.status] || 'secondary'}>{line.status}</Badge></td>
      <td className="px-4 py-2 text-right space-x-1">
        {editable && (
          <>
            <Button size="sm" variant="secondary" onClick={() => setEditing(true)}>Edit</Button>
            <Button size="sm" variant="danger" onClick={remove} disabled={busy || line.quantity_received > 0}>Delete</Button>
          </>
        )}
      </td>
    </tr>
  )
}

function AddLineButton({ poId, onAdded, onError }) {
  const [open, setOpen] = useState(false)
  const [form, setForm] = useState({
    description: '',
    sku: '',
    quantity_ordered: '1',
    unit_cost_cents: '0',
    tax_cents: '0',
    notes: '',
  })
  const [busy, setBusy] = useState(false)

  const submit = async (e) => {
    e.preventDefault()
    setBusy(true)
    try {
      await purchaseOrdersService.addLine(poId, {
        description: form.description,
        sku: form.sku || null,
        quantity_ordered: Number(form.quantity_ordered),
        unit_cost_cents: Number(form.unit_cost_cents),
        tax_cents: Number(form.tax_cents),
        notes: form.notes || null,
      })
      setOpen(false)
      setForm({ description: '', sku: '', quantity_ordered: '1', unit_cost_cents: '0', tax_cents: '0', notes: '' })
      await onAdded()
    } catch (err) {
      onError(err?.response?.data?.message || err?.message || 'Add line failed')
    } finally {
      setBusy(false)
    }
  }

  return (
    <>
      <Button size="sm" onClick={() => setOpen(true)}>+ Add Line</Button>
      {open && (
        <Modal open size="md" title="Add Line" onClose={() => setOpen(false)}>
          <form onSubmit={submit} className="space-y-3">
            <Input label="Description *" value={form.description}
              onChange={(e) => setForm((f) => ({ ...f, description: e.target.value }))} required />
            <Input label="SKU" value={form.sku}
              onChange={(e) => setForm((f) => ({ ...f, sku: e.target.value }))} />
            <div className="grid grid-cols-3 gap-2">
              <Input label="Qty *" type="number" step="0.001" value={form.quantity_ordered}
                onChange={(e) => setForm((f) => ({ ...f, quantity_ordered: e.target.value }))} />
              <Input label="Unit Cost (cents)" type="number" value={form.unit_cost_cents}
                onChange={(e) => setForm((f) => ({ ...f, unit_cost_cents: e.target.value }))} />
              <Input label="Tax (cents)" type="number" value={form.tax_cents}
                onChange={(e) => setForm((f) => ({ ...f, tax_cents: e.target.value }))} />
            </div>
            <Textarea label="Notes" rows={2} value={form.notes}
              onChange={(e) => setForm((f) => ({ ...f, notes: e.target.value }))} />
            <div className="flex justify-end gap-2 pt-2 border-t border-gray-100">
              <Button variant="secondary" type="button" onClick={() => setOpen(false)} disabled={busy}>Cancel</Button>
              <Button type="submit" disabled={busy}>{busy ? 'Adding…' : 'Add Line'}</Button>
            </div>
          </form>
        </Modal>
      )}
    </>
  )
}

function ReceiveShipmentModal({ po, lines, onCancel, onReceived }) {
  const remainingLines = useMemo(
    () => lines.filter((l) => l.quantity_ordered - l.quantity_received > 0.0001 && l.status !== 'cancelled'),
    [lines],
  )
  const [items, setItems] = useState(() => remainingLines.map((l) => ({ id: l.id, qty: '0', notes: '' })))
  const [meta, setMeta] = useState({ packing_slip_ref: '', notes: '' })
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')

  const setItemQty = (id, qty) => setItems((arr) => arr.map((i) => i.id === id ? { ...i, qty } : i))
  const setItemNotes = (id, notes) => setItems((arr) => arr.map((i) => i.id === id ? { ...i, notes } : i))

  const submit = async (e) => {
    e.preventDefault()
    setErr('')
    const payload = items
      .filter((i) => Number(i.qty) > 0)
      .map((i) => ({
        purchase_order_line_id: i.id,
        quantity_received: Number(i.qty),
        notes: i.notes || null,
      }))
    if (payload.length === 0) {
      setErr('Enter a quantity > 0 for at least one line.')
      return
    }
    setBusy(true)
    try {
      await purchaseOrdersService.receive(po.id, payload, {
        packing_slip_ref: meta.packing_slip_ref || null,
        notes: meta.notes || null,
      })
      onReceived()
    } catch (e2) {
      setErr(e2?.response?.data?.message || e2?.message || 'Receive failed')
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal open size="xl" title={`Receive Shipment — ${po.po_number}`} onClose={onCancel}>
      <form onSubmit={submit} className="space-y-3">
        {err && <Alert variant="danger">{err}</Alert>}
        {po.is_consigned && (
          <Alert variant="info">
            Consigned PO — receiving will NOT increment inventory stock counts (vendor still owns).
          </Alert>
        )}
        <div className="grid grid-cols-2 gap-3">
          <Input label="Packing Slip Ref" value={meta.packing_slip_ref}
            onChange={(e) => setMeta((m) => ({ ...m, packing_slip_ref: e.target.value }))} />
          <Input label="Notes" value={meta.notes}
            onChange={(e) => setMeta((m) => ({ ...m, notes: e.target.value }))} />
        </div>
        {remainingLines.length === 0 ? (
          <p className="text-sm text-gray-500">All lines already fully received.</p>
        ) : (
          <table className="min-w-full text-sm">
            <thead className="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
              <tr>
                <th className="px-2 py-1 text-left">Line</th>
                <th className="px-2 py-1 text-right">Outstanding</th>
                <th className="px-2 py-1 text-right">Receive Now</th>
                <th className="px-2 py-1 text-left">Line Notes</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {remainingLines.map((l) => {
                const item = items.find((i) => i.id === l.id) || { qty: '0', notes: '' }
                const remaining = l.quantity_ordered - l.quantity_received
                return (
                  <tr key={l.id}>
                    <td className="px-2 py-1">#{l.line_number} — {l.description}</td>
                    <td className="px-2 py-1 text-right">{remaining}</td>
                    <td className="px-2 py-1 text-right">
                      <input
                        className="w-24 text-right border rounded px-1 py-0.5"
                        type="number"
                        step="0.001"
                        min="0"
                        max={remaining}
                        value={item.qty}
                        onChange={(e) => setItemQty(l.id, e.target.value)}
                      />
                    </td>
                    <td className="px-2 py-1">
                      <input className="w-full border rounded px-1 py-0.5" value={item.notes}
                        onChange={(e) => setItemNotes(l.id, e.target.value)} />
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        )}
        <div className="flex justify-end gap-2 pt-2 border-t border-gray-100">
          <Button variant="secondary" type="button" onClick={onCancel} disabled={busy}>Cancel</Button>
          <Button type="submit" disabled={busy || remainingLines.length === 0}>
            {busy ? 'Recording…' : 'Record Receipt'}
          </Button>
        </div>
      </form>
    </Modal>
  )
}

function CancelPoModal({ poId, onCancel, onConfirmed }) {
  const [reason, setReason] = useState('')
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')

  const submit = async (e) => {
    e.preventDefault()
    setBusy(true)
    setErr('')
    try {
      await purchaseOrdersService.cancel(poId, reason)
      onConfirmed()
    } catch (e2) {
      setErr(e2?.response?.data?.message || e2?.message || 'Cancel failed')
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal open size="md" title="Cancel Purchase Order" onClose={onCancel}>
      <form onSubmit={submit} className="space-y-3">
        {err && <Alert variant="danger">{err}</Alert>}
        <p className="text-sm text-gray-600">
          Cancellation is only allowed when no quantity has been received.
        </p>
        <Textarea label="Reason (optional)" rows={3} value={reason}
          onChange={(e) => setReason(e.target.value)} />
        <div className="flex justify-end gap-2 pt-2 border-t border-gray-100">
          <Button variant="secondary" type="button" onClick={onCancel} disabled={busy}>Back</Button>
          <Button variant="danger" type="submit" disabled={busy}>{busy ? 'Cancelling…' : 'Confirm Cancel'}</Button>
        </div>
      </form>
    </Modal>
  )
}
