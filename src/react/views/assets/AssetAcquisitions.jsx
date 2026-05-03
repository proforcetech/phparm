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
  ACQUISITION_STATUS_VARIANTS,
  formatDate,
  formatDateTime,
  formatCurrencyCents,
  normalizeAuditTimeline,
  titleizeStatus,
} from './lifecycleHelpers'

/**
 * Phase 13 admin UI (#124) — acquisitions.
 *
 * The acquisition state machine is wide (10 statuses, 8 transition verbs)
 * so the right-hand action panel is driven by `allowed_transitions` from
 * the API rather than a hardcoded matrix in the UI. Transitions that
 * require a body (PO issue, install scheduling, install completion,
 * cancel, reject) open a modal; one-click transitions (approve, receive,
 * activate) post directly with a confirmation.
 */

const STATUSES = [
  'draft',
  'quoted',
  'approved',
  'rejected',
  'po_issued',
  'received',
  'install_scheduled',
  'installed',
  'active',
  'cancelled',
]

// Map from each canonical transition target to a verb the UI shows.
// Driven from AssetAcquisition::TRANSITIONS in src/Models/AssetAcquisition.php.
const TRANSITION_LABEL = {
  quoted: 'Mark quoted',
  approved: 'Approve',
  rejected: 'Reject',
  po_issued: 'Issue PO',
  received: 'Mark received',
  install_scheduled: 'Schedule install',
  installed: 'Mark installed',
  active: 'Activate (link asset)',
  cancelled: 'Cancel',
}

// Which transitions need a modal because they take required body fields.
const MODAL_TRANSITIONS = new Set([
  'rejected',
  'po_issued',
  'install_scheduled',
  'installed',
  'active',
  'cancelled',
])

export default function AssetAcquisitions() {
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
      const res = await assetLifecycleService.listAcquisitions(params)
      setList(Array.isArray(res?.acquisitions) ? res.acquisitions : [])
      setTotal(res?.total ?? 0)
    } catch (err) {
      console.error('Failed to load acquisitions', err)
      setError('Unable to load acquisitions.')
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
        assetLifecycleService.getAcquisition(id),
        assetLifecycleService.timelineFor('asset_acquisition', id),
      ])
      setSelected(row)
      setTimeline(normalizeAuditTimeline(audit))
    } catch (err) {
      console.error('Failed to load acquisition detail', err)
      setError('Unable to load acquisition detail.')
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
    if (!window.confirm(`${TRANSITION_LABEL[target] || target} acquisition #${selected.id}?`)) return
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
    return allowed.map((target) => (
      <Button
        key={target}
        variant={target === 'cancelled' || target === 'rejected' ? 'outline' : 'primary'}
        disabled={submitting}
        onClick={() => (MODAL_TRANSITIONS.has(target) ? openModal(target) : oneClickTransition(target))}
      >
        {TRANSITION_LABEL[target] || titleizeStatus(target)}
      </Button>
    ))
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Asset Acquisitions</h1>
          <p className="mt-1 text-sm text-gray-500">
            New-equipment workflow from quote → approval → PO → install → activation.
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
          <h2 className="text-lg font-semibold text-gray-900 mb-3">Acquisitions</h2>
          {loading ? (
            <div className="py-10 flex justify-center">
              <Loading text="Loading acquisitions..." />
            </div>
          ) : list.length === 0 ? (
            <div className="py-10 text-center text-gray-500">No acquisitions match this filter.</div>
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
                        #{row.id} · {row.title || '(untitled)'}
                      </div>
                      <div className="text-xs text-gray-500">
                        Customer #{row.customer_id || '—'} · target {formatDate(row.target_install_date)}
                      </div>
                    </div>
                    <Badge variant={ACQUISITION_STATUS_VARIANTS[row.status] || 'secondary'}>
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
              <div className="py-10 text-center text-gray-500">Select an acquisition to see details.</div>
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
                      Acquisition #{selected.id} · {selected.title || '(untitled)'}
                    </h2>
                    <p className="text-sm text-gray-500">{selected.description || '—'}</p>
                  </div>
                  <Badge variant={ACQUISITION_STATUS_VARIANTS[selected.status] || 'secondary'}>
                    {titleizeStatus(selected.status)}
                  </Badge>
                </div>

                <dl className="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3 text-sm">
                  <div>
                    <dt className="text-gray-500">Customer / Site</dt>
                    <dd className="text-gray-900">
                      {selected.customer_id ? `Customer #${selected.customer_id}` : '—'}
                      {selected.site_id ? ` · Site #${selected.site_id}` : ''}
                    </dd>
                  </div>
                  <div>
                    <dt className="text-gray-500">Quantity</dt>
                    <dd className="text-gray-900">{selected.quantity ?? '—'}</dd>
                  </div>
                  <div>
                    <dt className="text-gray-500">Target install</dt>
                    <dd className="text-gray-900">{formatDate(selected.target_install_date)}</dd>
                  </div>
                  <div>
                    <dt className="text-gray-500">Estimate</dt>
                    <dd className="text-gray-900">{selected.estimate_id ? `#${selected.estimate_id}` : '—'}</dd>
                  </div>
                  <div>
                    <dt className="text-gray-500">Vendor / PO</dt>
                    <dd className="text-gray-900">
                      {selected.vendor_name || '—'}
                      {selected.vendor_po_number ? ` · PO ${selected.vendor_po_number}` : ''}
                      {selected.vendor_po_total_cents
                        ? ` · ${formatCurrencyCents(selected.vendor_po_total_cents)}`
                        : ''}
                    </dd>
                  </div>
                  <div>
                    <dt className="text-gray-500">Customer approval</dt>
                    <dd className="text-gray-900">
                      {selected.customer_approved_at
                        ? `Approved ${formatDateTime(selected.customer_approved_at)}`
                        : selected.customer_rejected_at
                          ? `Rejected ${formatDateTime(selected.customer_rejected_at)}: ${selected.customer_rejection_reason || ''}`
                          : '—'}
                    </dd>
                  </div>
                  <div>
                    <dt className="text-gray-500">Install scheduled</dt>
                    <dd className="text-gray-900">{formatDateTime(selected.install_scheduled_at)}</dd>
                  </div>
                  <div>
                    <dt className="text-gray-500">Installed / Activated</dt>
                    <dd className="text-gray-900">
                      {selected.installed_at ? `Installed ${formatDateTime(selected.installed_at)}` : '—'}
                      {selected.activated_at ? ` · Activated ${formatDateTime(selected.activated_at)}` : ''}
                      {selected.target_site_asset_id ? ` (asset #${selected.target_site_asset_id})` : ''}
                    </dd>
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
            ? `${TRANSITION_LABEL[modalTarget] || modalTarget} — acquisition #${selected.id}`
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
    case 'rejected':
      return { reason: '' }
    case 'po_issued':
      return {
        vendor_name: '',
        vendor_po_number: '',
        vendor_po_total_cents: '',
        note: '',
      }
    case 'install_scheduled':
      return { install_scheduled_at: '', install_workorder_id: '', note: '' }
    case 'installed':
      return { note: '' }
    case 'active':
      return { target_site_asset_id: '', note: '' }
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
    case 'rejected':
      body = (
        <Textarea
          label="Rejection reason (required)"
          modelValue={value.reason || ''}
          onUpdateModelValue={(v) => update('reason', v)}
          rows={3}
        />
      )
      break
    case 'po_issued':
      body = (
        <>
          <Input
            label="Vendor name (required)"
            modelValue={value.vendor_name || ''}
            onUpdateModelValue={(v) => update('vendor_name', v)}
          />
          <Input
            label="PO number (required)"
            modelValue={value.vendor_po_number || ''}
            onUpdateModelValue={(v) => update('vendor_po_number', v)}
          />
          <Input
            label="PO total (cents)"
            type="number"
            modelValue={value.vendor_po_total_cents || ''}
            onUpdateModelValue={(v) => update('vendor_po_total_cents', v)}
          />
          <Input
            label="Note (optional)"
            modelValue={value.note || ''}
            onUpdateModelValue={(v) => update('note', v)}
          />
        </>
      )
      break
    case 'install_scheduled':
      body = (
        <>
          <Input
            label="Scheduled at (YYYY-MM-DD HH:MM:SS)"
            modelValue={value.install_scheduled_at || ''}
            onUpdateModelValue={(v) => update('install_scheduled_at', v)}
            placeholder="2026-05-10 09:00:00"
          />
          <Input
            label="Install workorder ID (optional)"
            type="number"
            modelValue={value.install_workorder_id || ''}
            onUpdateModelValue={(v) => update('install_workorder_id', v)}
          />
          <Input
            label="Note (optional)"
            modelValue={value.note || ''}
            onUpdateModelValue={(v) => update('note', v)}
          />
        </>
      )
      break
    case 'installed':
      body = (
        <Input
          label="Note (optional)"
          modelValue={value.note || ''}
          onUpdateModelValue={(v) => update('note', v)}
        />
      )
      break
    case 'active':
      body = (
        <>
          <Input
            label="Target site_asset_id (links the new active asset)"
            type="number"
            modelValue={value.target_site_asset_id || ''}
            onUpdateModelValue={(v) => update('target_site_asset_id', v)}
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
    case 'quoted':
      return assetLifecycleService.attachAcquisitionQuote(id, cleaned)
    case 'approved':
      return assetLifecycleService.approveAcquisition(id, cleaned)
    case 'rejected':
      return assetLifecycleService.rejectAcquisition(id, cleaned)
    case 'po_issued':
      return assetLifecycleService.issueAcquisitionPo(id, cleaned)
    case 'received':
      return assetLifecycleService.receiveAcquisition(id, cleaned)
    case 'install_scheduled':
      return assetLifecycleService.scheduleAcquisitionInstall(id, cleaned)
    case 'installed':
      return assetLifecycleService.installAcquisition(id, cleaned)
    case 'active':
      return assetLifecycleService.activateAcquisition(id, cleaned)
    case 'cancelled':
      return assetLifecycleService.cancelAcquisition(id, cleaned)
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
