// TODO: Add clock-in/clock-out timer controls. v1 is read-only; the supporting
// endpoints (/api/time-tracking/start, /api/time-tracking/:id/stop) already
// exist on the backend and on timeTrackingService — wire them up in a follow-up.

import { useCallback, useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'

import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import timeTrackingService from '../../../services/time-tracking.service'
import { useAuthStore } from '../../stores/auth'

const HISTORY_LIMIT = 10
const ACTIVE_TIMER_TICK_MS = 1000 * 30

const formatMinutesAsHM = (value) => {
  const total = Math.max(0, Math.round(Number(value || 0)))
  const hours = Math.floor(total / 60)
  const minutes = total % 60
  if (hours === 0) return `${minutes}m`
  return `${hours}h ${minutes}m`
}

const formatDateTime = (value) => {
  if (!value) return '—'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return '—'
  return date.toLocaleString()
}

const formatDate = (value) => {
  if (!value) return '—'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return '—'
  return date.toLocaleDateString()
}

const elapsedMinutesSince = (startedAt) => {
  if (!startedAt) return 0
  const start = new Date(startedAt).getTime()
  if (Number.isNaN(start)) return 0
  return Math.max(0, (Date.now() - start) / 1000 / 60)
}

const customerStatusVariant = (status) => {
  const value = (status || '').toLowerCase()
  if (value === 'approved') return 'success'
  if (value === 'rejected' || value === 'declined') return 'danger'
  if (value === 'pending') return 'warning'
  return 'secondary'
}

const entryStatusVariant = (status) => {
  const value = (status || '').toLowerCase()
  if (value === 'approved') return 'success'
  if (value === 'rejected') return 'danger'
  if (value === 'pending') return 'warning'
  return 'secondary'
}

const formatStatusLabel = (value) => {
  if (!value) return 'Unknown'
  return value.charAt(0).toUpperCase() + value.slice(1)
}

export default function TechnicianPortal() {
  const { user } = useAuthStore()
  const role = (user?.role || '').toLowerCase()
  const isTechnician = role === 'technician'

  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')
  const [data, setData] = useState(null)
  const [now, setNow] = useState(() => Date.now())

  const fetchPortal = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      const response = await timeTrackingService.technicianPortal()
      setData(response || null)
    } catch (fetchError) {
      setError(fetchError.response?.data?.message || 'Unable to load your time summary. Please try again.')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    if (!isTechnician) return
    fetchPortal()
  }, [fetchPortal, isTechnician])

  // Tick once every 30s so the active timer's "elapsed" stays roughly fresh
  // without burning a re-render every second.
  useEffect(() => {
    if (!data?.active_entry) return undefined
    const handle = window.setInterval(() => setNow(Date.now()), ACTIVE_TIMER_TICK_MS)
    return () => window.clearInterval(handle)
  }, [data?.active_entry])

  const jobs = useMemo(() => Array.isArray(data?.jobs) ? data.jobs : [], [data])
  const history = useMemo(() => {
    const all = Array.isArray(data?.history) ? data.history : []
    // entriesForTechnician on the backend returns entries newest-first by default,
    // but we sort defensively in case ordering changes.
    return [...all]
      .sort((a, b) => new Date(b.started_at || 0).getTime() - new Date(a.started_at || 0).getTime())
      .slice(0, HISTORY_LIMIT)
  }, [data])

  const activeEntry = data?.active_entry || null
  const activeElapsedMinutes = activeEntry ? elapsedMinutesSince(activeEntry.started_at) : 0
  // `now` participates in the calculation indirectly via Date.now() above; the
  // dependency below silences exhaustive-deps without changing behavior.
  void now

  const totals = data?.totals || { today_minutes: 0, week_minutes: 0 }

  if (!isTechnician) {
    return (
      <div className="space-y-6">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">My Time</h1>
        </div>
        <Card>
          <p className="text-sm text-gray-600">
            This view is only available for technicians. If you believe you should have access,
            contact an administrator.
          </p>
        </Card>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">My Time</h1>
          <p className="text-sm text-gray-600">
            Your assigned jobs, active timer, and recent time entries.
          </p>
        </div>
        <div className="flex gap-2">
          <Button variant="secondary" size="sm" onClick={fetchPortal} loading={loading}>
            Refresh
          </Button>
        </div>
      </div>

      {error ? (
        <Card>
          <p className="text-sm text-red-600">{error}</p>
        </Card>
      ) : null}

      <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
        <Card>
          <p className="text-xs font-medium uppercase tracking-wide text-gray-500">Today</p>
          <p className="mt-2 text-3xl font-semibold text-gray-900">
            {formatMinutesAsHM(totals.today_minutes)}
          </p>
          <p className="mt-1 text-xs text-gray-500">Approved time logged today</p>
        </Card>
        <Card>
          <p className="text-xs font-medium uppercase tracking-wide text-gray-500">This Week</p>
          <p className="mt-2 text-3xl font-semibold text-gray-900">
            {formatMinutesAsHM(totals.week_minutes)}
          </p>
          <p className="mt-1 text-xs text-gray-500">Approved time logged since Monday</p>
        </Card>
      </div>

      <Card title="Active timer">
        {activeEntry ? (
          <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
              <div className="flex items-center gap-2">
                <Badge variant="success" size="sm" dot>
                  Running
                </Badge>
                {activeEntry.task_name ? (
                  <span className="text-sm font-medium text-gray-900">{activeEntry.task_name}</span>
                ) : null}
              </div>
              <p className="mt-2 text-sm text-gray-700">
                Started {formatDateTime(activeEntry.started_at)}
              </p>
              {activeEntry.estimate_job_id ? (
                <p className="text-xs text-gray-500">Job #{activeEntry.estimate_job_id}</p>
              ) : null}
            </div>
            <div className="text-left sm:text-right">
              <p className="text-xs font-medium uppercase tracking-wide text-gray-500">Elapsed</p>
              <p className="mt-1 text-2xl font-semibold text-gray-900">
                {formatMinutesAsHM(activeElapsedMinutes)}
              </p>
            </div>
          </div>
        ) : (
          <p className="text-sm text-gray-600">No active timer.</p>
        )}
      </Card>

      <Card title="Assigned jobs">
        {loading && jobs.length === 0 ? (
          <p className="text-sm text-gray-500">Loading...</p>
        ) : jobs.length === 0 ? (
          <p className="text-sm text-gray-500">No jobs assigned to you right now.</p>
        ) : (
          <ul className="divide-y divide-gray-100">
            {jobs.map((job) => {
              const row = (
                <div className="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between">
                  <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                      <span className="text-sm font-semibold text-gray-900">
                        {job.title || 'Untitled job'}
                      </span>
                      {job.estimate_number ? (
                        <span className="text-xs text-gray-500">#{job.estimate_number}</span>
                      ) : null}
                      {job.is_mobile ? (
                        <Badge variant="info" size="sm">Mobile</Badge>
                      ) : null}
                    </div>
                    <p className="mt-1 text-xs text-gray-600">
                      {job.customer_name || 'Unknown customer'}
                      {job.vehicle_vin ? <span className="text-gray-400"> · VIN {job.vehicle_vin}</span> : null}
                    </p>
                  </div>
                  <div className="flex items-center gap-2">
                    <Badge variant={customerStatusVariant(job.customer_status)} size="sm">
                      {formatStatusLabel(job.customer_status)}
                    </Badge>
                  </div>
                </div>
              )

              return (
                <li key={job.id}>
                  {job.estimate_number ? (
                    <Link
                      to={`/cp/estimates/${job.id}`}
                      className="-mx-2 block rounded px-2 hover:bg-gray-50"
                    >
                      {row}
                    </Link>
                  ) : (
                    <div className="-mx-2 px-2">{row}</div>
                  )}
                </li>
              )
            })}
          </ul>
        )}
      </Card>

      <Card title="Recent time entries">
        {loading && history.length === 0 ? (
          <p className="text-sm text-gray-500">Loading...</p>
        ) : history.length === 0 ? (
          <p className="text-sm text-gray-500">No time entries yet.</p>
        ) : (
          <ul className="divide-y divide-gray-100">
            {history.map((entry) => (
              <li key={entry.id} className="flex flex-col gap-1 py-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                  <p className="text-sm font-medium text-gray-900">
                    {formatDate(entry.started_at)}
                  </p>
                  <p className="text-xs text-gray-500">
                    {formatDateTime(entry.started_at)}
                    {entry.ended_at ? ` → ${formatDateTime(entry.ended_at)}` : ''}
                  </p>
                </div>
                <div className="flex items-center gap-3">
                  <span className="text-sm font-semibold text-gray-900">
                    {formatMinutesAsHM(entry.duration_minutes)}
                  </span>
                  <Badge variant={entryStatusVariant(entry.status)} size="sm">
                    {formatStatusLabel(entry.status)}
                  </Badge>
                </div>
              </li>
            ))}
          </ul>
        )}
      </Card>
    </div>
  )
}
