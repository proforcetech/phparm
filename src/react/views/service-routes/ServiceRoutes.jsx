import { useCallback, useEffect, useMemo, useState } from 'react'

import Alert from '../../components/ui/Alert'
import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Modal from '../../components/ui/Modal'
import Textarea from '../../components/ui/Textarea'
import serviceRoutesService from '../../../services/service-routes.service'

/**
 * Phase 15 / M7 admin UI for recurring service routes.
 *
 * Tabs:
 *   1. Routes — list + filters + create + drill into detail
 *   2. Visits — upcoming materialized occurrences across all routes
 *
 * Detail view: route header + recurrence summary + stops sub-list with
 * inline reorder + visit history + "generate now" button.
 *
 * Read perm:    service_routes.view
 * Manage perm:  service_routes.manage  (route + stop CRUD, generator trigger,
 *                                       dispatcher reassign)
 * Execute perm: service_routes.execute (mobile state transitions)
 */

const TABS = [
  { id: 'routes', label: 'Routes' },
  { id: 'visits', label: 'Visits' },
]

const ROUTE_STATUSES = ['active', 'paused', 'archived']
const RECURRENCE_TYPES = ['daily', 'weekly', 'monthly', 'custom']

const VISIT_STATUSES = [
  'planned', 'en_route', 'arrived',
  'completed', 'skipped', 'missed',
]

const ROUTE_STATUS_VARIANT = {
  active: 'success',
  paused: 'warning',
  archived: 'secondary',
}

const VISIT_STATUS_VARIANT = {
  planned: 'secondary',
  en_route: 'info',
  arrived: 'info',
  completed: 'success',
  skipped: 'warning',
  missed: 'danger',
}

const DAY_LABELS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']

function titleize(s) {
  if (!s) return ''
  return String(s).replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())
}

function formatDate(s) {
  if (!s) return '—'
  try {
    return new Date(String(s).replace(' ', 'T')).toLocaleString()
  } catch {
    return s
  }
}

function formatDateOnly(s) {
  if (!s) return '—'
  try {
    return new Date(String(s).replace(' ', 'T')).toLocaleDateString()
  } catch {
    return s
  }
}

function recurrenceSummary(route) {
  if (!route) return ''
  const interval = route.recurrence_interval || 1
  switch (route.recurrence_type) {
    case 'daily':
      return interval === 1 ? 'Every day' : `Every ${interval} days`
    case 'weekly': {
      const days = (route.days_of_week || []).map((d) => DAY_LABELS[d]).join(', ') || 'no days set'
      return interval === 1 ? `Weekly on ${days}` : `Every ${interval} weeks on ${days}`
    }
    case 'monthly': {
      const dom = route.recurrence_day_of_month || 1
      return interval === 1 ? `Monthly on the ${dom}` : `Every ${interval} months on the ${dom}`
    }
    case 'custom':
      return interval === 1 ? 'Custom (1 day cadence)' : `Custom every ${interval} days`
    default:
      return route.recurrence_type || ''
  }
}

export default function ServiceRoutes() {
  const [tab, setTab] = useState('routes')
  const [error, setError] = useState('')
  const [selectedRouteId, setSelectedRouteId] = useState(null)

  if (selectedRouteId !== null) {
    return (
      <ServiceRouteDetail
        id={selectedRouteId}
        onBack={() => setSelectedRouteId(null)}
        onError={setError}
        error={error}
      />
    )
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Service routes</h1>
          <p className="mt-1 text-sm text-gray-500">
            Recurring multi-stop service routes: define a cadence, attach stops,
            and let the generator materialize visits forward.
          </p>
        </div>
      </div>

      <div className="border-b border-gray-200">
        <nav className="-mb-px flex flex-wrap gap-6">
          {TABS.map((t) => (
            <button
              key={t.id}
              onClick={() => setTab(t.id)}
              className={`py-2 px-1 border-b-2 text-sm font-medium ${
                tab === t.id
                  ? 'border-blue-600 text-blue-700'
                  : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
              }`}
            >
              {t.label}
            </button>
          ))}
        </nav>
      </div>

      {error ? <Alert variant="danger">{error}</Alert> : null}

      {tab === 'routes' && (
        <RouteListTab onError={setError} onSelect={setSelectedRouteId} />
      )}
      {tab === 'visits' && (
        <VisitListTab onError={setError} />
      )}
    </div>
  )
}

// ---------------------------------------------------------------------------
// Route list tab
// ---------------------------------------------------------------------------

function RouteListTab({ onError, onSelect }) {
  const [rows, setRows] = useState([])
  const [loading, setLoading] = useState(true)
  const [statusFilter, setStatusFilter] = useState('')
  const [typeFilter, setTypeFilter] = useState('')
  const [search, setSearch] = useState('')
  const [showForm, setShowForm] = useState(false)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const params = { per_page: 100 }
      if (statusFilter) params.status = statusFilter
      if (typeFilter) params.recurrence_type = typeFilter
      if (search) params.search = search
      const res = await serviceRoutesService.list(params)
      setRows(res?.data?.service_routes || [])
    } catch (err) {
      console.error('Failed to load service routes', err)
      onError('Unable to load service routes.')
    } finally {
      setLoading(false)
    }
  }, [statusFilter, typeFilter, search, onError])

  useEffect(() => { load() }, [load])

  return (
    <div className="space-y-4">
      <Card>
        <div className="flex flex-wrap items-end gap-3">
          <label className="block text-sm font-medium text-gray-700">
            Status
            <select
              value={statusFilter}
              onChange={(e) => setStatusFilter(e.target.value)}
              className="mt-1 block rounded-md border-gray-300 shadow-sm sm:text-sm px-3 py-2"
            >
              <option value="">All</option>
              {ROUTE_STATUSES.map((s) => <option key={s} value={s}>{titleize(s)}</option>)}
            </select>
          </label>
          <label className="block text-sm font-medium text-gray-700">
            Cadence
            <select
              value={typeFilter}
              onChange={(e) => setTypeFilter(e.target.value)}
              className="mt-1 block rounded-md border-gray-300 shadow-sm sm:text-sm px-3 py-2"
            >
              <option value="">All</option>
              {RECURRENCE_TYPES.map((t) => <option key={t} value={t}>{titleize(t)}</option>)}
            </select>
          </label>
          <Input label="Search" modelValue={search} onUpdateModelValue={setSearch} />
          <div className="flex-1" />
          <Button variant="primary" onClick={() => setShowForm(true)}>New route</Button>
        </div>
      </Card>

      <Card>
        {loading ? (
          <div className="py-10 flex justify-center"><Loading text="Loading routes..." /></div>
        ) : rows.length === 0 ? (
          <div className="py-10 text-center text-gray-500">No service routes found.</div>
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Route</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Customer</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Cadence</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Generated through</th>
                  <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                </tr>
              </thead>
              <tbody className="bg-white divide-y divide-gray-100">
                {rows.map((row) => (
                  <tr key={row.id} className="hover:bg-gray-50">
                    <td className="px-4 py-2 text-sm text-gray-900 font-medium">
                      <div>{row.name}</div>
                      <div className="text-xs text-gray-500">#{row.id}</div>
                    </td>
                    <td className="px-4 py-2 text-sm text-gray-700">
                      {row.customer_id ? `#${row.customer_id}` : '—'}
                    </td>
                    <td className="px-4 py-2 text-sm text-gray-700">{recurrenceSummary(row)}</td>
                    <td className="px-4 py-2 text-sm">
                      <Badge variant={ROUTE_STATUS_VARIANT[row.status] || 'secondary'}>
                        {titleize(row.status)}
                      </Badge>
                    </td>
                    <td className="px-4 py-2 text-sm text-gray-700">
                      {formatDateOnly(row.last_generated_through)}
                    </td>
                    <td className="px-4 py-2 text-sm text-right">
                      <button onClick={() => onSelect(row.id)} className="text-blue-600 hover:underline">
                        Open
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      {showForm && (
        <RouteFormModal
          onClose={() => setShowForm(false)}
          onSaved={async (id) => { setShowForm(false); await load(); if (id) onSelect(id) }}
          onError={onError}
        />
      )}
    </div>
  )
}

// ---------------------------------------------------------------------------
// Route form modal
// ---------------------------------------------------------------------------

function RouteFormModal({ onClose, onSaved, onError, row = null }) {
  const [form, setForm] = useState({
    name: row?.name || '',
    description: row?.description || '',
    customer_id: row?.customer_id ?? '',
    service_line_id: row?.service_line_id ?? '',
    default_assigned_user_id: row?.default_assigned_user_id ?? '',
    status: row?.status || 'active',
    recurrence_type: row?.recurrence_type || 'weekly',
    recurrence_interval: row?.recurrence_interval ?? 1,
    days_of_week: Array.isArray(row?.days_of_week) ? row.days_of_week : [],
    recurrence_day_of_month: row?.recurrence_day_of_month ?? '',
    recurrence_time_of_day: row?.recurrence_time_of_day || '',
    start_date: row?.start_date || '',
    end_date: row?.end_date || '',
    generation_horizon_days: row?.generation_horizon_days ?? 30,
    photo_verification_required: row?.photo_verification_required ?? false,
    min_photos_per_visit: row?.min_photos_per_visit ?? 0,
    estimated_visit_minutes: row?.estimated_visit_minutes ?? '',
    notes: row?.notes || '',
  })
  const [submitting, setSubmitting] = useState(false)

  const toggleDay = (day) => {
    setForm((p) => {
      const has = p.days_of_week.includes(day)
      const next = has
        ? p.days_of_week.filter((d) => d !== day)
        : [...p.days_of_week, day].sort((a, b) => a - b)
      return { ...p, days_of_week: next }
    })
  }

  const submit = async () => {
    setSubmitting(true)
    try {
      const numericIfSet = (v) => (v === '' || v === null ? null : Number(v))
      const payload = {
        name: form.name,
        description: form.description || null,
        customer_id: numericIfSet(form.customer_id),
        service_line_id: numericIfSet(form.service_line_id),
        default_assigned_user_id: numericIfSet(form.default_assigned_user_id),
        status: form.status,
        recurrence_type: form.recurrence_type,
        recurrence_interval: Math.max(1, Number(form.recurrence_interval) || 1),
        recurrence_days_of_week: form.recurrence_type === 'weekly'
          ? form.days_of_week.join(',')
          : null,
        recurrence_day_of_month: form.recurrence_type === 'monthly'
          ? numericIfSet(form.recurrence_day_of_month)
          : null,
        recurrence_time_of_day: form.recurrence_time_of_day || null,
        start_date: form.start_date || null,
        end_date: form.end_date || null,
        generation_horizon_days: Math.max(1, Number(form.generation_horizon_days) || 30),
        photo_verification_required: form.photo_verification_required ? 1 : 0,
        min_photos_per_visit: Math.max(0, Number(form.min_photos_per_visit) || 0),
        estimated_visit_minutes: numericIfSet(form.estimated_visit_minutes),
        notes: form.notes || null,
      }
      if (row?.id) {
        await serviceRoutesService.update(row.id, payload)
        await onSaved(row.id)
      } else {
        const res = await serviceRoutesService.create(payload)
        await onSaved(res?.data?.id || null)
      }
    } catch (err) {
      onError(err?.response?.data?.message || 'Unable to save route.')
    } finally {
      setSubmitting(false)
    }
  }

  const isWeekly = form.recurrence_type === 'weekly'
  const isMonthly = form.recurrence_type === 'monthly'

  return (
    <Modal open title={row?.id ? `Edit route #${row.id}` : 'New service route'} onClose={onClose} size="xl">
      <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div className="sm:col-span-2">
          <Input label="Route name" required modelValue={form.name} onUpdateModelValue={(v) => setForm((p) => ({ ...p, name: v }))} />
        </div>
        <div className="sm:col-span-2">
          <Textarea label="Description" rows={2} modelValue={form.description} onUpdateModelValue={(v) => setForm((p) => ({ ...p, description: v }))} />
        </div>

        <Input label="Customer ID" modelValue={form.customer_id} onUpdateModelValue={(v) => setForm((p) => ({ ...p, customer_id: v }))} />
        <Input label="Service line ID" modelValue={form.service_line_id} onUpdateModelValue={(v) => setForm((p) => ({ ...p, service_line_id: v }))} />
        <Input label="Default assigned user ID" modelValue={form.default_assigned_user_id} onUpdateModelValue={(v) => setForm((p) => ({ ...p, default_assigned_user_id: v }))} />

        <label className="block text-sm font-medium text-gray-700">
          Status
          <select
            value={form.status}
            onChange={(e) => setForm((p) => ({ ...p, status: e.target.value }))}
            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm px-3 py-2"
          >
            {ROUTE_STATUSES.map((s) => <option key={s} value={s}>{titleize(s)}</option>)}
          </select>
        </label>

        <label className="block text-sm font-medium text-gray-700">
          Recurrence type
          <select
            value={form.recurrence_type}
            onChange={(e) => setForm((p) => ({ ...p, recurrence_type: e.target.value }))}
            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm px-3 py-2"
          >
            {RECURRENCE_TYPES.map((t) => <option key={t} value={t}>{titleize(t)}</option>)}
          </select>
        </label>
        <Input
          label="Interval"
          type="number"
          modelValue={form.recurrence_interval}
          onUpdateModelValue={(v) => setForm((p) => ({ ...p, recurrence_interval: v }))}
        />

        {isWeekly && (
          <div className="sm:col-span-2">
            <div className="text-sm font-medium text-gray-700 mb-1">Days of the week</div>
            <div className="flex flex-wrap gap-2">
              {DAY_LABELS.map((label, idx) => {
                const selected = form.days_of_week.includes(idx)
                return (
                  <button
                    key={idx}
                    type="button"
                    onClick={() => toggleDay(idx)}
                    className={`px-3 py-1 rounded-full text-sm border ${
                      selected
                        ? 'bg-blue-600 text-white border-blue-600'
                        : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50'
                    }`}
                  >
                    {label}
                  </button>
                )
              })}
            </div>
          </div>
        )}

        {isMonthly && (
          <Input
            label="Day of month (1–31)"
            type="number"
            modelValue={form.recurrence_day_of_month}
            onUpdateModelValue={(v) => setForm((p) => ({ ...p, recurrence_day_of_month: v }))}
          />
        )}

        <Input
          label="Time of day (HH:MM:SS)"
          modelValue={form.recurrence_time_of_day}
          onUpdateModelValue={(v) => setForm((p) => ({ ...p, recurrence_time_of_day: v }))}
        />

        <Input label="Start date" type="date" modelValue={form.start_date} onUpdateModelValue={(v) => setForm((p) => ({ ...p, start_date: v }))} />
        <Input label="End date" type="date" modelValue={form.end_date} onUpdateModelValue={(v) => setForm((p) => ({ ...p, end_date: v }))} />
        <Input
          label="Generation horizon (days)"
          type="number"
          modelValue={form.generation_horizon_days}
          onUpdateModelValue={(v) => setForm((p) => ({ ...p, generation_horizon_days: v }))}
        />
        <Input
          label="Estimated visit minutes"
          type="number"
          modelValue={form.estimated_visit_minutes}
          onUpdateModelValue={(v) => setForm((p) => ({ ...p, estimated_visit_minutes: v }))}
        />

        <label className="block text-sm font-medium text-gray-700 sm:col-span-2">
          <input
            type="checkbox"
            checked={!!form.photo_verification_required}
            onChange={(e) => setForm((p) => ({ ...p, photo_verification_required: e.target.checked }))}
            className="mr-2"
          />
          Require photo verification at completion
        </label>
        <Input
          label="Min photos per visit"
          type="number"
          modelValue={form.min_photos_per_visit}
          onUpdateModelValue={(v) => setForm((p) => ({ ...p, min_photos_per_visit: v }))}
        />

        <div className="sm:col-span-2">
          <Textarea label="Notes" rows={2} modelValue={form.notes} onUpdateModelValue={(v) => setForm((p) => ({ ...p, notes: v }))} />
        </div>
      </div>
      <div className="mt-5 flex justify-end gap-2">
        <Button variant="ghost" onClick={onClose} disabled={submitting}>Cancel</Button>
        <Button variant="primary" onClick={submit} disabled={submitting || !form.name}>
          {submitting ? 'Saving...' : 'Save'}
        </Button>
      </div>
    </Modal>
  )
}

// ---------------------------------------------------------------------------
// Route detail view
// ---------------------------------------------------------------------------

function ServiceRouteDetail({ id, onBack, onError, error }) {
  const [route, setRoute] = useState(null)
  const [loading, setLoading] = useState(true)
  const [editing, setEditing] = useState(false)
  const [stopForm, setStopForm] = useState(null)
  const [generating, setGenerating] = useState(false)
  const [visits, setVisits] = useState([])
  const [visitsLoading, setVisitsLoading] = useState(false)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const res = await serviceRoutesService.get(id)
      setRoute(res?.data || null)
    } catch (err) {
      console.error('Failed to load route', err)
      onError('Unable to load route detail.')
    } finally {
      setLoading(false)
    }
  }, [id, onError])

  const loadVisits = useCallback(async () => {
    setVisitsLoading(true)
    try {
      const res = await serviceRoutesService.listVisits({
        service_route_id: id,
        per_page: 100,
      })
      setVisits(res?.data?.route_visits || [])
    } catch (err) {
      console.error('Failed to load visits', err)
      onError('Unable to load route visits.')
    } finally {
      setVisitsLoading(false)
    }
  }, [id, onError])

  useEffect(() => { load(); loadVisits() }, [load, loadVisits])

  const generate = async () => {
    setGenerating(true)
    try {
      const res = await serviceRoutesService.generate(id)
      const created = res?.data?.visits_created ?? 0
      onError('') // clear prior errors so the success surfaces
      await load()
      await loadVisits()
      // soft success notice via the same alert slot
      onError(`Generator created ${created} visit${created === 1 ? '' : 's'}.`)
    } catch (err) {
      onError(err?.response?.data?.message || 'Unable to run generator.')
    } finally {
      setGenerating(false)
    }
  }

  const deleteStop = async (stopId) => {
    if (!confirm('Delete this stop? Existing materialized visits are preserved.')) return
    try {
      await serviceRoutesService.deleteStop(stopId)
      await load()
    } catch (err) {
      onError(err?.response?.data?.message || 'Unable to delete stop.')
    }
  }

  if (loading) {
    return (
      <div className="space-y-4">
        <Button variant="ghost" onClick={onBack}>← Back to routes</Button>
        <Card><div className="py-10 flex justify-center"><Loading text="Loading route..." /></div></Card>
      </div>
    )
  }
  if (!route) {
    return (
      <div className="space-y-4">
        <Button variant="ghost" onClick={onBack}>← Back to routes</Button>
        <Card><div className="py-10 text-center text-gray-500">Route not found.</div></Card>
      </div>
    )
  }

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <Button variant="ghost" onClick={onBack}>← Back to routes</Button>
        <div className="flex gap-2">
          <Button variant="outline" onClick={() => setEditing(true)}>Edit route</Button>
          <Button variant="primary" onClick={generate} disabled={generating}>
            {generating ? 'Generating...' : 'Generate now'}
          </Button>
        </div>
      </div>

      {error ? <Alert variant="info">{error}</Alert> : null}

      <Card>
        <div className="space-y-3">
          <div className="flex flex-wrap items-center gap-2">
            <h2 className="text-xl font-semibold text-gray-900">{route.name}</h2>
            <Badge variant={ROUTE_STATUS_VARIANT[route.status] || 'secondary'}>{titleize(route.status)}</Badge>
            <span className="text-sm text-gray-500">#{route.id}</span>
          </div>
          {route.description ? (
            <p className="text-sm text-gray-700 whitespace-pre-wrap">{route.description}</p>
          ) : null}

          <div className="grid grid-cols-2 sm:grid-cols-3 gap-3 text-sm">
            <Field label="Cadence" value={recurrenceSummary(route)} />
            <Field label="Time of day" value={route.recurrence_time_of_day || '—'} />
            <Field label="Customer" value={route.customer_id ? `#${route.customer_id}` : '—'} />
            <Field label="Service line" value={route.service_line_id ? `#${route.service_line_id}` : '—'} />
            <Field label="Default assignee" value={route.default_assigned_user_id ? `#${route.default_assigned_user_id}` : '—'} />
            <Field label="Start date" value={formatDateOnly(route.start_date)} />
            <Field label="End date" value={formatDateOnly(route.end_date)} />
            <Field label="Horizon (days)" value={route.generation_horizon_days} />
            <Field label="Generated through" value={formatDateOnly(route.last_generated_through)} />
            <Field label="Photo verification" value={route.photo_verification_required ? 'Required' : 'Optional'} />
            <Field label="Min photos / visit" value={route.min_photos_per_visit} />
            <Field label="Visit minutes" value={route.estimated_visit_minutes ?? '—'} />
          </div>

          {route.notes ? <Section title="Notes" body={route.notes} /> : null}
        </div>
      </Card>

      <Card>
        <div className="flex items-center justify-between mb-3">
          <h3 className="text-lg font-semibold text-gray-900">Stops</h3>
          <Button variant="outline" onClick={() => setStopForm({})}>Add stop</Button>
        </div>
        {(route.stops || []).length === 0 ? (
          <p className="py-3 text-sm text-gray-500">No stops on this route yet.</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Seq</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Site</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Asset</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Min</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Photos req'd</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Active</th>
                  <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                </tr>
              </thead>
              <tbody className="bg-white divide-y divide-gray-100">
                {(route.stops || []).map((stop) => (
                  <tr key={stop.id}>
                    <td className="px-4 py-2 text-sm text-gray-700">{stop.sequence}</td>
                    <td className="px-4 py-2 text-sm text-gray-900 font-medium">{stop.stop_name || `Stop #${stop.id}`}</td>
                    <td className="px-4 py-2 text-sm text-gray-700">{stop.site_id ? `#${stop.site_id}` : '—'}</td>
                    <td className="px-4 py-2 text-sm text-gray-700">{stop.site_asset_id ? `#${stop.site_asset_id}` : '—'}</td>
                    <td className="px-4 py-2 text-sm text-gray-700">{stop.estimated_minutes ?? '—'}</td>
                    <td className="px-4 py-2 text-sm text-gray-700">{stop.required_photos}</td>
                    <td className="px-4 py-2 text-sm">
                      <Badge variant={stop.is_active ? 'success' : 'secondary'}>
                        {stop.is_active ? 'Yes' : 'No'}
                      </Badge>
                    </td>
                    <td className="px-4 py-2 text-sm text-right space-x-3">
                      <button onClick={() => setStopForm(stop)} className="text-blue-600 hover:underline">Edit</button>
                      <button onClick={() => deleteStop(stop.id)} className="text-red-600 hover:underline">Delete</button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      <Card>
        <div className="flex items-center justify-between mb-3">
          <h3 className="text-lg font-semibold text-gray-900">Visits</h3>
          <Button variant="ghost" onClick={loadVisits} disabled={visitsLoading}>
            {visitsLoading ? 'Reloading...' : 'Refresh'}
          </Button>
        </div>
        {visitsLoading ? (
          <div className="py-6 flex justify-center"><Loading text="Loading visits..." /></div>
        ) : visits.length === 0 ? (
          <p className="py-3 text-sm text-gray-500">No visits materialized yet — try Generate now.</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Visit</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Stop</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Scheduled</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Assignee</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Photos</th>
                </tr>
              </thead>
              <tbody className="bg-white divide-y divide-gray-100">
                {visits.map((v) => (
                  <tr key={v.id}>
                    <td className="px-4 py-2 text-sm text-gray-700">#{v.id}</td>
                    <td className="px-4 py-2 text-sm text-gray-700">#{v.route_stop_id}</td>
                    <td className="px-4 py-2 text-sm text-gray-700">{formatDate(v.scheduled_for)}</td>
                    <td className="px-4 py-2 text-sm">
                      <Badge variant={VISIT_STATUS_VARIANT[v.status] || 'secondary'}>
                        {titleize(v.status)}
                      </Badge>
                    </td>
                    <td className="px-4 py-2 text-sm text-gray-700">{v.assigned_user_id ? `#${v.assigned_user_id}` : '—'}</td>
                    <td className="px-4 py-2 text-sm text-gray-700">{v.photos_uploaded}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      {editing && (
        <RouteFormModal
          row={route}
          onClose={() => setEditing(false)}
          onSaved={async () => { setEditing(false); await load() }}
          onError={onError}
        />
      )}

      {stopForm !== null && (
        <StopFormModal
          routeId={id}
          row={stopForm.id ? stopForm : null}
          onClose={() => setStopForm(null)}
          onSaved={async () => { setStopForm(null); await load() }}
          onError={onError}
        />
      )}
    </div>
  )
}

// ---------------------------------------------------------------------------
// Stop form modal
// ---------------------------------------------------------------------------

function StopFormModal({ routeId, row = null, onClose, onSaved, onError }) {
  const [form, setForm] = useState({
    sequence: row?.sequence ?? 1,
    site_id: row?.site_id ?? '',
    site_asset_id: row?.site_asset_id ?? '',
    stop_name: row?.stop_name || '',
    estimated_minutes: row?.estimated_minutes ?? '',
    checklist_template_id: row?.checklist_template_id ?? '',
    required_photos: row?.required_photos ?? 0,
    notes: row?.notes || '',
    is_active: row?.is_active ?? true,
  })
  const [submitting, setSubmitting] = useState(false)

  const submit = async () => {
    setSubmitting(true)
    try {
      const numericIfSet = (v) => (v === '' || v === null ? null : Number(v))
      const payload = {
        sequence: Math.max(1, Number(form.sequence) || 1),
        site_id: numericIfSet(form.site_id),
        site_asset_id: numericIfSet(form.site_asset_id),
        stop_name: form.stop_name || null,
        estimated_minutes: numericIfSet(form.estimated_minutes),
        checklist_template_id: numericIfSet(form.checklist_template_id),
        required_photos: Math.max(0, Number(form.required_photos) || 0),
        notes: form.notes || null,
        is_active: form.is_active ? 1 : 0,
      }
      if (row?.id) {
        await serviceRoutesService.updateStop(row.id, payload)
      } else {
        await serviceRoutesService.createStop(routeId, payload)
      }
      await onSaved()
    } catch (err) {
      onError(err?.response?.data?.message || 'Unable to save stop.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Modal open title={row?.id ? `Edit stop #${row.id}` : 'Add stop'} onClose={onClose} size="lg">
      <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <Input label="Sequence" type="number" required modelValue={form.sequence} onUpdateModelValue={(v) => setForm((p) => ({ ...p, sequence: v }))} />
        <Input label="Stop name" modelValue={form.stop_name} onUpdateModelValue={(v) => setForm((p) => ({ ...p, stop_name: v }))} />
        <Input label="Site ID" required modelValue={form.site_id} onUpdateModelValue={(v) => setForm((p) => ({ ...p, site_id: v }))} />
        <Input label="Site asset ID" modelValue={form.site_asset_id} onUpdateModelValue={(v) => setForm((p) => ({ ...p, site_asset_id: v }))} />
        <Input label="Estimated minutes" type="number" modelValue={form.estimated_minutes} onUpdateModelValue={(v) => setForm((p) => ({ ...p, estimated_minutes: v }))} />
        <Input label="Required photos" type="number" modelValue={form.required_photos} onUpdateModelValue={(v) => setForm((p) => ({ ...p, required_photos: v }))} />
        <Input label="Checklist template ID" modelValue={form.checklist_template_id} onUpdateModelValue={(v) => setForm((p) => ({ ...p, checklist_template_id: v }))} />
        <label className="flex items-center text-sm font-medium text-gray-700">
          <input
            type="checkbox"
            checked={!!form.is_active}
            onChange={(e) => setForm((p) => ({ ...p, is_active: e.target.checked }))}
            className="mr-2"
          />
          Active
        </label>
        <div className="sm:col-span-2">
          <Textarea label="Notes" rows={2} modelValue={form.notes} onUpdateModelValue={(v) => setForm((p) => ({ ...p, notes: v }))} />
        </div>
      </div>
      <div className="mt-5 flex justify-end gap-2">
        <Button variant="ghost" onClick={onClose} disabled={submitting}>Cancel</Button>
        <Button variant="primary" onClick={submit} disabled={submitting || !form.site_id}>
          {submitting ? 'Saving...' : 'Save stop'}
        </Button>
      </div>
    </Modal>
  )
}

// ---------------------------------------------------------------------------
// Visits tab (cross-route view)
// ---------------------------------------------------------------------------

function VisitListTab({ onError }) {
  const today = useMemo(() => {
    const d = new Date()
    d.setHours(0, 0, 0, 0)
    return d
  }, [])

  const [start, setStart] = useState(() => fmtDate(today))
  const [end, setEnd] = useState(() => fmtDate(new Date(today.getTime() + 14 * 86400000)))
  const [statusFilter, setStatusFilter] = useState('')
  const [rows, setRows] = useState([])
  const [loading, setLoading] = useState(true)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const params = {
        scheduled_from: `${start} 00:00:00`,
        scheduled_to: `${end} 23:59:59`,
        per_page: 200,
      }
      if (statusFilter) params.status = statusFilter
      const res = await serviceRoutesService.listVisits(params)
      setRows(res?.data?.route_visits || [])
    } catch (err) {
      console.error('Failed to load visits', err)
      onError('Unable to load visits.')
    } finally {
      setLoading(false)
    }
  }, [start, end, statusFilter, onError])

  useEffect(() => { load() }, [load])

  return (
    <div className="space-y-4">
      <Card>
        <div className="flex flex-wrap items-end gap-3">
          <Input label="From" type="date" modelValue={start} onUpdateModelValue={setStart} />
          <Input label="To" type="date" modelValue={end} onUpdateModelValue={setEnd} />
          <label className="block text-sm font-medium text-gray-700">
            Status
            <select
              value={statusFilter}
              onChange={(e) => setStatusFilter(e.target.value)}
              className="mt-1 block rounded-md border-gray-300 shadow-sm sm:text-sm px-3 py-2"
            >
              <option value="">All</option>
              {VISIT_STATUSES.map((s) => <option key={s} value={s}>{titleize(s)}</option>)}
            </select>
          </label>
          <Button variant="outline" onClick={load}>Refresh</Button>
        </div>
      </Card>

      <Card>
        {loading ? (
          <div className="py-10 flex justify-center"><Loading text="Loading visits..." /></div>
        ) : rows.length === 0 ? (
          <div className="py-10 text-center text-gray-500">No visits in this window.</div>
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Visit</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Route</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Stop</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Scheduled</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Assignee</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Photos</th>
                </tr>
              </thead>
              <tbody className="bg-white divide-y divide-gray-100">
                {rows.map((v) => (
                  <tr key={v.id}>
                    <td className="px-4 py-2 text-sm text-gray-700">#{v.id}</td>
                    <td className="px-4 py-2 text-sm text-gray-700">#{v.service_route_id}</td>
                    <td className="px-4 py-2 text-sm text-gray-700">#{v.route_stop_id}</td>
                    <td className="px-4 py-2 text-sm text-gray-700">{formatDate(v.scheduled_for)}</td>
                    <td className="px-4 py-2 text-sm">
                      <Badge variant={VISIT_STATUS_VARIANT[v.status] || 'secondary'}>
                        {titleize(v.status)}
                      </Badge>
                    </td>
                    <td className="px-4 py-2 text-sm text-gray-700">{v.assigned_user_id ? `#${v.assigned_user_id}` : '—'}</td>
                    <td className="px-4 py-2 text-sm text-gray-700">{v.photos_uploaded}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>
    </div>
  )
}

function Field({ label, value }) {
  return (
    <div>
      <div className="text-xs uppercase tracking-wide text-gray-500">{label}</div>
      <div className="text-sm text-gray-800">{value ?? '—'}</div>
    </div>
  )
}

function Section({ title, body }) {
  return (
    <div className="border-l-4 border-gray-200 pl-3">
      <div className="text-xs uppercase tracking-wide text-gray-500">{title}</div>
      <p className="text-sm text-gray-800 whitespace-pre-wrap">{body}</p>
    </div>
  )
}

function fmtDate(d) {
  const y = d.getFullYear()
  const m = String(d.getMonth() + 1).padStart(2, '0')
  const day = String(d.getDate()).padStart(2, '0')
  return `${y}-${m}-${day}`
}
