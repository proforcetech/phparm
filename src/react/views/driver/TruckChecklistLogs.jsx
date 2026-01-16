import { useCallback, useEffect, useMemo, useState } from 'react'

import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Table from '../../components/ui/Table'
import truckChecklistService from '../../../services/truck-checklist.service'

const checklistLabels = {
  pre_trip: 'Pre-trip',
  post_trip: 'Post-trip',
}

const perPage = 25

const formatDate = (value) => {
  if (!value) return '—'
  return new Date(value).toLocaleString()
}

export default function TruckChecklistLogs() {
  const [loading, setLoading] = useState(false)
  const [entries, setEntries] = useState([])
  const [total, setTotal] = useState(0)
  const [currentPage, setCurrentPage] = useState(1)
  const [filters, setFilters] = useState({
    checklist_type: '',
    start_date: '',
    end_date: '',
  })
  const [error, setError] = useState('')

  const columns = useMemo(() => ([
    { key: 'driver_name', label: 'Driver' },
    { key: 'type', label: 'Type' },
    { key: 'template', label: 'Template' },
    { key: 'shift', label: 'Shift Window' },
    { key: 'completed_at', label: 'Completed At' },
  ]), [])

  const loadEntries = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      const response = await truckChecklistService.listEntries({
        ...filters,
        page: currentPage,
        per_page: perPage,
      })
      setEntries(response.data ?? [])
      setTotal(response.pagination?.total ?? 0)
    } catch (err) {
      setError(err.response?.data?.message || 'Unable to load checklist logs.')
    } finally {
      setLoading(false)
    }
  }, [filters, currentPage])

  useEffect(() => {
    loadEntries()
  }, [loadEntries])

  const pageCount = Math.max(1, Math.ceil(total / perPage))

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-gray-900">Truck Checklist Logs</h1>
        <p className="text-sm text-gray-600">Review completed pre-trip and post-trip checklists for compliance.</p>
      </div>

      {error ? <div className="text-sm text-red-600">{error}</div> : null}

      <Card title="Filters">
        <div className="grid gap-4 md:grid-cols-4">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Checklist type</label>
            <select
              className="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm"
              value={filters.checklist_type}
              onChange={(event) => {
                setFilters((prev) => ({ ...prev, checklist_type: event.target.value }))
                setCurrentPage(1)
              }}
            >
              <option value="">All types</option>
              <option value="pre_trip">Pre-trip</option>
              <option value="post_trip">Post-trip</option>
            </select>
          </div>
          <Input
            label="Start date"
            type="date"
            value={filters.start_date}
            onChange={(event) => {
              setFilters((prev) => ({ ...prev, start_date: event.target.value }))
              setCurrentPage(1)
            }}
          />
          <Input
            label="End date"
            type="date"
            value={filters.end_date}
            onChange={(event) => {
              setFilters((prev) => ({ ...prev, end_date: event.target.value }))
              setCurrentPage(1)
            }}
          />
          <div className="flex items-end">
            <Button variant="outline" onClick={() => loadEntries()}>
              Refresh
            </Button>
          </div>
        </div>
      </Card>

      <Card>
        <Table
          columns={columns}
          data={entries.map((entry) => ({
            id: entry.id,
            driver_name: entry.driver_name || `Driver #${entry.driver_profile_id}`,
            type: checklistLabels[entry.checklist_type] || entry.checklist_type,
            template: entry.template_name || '—',
            shift: entry.shift_start ? `${formatDate(entry.shift_start)} → ${formatDate(entry.shift_end)}` : '—',
            completed_at: formatDate(entry.completed_at),
          }))}
          loading={loading}
          renderEmpty={<p className="text-sm">No checklist entries found.</p>}
        />

        <div className="mt-4 flex items-center justify-between text-sm text-gray-600">
          <span>
            {total === 0
              ? 'Showing 0 of 0'
              : `Showing ${(currentPage - 1) * perPage + 1} - ${Math.min(currentPage * perPage, total)} of ${total}`}
          </span>
          <div className="flex items-center gap-2">
            <Button
              variant="outline"
              size="sm"
              disabled={currentPage <= 1}
              onClick={() => setCurrentPage((prev) => Math.max(1, prev - 1))}
            >
              Previous
            </Button>
            <span>
              Page {currentPage} of {pageCount}
            </span>
            <Button
              variant="outline"
              size="sm"
              disabled={currentPage >= pageCount}
              onClick={() => setCurrentPage((prev) => Math.min(pageCount, prev + 1))}
            >
              Next
            </Button>
          </div>
        </div>
      </Card>
    </div>
  )
}
