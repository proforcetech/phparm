import { useCallback, useEffect, useState } from 'react'

import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import fleetService from '../../../services/fleet.service'
import { useToast } from '../../stores/toast.jsx'

function unwrapItem(response) {
  if (response && typeof response === 'object' && 'data' in response) {
    return response.data ?? null
  }
  return response ?? null
}

function isPlainObject(value) {
  return value !== null && typeof value === 'object' && !Array.isArray(value)
}

function formatLabel(key) {
  return String(key)
    .replace(/[_-]+/g, ' ')
    .replace(/\b\w/g, (c) => c.toUpperCase())
}

function looksLikeMoney(key) {
  const k = String(key).toLowerCase()
  return k.includes('cost') || k.includes('price') || k.includes('amount') || k.includes('total')
}

function formatValue(key, value) {
  if (value === null || value === undefined || value === '') return '—'
  if (typeof value === 'number') {
    if (looksLikeMoney(key)) {
      return value.toLocaleString(undefined, { style: 'currency', currency: 'USD' })
    }
    return value.toLocaleString(undefined, { maximumFractionDigits: 4 })
  }
  if (typeof value === 'string' && /^\d+(\.\d+)?$/.test(value)) {
    return formatValue(key, Number(value))
  }
  return String(value)
}

function findBreakdown(payload) {
  if (!isPlainObject(payload)) return null
  for (const [key, value] of Object.entries(payload)) {
    if (Array.isArray(value) && value.length > 0 && isPlainObject(value[0])) {
      return { key, items: value }
    }
  }
  return null
}

function MetricRows({ payload }) {
  if (!isPlainObject(payload)) {
    return <div className="text-sm text-gray-500">No data.</div>
  }
  const scalarEntries = Object.entries(payload).filter(([, v]) => !Array.isArray(v) && !isPlainObject(v))
  if (scalarEntries.length === 0) {
    return <div className="text-sm text-gray-500">No metrics returned.</div>
  }
  return (
    <dl className="grid grid-cols-1 sm:grid-cols-2 gap-3">
      {scalarEntries.map(([key, value]) => (
        <div key={key}>
          <dt className="text-sm font-medium text-gray-500">{formatLabel(key)}</dt>
          <dd className="mt-1 text-base text-gray-900 font-semibold">{formatValue(key, value)}</dd>
        </div>
      ))}
    </dl>
  )
}

function BreakdownTable({ items }) {
  if (!items?.length) return null
  const columns = Array.from(
    items.reduce((acc, row) => {
      Object.keys(row).forEach((k) => acc.add(k))
      return acc
    }, new Set())
  )
  return (
    <div className="overflow-x-auto">
      <table className="min-w-full divide-y divide-gray-200">
        <thead className="bg-gray-50">
          <tr>
            {columns.map((col) => (
              <th key={col} className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">
                {formatLabel(col)}
              </th>
            ))}
          </tr>
        </thead>
        <tbody className="divide-y divide-gray-200 bg-white">
          {items.map((row, idx) => (
            <tr key={row.id ?? row.unit_id ?? idx}>
              {columns.map((col) => (
                <td key={col} className="px-4 py-2 text-sm text-gray-700">
                  {formatValue(col, row[col])}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

export default function FleetReports() {
  const { error } = useToast()
  const [dateFrom, setDateFrom] = useState('')
  const [dateTo, setDateTo] = useState('')
  const [unitId, setUnitId] = useState('')

  const [costPerMile, setCostPerMile] = useState(null)
  const [costPerHour, setCostPerHour] = useState(null)
  const [loading, setLoading] = useState(false)
  const [appliedRange, setAppliedRange] = useState({ from: '', to: '', unit_id: '' })

  const loadReports = useCallback(async () => {
    setLoading(true)
    try {
      const params = {}
      if (dateFrom) params.date_from = dateFrom
      if (dateTo) params.date_to = dateTo
      if (unitId) params.unit_id = unitId
      const [mile, hour] = await Promise.all([
        fleetService.costPerMile(params).catch(() => null),
        fleetService.costPerHour(params).catch(() => null),
      ])
      setCostPerMile(unwrapItem(mile))
      setCostPerHour(unwrapItem(hour))
      setAppliedRange({ from: dateFrom, to: dateTo, unit_id: unitId })
    } catch {
      error('Failed to load fleet reports')
    } finally {
      setLoading(false)
    }
  }, [dateFrom, dateTo, unitId, error])

  useEffect(() => {
    loadReports()
    // initial load only — subsequent reloads via Apply button
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  const handleApply = (event) => {
    event.preventDefault()
    loadReports()
  }

  const mileBreakdown = findBreakdown(costPerMile)
  const hourBreakdown = findBreakdown(costPerHour)

  const rangeLabel = (() => {
    const parts = []
    if (appliedRange.from || appliedRange.to) {
      parts.push(`${appliedRange.from || '—'} to ${appliedRange.to || '—'}`)
    } else {
      parts.push('all time')
    }
    if (appliedRange.unit_id) parts.push(`unit ${appliedRange.unit_id}`)
    return parts.join(' · ')
  })()

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-gray-900">Fleet Reports</h1>
        <p className="mt-1 text-sm text-gray-500">Cost-per-mile and cost-per-hour analysis.</p>
      </div>

      <Card>
        <form onSubmit={handleApply} className="grid grid-cols-1 sm:grid-cols-4 gap-3">
          <Input
            label="Date from"
            type="date"
            value={dateFrom}
            onUpdateModelValue={setDateFrom}
          />
          <Input
            label="Date to"
            type="date"
            value={dateTo}
            onUpdateModelValue={setDateTo}
          />
          <Input
            label="Unit ID (optional)"
            type="number"
            value={unitId}
            onUpdateModelValue={setUnitId}
          />
          <div className="flex items-end">
            <Button type="submit" loading={loading} fullWidth>Apply</Button>
          </div>
        </form>
      </Card>

      {loading ? (
        <div className="py-12 flex justify-center">
          <Loading text="Computing reports..." />
        </div>
      ) : (
        <>
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <Card>
              <h3 className="text-lg font-medium text-gray-900 mb-4">Cost per Mile</h3>
              {costPerMile ? (
                <MetricRows payload={costPerMile} />
              ) : (
                <p className="text-sm text-gray-500">No data available.</p>
              )}
            </Card>
            <Card>
              <h3 className="text-lg font-medium text-gray-900 mb-4">Cost per Hour</h3>
              {costPerHour ? (
                <MetricRows payload={costPerHour} />
              ) : (
                <p className="text-sm text-gray-500">No data available.</p>
              )}
            </Card>
          </div>

          <p className="text-sm text-gray-500">
            Range applied: <span className="text-gray-900 font-medium">{rangeLabel}</span>.
          </p>

          {mileBreakdown ? (
            <Card>
              <h3 className="text-lg font-medium text-gray-900 mb-4">
                Cost per Mile — {formatLabel(mileBreakdown.key)}
              </h3>
              <BreakdownTable items={mileBreakdown.items} />
            </Card>
          ) : null}

          {hourBreakdown ? (
            <Card>
              <h3 className="text-lg font-medium text-gray-900 mb-4">
                Cost per Hour — {formatLabel(hourBreakdown.key)}
              </h3>
              <BreakdownTable items={hourBreakdown.items} />
            </Card>
          ) : null}
        </>
      )}
    </div>
  )
}
