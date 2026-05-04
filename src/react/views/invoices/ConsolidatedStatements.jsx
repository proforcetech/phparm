import { useCallback, useEffect, useMemo, useState } from 'react'

import Alert from '../../components/ui/Alert'
import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Modal from '../../components/ui/Modal'
import Select from '../../components/ui/Select'
import Table from '../../components/ui/Table'
import consolidatedBillingService, {
  STATUS_OPTIONS,
} from '../../../services/consolidated-billing.service'

/**
 * Phase 17 / M11 of docs/woms-expansion-plan.md.
 *
 * Manage consolidated monthly statements: list view with filters, detail
 * drawer with the child invoice rollup, and per-customer + monthly-batch
 * generation. Designed for back-office use — chain customers see the
 * statements in their portal, technicians do not interact with this surface.
 *
 * Read perm:  consolidated_billing.view  (server-enforced)
 * Write perm: consolidated_billing.manage
 */

const STATUS_VARIANT = {
  draft: 'secondary',
  sent: 'info',
  partial: 'warning',
  paid: 'success',
  cancelled: 'danger',
}

function titleize(s) {
  if (!s) return ''
  return String(s).replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())
}

function formatMoney(n) {
  if (n === null || n === undefined) return '—'
  const num = Number(n)
  if (!Number.isFinite(num)) return '—'
  return num.toLocaleString(undefined, { style: 'currency', currency: 'USD' })
}

function formatDate(d) {
  if (!d) return '—'
  try {
    const dt = new Date(d)
    if (Number.isNaN(dt.getTime())) return d
    return dt.toLocaleDateString()
  } catch {
    return d
  }
}

function priorMonthRange() {
  const now = new Date()
  const firstThisMonth = new Date(now.getFullYear(), now.getMonth(), 1)
  const lastPrev = new Date(firstThisMonth.getTime() - 24 * 60 * 60 * 1000)
  const start = new Date(lastPrev.getFullYear(), lastPrev.getMonth(), 1)
  const fmt = (d) => d.toISOString().slice(0, 10)
  return { period_start: fmt(start), period_end: fmt(lastPrev) }
}

export default function ConsolidatedStatements() {
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [info, setInfo] = useState('')
  const [filters, setFilters] = useState({
    customer_id: '',
    status: '',
    period_start: '',
    period_end: '',
  })
  const [page, setPage] = useState(1)
  const [perPage] = useState(25)
  const [list, setList] = useState({ items: [], pagination: { page: 1, per_page: 25, total: 0 } })
  const [activeId, setActiveId] = useState(null)
  const [showGenerate, setShowGenerate] = useState(false)
  const [showBatch, setShowBatch] = useState(false)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const params = { page, per_page: perPage }
      if (filters.customer_id) params.customer_id = filters.customer_id
      if (filters.status) params.status = filters.status
      if (filters.period_start) params.period_start = filters.period_start
      if (filters.period_end) params.period_end = filters.period_end
      const res = await consolidatedBillingService.list(params)
      setList(res?.data ?? { items: [], pagination: { page: 1, per_page: perPage, total: 0 } })
    } catch (e) {
      setError(e?.response?.data?.message || e?.message || 'Failed to load statements')
    } finally {
      setLoading(false)
    }
  }, [filters, page, perPage])

  useEffect(() => {
    load()
  }, [load])

  const columns = useMemo(() => [
    { key: 'number', label: 'Number' },
    { key: 'customer_id', label: 'Customer' },
    { key: 'period', label: 'Period' },
    { key: 'status', label: 'Status' },
    { key: 'invoice_count', label: 'Invoices' },
    { key: 'total', label: 'Total' },
    { key: 'balance_due', label: 'Balance' },
  ], [])

  const cellRenderers = {
    number: (row) => (
      <button
        type="button"
        className="font-mono text-primary-600 hover:underline"
        onClick={() => setActiveId(row.id)}
      >
        {row.number}
      </button>
    ),
    period: (row) => `${row.period_start} → ${row.period_end}`,
    status: (row) => (
      <Badge variant={STATUS_VARIANT[row.status] || 'secondary'}>{titleize(row.status)}</Badge>
    ),
    total: (row) => formatMoney(row.total),
    balance_due: (row) => formatMoney(row.balance_due),
  }

  return (
    <div className="space-y-4 p-4">
      <header className="flex items-center justify-between flex-wrap gap-2">
        <div>
          <h1 className="text-xl font-semibold">Consolidated Statements</h1>
          <p className="text-sm text-gray-500">
            Monthly billing rollups for chain customers. Each statement bundles every invoice
            issued in the period into one envelope; child invoices keep their own status and audit history.
          </p>
        </div>
        <div className="flex gap-2">
          <Button variant="secondary" onClick={() => setShowBatch(true)}>Run monthly batch</Button>
          <Button onClick={() => setShowGenerate(true)}>Generate for customer</Button>
        </div>
      </header>

      {error && <Alert variant="danger" onClose={() => setError('')}>{error}</Alert>}
      {info && <Alert variant="success" onClose={() => setInfo('')}>{info}</Alert>}

      <Card>
        <div className="p-3 grid grid-cols-1 md:grid-cols-5 gap-2">
          <Input
            label="Customer ID"
            type="number"
            value={filters.customer_id}
            onChange={(e) => setFilters((p) => ({ ...p, customer_id: e?.target?.value ?? e }))}
          />
          <Select
            label="Status"
            value={filters.status}
            onChange={(e) => setFilters((p) => ({ ...p, status: e?.target?.value ?? e }))}
            options={[{ value: '', label: 'Any' }, ...STATUS_OPTIONS]}
          />
          <Input
            label="Period from"
            type="date"
            value={filters.period_start}
            onChange={(e) => setFilters((p) => ({ ...p, period_start: e?.target?.value ?? e }))}
          />
          <Input
            label="Period to"
            type="date"
            value={filters.period_end}
            onChange={(e) => setFilters((p) => ({ ...p, period_end: e?.target?.value ?? e }))}
          />
          <div className="flex items-end">
            <Button onClick={() => { setPage(1); load() }} className="w-full">Apply</Button>
          </div>
        </div>
      </Card>

      {loading ? (
        <Loading />
      ) : (
        <Card>
          <Table
            columns={columns}
            data={list.items || []}
            cellRenderers={cellRenderers}
            pagination
            perPage={perPage}
            currentPage={page}
            total={list?.pagination?.total ?? 0}
            onPageChange={(p) => setPage(p)}
          />
        </Card>
      )}

      {activeId && (
        <DetailModal
          statementId={activeId}
          onClose={() => setActiveId(null)}
          onChanged={() => { load(); }}
          onError={setError}
          onInfo={setInfo}
        />
      )}

      {showGenerate && (
        <GenerateModal
          onClose={() => setShowGenerate(false)}
          onDone={() => { setShowGenerate(false); load(); setInfo('Statement generated.') }}
          onError={setError}
        />
      )}

      {showBatch && (
        <BatchModal
          onClose={() => setShowBatch(false)}
          onDone={(result) => {
            setShowBatch(false)
            load()
            setInfo(`Batch complete — processed ${result?.processed ?? 0}, failed ${result?.failures?.length ?? 0}.`)
          }}
          onError={setError}
        />
      )}
    </div>
  )
}

function DetailModal({ statementId, onClose, onChanged, onError, onInfo }) {
  const [loading, setLoading] = useState(true)
  const [data, setData] = useState({ statement: null, invoices: [] })
  const [busy, setBusy] = useState(false)

  const reload = useCallback(async () => {
    setLoading(true)
    try {
      const res = await consolidatedBillingService.get(statementId)
      setData(res?.data ?? { statement: null, invoices: [] })
    } catch (e) {
      onError(e?.response?.data?.message || e?.message || 'Failed to load statement')
    } finally {
      setLoading(false)
    }
  }, [statementId, onError])

  useEffect(() => {
    reload()
  }, [reload])

  const statement = data.statement

  const handleMarkSent = async () => {
    setBusy(true)
    try {
      await consolidatedBillingService.markSent(statementId)
      onInfo('Statement marked as sent.')
      onChanged()
      await reload()
    } catch (e) {
      onError(e?.response?.data?.message || e?.message || 'Failed to mark sent')
    } finally {
      setBusy(false)
    }
  }

  const handleCancel = async () => {
    if (!confirm('Cancel this statement? Child invoices will be released.')) return
    setBusy(true)
    try {
      await consolidatedBillingService.cancel(statementId)
      onInfo('Statement cancelled.')
      onChanged()
      await reload()
    } catch (e) {
      onError(e?.response?.data?.message || e?.message || 'Failed to cancel')
    } finally {
      setBusy(false)
    }
  }

  const handleDetach = async (invoiceId) => {
    if (!confirm(`Detach invoice ${invoiceId} from this statement?`)) return
    setBusy(true)
    try {
      await consolidatedBillingService.detachInvoice(statementId, invoiceId)
      onInfo('Invoice detached.')
      onChanged()
      await reload()
    } catch (e) {
      onError(e?.response?.data?.message || e?.message || 'Failed to detach invoice')
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal
      isOpen
      onClose={onClose}
      title={statement ? `Statement ${statement.number}` : 'Statement'}
      size="lg"
      footer={
        <div className="flex justify-between w-full">
          <div className="flex gap-2">
            {statement?.status === 'draft' && (
              <Button onClick={handleMarkSent} disabled={busy}>Mark Sent</Button>
            )}
            {statement && statement.status !== 'cancelled' && statement.status !== 'paid' && (
              <Button variant="danger" onClick={handleCancel} disabled={busy}>Cancel Statement</Button>
            )}
          </div>
          <Button variant="secondary" onClick={onClose}>Close</Button>
        </div>
      }
    >
      {loading || !statement ? (
        <Loading />
      ) : (
        <div className="space-y-4">
          <div className="grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">
            <KV label="Status">
              <Badge variant={STATUS_VARIANT[statement.status] || 'secondary'}>
                {titleize(statement.status)}
              </Badge>
            </KV>
            <KV label="Customer ID">{statement.customer_id}</KV>
            <KV label="Period">{statement.period_start} → {statement.period_end}</KV>
            <KV label="Invoices">{statement.invoice_count}</KV>
            <KV label="Subtotal">{formatMoney(statement.subtotal)}</KV>
            <KV label="Tax">{formatMoney(statement.tax)}</KV>
            <KV label="Total">{formatMoney(statement.total)}</KV>
            <KV label="Balance Due">{formatMoney(statement.balance_due)}</KV>
            <KV label="Sent">{formatDate(statement.sent_at)}</KV>
            <KV label="Paid">{formatDate(statement.paid_at)}</KV>
            <KV label="Cancelled">{formatDate(statement.cancelled_at)}</KV>
            <KV label="Created">{formatDate(statement.created_at)}</KV>
          </div>

          {statement.notes && (
            <div className="text-sm">
              <div className="text-xs uppercase tracking-wide text-gray-500 mb-1">Notes</div>
              <div className="bg-gray-50 rounded p-2">{statement.notes}</div>
            </div>
          )}

          <div>
            <div className="text-xs uppercase tracking-wide text-gray-500 mb-2">Invoices</div>
            {(data.invoices || []).length === 0 ? (
              <div className="text-sm text-gray-500 italic">No invoices attached.</div>
            ) : (
              <div className="overflow-x-auto">
                <table className="min-w-full text-sm border">
                  <thead className="bg-gray-50">
                    <tr>
                      <th className="text-left p-2">Number</th>
                      <th className="text-left p-2">Status</th>
                      <th className="text-left p-2">Issued</th>
                      <th className="text-left p-2">Due</th>
                      <th className="text-right p-2">Total</th>
                      <th className="text-right p-2">Paid</th>
                      <th className="text-right p-2">Balance</th>
                      <th className="p-2" />
                    </tr>
                  </thead>
                  <tbody>
                    {data.invoices.map((inv) => (
                      <tr key={inv.id} className="border-t">
                        <td className="p-2 font-mono">#{inv.number}</td>
                        <td className="p-2">{titleize(inv.status)}</td>
                        <td className="p-2">{formatDate(inv.issue_date)}</td>
                        <td className="p-2">{formatDate(inv.due_date)}</td>
                        <td className="p-2 text-right">{formatMoney(inv.total)}</td>
                        <td className="p-2 text-right">{formatMoney(inv.amount_paid)}</td>
                        <td className="p-2 text-right">{formatMoney(inv.balance_due)}</td>
                        <td className="p-2 text-right">
                          {statement.status !== 'cancelled' && (
                            <button
                              type="button"
                              className="text-xs text-red-600 hover:underline"
                              onClick={() => handleDetach(inv.id)}
                              disabled={busy}
                            >
                              Detach
                            </button>
                          )}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        </div>
      )}
    </Modal>
  )
}

function KV({ label, children }) {
  return (
    <div>
      <div className="text-xs uppercase tracking-wide text-gray-500">{label}</div>
      <div className="font-medium">{children}</div>
    </div>
  )
}

function GenerateModal({ onClose, onDone, onError }) {
  const defaults = priorMonthRange()
  const [form, setForm] = useState({
    customer_id: '',
    period_start: defaults.period_start,
    period_end: defaults.period_end,
    notes: '',
  })
  const [busy, setBusy] = useState(false)

  const submit = async () => {
    if (!form.customer_id || !form.period_start || !form.period_end) {
      onError('Customer, period start, and period end are required.')
      return
    }
    setBusy(true)
    try {
      await consolidatedBillingService.generate({
        customer_id: Number(form.customer_id),
        period_start: form.period_start,
        period_end: form.period_end,
        notes: form.notes || null,
      })
      onDone()
    } catch (e) {
      onError(e?.response?.data?.message || e?.message || 'Failed to generate statement')
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal
      isOpen
      onClose={onClose}
      title="Generate consolidated statement"
      footer={
        <div className="flex justify-end gap-2 w-full">
          <Button variant="secondary" onClick={onClose} disabled={busy}>Cancel</Button>
          <Button onClick={submit} disabled={busy}>Generate</Button>
        </div>
      }
    >
      <div className="space-y-3">
        <Input
          label="Customer ID"
          type="number"
          value={form.customer_id}
          onChange={(e) => setForm((p) => ({ ...p, customer_id: e?.target?.value ?? e }))}
        />
        <Input
          label="Period start"
          type="date"
          value={form.period_start}
          onChange={(e) => setForm((p) => ({ ...p, period_start: e?.target?.value ?? e }))}
        />
        <Input
          label="Period end"
          type="date"
          value={form.period_end}
          onChange={(e) => setForm((p) => ({ ...p, period_end: e?.target?.value ?? e }))}
        />
        <Input
          label="Notes (optional)"
          value={form.notes}
          onChange={(e) => setForm((p) => ({ ...p, notes: e?.target?.value ?? e }))}
        />
        <p className="text-xs text-gray-500">
          All eligible non-cancelled invoices the customer has in the period will be attached.
          A duplicate run for the same period will refresh totals rather than creating a new statement.
        </p>
      </div>
    </Modal>
  )
}

function BatchModal({ onClose, onDone, onError }) {
  const defaults = priorMonthRange()
  const [form, setForm] = useState(defaults)
  const [busy, setBusy] = useState(false)

  const submit = async () => {
    if (!form.period_start || !form.period_end) {
      onError('Period start and end are required.')
      return
    }
    setBusy(true)
    try {
      const res = await consolidatedBillingService.runBatch({
        period_start: form.period_start,
        period_end: form.period_end,
      })
      onDone(res?.data)
    } catch (e) {
      onError(e?.response?.data?.message || e?.message || 'Failed to run monthly batch')
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal
      isOpen
      onClose={onClose}
      title="Run monthly batch"
      footer={
        <div className="flex justify-end gap-2 w-full">
          <Button variant="secondary" onClick={onClose} disabled={busy}>Cancel</Button>
          <Button onClick={submit} disabled={busy}>Run batch</Button>
        </div>
      }
    >
      <div className="space-y-3">
        <p className="text-sm text-gray-700">
          Iterates over every customer with monthly_consolidated_billing = 1 and generates a
          statement for the given period. Per-customer failures are isolated.
        </p>
        <Input
          label="Period start"
          type="date"
          value={form.period_start}
          onChange={(e) => setForm((p) => ({ ...p, period_start: e?.target?.value ?? e }))}
        />
        <Input
          label="Period end"
          type="date"
          value={form.period_end}
          onChange={(e) => setForm((p) => ({ ...p, period_end: e?.target?.value ?? e }))}
        />
      </div>
    </Modal>
  )
}
