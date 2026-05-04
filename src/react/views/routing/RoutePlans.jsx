import { useCallback, useEffect, useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'

import Alert from '../../components/ui/Alert'
import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Modal from '../../components/ui/Modal'
import Select from '../../components/ui/Select'
import Textarea from '../../components/ui/Textarea'
import { useToast } from '../../stores/toast.jsx'
import routingService from '../../../services/routing.service'

const PLAN_STATUSES = ['draft', 'active', 'completed', 'cancelled']

const STATUS_VARIANT = {
  draft: 'secondary',
  active: 'info',
  completed: 'success',
  cancelled: 'danger',
}

const STATUS_OPTIONS = [
  { value: '', label: 'All statuses' },
  ...PLAN_STATUSES.map((s) => ({ value: s, label: titleize(s) })),
]

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

function unwrapList(res, keys) {
  const data = res?.data ?? res
  if (Array.isArray(data)) return data
  for (const k of keys) {
    if (Array.isArray(data?.[k])) return data[k]
  }
  return []
}

export default function RoutePlans() {
  const navigate = useNavigate()
  const toast = useToast()
  const [rows, setRows] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [statusFilter, setStatusFilter] = useState('')
  const [dateFrom, setDateFrom] = useState('')
  const [dateTo, setDateTo] = useState('')
  const [ownerFilter, setOwnerFilter] = useState('')
  const [showCreate, setShowCreate] = useState(false)

  const params = useMemo(() => {
    const p = { per_page: 100 }
    if (statusFilter) p.status = statusFilter
    if (dateFrom) p.date_from = dateFrom
    if (dateTo) p.date_to = dateTo
    if (ownerFilter) p.owner_user_id = ownerFilter
    return p
  }, [statusFilter, dateFrom, dateTo, ownerFilter])

  const load = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      const res = await routingService.listPlans(params)
      setRows(unwrapList(res, ['route_plans', 'plans', 'items']))
    } catch (err) {
      setError(err?.response?.data?.message || 'Unable to load route plans.')
    } finally {
      setLoading(false)
    }
  }, [params])

  useEffect(() => { load() }, [load])

  const handleCreated = (id) => {
    setShowCreate(false)
    toast.success('Route plan created.')
    if (id) {
      navigate(`/cp/routing/route-plans/${id}`)
    } else {
      load()
    }
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Route plans</h1>
          <p className="mt-1 text-sm text-gray-500">
            One-off multi-stop route plans with stop lifecycle tracking.
          </p>
        </div>
        <Button variant="primary" onClick={() => setShowCreate(true)}>New plan</Button>
      </div>

      {error ? <Alert variant="danger">{error}</Alert> : null}

      <Card>
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
          <Select
            label="Status"
            placeholder=""
            options={STATUS_OPTIONS}
            modelValue={statusFilter}
            onUpdateModelValue={setStatusFilter}
          />
          <Input label="Date from" type="date" modelValue={dateFrom} onUpdateModelValue={setDateFrom} />
          <Input label="Date to" type="date" modelValue={dateTo} onUpdateModelValue={setDateTo} />
          <Input label="Owner user ID" modelValue={ownerFilter} onUpdateModelValue={setOwnerFilter} />
        </div>
      </Card>

      <Card padding={false}>
        {loading ? (
          <div className="py-10 flex justify-center"><Loading text="Loading route plans..." /></div>
        ) : rows.length === 0 ? (
          <div className="py-10 text-center text-gray-500">No route plans match the current filters.</div>
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Scheduled</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Owner</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Stops</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Created</th>
                </tr>
              </thead>
              <tbody className="bg-white divide-y divide-gray-100">
                {rows.map((row) => (
                  <tr
                    key={row.id}
                    onClick={() => navigate(`/cp/routing/route-plans/${row.id}`)}
                    className="hover:bg-gray-50 cursor-pointer"
                  >
                    <td className="px-4 py-2 text-sm text-gray-900 font-medium">
                      <div>{row.name || `Plan #${row.id}`}</div>
                      <div className="text-xs text-gray-500">#{row.id}</div>
                    </td>
                    <td className="px-4 py-2 text-sm text-gray-700">{formatDateOnly(row.scheduled_date)}</td>
                    <td className="px-4 py-2 text-sm text-gray-700">
                      {row.owner_user_id ? `#${row.owner_user_id}` : '—'}
                    </td>
                    <td className="px-4 py-2 text-sm text-gray-700">{row.stops_count ?? row.stop_count ?? '—'}</td>
                    <td className="px-4 py-2 text-sm">
                      <Badge variant={STATUS_VARIANT[row.status] || 'secondary'}>
                        {titleize(row.status)}
                      </Badge>
                    </td>
                    <td className="px-4 py-2 text-sm text-gray-700">{formatDate(row.created_at)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      {showCreate && (
        <CreatePlanModal
          onClose={() => setShowCreate(false)}
          onCreated={handleCreated}
          onError={(msg) => toast.error(msg)}
        />
      )}
    </div>
  )
}

function CreatePlanModal({ onClose, onCreated, onError }) {
  const [form, setForm] = useState({
    name: '',
    scheduled_date: '',
    owner_user_id: '',
    notes: '',
  })
  const [submitting, setSubmitting] = useState(false)

  const submit = async () => {
    if (!form.name.trim()) return
    setSubmitting(true)
    try {
      const payload = {
        name: form.name.trim(),
        scheduled_date: form.scheduled_date || null,
        owner_user_id: form.owner_user_id ? Number(form.owner_user_id) : null,
        notes: form.notes || null,
      }
      const res = await routingService.createPlan(payload)
      const data = res?.data ?? res
      const id = data?.id ?? data?.route_plan?.id ?? null
      onCreated(id)
    } catch (err) {
      onError(err?.response?.data?.message || 'Unable to create route plan.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Modal open title="New route plan" onClose={onClose} size="lg">
      <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div className="sm:col-span-2">
          <Input
            label="Name"
            required
            modelValue={form.name}
            onUpdateModelValue={(v) => setForm((p) => ({ ...p, name: v }))}
          />
        </div>
        <Input
          label="Scheduled date"
          type="date"
          modelValue={form.scheduled_date}
          onUpdateModelValue={(v) => setForm((p) => ({ ...p, scheduled_date: v }))}
        />
        <Input
          label="Owner user ID"
          modelValue={form.owner_user_id}
          onUpdateModelValue={(v) => setForm((p) => ({ ...p, owner_user_id: v }))}
        />
        <div className="sm:col-span-2">
          <Textarea
            label="Notes"
            rows={3}
            modelValue={form.notes}
            onUpdateModelValue={(v) => setForm((p) => ({ ...p, notes: v }))}
          />
        </div>
      </div>
      <div className="mt-5 flex justify-end gap-2">
        <Button variant="ghost" onClick={onClose} disabled={submitting}>Cancel</Button>
        <Button variant="primary" onClick={submit} disabled={submitting || !form.name.trim()}>
          {submitting ? 'Creating...' : 'Create plan'}
        </Button>
      </div>
    </Modal>
  )
}
