import { useCallback, useEffect, useMemo, useState } from 'react'

import Alert from '../../components/ui/Alert'
import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Modal from '../../components/ui/Modal'
import Select from '../../components/ui/Select'
import { useToast } from '../../stores/toast.jsx'
import securityEventsService from '../../../services/security-events.service'

const SEVERITY_VARIANT = {
  info: 'default',
  low: 'default',
  warning: 'warning',
  medium: 'warning',
  high: 'danger',
  critical: 'danger',
}

const SEVERITY_OPTIONS = [
  { value: '', label: 'All severities' },
  { value: 'info', label: 'Info' },
  { value: 'warning', label: 'Warning' },
  { value: 'critical', label: 'Critical' },
]

function formatDate(value) {
  if (!value) return '—'
  try {
    const d = new Date(String(value).replace(' ', 'T'))
    if (Number.isNaN(d.getTime())) return String(value)
    return d.toLocaleString()
  } catch {
    return String(value)
  }
}

function truncate(value, n = 60) {
  if (!value) return ''
  const s = typeof value === 'string' ? value : JSON.stringify(value)
  return s.length > n ? `${s.slice(0, n)}…` : s
}

function severityBadge(value) {
  const variant = SEVERITY_VARIANT[String(value || '').toLowerCase()] || 'default'
  return <Badge variant={variant}>{value || 'unknown'}</Badge>
}

export default function SecurityEvents() {
  const toast = useToast()

  const [filters, setFilters] = useState({
    event_type: '',
    severity: '',
    user_id: '',
    ip_address: '',
    date_from: '',
    date_to: '',
  })

  const [events, setEvents] = useState([])
  const [loading, setLoading] = useState(false)
  const [summary, setSummary] = useState(null)
  const [summaryLoading, setSummaryLoading] = useState(false)
  const [error, setError] = useState('')

  const [detailEvent, setDetailEvent] = useState(null)
  const [detailLoading, setDetailLoading] = useState(false)

  const queryParams = useMemo(() => {
    const out = {}
    if (filters.event_type) out.event_type = filters.event_type
    if (filters.severity) out.severity = filters.severity
    if (filters.user_id) out.user_id = filters.user_id
    if (filters.ip_address) out.ip_address = filters.ip_address
    if (filters.date_from) out.date_from = filters.date_from
    if (filters.date_to) out.date_to = filters.date_to
    return out
  }, [filters])

  const loadSummary = useCallback(() => {
    setSummaryLoading(true)
    const params = {}
    if (filters.date_from) params.date_from = filters.date_from
    if (filters.date_to) params.date_to = filters.date_to
    securityEventsService
      .summary(params)
      .then((res) => setSummary(res?.data ?? res ?? null))
      .catch((e) => setError(e?.response?.data?.message || e?.message || 'Failed to load summary'))
      .finally(() => setSummaryLoading(false))
  }, [filters.date_from, filters.date_to])

  const loadEvents = useCallback(() => {
    setLoading(true)
    securityEventsService
      .list(queryParams)
      .then((res) => {
        const payload = res?.data ?? res ?? {}
        const list = Array.isArray(payload) ? payload : (payload?.events ?? [])
        setEvents(Array.isArray(list) ? list : [])
      })
      .catch((e) => setError(e?.response?.data?.message || e?.message || 'Failed to load events'))
      .finally(() => setLoading(false))
  }, [queryParams])

  useEffect(() => { loadSummary() }, [loadSummary])
  useEffect(() => { loadEvents() }, [loadEvents])

  const eventTypes = useMemo(() => {
    const set = new Set()
    events.forEach((e) => { if (e.event_type) set.add(e.event_type) })
    return Array.from(set).sort()
  }, [events])

  const eventTypeOptions = useMemo(() => ([
    { value: '', label: 'All event types' },
    ...eventTypes.map((t) => ({ value: t, label: t })),
  ]), [eventTypes])

  const summaryStats = useMemo(() => {
    if (!summary || typeof summary !== 'object') return []
    const source = summary.by_severity && typeof summary.by_severity === 'object'
      ? summary.by_severity
      : summary
    return Object.entries(source)
      .filter(([, v]) => typeof v === 'number')
      .map(([key, value]) => ({ key, value }))
  }, [summary])

  const openDetail = async (row) => {
    setDetailEvent({ loading: true, data: row })
    setDetailLoading(true)
    try {
      const res = await securityEventsService.get(row.id)
      setDetailEvent({ loading: false, data: res?.data ?? res ?? row })
    } catch (e) {
      toast.error(e?.response?.data?.message || e?.message || 'Failed to load event')
      setDetailEvent({ loading: false, data: row })
    } finally {
      setDetailLoading(false)
    }
  }

  return (
    <div className="space-y-4 p-4">
      <header>
        <h1 className="text-xl font-semibold">Security events</h1>
        <p className="text-sm text-gray-500">
          Read-only audit feed for authentication, MFA challenges, and suspicious activity.
        </p>
      </header>

      {error && <Alert variant="danger" onClose={() => setError('')}>{error}</Alert>}

      <Card title="Summary">
        {summaryLoading ? (
          <Loading />
        ) : summaryStats.length === 0 ? (
          <p className="text-sm text-gray-500">No summary metrics available.</p>
        ) : (
          <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
            {summaryStats.map((s) => (
              <div key={s.key} className="border rounded p-3 bg-gray-50">
                <div className="text-xs uppercase text-gray-500 tracking-wide">
                  {s.key.replace(/_/g, ' ')}
                </div>
                <div className="text-2xl font-semibold mt-1">{s.value}</div>
              </div>
            ))}
          </div>
        )}
      </Card>

      <Card padding={false}>
        <div className="p-4 grid grid-cols-1 md:grid-cols-3 lg:grid-cols-6 gap-3">
          <Input
            label="Date from"
            type="date"
            value={filters.date_from}
            onChange={(e) => setFilters({ ...filters, date_from: e.target.value })}
          />
          <Input
            label="Date to"
            type="date"
            value={filters.date_to}
            onChange={(e) => setFilters({ ...filters, date_to: e.target.value })}
          />
          <Select
            label="Event type"
            value={filters.event_type}
            onChange={(e) => setFilters({ ...filters, event_type: e?.target?.value ?? '' })}
            options={eventTypeOptions}
            placeholder=""
          />
          <Select
            label="Severity"
            value={filters.severity}
            onChange={(e) => setFilters({ ...filters, severity: e?.target?.value ?? '' })}
            options={SEVERITY_OPTIONS}
            placeholder=""
          />
          <Input
            label="User ID"
            value={filters.user_id}
            onChange={(e) => setFilters({ ...filters, user_id: e.target.value })}
            placeholder="Filter by user id"
          />
          <Input
            label="IP address"
            value={filters.ip_address}
            onChange={(e) => setFilters({ ...filters, ip_address: e.target.value })}
            placeholder="e.g. 10.0.0.1"
          />
        </div>

        <div className="px-4 pb-2 flex gap-2">
          <Button variant="secondary" onClick={() => { loadEvents(); loadSummary() }}>Refresh</Button>
          <Button
            variant="ghost"
            onClick={() => setFilters({
              event_type: '', severity: '', user_id: '', ip_address: '', date_from: '', date_to: '',
            })}
          >
            Reset filters
          </Button>
        </div>

        {loading ? (
          <div className="p-6 text-center"><Loading /></div>
        ) : events.length === 0 ? (
          <div className="p-6 text-center text-gray-500">No events match these filters.</div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="bg-gray-50 text-xs uppercase text-gray-500">
                <tr>
                  <th className="text-left p-2">Occurred</th>
                  <th className="text-left p-2">Event</th>
                  <th className="text-left p-2">Severity</th>
                  <th className="text-left p-2">User</th>
                  <th className="text-left p-2">IP</th>
                  <th className="text-left p-2">User agent</th>
                  <th className="text-left p-2">Summary</th>
                </tr>
              </thead>
              <tbody>
                {events.map((row) => (
                  <tr
                    key={row.id}
                    className="border-t hover:bg-gray-50 cursor-pointer"
                    onClick={() => openDetail(row)}
                  >
                    <td className="p-2 whitespace-nowrap">{formatDate(row.occurred_at || row.created_at)}</td>
                    <td className="p-2"><Badge variant="info">{row.event_type || '—'}</Badge></td>
                    <td className="p-2">{severityBadge(row.severity)}</td>
                    <td className="p-2">{row.user_id ?? row.user ?? '—'}</td>
                    <td className="p-2 font-mono text-xs">{row.ip_address || '—'}</td>
                    <td className="p-2 max-w-xs truncate text-gray-600" title={row.user_agent || ''}>
                      {truncate(row.user_agent, 40) || '—'}
                    </td>
                    <td className="p-2 max-w-md truncate">{row.summary || row.message || '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      <Modal
        open={detailEvent !== null}
        onClose={() => setDetailEvent(null)}
        title="Event detail"
        size="lg"
      >
        {detailLoading ? (
          <Loading />
        ) : (
          <pre className="text-xs whitespace-pre-wrap break-words bg-gray-50 p-2 rounded max-h-[60vh] overflow-auto">
            {detailEvent?.data ? JSON.stringify(detailEvent.data, null, 2) : ''}
          </pre>
        )}
      </Modal>
    </div>
  )
}
