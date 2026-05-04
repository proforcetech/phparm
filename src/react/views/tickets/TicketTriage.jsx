import { useCallback, useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'

import Alert from '../../components/ui/Alert'
import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Loading from '../../components/ui/Loading'
import Modal from '../../components/ui/Modal'
import Textarea from '../../components/ui/Textarea'
import ticketTriageService from '../../../services/ticket-triage.service'
import { useToast } from '../../stores/toast.jsx'

const formatDate = (value) => {
  if (!value) return '—'
  try {
    const d = new Date(value)
    if (Number.isNaN(d.getTime())) return value
    return d.toLocaleString()
  } catch {
    return value
  }
}

const statusVariant = (status) => {
  switch ((status || '').toLowerCase()) {
    case 'pending': return 'warning'
    case 'accepted': return 'success'
    case 'rejected': return 'default'
    case 'expired': return 'default'
    default: return 'info'
  }
}

const summarizeRecommendation = (s) => {
  const parts = []
  if (s.recommended_category_name || s.recommended_category_id) {
    parts.push(`Category: ${s.recommended_category_name || `#${s.recommended_category_id}`}`)
  }
  if (s.recommended_priority) parts.push(`Priority: ${s.recommended_priority}`)
  if (s.recommended_queue_name || s.recommended_queue_id) {
    parts.push(`Queue: ${s.recommended_queue_name || `#${s.recommended_queue_id}`}`)
  }
  if (s.recommended_assignee_name || s.recommended_assignee_id) {
    parts.push(`Assignee: ${s.recommended_assignee_name || `#${s.recommended_assignee_id}`}`)
  }
  if (s.action) parts.push(s.action)
  return parts.length ? parts.join(' · ') : (s.recommendation || s.summary || 'See suggestion details')
}

export default function TicketTriage() {
  const { success, error } = useToast()
  const [searchParams] = useSearchParams()
  const ticketFilter = searchParams.get('ticket')

  const [suggestions, setSuggestions] = useState([])
  const [loading, setLoading] = useState(true)
  const [apiError, setApiError] = useState('')
  const [busyId, setBusyId] = useState(null)

  const [rejectModal, setRejectModal] = useState({ open: false, suggestion: null })
  const [rejectReason, setRejectReason] = useState('')

  const load = useCallback(async () => {
    setLoading(true)
    setApiError('')
    try {
      const params = { status: 'pending' }
      if (ticketFilter) params.ticket_id = ticketFilter
      const res = await ticketTriageService.list(params)
      const list = Array.isArray(res) ? res : res?.data ?? []
      setSuggestions(list)
    } catch (e) {
      setApiError(e?.response?.data?.message || e?.message || 'Failed to load suggestions')
      setSuggestions([])
    } finally {
      setLoading(false)
    }
  }, [ticketFilter])

  useEffect(() => { load() }, [load])

  const accept = async (suggestion) => {
    setBusyId(suggestion.id)
    try {
      await ticketTriageService.accept(suggestion.id)
      success('Suggestion accepted')
      load()
    } catch (e) {
      error(e?.response?.data?.message || 'Failed to accept suggestion')
    } finally {
      setBusyId(null)
    }
  }

  const openReject = (suggestion) => {
    setRejectReason('')
    setRejectModal({ open: true, suggestion })
  }

  const submitReject = async () => {
    const s = rejectModal.suggestion
    if (!s) return
    setBusyId(s.id)
    try {
      await ticketTriageService.reject(s.id, rejectReason ? { reason: rejectReason } : {})
      success('Suggestion rejected')
      setRejectModal({ open: false, suggestion: null })
      load()
    } catch (e) {
      error(e?.response?.data?.message || 'Failed to reject suggestion')
    } finally {
      setBusyId(null)
    }
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Ticket Triage</h1>
          <p className="mt-1 text-sm text-gray-500">
            Review pending triage suggestions and accept or reject each recommendation.
            {ticketFilter && (
              <> Filtering for ticket <Link to={`/cp/tickets/${ticketFilter}`} className="text-primary-600 hover:text-primary-500">#{ticketFilter}</Link>.</>
            )}
          </p>
        </div>
        <Button variant="secondary" onClick={load}>Refresh</Button>
      </div>

      {apiError && <Alert variant="danger" onClose={() => setApiError('')}>{apiError}</Alert>}

      <Card padding={false}>
        {loading ? (
          <div className="p-10 flex justify-center"><Loading text="Loading suggestions..." /></div>
        ) : suggestions.length === 0 ? (
          <div className="p-10 text-center">
            <h3 className="text-sm font-medium text-gray-900">No pending suggestions</h3>
            <p className="mt-1 text-sm text-gray-500">All caught up. New suggestions will show up here as tickets come in.</p>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Ticket</th>
                  <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Recommendation</th>
                  <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Confidence</th>
                  <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                  <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Created</th>
                  <th className="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-200 bg-white">
                {suggestions.map((s) => {
                  const ticketId = s.ticket_id ?? s.ticket?.id
                  const isBusy = busyId === s.id
                  return (
                    <tr key={s.id}>
                      <td className="px-3 py-2 text-sm">
                        {ticketId ? (
                          <Link to={`/cp/tickets/${ticketId}`} className="text-primary-600 hover:text-primary-500 font-medium">
                            #{ticketId}
                          </Link>
                        ) : <span className="text-gray-400">—</span>}
                        {(s.ticket_subject || s.ticket?.subject) && (
                          <div className="text-xs text-gray-500 truncate max-w-xs">{s.ticket_subject || s.ticket?.subject}</div>
                        )}
                      </td>
                      <td className="px-3 py-2 text-sm text-gray-700">
                        <div>{summarizeRecommendation(s)}</div>
                        {s.reason && <div className="text-xs text-gray-500 mt-1">{s.reason}</div>}
                      </td>
                      <td className="px-3 py-2 text-sm text-gray-700">
                        {s.confidence != null ? `${Math.round(Number(s.confidence) * (Number(s.confidence) <= 1 ? 100 : 1))}%` : '—'}
                      </td>
                      <td className="px-3 py-2"><Badge size="sm" variant={statusVariant(s.status)}>{s.status || 'pending'}</Badge></td>
                      <td className="px-3 py-2 text-sm text-gray-500">{formatDate(s.created_at)}</td>
                      <td className="px-3 py-2 text-right">
                        <div className="flex justify-end gap-2">
                          <Button size="sm" disabled={isBusy} onClick={() => accept(s)}>Accept</Button>
                          <Button size="sm" variant="danger" disabled={isBusy} onClick={() => openReject(s)}>Reject</Button>
                        </div>
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      <Modal
        open={rejectModal.open}
        onClose={() => setRejectModal({ open: false, suggestion: null })}
        title="Reject suggestion"
      >
        <div className="space-y-3">
          <Textarea
            label="Reason (optional)"
            rows={3}
            value={rejectReason}
            onChange={(e) => setRejectReason(e.target.value)}
            placeholder="Why is this suggestion being rejected?"
          />
          <div className="flex justify-end gap-2">
            <Button variant="ghost" onClick={() => setRejectModal({ open: false, suggestion: null })}>Cancel</Button>
            <Button variant="danger" onClick={submitReject}>Reject</Button>
          </div>
        </div>
      </Modal>
    </div>
  )
}
