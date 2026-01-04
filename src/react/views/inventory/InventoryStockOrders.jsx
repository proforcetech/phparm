import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'

import Alert from '../../components/ui/Alert'
import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Loading from '../../components/ui/Loading'
import stockOrderService from '../../../services/inventory-stock-orders.service'

const STATUS_LABELS = {
  backorder: 'Backorder',
  on_order: 'On order',
  received: 'Received',
  cancelled: 'Cancelled',
}

const STATUS_VARIANTS = {
  backorder: 'warning',
  on_order: 'info',
  received: 'success',
  cancelled: 'secondary',
}

const formatDate = (value) => {
  if (!value) return '—'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) {
    return value
  }
  return date.toLocaleDateString()
}

export default function InventoryStockOrders() {
  const navigate = useNavigate()
  const [orders, setOrders] = useState([])
  const [query, setQuery] = useState('')
  const [limit] = useState(10)
  const [offset, setOffset] = useState(0)
  const [hasMore, setHasMore] = useState(false)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)

  const loadOrders = async (reset = false, overrideOffset = null) => {
    try {
      setLoading(true)
      setError(null)

      const nextOffset = reset ? 0 : (overrideOffset ?? offset)
      if (reset) {
        setOffset(0)
      }

      const listLimit = limit + 1
      const response = await stockOrderService.list({
        query,
        limit: listLimit,
        offset: nextOffset,
      })

      const normalizedList = Array.isArray(response?.items)
        ? response.items
        : Array.isArray(response?.data)
          ? response.data
          : []

      setHasMore(normalizedList.length > limit)
      setOrders(normalizedList.slice(0, limit))
    } catch (err) {
      console.error('Failed to load stock orders', err)
      setError('Unable to load stock orders. Please try again.')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    loadOrders()
  }, [])

  const nextPage = () => {
    if (!hasMore) return
    const nextOffset = offset + limit
    setOffset(nextOffset)
    loadOrders(false, nextOffset)
  }

  const previousPage = () => {
    if (offset === 0) return
    const nextOffset = Math.max(0, offset - limit)
    setOffset(nextOffset)
    loadOrders(false, nextOffset)
  }

  return (
    <div>
      <div className="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Stock Orders</h1>
          <p className="mt-1 text-sm text-gray-500">Track replenishment orders and expected arrivals</p>
        </div>
        <div className="flex gap-2">
          <Button variant="outline" onClick={() => navigate('/cp/inventory/alerts')}>View alerts</Button>
          <Button variant="outline" onClick={() => navigate('/cp/inventory')}>Back to inventory</Button>
        </div>
      </div>

      <Card>
        <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          <h3 className="text-lg font-medium text-gray-900">Orders</h3>
          <div className="flex gap-3 w-full sm:w-auto">
            <input
              value={query}
              onChange={(event) => setQuery(event.target.value)}
              onKeyUp={(event) => {
                if (event.key === 'Enter') {
                  loadOrders(true)
                }
              }}
              type="text"
              placeholder="Search by description or SKU"
              className="flex-1 rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500"
            />
            <Button variant="primary" onClick={() => loadOrders(true)}>Search</Button>
          </div>
        </div>

        {error ? (
          <div className="mb-4">
            <Alert variant="danger">{error}</Alert>
          </div>
        ) : null}

        {loading ? (
          <div className="py-8 flex justify-center">
            <Loading text="Loading stock orders..." />
          </div>
        ) : (
          <>
            {orders.length === 0 ? (
              <div className="py-6 text-center text-gray-500">No stock orders found for this filter.</div>
            ) : (
              <div className="overflow-x-auto">
                <table className="min-w-full divide-y divide-gray-200">
                  <thead className="bg-gray-50">
                    <tr>
                      <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        Item
                      </th>
                      <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        SKU
                      </th>
                      <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        Qty
                      </th>
                      <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        Status
                      </th>
                      <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        ETA
                      </th>
                      <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        Vendor
                      </th>
                      <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        Order Ref
                      </th>
                    </tr>
                  </thead>
                  <tbody className="bg-white divide-y divide-gray-200">
                    {orders.map((order) => (
                      <tr key={order.id} className="hover:bg-gray-50">
                        <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                          {order.inventory_item_name || order.description}
                        </td>
                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{order.sku || '—'}</td>
                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{order.quantity_ordered}</td>
                        <td className="px-6 py-4 whitespace-nowrap text-sm">
                          <Badge variant={STATUS_VARIANTS[order.status] || 'default'}>
                            {STATUS_LABELS[order.status] || order.status}
                          </Badge>
                        </td>
                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                          {formatDate(order.expected_arrival_date)}
                        </td>
                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{order.vendor || '—'}</td>
                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                          {order.order_reference || '—'}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}

            <div className="flex items-center justify-between mt-6">
              <div className="text-sm text-gray-500">Showing {orders.length} results</div>
              <div className="flex gap-2">
                <Button variant="outline" disabled={offset === 0} onClick={previousPage}>Previous</Button>
                <Button variant="outline" disabled={!hasMore} onClick={nextPage}>Next</Button>
              </div>
            </div>
          </>
        )}
      </Card>
    </div>
  )
}
