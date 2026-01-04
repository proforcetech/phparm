import { useCallback, useEffect, useMemo, useState } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'

import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Modal from '../../components/ui/Modal'
import Table from '../../components/ui/Table'
import inventoryMetaService from '../../../services/inventory-meta.service'
import { useToast } from '../../stores/toast.jsx'

const labels = {
  categories: {
    title: 'Inventory Categories',
    subtitle: 'Create categories to group similar items',
    singular: 'Category',
    plural: 'Categories',
    placeholder: 'Brakes',
  },
  vendors: {
    title: 'Inventory Vendors',
    subtitle: 'Track vendors for ordering and pricing',
    singular: 'Vendor',
    plural: 'Vendors',
    placeholder: 'ACME Parts Co.',
  },
  locations: {
    title: 'Inventory Locations',
    subtitle: 'Keep locations updated for quick picking',
    singular: 'Location',
    plural: 'Locations',
    placeholder: 'Aisle 3',
  },
}

export default function InventoryLookupManager() {
  const navigate = useNavigate()
  const location = useLocation()
  const { success, error } = useToast()

  const type = useMemo(() => {
    if (location.pathname.includes('/cp/inventory/vendors')) return 'vendors'
    if (location.pathname.includes('/cp/inventory/locations')) return 'locations'
    return 'categories'
  }, [location.pathname])

  const copy = labels[type] || labels.categories

  const [items, setItems] = useState([])
  const [filteredItems, setFilteredItems] = useState([])
  const [search, setSearch] = useState('')
  const [loading, setLoading] = useState(false)
  const [saving, setSaving] = useState(false)
  const [deletingId, setDeletingId] = useState(null)
  const [showModal, setShowModal] = useState(false)
  const [editingItem, setEditingItem] = useState(null)
  const [form, setForm] = useState({
    name: '',
    description: '',
    is_parts_supplier: false,
  })

  const columns = useMemo(() => ([
    { key: 'name', label: 'Name' },
    { key: 'description', label: 'Description' },
  ]), [])

  const handleSearch = useCallback((term) => {
    const lower = term.toLowerCase()
    setFilteredItems(!lower
      ? items
      : items.filter((item) => {
        const name = (item.name || '').toLowerCase()
        const description = (item.description || '').toLowerCase()
        return name.includes(lower) || description.includes(lower)
      }))
  }, [items])

  const resetForm = () => {
    setForm({ name: '', description: '', is_parts_supplier: false })
  }

  const loadItems = useCallback(async () => {
    setLoading(true)
    try {
      const data = await inventoryMetaService.list(type)
      setItems(data)
      setFilteredItems(data)
      if (search) {
        handleSearch(search)
      }
    } catch (err) {
      console.error(err)
      error(`Unable to load ${copy.plural.toLowerCase()}`)
    } finally {
      setLoading(false)
    }
  }, [copy.plural, error, handleSearch, search, type])

  useEffect(() => {
    resetForm()
    setSearch('')
    loadItems()
  }, [loadItems, type])

  const startCreate = () => {
    setEditingItem(null)
    resetForm()
    setShowModal(true)
  }

  const startEdit = (item) => {
    setEditingItem(item)
    setForm({
      name: item.name,
      description: item.description || '',
      is_parts_supplier: Boolean(item.is_parts_supplier),
    })
    setShowModal(true)
  }

  const closeModal = () => {
    setShowModal(false)
    resetForm()
    setEditingItem(null)
  }

  const saveItem = async () => {
    setSaving(true)
    try {
      const payload = { name: form.name, description: form.description }
      if (type === 'vendors') {
        payload.is_parts_supplier = form.is_parts_supplier
      }
      if (editingItem) {
        await inventoryMetaService.update(type, editingItem.id, payload)
        success(`${copy.singular} updated`)
      } else {
        await inventoryMetaService.create(type, payload)
        success(`${copy.singular} created`)
      }
      closeModal()
      await loadItems()
    } catch (err) {
      console.error(err)
      error(`Unable to save ${copy.singular.toLowerCase()}`)
    } finally {
      setSaving(false)
    }
  }

  const deleteItem = async (item) => {
    if (!window.confirm(`Delete this ${copy.singular.toLowerCase()}?`)) return
    setDeletingId(item.id)
    try {
      await inventoryMetaService.remove(type, item.id)
      success(`${copy.singular} deleted`)
      await loadItems()
    } catch (err) {
      console.error(err)
      error(`Unable to delete ${copy.singular.toLowerCase()}`)
    } finally {
      setDeletingId(null)
    }
  }

  return (
    <div>
      <div className="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">{copy.title}</h1>
          <p className="mt-1 text-sm text-gray-500">{copy.subtitle}</p>
        </div>
        <div className="flex gap-3 flex-wrap">
          <Button variant="secondary" onClick={() => navigate('/cp/inventory')}>Back to inventory</Button>
          <Button onClick={startCreate}>
            <svg className="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 4v16m8-8H4" />
            </svg>
            Add {copy.singular}
          </Button>
        </div>
      </div>

      <Card>
        <div className="flex flex-col gap-4">
          <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
              <label className="block text-sm font-medium text-gray-700">Search</label>
              <Input
                modelValue={search}
                placeholder={`Search ${copy.plural.toLowerCase()}`}
                onUpdateModelValue={(value) => {
                  setSearch(value)
                  handleSearch(value)
                }}
              />
            </div>
          </div>

          <div className="relative">
            {loading ? <Loading overlay text={`Loading ${copy.plural.toLowerCase()}...`} /> : null}
            <Table
              columns={columns}
              data={filteredItems}
              loading={loading}
              hoverable
              cellRenderers={{
                name: ({ value }) => <div className="font-semibold text-gray-900">{value}</div>,
                description: ({ value }) => <span className="text-sm text-gray-600">{value || '—'}</span>,
              }}
              renderActions={(row) => (
                <div className="flex gap-2">
                  <Button size="sm" variant="secondary" onClick={() => startEdit(row)}>Edit</Button>
                  <Button size="sm" variant="danger" loading={deletingId === row.id} onClick={() => deleteItem(row)}>Delete</Button>
                </div>
              )}
              renderEmpty={() => <p className="text-sm text-gray-500">No {copy.plural.toLowerCase()} found.</p>}
            />
          </div>
        </div>
      </Card>

      <Modal
        open={showModal}
        title={editingItem ? `Edit ${copy.singular}` : `New ${copy.singular}`}
        onClose={closeModal}
        footer={(
          <div className="flex justify-end gap-3">
            <Button variant="secondary" onClick={closeModal}>Cancel</Button>
            <Button loading={saving} onClick={saveItem}>
              {editingItem ? 'Save changes' : `Create ${copy.singular}`}
            </Button>
          </div>
        )}
      >
        <div className="space-y-4">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Name</label>
            <Input
              modelValue={form.name}
              required
              placeholder={copy.placeholder}
              onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, name: value }))}
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Description</label>
            <textarea
              value={form.description}
              onChange={(event) => setForm((prev) => ({ ...prev, description: event.target.value }))}
              rows={3}
              className="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500 sm:text-sm"
              placeholder="Optional details"
            />
          </div>
          {type === 'vendors' ? (
            <div className="flex items-center gap-2 pt-2">
              <input
                id="partsSupplier"
                checked={form.is_parts_supplier}
                onChange={(event) => setForm((prev) => ({ ...prev, is_parts_supplier: event.target.checked }))}
                type="checkbox"
                className="h-4 w-4 rounded border-gray-300 text-primary-600 focus:ring-primary-500"
              />
              <label htmlFor="partsSupplier" className="text-sm text-gray-700">Parts supplier</label>
            </div>
          ) : null}
        </div>
      </Modal>
    </div>
  )
}
