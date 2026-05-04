import { useCallback, useEffect, useMemo, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'

import Alert from '../../components/ui/Alert'
import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Modal from '../../components/ui/Modal'
import Select from '../../components/ui/Select'
import Textarea from '../../components/ui/Textarea'
import ticketsService from '../../../services/tickets.service'
import { useToast } from '../../stores/toast.jsx'

const STATUS_OPTIONS = [
  { value: 'open', label: 'Open' },
  { value: 'in_progress', label: 'In progress' },
  { value: 'pending', label: 'Pending' },
  { value: 'resolved', label: 'Resolved' },
  { value: 'closed', label: 'Closed' },
]

const PRIORITY_OPTIONS = [
  { value: 'low', label: 'Low' },
  { value: 'normal', label: 'Normal' },
  { value: 'high', label: 'High' },
  { value: 'urgent', label: 'Urgent' },
]

const TABS = [
  { id: 'overview', label: 'Overview' },
  { id: 'sla', label: 'SLA' },
  { id: 'comments', label: 'Comments' },
  { id: 'workorders', label: 'Linked Workorders' },
  { id: 'triage', label: 'Triage' },
]

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
    case 'open': return 'info'
    case 'in_progress': return 'primary'
    case 'pending': return 'warning'
    case 'resolved': return 'success'
    case 'closed': return 'default'
    default: return 'default'
  }
}

const priorityVariant = (priority) => {
  switch ((priority || '').toLowerCase()) {
    case 'urgent': return 'danger'
    case 'high': return 'warning'
    case 'normal': return 'info'
    case 'low': return 'default'
    default: return 'default'
  }
}

export default function TicketDetail() {
  const { id } = useParams()
  const navigate = useNavigate()
  const { success, error } = useToast()

  const [ticket, setTicket] = useState(null)
  const [loading, setLoading] = useState(true)
  const [apiError, setApiError] = useState('')
  const [tab, setTab] = useState('overview')

  const [editing, setEditing] = useState(false)
  const [editForm, setEditForm] = useState(null)
  const [saving, setSaving] = useState(false)

  const [categories, setCategories] = useState([])
  const [queues, setQueues] = useState([])

  const [sla, setSla] = useState(null)
  const [slaLoading, setSlaLoading] = useState(false)

  const [linkedWos, setLinkedWos] = useState([])
  const [woLoading, setWoLoading] = useState(false)

  const [commentBody, setCommentBody] = useState('')
  const [postingComment, setPostingComment] = useState(false)

  const [deleteOpen, setDeleteOpen] = useState(false)
  const [deleting, setDeleting] = useState(false)

  const loadTicket = useCallback(async () => {
    setLoading(true)
    setApiError('')
    try {
      const res = await ticketsService.get(id)
      const t = res?.data ?? res
      setTicket(t)
    } catch (e) {
      setApiError(e?.response?.data?.message || e?.message || 'Failed to load ticket')
    } finally {
      setLoading(false)
    }
  }, [id])

  useEffect(() => { loadTicket() }, [loadTicket])

  useEffect(() => {
    let cancelled = false
    Promise.all([
      ticketsService.listCategories({ limit: 200 }).catch(() => null),
      ticketsService.listQueues({ limit: 200 }).catch(() => null),
    ]).then(([cRes, qRes]) => {
      if (cancelled) return
      setCategories(Array.isArray(cRes) ? cRes : cRes?.data ?? [])
      setQueues(Array.isArray(qRes) ? qRes : qRes?.data ?? [])
    })
    return () => { cancelled = true }
  }, [])

  useEffect(() => {
    if (tab !== 'sla' || !id) return
    setSlaLoading(true)
    ticketsService.sla(id)
      .then((res) => setSla(res?.data ?? res))
      .catch((e) => error(e?.response?.data?.message || 'Failed to load SLA'))
      .finally(() => setSlaLoading(false))
  }, [tab, id, error])

  useEffect(() => {
    if (tab !== 'workorders' || !id) return
    setWoLoading(true)
    ticketsService.listLinkedWorkorders(id)
      .then((res) => {
        const list = Array.isArray(res) ? res : res?.data ?? []
        setLinkedWos(list)
      })
      .catch((e) => error(e?.response?.data?.message || 'Failed to load workorders'))
      .finally(() => setWoLoading(false))
  }, [tab, id, error])

  const startEdit = () => {
    setEditForm({
      title: ticket?.subject || ticket?.title || '',
      description: ticket?.description || '',
      status: ticket?.status || 'open',
      priority: ticket?.priority || 'normal',
      category_id: ticket?.category_id ? String(ticket.category_id) : '',
      queue_id: ticket?.queue_id ? String(ticket.queue_id) : '',
    })
    setEditing(true)
  }

  const cancelEdit = () => {
    setEditing(false)
    setEditForm(null)
  }

  const updateEdit = (field) => (eOrValue) => {
    const value = eOrValue && eOrValue.target !== undefined ? eOrValue.target.value : eOrValue
    setEditForm((prev) => ({ ...prev, [field]: value }))
  }

  const saveEdit = async () => {
    if (!editForm) return
    setSaving(true)
    try {
      const payload = {
        title: editForm.title.trim(),
        subject: editForm.title.trim(),
        description: editForm.description,
        status: editForm.status,
        priority: editForm.priority,
        category_id: editForm.category_id || null,
        queue_id: editForm.queue_id || null,
      }
      await ticketsService.update(id, payload)
      success('Ticket updated')
      setEditing(false)
      setEditForm(null)
      loadTicket()
    } catch (e) {
      error(e?.response?.data?.message || 'Failed to update ticket')
    } finally {
      setSaving(false)
    }
  }

  const submitComment = async (e) => {
    e.preventDefault()
    if (!commentBody.trim()) return
    setPostingComment(true)
    try {
      await ticketsService.comment(id, { body: commentBody.trim() })
      success('Comment added')
      setCommentBody('')
      loadTicket()
    } catch (e2) {
      error(e2?.response?.data?.message || 'Failed to add comment')
    } finally {
      setPostingComment(false)
    }
  }

  const handleDelete = async () => {
    setDeleting(true)
    try {
      await ticketsService.delete(id)
      success('Ticket deleted')
      navigate('/cp/tickets')
    } catch (e) {
      error(e?.response?.data?.message || 'Failed to delete ticket')
    } finally {
      setDeleting(false)
      setDeleteOpen(false)
    }
  }

  const categoryOptions = useMemo(
    () => [{ value: '', label: 'No category' }, ...categories.map((c) => ({ value: String(c.id), label: c.name || `#${c.id}` }))],
    [categories]
  )
  const queueOptions = useMemo(
    () => [{ value: '', label: 'No queue' }, ...queues.map((q) => ({ value: String(q.id), label: q.name || `#${q.id}` }))],
    [queues]
  )

  if (loading) {
    return <div className="py-10 flex justify-center"><Loading text="Loading ticket..." /></div>
  }

  if (apiError && !ticket) {
    return (
      <div className="space-y-4">
        <Alert variant="danger">{apiError}</Alert>
        <Button variant="ghost" onClick={() => navigate('/cp/tickets')}>Back to tickets</Button>
      </div>
    )
  }

  if (!ticket) return null

  const comments = Array.isArray(ticket.comments) ? ticket.comments
    : Array.isArray(ticket.activity) ? ticket.activity
    : []

  return (
    <div className="space-y-6">
      <div className="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
        <div>
          <div className="flex items-center gap-2 text-sm text-gray-500">
            <Link to="/cp/tickets" className="hover:text-primary-600">Tickets</Link>
            <span>/</span>
            <span>#{ticket.id}</span>
          </div>
          <h1 className="text-2xl font-bold text-gray-900 mt-1">{ticket.subject || ticket.title || `Ticket #${ticket.id}`}</h1>
          <div className="mt-2 flex flex-wrap gap-2">
            <Badge variant={statusVariant(ticket.status)}>{ticket.status || 'unknown'}</Badge>
            <Badge variant={priorityVariant(ticket.priority)}>{ticket.priority || 'normal'}</Badge>
          </div>
        </div>
        <div className="flex gap-2">
          {!editing && <Button variant="secondary" onClick={startEdit}>Edit</Button>}
          <Button variant="danger" onClick={() => setDeleteOpen(true)}>Delete</Button>
        </div>
      </div>

      {apiError && <Alert variant="danger" onClose={() => setApiError('')}>{apiError}</Alert>}

      <div className="border-b border-gray-200">
        <nav className="-mb-px flex flex-wrap gap-4">
          {TABS.map((t) => (
            <button
              type="button"
              key={t.id}
              onClick={() => setTab(t.id)}
              className={`py-2 px-1 border-b-2 text-sm font-medium ${
                tab === t.id
                  ? 'border-primary-500 text-primary-600'
                  : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
              }`}
            >
              {t.label}
            </button>
          ))}
        </nav>
      </div>

      {tab === 'overview' && (
        <Card>
          {editing ? (
            <div className="space-y-4">
              <Input label="Title" value={editForm.title} onChange={updateEdit('title')} />
              <Textarea label="Description" rows={5} value={editForm.description} onChange={updateEdit('description')} />
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <Select label="Status" value={editForm.status} onChange={updateEdit('status')} options={STATUS_OPTIONS} placeholder="" />
                <Select label="Priority" value={editForm.priority} onChange={updateEdit('priority')} options={PRIORITY_OPTIONS} placeholder="" />
                <Select label="Category" value={editForm.category_id} onChange={updateEdit('category_id')} options={categoryOptions} placeholder="" />
                <Select label="Queue" value={editForm.queue_id} onChange={updateEdit('queue_id')} options={queueOptions} placeholder="" />
              </div>
              <div className="flex justify-end gap-2">
                <Button variant="ghost" onClick={cancelEdit}>Cancel</Button>
                <Button onClick={saveEdit} loading={saving} disabled={saving}>Save</Button>
              </div>
            </div>
          ) : (
            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
              <div className="lg:col-span-2 space-y-4">
                <div>
                  <div className="text-xs uppercase text-gray-500 tracking-wide">Description</div>
                  <p className="mt-1 text-sm text-gray-800 whitespace-pre-wrap">
                    {ticket.description || <span className="text-gray-400">No description</span>}
                  </p>
                </div>
              </div>
              <div className="space-y-3 text-sm">
                <DetailRow label="Status" value={ticket.status} />
                <DetailRow label="Priority" value={ticket.priority} />
                <DetailRow label="Category" value={ticket.category_name || ticket.category?.name || (ticket.category_id ? `#${ticket.category_id}` : null)} />
                <DetailRow label="Queue" value={ticket.queue_name || ticket.queue?.name || (ticket.queue_id ? `#${ticket.queue_id}` : null)} />
                <DetailRow label="Site" value={ticket.site_name || ticket.site?.name || ticket.site_id} />
                <DetailRow label="Asset" value={ticket.site_asset_name || ticket.site_asset?.name || ticket.site_asset_id} />
                <DetailRow label="Reporter email" value={ticket.reporter_email} />
                <DetailRow label="Reporter phone" value={ticket.reporter_phone} />
                <DetailRow label="Created" value={formatDate(ticket.created_at)} />
                <DetailRow label="Updated" value={formatDate(ticket.updated_at)} />
                <DetailRow label="Resolved" value={formatDate(ticket.resolved_at)} />
                <DetailRow label="Closed" value={formatDate(ticket.closed_at)} />
              </div>
            </div>
          )}
        </Card>
      )}

      {tab === 'sla' && (
        <Card>
          {slaLoading ? (
            <div className="py-6 flex justify-center"><Loading /></div>
          ) : !sla ? (
            <div className="text-sm text-gray-500">No SLA information available.</div>
          ) : (
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
              <DetailRow label="Policy" value={sla.policy_name || sla.policy?.name || sla.policy_id} />
              <DetailRow label="Business hours only" value={sla.business_hours_only ? 'Yes' : 'No'} />
              <DetailRow label="Response due" value={formatDate(sla.response_due_at || sla.sla_response_due_at)} />
              <DetailRow label="Response met" value={sla.response_met_at ? formatDate(sla.response_met_at) : 'Pending'} />
              <DetailRow label="Resolution due" value={formatDate(sla.resolution_due_at || sla.sla_resolution_due_at)} />
              <DetailRow label="Resolution met" value={sla.resolution_met_at ? formatDate(sla.resolution_met_at) : 'Pending'} />
              <DetailRow label="Response remaining" value={sla.response_minutes_remaining != null ? `${sla.response_minutes_remaining} min` : null} />
              <DetailRow label="Resolution remaining" value={sla.resolution_minutes_remaining != null ? `${sla.resolution_minutes_remaining} min` : null} />
              <div className="sm:col-span-2">
                {sla.breached || sla.is_breached ? (
                  <Alert variant="danger" closable={false}>SLA has been breached.</Alert>
                ) : sla.due_soon || sla.is_due_soon ? (
                  <Alert variant="warning" closable={false}>SLA due soon.</Alert>
                ) : (
                  <Alert variant="success" closable={false}>SLA on track.</Alert>
                )}
              </div>
            </div>
          )}
        </Card>
      )}

      {tab === 'comments' && (
        <Card>
          <form onSubmit={submitComment} className="space-y-3">
            <Textarea
              label="Add a comment"
              rows={3}
              value={commentBody}
              onChange={(e) => setCommentBody(e.target.value)}
              placeholder="Write a reply or note..."
            />
            <div className="flex justify-end">
              <Button type="submit" loading={postingComment} disabled={!commentBody.trim() || postingComment}>
                Post comment
              </Button>
            </div>
          </form>

          <div className="mt-6 space-y-3">
            {comments.length === 0 ? (
              <div className="text-sm text-gray-500">No comments yet.</div>
            ) : (
              comments.map((c, idx) => (
                <div key={c.id ?? idx} className="border rounded p-3 bg-gray-50">
                  <div className="flex items-center justify-between text-xs text-gray-500">
                    <span>{c.author_name || c.user_name || c.user?.name || 'System'}</span>
                    <span>{formatDate(c.created_at)}</span>
                  </div>
                  <div className="mt-1 text-sm text-gray-800 whitespace-pre-wrap">{c.body || c.content || ''}</div>
                </div>
              ))
            )}
          </div>
        </Card>
      )}

      {tab === 'workorders' && (
        <Card>
          {woLoading ? (
            <div className="py-6 flex justify-center"><Loading /></div>
          ) : linkedWos.length === 0 ? (
            <div className="text-sm text-gray-500">No linked workorders.</div>
          ) : (
            <ul className="divide-y">
              {linkedWos.map((wo) => (
                <li key={wo.id} className="py-3 flex justify-between items-center">
                  <div>
                    <Link to={`/cp/workorders/${wo.id}`} className="text-primary-600 hover:text-primary-500 font-medium">
                      WO #{wo.id} {wo.title ? `— ${wo.title}` : ''}
                    </Link>
                    <div className="text-xs text-gray-500">{wo.status || ''} {wo.created_at ? `· ${formatDate(wo.created_at)}` : ''}</div>
                  </div>
                  {wo.status && <Badge size="sm" variant={statusVariant(wo.status)}>{wo.status}</Badge>}
                </li>
              ))}
            </ul>
          )}
        </Card>
      )}

      {tab === 'triage' && (
        <Card>
          <div className="space-y-3">
            <p className="text-sm text-gray-600">
              View AI/rule-driven triage suggestions for this ticket on the staff triage board.
            </p>
            <div>
              <Button variant="secondary" onClick={() => navigate(`/cp/tickets/triage?ticket=${id}`)}>
                Open triage board
              </Button>
            </div>
          </div>
        </Card>
      )}

      <Modal open={deleteOpen} onClose={() => setDeleteOpen(false)} title="Delete ticket">
        <p className="text-sm text-gray-600 mb-4">
          Are you sure you want to delete this ticket? This action cannot be undone.
        </p>
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={() => setDeleteOpen(false)}>Cancel</Button>
          <Button variant="danger" loading={deleting} disabled={deleting} onClick={handleDelete}>Delete</Button>
        </div>
      </Modal>
    </div>
  )
}

function DetailRow({ label, value }) {
  return (
    <div className="flex justify-between gap-3 border-b border-gray-100 pb-1">
      <span className="text-gray-500 uppercase text-xs tracking-wide">{label}</span>
      <span className="text-gray-900 text-sm text-right">{value || <span className="text-gray-400">—</span>}</span>
    </div>
  )
}
