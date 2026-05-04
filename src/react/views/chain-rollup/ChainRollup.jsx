import { useCallback, useEffect, useMemo, useState } from 'react'

import Alert from '../../components/ui/Alert'
import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Select from '../../components/ui/Select'
import chainRollupService from '../../../services/chain-rollup.service'

/**
 * Phase 17 / S4 of docs/woms-expansion-plan.md.
 *
 * Multi-site operations rollup for chain customers. Top-level metrics
 * aggregate across the chain; the table compares the same metrics per site
 * so an account owner can spot the underperformer at a glance.
 *
 * Read perm: chain_rollup.view (server-enforced)
 */

function defaultPeriod() {
  const today = new Date()
  const from = new Date(today.getFullYear(), today.getMonth() - 2, 1)
  const fmt = (d) => d.toISOString().slice(0, 10)
  return { from: fmt(from), to: fmt(today) }
}

function formatMoney(n) {
  if (n === null || n === undefined) return '—'
  const num = Number(n)
  if (!Number.isFinite(num)) return '—'
  return num.toLocaleString(undefined, { style: 'currency', currency: 'USD' })
}

function formatNum(n) {
  if (n === null || n === undefined) return '—'
  const num = Number(n)
  if (!Number.isFinite(num)) return '—'
  return num.toLocaleString()
}

function formatMinutes(n) {
  if (n === null || n === undefined) return '—'
  const num = Number(n)
  if (!Number.isFinite(num)) return '—'
  if (num < 60) return `${Math.round(num)}m`
  const h = Math.floor(num / 60)
  const m = Math.round(num % 60)
  return `${h}h ${m}m`
}

export default function ChainRollup() {
  const [chains, setChains] = useState([])
  const [chainsLoading, setChainsLoading] = useState(true)
  const [search, setSearch] = useState('')
  const [selectedId, setSelectedId] = useState('')
  const [period, setPeriod] = useState(defaultPeriod())
  const [rollup, setRollup] = useState(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')

  const loadChains = useCallback(async () => {
    setChainsLoading(true)
    try {
      const res = await chainRollupService.listChains(search ? { search } : {})
      const list = res?.data ?? []
      setChains(list)
      if (!selectedId && list.length > 0) {
        setSelectedId(String(list[0].id))
      }
    } catch (e) {
      setError(e?.response?.data?.message || e?.message || 'Failed to load chains')
    } finally {
      setChainsLoading(false)
    }
  }, [search, selectedId])

  useEffect(() => {
    loadChains()
  }, [loadChains])

  const loadRollup = useCallback(async () => {
    if (!selectedId) return
    setLoading(true)
    try {
      const res = await chainRollupService.rollup(selectedId, {
        from: period.from,
        to: period.to,
      })
      setRollup(res?.data ?? null)
    } catch (e) {
      setError(e?.response?.data?.message || e?.message || 'Failed to load rollup')
    } finally {
      setLoading(false)
    }
  }, [selectedId, period.from, period.to])

  useEffect(() => {
    loadRollup()
  }, [loadRollup])

  const sortedSites = useMemo(() => {
    if (!rollup?.sites) return []
    return [...rollup.sites].sort((a, b) =>
      (b.spend_in_window || 0) - (a.spend_in_window || 0)
    )
  }, [rollup])

  return (
    <div className="space-y-4 p-4">
      <header className="flex items-center justify-between flex-wrap gap-2">
        <div>
          <h1 className="text-xl font-semibold">Chain Rollup</h1>
          <p className="text-sm text-gray-500">
            Operations summary across every site for a chain customer. Compare SLA, spend,
            workorder velocity, asset count, and contract value side-by-side.
          </p>
        </div>
        <div className="flex items-end gap-2 flex-wrap">
          <Input
            label="Search chains"
            value={search}
            onChange={(e) => setSearch(e?.target?.value ?? e)}
            placeholder="Type to filter…"
          />
          <Select
            label="Chain"
            value={selectedId}
            onChange={(e) => setSelectedId(e?.target?.value ?? e)}
            options={[
              { value: '', label: chainsLoading ? 'Loading…' : 'Select chain…' },
              ...chains.map((c) => ({
                value: String(c.id),
                label: `${c.name} (${c.site_count} site${c.site_count === 1 ? '' : 's'})`,
              })),
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
          <Button onClick={loadRollup}>Refresh</Button>
        </div>
      </header>

      {error && <Alert variant="danger" onClose={() => setError('')}>{error}</Alert>}

      {loading ? (
        <Loading />
      ) : !rollup ? (
        <Card>
          <div className="p-6 text-center text-gray-500">
            Pick a chain to load its rollup.
          </div>
        </Card>
      ) : (
        <>
          <div className="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-5 gap-3">
            <KpiCard
              label="Open Tickets"
              value={formatNum(rollup.chain_totals.open_tickets)}
              hint={`${formatNum(rollup.chain_totals.breached_sla_tickets)} SLA breach${
                rollup.chain_totals.breached_sla_tickets === 1 ? '' : 'es'
              } in window`}
              tone={rollup.chain_totals.breached_sla_tickets > 0 ? 'danger' : 'default'}
            />
            <KpiCard
              label="Open Workorders"
              value={formatNum(rollup.chain_totals.open_workorders)}
              hint={`${formatNum(rollup.chain_totals.completed_workorders_in_window)} completed in window`}
            />
            <KpiCard
              label="Spend (window)"
              value={formatMoney(rollup.chain_totals.spend_in_window)}
              hint={`${formatMoney(rollup.chain_totals.outstanding_balance)} outstanding`}
            />
            <KpiCard
              label="Active Assets"
              value={formatNum(rollup.chain_totals.active_assets)}
            />
            <KpiCard
              label="Active Contracts"
              value={formatNum(rollup.chain_totals.active_contracts)}
              hint={`${formatMoney(rollup.chain_totals.monthly_contract_value)}/mo`}
            />
          </div>

          <Card>
            <div className="px-4 py-2 border-b flex items-center justify-between">
              <div>
                <div className="font-medium">{rollup.company.name}</div>
                <div className="text-xs text-gray-500">
                  Period {rollup.period.from} → {rollup.period.to} · {rollup.sites.length} site{rollup.sites.length === 1 ? '' : 's'}
                </div>
              </div>
            </div>
            {rollup.sites.length === 0 ? (
              <div className="p-6 text-center text-gray-500">
                This chain has no active sites.
              </div>
            ) : (
              <div className="overflow-x-auto">
                <table className="min-w-full text-sm">
                  <thead className="bg-gray-50 text-gray-700">
                    <tr>
                      <th className="text-left p-2">Site</th>
                      <th className="text-left p-2">Location</th>
                      <th className="text-right p-2">Open Tix</th>
                      <th className="text-right p-2">SLA Breach</th>
                      <th className="text-right p-2">Avg FRT</th>
                      <th className="text-right p-2">Open WO</th>
                      <th className="text-right p-2">Done WO</th>
                      <th className="text-right p-2">Spend</th>
                      <th className="text-right p-2">Outstanding</th>
                      <th className="text-right p-2">Assets</th>
                      <th className="text-right p-2">Contracts</th>
                      <th className="text-right p-2">$/mo</th>
                    </tr>
                  </thead>
                  <tbody>
                    {sortedSites.map((s) => (
                      <tr key={s.id} className="border-t hover:bg-gray-50">
                        <td className="p-2">
                          <div className="font-medium">{s.name}</div>
                          {s.code && <div className="text-xs text-gray-500">#{s.code}</div>}
                        </td>
                        <td className="p-2 text-gray-600">
                          {[s.city, s.state, s.country].filter(Boolean).join(', ') || '—'}
                        </td>
                        <td className="p-2 text-right">{formatNum(s.open_tickets)}</td>
                        <td className="p-2 text-right">
                          {s.breached_sla_tickets > 0 ? (
                            <Badge variant="danger">{s.breached_sla_tickets}</Badge>
                          ) : (
                            <span className="text-gray-400">0</span>
                          )}
                        </td>
                        <td className="p-2 text-right">{formatMinutes(s.avg_first_response_minutes)}</td>
                        <td className="p-2 text-right">{formatNum(s.open_workorders)}</td>
                        <td className="p-2 text-right">{formatNum(s.completed_workorders_in_window)}</td>
                        <td className="p-2 text-right font-medium">{formatMoney(s.spend_in_window)}</td>
                        <td className="p-2 text-right">
                          {s.outstanding_balance > 0 ? (
                            <span className="text-orange-600">{formatMoney(s.outstanding_balance)}</span>
                          ) : (
                            formatMoney(s.outstanding_balance)
                          )}
                        </td>
                        <td className="p-2 text-right">{formatNum(s.active_assets)}</td>
                        <td className="p-2 text-right">{formatNum(s.active_contracts)}</td>
                        <td className="p-2 text-right">{formatMoney(s.monthly_contract_value)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </Card>
        </>
      )}
    </div>
  )
}

function KpiCard({ label, value, hint, tone = 'default' }) {
  const toneCls = tone === 'danger' ? 'border-l-4 border-red-400' : ''
  return (
    <Card className={toneCls}>
      <div className="p-3">
        <div className="text-xs uppercase tracking-wide text-gray-500">{label}</div>
        <div className="text-2xl font-semibold mt-1">{value}</div>
        {hint && <div className="text-xs text-gray-500 mt-1">{hint}</div>}
      </div>
    </Card>
  )
}
