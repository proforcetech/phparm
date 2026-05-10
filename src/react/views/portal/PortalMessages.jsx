import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'

import Card from '../../components/ui/Card'
import Alert from '../../components/ui/Alert'
import Loading from '../../components/ui/Loading'
import Button from '../../components/ui/Button'
import Textarea from '../../components/ui/Textarea'
import { portalService } from '../../../services/portal/portal.service'
import { usePortalAuth } from '../../stores/portalAuth'
import { PORTAL_PERMISSION } from '../../../services/portal/permissions'

const formatDate = (s) => (s ? new Date(s).toLocaleString() : '')

const entityLink = (thread) => {
  if (thread.workorder_id) return `/p/work-orders/${thread.workorder_id}`
  // Tickets don't have a dedicated portal view yet — link to dashboard.
  return '/p'
}

const entityLabel = (thread) => {
  if (thread.workorder_id) return `Work order #${thread.workorder_id}`
  if (thread.ticket_id) return `Ticket #${thread.ticket_id}`
  return 'Message thread'
}

export default function PortalMessages() {
  const { can } = usePortalAuth()
  const canReply = can(PORTAL_PERMISSION.CREATE_MESSAGES)
  const [unreadOnly, setUnreadOnly] = useState(false)
  const [list, setList] = useState({ data: [], total: 0 })
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)

  const [activeThread, setActiveThread] = useState(null)
  const [messages, setMessages] = useState([])
  const [messagesLoading, setMessagesLoading] = useState(false)
  const [draft, setDraft] = useState('')
  const [sending, setSending] = useState(false)

  const reload = useCallback(async () => {
    setLoading(true)
    setError(null)
    try {
      const params = unreadOnly ? { unread_only: 1 } : {}
      const result = await portalService.listMessageInbox(params)
      setList(result || { data: [], total: 0 })
    } catch (err) {
      setError(err.response?.data?.message || 'Unable to load inbox.')
    } finally {
      setLoading(false)
    }
  }, [unreadOnly])

  useEffect(() => { reload() }, [reload])

  const openThread = async (thread) => {
    setActiveThread(thread)
    setMessagesLoading(true)
    setMessages([])
    try {
      const data = await portalService.listThreadMessages(thread.id)
      setMessages(Array.isArray(data) ? data : [])
      try { await portalService.markThreadRead(thread.id) } catch { /* non-fatal */ }
      // Optimistically clear unread on the list row
      setList((prev) => ({
        ...prev,
        data: prev.data.map((t) => t.id === thread.id ? { ...t, unread_count: 0 } : t),
      }))
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

  return (
    <div className="space-y-6">
      <header className="flex items-end justify-between gap-4 flex-wrap">
        <div>
          <h1 className="text-2xl font-semibold">Messages</h1>
          <p className="text-sm text-gray-600 mt-1">
            All conversations on your tickets and work orders.
          </p>
        </div>
        <label className="inline-flex items-center gap-2 text-sm text-gray-700">
          <input
            type="checkbox"
            className="rounded"
            checked={unreadOnly}
            onChange={(e) => setUnreadOnly(e.target.checked)}
          />
          Unread only
        </label>
      </header>

      {error && <Alert variant="error" closable={false}>{error}</Alert>}

      <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
        <Card className="md:col-span-1" padding={false}>
          <div className="p-3 border-b bg-gray-50">
            <h2 className="text-sm font-semibold">
              Threads {list.total > 0 && <span className="text-gray-500">({list.total})</span>}
            </h2>
          </div>
          {loading ? (
            <div className="p-6 flex justify-center"><Loading text="Loading…" /></div>
          ) : list.data.length === 0 ? (
            <p className="p-3 text-sm text-gray-500">
              {unreadOnly ? 'No unread threads.' : 'No threads yet.'}
            </p>
          ) : (
            <ul className="divide-y max-h-[32rem] overflow-y-auto">
              {list.data.map((t) => (
                <li key={t.id}>
                  <button
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
                    <div className="text-xs text-gray-500 mt-1">{entityLabel(t)}</div>
                    {t.last_message && (
                      <p className="text-xs text-gray-600 mt-1 truncate">{t.last_message}</p>
                    )}
                    <div className="text-xs text-gray-400 mt-1">{formatDate(t.last_message_at || t.created_at)}</div>
                  </button>
                </li>
              ))}
            </ul>
          )}
        </Card>

        <Card className="md:col-span-2 flex flex-col min-h-[24rem]" padding={false}>
          {!activeThread ? (
            <div className="flex-1 flex items-center justify-center text-sm text-gray-500 p-6">
              Select a thread to read messages.
            </div>
          ) : (
            <>
              <div className="p-3 border-b bg-gray-50 flex items-center justify-between gap-3">
                <div>
                  <h3 className="text-sm font-semibold">{activeThread.subject || 'Untitled thread'}</h3>
                  <Link
                    to={entityLink(activeThread)}
                    className="text-xs hover:underline"
                    style={{ color: 'var(--portal-primary, #2563eb)' }}
                  >
                    Open {entityLabel(activeThread)}
                  </Link>
                </div>
              </div>
              <div className="flex-1 p-3 space-y-3 overflow-y-auto max-h-96">
                {messagesLoading ? (
                  <Loading text="Loading messages…" />
                ) : messages.length === 0 ? (
                  <p className="text-sm text-gray-500">No messages in this thread.</p>
                ) : messages.map((m) => (
                  <div key={m.id} className="text-sm">
                    <div className="text-xs text-gray-500">
                      {m.portal_account_id ? 'You / portal' : 'Staff'} · {formatDate(m.created_at)}
                    </div>
                    <p className="mt-1 whitespace-pre-wrap">{m.body}</p>
                  </div>
                ))}
              </div>
              <div className="p-3 border-t bg-gray-50 space-y-2">
                {canReply ? (
                  <>
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
                  </>
                ) : (
                  <p className="text-xs text-gray-500 italic">
                    Your portal role can read messages but can&rsquo;t reply.
                  </p>
                )}
              </div>
            </>
          )}
        </Card>
      </div>
    </div>
  )
}
