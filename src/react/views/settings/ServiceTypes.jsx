import { useCallback, useEffect, useMemo, useState } from 'react'

import Alert from '../../components/ui/Alert'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import ColorPicker from '../../components/ui/ColorPicker'
import IconPicker from '../../components/ui/IconPicker'
import Input from '../../components/ui/Input'
import Modal from '../../components/ui/Modal'
import Table from '../../components/ui/Table'
import Textarea from '../../components/ui/Textarea'
import {
  createServiceType,
  deleteServiceType,
  fetchServiceTypes,
  updateServiceType,
} from '../../../services/service-types.service'
import * as OutlineIcons from '@heroicons/react/24/outline'

const initialFormState = {
  name: '',
  alias: '',
  color: '#1F2937',
  icon: 'WrenchIcon',
  description: '',
  displayOrder: 0,
  active: true,
}

const generateAlias = (name) => {
  return name
    .toLowerCase()
    .replace(/[^a-z0-9\s]/g, '')
    .replace(/\s+/g, '_')
    .replace(/^_+|_+$/g, '')
}

export default function ServiceTypes() {
  const [form, setForm] = useState(initialFormState)
  const [serviceTypes, setServiceTypes] = useState([])
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [deleting, setDeleting] = useState(false)
  const [message, setMessage] = useState('')
  const [error, setError] = useState('')
  const [showModal, setShowModal] = useState(false)
  const [editingId, setEditingId] = useState(null)
  const [aliasManuallyEdited, setAliasManuallyEdited] = useState(false)
  const [deleteConfirmId, setDeleteConfirmId] = useState(null)

  const loadServiceTypes = useCallback(async () => {
    setLoading(true)
    setError('')

    try {
      const data = await fetchServiceTypes()
      setServiceTypes(Array.isArray(data) ? data : [])
    } catch (fetchError) {
      setError(fetchError?.message || 'Unable to load service types.')
      setServiceTypes([])
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    loadServiceTypes()
  }, [loadServiceTypes])

  const updateField = (key, value) => {
    setForm((prev) => {
      const updated = { ...prev, [key]: value }

      if (key === 'name' && !aliasManuallyEdited) {
        updated.alias = generateAlias(value)
      }

      return updated
    })
  }

  const handleAliasChange = (value) => {
    setAliasManuallyEdited(true)
    setForm((prev) => ({ ...prev, alias: value }))
  }

  const resetForm = () => {
    setForm(initialFormState)
    setEditingId(null)
    setAliasManuallyEdited(false)
  }

  const openCreateModal = () => {
    resetForm()
    setShowModal(true)
  }

  const openEditModal = (serviceType) => {
    setEditingId(serviceType.id)
    setForm({
      name: serviceType.name || '',
      alias: serviceType.alias || '',
      color: serviceType.color || '',
      icon: serviceType.icon || '',
      description: serviceType.description || '',
      displayOrder: serviceType.display_order ?? 0,
      active: serviceType.active ?? true,
    })
    setAliasManuallyEdited(true)
    setShowModal(true)
  }

  const closeModal = () => {
    setShowModal(false)
    resetForm()
  }

  const handleSubmit = async (event) => {
    event?.preventDefault()
    setSaving(true)
    setMessage('')
    setError('')

    const payload = {
      name: form.name,
      alias: form.alias,
      color: form.color,
      icon: form.icon,
      description: form.description,
      display_order: Number(form.displayOrder) || 0,
      active: form.active,
    }

    try {
      if (editingId) {
        await updateServiceType(editingId, payload)
        setMessage('Service type updated successfully.')
      } else {
        await createServiceType(payload)
        setMessage('Service type created successfully.')
      }
      closeModal()
      await loadServiceTypes()
    } catch (submitError) {
      setError(submitError?.response?.data?.message || submitError?.message || 'Failed to save service type.')
    } finally {
      setSaving(false)
    }
  }

  const handleDelete = async (id) => {
    setDeleting(true)
    setError('')

    try {
      await deleteServiceType(id)
      setMessage('Service type deleted successfully.')
      setDeleteConfirmId(null)
      await loadServiceTypes()
    } catch (deleteError) {
      setError(deleteError?.response?.data?.message || deleteError?.message || 'Failed to delete service type.')
    } finally {
      setDeleting(false)
    }
  }

  const columns = useMemo(() => ([
    { key: 'name', label: 'Name' },
    { key: 'alias', label: 'Alias' },
    { key: 'color', label: 'Color' },
    { key: 'icon', label: 'Icon' },
    { key: 'active', label: 'Active' },
    { key: 'display_order', label: 'Order' },
    { key: 'actions', label: '' },
  ]), [])

  const cellRenderers = useMemo(() => ({
    active: ({ value }) => (value ? 'Yes' : 'No'),
    color: ({ value }) => value ? (
      <div className="flex items-center gap-2">
        <span
          className="inline-block w-5 h-5 rounded border border-gray-300"
          style={{ backgroundColor: value }}
        />
        <span className="text-sm text-gray-600">{value}</span>
      </div>
    ) : '-',
    icon: ({ value }) => {
      if (!value) return '-'
      const IconComponent = OutlineIcons[value]
      return IconComponent ? (
        <div className="flex items-center gap-2">
          <IconComponent className="h-5 w-5 text-gray-600" />
          <span className="text-sm text-gray-600">{value.replace(/Icon$/, '')}</span>
        </div>
      ) : (
        <span className="text-sm text-gray-600">{value}</span>
      )
    },
    actions: ({ row }) => (
      <div className="flex items-center justify-end gap-2">
        <Button variant="outline" size="sm" onClick={() => openEditModal(row)}>
          Edit
        </Button>
        <Button variant="danger" size="sm" onClick={() => setDeleteConfirmId(row.id)}>
          Delete
        </Button>
      </div>
    ),
  }), [])

  return (
    <div>
      <div className="mb-6">
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-2xl font-bold text-gray-900">Service types</h1>
            <p className="mt-1 text-sm text-gray-500">
              Manage the categories used for estimates, bundles, and public request forms.
            </p>
          </div>
          <Button onClick={openCreateModal}>Add Service Type</Button>
        </div>
      </div>

      {message ? <Alert variant="success" className="mb-4">{message}</Alert> : null}
      {error ? <Alert variant="danger" className="mb-4">{error}</Alert> : null}

      <Card>
        <h2 className="text-lg font-semibold text-gray-900 mb-4">Existing service types</h2>
        <Table
          columns={columns}
          data={serviceTypes}
          loading={loading}
          hoverable
          cellRenderers={cellRenderers}
          renderEmpty="No service types have been created yet."
        />
      </Card>

      <Modal
        open={showModal}
        title={editingId ? 'Edit Service Type' : 'Add Service Type'}
        size="lg"
        onClose={closeModal}
      >
        <form onSubmit={handleSubmit} className="space-y-4">
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label className="block text-sm font-medium text-gray-700">
                Name <span className="text-red-500">*</span>
              </label>
              <Input
                value={form.name}
                placeholder="Steering & Suspension"
                className="mt-1"
                required
                onChange={(event) => updateField('name', event.target.value)}
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700">
                Alias <span className="text-red-500">*</span>
              </label>
              <Input
                value={form.alias}
                placeholder="steering_suspension"
                className="mt-1"
                required
                onChange={(event) => handleAliasChange(event.target.value)}
              />
              <p className="mt-1 text-xs text-gray-500">
                Auto-generated from name. Use lowercase letters, numbers, and underscores.
              </p>
            </div>
            <ColorPicker
              label="Color"
              value={form.color}
              onChange={(value) => updateField('color', value)}
            />
            <IconPicker
              label="Icon"
              value={form.icon}
              onChange={(value) => updateField('icon', value)}
            />
            <div>
              <label className="block text-sm font-medium text-gray-700">Display order</label>
              <Input
                type="number"
                min="0"
                value={form.displayOrder}
                className="mt-1"
                onChange={(event) => updateField('displayOrder', event.target.value)}
              />
            </div>
            <div className="flex items-center space-x-2 pt-6">
              <input
                id="service-type-active"
                type="checkbox"
                checked={form.active}
                className="h-4 w-4 text-indigo-600 border-gray-300 rounded"
                onChange={(event) => updateField('active', event.target.checked)}
              />
              <label htmlFor="service-type-active" className="text-sm font-medium text-gray-700">
                Active
              </label>
            </div>
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700">Description</label>
            <Textarea
              value={form.description}
              rows={3}
              placeholder="Describe what this service type covers."
              className="mt-1"
              onChange={(event) => updateField('description', event.target.value)}
            />
          </div>
          <div className="flex justify-end gap-2 pt-4">
            <Button type="button" variant="outline" onClick={closeModal}>
              Cancel
            </Button>
            <Button type="submit" loading={saving}>
              {editingId ? 'Update' : 'Create'} Service Type
            </Button>
          </div>
        </form>
      </Modal>

      <Modal
        open={!!deleteConfirmId}
        title="Delete Service Type"
        size="sm"
        onClose={() => setDeleteConfirmId(null)}
      >
        <p className="text-gray-600">
          Are you sure you want to delete this service type? This action cannot be undone.
        </p>
        <div className="flex justify-end gap-2 mt-6">
          <Button variant="outline" onClick={() => setDeleteConfirmId(null)}>
            Cancel
          </Button>
          <Button
            variant="danger"
            loading={deleting}
            onClick={() => handleDelete(deleteConfirmId)}
          >
            Delete
          </Button>
        </div>
      </Modal>
    </div>
  )
}
