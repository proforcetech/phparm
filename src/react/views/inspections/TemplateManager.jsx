import { useEffect, useState } from 'react'

import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import inspectionService from '../../../services/inspection.service'

const emptyForm = () => ({
  id: null,
  name: '',
  description: '',
  active: true,
  sections: [
    {
      name: 'General',
      items: [
        { name: 'Notes', input_type: 'text', default_value: '' },
      ],
    },
  ],
})

export default function TemplateManager() {
  const [templates, setTemplates] = useState([])
  const [error, setError] = useState('')
  const [form, setForm] = useState(emptyForm())

  const loadTemplates = async () => {
    setError('')
    try {
      const data = await inspectionService.listTemplates()
      setTemplates(data)
    } catch (err) {
      console.error(err)
      setError('Unable to load templates')
    }
  }

  useEffect(() => {
    loadTemplates()
  }, [])

  const addSection = () => {
    setForm((prev) => ({
      ...prev,
      sections: [...prev.sections, { name: 'New Section', items: [{ name: 'New Item', input_type: 'text', default_value: '' }] }],
    }))
  }

  const removeSection = (index) => {
    setForm((prev) => ({
      ...prev,
      sections: prev.sections.filter((_, idx) => idx !== index),
    }))
  }

  const addItem = (sectionIndex) => {
    setForm((prev) => ({
      ...prev,
      sections: prev.sections.map((section, idx) => (
        idx === sectionIndex
          ? {
            ...section,
            items: [...section.items, { name: 'Item', input_type: 'text', default_value: '', options: {} }],
          }
          : section
      )),
    }))
  }

  const removeItem = (sectionIndex, itemIndex) => {
    setForm((prev) => ({
      ...prev,
      sections: prev.sections.map((section, idx) => (
        idx === sectionIndex
          ? { ...section, items: section.items.filter((_, itemIdx) => itemIdx !== itemIndex) }
          : section
      )),
    }))
  }

  const onFieldTypeChange = (sectionIndex, itemIndex, value) => {
    setForm((prev) => ({
      ...prev,
      sections: prev.sections.map((section, sIdx) => {
        if (sIdx !== sectionIndex) return section
        return {
          ...section,
          items: section.items.map((item, iIdx) => {
            if (iIdx !== itemIndex) return item
            const nextItem = { ...item, input_type: value }
            if (value === 'number_scale') {
              nextItem.options = { min: 0, max: 10, step: 1 }
            } else if (value === 'select_scale') {
              nextItem.options = { choices: ['Excellent', 'Good', 'Fair', 'Poor'] }
            } else {
              nextItem.options = {}
            }
            return nextItem
          }),
        }
      }),
    }))
  }

  const addScaleChoice = (sectionIndex, itemIndex) => {
    setForm((prev) => ({
      ...prev,
      sections: prev.sections.map((section, sIdx) => {
        if (sIdx !== sectionIndex) return section
        return {
          ...section,
          items: section.items.map((item, iIdx) => {
            if (iIdx !== itemIndex) return item
            const choices = item.options?.choices ? [...item.options.choices] : []
            return { ...item, options: { ...item.options, choices: [...choices, ''] } }
          }),
        }
      }),
    }))
  }

  const removeScaleChoice = (sectionIndex, itemIndex, choiceIndex) => {
    setForm((prev) => ({
      ...prev,
      sections: prev.sections.map((section, sIdx) => {
        if (sIdx !== sectionIndex) return section
        return {
          ...section,
          items: section.items.map((item, iIdx) => {
            if (iIdx !== itemIndex) return item
            if (!item.options?.choices || item.options.choices.length <= 2) return item
            return {
              ...item,
              options: {
                ...item.options,
                choices: item.options.choices.filter((_, cIdx) => cIdx !== choiceIndex),
              },
            }
          }),
        }
      }),
    }))
  }

  const updateFormField = (field, value) => {
    setForm((prev) => ({ ...prev, [field]: value }))
  }

  const updateSectionField = (sectionIndex, value) => {
    setForm((prev) => ({
      ...prev,
      sections: prev.sections.map((section, idx) => (
        idx === sectionIndex ? { ...section, name: value } : section
      )),
    }))
  }

  const updateItemField = (sectionIndex, itemIndex, field, value) => {
    setForm((prev) => ({
      ...prev,
      sections: prev.sections.map((section, sIdx) => {
        if (sIdx !== sectionIndex) return section
        return {
          ...section,
          items: section.items.map((item, iIdx) => (
            iIdx === itemIndex ? { ...item, [field]: value } : item
          )),
        }
      }),
    }))
  }

  const updateScaleChoice = (sectionIndex, itemIndex, choiceIndex, value) => {
    setForm((prev) => ({
      ...prev,
      sections: prev.sections.map((section, sIdx) => {
        if (sIdx !== sectionIndex) return section
        return {
          ...section,
          items: section.items.map((item, iIdx) => {
            if (iIdx !== itemIndex) return item
            const nextChoices = item.options?.choices ? [...item.options.choices] : []
            nextChoices[choiceIndex] = value
            return { ...item, options: { ...item.options, choices: nextChoices } }
          }),
        }
      }),
    }))
  }

  const resetForm = () => {
    setForm(emptyForm())
  }

  const loadTemplate = (template) => {
    setForm(JSON.parse(JSON.stringify(template)))
  }

  const submit = async () => {
    setError('')
    try {
      if (form.id) {
        await inspectionService.updateTemplate(form.id, form)
      } else {
        await inspectionService.createTemplate(form)
      }
      resetForm()
      await loadTemplates()
    } catch (err) {
      console.error(err)
      setError(err.response?.data?.message || 'Unable to save template')
    }
  }

  const deleteTemplate = async (id) => {
    if (!window.confirm('Delete this template?')) return
    try {
      await inspectionService.deleteTemplate(id)
      await loadTemplates()
    } catch (err) {
      console.error(err)
      setError('Unable to delete template')
    }
  }

  return (
    <div className="p-6 space-y-6">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-semibold">Inspection Templates</h1>
        <Button onClick={resetForm}>New Template</Button>
      </div>

      <div className="grid gap-6 md:grid-cols-2">
        <Card>
          <h2 className="text-lg font-semibold mb-4">{form.id ? 'Edit Template' : 'Create Template'}</h2>
          <div className="space-y-4">
            <div>
              <label className="block text-sm font-medium text-gray-700">Name</label>
              <input
                value={form.name}
                onChange={(event) => updateFormField('name', event.target.value)}
                type="text"
                className="w-full p-2 border rounded"
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700">Description</label>
              <textarea
                value={form.description}
                onChange={(event) => updateFormField('description', event.target.value)}
                className="w-full p-2 border rounded"
                rows={2}
              />
            </div>
            <div className="flex items-center space-x-2">
              <input
                checked={form.active}
                onChange={(event) => updateFormField('active', event.target.checked)}
                type="checkbox"
                id="active"
              />
              <label htmlFor="active" className="text-sm">Active</label>
            </div>

            <div className="space-y-4">
              <div className="flex items-center justify-between">
                <h3 className="font-semibold">Sections</h3>
                <button className="text-indigo-600" onClick={addSection}>+ Add Section</button>
              </div>
              {form.sections.map((section, sIndex) => (
                <div key={`section-${sIndex}`} className="p-3 border rounded space-y-3">
                  <div className="flex items-center space-x-2">
                    <input
                      value={section.name}
                      onChange={(event) => updateSectionField(sIndex, event.target.value)}
                      placeholder="Section name"
                      className="flex-1 p-2 border rounded"
                      type="text"
                    />
                    <button className="text-sm text-red-600" onClick={() => removeSection(sIndex)}>Remove</button>
                  </div>
                  <div className="flex items-center justify-between">
                    <span className="text-sm font-medium">Items</span>
                    <button className="text-indigo-600 text-sm" onClick={() => addItem(sIndex)}>+ Add Item</button>
                  </div>
                  {section.items.map((item, iIndex) => (
                    <div key={`item-${iIndex}`} className="p-3 border-2 rounded space-y-2 bg-gray-50">
                      <input
                        value={item.name}
                        onChange={(event) => updateItemField(sIndex, iIndex, 'name', event.target.value)}
                        placeholder="Item name"
                        className="w-full p-2 border rounded"
                        type="text"
                      />

                      <div>
                        <label className="block text-xs font-medium text-gray-700 mb-1">Field Type</label>
                        <select
                          value={item.input_type}
                          onChange={(event) => onFieldTypeChange(sIndex, iIndex, event.target.value)}
                          className="w-full p-2 border rounded"
                        >
                          <option value="text">Text</option>
                          <option value="textarea">Textarea</option>
                          <option value="boolean">Yes/No</option>
                          <option value="boolean_na">Yes/No/N/A</option>
                          <option value="number">Number (free input)</option>
                          <option value="number_scale">Number (scale)</option>
                          <option value="select_scale">Written Scale</option>
                        </select>
                      </div>

                      {item.input_type === 'number_scale' ? (
                        <div className="pl-3 border-l-2 border-indigo-300 space-y-2">
                          <p className="text-xs font-medium text-gray-700">Scale Configuration</p>
                          <div className="grid grid-cols-3 gap-2">
                            <div>
                              <label className="block text-xs text-gray-600">Min</label>
                              <input
                                value={item.options?.min ?? 0}
                                onChange={(event) => updateItemField(sIndex, iIndex, 'options', { ...item.options, min: Number(event.target.value) })}
                                type="number"
                                className="w-full p-1 text-sm border rounded"
                                placeholder="0"
                              />
                            </div>
                            <div>
                              <label className="block text-xs text-gray-600">Max</label>
                              <input
                                value={item.options?.max ?? 10}
                                onChange={(event) => updateItemField(sIndex, iIndex, 'options', { ...item.options, max: Number(event.target.value) })}
                                type="number"
                                className="w-full p-1 text-sm border rounded"
                                placeholder="10"
                              />
                            </div>
                            <div>
                              <label className="block text-xs text-gray-600">Step</label>
                              <input
                                value={item.options?.step ?? 1}
                                onChange={(event) => updateItemField(sIndex, iIndex, 'options', { ...item.options, step: Number(event.target.value) })}
                                type="number"
                                step="0.1"
                                className="w-full p-1 text-sm border rounded"
                                placeholder="1"
                              />
                            </div>
                          </div>
                        </div>
                      ) : null}

                      {item.input_type === 'select_scale' ? (
                        <div className="pl-3 border-l-2 border-indigo-300 space-y-2">
                          <p className="text-xs font-medium text-gray-700">Scale Options</p>
                          <div className="space-y-1">
                            {(item.options?.choices || []).map((choice, cIndex) => (
                              <div key={`choice-${cIndex}`} className="flex items-center space-x-2">
                                <input
                                  value={choice}
                                  onChange={(event) => updateScaleChoice(sIndex, iIndex, cIndex, event.target.value)}
                                  type="text"
                                  className="flex-1 p-1 text-sm border rounded"
                                  placeholder={`Option ${cIndex + 1}`}
                                />
                                {(item.options?.choices || []).length > 2 ? (
                                  <button
                                    className="text-xs text-red-600 hover:text-red-800"
                                    onClick={() => removeScaleChoice(sIndex, iIndex, cIndex)}
                                  >
                                    ×
                                  </button>
                                ) : null}
                              </div>
                            ))}
                            <button className="text-xs text-indigo-600 hover:text-indigo-800" onClick={() => addScaleChoice(sIndex, iIndex)}>
                              + Add Option
                            </button>
                          </div>
                          <p className="text-xs text-gray-500 italic">Examples: Excellent, Good, Fair, Poor</p>
                        </div>
                      ) : null}

                      {!['number_scale', 'select_scale'].includes(item.input_type) ? (
                        <input
                          value={item.default_value || ''}
                          onChange={(event) => updateItemField(sIndex, iIndex, 'default_value', event.target.value)}
                          placeholder="Default value (optional)"
                          className="w-full p-2 border rounded text-sm"
                          type="text"
                        />
                      ) : null}

                      <button className="text-xs text-red-600 hover:text-red-800 font-medium" onClick={() => removeItem(sIndex, iIndex)}>
                        Remove Item
                      </button>
                    </div>
                  ))}
                </div>
              ))}
            </div>

            <div className="flex space-x-2">
              <Button onClick={submit}>Save</Button>
              <Button variant="secondary" onClick={resetForm}>Cancel</Button>
            </div>
            {error ? <p className="text-sm text-red-600">{error}</p> : null}
          </div>
        </Card>

        <Card>
          <h2 className="text-lg font-semibold mb-4">Existing Templates</h2>
          <div className="space-y-4">
            {templates.map((template) => (
              <div key={template.id} className="p-3 border rounded">
                <div className="flex items-center justify-between">
                  <div>
                    <p className="font-semibold">{template.name}</p>
                    <p className="text-sm text-gray-600">{template.description}</p>
                  </div>
                  <div className="space-x-2">
                    <button className="text-indigo-600" onClick={() => loadTemplate(template)}>Edit</button>
                    <button className="text-red-600" onClick={() => deleteTemplate(template.id)}>Delete</button>
                  </div>
                </div>
                <div className="mt-2 space-y-2">
                  {(template.sections || []).map((section) => (
                    <div key={section.id} className="p-2 bg-gray-50 rounded">
                      <p className="font-semibold text-sm">{section.name}</p>
                      <ul className="pl-4 list-disc text-sm text-gray-700">
                        {(section.items || []).map((item) => (
                          <li key={item.id}>{item.name} ({item.input_type})</li>
                        ))}
                      </ul>
                    </div>
                  ))}
                </div>
              </div>
            ))}
          </div>
        </Card>
      </div>
    </div>
  )
}
