import { useCallback, useEffect, useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'

import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Table from '../../components/ui/Table'
import inventoryService from '../../../services/inventory.service'

export default function InventoryList() {
  const navigate = useNavigate()
  const [loading, setLoading] = useState(false)
  const [deletingId, setDeletingId] = useState(null)
  const [items, setItems] = useState([])
  const [page, setPage] = useState(1)
  const [hasNextPage, setHasNextPage] = useState(false)
  const [filters, setFilters] = useState({
    query: '',
    category: '',
    location: '',
    low_stock_only: false,
  })

  const perPage = 10

  const columns = useMemo(() => ([
    { key: 'name', label: 'Item' },
    { key: 'category', label: 'Category' },
    { key: 'stock_quantity', label: 'Stock' },
    { key: 'pricing', label: 'Pricing' },
    { key: 'reorder_quantity', label: 'Reorder' },
  ]), [])

  const loadItems = useCallback(async () => {
    setLoading(true)
    try {
      const params = {
        limit: perPage,
        offset: (page - 1) * perPage,
      }

      Object.entries(filters).forEach(([key, value]) => {
        if (value) params[key] = value
      })

      const data = await inventoryService.list(params)
      const normalized = data.map((item) => ({
        ...item,
        severity: item.stock_quantity === 0 ? 'out' : item.stock_quantity <= item.low_stock_threshold ? 'low' : 'ok',
      }))
      setItems(normalized)
      setHasNextPage(normalized.length === perPage)
    } finally {
      setLoading(false)
    }
  }, [filters, page])

  useEffect(() => {
    loadItems()
  }, [loadItems])

  const refresh = (nextFilters) => {
    setPage(1)
    setFilters((prev) => ({ ...prev, ...nextFilters }))
  }

  const nextPage = () => {
    if (!hasNextPage) return
    setPage((prev) => prev + 1)
  }

  const previousPage = () => {
    if (page === 1) return
    setPage((prev) => prev - 1)
  }

  const confirmDelete = async (id) => {
    if (!window.confirm('Delete this inventory item?')) return
    setDeletingId(id)
    try {
      await inventoryService.remove(id)
      setItems((prev) => prev.filter((item) => item.id !== id))
    } finally {
      setDeletingId(null)
    }
  }

  return (
    <div>
      <div className="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Inventory</h1>
          <p className="mt-1 text-sm text-gray-500">Search, filter, and manage stock</p>
        </div>
        <Button onClick={() => navigate('/cp/inventory/create')}>
          <svg className="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 4v16m8-8H4" />
          </svg>
          Add Item
        </Button>
      </div>

      <div className="grid grid-cols-1 gap-4 xl:grid-cols-4">
        <Card className="xl:col-span-3">
          <div className="flex flex-col gap-4">
            <div className="grid grid-cols-1 gap-3 md:grid-cols-4">
              <div>
                <label className="block text-sm font-medium text-gray-700">Search</label>
                <Input
                  modelValue={filters.query}
                  placeholder="Name or SKU"
                  onUpdateModelValue={(value) => refresh({ query: value })}
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700">Category</label>
                <Input
                  modelValue={filters.category}
                  placeholder="Brakes"
                  onUpdateModelValue={(value) => refresh({ category: value })}
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700">Location</label>
                <Input
                  modelValue={filters.location}
                  placeholder="Aisle 3"
                  onUpdateModelValue={(value) => refresh({ location: value })}
                />
              </div>
              <div className="flex items-end gap-2">
                <input
                  id="lowStockOnly"
                  checked={filters.low_stock_only}
                  type="checkbox"
                  className="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                  onChange={(event) => refresh({ low_stock_only: event.target.checked })}
                />
                <label htmlFor="lowStockOnly" className="text-sm text-gray-700">Show low stock only</label>
              </div>
            </div>

            <Table
              columns={columns}
              data={items}
              loading={loading}
              hoverable
              cellRenderers={{
                name: ({ row }) => (
                  <div>
                    <div className="font-semibold text-gray-900">{row.name}</div>
                    <p className="text-xs text-gray-500">SKU: {row.sku || '—'}</p>
                  </div>
                ),
                stock_quantity: ({ row }) => (
                  <div className="flex items-center gap-2">
                    <Badge variant={row.severity === 'out' ? 'danger' : row.severity === 'low' ? 'warning' : 'secondary'}>
                      {row.stock_quantity}
                    </Badge>
                    <span className="text-xs text-gray-500">Threshold {row.low_stock_threshold}</span>
                  </div>
                ),
                pricing: ({ row }) => (
                  <div className="text-sm text-gray-800">
                    <div>
                      <span className="font-semibold">Cost:</span> ${Number(row.cost).toFixed(2)}
                    </div>
                    <div>
                      <span className="font-semibold">Price:</span> ${Number(row.sale_price).toFixed(2)}
                    </div>
                    <div className="text-xs text-gray-500">Markup: {row.markup ?? '—'}%</div>
                  </div>
                ),
                reorder_quantity: ({ row }) => (
                  <div className="text-sm text-gray-900">
                    {row.reorder_quantity || '—'}
                    <p className="text-xs text-gray-500">Vendor: {row.vendor || '—'}</p>
                  </div>
                ),
              }}
              renderActions={(row) => (
                <div className="flex gap-2">
                  <Button size="sm" variant="secondary" onClick={() => navigate(`/cp/inventory/${row.id}/edit`)}>Edit</Button>
                  <Button size="sm" variant="danger" loading={deletingId === row.id} onClick={() => confirmDelete(row.id)}>
                    Delete
                  </Button>
                </div>
              )}
              renderEmpty={() => <p className="text-sm text-gray-500">No inventory items found for the current filters.</p>}
            />

            <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
              <div className="text-sm text-gray-600">Page {page} ({items.length} items)</div>
              <div className="flex items-center gap-2">
                <Button disabled={page === 1} variant="secondary" onClick={previousPage}>Previous</Button>
                <Button disabled={!hasNextPage} variant="secondary" onClick={nextPage}>Next</Button>
              </div>
            </div>
          </div>
        </Card>

        <Card className="space-y-3">
          <h3 className="text-lg font-semibold text-gray-900">Quick actions</h3>
          <p className="text-sm text-gray-600">Import/export and alerts at a glance.</p>
          <div className="rounded-md bg-yellow-50 p-3 text-sm text-yellow-800">
            Low stock alerting is enabled. Use the toggle above to triage items that need restocking.
          </div>
          <Button variant="secondary" onClick={() => navigate('/cp/inventory/alerts')}>View Alerts</Button>
          <div className="pt-2 border-t border-gray-100 space-y-2">
            <h4 className="text-sm font-semibold text-gray-900">Manage lists</h4>
            <div className="flex flex-wrap gap-2">
              <Button size="sm" variant="secondary" onClick={() => navigate('/cp/inventory/categories')}>Categories</Button>
              <Button size="sm" variant="secondary" onClick={() => navigate('/cp/inventory/vendors')}>Vendors</Button>
              <Button size="sm" variant="secondary" onClick={() => navigate('/cp/inventory/locations')}>Locations</Button>
            </div>
          </div>
        </Card>
      </div>
    </div>
  )
}
