import { useCallback, useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'

import Card from '../../components/ui/Card'
import Alert from '../../components/ui/Alert'
import Loading from '../../components/ui/Loading'
import Button from '../../components/ui/Button'
import Textarea from '../../components/ui/Textarea'
import { portalService } from '../../../services/portal/portal.service'
import { usePortalAuth } from '../../stores/portalAuth'
import { PORTAL_PERMISSION } from '../../../services/portal/permissions'

const statusBadge = (status) => {
  switch (status) {
    case 'pending': return 'bg-gray-100 text-gray-700'
    case 'in_progress': return 'bg-blue-100 text-blue-800'
    case 'on_hold': return 'bg-amber-100 text-amber-800'
    case 'completed': return 'bg-green-100 text-green-800'
    case 'ready_for_pickup': return 'bg-cyan-100 text-cyan-800'
    case 'cancelled': return 'bg-red-100 text-red-800'
    case 'goa': return 'bg-red-100 text-red-800'
    default: return 'bg-gray-100 text-gray-700'
  }
}

const formatMoney = (n) => {
  if (n == null || isNaN(Number(n))) return '$0.00'
  return `$${Number(n).toFixed(2)}`
}

const formatDate = (s) => (s ? new Date(s).toLocaleString() : '—')

function MessagesPanel({ workorderId }) {
  const { can } = usePortalAuth()
  const canReply = can(PORTAL_PERMISSION.CREATE_MESSAGES)
  const [threads, setThreads] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [activeThread, setActiveThread] = useState(null)
  const [messages, setMessages] = useState([])
  const [messagesLoading, setMessagesLoading] = useState(false)
  const [draft, setDraft] = useState('')
  const [sending, setSending] = useState(false)
  const [newSubject, setNewSubject] = useState('')
  const [newBody, setNewBody] = useState('')
  const [creatingThread, setCreatingThread] = useState(false)

  const reloadThreads = useCallback(async () => {
    setLoading(true)
    try {
      const list = await portalService.listWorkorderThreads(workorderId)
      setThreads(Array.isArray(list) ? list : [])
    } catch (err) {
      setError(err.response?.data?.message || 'Unable to load messages.')
    } finally {
      setLoading(false)
    }
  }, [workorderId])

  useEffect(() => { reloadThreads() }, [reloadThreads])

  const openThread = async (thread) => {
    setActiveThread(thread)
    setMessagesLoading(true)
    try {
      const list = await portalService.listThreadMessages(thread.id)
      setMessages(Array.isArray(list) ? list : [])
      try { await portalService.markThreadRead(thread.id) } catch { /* non-fatal */ }
    } catch (err) {
      setError(err.response?.data?.message || 'Unable to load thread.')
    } finally {
      setMessagesLoading(false)
    }
  }

  const sendReply = async () => {
    if (!draft.trim() || !activeThread) return
    setSending(true)
    try {
      const msg = await portalService.postThreadMessage(activeThread.id, draft.trim())
      setMessages((prev) => [...prev, msg])
      setDraft('')
    } catch (err) {
      setError(err.response?.data?.message || 'Unable to send message.')
    } finally {
      setSending(false)
    }
  }

  const startThread = async () => {
    if (!newBody.trim()) return
    setCreatingThread(true)
    try {
      const created = await portalService.startWorkorderThread(
        workorderId,
        newBody.trim(),
        newSubject.trim() || null,
      )
      setNewBody('')
      setNewSubject('')
      await reloadThreads()
      if (created?.id) openThread(created)
    } catch (err) {
      setError(err.response?.data?.message || 'Unable to start thread.')
    } finally {
      setCreatingThread(false)
    }
  }

  return (
    <Card>
      <h2 className="text-lg font-semibold mb-3">Messages</h2>
      {error && <Alert variant="error" closable={false}>{error}</Alert>}

      {loading ? (
        <Loading text="Loading messages…" />
      ) : (
        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div className="md:col-span-1 border rounded">
            <div className="p-3 border-b bg-gray-50">
              <h3 className="text-sm font-semibold">Threads</h3>
            </div>
            <div className="divide-y">
              {threads.length === 0 ? (
                <p className="p-3 text-sm text-gray-500">No threads yet.</p>
              ) : threads.map((t) => (
                <button
                  key={t.id}
                  type="button"
                  onClick={() => openThread(t)}
                  className={`w-full text-left p-3 hover:bg-gray-50 ${
                    activeThread?.id === t.id ? 'bg-blue-50' : ''
                  }`}
                >
                  <div className="flex items-center justify-between gap-2">
                    <span className="text-sm font-medium truncate">
                      {t.subject || 'Untitled'}
                    </span>
                    {t.unread_count > 0 && (
                      <span className="text-xs px-1.5 py-0.5 rounded-full bg-blue-600 text-white">
                        {t.unread_count}
                      </span>
                    )}
                  </div>
                  {t.last_message && (
                    <p className="text-xs text-gray-500 mt-1 truncate">{t.last_message}</p>
                  )}
                </button>
              ))}
            </div>
            {canReply ? (
              <div className="p-3 border-t bg-gray-50 space-y-2">
                <input
                  type="text"
                  placeholder="Subject (optional)"
                  value={newSubject}
                  onChange={(e) => setNewSubject(e.target.value)}
                  className="w-full text-sm border rounded px-2 py-1"
                />
                <Textarea
                  placeholder="Start a new thread…"
                  modelValue={newBody}
                  onUpdateModelValue={setNewBody}
                  rows={2}
                />
                <Button
                  size="sm"
                  onClick={startThread}
                  loading={creatingThread}
                  disabled={creatingThread || !newBody.trim()}
                >
                  Start thread
                </Button>
              </div>
            ) : (
              <div className="p-3 border-t bg-gray-50">
                <p className="text-xs text-gray-500 italic">
                  Your portal role can&rsquo;t start new threads.
                </p>
              </div>
            )}
          </div>

          <div className="md:col-span-2 border rounded flex flex-col min-h-[20rem]">
            {!activeThread ? (
              <div className="flex-1 flex items-center justify-center text-sm text-gray-500">
                Select or start a thread to see messages.
              </div>
            ) : (
              <>
                <div className="p-3 border-b bg-gray-50">
                  <h3 className="text-sm font-semibold">{activeThread.subject || 'Untitled thread'}</h3>
                </div>
                <div className="flex-1 p-3 space-y-3 overflow-y-auto max-h-96">
                  {messagesLoading ? (
                    <Loading text="Loading messages…" />
                  ) : messages.length === 0 ? (
                    <p className="text-sm text-gray-500">No messages yet.</p>
                  ) : messages.map((m) => (
                    <div key={m.id} className="text-sm">
                      <div className="text-xs text-gray-500">
                        {m.portal_account_id ? 'You / portal' : 'Staff'} · {formatDate(m.created_at)}
                      </div>
                      <p className="mt-1 whitespace-pre-wrap">{m.body}</p>
                    </div>
                  ))}
                </div>
                {canReply ? (
                  <div className="p-3 border-t bg-gray-50 space-y-2">
                    <Textarea
                      placeholder="Type a reply…"
                      modelValue={draft}
                      onUpdateModelValue={setDraft}
                      rows={2}
                    />
                    <Button
                      size="sm"
                      onClick={sendReply}
                      loading={sending}
                      disabled={sending || !draft.trim()}
                    >
                      Send
                    </Button>
                  </div>
                ) : (
                  <div className="p-3 border-t bg-gray-50">
                    <p className="text-xs text-gray-500 italic">
                      Your portal role can read this thread but can&rsquo;t reply.
                    </p>
                  </div>
                )}
              </>
            )}
          </div>
        </div>
      )}
    </Card>
  )
}

export default function PortalWorkorderDetail() {
  const { id } = useParams()
  const [wo, setWo] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)

  useEffect(() => {
    let cancelled = false
    setLoading(true)
    setError(null)
    portalService.getWorkorder(id)
      .then((data) => { if (!cancelled) setWo(data) })
      .catch((err) => { if (!cancelled) setError(err.response?.data?.message || 'Unable to load work order.') })
      .finally(() => { if (!cancelled) setLoading(false) })
    return () => { cancelled = true }
  }, [id])

  if (loading) {
    return (
      <Card>
        <div className="py-10 flex justify-center"><Loading text="Loading work order…" /></div>
      </Card>
    )
  }
  if (error) return <Alert variant="error" closable={false}>{error}</Alert>
  if (!wo) return <Alert variant="warning" closable={false}>Work order not found.</Alert>

  return (
    <div className="space-y-6">
      <div>
        <Link to="/p/work-orders" className="text-sm hover:underline" style={{ color: 'var(--portal-primary, #2563eb)' }}>
          ← Back to work orders
        </Link>
      </div>

      <Card>
        <div className="flex items-start justify-between gap-4 flex-wrap">
          <div>
            <h1 className="text-2xl font-semibold">{wo.number}</h1>
            <p className="text-sm text-gray-500 mt-1">
              {wo.type} · {wo.priority} priority
            </p>
          </div>
          <div className="flex items-center gap-2">
            <span className={`text-xs px-2 py-0.5 rounded-full ${statusBadge(wo.status)}`}>
              {wo.status}
            </span>
          </div>
        </div>

        <dl className="mt-6 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 text-sm">
          <div>
            <dt className="text-gray-500">Created</dt>
            <dd className="font-medium">{formatDate(wo.created_at)}</dd>
          </div>
          <div>
            <dt className="text-gray-500">Started</dt>
            <dd className="font-medium">{formatDate(wo.started_at)}</dd>
          </div>
          <div>
            <dt className="text-gray-500">Completed</dt>
            <dd className="font-medium">{formatDate(wo.completed_at)}</dd>
          </div>
          <div>
            <dt className="text-gray-500">ETA</dt>
            <dd className="font-medium">{wo.estimated_completion ? new Date(wo.estimated_completion).toLocaleDateString() : '—'}</dd>
          </div>
          <div>
            <dt className="text-gray-500">Subtotal</dt>
            <dd className="font-medium">{formatMoney(wo.subtotal)}</dd>
          </div>
          <div>
            <dt className="text-gray-500">Tax</dt>
            <dd className="font-medium">{formatMoney(wo.tax)}</dd>
          </div>
          <div>
            <dt className="text-gray-500">Grand total</dt>
            <dd className="font-semibold">{formatMoney(wo.grand_total)}</dd>
          </div>
        </dl>

        {wo.customer_notes && (
          <div className="mt-4">
            <h3 className="text-sm font-semibold text-gray-700 mb-1">Notes</h3>
            <p className="text-sm text-gray-600 whitespace-pre-wrap">{wo.customer_notes}</p>
          </div>
        )}
      </Card>

      {Array.isArray(wo.jobs) && wo.jobs.length > 0 && (
        <Card>
          <h2 className="text-lg font-semibold mb-3">Line items</h2>
          <div className="space-y-4">
            {wo.jobs.map(({ job, items }) => (
              <div key={job.id} className="border rounded">
                <div className="flex items-center justify-between p-3 bg-gray-50 border-b">
                  <div>
                    <h3 className="text-sm font-semibold">{job.title}</h3>
                    {job.reference && (
                      <p className="text-xs text-gray-500">Ref: {job.reference}</p>
                    )}
                  </div>
                  <span className={`text-xs px-2 py-0.5 rounded-full ${statusBadge(job.status)}`}>
                    {job.status}
                  </span>
                </div>
                {items.length > 0 ? (
                  <table className="min-w-full divide-y divide-gray-200 text-sm">
                    <thead className="bg-white text-xs uppercase tracking-wide text-gray-500">
                      <tr>
                        <th className="px-3 py-2 text-left">Description</th>
                        <th className="px-3 py-2 text-right">Qty</th>
                        <th className="px-3 py-2 text-right">Unit</th>
                        <th className="px-3 py-2 text-right">Total</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                      {items.map((item) => (
                        <tr key={item.id}>
                          <td className="px-3 py-2">
                            {item.description}
                            {item.sku && <span className="ml-2 text-xs text-gray-500">({item.sku})</span>}
                          </td>
                          <td className="px-3 py-2 text-right">{item.quantity}</td>
                          <td className="px-3 py-2 text-right">{formatMoney(item.unit_price)}</td>
                          <td className="px-3 py-2 text-right font-medium">{formatMoney(item.line_total)}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                ) : (
                  <p className="p-3 text-sm text-gray-500">No items on this job.</p>
                )}
              </div>
            ))}
          </div>
        </Card>
      )}

      {Array.isArray(wo.status_history) && wo.status_history.length > 0 && (
        <Card>
          <h2 className="text-lg font-semibold mb-3">Status history</h2>
          <ul className="space-y-2">
            {wo.status_history.map((h, idx) => (
              <li key={idx} className="text-sm flex items-start gap-3">
                <span className="text-xs text-gray-500 w-40 flex-shrink-0">
                  {formatDate(h.created_at)}
                </span>
                <span>
                  {h.from_status ? `${h.from_status} → ` : ''}
                  <strong>{h.to_status}</strong>
                  {h.notes && <span className="text-gray-600"> — {h.notes}</span>}
                </span>
              </li>
            ))}
          </ul>
        </Card>
      )}

      <MessagesPanel workorderId={wo.id} />
    </div>
  )
}
