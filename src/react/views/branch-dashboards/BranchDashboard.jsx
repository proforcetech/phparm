import { useCallback, useEffect, useMemo, useState } from 'react'
import { Link, useParams } from 'react-router-dom'

import Alert from '../../components/ui/Alert'
import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import branchDashboardsService from '../../../services/branch-dashboards.service'

const SCALAR_TYPES = new Set(['number', 'string', 'boolean'])
const KPI_SKIP = new Set([
  'id', 'branch_id', 'branch_name', 'name', 'code',
  'date_from', 'date_to', 'from', 'to', 'period',
])

function humanizeKey(key) {
  return key
    .replace(/_/g, ' ')
    .replace(/\bid\b/i, 'ID')
    .replace(/\b\w/g, (c) => c.toUpperCase())
}

function isCurrencyKey(key) {
  return /revenue|amount|total|cost|price|paid|owed|balance/i.test(key)
}

function formatScalar(key, value) {
  if (value === null || value === undefined || value === '') return '—'
  if (typeof value === 'number') {
    if (isCurrencyKey(key)) {
      return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(value)
    }
    return new Intl.NumberFormat('en-US').format(value)
  }
  if (typeof value === 'boolean') return value ? 'Yes' : 'No'
  return String(value)
}

function statusVariant(status) {
  const v = String(status || '').toLowerCase()
  if (['open', 'pending', 'new', 'in_progress', 'in-progress', 'active'].includes(v)) return 'warning'
  if (['done', 'completed', 'closed', 'paid'].includes(v)) return 'success'
  if (['cancelled', 'canceled', 'failed', 'blocked'].includes(v)) return 'danger'
  return 'default'
}

function DataTable({ rows, linkBuilder = null, idKey = 'id' }) {
  if (!rows || rows.length === 0) {
    return <div className="p-4 text-sm text-gray-500">None.</div>
  }
  const cols = Object.keys(rows[0]).filter((k) => SCALAR_TYPES.has(typeof rows[0][k]) || rows[0][k] === null)
  return (
    <div className="overflow-x-auto">
      <table className="w-full text-sm">
        <thead className="bg-gray-50 text-xs uppercase text-gray-500">
          <tr>
            {cols.map((c) => (
              <th key={c} className="text-left p-2">{humanizeKey(c)}</th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.map((row, idx) => (
            <tr key={row[idKey] ?? idx} className="border-t">
              {cols.map((c) => {
                const v = row[c]
                let cell = formatScalar(c, v)
                if (/status$/i.test(c) && v) {
                  cell = <Badge variant={statusVariant(v)}>{String(v)}</Badge>
                } else if (linkBuilder && c === idKey) {
                  const href = linkBuilder(row)
                  if (href) cell = <Link className="text-primary-600 hover:underline" to={href}>{formatScalar(c, v)}</Link>
                }
                return <td key={c} className="p-2">{cell}</td>
              })}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

function pickArray(data, ...keys) {
  if (!data) return null
  for (const k of keys) {
    if (Array.isArray(data[k])) return data[k]
  }
  return null
}

export default function BranchDashboard() {
  const { id } = useParams()
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [dateFrom, setDateFrom] = useState('')
  const [dateTo, setDateTo] = useState('')

  const load = useCallback(() => {
    if (!id) return
    setLoading(true)
    setError('')
    const params = {}
    if (dateFrom) params.date_from = dateFrom
    if (dateTo) params.date_to = dateTo
    branchDashboardsService
      .byBranch(id, params)
      .then((res) => setData(res?.data ?? res ?? null))
      .catch((e) => setError(e?.response?.data?.message || e?.message || 'Failed to load branch dashboard'))
      .finally(() => setLoading(false))
  }, [id, dateFrom, dateTo])

  useEffect(() => { load() }, [load])

  const branchName = data?.branch_name ?? data?.name ?? `Branch ${id}`
  const appliedRange = useMemo(() => {
    if (!data) return null
    const from = data.date_from || data.from
    const to = data.date_to || data.to
    if (!from && !to) return null
    return `${from || '…'} → ${to || '…'}`
  }, [data])

  const kpis = useMemo(() => {
    if (!data) return []
    if (data.kpis && typeof data.kpis === 'object' && !Array.isArray(data.kpis)) {
      return Object.entries(data.kpis)
    }
    return Object.entries(data).filter(
      ([k, v]) => !KPI_SKIP.has(k) && SCALAR_TYPES.has(typeof v)
    )
  }, [data])

  const openWorkorders = useMemo(
    () => pickArray(data, 'open_workorders', 'workorders'),
    [data]
  )
  const openTickets = useMemo(
    () => pickArray(data, 'open_tickets', 'tickets'),
    [data]
  )
  const technicians = useMemo(
    () => pickArray(data, 'technicians_on_duty', 'technicians', 'on_duty'),
    [data]
  )

  return (
    <div className="space-y-4 p-4">
      <header className="flex items-end justify-between gap-3 flex-wrap">
        <div>
          <Link to="/cp/branches/dashboards" className="text-xs text-primary-600 hover:underline">
            &larr; All branches
          </Link>
          <h1 className="text-xl font-semibold mt-1">{branchName}</h1>
          {appliedRange && (
            <p className="text-sm text-gray-500">Date range: {appliedRange}</p>
          )}
        </div>
      </header>

      {error && <Alert variant="danger" onClose={() => setError('')}>{error}</Alert>}

      <Card padding={false}>
        <div className="p-4 flex items-end gap-3 flex-wrap">
          <Input
            type="date"
            label="From"
            value={dateFrom}
            onChange={(e) => setDateFrom(e.target.value)}
          />
          <Input
            type="date"
            label="To"
            value={dateTo}
            onChange={(e) => setDateTo(e.target.value)}
          />
          <Button variant="secondary" onClick={load}>Refresh</Button>
        </div>
      </Card>

      {loading ? (
        <div className="p-6 text-center"><Loading /></div>
      ) : !data ? (
        <Card><div className="p-6 text-center text-gray-500">No data.</div></Card>
      ) : (
        <>
          {kpis.length > 0 && (
            <Card title="KPIs">
              <dl className="grid gap-3 grid-cols-2 sm:grid-cols-3 lg:grid-cols-4">
                {kpis.map(([k, v]) => (
                  <div key={k} className="rounded border border-gray-100 p-3">
                    <dt className="text-[10px] uppercase tracking-wide text-gray-500">
                      {humanizeKey(k)}
                    </dt>
                    <dd className="mt-1 text-lg font-semibold text-gray-900">
                      {formatScalar(k, v)}
                    </dd>
                  </div>
                ))}
              </dl>
            </Card>
          )}

          {openWorkorders && (
            <Card title="Open Work Orders" padding={false}>
              <DataTable
                rows={openWorkorders}
                linkBuilder={(row) => (row.id ? `/cp/workorders/${row.id}` : null)}
              />
            </Card>
          )}

          {openTickets && (
            <Card title="Open Tickets" padding={false}>
              <DataTable
                rows={openTickets}
                linkBuilder={(row) => (row.id ? `/cp/tickets/${row.id}` : null)}
              />
            </Card>
          )}

          {technicians && (
            <Card title="Technicians On Duty" padding={false}>
              <DataTable rows={technicians} />
            </Card>
          )}
        </>
      )}
    </div>
  )
}
