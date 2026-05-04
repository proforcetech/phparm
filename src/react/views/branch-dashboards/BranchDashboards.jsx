import { useCallback, useEffect, useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'

import Alert from '../../components/ui/Alert'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import branchDashboardsService from '../../../services/branch-dashboards.service'

const STATIC_KEYS = new Set(['id', 'branch_id', 'branch_name', 'name', 'code'])

function humanizeKey(key) {
  return key
    .replace(/_/g, ' ')
    .replace(/\bid\b/i, 'ID')
    .replace(/\b\w/g, (c) => c.toUpperCase())
}

function formatValue(key, value) {
  if (value === null || value === undefined) return '—'
  if (typeof value === 'number') {
    if (/revenue|amount|total|cost|price/i.test(key)) {
      return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(value)
    }
    return new Intl.NumberFormat('en-US').format(value)
  }
  if (typeof value === 'boolean') return value ? 'Yes' : 'No'
  if (typeof value === 'string' || typeof value === 'number') return String(value)
  return JSON.stringify(value)
}

export default function BranchDashboards() {
  const navigate = useNavigate()
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [dateFrom, setDateFrom] = useState('')
  const [dateTo, setDateTo] = useState('')

  const load = useCallback(() => {
    setLoading(true)
    setError('')
    const params = {}
    if (dateFrom) params.date_from = dateFrom
    if (dateTo) params.date_to = dateTo
    branchDashboardsService
      .overview(params)
      .then((res) => setData(res?.data ?? res ?? null))
      .catch((e) => setError(e?.response?.data?.message || e?.message || 'Failed to load overview'))
      .finally(() => setLoading(false))
  }, [dateFrom, dateTo])

  useEffect(() => { load() }, [load])

  const branches = useMemo(() => {
    if (!data) return []
    if (Array.isArray(data)) return data
    if (Array.isArray(data.branches)) return data.branches
    if (Array.isArray(data.items)) return data.items
    if (Array.isArray(data.rows)) return data.rows
    return []
  }, [data])

  const appliedRange = useMemo(() => {
    if (!data || Array.isArray(data)) return null
    const from = data.date_from || data.from
    const to = data.date_to || data.to
    if (!from && !to) return null
    return `${from || '…'} → ${to || '…'}`
  }, [data])

  return (
    <div className="space-y-4 p-4">
      <header className="flex items-end justify-between gap-3 flex-wrap">
        <div>
          <h1 className="text-xl font-semibold">Branch Operations</h1>
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
      ) : branches.length === 0 ? (
        <Card>
          <div className="p-6 text-center text-gray-500">No branch data available.</div>
        </Card>
      ) : (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {branches.map((branch) => {
            const branchId = branch.branch_id ?? branch.id
            const branchName = branch.branch_name ?? branch.name ?? `Branch ${branchId ?? ''}`
            const metricEntries = Object.entries(branch).filter(
              ([k, v]) => !STATIC_KEYS.has(k) && (typeof v === 'number' || typeof v === 'string' || typeof v === 'boolean' || v === null)
            )

            const goDetail = () => {
              if (branchId !== undefined && branchId !== null) {
                navigate(`/cp/branches/${branchId}/dashboard`)
              }
            }

            return (
              <Card
                key={branchId ?? branchName}
                hover
                onClick={goDetail}
                role="button"
                tabIndex={0}
                onKeyDown={(e) => {
                  if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault()
                    goDetail()
                  }
                }}
              >
                <div className="space-y-3">
                  <div className="flex items-center justify-between">
                    <h3 className="text-base font-semibold text-gray-900">{branchName}</h3>
                    {branch.code && (
                      <span className="font-mono text-xs text-gray-400">{branch.code}</span>
                    )}
                  </div>
                  {metricEntries.length === 0 ? (
                    <div className="text-sm text-gray-400">No metrics.</div>
                  ) : (
                    <dl className="grid grid-cols-2 gap-2">
                      {metricEntries.map(([k, v]) => (
                        <div key={k} className="rounded border border-gray-100 p-2">
                          <dt className="text-[10px] uppercase tracking-wide text-gray-500">
                            {humanizeKey(k)}
                          </dt>
                          <dd className="mt-1 text-sm font-medium text-gray-900">
                            {formatValue(k, v)}
                          </dd>
                        </div>
                      ))}
                    </dl>
                  )}
                </div>
              </Card>
            )
          })}
        </div>
      )}
    </div>
  )
}
