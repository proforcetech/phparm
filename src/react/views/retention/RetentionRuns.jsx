import { useCallback, useEffect, useMemo, useState } from 'react'

import Alert from '../../components/ui/Alert'
import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Modal from '../../components/ui/Modal'
import Select from '../../components/ui/Select'
import retentionService from '../../../services/retention.service'

const STATUS_VARIANT = {
  success: 'success',
  completed: 'success',
  ok: 'success',
  error: 'danger',
  failed: 'danger',
  partial: 'warning',
  running: 'info',
  pending: 'default',
}

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

function truncate(value, n = 80) {
  if (!value) return ''
  const s = typeof value === 'string' ? value : JSON.stringify(value)
  return s.length > n ? `${s.slice(0, n)}…` : s
}

function statusBadge(status) {
  const variant = STATUS_VARIANT[String(status || '').toLowerCase()] || 'default'
  return <Badge variant={variant}>{status || '—'}</Badge>
}

export default function RetentionRuns() {
  const [policies, setPolicies] = useState([])
  const [policiesLoading, setPoliciesLoading] = useState(true)

  const [filters, setFilters] = useState({
    policy_id: '',
    date_from: '',
    date_to: '',
  })

  const [runs, setRuns] = useState([])
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')
  const [errorModal, setErrorModal] = useState(null)

  useEffect(() => {
    setPoliciesLoading(true)
    retentionService
      .listPolicies()
      .then((res) => setPolicies(res?.data ?? res ?? []))
      .catch((e) => setError(e?.response?.data?.message || e?.message || 'Failed to load policies'))
      .finally(() => setPoliciesLoading(false))
  }, [])

  const queryParams = useMemo(() => {
    const out = {}
    if (filters.policy_id) out.policy_id = filters.policy_id
    if (filters.date_from) out.date_from = filters.date_from
    if (filters.date_to) out.date_to = filters.date_to
    return out
  }, [filters])

  const load = useCallback(() => {
    setLoading(true)
    retentionService
      .listRuns(queryParams)
      .then((res) => setRuns(res?.data ?? res ?? []))
      .catch((e) => setError(e?.response?.data?.message || e?.message || 'Failed to load runs'))
      .finally(() => setLoading(false))
  }, [queryParams])

  useEffect(() => { load() }, [load])

  const policyOptions = useMemo(() => ([
    { value: '', label: policiesLoading ? 'Loading…' : 'All policies' },
    ...policies.map((p) => ({ value: String(p.id), label: p.name })),
  ]), [policies, policiesLoading])

  const policyNameById = useMemo(() => {
    const map = new Map()
    policies.forEach((p) => map.set(String(p.id), p.name))
    return map
  }, [policies])

  return (
    <div className="space-y-4 p-4">
      <header>
        <h1 className="text-xl font-semibold">Retention runs</h1>
        <p className="text-sm text-gray-500">
          Read-only history of every policy execution.
        </p>
      </header>

      {error && <Alert variant="danger" onClose={() => setError('')}>{error}</Alert>}

      <Card padding={false}>
        <div className="p-4 grid grid-cols-1 md:grid-cols-4 gap-3">
          <Select
            label="Policy"
            value={filters.policy_id}
            onChange={(e) => setFilters({ ...filters, policy_id: e?.target?.value ?? '' })}
            options={policyOptions}
            placeholder=""
          />
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
          <div className="flex items-end gap-2">
            <Button variant="secondary" onClick={load}>Refresh</Button>
            <Button
              variant="ghost"
              onClick={() => setFilters({ policy_id: '', date_from: '', date_to: '' })}
            >
              Reset
            </Button>
          </div>
        </div>

        {loading ? (
          <div className="p-6 text-center"><Loading /></div>
        ) : runs.length === 0 ? (
          <div className="p-6 text-center text-gray-500">No runs match these filters.</div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="bg-gray-50 text-xs uppercase text-gray-500">
                <tr>
                  <th className="text-left p-2">Started</th>
                  <th className="text-left p-2">Completed</th>
                  <th className="text-left p-2">Policy</th>
                  <th className="text-left p-2">Target table</th>
                  <th className="text-right p-2">Evaluated</th>
                  <th className="text-right p-2">Pruned</th>
                  <th className="text-right p-2">Duration (s)</th>
                  <th className="text-left p-2">Status</th>
                  <th className="text-left p-2">Error</th>
                </tr>
              </thead>
              <tbody>
                {runs.map((r) => (
                  <tr key={r.id} className="border-t">
                    <td className="p-2 whitespace-nowrap">{formatDate(r.started_at)}</td>
                    <td className="p-2 whitespace-nowrap">{formatDate(r.completed_at)}</td>
                    <td className="p-2">{r.policy_name ?? policyNameById.get(String(r.policy_id)) ?? `#${r.policy_id ?? '—'}`}</td>
                    <td className="p-2 font-mono text-xs">{r.target_table || '—'}</td>
                    <td className="p-2 text-right">{r.records_evaluated ?? '—'}</td>
                    <td className="p-2 text-right">{r.records_pruned ?? '—'}</td>
                    <td className="p-2 text-right">{r.duration_seconds ?? '—'}</td>
                    <td className="p-2">{statusBadge(r.status)}</td>
                    <td className="p-2 max-w-xs">
                      {r.error_message ? (
                        <button
                          type="button"
                          className="text-red-600 underline text-left"
                          onClick={() => setErrorModal(r)}
                        >
                          {truncate(r.error_message)}
                        </button>
                      ) : <span className="text-gray-400">—</span>}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      <Modal
        open={errorModal !== null}
        onClose={() => setErrorModal(null)}
        title="Run error"
        size="lg"
      >
        <pre className="text-xs whitespace-pre-wrap break-words bg-gray-50 p-2 rounded max-h-[60vh] overflow-auto">
          {errorModal?.error_message || ''}
        </pre>
      </Modal>
    </div>
  )
}
