import { useCallback, useEffect, useMemo, useState } from 'react'

import Alert from '../../components/ui/Alert'
import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Modal from '../../components/ui/Modal'
import Timeline from '../../components/Timeline'
import { assetLifecycleService } from '../../../services/asset-lifecycle.service'
import {
  LEASE_STATUS_VARIANTS,
  formatDate,
  formatDateTime,
  formatCurrencyCents,
  normalizeAuditTimeline,
  titleizeStatus,
} from './lifecycleHelpers'

/**
 * Phase 13 admin UI (#124) — leases.
 *
 * Master list (left) of leases with status filter; right panel shows a
 * detail card with key dates, alerts-sent indicators, and a write surface
 * for end-of-lease decisions and termination. Audit timeline below the
 * detail summarizes all state events from /api/audit.
 *
 * Read perm enforced server-side: asset_leases.view.
 * Write perms: asset_leases.manage.
 */

const STATUSES = [
  'active',
  'pending_renewal',
  'renewed',
  'buyout_pending',
  'bought_out',
  'returned',
  'expired',
  'terminated',
]

const DECISIONS = ['renew', 'buyout', 'return', 'replace']

export default function AssetLeases() {
  const [statusFilter, setStatusFilter] = useState('')
  const [leases, setLeases] = useState([])
  const [total, setTotal] = useState(0)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  const [selectedId, setSelectedId] = useState(null)
  const [selected, setSelected] = useState(null)
  const [detailLoading, setDetailLoading] = useState(false)
  const [timeline, setTimeline] = useState([])

  const [showDecide, setShowDecide] = useState(false)
  const [decideForm, setDecideForm] = useState({ decision: 'renew', note: '' })
  const [submitting, setSubmitting] = useState(false)

  const loadList = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      const params = { per_page: 50 }
      if (statusFilter) params.status = statusFilter
      const res = await assetLifecycleService.listLeases(params)
      setLeases(Array.isArray(res?.leases) ? res.leases : [])
      setTotal(res?.total ?? 0)
    } catch (err) {
      console.error('Failed to load leases', err)
      setError('Unable to load leases.')
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
        assetLifecycleService.getLease(id),
        assetLifecycleService.timelineFor('asset_lease', id),
      ])
      setSelected(row)
      setTimeline(normalizeAuditTimeline(audit))
    } catch (err) {
      console.error('Failed to load lease detail', err)
      setError('Unable to load lease detail.')
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
    return leases.reduce((acc, row) => {
      acc[row.status] = (acc[row.status] || 0) + 1
      return acc
    }, {})
  }, [leases])

  const submitDecision = async () => {
    if (!selected) return
    setSubmitting(true)
    try {
      await assetLifecycleService.recordLeaseDecision(selected.id, {
        decision: decideForm.decision,
        note: decideForm.note || undefined,
      })
      setShowDecide(false)
      setDecideForm({ decision: 'renew', note: '' })
      await loadList()
      await loadDetail(selected.id)
    } catch (err) {
      console.error('Failed to record decision', err)
      setError(err?.response?.data?.message || 'Unable to record decision.')
    } finally {
      setSubmitting(false)
    }
  }

  const terminate = async () => {
    if (!selected) return
    if (!window.confirm('Terminate this lease? This action cannot be undone.')) return
    setSubmitting(true)
    try {
      await assetLifecycleService.terminateLease(selected.id)
      await loadList()
      await loadDetail(selected.id)
    } catch (err) {
      console.error('Failed to terminate lease', err)
      setError(err?.response?.data?.message || 'Unable to terminate lease.')
    } finally {
      setSubmitting(false)
    }
  }

  const renderDecisionEligible = (row) => {
    if (!row) return false
    if (row.end_of_lease_decision) return false
    return row.status === 'active' || row.status === 'pending_renewal'
  }

  const renderTerminateEligible = (row) => {
    if (!row) return false
    return row.status !== 'terminated' && row.status !== 'expired'
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Asset Leases</h1>
          <p className="mt-1 text-sm text-gray-500">
            End-of-lease decisions, alert history, and termination across the installed-asset fleet.
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
          <h2 className="text-lg font-semibold text-gray-900 mb-3">Leases</h2>
          {loading ? (
            <div className="py-10 flex justify-center">
              <Loading text="Loading leases..." />
            </div>
          ) : leases.length === 0 ? (
            <div className="py-10 text-center text-gray-500">No leases match this filter.</div>
          ) : (
            <ul className="divide-y divide-gray-200">
              {leases.map((row) => (
                <li
                  key={row.id}
                  className={`py-3 cursor-pointer ${selectedId === row.id ? 'bg-blue-50 -mx-3 px-3 rounded' : 'hover:bg-gray-50 -mx-3 px-3 rounded'}`}
                  onClick={() => setSelectedId(row.id)}
                >
                  <div className="flex items-center justify-between gap-2">
                    <div className="min-w-0">
                      <div className="text-sm font-medium text-gray-900 truncate">
                        Lease #{row.id} · {row.lessor_name}
                      </div>
                      <div className="text-xs text-gray-500">
                        Asset #{row.site_asset_id} · ends {formatDate(row.end_date)}
                      </div>
                    </div>
                    <Badge variant={LEASE_STATUS_VARIANTS[row.status] || 'secondary'}>
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
              <div className="py-10 text-center text-gray-500">Select a lease to see details.</div>
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
                      Lease #{selected.id} · {selected.lessor_name}
                    </h2>
                    <p className="text-sm text-gray-500">
                      {selected.lease_number ? `Number ${selected.lease_number} · ` : ''}
                      Asset #{selected.site_asset_id}
                    </p>
                  </div>
                  <Badge variant={LEASE_STATUS_VARIANTS[selected.status] || 'secondary'}>
                    {titleizeStatus(selected.status)}
                  </Badge>
                </div>

                <dl className="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3 text-sm">
                  <div>
                    <dt className="text-gray-500">Term</dt>
                    <dd className="text-gray-900">{formatDate(selected.start_date)} → {formatDate(selected.end_date)}</dd>
                  </div>
                  <div>
                    <dt className="text-gray-500">Monthly payment</dt>
                    <dd className="text-gray-900">{formatCurrencyCents(selected.monthly_payment_cents)}</dd>
                  </div>
                  <div>
                    <dt className="text-gray-500">Buyout price</dt>
                    <dd className="text-gray-900">{formatCurrencyCents(selected.buyout_price_cents)}</dd>
                  </div>
                  <div>
                    <dt className="text-gray-500">Residual</dt>
                    <dd className="text-gray-900">{formatCurrencyCents(selected.residual_value_cents)}</dd>
                  </div>
                  <div>
                    <dt className="text-gray-500">Decision</dt>
                    <dd className="text-gray-900">
                      {selected.end_of_lease_decision
                        ? `${titleizeStatus(selected.end_of_lease_decision)} on ${formatDateTime(selected.decision_made_at)}`
                        : '—'}
                    </dd>
                  </div>
                  <div>
                    <dt className="text-gray-500">Customer</dt>
                    <dd className="text-gray-900">{selected.customer_id ? `#${selected.customer_id}` : '—'}</dd>
                  </div>
                </dl>

                <div className="mt-4 border-t border-gray-200 pt-3">
                  <div className="text-xs font-medium text-gray-500 uppercase">Renewal alerts</div>
                  <div className="mt-2 flex flex-wrap gap-3 text-xs">
                    {['90d', '60d', '30d', '0d'].map((window) => {
                      const sent = selected.alerts_sent?.[window]
                      return (
                        <span
                          key={window}
                          className={sent ? 'text-emerald-700' : 'text-gray-400'}
                        >
                          {sent ? `✓ ${window}` : `· ${window}`}
                          {sent ? <span className="ml-1 text-gray-500">{formatDateTime(sent)}</span> : null}
                        </span>
                      )
                    })}
                  </div>
                </div>

                <div className="mt-5 flex flex-wrap gap-2">
                  <Button
                    variant="primary"
                    onClick={() => setShowDecide(true)}
                    disabled={!renderDecisionEligible(selected) || submitting}
                  >
                    Record decision
                  </Button>
                  <Button
                    variant="outline"
                    onClick={terminate}
                    disabled={!renderTerminateEligible(selected) || submitting}
                  >
                    Terminate
                  </Button>
                </div>
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
        open={showDecide}
        title={selected ? `Record decision for lease #${selected.id}` : 'Record decision'}
        onClose={() => setShowDecide(false)}
      >
        <div className="space-y-4">
          <label className="block text-sm font-medium text-gray-700">
            Decision
            <select
              value={decideForm.decision}
              onChange={(e) => setDecideForm((p) => ({ ...p, decision: e.target.value }))}
              className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500"
            >
              {DECISIONS.map((d) => (
                <option key={d} value={d}>{titleizeStatus(d)}</option>
              ))}
            </select>
          </label>
          <Input
            label="Note (optional)"
            modelValue={decideForm.note}
            onUpdateModelValue={(v) => setDecideForm((p) => ({ ...p, note: v }))}
            placeholder="Add context for the audit log"
          />
          <div className="flex justify-end gap-2">
            <Button variant="ghost" onClick={() => setShowDecide(false)} disabled={submitting}>Cancel</Button>
            <Button variant="primary" onClick={submitDecision} disabled={submitting}>
              {submitting ? 'Saving...' : 'Save'}
            </Button>
          </div>
        </div>
      </Modal>
    </div>
  )
}
