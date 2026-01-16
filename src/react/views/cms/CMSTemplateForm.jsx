import { useCallback, useEffect, useMemo, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { ArrowLeftIcon, InformationCircleIcon } from '@heroicons/react/24/outline'

import Alert from '../../components/ui/Alert'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Textarea from '../../components/ui/Textarea'
import { cmsService } from '../../../services/cms.service'

const structurePlaceholder = [
  '<!DOCTYPE html>',
  '<html>',
  '<head>',
  '    <title>{{title}}</title>',
  '    <meta name="description" content="{{meta_description}}">',
  '    <link rel="canonical" href="{{canonical_url}}">',
  '    <meta property="og:title" content="{{og_title}}">',
  '    <meta property="og:description" content="{{og_description}}">',
  '    <meta property="og:image" content="{{og_image}}">',
  '    <meta property="og:type" content="{{og_type}}">',
  '    <meta property="og:url" content="{{og_url}}">',
  '    <style>{{custom_css}}</style>',
  '</head>',
  '<body>',
  '    {{header}}',
  '    <main>{{content}}</main>',
  '    {{footer}}',
  '    <script>{{custom_js}}<\\/script>',
  '</body>',
  '</html>',
].join('\n')

const placeholders = [
  { token: '{{header}}', label: 'Header component' },
  { token: '{{content}}', label: 'Page content' },
  { token: '{{footer}}', label: 'Footer component' },
  { token: '{{title}}', label: 'Page title' },
  { token: '{{meta_description}}', label: 'Meta description' },
  { token: '{{canonical_url}}', label: 'Canonical URL' },
  { token: '{{og_title}}', label: 'Open Graph title' },
  { token: '{{og_description}}', label: 'Open Graph description' },
  { token: '{{og_image}}', label: 'Open Graph image URL' },
  { token: '{{og_type}}', label: 'Open Graph type' },
  { token: '{{og_url}}', label: 'Open Graph URL' },
  { token: '{{custom_css}}', label: 'Page custom CSS' },
  { token: '{{custom_js}}', label: 'Page custom JavaScript' },
]

const defaultForm = {
  name: '',
  slug: '',
  description: '',
  structure: '',
  default_css: '',
  default_js: '',
  is_active: true,
}

export default function CMSTemplateForm() {
  const navigate = useNavigate()
  const { id } = useParams()
  const isEditing = useMemo(() => !!id && id !== 'create', [id])

  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState(null)
  const [templateData, setTemplateData] = useState(null)
  const [form, setForm] = useState(defaultForm)

  const loadData = useCallback(async () => {
    try {
      setLoading(true)
      setError(null)

      if (isEditing) {
        const data = await cmsService.getTemplate(id)
        setTemplateData(data)
        setForm({
          name: data.name || '',
          slug: data.slug || '',
          description: data.description || '',
          structure: data.structure || '',
          default_css: data.default_css || '',
          default_js: data.default_js || '',
          is_active: !!data.is_active,
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

  const copyToClipboard = (text) => {
    if (navigator.clipboard) {
      navigator.clipboard
        .writeText(text)
        .then(() => console.log('Copied to clipboard:', text))
        .catch((err) => {
          console.error('Copy failed:', err)
          window.alert(`Copy failed: ${err}`)
        })
    } else {
      window.alert('Clipboard API not supported.')
    }
  }

  const saveTemplate = async (event) => {
    event.preventDefault()

    try {
      setSaving(true)
      setError(null)

      if (isEditing) {
        await cmsService.updateTemplate(id, form)
      } else {
        const newTemplate = await cmsService.createTemplate(form)
        navigate(`/cp/cms/templates/${newTemplate.id}`)
        return
      }

      await loadData()
    } catch (err) {
      console.error('Failed to save template:', err)

      if (err.response?.status === 409) {
        setError(err.response.data.message || 'A template with this name or slug already exists.')
      } else if (err.response?.status === 403) {
        setError('You do not have permission to perform this action.')
      } else {
        setError(err.response?.data?.message || 'Failed to save template.')
      }
    } finally {
      setSaving(false)
    }
  }

  const formatDate = (date) => {
    if (!date) return '-'
    return new Intl.DateTimeFormat('en-US', {
      month: 'short',
      day: 'numeric',
      year: 'numeric',
      hour: 'numeric',
      minute: '2-digit',
    }).format(new Date(date))
  }

  return (
    <div>
      <div className="mb-8">
        <div className="flex items-center gap-4">
          <Button variant="ghost" onClick={() => navigate('/cp/cms/templates')}>
            <ArrowLeftIcon className="h-5 w-5" />
          </Button>
          <div>
            <h1 className="text-2xl font-bold text-gray-900">
              {isEditing ? 'Edit Template' : 'Create Template'}
            </h1>
            <p className="mt-1 text-sm text-gray-500">
              Define the HTML structure and default assets for your pages.
            </p>
          </div>
        </div>
      </div>

      {loading ? (
        <div className="flex justify-center py-12">
          <Loading size="xl" text="Loading template..." />
        </div>
      ) : error ? (
        <Alert variant="danger" className="mb-6">
          {error}
        </Alert>
      ) : (
        <form onSubmit={saveTemplate}>
          <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div className="lg:col-span-2 space-y-6">
              <Card header={<h3 className="text-lg font-medium text-gray-900">Template Structure</h3>}>
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

                  <div>
                    <label className="block text-sm font-medium text-gray-700 mb-1">Slug *</label>
                    <Input
                      required
                      value={form.slug}
                      onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, slug: value }))}
                      className="flex-1"
                    />
                  </div>

                  <Textarea
                    label="Description"
                    rows={2}
                    value={form.description}
                    onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, description: value }))}
                  />

                  <div>
                    <div className="flex items-center justify-between mb-1">
                      <label className="block text-sm font-medium text-gray-700">HTML Structure *</label>
                      <button
                        type="button"
                        className="text-xs text-primary-600 hover:text-primary-700"
                        onClick={() => setForm((prev) => ({ ...prev, structure: structurePlaceholder }))}
                      >
                        Insert Default Boilerplate
                      </button>
                    </div>
                    <Textarea
                      required
                      rows={20}
                      className="font-mono text-sm bg-gray-50"
                      placeholder="<html>...</html>"
                      value={form.structure}
                      onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, structure: value }))}
                    />
                    <p className="mt-1 text-xs text-gray-500">
                      Use placeholders like <code>{'{{content}}'}</code> to inject page data.
                    </p>
                  </div>
                </div>
              </Card>

              <Card header={<h3 className="text-lg font-medium text-gray-900">Default Assets</h3>}>
                <div className="space-y-4">
                  <Textarea
                    label="Default CSS"
                    rows={10}
                    className="font-mono text-sm"
                    placeholder="/* CSS applied to all pages using this template */"
                    value={form.default_css}
                    onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, default_css: value }))}
                  />
                  <Textarea
                    label="Default JavaScript"
                    rows={10}
                    className="font-mono text-sm"
                    placeholder="// JS applied to all pages using this template"
                    value={form.default_js}
                    onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, default_js: value }))}
                  />
                </div>
              </Card>
            </div>

            <div className="space-y-6">
              <Card header={<h3 className="text-lg font-medium text-gray-900">Publishing</h3>}>
                <div className="space-y-4">
                  <div className="flex items-center justify-between">
                    <span className="text-sm text-gray-700">Status</span>
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
                      {saving ? 'Saving...' : isEditing ? 'Update Template' : 'Create Template'}
                    </Button>
                  </div>
                </div>
              </Card>

              <Card
                header={(
                  <div className="flex items-center gap-2">
                    <InformationCircleIcon className="h-5 w-5 text-gray-400" />
                    <h3 className="text-lg font-medium text-gray-900">Available Placeholders</h3>
                  </div>
                )}
              >
                <div className="space-y-2">
                  <p className="text-xs text-gray-500 mb-3">
                    Click to copy placeholders to clipboard.
                  </p>
                  {placeholders.map((placeholder) => (
                    <div
                      key={placeholder.token}
                      className="group flex items-center justify-between p-2 rounded bg-gray-50 hover:bg-gray-100 cursor-pointer border border-transparent hover:border-gray-200 transition-colors"
                      onClick={() => copyToClipboard(placeholder.token)}
                    >
                      <code className="text-xs text-primary-700 font-mono">{placeholder.token}</code>
                      <span className="text-xs text-gray-500">{placeholder.label}</span>
                    </div>
                  ))}
                </div>
              </Card>

              {isEditing && templateData ? (
                <div className="text-xs text-gray-500 space-y-1 px-1">
                  <p>Created: {formatDate(templateData.created_at)}</p>
                  <p>Updated: {formatDate(templateData.updated_at)}</p>
                </div>
              ) : null}
            </div>
          </div>
        </form>
      )}
    </div>
  )
}
