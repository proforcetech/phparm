import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'

import Alert from '../../components/ui/Alert'
import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Loading from '../../components/ui/Loading'
import inventoryService from '../../../services/inventory.service'
import dashboardService from '../../../services/dashboard.service'
import { warrantyService } from '../../../services/warranty.service'

const STATUS_LABELS = {
  out_of_stock: 'Out of stock',
  backorder: 'Backorder',
  on_order: 'On order',
}

const STATUS_VARIANTS = {
  out_of_stock: 'danger',
  backorder: 'warning',
  on_order: 'info',
}

const formatDate = (value) => {
  if (!value) return '—'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) {
    return value
  }
  return date.toLocaleDateString()
}

export default function InventoryAlerts() {
  const navigate = useNavigate()
  const [items, setItems] = useState([])
  const [summary, setSummary] = useState({ out_of_stock: 0, low_stock: 0 })
  const [query, setQuery] = useState('')
  const [limit] = useState(10)
  const [offset, setOffset] = useState(0)
  const [hasMore, setHasMore] = useState(false)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [warrantySummary, setWarrantySummary] = useState(null)

  const loadWarrantySummary = async () => {
    try {
      const statuses = ['defective', 'rma_requested', 'shipped']
      const results = await Promise.all(
        statuses.map((status) => warrantyService.listClaims({ status }))
      )

      const summary = statuses.reduce((acc, status, index) => {
        acc[status] = Array.isArray(results[index]) ? results[index].length : 0
        return acc
      }, {})

      setWarrantySummary(summary)
    } catch (err) {
      console.error('Failed to load warranty summary', err)
      setWarrantySummary(null)
    }
  }

  const loadAlerts = async (reset = false, overrideOffset = null) => {
    try {
      setLoading(true)
      setError(null)

      const nextOffset = reset ? 0 : (overrideOffset ?? offset)
      if (reset) {
        setOffset(0)
      }

      const listLimit = limit + 1
      const [alertList, tileData] = await Promise.all([
        inventoryService.getLowStock({
          query,
          limit: listLimit,
          offset: nextOffset,
        }),
        dashboardService.getInventoryLowStockTile().catch(() => null),
      ])

      const normalizedList = Array.isArray(alertList?.data)
        ? alertList.data
        : Array.isArray(alertList)
          ? alertList
          : []

      setHasMore(normalizedList.length > limit)
      setItems(normalizedList.slice(0, limit))

      if (tileData?.counts) {
        setSummary(tileData.counts)
      }
    } catch (err) {
      console.error('Failed to load alerts', err)
      setError('Unable to load inventory alerts. Please try again.')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    loadAlerts()
    loadWarrantySummary()
  }, [])

  const nextPage = () => {
    if (!hasMore) return
    const nextOffset = offset + limit
    setOffset(nextOffset)
    loadAlerts(false, nextOffset)
  }

  const previousPage = () => {
    if (offset === 0) return
    const nextOffset = Math.max(0, offset - limit)
    setOffset(nextOffset)
    loadAlerts(false, nextOffset)
  }

  return (
    <div>
      <div className="mb-6 flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Inventory Alerts</h1>
          <p className="mt-1 text-sm text-gray-500">Track low and out-of-stock items from the dashboard</p>
        </div>
        <div className="flex gap-2">
          <Button variant="outline" onClick={() => navigate('/cp/inventory/stock-orders')}>Stock orders</Button>
          <Button variant="outline" onClick={() => navigate('/cp/inventory')}>Back to inventory</Button>
        </div>
      </div>

      <Card className="mb-6">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div className="p-4 rounded-lg bg-red-50 border border-red-100">
            <p className="text-sm font-medium text-red-700">Out of Stock</p>
            <p className="mt-2 text-3xl font-bold text-red-800">{summary.out_of_stock}</p>
            <p className="text-sm text-red-600">Items currently unavailable</p>
          </div>

          <div className="p-4 rounded-lg bg-amber-50 border border-amber-100">
            <p className="text-sm font-medium text-amber-700">Low Stock</p>
            <p className="mt-2 text-3xl font-bold text-amber-800">{summary.low_stock}</p>
            <p className="text-sm text-amber-600">Items approaching threshold</p>
          </div>
        </div>
      </Card>

      {warrantySummary ? (
        <Card className="mb-6">
          <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
              <h3 className="text-lg font-medium text-gray-900">Warranty Claims Awaiting Credit</h3>
              <p className="text-sm text-gray-500">Track defective inventory claims that still need vendor credit.</p>
            </div>
            <Button variant="outline" onClick={() => navigate('/cp/warranty')}>View warranty claims</Button>
          </div>
          <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div className="rounded-lg border border-red-100 bg-red-50 p-4">
              <p className="text-sm font-medium text-red-700">Defective</p>
              <p className="mt-2 text-2xl font-semibold text-red-800">{warrantySummary.defective || 0}</p>
            </div>
            <div className="rounded-lg border border-amber-100 bg-amber-50 p-4">
              <p className="text-sm font-medium text-amber-700">RMA Requested</p>
              <p className="mt-2 text-2xl font-semibold text-amber-800">{warrantySummary.rma_requested || 0}</p>
            </div>
            <div className="rounded-lg border border-blue-100 bg-blue-50 p-4">
              <p className="text-sm font-medium text-blue-700">Shipped</p>
              <p className="mt-2 text-2xl font-semibold text-blue-800">{warrantySummary.shipped || 0}</p>
            </div>
          </div>
        </Card>
      ) : null}

      <Card>
        <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          <h3 className="text-lg font-medium text-gray-900">Alert Items</h3>
          <div className="flex gap-3 w-full sm:w-auto">
            <input
              value={query}
              onChange={(event) => setQuery(event.target.value)}
              onKeyUp={(event) => {
                if (event.key === 'Enter') {
                  loadAlerts(true)
                }
              }}
              type="text"
              placeholder="Search by name or SKU"
              className="flex-1 rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500"
            />
            <Button variant="primary" onClick={() => loadAlerts(true)}>Search</Button>
          </div>
        </div>

        {error ? (
          <div className="mb-4">
            <Alert variant="danger">{error}</Alert>
          </div>
        ) : null}

        {loading ? (
          <div className="py-8 flex justify-center">
            <Loading text="Loading alerts..." />
          </div>
        ) : (
          <>
            {items.length === 0 ? (
              <div className="py-6 text-center text-gray-500">No low-stock items found for this filter.</div>
            ) : (
              <div className="overflow-x-auto">
                <table className="min-w-full divide-y divide-gray-200">
                  <thead className="bg-gray-50">
                    <tr>
                      <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Item</th>
                      <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">SKU</th>
                      <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Location</th>
                      <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Stock</th>
                      <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Threshold</th>
                      <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                      <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ETA</th>
                    </tr>
                  </thead>
                  <tbody className="bg-white divide-y divide-gray-200">
                    {items.map((item) => (
                      <tr key={item.id} className="hover:bg-gray-50">
                        <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">{item.name}</td>
                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{item.sku || '—'}</td>
                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{item.location || '—'}</td>
                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{item.stock_quantity}</td>
                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{item.low_stock_threshold}</td>
                        <td className="px-6 py-4 whitespace-nowrap text-sm">
                          <Badge variant={STATUS_VARIANTS[item.status] || (item.severity === 'out' ? 'danger' : 'warning')}>
                            {STATUS_LABELS[item.status] || (item.severity === 'out' ? 'Out of Stock' : 'Low Stock')}
                          </Badge>
                        </td>
                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                          {formatDate(item.expected_arrival_date)}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}

            <div className="flex items-center justify-between mt-6">
              <div className="text-sm text-gray-500">Showing {items.length} results</div>
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
