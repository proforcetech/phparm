import { useCallback, useEffect, useMemo, useState } from 'react'

import Alert from '../../components/ui/Alert'
import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Loading from '../../components/ui/Loading'
import Modal from '../../components/ui/Modal'
import Select from '../../components/ui/Select'
import Textarea from '../../components/ui/Textarea'
import pmService from '../../../services/pm.service'
import crmService from '../../../services/crm.service'
import { useToast } from '../../stores/toast.jsx'

const humanizeMetric = (key) => {
  return String(key)
    .replace(/_/g, ' ')
    .replace(/\b\w/g, (c) => c.toUpperCase())
}

const formatDateTime = (value) => {
  if (!value) return '—'
  const d = new Date(value)
  if (Number.isNaN(d.getTime())) return value
  return d.toLocaleString()
}

const relativeFromNow = (value) => {
  if (!value) return '—'
  const d = new Date(value)
  if (Number.isNaN(d.getTime())) return value
  const diffMs = d.getTime() - Date.now()
  const absMin = Math.round(Math.abs(diffMs) / 60000)
  const past = diffMs < 0
  if (absMin < 60) return past ? `${absMin}m ago` : `in ${absMin}m`
  const absHr = Math.round(absMin / 60)
  if (absHr < 48) return past ? `${absHr}h ago` : `in ${absHr}h`
  const absDay = Math.round(absHr / 24)
  return past ? `${absDay}d ago` : `in ${absDay}d`
}

const targetLabel = (row) => {
  const kind = row.target_kind || '—'
  const id = row.target_id ?? '—'
  return `${kind} #${id}`
}

const NUMERIC_KEYS_PREFERENCE = [
  'total_schedules',
  'on_time',
  'due_soon',
  'overdue',
  'at_risk',
]

export default function PmCompliance() {
  const { success, error } = useToast()

  const [companies, setCompanies] = useState([])
  const [companyId, setCompanyId] = useState('')
  const [loading, setLoading] = useState(true)
  const [pageError, setPageError] = useState('')
  const [summary, setSummary] = useState(null)
  const [rows, setRows] = useState([])

  const [completeTarget, setCompleteTarget] = useState(null)
  const [completeNotes, setCompleteNotes] = useState('')
  const [completing, setCompleting] = useState(false)

  useEffect(() => {
    crmService
      .listCompanies({ limit: 500 })
      .then((res) => setCompanies(Array.isArray(res) ? res : res?.data ?? []))
      .catch(() => setCompanies([]))
  }, [])

  const loadCompliance = useCallback(async () => {
    setLoading(true)
    try {
      let data
      if (companyId) {
        data = await pmService.companyCompliance(companyId)
      } else {
        data = await pmService.overdue()
      }
      const payload = data?.data ?? data
      // Normalise: payload may be array (overdue list) or object with summary + items
      if (Array.isArray(payload)) {
        setSummary(null)
        setRows(payload)
      } else if (payload && typeof payload === 'object') {
        const items = payload.items
          ?? payload.schedules
          ?? payload.overdue
          ?? payload.rows
          ?? []
        setRows(Array.isArray(items) ? items : [])
        // Strip non-numeric / non-summary keys for the cards
        const summaryObj = {}
        for (const [k, v] of Object.entries(payload)) {
          if (typeof v === 'number') summaryObj[k] = v
        }
        setSummary(Object.keys(summaryObj).length ? summaryObj : null)
      } else {
        setSummary(null)
        setRows([])
      }
      setPageError('')
    } catch (e) {
      setSummary(null)
      setRows([])
      setPageError(e?.response?.data?.message || e?.message || 'Failed to load compliance')
    } finally {
      setLoading(false)
    }
  }, [companyId])

  useEffect(() => { loadCompliance() }, [loadCompliance])

  const summaryEntries = useMemo(() => {
    if (!summary) return []
    const entries = Object.entries(summary)
    entries.sort(([a], [b]) => {
      const ai = NUMERIC_KEYS_PREFERENCE.indexOf(a)
      const bi = NUMERIC_KEYS_PREFERENCE.indexOf(b)
      if (ai === -1 && bi === -1) return a.localeCompare(b)
      if (ai === -1) return 1
      if (bi === -1) return -1
      return ai - bi
    })
    return entries
  }, [summary])

  const cardVariant = (key) => {
    if (key === 'overdue') return 'text-red-600'
    if (key === 'at_risk' || key === 'due_soon') return 'text-yellow-600'
    if (key === 'on_time') return 'text-green-600'
    return 'text-gray-900'
  }

  const openComplete = (row) => {
    setCompleteTarget(row)
    setCompleteNotes('')
  }

  const submitComplete = async () => {
    const generationId = completeTarget?.generation_id
    if (!generationId) return
    setCompleting(true)
    try {
      await pmService.completeGeneration(generationId, {
        notes: completeNotes?.trim() || undefined,
      })
      success('Generation completed')
      setCompleteTarget(null)
      setCompleteNotes('')
      loadCompliance()
    } catch (e) {
      error(e?.response?.data?.message || e?.message || 'Failed to complete generation')
    } finally {
      setCompleting(false)
    }
  }

  const daysOverdue = (row) => {
    if (typeof row.days_overdue === 'number') return row.days_overdue
    if (!row.next_due_at) return null
    const d = new Date(row.next_due_at)
    if (Number.isNaN(d.getTime())) return null
    const diffMs = Date.now() - d.getTime()
    if (diffMs <= 0) return 0
    return Math.floor(diffMs / 86400000)
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">PM Compliance</h1>
          <p className="mt-1 text-sm text-gray-500">
            Portfolio-wide overdue PM, or scoped to a single company.
          </p>
        </div>
        <div className="w-full sm:w-72">
          <Select
            label="Company"
            value={companyId}
            placeholder="All companies (overdue only)"
            onChange={(e) => setCompanyId(e.target.value)}
            options={companies.map((c) => ({ value: String(c.id), label: c.name || `Company #${c.id}` }))}
          />
        </div>
      </div>

      {pageError ? <Alert variant="danger" onClose={() => setPageError('')}>{pageError}</Alert> : null}

      {loading ? (
        <div className="py-10 flex justify-center"><Loading text="Loading compliance..." /></div>
      ) : (
        <>
          {summaryEntries.length > 0 ? (
            <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3">
              {summaryEntries.map(([key, value]) => (
                <Card key={key} padding>
                  <div className="text-xs uppercase text-gray-500 tracking-wide">
                    {humanizeMetric(key)}
                  </div>
                  <div className={`mt-2 text-2xl font-semibold ${cardVariant(key)}`}>
                    {value}
                  </div>
                </Card>
              ))}
            </div>
          ) : null}

          <Card padding={false}>
            <div className="px-4 py-3 border-b border-gray-200">
              <h2 className="text-sm font-medium text-gray-900">Overdue and At-Risk</h2>
            </div>
            {rows.length === 0 ? (
              <div className="py-10 text-center text-gray-500 text-sm">
                Nothing overdue. Nice.
              </div>
            ) : (
              <div className="overflow-x-auto">
                <table className="min-w-full divide-y divide-gray-200">
                  <thead className="bg-gray-50">
                    <tr>
                      <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Plan</th>
                      <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Target</th>
                      <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Next due</th>
                      <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Days overdue</th>
                      <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Last completed</th>
                      <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Owner</th>
                      <th className="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-gray-200 bg-white">
                    {rows.map((row, idx) => {
                      const overdueDays = daysOverdue(row)
                      const canComplete = !!row.generation_id
                      const owner = row.owner
                        || row.assignee
                        || row.assigned_to_name
                        || row.company_name
                        || '—'
                      return (
                        <tr key={row.id ?? row.schedule_id ?? row.generation_id ?? idx} className="hover:bg-gray-50">
                          <td className="px-4 py-3 text-sm">
                            <div className="font-medium text-gray-900">
                              {row.plan_name || row.plan?.name || `Plan #${row.plan_id ?? '—'}`}
                            </div>
                          </td>
                          <td className="px-4 py-3 text-sm text-gray-700">{targetLabel(row)}</td>
                          <td className="px-4 py-3 text-sm text-gray-700">
                            <div>{formatDateTime(row.next_due_at)}</div>
                            <div className="text-xs text-gray-500">{relativeFromNow(row.next_due_at)}</div>
                          </td>
                          <td className="px-4 py-3 text-sm">
                            {overdueDays != null && overdueDays > 0 ? (
                              <Badge variant="danger" size="sm">{overdueDays}d</Badge>
                            ) : overdueDays === 0 ? (
                              <Badge variant="warning" size="sm">due</Badge>
                            ) : (
                              <span className="text-gray-400">—</span>
                            )}
                          </td>
                          <td className="px-4 py-3 text-sm text-gray-500">{formatDateTime(row.last_completed_at)}</td>
                          <td className="px-4 py-3 text-sm text-gray-700">{owner}</td>
                          <td className="px-4 py-3 text-right">
                            <span title={canComplete ? '' : 'No generation_id available on this row'}>
                              <Button
                                size="sm"
                                variant="ghost"
                                disabled={!canComplete}
                                onClick={() => openComplete(row)}
                              >
                                Complete now
                              </Button>
                            </span>
                          </td>
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

      <Modal
        open={!!completeTarget}
        title="Complete PM Generation"
        onClose={() => { setCompleteTarget(null); setCompleteNotes('') }}
      >
        <div className="space-y-3">
          <p className="text-sm text-gray-600">
            Mark this generation as completed for{' '}
            <strong>{completeTarget?.plan_name || `Plan #${completeTarget?.plan_id ?? ''}`}</strong>{' '}
            on {completeTarget ? targetLabel(completeTarget) : ''}.
          </p>
          <Textarea
            label="Notes (optional)"
            value={completeNotes}
            onUpdateModelValue={setCompleteNotes}
            rows={3}
            placeholder="What was done, any follow-ups…"
          />
          <div className="flex justify-end gap-2">
            <Button variant="ghost" onClick={() => { setCompleteTarget(null); setCompleteNotes('') }}>
              Cancel
            </Button>
            <Button loading={completing} onClick={submitComplete}>Complete</Button>
          </div>
        </div>
      </Modal>
    </div>
  )
}
