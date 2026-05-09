import { useCallback, useEffect, useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'

import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Modal from '../../components/ui/Modal'
import Table from '../../components/ui/Table'
import bundleService from '../../../services/bundle.service'
import { serviceLineService } from '../../../services/serviceLine.service'

export default function BundleList() {
  const navigate = useNavigate()
  const [bundles, setBundles] = useState([])
  const [serviceLines, setServiceLines] = useState([])
  const [loading, setLoading] = useState(false)
  const [showDelete, setShowDelete] = useState(false)
  const [bundleToDelete, setBundleToDelete] = useState(null)
  const [filters, setFilters] = useState({ query: '', activeOnly: true, serviceLineId: '' })

  const columns = useMemo(() => ([
    { key: 'name', label: 'Name' },
    { key: 'service_line_name', label: 'Service Line' },
    { key: 'service_type_name', label: 'Service Type' },
    { key: 'item_count', label: 'Items' },
    { key: 'sort_order', label: 'Sort Order' },
    { key: 'is_active', label: 'Status' },
  ]), [])

  const loadBundles = useCallback(async () => {
    setLoading(true)
    try {
      const params = {
        query: filters.query || undefined,
        active: filters.activeOnly ? 1 : undefined,
        service_line_id: filters.serviceLineId || undefined,
      }
      const { data } = await bundleService.list(params)
      setBundles(data)
    } catch (error) {
      console.error('Failed to load bundles', error)
      setBundles([])
    } finally {
      setLoading(false)
    }
  }, [filters])

  useEffect(() => {
    loadBundles()
  }, [loadBundles])

  useEffect(() => {
    serviceLineService
      .list()
      .then((lines) => setServiceLines(Array.isArray(lines) ? lines : []))
      .catch((err) => {
        console.error('Failed to load service lines', err)
        setServiceLines([])
      })
  }, [])

  const confirmDelete = (bundle) => {
    setBundleToDelete(bundle)
    setShowDelete(true)
  }

  const deleteBundle = async () => {
    if (!bundleToDelete) return
    try {
      await bundleService.remove(bundleToDelete.id)
      await loadBundles()
    } catch (error) {
      console.error('Failed to delete bundle', error)
    } finally {
      setShowDelete(false)
      setBundleToDelete(null)
    }
  }

  return (
    <div>
      <div className="mb-6 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Preset Bundles</h1>
          <p className="mt-1 text-sm text-gray-500">Create reusable groups of line items for faster estimate building.</p>
        </div>
        <Button onClick={() => navigate('/cp/bundles/create')}>New Bundle</Button>
      </div>

      <Card>
        <div className="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
          <div className="flex flex-col gap-2 md:flex-row md:items-center">
            <Input
              modelValue={filters.query}
              placeholder="Search bundles"
              className="w-full md:w-64"
              onUpdateModelValue={(value) => setFilters((prev) => ({ ...prev, query: value }))}
            />
            <select
              value={filters.serviceLineId}
              onChange={(event) => setFilters((prev) => ({ ...prev, serviceLineId: event.target.value }))}
              className="rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
            >
              <option value="">All service lines</option>
              {serviceLines.map((line) => (
                <option key={line.id} value={line.id}>{line.name}</option>
              ))}
            </select>
            <label className="inline-flex items-center gap-2 text-sm text-gray-700">
              <input
                checked={filters.activeOnly}
                type="checkbox"
                className="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                onChange={(event) => setFilters((prev) => ({ ...prev, activeOnly: event.target.checked }))}
              />
              Active only
            </label>
          </div>
          <div className="text-sm text-gray-600">{bundles.length} results</div>
        </div>

        {loading ? (
          <div className="py-10">
            <Loading text="Loading bundles..." />
          </div>
        ) : (
          <>
            {bundles.length === 0 ? (
              <div className="py-10 text-center text-gray-500">No bundles found.</div>
            ) : null}

            <div className="hidden md:block mt-4">
              <Table
                columns={columns}
                data={bundles}
                loading={loading}
                hoverable
                cellRenderers={{
                  name: ({ row }) => (
                    <div className="py-3">
                      <div className="font-medium text-gray-900">{row.name}</div>
                      <div className="text-sm text-gray-500">{row.description || 'No description'}</div>
                    </div>
                  ),
                  service_line_name: ({ value }) => <span className="text-sm text-gray-700">{value || 'All'}</span>,
                  service_type_name: ({ value }) => <span className="text-sm text-gray-700">{value || '—'}</span>,
                  item_count: ({ value }) => <span className="text-sm text-gray-700">{value ?? 0}</span>,
                  sort_order: ({ value }) => <span className="text-sm text-gray-700">{value}</span>,
                  is_active: ({ value }) => <Badge variant={value ? 'success' : 'secondary'}>{value ? 'Active' : 'Inactive'}</Badge>,
                  actions: ({ row }) => (
                    <div className="flex justify-end gap-2">
                      <Button size="sm" variant="secondary" onClick={() => navigate(`/cp/bundles/${row.id}/edit`)}>Edit</Button>
                      <Button size="sm" variant="danger" onClick={() => confirmDelete(row)}>Delete</Button>
                    </div>
                  ),
                }}
                renderActions={(row) => (
                  <div className="flex justify-end gap-2">
                    <Button size="sm" variant="secondary" onClick={() => navigate(`/cp/bundles/${row.id}/edit`)}>Edit</Button>
                    <Button size="sm" variant="danger" onClick={() => confirmDelete(row)}>Delete</Button>
                  </div>
                )}
              />
            </div>

            <div className="grid grid-cols-1 gap-4 md:hidden mt-4">
              {bundles.map((bundle) => (
                <div key={bundle.id} className="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                  <div className="flex items-start justify-between">
                    <div>
                      <div className="text-lg font-semibold text-gray-900">{bundle.name}</div>
                      <div className="text-sm text-gray-500">{bundle.description || 'No description'}</div>
                    </div>
                    <Badge variant={bundle.is_active ? 'success' : 'secondary'}>{bundle.is_active ? 'Active' : 'Inactive'}</Badge>
                  </div>
                  <dl className="mt-3 grid grid-cols-2 gap-3 text-sm text-gray-700">
                    <div>
                      <dt className="text-gray-500">Service Line</dt>
                      <dd className="font-medium">{bundle.service_line_name || 'All'}</dd>
                    </div>
                    <div>
                      <dt className="text-gray-500">Service Type</dt>
                      <dd className="font-medium">{bundle.service_type_name || '—'}</dd>
                    </div>
                    <div>
                      <dt className="text-gray-500">Items</dt>
                      <dd className="font-medium">{bundle.item_count ?? 0}</dd>
                    </div>
                    <div>
                      <dt className="text-gray-500">Sort Order</dt>
                      <dd className="font-medium">{bundle.sort_order}</dd>
                    </div>
                  </dl>
                  <div className="mt-4 flex justify-end gap-2">
                    <Button size="sm" variant="secondary" onClick={() => navigate(`/cp/bundles/${bundle.id}/edit`)}>Edit</Button>
                    <Button size="sm" variant="danger" onClick={() => confirmDelete(bundle)}>Delete</Button>
                  </div>
                </div>
              ))}
            </div>
          </>
        )}
      </Card>

      <Modal
        open={showDelete}
        title="Delete bundle?"
        onClose={() => setShowDelete(false)}
        footer={(
          <div className="flex justify-end gap-3">
            <Button variant="secondary" onClick={() => setShowDelete(false)}>Cancel</Button>
            <Button variant="danger" onClick={deleteBundle}>Delete</Button>
          </div>
        )}
      >
        <p className="text-sm text-gray-600">This will remove the bundle and its items. This action cannot be undone.</p>
      </Modal>
    </div>
  )
}
