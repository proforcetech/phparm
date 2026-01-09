import { useCallback, useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'

import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import customerRetentionService from '../../../services/customer-retention.service'
import { useToast } from '../../stores/toast.jsx'

const perPage = 25

export default function CustomerRetentionReport() {
  const toast = useToast()
  const [loading, setLoading] = useState(true)
  const [rows, setRows] = useState([])
  const [page, setPage] = useState(1)
  const [total, setTotal] = useState(0)
  const [months, setMonths] = useState(6)
  const [query, setQuery] = useState('')
  const [exportFormat, setExportFormat] = useState('csv')
  const [campaignName, setCampaignName] = useState('Customer Retention')
  const [dispatching, setDispatching] = useState(false)
  const [exporting, setExporting] = useState(false)

  const loadReport = useCallback(async () => {
    setLoading(true)
    try {
      const params = {
        months,
        limit: perPage,
        offset: (page - 1) * perPage,
      }
      if (query.trim()) {
        params.query = query.trim()
      }
      const response = await customerRetentionService.list(params)
      setRows(response?.data || [])
      setTotal(response?.total || 0)
    } catch {
      toast.error('Failed to load customer retention report')
      setRows([])
      setTotal(0)
    } finally {
      setLoading(false)
    }
  }, [months, page, query, toast])

  useEffect(() => {
    loadReport()
  }, [loadReport])

  const handleRefresh = (event) => {
    event.preventDefault()
    setPage(1)
    loadReport()
  }

  const exportReport = async () => {
    setExporting(true)
    try {
      const params = { months, format: exportFormat }
      if (query.trim()) {
        params.query = query.trim()
      }
      const response = await customerRetentionService.exportReport(params)
      const type = exportFormat === 'json' ? 'application/json' : 'text/csv;charset=utf-8;'
      const blob = new Blob([response.data], { type })
      const url = URL.createObjectURL(blob)
      const link = document.createElement('a')
      link.href = url
      link.setAttribute('download', response.filename || `customer-retention.${exportFormat}`)
      document.body.appendChild(link)
      link.click()
      document.body.removeChild(link)
      URL.revokeObjectURL(url)
    } catch {
      toast.error('Failed to export report')
    } finally {
      setExporting(false)
    }
  }

  const dispatchCampaign = async () => {
    setDispatching(true)
    try {
      const payload = {
        months,
        campaign_name: campaignName,
      }
      if (query.trim()) {
        payload.query = query.trim()
      }
      const response = await customerRetentionService.dispatchCampaign(payload)
      const count = response?.payload?.recipient_count ?? 0
      toast.success(`Campaign hook prepared for ${count} customers`)
    } catch {
      toast.error('Failed to dispatch campaign hook')
    } finally {
      setDispatching(false)
    }
  }

  const hasNext = rows.length === perPage
  const showRange = useMemo(() => {
    if (!rows.length) return '0'
    const start = (page - 1) * perPage + 1
    const end = start + rows.length - 1
    return `${start} - ${end}`
  }, [rows.length, page])

  const formatDate = (value) => {
    if (!value) return 'Never'
    const date = new Date(value)
    return Number.isNaN(date.getTime()) ? value : date.toLocaleDateString()
  }

  const formatMonths = (value) => {
    if (value === null || value === undefined) return '—'
    return `${value} mo`
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold text-gray-900">Customer Retention Report</h1>
          <p className="text-sm text-gray-600">Identify customers without workorders in the last {months} months.</p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <select
            className="border border-gray-300 rounded-md px-2 py-2 text-sm"
            value={exportFormat}
            onChange={(event) => setExportFormat(event.target.value)}
          >
            <option value="csv">Export CSV</option>
            <option value="json">Export JSON</option>
          </select>
          <Button variant="secondary" onClick={exportReport} loading={exporting}>Export</Button>
          <Button onClick={dispatchCampaign} loading={dispatching}>Send Campaign Hook</Button>
        </div>
      </div>

      <Card>
        <form onSubmit={handleRefresh} className="grid grid-cols-1 lg:grid-cols-5 gap-4">
          <div>
            <label className="block text-sm font-medium text-gray-700">Months inactive</label>
            <Input
              type="number"
              min="1"
              modelValue={months}
              onUpdateModelValue={(value) => setMonths(Number(value) || 1)}
            />
          </div>
          <div className="lg:col-span-2">
            <label className="block text-sm font-medium text-gray-700">Search</label>
            <Input
              modelValue={query}
              onUpdateModelValue={setQuery}
              placeholder="Search by name, email, or phone"
            />
          </div>
          <div className="lg:col-span-2">
            <label className="block text-sm font-medium text-gray-700">Campaign name</label>
            <Input
              modelValue={campaignName}
              onUpdateModelValue={setCampaignName}
              placeholder="Customer Retention"
            />
          </div>
          <div className="lg:col-span-5 flex justify-end">
            <Button type="submit" variant="secondary">Refresh</Button>
          </div>
        </form>
      </Card>

      <Card>
        {loading ? (
          <div className="py-10 flex justify-center">
            <Loading text="Loading retention report..." />
          </div>
        ) : rows.length === 0 ? (
          <div className="text-center py-10 text-sm text-gray-600">No customers match this retention window.</div>
        ) : (
          <>
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-gray-200">
                <thead className="bg-gray-50">
                  <tr>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Customer</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Last Workorder</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Inactive</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Preferred Channel</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Contact</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Opt-in</th>
                    <th className="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-200 bg-white">
                  {rows.map((row) => (
                    <tr key={row.id} className="hover:bg-gray-50">
                      <td className="px-4 py-3">
                        <div className="font-medium text-gray-900">{row.name || '—'}</div>
                        <div className="text-sm text-gray-500">{row.business_name || ''}</div>
                      </td>
                      <td className="px-4 py-3 text-sm text-gray-500">{formatDate(row.last_workorder_at)}</td>
                      <td className="px-4 py-3 text-sm text-gray-500">{formatMonths(row.months_since_workorder)}</td>
                      <td className="px-4 py-3 text-sm text-gray-500">{row.messaging?.preferred_channel || 'none'}</td>
                      <td className="px-4 py-3 text-sm text-gray-500">
                        <div>{row.messaging?.email || row.email || '—'}</div>
                        <div>{row.messaging?.phone || row.phone || ''}</div>
                      </td>
                      <td className="px-4 py-3">
                        <Badge size="sm" variant={row.messaging?.is_subscribed ? 'success' : 'default'}>
                          {row.messaging?.is_subscribed ? 'Subscribed' : 'Not subscribed'}
                        </Badge>
                      </td>
                      <td className="px-4 py-3 text-right">
                        <Link className="text-primary-600 hover:text-primary-500 text-sm" to={`/cp/customers/${row.id}`}>
                          View
                        </Link>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            <div className="flex flex-col sm:flex-row justify-between items-center mt-4 gap-2">
              <span className="text-sm text-gray-500">Showing {showRange} {total ? `of ${total}` : ''}</span>
              <div className="flex gap-2">
                <Button variant="ghost" size="sm" disabled={page === 1} onClick={() => setPage(page - 1)}>
                  Previous
                </Button>
                <Button variant="ghost" size="sm" disabled={!hasNext} onClick={() => setPage(page + 1)}>
                  Next
                </Button>
              </div>
            </div>
          </>
        )}
      </Card>
    </div>
  )
}
