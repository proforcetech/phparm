import { useCallback, useEffect, useMemo, useState } from 'react'

import Alert from '../../components/ui/Alert'
import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Modal from '../../components/ui/Modal'
import Select from '../../components/ui/Select'
import Textarea from '../../components/ui/Textarea'
import customFieldsService from '../../../services/custom-fields.service'
import { useToast } from '../../stores/toast.jsx'

// Must mirror CustomFieldService::SUPPORTED_ENTITIES on the backend.
const ENTITY_TYPES = [
  { value: 'customer', label: 'customer' },
  { value: 'site', label: 'site' },
  { value: 'site_asset', label: 'site_asset' },
  { value: 'workorder', label: 'workorder' },
  { value: 'ticket', label: 'ticket' },
  { value: 'estimate', label: 'estimate' },
  { value: 'invoice', label: 'invoice' },
  { value: 'appointment', label: 'appointment' },
  { value: 'vehicle', label: 'vehicle' },
  { value: 'user', label: 'user' },
  { value: 'inventory_item', label: 'inventory_item' },
  { value: 'contract', label: 'contract' },
]

// Must mirror CustomFieldService::FIELD_TYPES on the backend.
const FIELD_TYPES = [
  { value: 'text', label: 'text' },
  { value: 'number', label: 'number' },
  { value: 'date', label: 'date' },
  { value: 'select', label: 'select' },
  { value: 'multiselect', label: 'multiselect' },
  { value: 'boolean', label: 'boolean' },
  { value: 'asset_ref', label: 'asset_ref' },
]

const KEY_PATTERN = /^[a-z][a-z0-9_]*$/

const emptyForm = () => ({
  id: null,
  entity_type: ENTITY_TYPES[0].value,
  field_key: '',
  label: '',
  field_type: 'text',
  is_required: false,
  sort_order: 0,
  options: '',
  is_active: true,
})

function unwrapList(res) {
  if (Array.isArray(res)) return res
  if (Array.isArray(res?.data)) return res.data
  if (Array.isArray(res?.data?.data)) return res.data.data
  if (Array.isArray(res?.items)) return res.items
  return []
}

function formatDate(value) {
  if (!value) return '—'
  const ts = typeof value === 'string' ? Date.parse(value) : Number(value)
  if (Number.isNaN(ts)) return String(value)
  return new Date(ts).toLocaleString()
}

function renderValue(raw) {
  if (raw === null || raw === undefined || raw === '') return <span className="text-gray-400">—</span>
  if (typeof raw === 'boolean') return raw ? 'true' : 'false'
  if (typeof raw === 'object') {
    try {
      return <code className="text-xs">{JSON.stringify(raw)}</code>
    } catch {
      return String(raw)
    }
  }
  return String(raw)
}

export default function CustomFields() {
  const { success, error } = useToast()

  const [filterEntity, setFilterEntity] = useState(ENTITY_TYPES[0].value)
  const [definitions, setDefinitions] = useState([])
  const [defsLoading, setDefsLoading] = useState(true)
  const [defsError, setDefsError] = useState('')

  const [editorOpen, setEditorOpen] = useState(false)
  const [editorMode, setEditorMode] = useState('create')
  const [editorBusy, setEditorBusy] = useState(false)
  const [editorErrors, setEditorErrors] = useState({})
  const [form, setForm] = useState(emptyForm())

  const [deleteTarget, setDeleteTarget] = useState(null)
  const [deleting, setDeleting] = useState(false)

  const [inspectKind, setInspectKind] = useState(ENTITY_TYPES[0].value)
  const [inspectId, setInspectId] = useState('')
  const [inspectLoading, setInspectLoading] = useState(false)
  const [inspectError, setInspectError] = useState('')
  const [inspectValues, setInspectValues] = useState(null)

  const loadDefinitions = useCallback(() => {
    if (!filterEntity) return
    setDefsLoading(true)
    setDefsError('')
    customFieldsService
      .listDefinitions({ entity_type: filterEntity, include_inactive: true })
      .then((res) => setDefinitions(unwrapList(res)))
      .catch((e) => {
        setDefinitions([])
        setDefsError(e?.response?.data?.error || e?.response?.data?.message || e?.message || 'Failed to load field definitions')
      })
      .finally(() => setDefsLoading(false))
  }, [filterEntity])

  useEffect(() => { loadDefinitions() }, [loadDefinitions])

  const sortedDefinitions = useMemo(() => {
    return [...definitions].sort((a, b) => (a.sort_order ?? 0) - (b.sort_order ?? 0))
  }, [definitions])

  const openCreate = () => {
    setEditorMode('create')
    setForm({ ...emptyForm(), entity_type: filterEntity || ENTITY_TYPES[0].value })
    setEditorErrors({})
    setEditorOpen(true)
  }

  const openEdit = (def) => {
    setEditorMode('edit')
    let optionsText = ''
    if (def.options !== null && def.options !== undefined) {
      optionsText = typeof def.options === 'string'
        ? def.options
        : JSON.stringify(def.options, null, 2)
    }
    setForm({
      id: def.id,
      entity_type: def.entity_type || ENTITY_TYPES[0].value,
      field_key: def.field_key || '',
      label: def.label || '',
      field_type: def.field_type || 'text',
      is_required: !!def.is_required,
      sort_order: Number(def.sort_order ?? 0),
      options: optionsText,
      is_active: def.is_active !== false,
    })
    setEditorErrors({})
    setEditorOpen(true)
  }

  const validateForm = () => {
    const errs = {}
    if (!form.entity_type) errs.entity_type = 'Required'
    if (!form.field_key) errs.field_key = 'Required'
    else if (!KEY_PATTERN.test(form.field_key)) errs.field_key = 'snake_case, starts with a letter'
    if (!form.label) errs.label = 'Required'
    if (!FIELD_TYPES.find((t) => t.value === form.field_type)) errs.field_type = 'Invalid type'
    if (form.field_type === 'select' || form.field_type === 'multiselect') {
      const trimmed = form.options.trim()
      if (!trimmed) {
        errs.options = 'Provide a JSON array of options'
      } else {
        try {
          const parsed = JSON.parse(trimmed)
          if (!Array.isArray(parsed)) errs.options = 'Must be a JSON array'
        } catch {
          errs.options = 'Invalid JSON'
        }
      }
    }
    setEditorErrors(errs)
    return Object.keys(errs).length === 0
  }

  const submitForm = async () => {
    if (!validateForm()) return
    setEditorBusy(true)
    try {
      const payload = {
        entity_type: form.entity_type,
        field_key: form.field_key,
        label: form.label,
        field_type: form.field_type,
        is_required: !!form.is_required,
        sort_order: Number(form.sort_order) || 0,
        is_active: !!form.is_active,
      }
      if (form.field_type === 'select' || form.field_type === 'multiselect') {
        payload.options = JSON.parse(form.options.trim())
      } else if (form.options.trim()) {
        try { payload.options = JSON.parse(form.options.trim()) } catch { /* ignore */ }
      }

      if (editorMode === 'create') {
        await customFieldsService.createDefinition(payload)
        success('Field definition created')
      } else {
        await customFieldsService.updateDefinition(form.id, payload)
        success('Field definition updated')
      }
      setEditorOpen(false)
      loadDefinitions()
    } catch (e) {
      error(e?.response?.data?.error || e?.response?.data?.message || 'Failed to save field definition')
    } finally {
      setEditorBusy(false)
    }
  }

  const confirmDelete = async () => {
    if (!deleteTarget) return
    setDeleting(true)
    try {
      await customFieldsService.deleteDefinition(deleteTarget.id)
      success('Field definition deleted')
      setDeleteTarget(null)
      loadDefinitions()
    } catch (e) {
      error(e?.response?.data?.error || e?.response?.data?.message || 'Failed to delete field definition')
    } finally {
      setDeleting(false)
    }
  }

  const loadInspectValues = async () => {
    if (!inspectKind || !inspectId.trim()) {
      setInspectError('Both entity type and id are required.')
      return
    }
    setInspectLoading(true)
    setInspectError('')
    setInspectValues(null)
    try {
      const res = await customFieldsService.listValues({
        entity_type: inspectKind,
        entity_id: inspectId.trim(),
      })
      const list = unwrapList(res)
      setInspectValues(list)
    } catch (e) {
      setInspectError(e?.response?.data?.error || e?.response?.data?.message || e?.message || 'Failed to load values')
    } finally {
      setInspectLoading(false)
    }
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-gray-900">Custom Fields</h1>
        <p className="mt-1 text-sm text-gray-500">
          Define custom fields per entity type and inspect saved values for any record.
        </p>
      </div>

      <Card padding={false}>
        <div className="px-6 py-4 border-b border-gray-200 flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3">
          <div>
            <h2 className="text-lg font-medium text-gray-900">Field Definitions</h2>
            <p className="text-sm text-gray-500">Schema-level configuration of custom fields.</p>
          </div>
          <div className="flex items-end gap-2">
            <Select
              label="Entity type"
              value={filterEntity}
              options={ENTITY_TYPES}
              placeholder=""
              onChange={(e) => setFilterEntity(e.target.value)}
            />
            <Button variant="secondary" onClick={loadDefinitions}>Refresh</Button>
            <Button onClick={openCreate}>New definition</Button>
          </div>
        </div>

        <div className="p-4">
          {defsError ? (
            <Alert variant="danger" onClose={() => setDefsError('')}>{defsError}</Alert>
          ) : null}

          {defsLoading ? (
            <div className="py-10 flex justify-center"><Loading text="Loading..." /></div>
          ) : sortedDefinitions.length === 0 ? (
            <div className="text-center py-12 text-gray-500">No definitions configured for this entity.</div>
          ) : (
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-gray-200">
                <thead className="bg-gray-50">
                  <tr>
                    <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Key</th>
                    <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Label</th>
                    <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                    <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Required</th>
                    <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Order</th>
                    <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Active</th>
                    <th className="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-200 bg-white">
                  {sortedDefinitions.map((def) => (
                    <tr key={def.id} className="hover:bg-gray-50">
                      <td className="px-3 py-2 text-sm font-mono text-gray-900">{def.field_key}</td>
                      <td className="px-3 py-2 text-sm text-gray-700">{def.label}</td>
                      <td className="px-3 py-2 text-sm text-gray-500">{def.field_type}</td>
                      <td className="px-3 py-2 text-sm text-gray-500">{def.is_required ? 'Yes' : 'No'}</td>
                      <td className="px-3 py-2 text-sm text-gray-500">{def.sort_order ?? 0}</td>
                      <td className="px-3 py-2">
                        {def.is_active === false
                          ? <Badge size="sm" variant="default">Inactive</Badge>
                          : <Badge size="sm" variant="success">Active</Badge>}
                      </td>
                      <td className="px-3 py-2 text-right">
                        <div className="flex justify-end gap-1">
                          <Button size="xs" variant="ghost" onClick={() => openEdit(def)}>Edit</Button>
                          <Button
                            size="xs"
                            variant="ghost"
                            className="text-red-600 hover:text-red-700"
                            onClick={() => setDeleteTarget(def)}
                          >
                            Delete
                          </Button>
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      </Card>

      <Card padding={false}>
        <div className="px-6 py-4 border-b border-gray-200">
          <h2 className="text-lg font-medium text-gray-900">Inspect Values for a Record</h2>
          <p className="text-sm text-gray-500">
            To edit field values inline, use the affected entity&apos;s detail page
            (e.g. customer detail, workorder detail) — this view is for auditing
            and troubleshooting.
          </p>
        </div>

        <div className="p-4 space-y-4">
          <div className="grid gap-3 sm:grid-cols-3">
            <Select
              label="Entity type"
              value={inspectKind}
              options={ENTITY_TYPES}
              placeholder=""
              onChange={(e) => setInspectKind(e.target.value)}
            />
            <Input
              label="Entity ID"
              value={inspectId}
              onChange={(e) => setInspectId(e.target.value)}
              placeholder="e.g. 42"
            />
            <div className="flex items-end">
              <Button onClick={loadInspectValues} disabled={!inspectId.trim()}>Load</Button>
            </div>
          </div>

          {inspectError ? (
            <Alert variant="danger" onClose={() => setInspectError('')}>{inspectError}</Alert>
          ) : null}

          {inspectLoading ? (
            <div className="py-6 flex justify-center"><Loading /></div>
          ) : inspectValues === null ? (
            <p className="text-sm text-gray-500">Provide an entity type and id, then click Load.</p>
          ) : inspectValues.length === 0 ? (
            <p className="text-sm text-gray-500">No custom field values stored for this record.</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-gray-200">
                <thead className="bg-gray-50">
                  <tr>
                    <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Key</th>
                    <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Value</th>
                    <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Updated</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-200 bg-white">
                  {inspectValues.map((row, idx) => (
                    <tr key={`${row.field_key || row.key || idx}-${idx}`} className="hover:bg-gray-50">
                      <td className="px-3 py-2 text-sm font-mono text-gray-900">
                        {row.field_key || row.key || '—'}
                      </td>
                      <td className="px-3 py-2 text-sm text-gray-700">
                        {renderValue(row.value)}
                      </td>
                      <td className="px-3 py-2 text-sm text-gray-500">
                        {formatDate(row.updated_at || row.modified_at)}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      </Card>

      <Modal
        open={editorOpen}
        onClose={() => setEditorOpen(false)}
        title={editorMode === 'create' ? 'New field definition' : 'Edit field definition'}
        size="lg"
      >
        <div className="space-y-3">
          <div className="grid gap-3 sm:grid-cols-2">
            <Select
              label="Entity type"
              value={form.entity_type}
              options={ENTITY_TYPES}
              placeholder=""
              error={editorErrors.entity_type}
              onChange={(e) => setForm({ ...form, entity_type: e.target.value })}
              disabled={editorMode === 'edit'}
            />
            <Select
              label="Field type"
              value={form.field_type}
              options={FIELD_TYPES}
              placeholder=""
              error={editorErrors.field_type}
              onChange={(e) => setForm({ ...form, field_type: e.target.value })}
            />
            <Input
              label="Field key"
              value={form.field_key}
              error={editorErrors.field_key}
              onChange={(e) => setForm({ ...form, field_key: e.target.value })}
              placeholder="snake_case_key"
              disabled={editorMode === 'edit'}
              helperText={editorMode === 'edit' ? 'Key cannot be changed after creation' : 'Lowercase letters, digits, underscores'}
            />
            <Input
              label="Label"
              value={form.label}
              error={editorErrors.label}
              onChange={(e) => setForm({ ...form, label: e.target.value })}
              placeholder="Human-readable label"
            />
            <Input
              label="Sort order"
              type="number"
              value={form.sort_order}
              onChange={(e) => setForm({ ...form, sort_order: e.target.value })}
            />
            <div className="flex items-center gap-4 pt-6">
              <label className="flex items-center gap-2 text-sm">
                <input
                  type="checkbox"
                  checked={form.is_required}
                  onChange={(e) => setForm({ ...form, is_required: e.target.checked })}
                />
                Required
              </label>
              <label className="flex items-center gap-2 text-sm">
                <input
                  type="checkbox"
                  checked={form.is_active}
                  onChange={(e) => setForm({ ...form, is_active: e.target.checked })}
                />
                Active
              </label>
            </div>
          </div>

          <Textarea
            label={`Options${form.field_type === 'select' || form.field_type === 'multiselect' ? ' (JSON array, required)' : ' (optional, only used for select/multiselect)'}`}
            value={form.options}
            error={editorErrors.options}
            onChange={(e) => setForm({ ...form, options: e.target.value })}
            placeholder='["red", "green", "blue"]'
            rows={4}
          />

          <div className="flex justify-end gap-2 pt-2 border-t">
            <Button variant="ghost" onClick={() => setEditorOpen(false)}>Cancel</Button>
            <Button loading={editorBusy} onClick={submitForm}>
              {editorMode === 'create' ? 'Create' : 'Save changes'}
            </Button>
          </div>
        </div>
      </Modal>

      <Modal
        open={!!deleteTarget}
        onClose={() => setDeleteTarget(null)}
        title="Delete field definition"
      >
        <p className="text-sm text-gray-600 mb-4">
          Delete <strong>{deleteTarget?.label || deleteTarget?.field_key || 'this definition'}</strong>?
          Existing stored values may become inaccessible. This cannot be undone.
        </p>
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={() => setDeleteTarget(null)}>Cancel</Button>
          <Button variant="danger" loading={deleting} onClick={confirmDelete}>Delete</Button>
        </div>
      </Modal>
    </div>
  )
}
