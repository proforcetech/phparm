import { useCallback, useEffect, useMemo, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'

import Alert from '../../components/ui/Alert'
import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Modal from '../../components/ui/Modal'
import Textarea from '../../components/ui/Textarea'
import { useToast } from '../../stores/toast.jsx'
import routingService from '../../../services/routing.service'

const PLAN_STATUS_VARIANT = {
  draft: 'secondary',
  active: 'info',
  completed: 'success',
  cancelled: 'danger',
}

const STOP_STATUS_VARIANT = {
  planned: 'secondary',
  en_route: 'info',
  arrived: 'info',
  completed: 'success',
  skipped: 'warning',
  cancelled: 'danger',
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

function formatDateOnly(s) {
  if (!s) return '—'
  try {
    return new Date(String(s).replace(' ', 'T')).toLocaleDateString()
  } catch {
    return s
  }
}

function unwrap(res) {
  return res?.data ?? res
}

function unwrapList(res, keys) {
  const data = unwrap(res)
  if (Array.isArray(data)) return data
  for (const k of keys) {
    if (Array.isArray(data?.[k])) return data[k]
  }
  return []
}

function unwrapPlan(res) {
  const data = unwrap(res)
  if (!data) return null
  if (data.route_plan) return data.route_plan
  if (data.plan) return data.plan
  return data
}

export default function RoutePlanDetail() {
  const { id } = useParams()
  const navigate = useNavigate()
  const toast = useToast()

  const [plan, setPlan] = useState(null)
  const [stops, setStops] = useState([])
  const [loading, setLoading] = useState(true)
  const [stopsLoading, setStopsLoading] = useState(false)
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)

  const [showEdit, setShowEdit] = useState(false)
  const [showDelete, setShowDelete] = useState(false)
  const [showCancel, setShowCancel] = useState(false)
  const [showAddStop, setShowAddStop] = useState(false)
  const [editingStop, setEditingStop] = useState(null)
  const [skipStop, setSkipStop] = useState(null)

  const loadPlan = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      const res = await routingService.getPlan(id)
      setPlan(unwrapPlan(res))
    } catch (err) {
      setError(err?.response?.data?.message || 'Unable to load route plan.')
    } finally {
      setLoading(false)
    }
  }, [id])

  const loadStops = useCallback(async () => {
    setStopsLoading(true)
    try {
      const res = await routingService.listStops(id)
      setStops(unwrapList(res, ['stops', 'route_plan_stops', 'items']))
    } catch (err) {
      setError(err?.response?.data?.message || 'Unable to load stops.')
    } finally {
      setStopsLoading(false)
    }
  }, [id])

  useEffect(() => { loadPlan(); loadStops() }, [loadPlan, loadStops])

  const wrap = useCallback(async (label, fn) => {
    setBusy(true)
    try {
      await fn()
      toast.success(label)
    } catch (err) {
      toast.error(err?.response?.data?.message || `Unable to ${label.toLowerCase()}.`)
    } finally {
      setBusy(false)
    }
  }, [toast])

  const onActivate = () => wrap('Plan activated', async () => {
    await routingService.activatePlan(id)
    await loadPlan()
  })

  const onComplete = () => wrap('Plan completed', async () => {
    await routingService.completePlan(id)
    await loadPlan()
  })

  const onOptimize = () => wrap('Stops optimized', async () => {
    await routingService.optimizePlan(id, {})
    await loadStops()
  })

  const onCancel = (reason) => wrap('Plan cancelled', async () => {
    const payload = reason ? { reason } : {}
    await routingService.cancelPlan(id, payload)
    setShowCancel(false)
    await loadPlan()
  })

  const onDelete = () => wrap('Plan deleted', async () => {
    await routingService.deletePlan(id)
    setShowDelete(false)
    navigate('/cp/routing/route-plans')
  })

  const onSavedEdit = async () => {
    setShowEdit(false)
    toast.success('Plan updated.')
    await loadPlan()
  }

  const onStopAction = (label, stopId, fn) => wrap(label, async () => {
    await fn(stopId, {})
    await loadStops()
  })

  const onDeleteStop = (stopId) => wrap('Stop deleted', async () => {
    await routingService.deleteStop(stopId)
    await loadStops()
  })

  const onSkip = async (reason) => {
    if (!skipStop) return
    await wrap('Stop skipped', async () => {
      await routingService.skippedStop(skipStop.id, { reason })
      setSkipStop(null)
      await loadStops()
    })
  }

  const status = plan?.status || ''
  const isDraft = status === 'draft'
  const isActive = status === 'active'

  const nextSequence = useMemo(() => {
    if (!stops.length) return 1
    const max = stops.reduce((acc, s) => Math.max(acc, Number(s.sequence) || 0), 0)
    return max + 1
  }, [stops])

  if (loading) {
    return (
      <div className="space-y-4">
        <Link to="/cp/routing/route-plans" className="text-sm text-primary-600 hover:underline">
          ← Back to route plans
        </Link>
        <Card><div className="py-10 flex justify-center"><Loading text="Loading plan..." /></div></Card>
      </div>
    )
  }

  if (!plan) {
    return (
      <div className="space-y-4">
        <Link to="/cp/routing/route-plans" className="text-sm text-primary-600 hover:underline">
          ← Back to route plans
        </Link>
        {error ? <Alert variant="danger">{error}</Alert> : null}
        <Card><div className="py-10 text-center text-gray-500">Route plan not found.</div></Card>
      </div>
    )
  }

  return (
    <div className="space-y-4">
      <Link to="/cp/routing/route-plans" className="text-sm text-primary-600 hover:underline">
        ← Back to route plans
      </Link>

      {error ? <Alert variant="danger">{error}</Alert> : null}

      <Card>
        <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
          <div className="space-y-2">
            <div className="flex flex-wrap items-center gap-2">
              <h1 className="text-2xl font-bold text-gray-900">{plan.name || `Plan #${plan.id}`}</h1>
              <Badge variant={PLAN_STATUS_VARIANT[plan.status] || 'secondary'}>
                {titleize(plan.status)}
              </Badge>
              <span className="text-sm text-gray-500">#{plan.id}</span>
            </div>
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-3 text-sm">
              <Field label="Scheduled" value={formatDateOnly(plan.scheduled_date)} />
              <Field label="Owner" value={plan.owner_user_id ? `#${plan.owner_user_id}` : '—'} />
              <Field label="Created" value={formatDate(plan.created_at)} />
            </div>
            {plan.notes ? (
              <div className="border-l-4 border-gray-200 pl-3">
                <div className="text-xs uppercase tracking-wide text-gray-500">Notes</div>
                <p className="text-sm text-gray-800 whitespace-pre-wrap">{plan.notes}</p>
              </div>
            ) : null}
          </div>
          <div className="flex flex-wrap gap-2 justify-end">
            {isDraft && (
              <Button variant="primary" onClick={onActivate} disabled={busy}>Activate</Button>
            )}
            {isActive && (
              <Button variant="primary" onClick={onComplete} disabled={busy}>Complete</Button>
            )}
            {(isDraft || isActive) && (
              <Button variant="outline" onClick={onOptimize} disabled={busy}>Optimize stops</Button>
            )}
            {(isDraft || isActive) && (
              <Button variant="danger" onClick={() => setShowCancel(true)} disabled={busy}>Cancel</Button>
            )}
            <Button variant="outline" onClick={() => setShowEdit(true)} disabled={busy}>Edit</Button>
            <Button variant="ghost" onClick={() => setShowDelete(true)} disabled={busy}>Delete</Button>
          </div>
        </div>
      </Card>

      <Card padding={false}>
        <div className="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
          <h2 className="text-lg font-semibold text-gray-900">Stops</h2>
          <Button variant="primary" size="sm" onClick={() => setShowAddStop(true)}>Add stop</Button>
        </div>
        {stopsLoading ? (
          <div className="py-10 flex justify-center"><Loading text="Loading stops..." /></div>
        ) : stops.length === 0 ? (
          <div className="py-10 text-center text-gray-500">No stops on this plan yet.</div>
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Seq</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Address / site</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">ETA</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Arrived</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Completed</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Notes</th>
                  <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                </tr>
              </thead>
              <tbody className="bg-white divide-y divide-gray-100">
                {stops.map((stop) => (
                  <tr key={stop.id}>
                    <td className="px-4 py-2 text-sm text-gray-700">{stop.sequence ?? '—'}</td>
                    <td className="px-4 py-2 text-sm text-gray-900">
                      <div>{stop.address || (stop.site_id ? `Site #${stop.site_id}` : '—')}</div>
                      {stop.site_id && stop.address ? (
                        <div className="text-xs text-gray-500">Site #{stop.site_id}</div>
                      ) : null}
                    </td>
                    <td className="px-4 py-2 text-sm">
                      <Badge variant={STOP_STATUS_VARIANT[stop.status] || 'secondary'}>
                        {titleize(stop.status)}
                      </Badge>
                    </td>
                    <td className="px-4 py-2 text-sm text-gray-700">{formatDate(stop.eta)}</td>
                    <td className="px-4 py-2 text-sm text-gray-700">{formatDate(stop.arrived_at)}</td>
                    <td className="px-4 py-2 text-sm text-gray-700">{formatDate(stop.completed_at)}</td>
                    <td className="px-4 py-2 text-sm text-gray-700 max-w-xs truncate">{stop.notes || '—'}</td>
                    <td className="px-4 py-2 text-sm text-right">
                      <div className="flex flex-wrap gap-1 justify-end">
                        {stop.status === 'planned' && (
                          <Button
                            size="xs"
                            variant="outline"
                            onClick={() => onStopAction('Stop en route', stop.id, routingService.enRouteStop)}
                            disabled={busy}
                          >
                            En route
                          </Button>
                        )}
                        {stop.status === 'en_route' && (
                          <Button
                            size="xs"
                            variant="outline"
                            onClick={() => onStopAction('Stop arrived', stop.id, routingService.arrivedStop)}
                            disabled={busy}
                          >
                            Arrived
                          </Button>
                        )}
                        {stop.status === 'arrived' && (
                          <>
                            <Button
                              size="xs"
                              variant="primary"
                              onClick={() => onStopAction('Stop completed', stop.id, routingService.completedStop)}
                              disabled={busy}
                            >
                              Complete
                            </Button>
                            <Button
                              size="xs"
                              variant="danger"
                              onClick={() => setSkipStop(stop)}
                              disabled={busy}
                            >
                              Skip
                            </Button>
                          </>
                        )}
                        <Button
                          size="xs"
                          variant="ghost"
                          onClick={() => setEditingStop(stop)}
                          disabled={busy}
                        >
                          Edit
                        </Button>
                        <Button
                          size="xs"
                          variant="ghost"
                          onClick={() => {
                            if (confirm('Delete this stop?')) onDeleteStop(stop.id)
                          }}
                          disabled={busy}
                        >
                          Delete
                        </Button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      {showEdit && (
        <PlanEditModal
          plan={plan}
          onClose={() => setShowEdit(false)}
          onSaved={onSavedEdit}
          onError={(msg) => toast.error(msg)}
        />
      )}

      {showCancel && (
        <CancelModal
          onClose={() => setShowCancel(false)}
          onConfirm={onCancel}
          busy={busy}
        />
      )}

      {showDelete && (
        <ConfirmModal
          title="Delete route plan?"
          body="This permanently removes the plan and its stops. This cannot be undone."
          confirmLabel="Delete"
          confirmVariant="danger"
          onClose={() => setShowDelete(false)}
          onConfirm={onDelete}
          busy={busy}
        />
      )}

      {showAddStop && (
        <StopFormModal
          planId={id}
          defaultSequence={nextSequence}
          onClose={() => setShowAddStop(false)}
          onSaved={async () => { setShowAddStop(false); toast.success('Stop added.'); await loadStops() }}
          onError={(msg) => toast.error(msg)}
        />
      )}

      {editingStop && (
        <StopFormModal
          planId={id}
          stop={editingStop}
          onClose={() => setEditingStop(null)}
          onSaved={async () => { setEditingStop(null); toast.success('Stop updated.'); await loadStops() }}
          onError={(msg) => toast.error(msg)}
        />
      )}

      {skipStop && (
        <SkipStopModal
          onClose={() => setSkipStop(null)}
          onConfirm={onSkip}
          busy={busy}
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

function PlanEditModal({ plan, onClose, onSaved, onError }) {
  const [form, setForm] = useState({
    name: plan?.name || '',
    scheduled_date: plan?.scheduled_date ? String(plan.scheduled_date).slice(0, 10) : '',
    notes: plan?.notes || '',
  })
  const [submitting, setSubmitting] = useState(false)

  const submit = async () => {
    if (!form.name.trim()) return
    setSubmitting(true)
    try {
      await routingService.updatePlan(plan.id, {
        name: form.name.trim(),
        scheduled_date: form.scheduled_date || null,
        notes: form.notes || null,
      })
      await onSaved()
    } catch (err) {
      onError(err?.response?.data?.message || 'Unable to update plan.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Modal open title="Edit route plan" onClose={onClose} size="lg">
      <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div className="sm:col-span-2">
          <Input
            label="Name"
            required
            modelValue={form.name}
            onUpdateModelValue={(v) => setForm((p) => ({ ...p, name: v }))}
          />
        </div>
        <Input
          label="Scheduled date"
          type="date"
          modelValue={form.scheduled_date}
          onUpdateModelValue={(v) => setForm((p) => ({ ...p, scheduled_date: v }))}
        />
        <div className="sm:col-span-2">
          <Textarea
            label="Notes"
            rows={3}
            modelValue={form.notes}
            onUpdateModelValue={(v) => setForm((p) => ({ ...p, notes: v }))}
          />
        </div>
      </div>
      <div className="mt-5 flex justify-end gap-2">
        <Button variant="ghost" onClick={onClose} disabled={submitting}>Cancel</Button>
        <Button variant="primary" onClick={submit} disabled={submitting || !form.name.trim()}>
          {submitting ? 'Saving...' : 'Save'}
        </Button>
      </div>
    </Modal>
  )
}

function CancelModal({ onClose, onConfirm, busy }) {
  const [reason, setReason] = useState('')
  return (
    <Modal open title="Cancel this route plan?" onClose={onClose} size="md">
      <p className="text-sm text-gray-700 mb-3">
        Cancelling will mark the plan and any incomplete stops as cancelled.
      </p>
      <Textarea
        label="Reason (optional)"
        rows={3}
        modelValue={reason}
        onUpdateModelValue={setReason}
      />
      <div className="mt-5 flex justify-end gap-2">
        <Button variant="ghost" onClick={onClose} disabled={busy}>Keep plan</Button>
        <Button variant="danger" onClick={() => onConfirm(reason.trim())} disabled={busy}>
          {busy ? 'Cancelling...' : 'Cancel plan'}
        </Button>
      </div>
    </Modal>
  )
}

function ConfirmModal({ title, body, confirmLabel, confirmVariant = 'primary', onClose, onConfirm, busy }) {
  return (
    <Modal open title={title} onClose={onClose} size="md">
      <p className="text-sm text-gray-700">{body}</p>
      <div className="mt-5 flex justify-end gap-2">
        <Button variant="ghost" onClick={onClose} disabled={busy}>Cancel</Button>
        <Button variant={confirmVariant} onClick={onConfirm} disabled={busy}>
          {busy ? 'Working...' : confirmLabel}
        </Button>
      </div>
    </Modal>
  )
}

function StopFormModal({ planId, stop = null, defaultSequence = 1, onClose, onSaved, onError }) {
  const [form, setForm] = useState({
    site_id: stop?.site_id ?? '',
    address: stop?.address || '',
    sequence: stop?.sequence ?? defaultSequence,
    eta: stop?.eta ? toDatetimeLocal(stop.eta) : '',
    notes: stop?.notes || '',
  })
  const [submitting, setSubmitting] = useState(false)

  const submit = async () => {
    setSubmitting(true)
    try {
      const payload = {
        site_id: form.site_id ? Number(form.site_id) : null,
        address: form.address || null,
        sequence: Math.max(1, Number(form.sequence) || 1),
        eta: form.eta || null,
        notes: form.notes || null,
      }
      if (stop?.id) {
        await routingService.updateStop(stop.id, payload)
      } else {
        await routingService.addStop(planId, payload)
      }
      await onSaved()
    } catch (err) {
      onError(err?.response?.data?.message || 'Unable to save stop.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Modal open title={stop?.id ? `Edit stop #${stop.id}` : 'Add stop'} onClose={onClose} size="lg">
      <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <Input
          label="Site ID"
          modelValue={form.site_id}
          onUpdateModelValue={(v) => setForm((p) => ({ ...p, site_id: v }))}
        />
        <Input
          label="Sequence"
          type="number"
          modelValue={form.sequence}
          onUpdateModelValue={(v) => setForm((p) => ({ ...p, sequence: v }))}
        />
        <div className="sm:col-span-2">
          <Input
            label="Address"
            modelValue={form.address}
            onUpdateModelValue={(v) => setForm((p) => ({ ...p, address: v }))}
          />
        </div>
        <Input
          label="ETA"
          type="datetime-local"
          modelValue={form.eta}
          onUpdateModelValue={(v) => setForm((p) => ({ ...p, eta: v }))}
        />
        <div className="sm:col-span-2">
          <Textarea
            label="Notes"
            rows={3}
            modelValue={form.notes}
            onUpdateModelValue={(v) => setForm((p) => ({ ...p, notes: v }))}
          />
        </div>
      </div>
      <div className="mt-5 flex justify-end gap-2">
        <Button variant="ghost" onClick={onClose} disabled={submitting}>Cancel</Button>
        <Button variant="primary" onClick={submit} disabled={submitting}>
          {submitting ? 'Saving...' : stop?.id ? 'Save stop' : 'Add stop'}
        </Button>
      </div>
    </Modal>
  )
}

function SkipStopModal({ onClose, onConfirm, busy }) {
  const [reason, setReason] = useState('')
  return (
    <Modal open title="Skip this stop?" onClose={onClose} size="md">
      <Textarea
        label="Reason (required)"
        rows={3}
        modelValue={reason}
        onUpdateModelValue={setReason}
      />
      <div className="mt-5 flex justify-end gap-2">
        <Button variant="ghost" onClick={onClose} disabled={busy}>Cancel</Button>
        <Button variant="danger" onClick={() => onConfirm(reason.trim())} disabled={busy || !reason.trim()}>
          {busy ? 'Skipping...' : 'Skip stop'}
        </Button>
      </div>
    </Modal>
  )
}

function toDatetimeLocal(s) {
  if (!s) return ''
  try {
    const d = new Date(String(s).replace(' ', 'T'))
    if (Number.isNaN(d.getTime())) return ''
    const pad = (n) => String(n).padStart(2, '0')
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`
  } catch {
    return ''
  }
}
