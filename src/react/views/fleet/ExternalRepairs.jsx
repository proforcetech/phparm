import { useCallback, useEffect, useState } from 'react'
import { useSearchParams } from 'react-router-dom'

import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Modal from '../../components/ui/Modal'
import Select from '../../components/ui/Select'
import Textarea from '../../components/ui/Textarea'
import fleetService from '../../../services/fleet.service'
import { useToast } from '../../stores/toast.jsx'

const STATUS_OPTIONS = [
  { value: '', label: 'All statuses' },
  { value: 'pending', label: 'Pending' },
  { value: 'in_progress', label: 'In Progress' },
  { value: 'completed', label: 'Completed' },
  { value: 'cancelled', label: 'Cancelled' },
]

const DETAIL_STATUS_OPTIONS = STATUS_OPTIONS.filter((o) => o.value !== '')

const STATUS_VARIANT = {
  pending: 'warning',
  in_progress: 'info',
  completed: 'success',
  cancelled: 'default',
}

function unwrapList(response) {
  if (Array.isArray(response)) return response
  if (Array.isArray(response?.data)) return response.data
  if (Array.isArray(response?.data?.items)) return response.data.items
  return []
}

function unwrapItem(response) {
  if (response && typeof response === 'object' && 'data' in response) {
    return response.data ?? null
  }
  return response ?? null
}

function formatDate(value) {
  if (!value) return '—'
  try {
    return new Date(value).toLocaleDateString()
  } catch {
    return value
  }
}

function formatMoney(value) {
  if (value === null || value === undefined || value === '') return '—'
  const num = Number(value)
  if (Number.isNaN(num)) return value
  return num.toLocaleString(undefined, { style: 'currency', currency: 'USD' })
}

function getUnitIdentifier(repair) {
  return (
    repair?.unit_identifier ||
    repair?.unit_license_plate ||
    repair?.unit_vin ||
    repair?.unit_name ||
    (repair?.unit_id ? `Unit #${repair.unit_id}` : '—')
  )
}

const EMPTY_CREATE = {
  unit_id: '',
  shop_name: '',
  in_date: '',
  description: '',
  estimated_cost: '',
}

export default function ExternalRepairs() {
  const { success, error } = useToast()
  const [searchParams, setSearchParams] = useSearchParams()

  const [repairs, setRepairs] = useState([])
  const [loading, setLoading] = useState(true)
  const [statusFilter, setStatusFilter] = useState('')
  const [unitFilter, setUnitFilter] = useState('')
  const [search, setSearch] = useState('')

  const [detail, setDetail] = useState(null)
  const [detailLoading, setDetailLoading] = useState(false)
  const [detailForm, setDetailForm] = useState(null)
  const [detailSaving, setDetailSaving] = useState(false)

  const [createOpen, setCreateOpen] = useState(false)
  const [createForm, setCreateForm] = useState(EMPTY_CREATE)
  const [creating, setCreating] = useState(false)

  const loadRepairs = useCallback(async () => {
    setLoading(true)
    try {
      const params = {}
      if (statusFilter) params.status = statusFilter
      if (unitFilter) params.unit_id = unitFilter
      if (search.trim()) params.query = search.trim()
      const response = await fleetService.listExternalRepairs(params)
      setRepairs(unwrapList(response))
    } catch {
      error('Failed to load external repairs')
      setRepairs([])
    } finally {
      setLoading(false)
    }
  }, [statusFilter, unitFilter, search, error])

  useEffect(() => {
    loadRepairs()
  }, [loadRepairs])

  const openDetail = useCallback(
    async (id) => {
      setDetail({ id })
      setDetailLoading(true)
      try {
        const response = await fleetService.getExternalRepair(id)
        const data = unwrapItem(response)
        setDetail(data)
        setDetailForm({
          shop_name: data?.shop_name ?? '',
          in_date: data?.in_date ?? '',
          out_date: data?.out_date ?? '',
          description: data?.description ?? '',
          cost: data?.cost ?? data?.total_cost ?? '',
          estimated_cost: data?.estimated_cost ?? '',
          status: data?.status ?? 'pending',
        })
      } catch {
        error('Failed to load external repair')
        setDetail(null)
      } finally {
        setDetailLoading(false)
      }
    },
    [error]
  )

  // Deep-link via ?id=
  useEffect(() => {
    const idParam = searchParams.get('id')
    if (idParam && (!detail || String(detail.id) !== String(idParam))) {
      openDetail(idParam)
    }
  }, [searchParams, detail, openDetail])

  const closeDetail = () => {
    setDetail(null)
    setDetailForm(null)
    if (searchParams.get('id')) {
      const next = new URLSearchParams(searchParams)
      next.delete('id')
      setSearchParams(next, { replace: true })
    }
  }

  const handleSearch = (event) => {
    event.preventDefault()
    loadRepairs()
  }

  const updateDetailForm = (field) => (value) => {
    setDetailForm((prev) => ({ ...prev, [field]: value }))
  }

  const saveDetail = async (event) => {
    event.preventDefault()
    if (!detail?.id) return
    setDetailSaving(true)
    try {
      const payload = {
        shop_name: detailForm.shop_name?.trim() || null,
        in_date: detailForm.in_date || null,
        out_date: detailForm.out_date || null,
        description: detailForm.description || null,
        cost: detailForm.cost === '' || detailForm.cost === null ? null : Number(detailForm.cost),
        estimated_cost:
          detailForm.estimated_cost === '' || detailForm.estimated_cost === null
            ? null
            : Number(detailForm.estimated_cost),
        status: detailForm.status,
      }
      await fleetService.updateExternalRepair(detail.id, payload)
      success('External repair updated')
      closeDetail()
      loadRepairs()
    } catch (e) {
      error(e?.response?.data?.message || 'Failed to update repair')
    } finally {
      setDetailSaving(false)
    }
  }

  const updateCreate = (field) => (value) => {
    setCreateForm((prev) => ({ ...prev, [field]: value }))
  }

  const handleCreate = async (event) => {
    event.preventDefault()
    if (!createForm.unit_id) {
      error('Unit ID is required')
      return
    }
    if (!createForm.shop_name.trim()) {
      error('Shop name is required')
      return
    }
    setCreating(true)
    try {
      await fleetService.createExternalRepair(createForm.unit_id, {
        shop_name: createForm.shop_name.trim(),
        in_date: createForm.in_date || null,
        description: createForm.description || null,
        estimated_cost: createForm.estimated_cost ? Number(createForm.estimated_cost) : null,
      })
      success('External repair created')
      setCreateOpen(false)
      setCreateForm(EMPTY_CREATE)
      loadRepairs()
    } catch (e) {
      error(e?.response?.data?.message || 'Failed to create external repair')
    } finally {
      setCreating(false)
    }
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">External Repairs</h1>
          <p className="mt-1 text-sm text-gray-500">
            Work farmed out to third-party shops across all fleet units.
          </p>
        </div>
        <Button onClick={() => setCreateOpen(true)}>New</Button>
      </div>

      <Card>
        <form onSubmit={handleSearch} className="mb-4 grid grid-cols-1 sm:grid-cols-4 gap-3">
          <Input
            value={search}
            onUpdateModelValue={setSearch}
            placeholder="Search shop or description..."
            className="sm:col-span-2"
          />
          <Select
            value={statusFilter}
            onUpdateModelValue={setStatusFilter}
            options={STATUS_OPTIONS}
            placeholder=""
          />
          <Input
            value={unitFilter}
            onUpdateModelValue={setUnitFilter}
            placeholder="Unit ID"
            type="number"
          />
          <div className="sm:col-span-4 flex justify-end">
            <Button type="submit" variant="secondary">Apply</Button>
          </div>
        </form>

        {loading ? (
          <div className="py-10 flex justify-center">
            <Loading text="Loading external repairs..." />
          </div>
        ) : repairs.length === 0 ? (
          <div className="text-center py-12">
            <h3 className="text-sm font-medium text-gray-900">No external repairs found</h3>
            <p className="mt-1 text-sm text-gray-500">Try adjusting filters or creating one.</p>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Unit</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Shop</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">In</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Out</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Cost</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-200 bg-white">
                {repairs.map((r) => (
                  <tr
                    key={r.id}
                    className="hover:bg-gray-50 cursor-pointer"
                    onClick={() => openDetail(r.id)}
                  >
                    <td className="px-4 py-3 text-sm text-gray-700">{getUnitIdentifier(r)}</td>
                    <td className="px-4 py-3 text-sm">
                      <span className="text-primary-600 font-medium">{r.shop_name || '—'}</span>
                    </td>
                    <td className="px-4 py-3 text-sm text-gray-700">{formatDate(r.in_date)}</td>
                    <td className="px-4 py-3 text-sm text-gray-700">{formatDate(r.out_date)}</td>
                    <td className="px-4 py-3">
                      <Badge size="sm" variant={STATUS_VARIANT[r.status] || 'default'}>
                        {r.status || '—'}
                      </Badge>
                    </td>
                    <td className="px-4 py-3 text-sm text-gray-700">{formatMoney(r.cost ?? r.total_cost)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      <Modal open={!!detail} title="External Repair Detail" onClose={closeDetail} size="lg">
        {detailLoading || !detailForm ? (
          <div className="py-8 flex justify-center"><Loading /></div>
        ) : (
          <form onSubmit={saveDetail} className="space-y-3">
            <div className="text-sm text-gray-500">
              Unit: <span className="text-gray-900 font-medium">{getUnitIdentifier(detail)}</span>
            </div>
            <Input
              label="Shop name"
              value={detailForm.shop_name}
              onUpdateModelValue={updateDetailForm('shop_name')}
            />
            <div className="grid grid-cols-2 gap-3">
              <Input
                label="In date"
                type="date"
                value={detailForm.in_date}
                onUpdateModelValue={updateDetailForm('in_date')}
              />
              <Input
                label="Out date"
                type="date"
                value={detailForm.out_date}
                onUpdateModelValue={updateDetailForm('out_date')}
              />
            </div>
            <Textarea
              label="Description"
              value={detailForm.description}
              onUpdateModelValue={updateDetailForm('description')}
              rows={3}
            />
            <div className="grid grid-cols-2 gap-3">
              <Input
                label="Estimated cost"
                type="number"
                value={detailForm.estimated_cost}
                onUpdateModelValue={updateDetailForm('estimated_cost')}
              />
              <Input
                label="Cost"
                type="number"
                value={detailForm.cost}
                onUpdateModelValue={updateDetailForm('cost')}
              />
            </div>
            <Select
              label="Status"
              value={detailForm.status}
              onUpdateModelValue={updateDetailForm('status')}
              options={DETAIL_STATUS_OPTIONS}
              placeholder=""
            />
            <div className="flex justify-end gap-2 pt-2">
              <Button type="button" variant="ghost" onClick={closeDetail}>Cancel</Button>
              <Button type="submit" loading={detailSaving}>Save</Button>
            </div>
          </form>
        )}
      </Modal>

      <Modal open={createOpen} title="New External Repair" onClose={() => setCreateOpen(false)}>
        <form onSubmit={handleCreate} className="space-y-3">
          <Input
            label="Unit ID"
            type="number"
            required
            value={createForm.unit_id}
            onUpdateModelValue={updateCreate('unit_id')}
          />
          <Input
            label="Shop name"
            required
            value={createForm.shop_name}
            onUpdateModelValue={updateCreate('shop_name')}
          />
          <Input
            label="In date"
            type="date"
            value={createForm.in_date}
            onUpdateModelValue={updateCreate('in_date')}
          />
          <Textarea
            label="Description"
            value={createForm.description}
            onUpdateModelValue={updateCreate('description')}
            rows={3}
          />
          <Input
            label="Estimated cost"
            type="number"
            value={createForm.estimated_cost}
            onUpdateModelValue={updateCreate('estimated_cost')}
          />
          <div className="flex justify-end gap-2 pt-2">
            <Button type="button" variant="ghost" onClick={() => setCreateOpen(false)}>Cancel</Button>
            <Button type="submit" loading={creating}>Create</Button>
          </div>
        </form>
      </Modal>
    </div>
  )
}
