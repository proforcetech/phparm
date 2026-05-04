import { useCallback, useEffect, useMemo, useState } from 'react'

import Alert from '../../components/ui/Alert'
import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Select from '../../components/ui/Select'
import tradeKpisService from '../../../services/trade-kpis.service'

/**
 * Phase 17 / S10 of docs/woms-expansion-plan.md.
 *
 * Trade-specific KPI dashboard. The full bundle (reliability, tickets,
 * workorders, routes) is computed for every service line; this view
 * highlights the metrics that matter for the chosen trade and groups the
 * rest under a "secondary" section.
 *
 * Read perm: trade_kpis.view (server-enforced)
 */

// Per-slug emphasis: which tile keys lead the layout.
const TRADE_PRIMARY = {
  equipment_repair:    ['mttr', 'mtbf', 'wo_complete'],
  fleet_management:    ['mttr', 'mtbf', 'wo_complete'],
  it_support:          ['fcr', 'avg_resolution', 'tickets_resolved'],
  commercial_cleaning: ['route_completion', 'route_planned', 'route_missed'],
  security_systems:    ['install_on_time', 'wo_complete', 'wo_revenue'],
  pos_support:         ['install_on_time', 'wo_complete', 'fcr'],
  building_repair:     ['wo_complete', 'wo_revenue', 'install_on_time'],
  property_management: ['wo_complete', 'tickets_resolved', 'wo_revenue'],
  auto_repair:         ['wo_complete', 'wo_revenue', 'mttr'],
}

function defaultPeriod() {
  const today = new Date()
  const from = new Date(today.getFullYear(), today.getMonth() - 2, 1)
  const fmt = (d) => d.toISOString().slice(0, 10)
  return { from: fmt(from), to: fmt(today) }
}

function formatNum(n) {
  if (n === null || n === undefined) return '—'
  const num = Number(n)
  if (!Number.isFinite(num)) return '—'
  return num.toLocaleString()
}

function formatPct(n) {
  if (n === null || n === undefined) return '—'
  const num = Number(n)
  if (!Number.isFinite(num)) return '—'
  return `${num.toFixed(1)}%`
}

function formatHours(n) {
  if (n === null || n === undefined) return '—'
  const num = Number(n)
  if (!Number.isFinite(num)) return '—'
  if (num < 1) return `${Math.round(num * 60)}m`
  return `${num.toFixed(1)}h`
}

function formatDays(n) {
  if (n === null || n === undefined) return '—'
  const num = Number(n)
  if (!Number.isFinite(num)) return '—'
  return `${num.toFixed(1)}d`
}

function formatMoney(n) {
  if (n === null || n === undefined) return '—'
  const num = Number(n)
  if (!Number.isFinite(num)) return '—'
  return num.toLocaleString(undefined, { style: 'currency', currency: 'USD' })
}

function buildTiles(bundle) {
  if (!bundle) return {}
  const r = bundle.reliability || {}
  const t = bundle.tickets || {}
  const w = bundle.workorders || {}
  const v = bundle.routes || {}
  return {
    mttr: { label: 'MTTR', value: formatHours(r.mttr_hours), hint: `${formatNum(r.sample_size)} repair WOs` },
    mtbf: { label: 'MTBF', value: formatDays(r.mtbf_days), hint: 'Mean time between failures' },
    fcr: {
      label: 'First-Call Resolution',
      value: formatPct(t.first_call_resolution_pct),
      hint: `${formatNum(t.first_call_resolved)} of ${formatNum(t.resolved)} resolved`,
    },
    avg_resolution: { label: 'Avg Resolution', value: formatHours(t.avg_resolution_hours), hint: 'created → resolved' },
    tickets_resolved: { label: 'Tickets Resolved', value: formatNum(t.resolved), hint: `${formatNum(t.created)} opened in window` },
    install_on_time: {
      label: 'Install On-Time',
      value: formatPct(w.install_on_time_pct),
      hint: `${formatNum(w.completed_on_time)} of ${formatNum(w.completed)}`,
    },
    wo_complete: { label: 'Workorders Completed', value: formatNum(w.completed), hint: `${formatNum(w.total)} total` },
    wo_revenue: { label: 'Revenue (completed)', value: formatMoney(w.revenue) },
    route_completion: { label: 'Route Completion', value: formatPct(v.completion_pct), hint: `${formatNum(v.completed)} completed visits` },
    route_planned: { label: 'Visits Planned', value: formatNum(v.planned) },
    route_missed: { label: 'Missed + Skipped', value: formatNum((v.missed || 0) + (v.skipped || 0)) },
  }
}

export default function TradeKpis() {
  const [lines, setLines] = useState([])
  const [linesLoading, setLinesLoading] = useState(true)
  const [serviceLineId, setServiceLineId] = useState('')
  const [period, setPeriod] = useState(defaultPeriod())
  const [bundle, setBundle] = useState(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')

  useEffect(() => {
    let mounted = true
    tradeKpisService.listServiceLines()
      .then((res) => {
        if (!mounted) return
        const list = res?.data ?? []
        setLines(list)
        if (!serviceLineId && list.length > 0) {
          setServiceLineId(String(list[0].id))
        }
      })
      .catch((e) => mounted && setError(e?.response?.data?.message || e?.message || 'Failed to load service lines'))
      .finally(() => mounted && setLinesLoading(false))
    return () => { mounted = false }
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  const loadBundle = useCallback(async () => {
    if (!serviceLineId) return
    setLoading(true)
    try {
      const res = await tradeKpisService.bundle(serviceLineId, {
        from: period.from,
        to: period.to,
      })
      setBundle(res?.data ?? null)
    } catch (e) {
      setError(e?.response?.data?.message || e?.message || 'Failed to load KPIs')
    } finally {
      setLoading(false)
    }
  }, [serviceLineId, period.from, period.to])

  useEffect(() => {
    loadBundle()
  }, [loadBundle])

  const tiles = useMemo(() => buildTiles(bundle), [bundle])
  const slug = bundle?.service_line?.slug || ''
  const primaryKeys = TRADE_PRIMARY[slug] || ['wo_complete', 'wo_revenue', 'tickets_resolved']
  const primarySet = new Set(primaryKeys)
  const secondaryKeys = Object.keys(tiles).filter((k) => !primarySet.has(k))

  return (
    <div className="space-y-4 p-4">
      <header className="flex items-center justify-between flex-wrap gap-2">
        <div>
          <h1 className="text-xl font-semibold">Trade KPI Dashboard</h1>
          <p className="text-sm text-gray-500">
            Operational metrics scoped to a single service line. Headline tiles change with the trade
            (MTBF/MTTR for equipment, FCR for IT, route completion for cleaning, install-on-time for security/POS).
          </p>
        </div>
        <div className="flex items-end gap-2 flex-wrap">
          <Select
            label="Service line"
            value={serviceLineId}
            onChange={(e) => setServiceLineId(e?.target?.value ?? e)}
            options={[
              { value: '', label: linesLoading ? 'Loading…' : 'Select…' },
              ...lines.map((l) => ({ value: String(l.id), label: l.name })),
            ]}
          />
          <Input
            label="From"
            type="date"
            value={period.from}
            onChange={(e) => setPeriod((p) => ({ ...p, from: e?.target?.value ?? e }))}
          />
          <Input
            label="To"
            type="date"
            value={period.to}
            onChange={(e) => setPeriod((p) => ({ ...p, to: e?.target?.value ?? e }))}
          />
          <Button onClick={loadBundle}>Refresh</Button>
        </div>
      </header>

      {error && <Alert variant="danger" onClose={() => setError('')}>{error}</Alert>}

      {loading || !bundle ? (
        loading ? <Loading /> : (
          <Card>
            <div className="p-6 text-center text-gray-500">Pick a service line to view KPIs.</div>
          </Card>
        )
      ) : (
        <>
          <div>
            <div className="flex items-center gap-2 mb-2">
              <h2 className="text-sm font-semibold uppercase tracking-wide text-gray-700">
                {bundle.service_line?.name} — Headline metrics
              </h2>
              <Badge variant="info">{bundle.period.from} → {bundle.period.to}</Badge>
            </div>
            <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
              {primaryKeys.map((k) => (
                tiles[k] ? <KpiCard key={k} {...tiles[k]} prominent /> : null
              ))}
            </div>
          </div>

          {secondaryKeys.length > 0 && (
            <div>
              <h2 className="text-sm font-semibold uppercase tracking-wide text-gray-700 mb-2">
                Secondary metrics
              </h2>
              <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
                {secondaryKeys.map((k) => (
                  tiles[k] ? <KpiCard key={k} {...tiles[k]} /> : null
                ))}
              </div>
            </div>
          )}
        </>
      )}
    </div>
  )
}

function KpiCard({ label, value, hint, prominent = false }) {
  return (
    <Card className={prominent ? 'border-l-4 border-primary-500' : ''}>
      <div className="p-3">
        <div className="text-xs uppercase tracking-wide text-gray-500">{label}</div>
        <div className={prominent ? 'text-3xl font-semibold mt-1' : 'text-xl font-semibold mt-1'}>{value}</div>
        {hint && <div className="text-xs text-gray-500 mt-1">{hint}</div>}
      </div>
    </Card>
  )
}
