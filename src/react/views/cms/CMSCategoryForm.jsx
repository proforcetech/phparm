import { useCallback, useEffect, useMemo, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { ArrowLeftIcon } from '@heroicons/react/24/outline'

import Alert from '../../components/ui/Alert'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Textarea from '../../components/ui/Textarea'
import { cmsService } from '../../../services/cms.service'

const defaultForm = {
  name: '',
  slug: '',
  description: '',
  sort_order: 0,
  status: 'published',
  meta_title: '',
  meta_description: '',
  meta_keywords: '',
}

export default function CMSCategoryForm() {
  const navigate = useNavigate()
  const { id } = useParams()
  const categoryId = useMemo(() => (id ? parseInt(id, 10) : null), [id])
  const isEditing = useMemo(() => !!categoryId, [categoryId])

  const [form, setForm] = useState(defaultForm)
  const [loading, setLoading] = useState(false)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [validationErrors, setValidationErrors] = useState([])

  const generateSlug = (name) => {
    if (isEditing || !name) return

    const slug = name
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-|-$/g, '')

    setForm((prev) => ({ ...prev, slug }))
  }

  const loadCategory = useCallback(async () => {
    if (!categoryId) return

    setLoading(true)
    setError('')
    try {
      const category = await cmsService.getCategory(categoryId)
      setForm({
        name: category.name || '',
        slug: category.slug || '',
        description: category.description || '',
        sort_order: category.sort_order || 0,
        status: category.status || 'published',
        meta_title: category.meta_title || '',
        meta_description: category.meta_description || '',
        meta_keywords: category.meta_keywords || '',
      })
    } catch (err) {
      setError(err.message || 'Failed to load category')
    } finally {
      setLoading(false)
    }
  }, [categoryId])

  useEffect(() => {
    if (isEditing) {
      loadCategory()
    }
  }, [isEditing, loadCategory])

  const validate = () => {
    const errors = []
    if (!form.name?.trim()) {
      errors.push('Name is required')
    }

    if (!form.slug?.trim()) {
      errors.push('Slug is required')
    }

    setValidationErrors(errors)
    return errors.length === 0
  }

  const saveCategory = async (event) => {
    event.preventDefault()

    if (!validate()) {
      return
    }

    setSaving(true)
    setError('')

    try {
      if (isEditing) {
        await cmsService.updateCategory(categoryId, form)
      } else {
        await cmsService.createCategory(form)
      }

      navigate('/cp/cms/categories')
    } catch (err) {
      setError(err.message || 'Failed to save category')
    } finally {
      setSaving(false)
    }
  }

  return (
    <div>
      <div className="mb-8">
        <div className="flex items-center gap-4">
          <Button variant="ghost" onClick={() => navigate('/cp/cms/categories')}>
            <ArrowLeftIcon className="h-5 w-5" />
          </Button>
          <div>
            <h1 className="text-2xl font-bold text-gray-900">
              {isEditing ? 'Edit Category' : 'Create Category'}
            </h1>
            <p className="mt-1 text-sm text-gray-500">
              {isEditing
                ? 'Update category settings'
                : 'Create a new category for organizing pages'}
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
        <form onSubmit={saveCategory}>
          {validationErrors.length ? (
            <Alert variant="warning" className="mb-4">
              <ul className="list-disc pl-5">
                {validationErrors.map((message) => (
                  <li key={message}>{message}</li>
                ))}
              </ul>
            </Alert>
          ) : null}

          <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div className="lg:col-span-2 space-y-6">
              <Card header={<h3 className="text-lg font-medium text-gray-900">Category Details</h3>}>
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
                    <div className="flex items-center">
                      <span className="text-gray-500 text-sm mr-1">/</span>
                      <Input
                        required
                        value={form.slug}
                        onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, slug: value }))}
                        className="flex-1"
                      />
                    </div>
                    <p className="mt-1 text-xs text-gray-500">Used in URLs: /{form.slug}/page-name</p>
                  </div>

                  <Textarea
                    label="Description"
                    rows={3}
                    placeholder="Brief description of this category..."
                    value={form.description}
                    onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, description: value }))}
                  />

                  <Input
                    label="Sort Order"
                    type="number"
                    min="0"
                    value={form.sort_order}
                    onUpdateModelValue={(value) =>
                      setForm((prev) => ({ ...prev, sort_order: Number(value) }))
                    }
                    helperText="Lower numbers appear first"
                  />
                </div>
              </Card>

              <Card header={<h3 className="text-lg font-medium text-gray-900">SEO Settings</h3>}>
                <div className="space-y-4">
                  <Input
                    label="Meta Title"
                    placeholder="Custom title for search engines"
                    value={form.meta_title}
                    onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, meta_title: value }))}
                  />

                  <Textarea
                    label="Meta Description"
                    rows={3}
                    placeholder="Description for search results"
                    value={form.meta_description}
                    onUpdateModelValue={(value) =>
                      setForm((prev) => ({ ...prev, meta_description: value }))
                    }
                  />

                  <Input
                    label="Meta Keywords"
                    placeholder="keyword1, keyword2, keyword3"
                    value={form.meta_keywords}
                    onUpdateModelValue={(value) =>
                      setForm((prev) => ({ ...prev, meta_keywords: value }))
                    }
                  />
                </div>
              </Card>
            </div>

            <div className="space-y-6">
              <Card header={<h3 className="text-lg font-medium text-gray-900">Actions</h3>}>
                <div className="space-y-3">
                  <Button type="submit" disabled={saving} className="w-full">
                    {saving ? 'Saving...' : isEditing ? 'Update Category' : 'Create Category'}
                  </Button>
                  {isEditing ? (
                    <Button
                      variant="outline"
                      type="button"
                      onClick={() => navigate('/cp/cms/categories')}
                      className="w-full"
                    >
                      Cancel
                    </Button>
                  ) : null}
                </div>
              </Card>

              <Card header={<h3 className="text-lg font-medium text-gray-900">Status</h3>}>
                <div>
                  <select
                    value={form.status}
                    className="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                    onChange={(event) => setForm((prev) => ({ ...prev, status: event.target.value }))}
                  >
                    <option value="draft">Draft</option>
                    <option value="published">Published</option>
                    <option value="archived">Archived</option>
                  </select>
                </div>
              </Card>
            </div>
          </div>
        </form>
      )}
    </div>
  )
}
