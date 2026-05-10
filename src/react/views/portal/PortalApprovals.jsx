import { useCallback, useEffect, useState } from 'react'

import Card from '../../components/ui/Card'
import Button from '../../components/ui/Button'
import Modal from '../../components/ui/Modal'
import Textarea from '../../components/ui/Textarea'
import Alert from '../../components/ui/Alert'
import Loading from '../../components/ui/Loading'
import { portalService } from '../../../services/portal/portal.service'

const formatMoney = (cents, currency = 'USD') => {
  if (cents == null) return '—'
  const amount = Number(cents) / 100
  return new Intl.NumberFormat('en-US', { style: 'currency', currency }).format(amount)
}

const formatTotal = (value) => {
  if (value == null) return '—'
  const amount = Number(value)
  if (Number.isNaN(amount)) return '—'
  return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(amount)
}

const formatDate = (value) => {
  if (!value) return '—'
  const d = new Date(value)
  if (Number.isNaN(d.getTime())) return value
  return d.toLocaleDateString()
}

export default function PortalApprovals() {
  const [data, setData] = useState({ estimates: [], contracts: [] })
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [busyKey, setBusyKey] = useState(null)
  const [actionError, setActionError] = useState(null)
  const [rejectTarget, setRejectTarget] = useState(null) // { kind, id, label }
  const [rejectReason, setRejectReason] = useState('')

  const load = useCallback(async () => {
    setLoading(true)
    setError(null)
    try {
      const result = await portalService.listApprovals()
      setData({
        estimates: Array.isArray(result?.estimates) ? result.estimates : [],
        contracts: Array.isArray(result?.contracts) ? result.contracts : [],
      })
    } catch (err) {
      setError(err.response?.data?.message || 'Unable to load approvals.')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => { load() }, [load])

  const approve = async (kind, id) => {
    setBusyKey(`${kind}:${id}`)
    setActionError(null)
    try {
      if (kind === 'estimate') {
        await portalService.approveEstimate(id)
      } else {
        await portalService.approveContract(id)
      }
      await load()
    } catch (err) {
      setActionError(err.response?.data?.message || `Unable to approve ${kind}.`)
    } finally {
      setBusyKey(null)
    }
  }

  const openReject = (kind, id, label) => {
    setRejectTarget({ kind, id, label })
    setRejectReason('')
    setActionError(null)
  }

  const submitReject = async () => {
    if (!rejectTarget) return
    const reason = rejectReason.trim()
    if (reason === '') {
      setActionError('A reason is required to reject.')
      return
    }
    setBusyKey(`${rejectTarget.kind}:${rejectTarget.id}`)
    setActionError(null)
    try {
      if (rejectTarget.kind === 'estimate') {
        await portalService.rejectEstimate(rejectTarget.id, reason)
      } else {
        await portalService.rejectContract(rejectTarget.id, reason)
      }
      setRejectTarget(null)
      setRejectReason('')
      await load()
    } catch (err) {
      setActionError(err.response?.data?.message || `Unable to reject ${rejectTarget.kind}.`)
    } finally {
      setBusyKey(null)
    }
  }

  if (loading) {
    return (
      <Card>
        <div className="py-10 flex justify-center">
          <Loading text="Loading approvals…" />
        </div>
      </Card>
    )
  }

  return (
    <div className="space-y-6">
      <header>
        <h1 className="text-2xl font-semibold">Approvals</h1>
        <p className="text-sm text-gray-600 mt-1">
          Estimates and contracts awaiting your decision.
        </p>
      </header>

      {error && <Alert variant="error" closable={false}>{error}</Alert>}
      {actionError && <Alert variant="error" closable={false}>{actionError}</Alert>}

      <section>
        <h2 className="text-sm font-semibold uppercase tracking-wide text-gray-500 mb-3">
          Estimates ({data.estimates.length})
        </h2>
        {data.estimates.length === 0 ? (
          <Card>
            <p className="text-sm text-gray-600">No estimates awaiting approval.</p>
          </Card>
        ) : (
          <div className="space-y-3">
            {data.estimates.map((e) => {
              const key = `estimate:${e.id}`
              const isBusy = busyKey === key
              return (
                <Card key={e.id}>
                  <div className="flex flex-col md:flex-row md:items-start md:justify-between gap-4">
                    <div className="flex-1 min-w-0">
                      <div className="flex items-center gap-2">
                        <h3 className="text-base font-semibold">Estimate #{e.number || e.id}</h3>
                        <span className="text-xs px-2 py-0.5 rounded-full bg-yellow-100 text-yellow-800 capitalize">
                          {e.status}
                        </span>
                      </div>
                      <dl className="mt-2 grid grid-cols-2 gap-x-6 gap-y-1 text-sm">
                        <div>
                          <dt className="text-gray-500">Total</dt>
                          <dd className="text-gray-900 font-medium">{formatTotal(e.grand_total)}</dd>
                        </div>
                        <div>
                          <dt className="text-gray-500">Expires</dt>
                          <dd className="text-gray-900">{formatDate(e.expiration_date)}</dd>
                        </div>
                      </dl>
                      {e.customer_notes && (
                        <p className="mt-2 text-sm text-gray-600 whitespace-pre-wrap">
                          {e.customer_notes}
                        </p>
                      )}
                    </div>
                    <div className="flex md:flex-col gap-2 md:w-44">
                      <Button
                        fullWidth
                        loading={isBusy}
                        disabled={!!busyKey}
                        onClick={() => approve('estimate', e.id)}
                      >
                        Approve
                      </Button>
                      <Button
                        variant="outline"
                        fullWidth
                        disabled={!!busyKey}
                        onClick={() => openReject('estimate', e.id, `Estimate #${e.number || e.id}`)}
                      >
                        Reject
                      </Button>
                    </div>
                  </div>
                </Card>
              )
            })}
          </div>
        )}
      </section>

      <section>
        <h2 className="text-sm font-semibold uppercase tracking-wide text-gray-500 mb-3">
          Contracts ({data.contracts.length})
        </h2>
        {data.contracts.length === 0 ? (
          <Card>
            <p className="text-sm text-gray-600">No contracts awaiting approval.</p>
          </Card>
        ) : (
          <div className="space-y-3">
            {data.contracts.map((c) => {
              const key = `contract:${c.id}`
              const isBusy = busyKey === key
              return (
                <Card key={c.id}>
                  <div className="flex flex-col md:flex-row md:items-start md:justify-between gap-4">
                    <div className="flex-1 min-w-0">
                      <div className="flex items-center gap-2 flex-wrap">
                        <h3 className="text-base font-semibold">{c.title || `Contract ${c.contract_number}`}</h3>
                        <span className="text-xs px-2 py-0.5 rounded-full bg-yellow-100 text-yellow-800 capitalize">
                          {c.status?.replace(/_/g, ' ')}
                        </span>
                        {c.kind === 'renewal' && (
                          <span className="text-xs px-2 py-0.5 rounded-full bg-blue-100 text-blue-800">
                            Renewal
                          </span>
                        )}
                      </div>
                      <dl className="mt-2 grid grid-cols-2 gap-x-6 gap-y-1 text-sm">
                        <div>
                          <dt className="text-gray-500">Billing</dt>
                          <dd className="text-gray-900 font-medium">
                            {formatMoney(c.billing_amount_cents)}{' '}
                            <span className="text-gray-500 capitalize">
                              ({c.billing_frequency?.replace(/_/g, ' ') || '—'})
                            </span>
                          </dd>
                        </div>
                        <div>
                          <dt className="text-gray-500">Term</dt>
                          <dd className="text-gray-900">
                            {formatDate(c.start_date)} → {formatDate(c.end_date)}
                          </dd>
                        </div>
                      </dl>
                      {c.description && (
                        <p className="mt-2 text-sm text-gray-600 whitespace-pre-wrap line-clamp-3">
                          {c.description}
                        </p>
                      )}
                    </div>
                    <div className="flex md:flex-col gap-2 md:w-44">
                      <Button
                        fullWidth
                        loading={isBusy}
                        disabled={!!busyKey}
                        onClick={() => approve('contract', c.id)}
                      >
                        Approve
                      </Button>
                      <Button
                        variant="outline"
                        fullWidth
                        disabled={!!busyKey}
                        onClick={() => openReject('contract', c.id, c.title || `Contract ${c.contract_number}`)}
                      >
                        Reject
                      </Button>
                    </div>
                  </div>
                </Card>
              )
            })}
          </div>
        )}
      </section>

      <Modal
        open={rejectTarget !== null}
        title={rejectTarget ? `Reject ${rejectTarget.label}` : 'Reject'}
        onClose={() => { setRejectTarget(null); setRejectReason(''); setActionError(null) }}
        footer={(
          <div className="flex justify-end gap-2">
            <Button
              variant="ghost"
              onClick={() => { setRejectTarget(null); setRejectReason(''); setActionError(null) }}
              disabled={!!busyKey}
            >
              Cancel
            </Button>
            <Button
              variant="danger"
              onClick={submitReject}
              loading={!!busyKey}
              disabled={!!busyKey || rejectReason.trim() === ''}
            >
              Reject
            </Button>
          </div>
        )}
      >
        <p className="text-sm text-gray-600 mb-3">
          Tell us why so the team can revise.
        </p>
        <Textarea
          label="Reason"
          required
          rows={4}
          value={rejectReason}
          onChange={(e) => setRejectReason(e.target.value)}
          placeholder="Explain what needs to change…"
        />
      </Modal>
    </div>
  )
}
