import { useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'

import Alert from '../../components/ui/Alert'
import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Loading from '../../components/ui/Loading'
import Select from '../../components/ui/Select'
import pullRequestService from '../../../services/pull-request.service'

const STATUS_OPTIONS = [
  { value: '', label: 'All statuses' },
  { value: 'pending', label: 'Pending' },
  { value: 'ordered', label: 'Ordered' },
  { value: 'pulled', label: 'Pulled' },
  { value: 'received', label: 'Received' },
  { value: 'cancelled', label: 'Cancelled' },
]

const TYPE_OPTIONS = [
  { value: '', label: 'All types' },
  { value: 'pull', label: 'Pull from stock' },
  { value: 'order', label: 'Order needed' },
]

const STATUS_LABELS = {
  pending: 'Pending',
  ordered: 'Ordered',
  pulled: 'Pulled',
  received: 'Received',
  cancelled: 'Cancelled',
}

const STATUS_VARIANTS = {
  pending: 'warning',
  ordered: 'info',
  pulled: 'success',
  received: 'success',
  cancelled: 'secondary',
}

const TYPE_LABELS = {
  pull: 'Pull',
  order: 'Order',
}

const formatDate = (value) => {
  if (!value) return '—'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) {
    return value
  }
  return date.toLocaleString()
}

const remainingQuantity = (request) => Math.max(0, Number(request.quantity_requested || 0) - Number(request.quantity_fulfilled || 0))

export default function PullRequestList() {
  const navigate = useNavigate()
  const [requests, setRequests] = useState([])
  const [summary, setSummary] = useState(null)
  const [filters, setFilters] = useState({ status: '', request_type: '' })
  const [limit] = useState(20)
  const [offset, setOffset] = useState(0)
  const [total, setTotal] = useState(0)
  const [loading, setLoading] = useState(true)
  const [savingId, setSavingId] = useState(null)
  const [error, setError] = useState(null)
  const [notice, setNotice] = useState(null)

  const loadRequests = async (nextOffset = offset, nextFilters = filters) => {
    try {
      setLoading(true)
      setError(null)
      const response = await pullRequestService.list({
        status: nextFilters.status || undefined,
        request_type: nextFilters.request_type || undefined,
        limit,
        offset: nextOffset,
      })
      const data = response.data || {}
      setRequests(Array.isArray(data.items) ? data.items : [])
      setTotal(Number(data.total || 0))
      setOffset(Number(data.offset || nextOffset))
    } catch (err) {
      console.error('Failed to load inventory pull requests', err)
      setError(err.response?.data?.error || 'Unable to load pull requests. Please try again.')
    } finally {
      setLoading(false)
    }
  }

  const loadSummary = async () => {
    try {
      const response = await pullRequestService.getSummary()
      setSummary(response.data || null)
    } catch (err) {
      console.error('Failed to load pull request summary', err)
    }
  }

  useEffect(() => {
    loadRequests(0, filters)
    loadSummary()
  }, [])

  const applyFilters = () => {
    setOffset(0)
    loadRequests(0, filters)
  }

  const reloadCurrentPage = async () => {
    await loadRequests(offset, filters)
    await loadSummary()
  }

  const runAction = async (request, action) => {
    setSavingId(request.id)
    setError(null)
    setNotice(null)

    try {
      await action()
      setNotice('Pull request updated.')
      await reloadCurrentPage()
    } catch (err) {
      console.error('Failed to update pull request', err)
      setError(err.response?.data?.error || 'Unable to update pull request.')
    } finally {
      setSavingId(null)
    }
  }

  const markPulled = (request) => runAction(
    request,
    () => pullRequestService.markAsPulled(request.id, remainingQuantity(request) || 1),
  )

  const markOrdered = (request) => {
    const reference = window.prompt('Optional order reference or PO number:', request.order_reference || '')
    if (reference === null) return
    runAction(request, () => pullRequestService.markAsOrdered(request.id, reference || null))
  }

  const markReceived = (request) => runAction(
    request,
    () => pullRequestService.markAsReceived(request.id, remainingQuantity(request) || 1),
  )

  const cancelRequest = (request) => {
    if (!window.confirm('Cancel this pull request?')) return
    runAction(request, () => pullRequestService.cancel(request.id))
  }

  const nextPage = () => {
    const nextOffset = offset + limit
    setOffset(nextOffset)
    loadRequests(nextOffset, filters)
  }

  const previousPage = () => {
    const nextOffset = Math.max(0, offset - limit)
    setOffset(nextOffset)
    loadRequests(nextOffset, filters)
  }

  const hasNextPage = offset + requests.length < total

  return (
    <div>
      <div className="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Inventory Pull Requests</h1>
          <p className="mt-1 text-sm text-gray-500">
            Fulfill requested parts from stock or move shortages into ordering.
          </p>
        </div>
        <Button variant="outline" onClick={() => navigate('/cp/inventory')}>Back to inventory</Button>
      </div>

      {error ? <Alert variant="danger" className="mb-4">{error}</Alert> : null}
      {notice ? <Alert variant="success" className="mb-4">{notice}</Alert> : null}

      <div className="mb-6 grid gap-4 md:grid-cols-4">
        <Card>
          <p className="text-sm font-medium text-gray-600">Open</p>
          <p className="mt-2 text-3xl font-semibold text-gray-900">{summary?.total_open ?? '—'}</p>
        </Card>
        <Card>
          <p className="text-sm font-medium text-gray-600">Pending pulls</p>
          <p className="mt-2 text-3xl font-semibold text-amber-700">{summary?.pending_pulls ?? '—'}</p>
        </Card>
        <Card>
          <p className="text-sm font-medium text-gray-600">Pending orders</p>
          <p className="mt-2 text-3xl font-semibold text-blue-700">{summary?.pending_orders ?? '—'}</p>
        </Card>
        <Card>
          <p className="text-sm font-medium text-gray-600">On order</p>
          <p className="mt-2 text-3xl font-semibold text-indigo-700">{summary?.on_order ?? '—'}</p>
        </Card>
      </div>

      <Card>
        <div className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
          <div>
            <h2 className="text-lg font-semibold text-gray-900">Requests</h2>
            <p className="text-sm text-gray-500">Create new requests from a workorder parts panel.</p>
          </div>
          <div className="grid gap-3 sm:grid-cols-3 lg:min-w-[36rem]">
            <Select
              label="Status"
              options={STATUS_OPTIONS}
              modelValue={filters.status}
              onUpdateModelValue={(value) => setFilters((prev) => ({ ...prev, status: value }))}
            />
            <Select
              label="Type"
              options={TYPE_OPTIONS}
              modelValue={filters.request_type}
              onUpdateModelValue={(value) => setFilters((prev) => ({ ...prev, request_type: value }))}
            />
            <Button variant="primary" onClick={applyFilters}>Apply filters</Button>
          </div>
        </div>

        {loading ? (
          <div className="py-8 flex justify-center">
            <Loading text="Loading pull requests..." />
          </div>
        ) : requests.length === 0 ? (
          <div className="py-8 text-center text-gray-500">No pull requests match this filter.</div>
        ) : (
          <>
            <div className="mt-6 overflow-x-auto">
              <table className="min-w-full divide-y divide-gray-200">
                <thead className="bg-gray-50">
                  <tr>
                    <th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Workorder</th>
                    <th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Part</th>
                    <th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Type</th>
                    <th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Qty</th>
                    <th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Status</th>
                    <th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Requested</th>
                    <th className="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-200 bg-white">
                  {requests.map((request) => {
                    const remaining = remainingQuantity(request)
                    const isSaving = savingId === request.id
                    const isClosed = ['pulled', 'received', 'cancelled'].includes(request.status)

                    return (
                      <tr key={request.id} className="hover:bg-gray-50">
                        <td className="px-4 py-3 text-sm">
                          {request.workorder_id ? (
                            <Link className="font-medium text-primary-600 hover:text-primary-700" to={`/cp/workorders/${request.workorder_id}`}>
                              {request.workorder_number || `#${request.workorder_id}`}
                            </Link>
                          ) : '—'}
                          {request.job_description ? <div className="text-xs text-gray-500">{request.job_description}</div> : null}
                        </td>
                        <td className="px-4 py-3 text-sm">
                          <div className="font-medium text-gray-900">{request.inventory_item_name || request.description}</div>
                          <div className="text-xs text-gray-500">{request.sku || request.vendor || 'No SKU/vendor'}</div>
                        </td>
                        <td className="px-4 py-3 text-sm text-gray-600">{TYPE_LABELS[request.request_type] || request.request_type}</td>
                        <td className="px-4 py-3 text-sm text-gray-600">
                          {request.quantity_fulfilled || 0} / {request.quantity_requested || 0}
                          {remaining > 0 ? <div className="text-xs text-gray-500">{remaining} remaining</div> : null}
                        </td>
                        <td className="px-4 py-3 text-sm">
                          <Badge variant={STATUS_VARIANTS[request.status] || 'default'}>
                            {STATUS_LABELS[request.status] || request.status}
                          </Badge>
                        </td>
                        <td className="px-4 py-3 text-sm text-gray-600">
                          <div>{request.requested_by_name || '—'}</div>
                          <div className="text-xs text-gray-500">{formatDate(request.requested_at || request.created_at)}</div>
                        </td>
                        <td className="px-4 py-3 text-right text-sm">
                          <div className="flex flex-wrap justify-end gap-2">
                            {request.status === 'pending' && request.request_type === 'pull' ? (
                              <Button size="sm" variant="primary" disabled={isSaving} onClick={() => markPulled(request)}>Pull</Button>
                            ) : null}
                            {request.status === 'pending' && request.request_type === 'order' ? (
                              <Button size="sm" variant="primary" disabled={isSaving} onClick={() => markOrdered(request)}>Order</Button>
                            ) : null}
                            {request.status === 'ordered' ? (
                              <Button size="sm" variant="primary" disabled={isSaving} onClick={() => markReceived(request)}>Receive</Button>
                            ) : null}
                            {!isClosed ? (
                              <Button size="sm" variant="outline" disabled={isSaving} onClick={() => cancelRequest(request)}>Cancel</Button>
                            ) : null}
                          </div>
                        </td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>

            <div className="mt-6 flex items-center justify-between">
              <div className="text-sm text-gray-500">
                Showing {offset + 1}-{offset + requests.length} of {total}
              </div>
              <div className="flex gap-2">
                <Button variant="outline" disabled={offset === 0 || loading} onClick={previousPage}>Previous</Button>
                <Button variant="outline" disabled={!hasNextPage || loading} onClick={nextPage}>Next</Button>
              </div>
            </div>
          </>
        )}
      </Card>
    </div>
  )
}
