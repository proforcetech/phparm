import { useCallback, useEffect, useMemo, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { ArrowLeftIcon } from '@heroicons/react/24/outline'

import Alert from '../../components/ui/Alert'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Select from '../../components/ui/Select'
import Textarea from '../../components/ui/Textarea'
import { cmsService } from '../../../services/cms.service'

const defaultForm = {
  name: '',
  slug: '',
  type: 'custom',
  description: '',
  content: '',
  css: '',
  javascript: '',
  cache_ttl: 3600,
  is_active: true,
}

export default function CMSComponentForm() {
  const navigate = useNavigate()
  const { id } = useParams()
  const isEditing = useMemo(() => !!id && id !== 'create', [id])
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState(null)
  const [form, setForm] = useState(defaultForm)

  const componentTypes = cmsService.getComponentTypes()

  const loadData = useCallback(async () => {
    try {
      setLoading(true)
      setError(null)

      if (isEditing) {
        const componentData = await cmsService.getComponent(id)
        setForm({
          name: componentData.name || '',
          slug: componentData.slug || '',
          type: componentData.type || 'custom',
          description: componentData.description || '',
          content: componentData.content || '',
          css: componentData.css || '',
          javascript: componentData.javascript || '',
          cache_ttl: componentData.cache_ttl || 3600,
          is_active: !!componentData.is_active,
        })
      } else {
        setForm(defaultForm)
      }
    } catch (err) {
      console.error('Failed to load data:', err)
      setError(err.response?.data?.message || 'Failed to load data')
    } finally {
      setLoading(false)
    }
  }, [id, isEditing])

  useEffect(() => {
    loadData()
  }, [loadData])

  const generateSlug = (name) => {
    if (isEditing) return

    const slug = name
      .toLowerCase()
      .trim()
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-+|-+$/g, '')

    setForm((prev) => ({ ...prev, slug }))
  }

  const saveComponent = async (event) => {
    event.preventDefault()

    try {
      setSaving(true)
      setError(null)

      if (isEditing) {
        await cmsService.updateComponent(id, form)
      } else {
        const newComponent = await cmsService.createComponent(form)
        navigate(`/cp/cms/components/${newComponent.id}`)
        return
      }

      await loadData()
    } catch (err) {
      console.error('Failed to save component:', err)
      setError(err.response?.data?.message || 'Failed to save component')
    } finally {
      setSaving(false)
    }
  }

  return (
    <div>
      <div className="mb-8">
        <div className="flex items-center gap-4">
          <Button variant="ghost" onClick={() => navigate('/cp/cms/components')}>
            <ArrowLeftIcon className="h-5 w-5" />
          </Button>
          <div>
            <h1 className="text-2xl font-bold text-gray-900">
              {isEditing ? 'Edit Component' : 'Create Component'}
            </h1>
            <p className="mt-1 text-sm text-gray-500">
              {isEditing ? 'Update your component content' : 'Create a new reusable component'}
            </p>
          </div>
        </div>
      </div>

      {loading ? (
        <div className="flex justify-center py-12">
          <Loading size="xl" text="Loading..." />
        </div>
      ) : error ? (
        <Alert variant="danger" className="mb-6">
          {error}
        </Alert>
      ) : (
        <form onSubmit={saveComponent}>
          <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div className="lg:col-span-2 space-y-6">
              <Card header={<h3 className="text-lg font-medium text-gray-900">Component Details</h3>}>
                <div className="space-y-4">
                  <Input
                    label="Name *"
                    required
                    value={form.name}
                    onUpdateModelValue={(value) => {
                      setForm((prev) => ({ ...prev, name: value }))
                      generateSlug(value)
                    }}
                  />

                  <Input
                    label="Slug *"
                    required
                    value={form.slug}
                    onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, slug: value }))}
                  />

                  <Textarea
                    label="Description"
                    rows={2}
                    placeholder="Brief description of this component..."
                    value={form.description}
                    onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, description: value }))}
                  />

                  <Textarea
                    label="HTML Content"
                    rows={12}
                    className="font-mono text-sm"
                    placeholder="Enter HTML content..."
                    value={form.content}
                    onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, content: value }))}
                  />
                </div>
              </Card>

              <Card header={<h3 className="text-lg font-medium text-gray-900">Styles & Scripts</h3>}>
                <div className="space-y-4">
                  <Textarea
                    label="CSS"
                    rows={8}
                    className="font-mono text-sm"
                    placeholder="/* Component CSS styles */"
                    value={form.css}
                    onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, css: value }))}
                  />

                  <Textarea
                    label="JavaScript"
                    rows={8}
                    className="font-mono text-sm"
                    placeholder="// Component JavaScript code"
                    value={form.javascript}
                    onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, javascript: value }))}
                  />
                </div>
              </Card>
            </div>

            <div className="space-y-6">
              <Card header={<h3 className="text-lg font-medium text-gray-900">Status</h3>}>
                <div className="space-y-4">
                  <div className="flex items-center justify-between">
                    <span className="text-sm text-gray-700">Active</span>
                    <label className="relative inline-flex items-center cursor-pointer">
                      <input
                        type="checkbox"
                        className="sr-only peer"
                        checked={form.is_active}
                        onChange={(event) =>
                          setForm((prev) => ({ ...prev, is_active: event.target.checked }))
                        }
                      />
                      <div className="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-primary-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-primary-600"></div>
                      <span className={`ml-2 text-sm font-medium ${form.is_active ? 'text-green-600' : 'text-gray-500'}`}>
                        {form.is_active ? 'Active' : 'Inactive'}
                      </span>
                    </label>
                  </div>

                  <div className="pt-4 border-t border-gray-200">
                    <Button type="submit" className="w-full" disabled={saving}>
                      {saving
                        ? 'Saving...'
                        : isEditing
                          ? 'Update Component'
                          : 'Create Component'}
                    </Button>
                  </div>
                </div>
              </Card>

              <Card header={<h3 className="text-lg font-medium text-gray-900">Component Type</h3>}>
                <div className="space-y-4">
                  <Select
                    label="Type *"
                    required
                    placeholder=""
                    options={componentTypes}
                    value={form.type}
                    onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, type: value }))}
                    helperText={(() => {
                      if (form.type === 'header') return 'Used as page header'
                      if (form.type === 'footer') return 'Used as page footer'
                      if (form.type === 'navigation') return 'Navigation menus'
                      if (form.type === 'sidebar') return 'Sidebar content'
                      if (form.type === 'widget') return 'Reusable widgets'
                      return 'Custom component'
                    })()}
                  />
                </div>
              </Card>

              <Card header={<h3 className="text-lg font-medium text-gray-900">Cache Settings</h3>}>
                <div className="space-y-4">
                  <Input
                    label="Cache TTL (seconds)"
                    type="number"
                    min="0"
                    value={form.cache_ttl}
                    onUpdateModelValue={(value) =>
                      setForm((prev) => ({ ...prev, cache_ttl: Number(value) }))
                    }
                    helperText="Set to 0 to disable caching"
                  />
                </div>
              </Card>
            </div>
          </div>
        </form>
      )}
    </div>
  )
}
