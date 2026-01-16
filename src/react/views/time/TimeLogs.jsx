import { useCallback, useEffect, useMemo, useRef, useState } from 'react'

import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Table from '../../components/ui/Table'
import timeTrackingService from '../../../services/time-tracking.service'
import laborTasksService from '../../../services/labor-tasks.service'
import payrollExportService from '../../../services/payroll-export.service'

const perPage = 25

const statusVariant = (value) => {
  if (value === 'pending') return 'warning'
  if (value === 'rejected') return 'danger'
  return 'success'
}

const exportStatusVariant = (value) => {
  if (value === 'failed') return 'danger'
  if (value === 'empty') return 'warning'
  return 'success'
}

const statusLabel = (value) => {
  if (!value) return 'Unknown'
  return value.charAt(0).toUpperCase() + value.slice(1)
}

const formatDate = (value) => {
  if (!value) return '—'
  return new Date(value).toLocaleString()
}

const formatCurrency = (value) =>
  `$${Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`

const formatHours = (value) => `${Number(value || 0).toFixed(2)} hrs`

const formatLocation = (lat, lng) => {
  if (lat == null || lng == null) return '—'
  return `${Number(lat).toFixed(5)}, ${Number(lng).toFixed(5)}`
}

const efficiencyVariant = (percentage) => {
  if (percentage == null) return 'secondary'
  if (percentage >= 100) return 'success'
  if (percentage >= 80) return 'warning'
  return 'danger'
}

export default function TimeLogs() {
  const [loading, setLoading] = useState(false)
  const [entries, setEntries] = useState([])
  const [total, setTotal] = useState(0)
  const [currentPage, setCurrentPage] = useState(1)
  const [listError, setListError] = useState('')
  const [rateLimitNotice, setRateLimitNotice] = useState('')
  const [manualError, setManualError] = useState('')
  const [editError, setEditError] = useState('')
  const [savingManual, setSavingManual] = useState(false)
  const [savingEdit, setSavingEdit] = useState(false)
  const [selectedEntry, setSelectedEntry] = useState(null)
  const [reviewNotes, setReviewNotes] = useState('')
  const [reviewError, setReviewError] = useState('')
  const [reviewing, setReviewing] = useState(false)
  const [includeInPayroll, setIncludeInPayroll] = useState(true)
  const [laborTasks, setLaborTasks] = useState([])
  const [exportForm, setExportForm] = useState({
    provider: 'gusto',
    start_date: '',
    end_date: '',
  })
  const [exportingPayroll, setExportingPayroll] = useState(false)
  const [exportError, setExportError] = useState('')
  const [exportHistory, setExportHistory] = useState([])
  const [exportHistoryLoading, setExportHistoryLoading] = useState(false)
  const [exportHistoryError, setExportHistoryError] = useState('')

  const [filters, setFilters] = useState({
    search: '',
    start_date: '',
    end_date: '',
    technician_id: '',
  })

  const [manualForm, setManualForm] = useState({
    technician_id: '',
    estimate_job_id: '',
    task_id: '',
    started_at: '',
    ended_at: '',
    en_route_at: '',
    on_site_at: '',
    wrap_up_at: '',
    notes: '',
    manual_override: true,
    reason: '',
  })

  const [editForm, setEditForm] = useState({
    id: null,
    started_at: '',
    ended_at: '',
    en_route_at: '',
    on_site_at: '',
    wrap_up_at: '',
    estimate_job_id: '',
    task_id: '',
    notes: '',
    manual_override: true,
    reason: '',
  })

  const debounceRef = useRef(null)
  const inflightRequestRef = useRef(null)
  const retryUntilRef = useRef(0)

  const columns = useMemo(() => ([
    { key: 'technician', label: 'Technician' },
    { key: 'task', label: 'Task' },
    { key: 'window', label: 'Window' },
    { key: 'stages', label: 'Stages' },
    { key: 'efficiency', label: 'Time / Efficiency' },
    { key: 'location', label: 'Location' },
    { key: 'status', label: 'Status' },
    { key: 'context', label: 'Context' },
    { key: 'manual_override', label: 'Source' },
    { key: 'payroll', label: 'Payroll' },
    { key: 'adjustments', label: 'Adjustments' },
  ]), [])

  const exportColumns = useMemo(() => ([
    { key: 'provider', label: 'Provider' },
    { key: 'range', label: 'Date Range' },
    { key: 'totals', label: 'Totals' },
    { key: 'status', label: 'Status' },
    { key: 'created', label: 'Created' },
  ]), [])

  const refresh = useCallback(async (options = {}) => {
    const { force = false } = options ?? {}
    const now = Date.now()
    if (!force && retryUntilRef.current > now) {
      const remainingSeconds = Math.max(1, Math.ceil((retryUntilRef.current - now) / 1000))
      setRateLimitNotice(`Rate limit exceeded. Try again in ${remainingSeconds} seconds.`)
      return
    }

    const query = {
      ...filters,
      page: currentPage,
      per_page: perPage,
    }
    const queryKey = JSON.stringify(query)

    if (!force && inflightRequestRef.current?.key === queryKey) {
      return
    }

    if (inflightRequestRef.current?.controller) {
      inflightRequestRef.current.controller.abort()
    }

    const controller = new AbortController()
    inflightRequestRef.current = { controller, key: queryKey }
    setLoading(true)
    setListError('')
    setRateLimitNotice('')
    try {
      const response = await timeTrackingService.list(query, { signal: controller.signal })
      retryUntilRef.current = 0
      setEntries(response.data)
      setTotal(response.pagination.total)
      setCurrentPage(Math.floor(response.pagination.offset / response.pagination.limit) + 1)

      if (selectedEntry) {
        const updated = response.data.find((entry) => entry.id === selectedEntry.id)
        if (updated) {
          handleSelectEntry(updated)
        }
      }
    } catch (error) {
      if (error?.code === 'ERR_CANCELED') {
        return
      }

      const status = error.response?.status
      const retryAfterHeader = error.response?.headers?.['retry-after']
      const retryAfterPayload = error.response?.data?.retry_after
      const retryAfterSeconds = Number(retryAfterPayload ?? retryAfterHeader)

      if (status === 429) {
        if (Number.isFinite(retryAfterSeconds) && retryAfterSeconds > 0) {
          retryUntilRef.current = Date.now() + retryAfterSeconds * 1000
          setRateLimitNotice(`Rate limit exceeded. Try again in ${retryAfterSeconds} seconds.`)
        } else {
          setRateLimitNotice('Rate limit exceeded. Please try again shortly.')
        }
        return
      }

      setListError(error.response?.data?.message || 'Unable to load time entries. Check your filters and try again.')
    } finally {
      if (inflightRequestRef.current?.key === queryKey) {
        inflightRequestRef.current = null
      }
      setLoading(false)
    }
  }, [filters, currentPage, selectedEntry])

  useEffect(() => {
    refresh()
  }, [refresh])

  useEffect(() => {
    laborTasksService.getActiveTasks().then(setLaborTasks).catch(() => {})
  }, [])

  const loadExportHistory = useCallback(async () => {
    setExportHistoryLoading(true)
    setExportHistoryError('')
    try {
      const response = await payrollExportService.list({ per_page: 10 })
      setExportHistory(response.data)
    } catch (error) {
      setExportHistoryError(error.response?.data?.message || 'Unable to load payroll export history.')
    } finally {
      setExportHistoryLoading(false)
    }
  }, [])

  useEffect(() => {
    loadExportHistory()
  }, [loadExportHistory])

  useEffect(() => () => {
    if (inflightRequestRef.current?.controller) {
      inflightRequestRef.current.controller.abort()
    }
  }, [])

  const handleSelectEntry = (row) => {
    setSelectedEntry(row)
    setEditForm({
      id: row.id,
      started_at: row.started_at?.slice(0, 16) || '',
      ended_at: row.ended_at?.slice(0, 16) || '',
      en_route_at: row.en_route_at?.slice(0, 16) || '',
      on_site_at: row.on_site_at?.slice(0, 16) || '',
      wrap_up_at: row.wrap_up_at?.slice(0, 16) || '',
      estimate_job_id: row.estimate_job_id || '',
      task_id: row.task_id || '',
      notes: row.notes || '',
      manual_override: row.manual_override,
      reason: '',
    })
    setReviewNotes('')
    setReviewError('')
    setIncludeInPayroll(row.status === 'pending' ? true : Boolean(row.payroll_included))
  }

  const debouncedRefresh = () => {
    if (debounceRef.current) {
      clearTimeout(debounceRef.current)
    }
    debounceRef.current = setTimeout(() => refresh(), 300)
  }

  const updateFilter = (key, value) => {
    setCurrentPage(1)
    setFilters((prev) => ({ ...prev, [key]: value }))
  }

  const submitManual = async (event) => {
    event.preventDefault()
    setManualError('')
    setSavingManual(true)
    try {
      await timeTrackingService.create({
        technician_id: manualForm.technician_id,
        estimate_job_id: manualForm.estimate_job_id || null,
        task_id: manualForm.task_id || null,
        started_at: manualForm.started_at,
        ended_at: manualForm.ended_at,
        en_route_at: manualForm.en_route_at || null,
        on_site_at: manualForm.on_site_at || null,
        wrap_up_at: manualForm.wrap_up_at || null,
        notes: manualForm.notes,
        manual_override: manualForm.manual_override,
        reason: manualForm.reason,
      })
      refresh()
      setManualForm({
        technician_id: '',
        estimate_job_id: '',
        task_id: '',
        started_at: '',
        ended_at: '',
        en_route_at: '',
        on_site_at: '',
        wrap_up_at: '',
        notes: '',
        manual_override: true,
        reason: '',
      })
    } catch (error) {
      setManualError(error.response?.data?.message || 'Unable to save entry')
    } finally {
      setSavingManual(false)
    }
  }

  const submitUpdate = async (event) => {
    event.preventDefault()
    if (!editForm.id) return
    setEditError('')
    setSavingEdit(true)
    try {
      await timeTrackingService.update(editForm.id, {
        started_at: editForm.started_at,
        ended_at: editForm.ended_at || null,
        en_route_at: editForm.en_route_at || null,
        on_site_at: editForm.on_site_at || null,
        wrap_up_at: editForm.wrap_up_at || null,
        estimate_job_id: editForm.estimate_job_id || null,
        task_id: editForm.task_id || null,
        notes: editForm.notes,
        manual_override: editForm.manual_override,
        reason: editForm.reason,
      })
      refresh()
      setEditForm((prev) => ({ ...prev, reason: '' }))
    } catch (error) {
      setEditError(error.response?.data?.message || 'Unable to update entry')
    } finally {
      setSavingEdit(false)
    }
  }

  const review = async (decision) => {
    if (!selectedEntry) return
    setReviewError('')
    setReviewing(true)
    try {
      const payload = reviewNotes ? { notes: reviewNotes } : {}
      if (decision === 'approved') {
        await timeTrackingService.approve(selectedEntry.id, {
          ...payload,
          include_in_payroll: includeInPayroll,
        })
      } else {
        await timeTrackingService.reject(selectedEntry.id, payload)
      }
      setReviewNotes('')
      await refresh()
    } catch (error) {
      setReviewError(error.response?.data?.message || 'Unable to update status')
    } finally {
      setReviewing(false)
    }
  }

  const submitPayrollExport = async (event) => {
    event.preventDefault()
    setExportError('')
    setExportingPayroll(true)
    try {
      const response = await payrollExportService.exportPayroll({
        provider: exportForm.provider,
        start_date: exportForm.start_date,
        end_date: exportForm.end_date,
      })
      const type = 'text/csv;charset=utf-8;'
      const blob = new Blob([response.data || ''], { type })
      const link = document.createElement('a')
      link.href = window.URL.createObjectURL(blob)
      link.setAttribute('download', response.filename || 'payroll-export.csv')
      document.body.appendChild(link)
      link.click()
      link.remove()
      await loadExportHistory()
    } catch (error) {
      setExportError(error.response?.data?.message || 'Unable to export payroll data.')
    } finally {
      setExportingPayroll(false)
    }
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Time Logs</h1>
          <p className="text-sm text-gray-600">Filter technician activity with adjustment history.</p>
        </div>
        <div className="flex gap-2">
          <Button variant="secondary" onClick={refresh}>Refresh</Button>
        </div>
      </div>

      {listError ? <p className="text-sm text-red-600">{listError}</p> : null}
      {rateLimitNotice ? <p className="text-sm text-amber-600">{rateLimitNotice}</p> : null}

      <Card>
        <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
          <div>
            <h2 className="text-lg font-semibold text-gray-900">Payroll exports</h2>
            <p className="text-sm text-gray-600">Generate Gusto, ADP, or QuickBooks files with approved hours and gross pay.</p>
          </div>
        </div>
        <form className="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-5" onSubmit={submitPayrollExport}>
          <div>
            <label className="block text-sm font-medium text-gray-700">Provider</label>
            <select
              value={exportForm.provider}
              onChange={(event) => setExportForm((prev) => ({ ...prev, provider: event.target.value }))}
              className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
            >
              <option value="gusto">Gusto</option>
              <option value="adp">ADP</option>
              <option value="quickbooks">QuickBooks</option>
            </select>
          </div>
          <Input
            modelValue={exportForm.start_date}
            type="date"
            label="Start Date"
            required
            onUpdateModelValue={(value) => setExportForm((prev) => ({ ...prev, start_date: value }))}
          />
          <Input
            modelValue={exportForm.end_date}
            type="date"
            label="End Date"
            required
            onUpdateModelValue={(value) => setExportForm((prev) => ({ ...prev, end_date: value }))}
          />
          <div className="flex items-end">
            <Button type="submit" loading={exportingPayroll}>Export CSV</Button>
          </div>
          <div className="flex items-end">
            {exportError ? <p className="text-sm text-red-600">{exportError}</p> : null}
          </div>
        </form>

        <div className="mt-6">
          <div className="flex items-center justify-between">
            <h3 className="text-base font-semibold text-gray-900">Export history & status</h3>
            <Button variant="secondary" size="sm" onClick={loadExportHistory}>Refresh</Button>
          </div>
          {exportHistoryError ? <p className="mt-2 text-sm text-red-600">{exportHistoryError}</p> : null}
          <div className="mt-3 hidden md:block">
            <Table
              columns={exportColumns}
              data={exportHistory}
              loading={exportHistoryLoading}
              hoverable
              cellRenderers={{
                provider: ({ value }) => <span className="font-medium text-gray-900">{value?.toUpperCase()}</span>,
                range: ({ row }) => (
                  <div className="text-sm text-gray-700">
                    <div>{row.start_date} → {row.end_date}</div>
                    <div className="text-xs text-gray-500">Rows: {row.row_count}</div>
                  </div>
                ),
                totals: ({ row }) => (
                  <div className="text-sm text-gray-700">
                    <div>{formatHours(row.total_hours)}</div>
                    <div className="text-xs text-gray-500">{formatCurrency(row.total_gross_pay)}</div>
                  </div>
                ),
                status: ({ value, row }) => (
                  <div className="flex flex-col gap-1">
                    <Badge variant={exportStatusVariant(value)} size="sm">{value}</Badge>
                    {row.error_message ? <span className="text-xs text-red-500">{row.error_message}</span> : null}
                  </div>
                ),
                created: ({ row }) => (
                  <div className="text-xs text-gray-600">
                    <div>{formatDate(row.created_at)}</div>
                    <div>{row.created_by_name || (row.created_by ? `User #${row.created_by}` : 'System')}</div>
                  </div>
                ),
              }}
            />
          </div>

          <div className="mt-3 space-y-3 md:hidden">
            {exportHistory.map((entry) => (
              <div key={entry.id} className="rounded border border-gray-200 bg-gray-50 p-3">
                <div className="flex items-start justify-between">
                  <div>
                    <p className="text-sm font-semibold text-gray-900">{entry.provider?.toUpperCase()}</p>
                    <p className="text-xs text-gray-600">{entry.start_date} → {entry.end_date}</p>
                  </div>
                  <Badge variant={exportStatusVariant(entry.status)} size="sm">{entry.status}</Badge>
                </div>
                <div className="mt-2 text-xs text-gray-600">
                  <div>{formatHours(entry.total_hours)} · {formatCurrency(entry.total_gross_pay)}</div>
                  <div>Rows: {entry.row_count}</div>
                  <div>{formatDate(entry.created_at)}</div>
                </div>
                {entry.error_message ? <div className="mt-1 text-xs text-red-500">{entry.error_message}</div> : null}
              </div>
            ))}
            {exportHistory.length === 0 && !exportHistoryLoading ? (
              <div className="py-4 text-center text-sm text-gray-500">No payroll exports yet.</div>
            ) : null}
            {exportHistoryLoading ? <div className="py-4 text-center text-sm text-gray-500">Loading...</div> : null}
          </div>
        </div>
      </Card>

      <Card>
        <div className="grid grid-cols-1 gap-4 md:grid-cols-5">
          <div className="md:col-span-2">
            <label className="block text-sm font-medium text-gray-700">Search</label>
            <Input
              modelValue={filters.search}
              placeholder="Technician, job, or customer"
              onUpdateModelValue={(value) => {
                updateFilter('search', value)
                debouncedRefresh()
              }}
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700">Technician ID</label>
            <Input
              modelValue={filters.technician_id}
              placeholder="123"
              onUpdateModelValue={(value) => {
                updateFilter('technician_id', value)
                debouncedRefresh()
              }}
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700">Start Date</label>
            <Input
              modelValue={filters.start_date}
              type="date"
              onUpdateModelValue={(value) => {
                updateFilter('start_date', value)
                refresh()
              }}
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700">End Date</label>
            <Input
              modelValue={filters.end_date}
              type="date"
              onUpdateModelValue={(value) => {
                updateFilter('end_date', value)
                refresh()
              }}
            />
          </div>
        </div>
      </Card>

      <Card>
        <div className="hidden md:block">
          <Table
            columns={columns}
            data={entries}
            loading={loading}
            hoverable
            pagination
            perPage={perPage}
            total={total}
            currentPage={currentPage}
            onPageChange={(page) => {
              setCurrentPage(page)
            }}
            onRowClick={handleSelectEntry}
            cellRenderers={{
              technician: ({ row }) => (
                <div>
                  <p className="font-semibold text-gray-900">{row.technician_name || `Tech #${row.technician_id}`}</p>
                  <p className="text-xs text-gray-500">Job: {row.job_title || 'Unassigned'}</p>
                </div>
              ),
              task: ({ row }) => (
                <div>
                  {row.task_name ? (
                    <>
                      <p className="font-medium text-gray-900">{row.task_name}</p>
                      <p className="text-xs text-gray-500">Flat rate: {row.flat_rate_minutes} mins</p>
                    </>
                  ) : (
                    <span className="text-xs text-gray-400">No task</span>
                  )}
                </div>
              ),
              window: ({ row }) => (
                <div className="text-sm text-gray-900">
                  <div>Start: {formatDate(row.started_at)}</div>
                  {row.ended_at ? <div>End: {formatDate(row.ended_at)}</div> : <div className="text-amber-700">Active</div>}
                </div>
              ),
              stages: ({ row }) => (
                <div className="text-xs text-gray-700">
                  <div>En route: {formatDate(row.en_route_at)}</div>
                  <div>On site: {formatDate(row.on_site_at)}</div>
                  <div>Wrap up: {formatDate(row.wrap_up_at)}</div>
                </div>
              ),
              efficiency: ({ row }) => (
                <div className="text-sm">
                  <p className="font-semibold text-gray-900">{Number(row.duration_minutes ?? 0).toFixed(2)} mins</p>
                  {row.flat_rate_minutes && row.flat_rate_minutes > 0 ? (
                    <div className="flex items-center gap-1 mt-1">
                      <Badge variant={efficiencyVariant(row.efficiency_percentage)} size="sm">
                        {row.efficiency_percentage != null ? `${row.efficiency_percentage}%` : 'N/A'}
                      </Badge>
                      <span className="text-xs text-gray-500">vs {row.flat_rate_minutes} mins</span>
                    </div>
                  ) : null}
                </div>
              ),
              location: ({ row }) => (
                <div className="text-xs text-gray-700">
                  {row.is_mobile ? (
                    <>
                      <div>Start: {formatLocation(row.start_latitude, row.start_longitude)}</div>
                      <div>End: {row.ended_at ? formatLocation(row.end_latitude, row.end_longitude) : '—'}</div>
                    </>
                  ) : (
                    <div className="text-gray-500">In-shop</div>
                  )}
                </div>
              ),
              status: ({ value, row }) => (
                <div className="flex items-center gap-2">
                  <Badge variant={statusVariant(value)} size="sm">{statusLabel(value)}</Badge>
                  {row.reviewed_at ? (
                    <span className="text-xs text-gray-500">
                      {row.reviewer_name || `User #${row.reviewed_by}`}
                    </span>
                  ) : null}
                </div>
              ),
              context: ({ row }) => (
                <div className="text-sm text-gray-800">
                  <div className="text-xs text-gray-500">Estimate: {row.estimate_number || '—'}</div>
                  <div className="text-xs text-gray-500">Customer: {row.customer_name || '—'}</div>
                  <div className="text-xs text-gray-500">Vehicle: {row.vehicle_vin || '—'}</div>
                </div>
              ),
              manual_override: ({ value }) => (
                <Badge variant={value ? 'warning' : 'secondary'}>{value ? 'Manual' : 'Timer'}</Badge>
              ),
              payroll: ({ row }) => {
                if (row.status !== 'approved') {
                  return <Badge variant="secondary">Not approved</Badge>
                }
                return row.payroll_included
                  ? <Badge variant="success">Included</Badge>
                  : <Badge variant="warning">Excluded</Badge>
              },
              adjustments: ({ row }) => (
                <div className="space-y-2">
                  {row.adjustments?.length === 0 ? (
                    <div className="text-xs text-gray-500">No adjustments</div>
                  ) : (
                    row.adjustments?.map((adj) => (
                      <div key={adj.id} className="rounded bg-gray-50 p-2">
                        <div className="text-xs font-semibold text-gray-800">{adj.actor_name || `User #${adj.actor_id}`}</div>
                        <div className="text-xs text-gray-600">{adj.reason}</div>
                        <div className="text-[11px] text-gray-500">{formatDate(adj.created_at)}</div>
                      </div>
                    ))
                  )}
                </div>
              ),
            }}
          />
        </div>

        <div className="space-y-3 md:hidden">
          {entries.map((row) => (
            <div
              key={row.id}
              className="rounded border border-gray-200 bg-gray-50 p-4 shadow-sm"
              onClick={() => handleSelectEntry(row)}
            >
              <div className="flex items-start justify-between gap-3">
                <div>
                  <p className="text-base font-semibold text-gray-900">{row.technician_name || `Tech #${row.technician_id}`}</p>
                  <p className="text-xs text-gray-600">Job: {row.job_title || 'Unassigned'}</p>
                </div>
                <div className="flex flex-col items-end gap-1">
                  <Badge variant={row.manual_override ? 'warning' : 'secondary'}>{row.manual_override ? 'Manual' : 'Timer'}</Badge>
                  <Badge variant={statusVariant(row.status)} size="sm">{statusLabel(row.status)}</Badge>
                  {row.status === 'approved' ? (
                    <Badge variant={row.payroll_included ? 'success' : 'warning'} size="sm">
                      {row.payroll_included ? 'Payroll included' : 'Payroll excluded'}
                    </Badge>
                  ) : null}
                </div>
              </div>
              {row.task_name ? (
                <div className="mt-2 text-sm text-gray-800">
                  <span className="font-medium">{row.task_name}</span>
                  <span className="text-xs text-gray-500 ml-2">({row.flat_rate_minutes} mins flat rate)</span>
                </div>
              ) : null}
              <div className="mt-2 text-sm text-gray-800">
                <div className="flex items-center gap-2">
                  <span className="font-semibold">{Number(row.duration_minutes ?? 0).toFixed(2)} mins</span>
                  {row.efficiency_percentage != null ? (
                    <Badge variant={efficiencyVariant(row.efficiency_percentage)} size="sm">
                      {row.efficiency_percentage}% efficiency
                    </Badge>
                  ) : null}
                </div>
                <div className="text-xs text-gray-600">Start: {formatDate(row.started_at)}</div>
                <div className="text-xs text-gray-600">
                  End:{' '}
                  <span className={row.ended_at ? '' : 'text-amber-700'}>
                    {row.ended_at ? formatDate(row.ended_at) : 'Active'}
                  </span>
                </div>
                <div className="text-xs text-gray-600">
                  Location:{' '}
                  {row.is_mobile
                    ? `${formatLocation(row.start_latitude, row.start_longitude)} → ${row.ended_at ? formatLocation(row.end_latitude, row.end_longitude) : '—'}`
                    : 'In-shop'}
                </div>
                <div className="text-xs text-gray-600">En route: {formatDate(row.en_route_at)}</div>
                <div className="text-xs text-gray-600">On site: {formatDate(row.on_site_at)}</div>
                <div className="text-xs text-gray-600">Wrap up: {formatDate(row.wrap_up_at)}</div>
              </div>
              <div className="mt-2 space-y-1 text-xs text-gray-600">
                <div>Estimate: {row.estimate_number || '—'}</div>
                <div>Customer: {row.customer_name || '—'}</div>
                <div>Vehicle: {row.vehicle_vin || '—'}</div>
              </div>
              <div className="mt-3 space-y-2">
                {row.adjustments?.length === 0 ? (
                  <div className="text-xs text-gray-500">No adjustments</div>
                ) : (
                  row.adjustments?.map((adj) => (
                    <div key={adj.id} className="rounded bg-white p-2">
                      <div className="text-xs font-semibold text-gray-800">{adj.actor_name || `User #${adj.actor_id}`}</div>
                      <div className="text-xs text-gray-600">{adj.reason}</div>
                      <div className="text-[11px] text-gray-500">{formatDate(adj.created_at)}</div>
                    </div>
                  ))
                )}
              </div>
            </div>
          ))}
          {entries.length === 0 && !loading ? (
            <div className="py-4 text-center text-sm text-gray-500">No entries found.</div>
          ) : null}
          {loading ? <div className="py-4 text-center text-sm text-gray-500">Loading...</div> : null}
        </div>
      </Card>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <Card>
          <h3 className="text-lg font-semibold text-gray-900">Add manual entry</h3>
          <p className="mt-1 text-sm text-gray-600">Manual submissions default to pending until reviewed.</p>
          <form className="mt-4 space-y-3" onSubmit={submitManual}>
            <div className="grid grid-cols-1 gap-3 md:grid-cols-3">
              <Input
                modelValue={manualForm.technician_id}
                label="Technician ID"
                required
                onUpdateModelValue={(value) => setManualForm((prev) => ({ ...prev, technician_id: value }))}
              />
              <Input
                modelValue={manualForm.estimate_job_id}
                label="Estimate Job ID"
                onUpdateModelValue={(value) => setManualForm((prev) => ({ ...prev, estimate_job_id: value }))}
              />
              <div>
                <label className="block text-sm font-medium text-gray-700">Task (for efficiency tracking)</label>
                <select
                  value={manualForm.task_id}
                  onChange={(e) => setManualForm((prev) => ({ ...prev, task_id: e.target.value }))}
                  className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                >
                  <option value="">No task selected</option>
                  {laborTasks.map((task) => (
                    <option key={task.id} value={task.id}>
                      {task.name} ({task.flat_rate_minutes} mins)
                    </option>
                  ))}
                </select>
              </div>
            </div>
            <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
              <Input
                modelValue={manualForm.started_at}
                type="datetime-local"
                label="Started"
                required
                onUpdateModelValue={(value) => setManualForm((prev) => ({ ...prev, started_at: value }))}
              />
              <Input
                modelValue={manualForm.ended_at}
                type="datetime-local"
                label="Ended"
                required
                onUpdateModelValue={(value) => setManualForm((prev) => ({ ...prev, ended_at: value }))}
              />
            </div>
            <div className="grid grid-cols-1 gap-3 md:grid-cols-3">
              <Input
                modelValue={manualForm.en_route_at}
                type="datetime-local"
                label="En Route"
                onUpdateModelValue={(value) => setManualForm((prev) => ({ ...prev, en_route_at: value }))}
              />
              <Input
                modelValue={manualForm.on_site_at}
                type="datetime-local"
                label="On Site"
                onUpdateModelValue={(value) => setManualForm((prev) => ({ ...prev, on_site_at: value }))}
              />
              <Input
                modelValue={manualForm.wrap_up_at}
                type="datetime-local"
                label="Wrap Up"
                onUpdateModelValue={(value) => setManualForm((prev) => ({ ...prev, wrap_up_at: value }))}
              />
            </div>
            <Input
              modelValue={manualForm.reason}
              label="Adjustment Reason"
              required
              onUpdateModelValue={(value) => setManualForm((prev) => ({ ...prev, reason: value }))}
            />
            <textarea
              value={manualForm.notes}
              onChange={(event) => setManualForm((prev) => ({ ...prev, notes: event.target.value }))}
              className="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
              rows={3}
              placeholder="Notes"
            />
            <label className="inline-flex items-center gap-2 text-sm text-gray-700">
              <input
                checked={manualForm.manual_override}
                onChange={(event) => setManualForm((prev) => ({ ...prev, manual_override: event.target.checked }))}
                type="checkbox"
                className="h-4 w-4 rounded border-gray-300 text-indigo-600"
              />
              Manual override
            </label>
            <div className="flex gap-2">
              <Button type="submit" loading={savingManual}>Save Entry</Button>
              {manualError ? <p className="text-sm text-red-600">{manualError}</p> : null}
            </div>
          </form>
        </Card>

        <Card>
          <div className="flex items-center justify-between">
            <h3 className="text-lg font-semibold text-gray-900">Edit selected entry</h3>
            {selectedEntry ? <Badge variant="secondary">ID {selectedEntry.id}</Badge> : null}
          </div>
          <p className="mt-1 text-sm text-gray-600">Click a row to load it into the editor.</p>

          {selectedEntry ? (
            <div className="mt-3 space-y-2 rounded border border-gray-200 bg-gray-50 p-3">
              <div className="flex items-center justify-between gap-3">
                <div className="flex items-center gap-2">
                  <Badge variant={statusVariant(selectedEntry.status)} size="sm" rounded>
                    {statusLabel(selectedEntry.status)}
                  </Badge>
                  {selectedEntry.manual_override ? <Badge variant="warning" size="sm">Manual</Badge> : null}
                </div>
                {selectedEntry.reviewed_at ? (
                  <span className="text-xs text-gray-600">
                    {selectedEntry.reviewer_name || `User #${selectedEntry.reviewed_by}`} · {formatDate(selectedEntry.reviewed_at)}
                  </span>
                ) : null}
              </div>
              <p className="text-xs text-gray-600">
                {selectedEntry.status === 'pending'
                  ? 'Pending entries are excluded from billable totals until approval.'
                  : selectedEntry.review_notes || 'Reviewed'}
              </p>
              {selectedEntry.status === 'pending' ? (
                <div className="space-y-2 pt-1">
                  <textarea
                    value={reviewNotes}
                    onChange={(event) => setReviewNotes(event.target.value)}
                    className="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                    rows={2}
                    placeholder="Review notes (optional)"
                  />
                  <label className="inline-flex items-center gap-2 text-xs text-gray-700">
                    <input
                      checked={includeInPayroll}
                      onChange={(event) => setIncludeInPayroll(event.target.checked)}
                      type="checkbox"
                      className="h-4 w-4 rounded border-gray-300 text-indigo-600"
                    />
                    Include approved hours in the next payroll run
                  </label>
                  <div className="flex flex-wrap gap-2">
                    <Button variant="primary" size="sm" loading={reviewing} onClick={() => review('approved')}>Approve</Button>
                    <Button variant="danger" size="sm" loading={reviewing} onClick={() => review('rejected')}>Reject</Button>
                  </div>
                </div>
              ) : null}
              {selectedEntry.status === 'approved' ? (
                <p className="text-xs text-gray-600">
                  Payroll: {selectedEntry.payroll_included ? 'Included' : 'Excluded'}
                </p>
              ) : null}
              {selectedEntry.status !== 'pending' && selectedEntry.review_notes ? (
                <p className="text-xs text-gray-600">Notes: {selectedEntry.review_notes}</p>
              ) : null}
              {reviewError ? <p className="text-sm text-red-600">{reviewError}</p> : null}
            </div>
          ) : null}

          <form className="mt-4 space-y-3" onSubmit={submitUpdate}>
            <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
              <Input
                modelValue={editForm.started_at}
                type="datetime-local"
                label="Started"
                disabled={!selectedEntry}
                required
                onUpdateModelValue={(value) => setEditForm((prev) => ({ ...prev, started_at: value }))}
              />
              <Input
                modelValue={editForm.ended_at}
                type="datetime-local"
                label="Ended"
                disabled={!selectedEntry}
                onUpdateModelValue={(value) => setEditForm((prev) => ({ ...prev, ended_at: value }))}
              />
            </div>
            <div className="grid grid-cols-1 gap-3 md:grid-cols-3">
              <Input
                modelValue={editForm.en_route_at}
                type="datetime-local"
                label="En Route"
                disabled={!selectedEntry}
                onUpdateModelValue={(value) => setEditForm((prev) => ({ ...prev, en_route_at: value }))}
              />
              <Input
                modelValue={editForm.on_site_at}
                type="datetime-local"
                label="On Site"
                disabled={!selectedEntry}
                onUpdateModelValue={(value) => setEditForm((prev) => ({ ...prev, on_site_at: value }))}
              />
              <Input
                modelValue={editForm.wrap_up_at}
                type="datetime-local"
                label="Wrap Up"
                disabled={!selectedEntry}
                onUpdateModelValue={(value) => setEditForm((prev) => ({ ...prev, wrap_up_at: value }))}
              />
            </div>
            <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
              <Input
                modelValue={editForm.estimate_job_id}
                label="Estimate Job ID"
                disabled={!selectedEntry}
                onUpdateModelValue={(value) => setEditForm((prev) => ({ ...prev, estimate_job_id: value }))}
              />
              <div>
                <label className="block text-sm font-medium text-gray-700">Task (for efficiency tracking)</label>
                <select
                  value={editForm.task_id}
                  onChange={(e) => setEditForm((prev) => ({ ...prev, task_id: e.target.value }))}
                  disabled={!selectedEntry}
                  className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm disabled:bg-gray-100"
                >
                  <option value="">No task selected</option>
                  {laborTasks.map((task) => (
                    <option key={task.id} value={task.id}>
                      {task.name} ({task.flat_rate_minutes} mins)
                    </option>
                  ))}
                </select>
              </div>
            </div>
            <Input
              modelValue={editForm.reason}
              label="Adjustment Reason"
              disabled={!selectedEntry}
              required
              onUpdateModelValue={(value) => setEditForm((prev) => ({ ...prev, reason: value }))}
            />
            <textarea
              value={editForm.notes}
              onChange={(event) => setEditForm((prev) => ({ ...prev, notes: event.target.value }))}
              className="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
              rows={3}
              disabled={!selectedEntry}
              placeholder="Notes"
            />
            <label className="inline-flex items-center gap-2 text-sm text-gray-700">
              <input
                checked={editForm.manual_override}
                onChange={(event) => setEditForm((prev) => ({ ...prev, manual_override: event.target.checked }))}
                type="checkbox"
                className="h-4 w-4 rounded border-gray-300 text-indigo-600"
                disabled={!selectedEntry}
              />
              Manual override
            </label>
            <div className="flex gap-2">
              <Button type="submit" disabled={!selectedEntry} loading={savingEdit}>Update Entry</Button>
              {editError ? <p className="text-sm text-red-600">{editError}</p> : null}
            </div>
          </form>
        </Card>
      </div>
    </div>
  )
}
