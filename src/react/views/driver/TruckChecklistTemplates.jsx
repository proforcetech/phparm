import { useCallback, useEffect, useMemo, useState } from 'react'

import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Textarea from '../../components/ui/Textarea'
import truckChecklistService from '../../../services/truck-checklist.service'
import { useToast } from '../../stores/toast'

const checklistLabels = {
  pre_trip: 'Pre-trip',
  post_trip: 'Post-trip',
}

const emptyItem = () => ({
  label: '',
  description: '',
  required: true,
})

const emptyTemplate = () => ({
  id: null,
  name: '',
  description: '',
  checklist_type: 'pre_trip',
  is_default: false,
  active: true,
  items: [emptyItem()],
})

export default function TruckChecklistTemplates() {
  const toast = useToast()
  const [loading, setLoading] = useState(false)
  const [templates, setTemplates] = useState([])
  const [form, setForm] = useState(emptyTemplate())
  const [error, setError] = useState('')

  const loadTemplates = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      const response = await truckChecklistService.listTemplates({ include_inactive: true })
      setTemplates(response.data ?? [])
    } catch (err) {
      setError(err.response?.data?.message || 'Unable to load checklist templates.')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    loadTemplates()
  }, [loadTemplates])

  const handleSelectTemplate = (template) => {
    const items = (template.items || []).map((item) => ({
      id: item.id,
      label: item.label ?? '',
      description: item.description ?? '',
      required: item.required !== false,
      display_order: item.display_order ?? 0,
    }))

    setForm({
      id: template.id,
      name: template.name ?? '',
      description: template.description ?? '',
      checklist_type: template.checklist_type ?? 'pre_trip',
      is_default: Boolean(template.is_default),
      active: Boolean(template.active),
      items: items.length > 0 ? items : [emptyItem()],
    })
    setError('')
  }

  const handleReset = () => {
    setForm(emptyTemplate())
    setError('')
  }

  const handleItemChange = (index, field, value) => {
    setForm((prev) => {
      const nextItems = [...prev.items]
      nextItems[index] = { ...nextItems[index], [field]: value }
      return { ...prev, items: nextItems }
    })
  }

  const addItem = () => {
    setForm((prev) => ({ ...prev, items: [...prev.items, emptyItem()] }))
  }

  const removeItem = (index) => {
    setForm((prev) => {
      const nextItems = prev.items.filter((_, idx) => idx !== index)
      return { ...prev, items: nextItems.length > 0 ? nextItems : [emptyItem()] }
    })
  }

  const saveTemplate = async () => {
    if (!form.name.trim()) {
      setError('Template name is required.')
      return
    }

    setLoading(true)
    setError('')
    try {
      const payload = {
        name: form.name,
        description: form.description,
        checklist_type: form.checklist_type,
        is_default: form.is_default,
        active: form.active,
        items: form.items.map((item, index) => ({
          label: item.label,
          description: item.description,
          required: item.required,
          display_order: index + 1,
        })),
      }

      if (form.id) {
        await truckChecklistService.updateTemplate(form.id, payload)
        toast.success('Checklist template updated.')
      } else {
        await truckChecklistService.createTemplate(payload)
        toast.success('Checklist template created.')
      }

      handleReset()
      await loadTemplates()
    } catch (err) {
      setError(err.response?.data?.message || 'Unable to save checklist template.')
    } finally {
      setLoading(false)
    }
  }

  const deleteTemplate = async (templateId) => {
    if (!window.confirm('Delete this checklist template?')) return
    setLoading(true)
    setError('')
    try {
      await truckChecklistService.deleteTemplate(templateId)
      toast.success('Checklist template deleted.')
      if (form.id === templateId) {
        handleReset()
      }
      await loadTemplates()
    } catch (err) {
      setError(err.response?.data?.message || 'Unable to delete checklist template.')
    } finally {
      setLoading(false)
    }
  }

  const selectedLabel = useMemo(() => checklistLabels[form.checklist_type] || 'Checklist', [form.checklist_type])

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-gray-900">Truck Checklist Templates</h1>
        <p className="text-sm text-gray-600">Manage pre-trip and post-trip checklist templates for drivers.</p>
      </div>

      {error ? <div className="text-sm text-red-600">{error}</div> : null}

      <div className="grid gap-6 lg:grid-cols-[320px_minmax(0,1fr)]">
        <Card title="Templates" className="h-fit">
          <div className="space-y-3">
            <Button variant="outline" onClick={handleReset} className="w-full">
              New Template
            </Button>

            {loading && templates.length === 0 ? (
              <div className="text-sm text-gray-500">Loading templates...</div>
            ) : null}

            {templates.map((template) => (
              <div
                key={template.id}
                className={`rounded border p-3 transition ${form.id === template.id ? 'border-primary-500 bg-primary-50' : 'border-gray-200'}`}
              >
                <div className="flex items-start justify-between gap-2">
                  <div>
                    <div className="text-sm font-semibold text-gray-900">{template.name}</div>
                    <div className="text-xs text-gray-500">{checklistLabels[template.checklist_type] || template.checklist_type}</div>
                  </div>
                  <div className="flex flex-col gap-1 items-end">
                    {template.is_default ? <Badge variant="success">Default</Badge> : null}
                    {!template.active ? <Badge variant="warning">Inactive</Badge> : null}
                  </div>
                </div>
                <div className="mt-2 flex items-center justify-between text-xs text-gray-500">
                  <span>{template.items?.length || 0} items</span>
                  <div className="flex gap-2">
                    <button className="text-indigo-600" onClick={() => handleSelectTemplate(template)} type="button">
                      Edit
                    </button>
                    <button className="text-red-600" onClick={() => deleteTemplate(template.id)} type="button">
                      Delete
                    </button>
                  </div>
                </div>
              </div>
            ))}
          </div>
        </Card>

        <Card title={`${form.id ? 'Edit' : 'Create'} ${selectedLabel} Template`}>
          <div className="space-y-4">
            <Input
              label="Template name"
              value={form.name}
              onChange={(event) => setForm((prev) => ({ ...prev, name: event.target.value }))}
              placeholder="e.g., Standard Pre-Trip Checklist"
            />

            <Textarea
              label="Description"
              value={form.description}
              onChange={(event) => setForm((prev) => ({ ...prev, description: event.target.value }))}
              placeholder="Optional description"
            />

            <div className="grid gap-4 md:grid-cols-3">
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Checklist type</label>
                <select
                  className="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm"
                  value={form.checklist_type}
                  onChange={(event) => setForm((prev) => ({ ...prev, checklist_type: event.target.value }))}
                >
                  <option value="pre_trip">Pre-trip</option>
                  <option value="post_trip">Post-trip</option>
                </select>
              </div>
              <label className="flex items-center gap-2 text-sm text-gray-700 mt-6">
                <input
                  type="checkbox"
                  checked={form.is_default}
                  onChange={(event) => setForm((prev) => ({ ...prev, is_default: event.target.checked }))}
                />
                Default template
              </label>
              <label className="flex items-center gap-2 text-sm text-gray-700 mt-6">
                <input
                  type="checkbox"
                  checked={form.active}
                  onChange={(event) => setForm((prev) => ({ ...prev, active: event.target.checked }))}
                />
                Active
              </label>
            </div>

            <div className="space-y-3">
              <div className="flex items-center justify-between">
                <h3 className="text-sm font-semibold text-gray-900">Checklist items</h3>
                <Button variant="outline" size="sm" onClick={addItem}>
                  Add item
                </Button>
              </div>

              {form.items.map((item, index) => (
                <div key={`${item.id || 'new'}-${index}`} className="rounded border border-gray-200 p-3 space-y-3">
                  <Input
                    label={`Item ${index + 1}`}
                    value={item.label}
                    onChange={(event) => handleItemChange(index, 'label', event.target.value)}
                    placeholder="Checklist item"
                  />
                  <Textarea
                    label="Details"
                    value={item.description}
                    onChange={(event) => handleItemChange(index, 'description', event.target.value)}
                    rows={2}
                    placeholder="Optional details"
                  />
                  <div className="flex items-center justify-between">
                    <label className="flex items-center gap-2 text-sm text-gray-700">
                      <input
                        type="checkbox"
                        checked={item.required}
                        onChange={(event) => handleItemChange(index, 'required', event.target.checked)}
                      />
                      Required
                    </label>
                    <Button variant="ghost" size="sm" onClick={() => removeItem(index)}>
                      Remove
                    </Button>
                  </div>
                </div>
              ))}
            </div>

            <div className="flex justify-end gap-2">
              <Button variant="outline" onClick={handleReset} disabled={loading}>
                Reset
              </Button>
              <Button onClick={saveTemplate} disabled={loading}>
                {form.id ? 'Update Template' : 'Create Template'}
              </Button>
            </div>
          </div>
        </Card>
      </div>
    </div>
  )
}
