import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'

import Card from '../../components/ui/Card'
import Alert from '../../components/ui/Alert'
import Loading from '../../components/ui/Loading'
import Select from '../../components/ui/Select'
import { portalService } from '../../../services/portal/portal.service'

const STATUS_BUCKETS = [
  { value: 'open', label: 'Open' },
  { value: 'closed', label: 'Closed' },
  { value: '', label: 'All' },
]

const statusBadge = (status) => {
  switch (status) {
    case 'pending': return 'bg-gray-100 text-gray-700'
    case 'in_progress': return 'bg-blue-100 text-blue-800'
    case 'on_hold': return 'bg-amber-100 text-amber-800'
    case 'parts_pending': return 'bg-amber-100 text-amber-800'
    case 'awaiting_authorization': return 'bg-purple-100 text-purple-800'
    case 'qc_required': return 'bg-yellow-100 text-yellow-800'
    case 'ready_for_pickup': return 'bg-cyan-100 text-cyan-800'
    case 'completed': return 'bg-green-100 text-green-800'
    case 'cancelled': return 'bg-red-100 text-red-800'
    case 'goa': return 'bg-red-100 text-red-800'
    default: return 'bg-gray-100 text-gray-700'
  }
}

const formatMoney = (n) => {
  if (n == null || isNaN(Number(n))) return '—'
  return `$${Number(n).toFixed(2)}`
}

const formatDate = (s) => (s ? new Date(s).toLocaleDateString() : '—')

export default function PortalWorkorders() {
  const [bucket, setBucket] = useState('open')
  const [list, setList] = useState({ data: [], total: 0 })
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)

  useEffect(() => {
    let cancelled = false
    setLoading(true)
    setError(null)
    const params = bucket ? { status_bucket: bucket } : {}
    portalService.listWorkorders(params)
      .then((result) => { if (!cancelled) setList(result || { data: [], total: 0 }) })
      .catch((err) => { if (!cancelled) setError(err.response?.data?.message || 'Unable to load work orders.') })
      .finally(() => { if (!cancelled) setLoading(false) })
    return () => { cancelled = true }
  }, [bucket])

  return (
    <div className="space-y-6">
      <header className="flex items-end justify-between gap-4 flex-wrap">
        <div>
          <h1 className="text-2xl font-semibold">Work orders</h1>
          <p className="text-sm text-gray-600 mt-1">
            Service work happening at your sites.
          </p>
        </div>
        <div className="w-44">
          <Select
            label="Status"
            options={STATUS_BUCKETS}
            modelValue={bucket}
            onUpdateModelValue={setBucket}
          />
        </div>
      </header>

      {error && <Alert variant="error" closable={false}>{error}</Alert>}

      {loading ? (
        <Card>
          <div className="py-10 flex justify-center"><Loading text="Loading work orders…" /></div>
        </Card>
      ) : list.data.length === 0 ? (
        <Card>
          <p className="text-sm text-gray-600">
            No {bucket || 'matching'} work orders for your company.
          </p>
        </Card>
      ) : (
        <div className="overflow-x-auto border rounded">
          <table className="min-w-full divide-y divide-gray-200 text-sm">
            <thead className="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
              <tr>
                <th className="px-3 py-2 text-left">Number</th>
                <th className="px-3 py-2 text-left">Status</th>
                <th className="px-3 py-2 text-left">Type</th>
                <th className="px-3 py-2 text-left">Priority</th>
                <th className="px-3 py-2 text-left">Created</th>
                <th className="px-3 py-2 text-left">ETA</th>
                <th className="px-3 py-2 text-right">Total</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100 bg-white">
              {list.data.map((wo) => (
                <tr key={wo.id} className="hover:bg-gray-50">
                  <td className="px-3 py-2">
                    <Link to={`/p/work-orders/${wo.id}`} className="font-medium" style={{ color: 'var(--portal-primary, #2563eb)' }}>
                      {wo.number}
                    </Link>
                  </td>
                  <td className="px-3 py-2">
                    <span className={`text-xs px-2 py-0.5 rounded-full ${statusBadge(wo.status)}`}>
                      {wo.status}
                    </span>
                  </td>
                  <td className="px-3 py-2 text-gray-600">{wo.type}</td>
                  <td className="px-3 py-2 text-gray-600">{wo.priority}</td>
                  <td className="px-3 py-2 text-gray-600">{formatDate(wo.created_at)}</td>
                  <td className="px-3 py-2 text-gray-600">{formatDate(wo.estimated_completion)}</td>
                  <td className="px-3 py-2 text-right font-medium">{formatMoney(wo.grand_total)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}
