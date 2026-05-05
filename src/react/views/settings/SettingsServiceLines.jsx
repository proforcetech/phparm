import { useCallback, useEffect, useMemo, useState } from 'react'

import Alert from '../../components/ui/Alert'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Modal from '../../components/ui/Modal'
import Select from '../../components/ui/Select'
import Table from '../../components/ui/Table'
import Textarea from '../../components/ui/Textarea'
import serviceLineService from '../../../services/serviceLine.service'

// Mirrors ServiceLineRepository::ALLOWED_SUBJECT_COLUMNS — keep in sync.
// Backend rejects anything else with InvalidArgumentException.
const SUBJECT_COLUMN_OPTIONS = [
  { value: '', label: 'None (route-based, no subject)' },
  { value: 'vehicle_id', label: 'Vehicle (vehicle_id)' },
  { value: 'site_asset_id', label: 'Asset / Building (site_asset_id)' },
]

const initialFormState = {
  slug: '',
  name: '',
  description: '',
  icon: '',
  sort_order: 0,
  is_active: true,
  subject_column: '',
  subject_required: false,
  subject_label: '',
}

const generateSlug = (name) =>
  name
    .toLowerCase()
    .replace(/[^a-z0-9\s_]/g, '')
    .replace(/\s+/g, '_')
    .replace(/^_+|_+$/g, '')

export default function SettingsServiceLines() {
  const [lines, setLines] = useState([])
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [message, setMessage] = useState('')
  const [error, setError] = useState('')
  const [showModal, setShowModal] = useState(false)
  const [editingId, setEditingId] = useState(null)
  const [form, setForm] = useState(initialFormState)
  const [slugManuallyEdited, setSlugManuallyEdited] = useState(false)

  const load = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      const data = await serviceLineService.list({ includeInactive: true })
      setLines(Array.isArray(data) ? data : [])
    } catch (loadError) {
      setError(loadError?.response?.data?.message || loadError?.message || 'Unable to load service lines.')
      setLines([])
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    load()
  }, [load])

  const updateField = (key, value) => {
    setForm((prev) => {
      const next = { ...prev, [key]: value }
      if (key === 'name' && !slugManuallyEdited && !editingId) {
        next.slug = generateSlug(value)
      }
      // Required only makes sense when there's a subject column. Clear it
      // when switching to route-based so the saved row stays consistent.
      if (key === 'subject_column' && !value) {
        next.subject_required = false
        next.subject_label = ''
      }
      return next
    })
  }

  const resetForm = () => {
    setForm(initialFormState)
    setEditingId(null)
    setSlugManuallyEdited(false)
  }

  const openCreate = () => {
    resetForm()
    setShowModal(true)
  }

  const openEdit = (line) => {
    setEditingId(line.id)
    setForm({
      slug: line.slug || '',
      name: line.name || '',
      description: line.description || '',
      icon: line.icon || '',
      sort_order: Number(line.sort_order ?? 0),
      is_active: Boolean(line.is_active),
      subject_column: line.subject_column || '',
      subject_required: Boolean(line.subject_required),
      subject_label: line.subject_label || '',
    })
    setSlugManuallyEdited(true)
    setShowModal(true)
  }

  const closeModal = () => {
    setShowModal(false)
    resetForm()
  }

  const buildPayload = () => {
    const payload = {
      name: form.name.trim(),
      description: form.description.trim() || null,
      icon: form.icon.trim() || null,
      sort_order: Number(form.sort_order) || 0,
      is_active: !!form.is_active,
      subject_column: form.subject_column || null,
      subject_required: !!form.subject_column && !!form.subject_required,
      subject_label: form.subject_column ? (form.subject_label.trim() || null) : null,
    }
    if (!editingId) {
      payload.slug = form.slug.trim()
    }
    return payload
  }

  const handleSubmit = async (event) => {
    event?.preventDefault()
    setSaving(true)
    setMessage('')
    setError('')

    try {
      const payload = buildPayload()
      if (editingId) {
        await serviceLineService.update(editingId, payload)
        setMessage('Service line updated.')
      } else {
        await serviceLineService.create(payload)
        setMessage('Service line created.')
      }
      closeModal()
      await load()
    } catch (submitError) {
      setError(submitError?.response?.data?.message || submitError?.message || 'Failed to save service line.')
    } finally {
      setSaving(false)
    }
  }

  const columns = useMemo(() => ([
    { key: 'sort_order', label: 'Order' },
    { key: 'name', label: 'Name' },
    { key: 'slug', label: 'Slug' },
    { key: 'subject', label: 'Subject FK' },
    { key: 'is_active', label: 'Active' },
    { key: 'actions', label: '' },
  ]), [])

  const cellRenderers = useMemo(() => ({
    name: ({ row }) => (
      <div className="flex items-center gap-2">
        {row.icon ? <span aria-hidden>{row.icon}</span> : null}
        <span className="font-medium text-gray-900">{row.name}</span>
      </div>
    ),
    subject: ({ row }) => {
      if (!row.subject_column) {
        return <span className="text-gray-400">— route-based —</span>
      }
      const label = row.subject_label || row.subject_column
      return (
        <span className="text-sm text-gray-700">
          {label}
          {row.subject_required ? (
            <span className="ml-1 rounded bg-amber-100 px-1.5 py-0.5 text-xs text-amber-800">required</span>
          ) : (
            <span className="ml-1 rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-600">optional</span>
          )}
        </span>
      )
    },
    is_active: ({ value }) => (value ? 'Yes' : 'No'),
    actions: ({ row }) => (
      <div className="flex items-center justify-end gap-2">
        <Button variant="outline" size="sm" onClick={() => openEdit(row)}>
          Edit
        </Button>
      </div>
    ),
  }), [])

  return (
    <div>
      <div className="mb-6 flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Service lines</h1>
          <p className="mt-1 text-sm text-gray-500">
            Define the trades your shop covers and the subject FK each one needs.
            Lines without a subject column produce generic estimates.
          </p>
        </div>
        <Button onClick={openCreate}>Add service line</Button>
      </div>

      {message ? <Alert variant="success" className="mb-4">{message}</Alert> : null}
      {error ? <Alert variant="danger" className="mb-4">{error}</Alert> : null}

      <Card>
        <Table
          columns={columns}
          data={lines}
          loading={loading}
          hoverable
          cellRenderers={cellRenderers}
          renderEmpty="No service lines configured yet."
        />
      </Card>

      <Modal
        open={showModal}
        title={editingId ? 'Edit service line' : 'Add service line'}
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
                placeholder="Auto Repair"
                className="mt-1"
                required
                onChange={(event) => updateField('name', event.target.value)}
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700">
                Slug <span className="text-red-500">*</span>
              </label>
              <Input
                value={form.slug}
                placeholder="auto_repair"
                className="mt-1"
                required
                disabled={!!editingId}
                onChange={(event) => {
                  setSlugManuallyEdited(true)
                  setForm((prev) => ({ ...prev, slug: event.target.value }))
                }}
              />
              <p className="mt-1 text-xs text-gray-500">
                {editingId
                  ? 'Slug is the stable external key and cannot be changed.'
                  : 'Lowercase letters, numbers, and underscores only.'}
              </p>
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700">Icon</label>
              <Input
                value={form.icon}
                placeholder="🔧"
                className="mt-1"
                onChange={(event) => updateField('icon', event.target.value)}
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700">Sort order</label>
              <Input
                type="number"
                min="0"
                value={form.sort_order}
                className="mt-1"
                onChange={(event) => updateField('sort_order', event.target.value)}
              />
            </div>
            <div className="md:col-span-2">
              <label className="block text-sm font-medium text-gray-700">Description</label>
              <Textarea
                value={form.description}
                rows={2}
                placeholder="What kind of work this line covers."
                className="mt-1"
                onChange={(event) => updateField('description', event.target.value)}
              />
            </div>
          </div>

          <fieldset className="rounded-md border border-gray-200 p-4">
            <legend className="px-2 text-sm font-medium text-gray-700">Subject FK rule</legend>
            <p className="mb-3 text-xs text-gray-500">
              Tells estimates, work orders, and invoices which column on the parent row
              identifies the subject of work. Lines without a subject column are
              route-based and produce generic documents.
            </p>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label className="block text-sm font-medium text-gray-700">Subject column</label>
                <Select
                  value={form.subject_column}
                  onChange={(event) => updateField('subject_column', event.target.value)}
                  options={SUBJECT_COLUMN_OPTIONS}
                  placeholder=""
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700">Display label</label>
                <Input
                  value={form.subject_label}
                  placeholder="Vehicle"
                  className="mt-1"
                  disabled={!form.subject_column}
                  onChange={(event) => updateField('subject_label', event.target.value)}
                />
                <p className="mt-1 text-xs text-gray-500">
                  Shown above the picker in the React UI. Falls back to a sensible default.
                </p>
              </div>
              <div className="md:col-span-2 flex items-center gap-2">
                <input
                  id="subject-required"
                  type="checkbox"
                  className="h-4 w-4 rounded border-gray-300 text-primary-600"
                  checked={form.subject_required}
                  disabled={!form.subject_column}
                  onChange={(event) => updateField('subject_required', event.target.checked)}
                />
                <label htmlFor="subject-required" className="text-sm text-gray-700">
                  Subject is required (rejects estimates, WOs, invoices that don't supply it)
                </label>
              </div>
            </div>
          </fieldset>

          <div className="flex items-center gap-2">
            <input
              id="line-active"
              type="checkbox"
              checked={form.is_active}
              className="h-4 w-4 rounded border-gray-300 text-primary-600"
              onChange={(event) => updateField('is_active', event.target.checked)}
            />
            <label htmlFor="line-active" className="text-sm font-medium text-gray-700">
              Active
            </label>
          </div>

          <div className="flex justify-end gap-2 pt-4">
            <Button type="button" variant="outline" onClick={closeModal}>
              Cancel
            </Button>
            <Button type="submit" loading={saving}>
              {editingId ? 'Save changes' : 'Create service line'}
            </Button>
          </div>
        </form>
      </Modal>
    </div>
  )
}
