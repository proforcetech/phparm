import { useCallback, useEffect, useMemo, useState } from 'react'

import Alert from '../../components/ui/Alert'
import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Modal from '../../components/ui/Modal'
import Textarea from '../../components/ui/Textarea'
import Timeline from '../../components/Timeline'
import { assetLifecycleService } from '../../../services/asset-lifecycle.service'
import {
  DECOMMISSION_STATUS_VARIANTS,
  formatDate,
  formatDateTime,
  formatCurrencyCents,
  normalizeAuditTimeline,
  titleizeStatus,
} from './lifecycleHelpers'

/**
 * Phase 13 admin UI (#124) — decommissions.
 *
 * Same shape as AssetAcquisitions: list + detail + audit timeline. Action
 * surface is keyed off `allowed_transitions`. The terminal step (`retire`)
 * is gated by a separate permission server-side and also flips the
 * underlying site_asset to status='retired', so the UI mirrors that with
 * a stronger confirm.
 */

const STATUSES = [
  'initiated',
  'wipe_in_progress',
  'wipe_complete',
  'recovery_in_progress',
  'recovery_complete',
  'entitlement_updated',
  'audited',
  'retired',
  'cancelled',
]

const TRANSITION_LABEL = {
  wipe_in_progress: 'Start wipe',
  wipe_complete: 'Complete wipe',
  recovery_in_progress: 'Start recovery',
  recovery_complete: 'Complete recovery',
  entitlement_updated: 'Update entitlements',
  audited: 'Mark audited',
  retired: 'Retire asset',
  cancelled: 'Cancel',
}

const MODAL_TRANSITIONS = new Set([
  'wipe_complete',
  'recovery_complete',
  'cancelled',
])

export default function AssetDecommissions() {
  const [statusFilter, setStatusFilter] = useState('')
  const [list, setList] = useState([])
  const [total, setTotal] = useState(0)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  const [selectedId, setSelectedId] = useState(null)
  const [selected, setSelected] = useState(null)
  const [detailLoading, setDetailLoading] = useState(false)
  const [timeline, setTimeline] = useState([])

  const [modalTarget, setModalTarget] = useState(null)
  const [modalForm, setModalForm] = useState({})
  const [submitting, setSubmitting] = useState(false)

  const loadList = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      const params = { per_page: 50 }
      if (statusFilter) params.status = statusFilter
      const res = await assetLifecycleService.listDecommissions(params)
      setList(Array.isArray(res?.decommissions) ? res.decommissions : [])
      setTotal(res?.total ?? 0)
    } catch (err) {
      console.error('Failed to load decommissions', err)
      setError('Unable to load decommissions.')
    } finally {
      setLoading(false)
    }
  }, [statusFilter])

  useEffect(() => {
    loadList()
  }, [loadList])

  const loadDetail = useCallback(async (id) => {
    setDetailLoading(true)
    try {
      const [row, audit] = await Promise.all([
        assetLifecycleService.getDecommission(id),
        assetLifecycleService.timelineFor('asset_decommission', id),
      ])
      setSelected(row)
      setTimeline(normalizeAuditTimeline(audit))
    } catch (err) {
      console.error('Failed to load decommission detail', err)
      setError('Unable to load decommission detail.')
    } finally {
      setDetailLoading(false)
    }
  }, [])

  useEffect(() => {
    if (selectedId) loadDetail(selectedId)
    else {
      setSelected(null)
      setTimeline([])
    }
  }, [selectedId, loadDetail])

  const totalsByStatus = useMemo(() => {
    return list.reduce((acc, row) => {
      acc[row.status] = (acc[row.status] || 0) + 1
      return acc
    }, {})
  }, [list])

  const refreshAfterAction = useCallback(async () => {
    await loadList()
    if (selected?.id) await loadDetail(selected.id)
  }, [loadList, loadDetail, selected])

  const oneClickTransition = async (target) => {
    if (!selected) return
    const isRetire = target === 'retired'
    const message = isRetire
      ? `Retire asset for decommission #${selected.id}? This sets the underlying site_asset to status='retired' and CANNOT be undone.`
      : `${TRANSITION_LABEL[target] || target} decommission #${selected.id}?`
    if (!window.confirm(message)) return
    setSubmitting(true)
    setError('')
    try {
      await dispatchTransition(selected.id, target, {})
      await refreshAfterAction()
    } catch (err) {
      console.error('Transition failed', err)
      setError(err?.response?.data?.message || `Unable to ${TRANSITION_LABEL[target] || target}.`)
    } finally {
      setSubmitting(false)
    }
  }

  const openModal = (target) => {
    setModalTarget(target)
    setModalForm(initialFormFor(target))
  }

  const submitModal = async () => {
    if (!selected || !modalTarget) return
    setSubmitting(true)
    setError('')
    try {
      await dispatchTransition(selected.id, modalTarget, modalForm)
      setModalTarget(null)
      setModalForm({})
      await refreshAfterAction()
    } catch (err) {
      console.error('Transition failed', err)
      setError(err?.response?.data?.message || 'Unable to complete action.')
    } finally {
      setSubmitting(false)
    }
  }

  const renderActionButtons = () => {
    if (!selected) return null
    const allowed = selected.allowed_transitions || []
    if (allowed.length === 0) {
      return <span className="text-sm text-gray-500">No further actions — terminal state.</span>
    }
    return allowed.map((target) => {
      const danger = target === 'retired' || target === 'cancelled'
      return (
        <Button
          key={target}
          variant={danger ? 'outline' : 'primary'}
          disabled={submitting}
          onClick={() => (MODAL_TRANSITIONS.has(target) ? openModal(target) : oneClickTransition(target))}
        >
          {TRANSITION_LABEL[target] || titleizeStatus(target)}
        </Button>
      )
    })
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Asset Decommissions</h1>
          <p className="mt-1 text-sm text-gray-500">
            Retiring installed assets — wipe, recovery, entitlements, audit, retire.
          </p>
        </div>
        <Button variant="outline" onClick={loadList}>Refresh</Button>
      </div>

      <Card>
        <div className="flex flex-wrap items-center gap-3">
          <span className="text-sm font-medium text-gray-700">Filter:</span>
          <select
            value={statusFilter}
            onChange={(e) => setStatusFilter(e.target.value)}
            className="border rounded-md px-3 py-2 text-sm text-gray-700"
          >
            <option value="">All statuses ({total})</option>
            {STATUSES.map((s) => (
              <option key={s} value={s}>
                {titleizeStatus(s)} ({totalsByStatus[s] || 0})
              </option>
            ))}
          </select>
        </div>
      </Card>

      {error ? <Alert variant="danger">{error}</Alert> : null}

      <div className="grid grid-cols-1 lg:grid-cols-5 gap-6">
        <Card className="lg:col-span-2">
          <h2 className="text-lg font-semibold text-gray-900 mb-3">Decommissions</h2>
          {loading ? (
            <div className="py-10 flex justify-center">
              <Loading text="Loading decommissions..." />
            </div>
          ) : list.length === 0 ? (
            <div className="py-10 text-center text-gray-500">No decommissions match this filter.</div>
          ) : (
            <ul className="divide-y divide-gray-200">
              {list.map((row) => (
                <li
                  key={row.id}
                  className={`py-3 cursor-pointer ${selectedId === row.id ? 'bg-blue-50 -mx-3 px-3 rounded' : 'hover:bg-gray-50 -mx-3 px-3 rounded'}`}
                  onClick={() => setSelectedId(row.id)}
                >
                  <div className="flex items-center justify-between gap-2">
                    <div className="min-w-0">
                      <div className="text-sm font-medium text-gray-900 truncate">
                        Decommission #{row.id} · Asset #{row.site_asset_id}
                      </div>
                      <div className="text-xs text-gray-500">
                        Customer #{row.customer_id || '—'} · requested {formatDate(row.requested_at)}
                        {row.requires_wipe ? ' · wipe required' : ''}
                      </div>
                    </div>
                    <Badge variant={DECOMMISSION_STATUS_VARIANTS[row.status] || 'secondary'}>
                      {titleizeStatus(row.status)}
                    </Badge>
                  </div>
                </li>
              ))}
            </ul>
          )}
        </Card>

        <div className="lg:col-span-3 space-y-6">
          {!selectedId ? (
            <Card>
              <div className="py-10 text-center text-gray-500">Select a decommission to see details.</div>
            </Card>
          ) : detailLoading || !selected ? (
            <Card>
              <div className="py-10 flex justify-center">
                <Loading text="Loading detail..." />
              </div>
            </Card>
          ) : (
            <>
              <Card>
                <div className="flex items-start justify-between gap-4">
                  <div>
                    <h2 className="text-lg font-semibold text-gray-900">
                      Decommission #{selected.id} · Asset #{selected.site_asset_id}
                    </h2>
                    <p className="text-sm text-gray-500">{selected.reason || 'No reason recorded.'}</p>
                  </div>
                  <Badge variant={DECOMMISSION_STATUS_VARIANTS[selected.status] || 'secondary'}>
                    {titleizeStatus(selected.status)}
                  </Badge>
                </div>

                <dl className="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3 text-sm">
                  <div>
                    <dt className="text-gray-500">Customer</dt>
                    <dd className="text-gray-900">{selected.customer_id ? `#${selected.customer_id}` : '—'}</dd>
                  </div>
                  <div>
                    <dt className="text-gray-500">Requires wipe</dt>
                    <dd className="text-gray-900">{selected.requires_wipe ? 'Yes' : 'No'}</dd>
                  </div>
                  <div>
                    <dt className="text-gray-500">Recovery method</dt>
                    <dd className="text-gray-900">{selected.recovery_method || '—'}</dd>
                  </div>
                  <div>
                    <dt className="text-gray-500">Requested</dt>
                    <dd className="text-gray-900">{formatDateTime(selected.requested_at)}</dd>
                  </div>
                  <div>
                    <dt className="text-gray-500">Wipe</dt>
                    <dd className="text-gray-900">
                      {selected.wipe_started_at ? `Started ${formatDateTime(selected.wipe_started_at)}` : '—'}
                      {selected.wipe_completed_at ? ` · Done ${formatDateTime(selected.wipe_completed_at)}` : ''}
                    </dd>
                  </div>
                  <div>
                    <dt className="text-gray-500">Wipe certificate</dt>
                    <dd className="text-gray-900">
                      {selected.wipe_certificate_url ? (
                        <a className="text-blue-600 hover:underline" href={selected.wipe_certificate_url} target="_blank" rel="noreferrer">
                          View
                        </a>
                      ) : '—'}
                    </dd>
                  </div>
                  <div>
                    <dt className="text-gray-500">Recovery</dt>
                    <dd className="text-gray-900">
                      {selected.recovery_started_at ? `Started ${formatDateTime(selected.recovery_started_at)}` : '—'}
                      {selected.recovery_completed_at ? ` · Done ${formatDateTime(selected.recovery_completed_at)}` : ''}
                    </dd>
                  </div>
                  <div>
                    <dt className="text-gray-500">Recovery value</dt>
                    <dd className="text-gray-900">{formatCurrencyCents(selected.recovery_value_cents)}</dd>
                  </div>
                  <div>
                    <dt className="text-gray-500">Audited</dt>
                    <dd className="text-gray-900">
                      {selected.audited_at ? formatDateTime(selected.audited_at) : '—'}
                      {selected.audit_log_id ? ` · audit_log #${selected.audit_log_id}` : ''}
                    </dd>
                  </div>
                  <div>
                    <dt className="text-gray-500">Retired</dt>
                    <dd className="text-gray-900">{selected.retired_at ? formatDateTime(selected.retired_at) : '—'}</dd>
                  </div>
                  {selected.cancelled_at ? (
                    <div className="sm:col-span-2">
                      <dt className="text-gray-500">Cancelled</dt>
                      <dd className="text-gray-900">
                        {formatDateTime(selected.cancelled_at)} — {selected.cancelled_reason || 'no reason recorded'}
                      </dd>
                    </div>
                  ) : null}
                </dl>

                <div className="mt-5 flex flex-wrap gap-2">{renderActionButtons()}</div>
              </Card>

              <Card>
                <h3 className="text-md font-semibold text-gray-900 mb-3">Activity timeline</h3>
                <Timeline events={timeline} />
              </Card>
            </>
          )}
        </div>
      </div>

      <Modal
        open={modalTarget !== null}
        title={
          modalTarget && selected
            ? `${TRANSITION_LABEL[modalTarget] || modalTarget} — decommission #${selected.id}`
            : ''
        }
        onClose={() => setModalTarget(null)}
      >
        {modalTarget ? (
          <ActionForm
            target={modalTarget}
            value={modalForm}
            onChange={setModalForm}
            onSubmit={submitModal}
            onCancel={() => setModalTarget(null)}
            submitting={submitting}
          />
        ) : null}
      </Modal>
    </div>
  )
}

function initialFormFor(target) {
  switch (target) {
    case 'wipe_complete':
      return { wipe_certificate_url: '', note: '' }
    case 'recovery_complete':
      return { recovery_reference: '', recovery_value_cents: '', note: '' }
    case 'cancelled':
      return { reason: '' }
    default:
      return { note: '' }
  }
}

function ActionForm({ target, value, onChange, onSubmit, onCancel, submitting }) {
  const update = (field, v) => onChange({ ...value, [field]: v })
  let body = null
  switch (target) {
    case 'wipe_complete':
      body = (
        <>
          <Input
            label="Wipe certificate URL"
            modelValue={value.wipe_certificate_url || ''}
            onUpdateModelValue={(v) => update('wipe_certificate_url', v)}
            placeholder="https://..."
          />
          <Input
            label="Note (optional)"
            modelValue={value.note || ''}
            onUpdateModelValue={(v) => update('note', v)}
          />
        </>
      )
      break
    case 'recovery_complete':
      body = (
        <>
          <Input
            label="Recovery reference (vendor ticket, RMA, etc.)"
            modelValue={value.recovery_reference || ''}
            onUpdateModelValue={(v) => update('recovery_reference', v)}
          />
          <Input
            label="Recovery value (cents)"
            type="number"
            modelValue={value.recovery_value_cents || ''}
            onUpdateModelValue={(v) => update('recovery_value_cents', v)}
          />
          <Input
            label="Note (optional)"
            modelValue={value.note || ''}
            onUpdateModelValue={(v) => update('note', v)}
          />
        </>
      )
      break
    case 'cancelled':
      body = (
        <Textarea
          label="Cancellation reason (required)"
          modelValue={value.reason || ''}
          onUpdateModelValue={(v) => update('reason', v)}
          rows={3}
        />
      )
      break
    default:
      body = null
  }

  return (
    <div className="space-y-4">
      {body}
      <div className="flex justify-end gap-2">
        <Button variant="ghost" onClick={onCancel} disabled={submitting}>Cancel</Button>
        <Button variant="primary" onClick={onSubmit} disabled={submitting}>
          {submitting ? 'Saving...' : 'Submit'}
        </Button>
      </div>
    </div>
  )
}

async function dispatchTransition(id, target, body) {
  const cleaned = sanitizePayload(body)
  switch (target) {
    case 'wipe_in_progress':
      return assetLifecycleService.startDecommissionWipe(id, cleaned)
    case 'wipe_complete':
      return assetLifecycleService.completeDecommissionWipe(id, cleaned)
    case 'recovery_in_progress':
      return assetLifecycleService.startDecommissionRecovery(id, cleaned)
    case 'recovery_complete':
      return assetLifecycleService.completeDecommissionRecovery(id, cleaned)
    case 'entitlement_updated':
      return assetLifecycleService.updateDecommissionEntitlements(id, cleaned)
    case 'audited':
      return assetLifecycleService.markDecommissionAudited(id, cleaned)
    case 'retired':
      return assetLifecycleService.retireDecommission(id, cleaned)
    case 'cancelled':
      return assetLifecycleService.cancelDecommission(id, cleaned)
    default:
      throw new Error(`Unknown transition: ${target}`)
  }
}

function sanitizePayload(body) {
  const out = {}
  Object.entries(body || {}).forEach(([k, v]) => {
    if (v === '' || v === null || v === undefined) return
    if (k.endsWith('_cents') || k.endsWith('_id')) {
      const n = Number(v)
      if (!Number.isNaN(n)) out[k] = n
      return
    }
    out[k] = v
  })
  return out
}
