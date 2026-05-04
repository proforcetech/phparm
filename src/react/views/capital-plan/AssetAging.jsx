import { useCallback, useEffect, useMemo, useState } from 'react'

import Alert from '../../components/ui/Alert'
import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Loading from '../../components/ui/Loading'
import Select from '../../components/ui/Select'
import { useToast } from '../../stores/toast.jsx'
import capitalPlanService from '../../../services/capital-plan.service'
import crmService from '../../../services/crm.service'
import divisionsService from '../../../services/divisions.service'

const SCOPE_OPTIONS = [
  { value: 'portfolio', label: 'Portfolio' },
  { value: 'company', label: 'Company' },
  { value: 'division', label: 'Division' },
]

const CLASSIFICATION_VARIANT = {
  like_new: 'success',
  monitor: 'default',
  plan: 'warning',
  replace_now: 'danger',
}

function classificationLabel(key) {
  if (!key) return 'Unknown'
  return String(key)
    .split('_')
    .map((s) => s.charAt(0).toUpperCase() + s.slice(1))
    .join(' ')
}

function formatNumber(n) {
  if (n === null || n === undefined || n === '') return '—'
  const num = Number(n)
  if (!Number.isFinite(num)) return '—'
  return num.toLocaleString()
}

function formatYears(n) {
  if (n === null || n === undefined || n === '') return '—'
  const num = Number(n)
  if (!Number.isFinite(num)) return '—'
  return `${num.toFixed(1)}y`
}

function formatMoney(n) {
  if (n === null || n === undefined || n === '') return '—'
  const num = Number(n)
  if (!Number.isFinite(num)) return '—'
  return num.toLocaleString(undefined, { style: 'currency', currency: 'USD', maximumFractionDigits: 0 })
}

function deriveBuckets(payload) {
  if (!payload) return []
  if (Array.isArray(payload.buckets)) return payload.buckets
  if (payload.buckets && typeof payload.buckets === 'object') {
    return Object.entries(payload.buckets).map(([key, value]) => ({
      classification: key,
      count: typeof value === 'number' ? value : value?.count ?? 0,
      ...(typeof value === 'object' ? value : {}),
    }))
  }
  if (payload.summary && typeof payload.summary === 'object') {
    return Object.entries(payload.summary).map(([key, value]) => ({
      classification: key,
      count: typeof value === 'number' ? value : value?.count ?? 0,
    }))
  }
  return []
}

function deriveAssets(payload) {
  if (!payload) return []
  if (Array.isArray(payload.assets)) return payload.assets
  if (Array.isArray(payload.items)) return payload.items
  if (Array.isArray(payload.rows)) return payload.rows
  if (Array.isArray(payload)) return payload
  return []
}

export default function AssetAging() {
  const toast = useToast()
  const [scope, setScope] = useState('portfolio')
  const [companyId, setCompanyId] = useState('')
  const [divisionId, setDivisionId] = useState('')
  const [scoringModelId, setScoringModelId] = useState('')

  const [companies, setCompanies] = useState([])
  const [divisions, setDivisions] = useState([])
  const [scoringModels, setScoringModels] = useState([])

  const [payload, setPayload] = useState(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')

  useEffect(() => {
    let mounted = true
    Promise.all([
      crmService.listCompanies({ limit: 500 }).catch(() => ({ data: [] })),
      divisionsService.list().catch(() => ({ data: [] })),
      capitalPlanService.listScoringModels().catch(() => ({ data: [] })),
    ]).then(([cRes, dRes, mRes]) => {
      if (!mounted) return
      setCompanies(cRes?.data ?? [])
      setDivisions(dRes?.data ?? [])
      setScoringModels(mRes?.data ?? [])
    })
    return () => {
      mounted = false
    }
  }, [])

  const load = useCallback(async () => {
    setError('')
    const params = {}
    if (scoringModelId) params.scoring_model_id = scoringModelId

    if (scope === 'company' && !companyId) {
      setPayload(null)
      return
    }
    if (scope === 'division' && !divisionId) {
      setPayload(null)
      return
    }

    setLoading(true)
    try {
      let res
      if (scope === 'portfolio') {
        res = await capitalPlanService.agingPortfolio(params)
      } else if (scope === 'company') {
        res = await capitalPlanService.agingForCompany(companyId, params)
      } else {
        res = await capitalPlanService.agingForDivision(divisionId, params)
      }
      setPayload(res?.data ?? null)
    } catch (e) {
      const msg = e?.response?.data?.message || e?.message || 'Failed to load aging'
      setError(msg)
      toast.error(msg)
    } finally {
      setLoading(false)
    }
  }, [scope, companyId, divisionId, scoringModelId, toast])

  useEffect(() => {
    load()
  }, [load])

  const buckets = useMemo(() => deriveBuckets(payload), [payload])
  const assets = useMemo(() => deriveAssets(payload), [payload])

  return (
    <div className="space-y-4 p-4">
      <header>
        <h1 className="text-xl font-semibold">Asset Aging</h1>
        <p className="text-sm text-gray-500">
          Replacement-readiness scoring across the installed base. Pick a scope to drill in.
        </p>
      </header>

      {error ? (
        <Alert variant="danger" onClose={() => setError('')}>
          {error}
        </Alert>
      ) : null}

      <Card padding={false}>
        <div className="p-4 flex items-end gap-3 flex-wrap">
          <Select
            label="Scope"
            value={scope}
            placeholder=""
            onChange={(e) => setScope(e?.target?.value ?? e)}
            options={SCOPE_OPTIONS}
          />
          {scope === 'company' ? (
            <Select
              label="Company"
              value={companyId}
              onChange={(e) => setCompanyId(e?.target?.value ?? e)}
              options={[
                { value: '', label: 'Select company…' },
                ...companies.map((c) => ({ value: String(c.id), label: c.name })),
              ]}
            />
          ) : null}
          {scope === 'division' ? (
            <Select
              label="Division"
              value={divisionId}
              onChange={(e) => setDivisionId(e?.target?.value ?? e)}
              options={[
                { value: '', label: 'Select division…' },
                ...divisions.map((d) => ({ value: String(d.id), label: d.name })),
              ]}
            />
          ) : null}
          <Select
            label="Scoring model"
            value={scoringModelId}
            onChange={(e) => setScoringModelId(e?.target?.value ?? e)}
            options={[
              { value: '', label: 'Default' },
              ...scoringModels.map((m) => ({ value: String(m.id), label: m.name })),
            ]}
          />
          <Button variant="secondary" onClick={load} disabled={loading}>
            Refresh
          </Button>
        </div>
      </Card>

      {loading ? (
        <div className="p-6 text-center">
          <Loading />
        </div>
      ) : !payload ? (
        <Card>
          <div className="p-6 text-center text-gray-500">
            {scope === 'company' && !companyId
              ? 'Select a company to view aging.'
              : scope === 'division' && !divisionId
                ? 'Select a division to view aging.'
                : 'No data to display.'}
          </div>
        </Card>
      ) : (
        <>
          {buckets.length > 0 ? (
            <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
              {buckets.map((b) => (
                <Card key={b.classification}>
                  <div className="p-3">
                    <div className="text-xs uppercase tracking-wide text-gray-500">
                      {classificationLabel(b.classification)}
                    </div>
                    <div className="text-2xl font-semibold mt-1">{formatNumber(b.count)}</div>
                    <div className="mt-2">
                      <Badge variant={CLASSIFICATION_VARIANT[b.classification] || 'default'}>
                        {classificationLabel(b.classification)}
                      </Badge>
                    </div>
                    {b.replacement_estimate !== undefined ? (
                      <div className="text-xs text-gray-500 mt-2">
                        Est: {formatMoney(b.replacement_estimate)}
                      </div>
                    ) : null}
                  </div>
                </Card>
              ))}
            </div>
          ) : null}

          <Card padding={false}>
            <div className="px-4 py-3 border-b border-gray-200">
              <h2 className="text-sm font-semibold uppercase tracking-wide text-gray-700">
                Assets
              </h2>
            </div>
            {assets.length === 0 ? (
              <div className="p-6 text-center text-gray-500">No assets in scope.</div>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead className="bg-gray-50 text-xs uppercase text-gray-500">
                    <tr>
                      <th className="text-left p-2">Name</th>
                      <th className="text-left p-2">Type</th>
                      <th className="text-left p-2">Site</th>
                      <th className="text-right p-2">Age</th>
                      <th className="text-right p-2">Score</th>
                      <th className="text-left p-2">Classification</th>
                      <th className="text-right p-2">Replacement Est.</th>
                    </tr>
                  </thead>
                  <tbody>
                    {assets.map((a) => {
                      const cls = a.classification || a.bucket
                      return (
                        <tr key={a.id ?? `${a.name}-${a.site_id ?? ''}`} className="border-t">
                          <td className="p-2">{a.name || a.asset_name || '—'}</td>
                          <td className="p-2">{a.type || a.asset_type || '—'}</td>
                          <td className="p-2">{a.site_name || a.site || '—'}</td>
                          <td className="p-2 text-right">{formatYears(a.age_years ?? a.age)}</td>
                          <td className="p-2 text-right">{formatNumber(a.score)}</td>
                          <td className="p-2">
                            {cls ? (
                              <Badge variant={CLASSIFICATION_VARIANT[cls] || 'default'}>
                                {classificationLabel(cls)}
                              </Badge>
                            ) : (
                              <span className="text-gray-400">—</span>
                            )}
                          </td>
                          <td className="p-2 text-right">{formatMoney(a.replacement_estimate)}</td>
                        </tr>
                      )
                    })}
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
