import { useCallback, useEffect, useMemo, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'

import Alert from '../../components/ui/Alert'
import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Select from '../../components/ui/Select'
import ticketsService from '../../../services/tickets.service'
import { useToast } from '../../stores/toast.jsx'

const PAGE_SIZE = 25

const STATUS_OPTIONS = [
  { value: '', label: 'All statuses' },
  { value: 'open', label: 'Open' },
  { value: 'in_progress', label: 'In progress' },
  { value: 'pending', label: 'Pending' },
  { value: 'resolved', label: 'Resolved' },
  { value: 'closed', label: 'Closed' },
]

const PRIORITY_OPTIONS = [
  { value: '', label: 'All priorities' },
  { value: 'low', label: 'Low' },
  { value: 'normal', label: 'Normal' },
  { value: 'high', label: 'High' },
  { value: 'urgent', label: 'Urgent' },
]

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

const slaBreachLabel = (ticket) => {
  if (!ticket) return null
  if (ticket.sla_breached || ticket.is_sla_breached) return { text: 'Breached', variant: 'danger' }
  if (ticket.sla_due_soon || ticket.is_sla_due_soon) return { text: 'Due soon', variant: 'warning' }
  if (ticket.sla_due_at || ticket.sla_response_due_at || ticket.sla_resolution_due_at) {
    return { text: 'On track', variant: 'success' }
  }
  return null
}

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

export default function TicketList() {
  const navigate = useNavigate()
  const { error } = useToast()

  const [tickets, setTickets] = useState([])
  const [loading, setLoading] = useState(true)
  const [apiError, setApiError] = useState('')
  const [page, setPage] = useState(1)
  const [total, setTotal] = useState(0)

  const [query, setQuery] = useState('')
  const [status, setStatus] = useState('')
  const [priority, setPriority] = useState('')
  const [queueId, setQueueId] = useState('')
  const [categoryId, setCategoryId] = useState('')

  const [queues, setQueues] = useState([])
  const [categories, setCategories] = useState([])

  useEffect(() => {
    let cancelled = false
    Promise.all([
      ticketsService.listQueues({ limit: 200 }).catch(() => null),
      ticketsService.listCategories({ limit: 200 }).catch(() => null),
    ]).then(([qRes, cRes]) => {
      if (cancelled) return
      const qList = Array.isArray(qRes) ? qRes : qRes?.data ?? []
      const cList = Array.isArray(cRes) ? cRes : cRes?.data ?? []
      setQueues(qList)
      setCategories(cList)
    })
    return () => { cancelled = true }
  }, [])

  const loadTickets = useCallback(async () => {
    setLoading(true)
    setApiError('')
    try {
      const params = {
        limit: PAGE_SIZE,
        offset: (page - 1) * PAGE_SIZE,
      }
      if (query.trim()) params.query = query.trim()
      if (status) params.status = status
      if (priority) params.priority = priority
      if (queueId) params.queue_id = queueId
      if (categoryId) params.category_id = categoryId

      const res = await ticketsService.list(params)
      const list = Array.isArray(res) ? res : res?.data ?? []
      setTickets(list)
      setTotal(res?.total ?? res?.meta?.total ?? list.length)
    } catch (e) {
      setApiError(e?.response?.data?.message || e?.message || 'Failed to load tickets')
      setTickets([])
      error('Failed to load tickets')
    } finally {
      setLoading(false)
    }
  }, [page, query, status, priority, queueId, categoryId, error])

  useEffect(() => { loadTickets() }, [loadTickets])

  const queueOptions = useMemo(
    () => [{ value: '', label: 'All queues' }, ...queues.map((q) => ({ value: String(q.id), label: q.name || `#${q.id}` }))],
    [queues]
  )
  const categoryOptions = useMemo(
    () => [{ value: '', label: 'All categories' }, ...categories.map((c) => ({ value: String(c.id), label: c.name || `#${c.id}` }))],
    [categories]
  )

  const handleSearch = (e) => {
    e.preventDefault()
    setPage(1)
    loadTickets()
  }

  const resetFilters = () => {
    setQuery('')
    setStatus('')
    setPriority('')
    setQueueId('')
    setCategoryId('')
    setPage(1)
  }

  const hasNext = tickets.length === PAGE_SIZE

  return (
    <div className="space-y-6">
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Tickets</h1>
          <p className="mt-1 text-sm text-gray-500">Service request intake before they become work orders.</p>
        </div>
        <div className="flex flex-wrap gap-2">
          <Button variant="secondary" onClick={() => navigate('/cp/tickets/triage')}>Triage</Button>
          <Button onClick={() => navigate('/cp/tickets/create')}>New ticket</Button>
        </div>
      </div>

      {apiError && <Alert variant="danger" onClose={() => setApiError('')}>{apiError}</Alert>}

      <Card>
        <form onSubmit={handleSearch} className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-3 mb-4">
          <div className="lg:col-span-2">
            <Input
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              placeholder="Search subject, body, ID..."
            />
          </div>
          <Select value={status} onChange={(e) => { setStatus(e.target.value); setPage(1) }} options={STATUS_OPTIONS} placeholder="" />
          <Select value={priority} onChange={(e) => { setPriority(e.target.value); setPage(1) }} options={PRIORITY_OPTIONS} placeholder="" />
          <Select value={queueId} onChange={(e) => { setQueueId(e.target.value); setPage(1) }} options={queueOptions} placeholder="" />
          <Select value={categoryId} onChange={(e) => { setCategoryId(e.target.value); setPage(1) }} options={categoryOptions} placeholder="" />
          <div className="flex gap-2 sm:col-span-2 lg:col-span-6">
            <Button type="submit" variant="secondary">Search</Button>
            <Button type="button" variant="ghost" onClick={resetFilters}>Reset</Button>
          </div>
        </form>

        {loading ? (
          <div className="py-10 flex justify-center"><Loading text="Loading tickets..." /></div>
        ) : tickets.length === 0 ? (
          <div className="text-center py-12">
            <h3 className="text-sm font-medium text-gray-900">No tickets found</h3>
            <p className="mt-1 text-sm text-gray-500">Try adjusting filters or create a new ticket.</p>
            <div className="mt-4">
              <Button onClick={() => navigate('/cp/tickets/create')}>New ticket</Button>
            </div>
          </div>
        ) : (
          <>
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-gray-200">
                <thead className="bg-gray-50">
                  <tr>
                    <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">ID</th>
                    <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Subject</th>
                    <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                    <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Priority</th>
                    <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Queue</th>
                    <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Category</th>
                    <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Site</th>
                    <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">SLA</th>
                    <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Created</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-200 bg-white">
                  {tickets.map((t) => {
                    const sla = slaBreachLabel(t)
                    return (
                      <tr
                        key={t.id}
                        className="hover:bg-gray-50 cursor-pointer"
                        onClick={() => navigate(`/cp/tickets/${t.id}`)}
                      >
                        <td className="px-3 py-2 text-sm text-gray-700">#{t.id}</td>
                        <td className="px-3 py-2 text-sm">
                          <Link
                            to={`/cp/tickets/${t.id}`}
                            className="text-primary-600 hover:text-primary-500 font-medium"
                            onClick={(e) => e.stopPropagation()}
                          >
                            {t.subject || t.title || '(no subject)'}
                          </Link>
                        </td>
                        <td className="px-3 py-2"><Badge size="sm" variant={statusVariant(t.status)}>{t.status || '—'}</Badge></td>
                        <td className="px-3 py-2"><Badge size="sm" variant={priorityVariant(t.priority)}>{t.priority || '—'}</Badge></td>
                        <td className="px-3 py-2 text-sm text-gray-700">{t.queue_name || t.queue?.name || (t.queue_id ? `#${t.queue_id}` : '—')}</td>
                        <td className="px-3 py-2 text-sm text-gray-700">{t.category_name || t.category?.name || (t.category_id ? `#${t.category_id}` : '—')}</td>
                        <td className="px-3 py-2 text-sm text-gray-700">{t.site_name || t.site?.name || (t.site_id ? `#${t.site_id}` : '—')}</td>
                        <td className="px-3 py-2">{sla ? <Badge size="sm" variant={sla.variant}>{sla.text}</Badge> : <span className="text-gray-400 text-xs">—</span>}</td>
                        <td className="px-3 py-2 text-sm text-gray-500">{formatDate(t.created_at)}</td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>

            <div className="flex justify-between items-center mt-4 pt-4 border-t">
              <span className="text-sm text-gray-500">
                Showing {(page - 1) * PAGE_SIZE + 1} - {(page - 1) * PAGE_SIZE + tickets.length} {total ? `of ${total}` : ''}
              </span>
              <div className="flex gap-2">
                <Button variant="ghost" size="sm" disabled={page === 1} onClick={() => setPage((p) => Math.max(1, p - 1))}>Previous</Button>
                <Button variant="ghost" size="sm" disabled={!hasNext} onClick={() => setPage((p) => p + 1)}>Next</Button>
              </div>
            </div>
          </>
        )}
      </Card>
    </div>
  )
}
