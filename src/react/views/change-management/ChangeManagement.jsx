import { useCallback, useEffect, useMemo, useState } from 'react'

import Alert from '../../components/ui/Alert'
import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Modal from '../../components/ui/Modal'
import Textarea from '../../components/ui/Textarea'
import changeManagementService from '../../../services/change-management.service'

/**
 * Phase 14 / S3 admin UI for the change-management surface (RFC + CAB).
 *
 * Tabs:
 *   1. RFCs       — list + filters + create + drill into detail
 *   2. Calendar   — change-window view (RFCs with planned start/end)
 *
 * Detail view: header + state-machine action bar + CAB voting panel.
 *
 * Read perm: change_management.view  (server-enforced)
 * Write perm: change_management.manage
 */

const TABS = [
  { id: 'rfcs', label: 'RFCs' },
  { id: 'calendar', label: 'Change calendar' },
]

const STATUSES = [
  'draft', 'submitted', 'cab_review',
  'approved', 'rejected',
  'scheduled', 'in_progress',
  'completed', 'rolled_back', 'cancelled',
]

const TYPES = ['standard', 'normal', 'emergency']
const RISKS = ['low', 'medium', 'high', 'critical']
const VOTES = ['approve', 'reject', 'abstain']

const STATUS_VARIANT = {
  draft: 'secondary',
  submitted: 'info',
  cab_review: 'warning',
  approved: 'success',
  rejected: 'danger',
  scheduled: 'info',
  in_progress: 'warning',
  completed: 'success',
  rolled_back: 'danger',
  cancelled: 'secondary',
}

const RISK_VARIANT = {
  low: 'success',
  medium: 'info',
  high: 'warning',
  critical: 'danger',
}

const TYPE_VARIANT = {
  standard: 'secondary',
  normal: 'info',
  emergency: 'danger',
}

const VOTE_VARIANT = {
  approve: 'success',
  reject: 'danger',
  abstain: 'secondary',
}

function titleize(s) {
  if (!s) return ''
  return String(s).replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())
}

function formatDate(s) {
  if (!s) return '—'
  try {
    return new Date(String(s).replace(' ', 'T')).toLocaleString()
  } catch {
    return s
  }
}

export default function ChangeManagement() {
  const [tab, setTab] = useState('rfcs')
  const [error, setError] = useState('')
  const [selectedId, setSelectedId] = useState(null)

  // Detail "view" overrides tab nav.
  if (selectedId !== null) {
    return (
      <ChangeRequestDetail
        id={selectedId}
        onBack={() => setSelectedId(null)}
        onError={setError}
        error={error}
      />
    )
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Change management</h1>
          <p className="mt-1 text-sm text-gray-500">
            Request for Change (RFC) workflow with CAB voting and a change-window calendar.
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

      {tab === 'rfcs' && (
        <RfcListTab
          onError={setError}
          onSelect={setSelectedId}
        />
      )}
      {tab === 'calendar' && <CalendarTab onError={setError} onSelect={setSelectedId} />}
    </div>
  )
}

// ---------------------------------------------------------------------------
// RFC list tab
// ---------------------------------------------------------------------------

function RfcListTab({ onError, onSelect }) {
  const [rows, setRows] = useState([])
  const [loading, setLoading] = useState(true)
  const [statusFilter, setStatusFilter] = useState('')
  const [typeFilter, setTypeFilter] = useState('')
  const [riskFilter, setRiskFilter] = useState('')
  const [showForm, setShowForm] = useState(false)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const params = { per_page: 100 }
      if (statusFilter) params.status = statusFilter
      if (typeFilter) params.change_type = typeFilter
      if (riskFilter) params.risk_level = riskFilter
      const res = await changeManagementService.list(params)
      setRows(res?.data?.change_requests || [])
    } catch (err) {
      console.error('Failed to load RFCs', err)
      onError('Unable to load change requests.')
    } finally {
      setLoading(false)
    }
  }, [statusFilter, typeFilter, riskFilter, onError])

  useEffect(() => { load() }, [load])

  return (
    <div className="space-y-4">
      <Card>
        <div className="flex flex-wrap items-end gap-3">
          <label className="block text-sm font-medium text-gray-700">
            Status
            <select
              value={statusFilter}
              onChange={(e) => setStatusFilter(e.target.value)}
              className="mt-1 block rounded-md border-gray-300 shadow-sm sm:text-sm px-3 py-2"
            >
              <option value="">All</option>
              {STATUSES.map((s) => <option key={s} value={s}>{titleize(s)}</option>)}
            </select>
          </label>
          <label className="block text-sm font-medium text-gray-700">
            Type
            <select
              value={typeFilter}
              onChange={(e) => setTypeFilter(e.target.value)}
              className="mt-1 block rounded-md border-gray-300 shadow-sm sm:text-sm px-3 py-2"
            >
              <option value="">All</option>
              {TYPES.map((t) => <option key={t} value={t}>{titleize(t)}</option>)}
            </select>
          </label>
          <label className="block text-sm font-medium text-gray-700">
            Risk
            <select
              value={riskFilter}
              onChange={(e) => setRiskFilter(e.target.value)}
              className="mt-1 block rounded-md border-gray-300 shadow-sm sm:text-sm px-3 py-2"
            >
              <option value="">All</option>
              {RISKS.map((r) => <option key={r} value={r}>{titleize(r)}</option>)}
            </select>
          </label>
          <div className="flex-1" />
          <Button variant="primary" onClick={() => setShowForm(true)}>New RFC</Button>
        </div>
      </Card>

      <Card>
        {loading ? (
          <div className="py-10 flex justify-center"><Loading text="Loading RFCs..." /></div>
        ) : rows.length === 0 ? (
          <div className="py-10 text-center text-gray-500">No change requests found.</div>
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">RFC</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Title</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Risk</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Window</th>
                  <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                </tr>
              </thead>
              <tbody className="bg-white divide-y divide-gray-100">
                {rows.map((row) => (
                  <tr key={row.id} className="hover:bg-gray-50">
                    <td className="px-4 py-2 text-sm text-gray-900">#{row.id}</td>
                    <td className="px-4 py-2 text-sm text-gray-900 font-medium">{row.title}</td>
                    <td className="px-4 py-2 text-sm">
                      <Badge variant={TYPE_VARIANT[row.change_type] || 'secondary'}>{titleize(row.change_type)}</Badge>
                    </td>
                    <td className="px-4 py-2 text-sm">
                      <Badge variant={RISK_VARIANT[row.risk_level] || 'secondary'}>{titleize(row.risk_level)}</Badge>
                    </td>
                    <td className="px-4 py-2 text-sm">
                      <Badge variant={STATUS_VARIANT[row.status] || 'secondary'}>{titleize(row.status)}</Badge>
                    </td>
                    <td className="px-4 py-2 text-sm text-gray-700">
                      {row.planned_start_at
                        ? `${formatDate(row.planned_start_at)} → ${formatDate(row.planned_end_at)}`
                        : <span className="text-gray-400">unscheduled</span>}
                    </td>
                    <td className="px-4 py-2 text-sm text-right">
                      <button onClick={() => onSelect(row.id)} className="text-blue-600 hover:underline">
                        Open
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      {showForm && (
        <RfcFormModal
          onClose={() => setShowForm(false)}
          onSaved={async (id) => { setShowForm(false); await load(); if (id) onSelect(id) }}
          onError={onError}
        />
      )}
    </div>
  )
}

function RfcFormModal({ onClose, onSaved, onError, row = null }) {
  const [form, setForm] = useState({
    title: row?.title || '',
    description: row?.description || '',
    change_type: row?.change_type || 'normal',
    risk_level: row?.risk_level || 'medium',
    impact_level: row?.impact_level || 'medium',
    customer_id: row?.customer_id ?? '',
    originating_ticket_id: row?.originating_ticket_id ?? '',
    affected_site_asset_id: row?.affected_site_asset_id ?? '',
    assigned_to_user_id: row?.assigned_to_user_id ?? '',
    implementation_plan: row?.implementation_plan || '',
    backout_plan: row?.backout_plan || '',
    test_plan: row?.test_plan || '',
    business_justification: row?.business_justification || '',
    planned_start_at: row?.planned_start_at || '',
    planned_end_at: row?.planned_end_at || '',
  })
  const [submitting, setSubmitting] = useState(false)

  const submit = async () => {
    setSubmitting(true)
    try {
      const numericIfSet = (v) => (v === '' || v === null ? null : Number(v))
      const payload = {
        ...form,
        customer_id: numericIfSet(form.customer_id),
        originating_ticket_id: numericIfSet(form.originating_ticket_id),
        affected_site_asset_id: numericIfSet(form.affected_site_asset_id),
        assigned_to_user_id: numericIfSet(form.assigned_to_user_id),
      }
      if (row?.id) {
        await changeManagementService.update(row.id, payload)
        await onSaved(row.id)
      } else {
        const res = await changeManagementService.create(payload)
        await onSaved(res?.data?.id || null)
      }
    } catch (err) {
      onError(err?.response?.data?.message || 'Unable to save RFC.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Modal open title={row?.id ? `Edit RFC #${row.id}` : 'New change request'} onClose={onClose} size="xl">
      <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div className="sm:col-span-2">
          <Input label="Title" required modelValue={form.title} onUpdateModelValue={(v) => setForm((p) => ({ ...p, title: v }))} />
        </div>
        <div className="sm:col-span-2">
          <Textarea label="Description" required rows={3} modelValue={form.description} onUpdateModelValue={(v) => setForm((p) => ({ ...p, description: v }))} />
        </div>

        <label className="block text-sm font-medium text-gray-700">
          Change type
          <select
            value={form.change_type}
            onChange={(e) => setForm((p) => ({ ...p, change_type: e.target.value }))}
            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm px-3 py-2"
          >
            {TYPES.map((t) => <option key={t} value={t}>{titleize(t)}</option>)}
          </select>
        </label>
        <label className="block text-sm font-medium text-gray-700">
          Risk level
          <select
            value={form.risk_level}
            onChange={(e) => setForm((p) => ({ ...p, risk_level: e.target.value }))}
            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm px-3 py-2"
          >
            {RISKS.map((r) => <option key={r} value={r}>{titleize(r)}</option>)}
          </select>
        </label>
        <label className="block text-sm font-medium text-gray-700">
          Impact level
          <select
            value={form.impact_level}
            onChange={(e) => setForm((p) => ({ ...p, impact_level: e.target.value }))}
            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm px-3 py-2"
          >
            {RISKS.map((r) => <option key={r} value={r}>{titleize(r)}</option>)}
          </select>
        </label>

        <Input label="Customer ID" modelValue={form.customer_id} onUpdateModelValue={(v) => setForm((p) => ({ ...p, customer_id: v }))} />
        <Input label="Originating ticket ID" modelValue={form.originating_ticket_id} onUpdateModelValue={(v) => setForm((p) => ({ ...p, originating_ticket_id: v }))} />
        <Input label="Affected site asset ID" modelValue={form.affected_site_asset_id} onUpdateModelValue={(v) => setForm((p) => ({ ...p, affected_site_asset_id: v }))} />
        <Input label="Assigned to user ID" modelValue={form.assigned_to_user_id} onUpdateModelValue={(v) => setForm((p) => ({ ...p, assigned_to_user_id: v }))} />

        <Input label="Planned start" type="datetime-local" modelValue={form.planned_start_at} onUpdateModelValue={(v) => setForm((p) => ({ ...p, planned_start_at: v }))} />
        <Input label="Planned end" type="datetime-local" modelValue={form.planned_end_at} onUpdateModelValue={(v) => setForm((p) => ({ ...p, planned_end_at: v }))} />

        <div className="sm:col-span-2">
          <Textarea label="Implementation plan" rows={3} modelValue={form.implementation_plan} onUpdateModelValue={(v) => setForm((p) => ({ ...p, implementation_plan: v }))} />
        </div>
        <div className="sm:col-span-2">
          <Textarea label="Back-out plan" rows={3} modelValue={form.backout_plan} onUpdateModelValue={(v) => setForm((p) => ({ ...p, backout_plan: v }))} />
        </div>
        <div className="sm:col-span-2">
          <Textarea label="Test plan" rows={2} modelValue={form.test_plan} onUpdateModelValue={(v) => setForm((p) => ({ ...p, test_plan: v }))} />
        </div>
        <div className="sm:col-span-2">
          <Textarea label="Business justification" rows={2} modelValue={form.business_justification} onUpdateModelValue={(v) => setForm((p) => ({ ...p, business_justification: v }))} />
        </div>
      </div>
      <div className="mt-5 flex justify-end gap-2">
        <Button variant="ghost" onClick={onClose} disabled={submitting}>Cancel</Button>
        <Button variant="primary" onClick={submit} disabled={submitting || !form.title || !form.description}>
          {submitting ? 'Saving...' : 'Save'}
        </Button>
      </div>
    </Modal>
  )
}

// ---------------------------------------------------------------------------
// RFC detail
// ---------------------------------------------------------------------------

function ChangeRequestDetail({ id, onBack, onError, error }) {
  const [rfc, setRfc] = useState(null)
  const [allowed, setAllowed] = useState([])
  const [approvals, setApprovals] = useState([])
  const [tally, setTally] = useState({ approve: 0, reject: 0, abstain: 0, total: 0 })
  const [loading, setLoading] = useState(true)
  const [editing, setEditing] = useState(false)
  const [pendingTransition, setPendingTransition] = useState(null)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const res = await changeManagementService.get(id)
      const data = res?.data || {}
      setRfc(data)
      setAllowed(data.allowed_transitions || [])
      setApprovals(data.approvals || [])
      setTally(data.tally || { approve: 0, reject: 0, abstain: 0, total: 0 })
    } catch (err) {
      console.error('Failed to load RFC', err)
      onError('Unable to load RFC detail.')
    } finally {
      setLoading(false)
    }
  }, [id, onError])

  useEffect(() => { load() }, [load])

  const headerEditable = useMemo(() => {
    if (!rfc) return false
    return ['draft', 'submitted', 'cab_review'].includes(rfc.status)
  }, [rfc])

  const decideFromCab = async () => {
    try {
      const res = await changeManagementService.decide(id, { minimum_voters: 1, threshold: 'majority' })
      if (res?.data?.decided) {
        await load()
      } else {
        onError('Quorum not yet met — no decision applied.')
      }
    } catch (err) {
      onError(err?.response?.data?.message || 'Unable to decide from CAB tally.')
    }
  }

  if (loading) {
    return (
      <div className="space-y-4">
        <Button variant="ghost" onClick={onBack}>← Back to RFCs</Button>
        <Card><div className="py-10 flex justify-center"><Loading text="Loading RFC..." /></div></Card>
      </div>
    )
  }
  if (!rfc) {
    return (
      <div className="space-y-4">
        <Button variant="ghost" onClick={onBack}>← Back to RFCs</Button>
        <Card><div className="py-10 text-center text-gray-500">RFC not found.</div></Card>
      </div>
    )
  }

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <Button variant="ghost" onClick={onBack}>← Back to RFCs</Button>
        {headerEditable ? (
          <Button variant="outline" onClick={() => setEditing(true)}>Edit header</Button>
        ) : null}
      </div>

      {error ? <Alert variant="danger">{error}</Alert> : null}

      <Card>
        <div className="space-y-3">
          <div className="flex flex-wrap items-center gap-2">
            <h2 className="text-xl font-semibold text-gray-900">RFC #{rfc.id} — {rfc.title}</h2>
            <Badge variant={STATUS_VARIANT[rfc.status] || 'secondary'}>{titleize(rfc.status)}</Badge>
            <Badge variant={TYPE_VARIANT[rfc.change_type] || 'secondary'}>{titleize(rfc.change_type)}</Badge>
            <Badge variant={RISK_VARIANT[rfc.risk_level] || 'secondary'}>Risk: {titleize(rfc.risk_level)}</Badge>
            <Badge variant={RISK_VARIANT[rfc.impact_level] || 'secondary'}>Impact: {titleize(rfc.impact_level)}</Badge>
          </div>
          <p className="text-sm text-gray-700 whitespace-pre-wrap">{rfc.description}</p>

          <div className="grid grid-cols-2 sm:grid-cols-3 gap-3 text-sm">
            <Field label="Customer" value={rfc.customer_id ? `#${rfc.customer_id}` : '—'} />
            <Field label="Originating ticket" value={rfc.originating_ticket_id ? `#${rfc.originating_ticket_id}` : '—'} />
            <Field label="Affected asset" value={rfc.affected_site_asset_id ? `#${rfc.affected_site_asset_id}` : '—'} />
            <Field label="Requested by" value={`#${rfc.requested_by_user_id}`} />
            <Field label="Assigned to" value={rfc.assigned_to_user_id ? `#${rfc.assigned_to_user_id}` : '—'} />
            <Field label="Decision by" value={rfc.decision_by_user_id ? `#${rfc.decision_by_user_id}` : '—'} />
            <Field label="Planned start" value={formatDate(rfc.planned_start_at)} />
            <Field label="Planned end" value={formatDate(rfc.planned_end_at)} />
            <Field label="Actual start" value={formatDate(rfc.actual_start_at)} />
            <Field label="Actual end" value={formatDate(rfc.actual_end_at)} />
            <Field label="Submitted" value={formatDate(rfc.submitted_at)} />
            <Field label="CAB started" value={formatDate(rfc.cab_review_started_at)} />
            <Field label="Decided" value={formatDate(rfc.decision_at)} />
            <Field label="Completed" value={formatDate(rfc.completed_at)} />
          </div>

          {rfc.decision_notes ? (
            <Section title="Decision notes" body={rfc.decision_notes} />
          ) : null}
          {rfc.rollback_reason ? (
            <Section title="Rollback reason" body={rfc.rollback_reason} />
          ) : null}
          {rfc.cancellation_reason ? (
            <Section title="Cancellation reason" body={rfc.cancellation_reason} />
          ) : null}

          {rfc.implementation_plan ? <Section title="Implementation plan" body={rfc.implementation_plan} /> : null}
          {rfc.backout_plan ? <Section title="Back-out plan" body={rfc.backout_plan} /> : null}
          {rfc.test_plan ? <Section title="Test plan" body={rfc.test_plan} /> : null}
          {rfc.business_justification ? <Section title="Business justification" body={rfc.business_justification} /> : null}
        </div>
      </Card>

      <Card>
        <div className="flex flex-col gap-3">
          <h3 className="text-lg font-semibold text-gray-900">State machine</h3>
          {allowed.length === 0 ? (
            <p className="text-sm text-gray-500">No transitions available — RFC is in a terminal state.</p>
          ) : (
            <div className="flex flex-wrap gap-2">
              {allowed.map((target) => (
                <Button
                  key={target}
                  variant={target === 'cancelled' || target === 'rolled_back' || target === 'rejected' ? 'danger' : 'primary'}
                  onClick={() => setPendingTransition(target)}
                >
                  Move to {titleize(target)}
                </Button>
              ))}
            </div>
          )}
        </div>
      </Card>

      <Card>
        <CabPanel
          rfc={rfc}
          approvals={approvals}
          tally={tally}
          onVoted={load}
          onDecide={decideFromCab}
          onError={onError}
        />
      </Card>

      {pendingTransition && (
        <TransitionModal
          rfcId={id}
          target={pendingTransition}
          onClose={() => setPendingTransition(null)}
          onApplied={async () => { setPendingTransition(null); await load() }}
          onError={onError}
        />
      )}

      {editing && (
        <RfcFormModal
          row={rfc}
          onClose={() => setEditing(false)}
          onSaved={async () => { setEditing(false); await load() }}
          onError={onError}
        />
      )}
    </div>
  )
}

function Field({ label, value }) {
  return (
    <div>
      <div className="text-xs uppercase tracking-wide text-gray-500">{label}</div>
      <div className="text-sm text-gray-800">{value ?? '—'}</div>
    </div>
  )
}

function Section({ title, body }) {
  return (
    <div className="border-l-4 border-gray-200 pl-3">
      <div className="text-xs uppercase tracking-wide text-gray-500">{title}</div>
      <p className="text-sm text-gray-800 whitespace-pre-wrap">{body}</p>
    </div>
  )
}

function TransitionModal({ rfcId, target, onClose, onApplied, onError }) {
  const [decisionNotes, setDecisionNotes] = useState('')
  const [rollbackReason, setRollbackReason] = useState('')
  const [cancellationReason, setCancellationReason] = useState('')
  const [submitting, setSubmitting] = useState(false)

  const needsRollbackReason = target === 'rolled_back'
  const showsDecisionNotes = target === 'approved' || target === 'rejected'
  const showsCancellationReason = target === 'cancelled'

  const submit = async () => {
    setSubmitting(true)
    try {
      const payload = { status: target }
      if (showsDecisionNotes && decisionNotes) payload.decision_notes = decisionNotes
      if (needsRollbackReason) payload.rollback_reason = rollbackReason
      if (showsCancellationReason && cancellationReason) payload.cancellation_reason = cancellationReason
      await changeManagementService.transition(rfcId, payload)
      await onApplied()
    } catch (err) {
      onError(err?.response?.data?.message || 'Unable to apply transition.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Modal open title={`Move RFC to ${titleize(target)}`} onClose={onClose} size="md">
      <div className="space-y-3">
        {showsDecisionNotes && (
          <Textarea
            label="Decision notes (optional)"
            rows={3}
            modelValue={decisionNotes}
            onUpdateModelValue={setDecisionNotes}
          />
        )}
        {needsRollbackReason && (
          <Textarea
            label="Rollback reason (required)"
            rows={3}
            modelValue={rollbackReason}
            onUpdateModelValue={setRollbackReason}
          />
        )}
        {showsCancellationReason && (
          <Textarea
            label="Cancellation reason (optional)"
            rows={3}
            modelValue={cancellationReason}
            onUpdateModelValue={setCancellationReason}
          />
        )}
        {!showsDecisionNotes && !needsRollbackReason && !showsCancellationReason && (
          <p className="text-sm text-gray-700">
            Apply the <strong>{titleize(target)}</strong> transition? The state change is audited and timestamped.
          </p>
        )}
      </div>
      <div className="mt-5 flex justify-end gap-2">
        <Button variant="ghost" onClick={onClose} disabled={submitting}>Cancel</Button>
        <Button
          variant="primary"
          onClick={submit}
          disabled={submitting || (needsRollbackReason && !rollbackReason.trim())}
        >
          {submitting ? 'Applying...' : 'Apply'}
        </Button>
      </div>
    </Modal>
  )
}

// ---------------------------------------------------------------------------
// CAB voting panel
// ---------------------------------------------------------------------------

function CabPanel({ rfc, approvals, tally, onVoted, onDecide, onError }) {
  const [vote, setVote] = useState('approve')
  const [comment, setComment] = useState('')
  const [voterUserId, setVoterUserId] = useState('')
  const [submitting, setSubmitting] = useState(false)

  const canVote = rfc.status === 'cab_review'

  const submit = async () => {
    setSubmitting(true)
    try {
      const payload = { vote, comment: comment || null }
      if (voterUserId) payload.voter_user_id = Number(voterUserId)
      await changeManagementService.recordVote(rfc.id, payload)
      setComment('')
      setVoterUserId('')
      await onVoted()
    } catch (err) {
      onError(err?.response?.data?.message || 'Unable to record vote.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <h3 className="text-lg font-semibold text-gray-900">CAB approvals</h3>
        {canVote ? (
          <Button variant="outline" onClick={onDecide}>Decide from tally</Button>
        ) : null}
      </div>

      <div className="grid grid-cols-4 gap-2 text-center">
        <TallyCell label="Approve" value={tally.approve} variant="success" />
        <TallyCell label="Reject" value={tally.reject} variant="danger" />
        <TallyCell label="Abstain" value={tally.abstain} variant="secondary" />
        <TallyCell label="Total" value={tally.total} variant="info" />
      </div>

      {canVote ? (
        <div className="rounded-md bg-gray-50 p-3 space-y-3">
          <h4 className="text-sm font-semibold text-gray-700">Cast / overwrite vote</h4>
          <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <label className="block text-sm font-medium text-gray-700">
              Vote
              <select
                value={vote}
                onChange={(e) => setVote(e.target.value)}
                className="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm px-3 py-2"
              >
                {VOTES.map((v) => <option key={v} value={v}>{titleize(v)}</option>)}
              </select>
            </label>
            <Input
              label="Voter user ID (blank = me)"
              modelValue={voterUserId}
              onUpdateModelValue={setVoterUserId}
            />
            <Input
              label="Comment"
              modelValue={comment}
              onUpdateModelValue={setComment}
            />
          </div>
          <div className="flex justify-end">
            <Button variant="primary" onClick={submit} disabled={submitting}>
              {submitting ? 'Recording...' : 'Record vote'}
            </Button>
          </div>
        </div>
      ) : (
        <p className="text-sm text-gray-500">
          Voting is only open while RFC is in <strong>cab_review</strong> status.
        </p>
      )}

      {approvals.length === 0 ? (
        <p className="py-3 text-sm text-gray-500">No votes recorded yet.</p>
      ) : (
        <div className="overflow-x-auto">
          <table className="min-w-full divide-y divide-gray-200">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Voter</th>
                <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Vote</th>
                <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Comment</th>
                <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Voted at</th>
              </tr>
            </thead>
            <tbody className="bg-white divide-y divide-gray-100">
              {approvals.map((a) => (
                <tr key={a.id}>
                  <td className="px-4 py-2 text-sm text-gray-800">#{a.user_id}</td>
                  <td className="px-4 py-2 text-sm">
                    <Badge variant={VOTE_VARIANT[a.vote] || 'secondary'}>{titleize(a.vote)}</Badge>
                  </td>
                  <td className="px-4 py-2 text-sm text-gray-700">{a.comment || '—'}</td>
                  <td className="px-4 py-2 text-sm text-gray-700">{formatDate(a.voted_at)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}

function TallyCell({ label, value, variant }) {
  return (
    <div className="rounded-md border border-gray-200 px-3 py-2">
      <div className="text-xs uppercase tracking-wide text-gray-500">{label}</div>
      <div className="mt-1">
        <Badge variant={variant}>{value}</Badge>
      </div>
    </div>
  )
}

// ---------------------------------------------------------------------------
// Calendar tab
// ---------------------------------------------------------------------------

function CalendarTab({ onError, onSelect }) {
  const today = useMemo(() => {
    const d = new Date()
    d.setHours(0, 0, 0, 0)
    return d
  }, [])

  const [start, setStart] = useState(() => fmtDate(today))
  const [end, setEnd] = useState(() => {
    const e = new Date(today.getTime() + 14 * 86400000)
    return fmtDate(e)
  })
  const [rows, setRows] = useState([])
  const [loading, setLoading] = useState(true)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const res = await changeManagementService.window({
        start: `${start} 00:00:00`,
        end: `${end} 23:59:59`,
      })
      setRows(res?.data?.change_requests || [])
    } catch (err) {
      console.error('Failed to load change window', err)
      onError('Unable to load change calendar.')
    } finally {
      setLoading(false)
    }
  }, [start, end, onError])

  useEffect(() => { load() }, [load])

  return (
    <div className="space-y-4">
      <Card>
        <div className="flex flex-wrap items-end gap-3">
          <Input label="From" type="date" modelValue={start} onUpdateModelValue={setStart} fullWidth={false} />
          <Input label="To" type="date" modelValue={end} onUpdateModelValue={setEnd} fullWidth={false} />
          <Button variant="outline" onClick={load}>Refresh</Button>
        </div>
      </Card>

      <Card>
        {loading ? (
          <div className="py-10 flex justify-center"><Loading text="Loading calendar..." /></div>
        ) : rows.length === 0 ? (
          <div className="py-10 text-center text-gray-500">No RFCs scheduled in this window.</div>
        ) : (
          <div className="space-y-2">
            {rows.map((row) => (
              <button
                key={row.id}
                onClick={() => onSelect(row.id)}
                className="w-full text-left rounded-md border border-gray-200 p-3 hover:bg-gray-50"
              >
                <div className="flex flex-wrap items-center gap-2">
                  <span className="text-sm font-semibold text-gray-900">RFC #{row.id} — {row.title}</span>
                  <Badge variant={STATUS_VARIANT[row.status] || 'secondary'}>{titleize(row.status)}</Badge>
                  <Badge variant={RISK_VARIANT[row.risk_level] || 'secondary'}>{titleize(row.risk_level)}</Badge>
                  <Badge variant={TYPE_VARIANT[row.change_type] || 'secondary'}>{titleize(row.change_type)}</Badge>
                </div>
                <div className="mt-1 text-xs text-gray-600">
                  {formatDate(row.planned_start_at)} → {formatDate(row.planned_end_at)}
                </div>
              </button>
            ))}
          </div>
        )}
      </Card>
    </div>
  )
}

function fmtDate(d) {
  const y = d.getFullYear()
  const m = String(d.getMonth() + 1).padStart(2, '0')
  const day = String(d.getDate()).padStart(2, '0')
  return `${y}-${m}-${day}`
}
