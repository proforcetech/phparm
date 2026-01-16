import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { ArrowLeftIcon } from '@heroicons/react/24/outline'

import Alert from '../../components/ui/Alert'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Textarea from '../../components/ui/Textarea'
import { cmsService } from '../../../services/cms.service'
import { useCmsPageStore } from '../../stores/cmsPages'
import { useToast } from '../../stores/toast.jsx'

const createDefaultForm = () => ({
  title: '',
  slug: '',
  category_id: null,
  template_id: null,
  header_component_id: null,
  footer_component_id: null,
  custom_css: '',
  custom_js: '',
  status: 'draft',
  meta_title: '',
  meta_description: '',
  meta_keywords: '',
  summary: '',
  content: '',
})

const parseNullableId = (value) => {
  if (value === '') return null
  const parsed = Number(value)
  return Number.isNaN(parsed) ? value : parsed
}

export default function CMSPageForm() {
  const navigate = useNavigate()
  const { id } = useParams()
  const pageStore = useCmsPageStore()
  const toast = useToast()

  const isEditing = useMemo(() => !!id && id !== 'create', [id])
  const draftKey = useMemo(() => id || 'new', [id])

  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState(null)
  const [validationErrors, setValidationErrors] = useState([])
  const [availableTemplates, setAvailableTemplates] = useState([])
  const [availableHeaderComponents, setAvailableHeaderComponents] = useState([])
  const [availableFooterComponents, setAvailableFooterComponents] = useState([])
  const [availableCategories, setAvailableCategories] = useState([])
  const [form, setForm] = useState(createDefaultForm())
  const [linkQuery, setLinkQuery] = useState('')
  const [linkResults, setLinkResults] = useState([])
  const [linkLoading, setLinkLoading] = useState(false)
  const [linkError, setLinkError] = useState(null)
  const contentRef = useRef(null)

  const categoryOptions = useMemo(() => {
    if (!availableCategories.length) return []
    const categoryMap = new Map()
    availableCategories.forEach((category) => {
      categoryMap.set(category.id, { ...category, children: [] })
    })

    categoryMap.forEach((category) => {
      if (category.parent_id && categoryMap.has(category.parent_id)) {
        categoryMap.get(category.parent_id).children.push(category)
      }
    })

    const sortCategories = (list) => {
      list.sort((a, b) => {
        if (a.sort_order !== b.sort_order) {
          return a.sort_order - b.sort_order
        }
        return a.name.localeCompare(b.name)
      })
      list.forEach((item) => sortCategories(item.children))
    }

    const roots = Array.from(categoryMap.values()).filter(
      (category) => !category.parent_id || !categoryMap.has(category.parent_id)
    )
    sortCategories(roots)

    const flattened = []
    const walk = (category, depth, path, slugPath) => {
      flattened.push({
        ...category,
        depth,
        path,
        slugPath,
      })
      category.children.forEach((child) =>
        walk(child, depth + 1, [...path, child.name], [...slugPath, child.slug])
      )
    }

    roots.forEach((category) => walk(category, 0, [category.name], [category.slug]))
    return flattened
  }, [availableCategories])

  const selectedCategory = useMemo(() => {
    if (!form.category_id) return null
    return categoryOptions.find((category) => category.id === form.category_id) || null
  }, [categoryOptions, form.category_id])

  const categoryPathMap = useMemo(() => {
    const map = new Map()
    categoryOptions.forEach((category) => {
      if (category.id) {
        map.set(category.id, category.slugPath)
      }
    })
    return map
  }, [categoryOptions])

  const resolvePageUrl = useCallback(
    (page) => {
      if (!page?.slug) return ''
      if (!page.category_id) return `/${page.slug}`
      const categoryPath = categoryPathMap.get(page.category_id)
      if (!categoryPath?.length) return `/${page.slug}`
      return `/${categoryPath.join('/')}/${page.slug}`
    },
    [categoryPathMap]
  )

  const escapeHtml = useCallback((value) => {
    if (!value) return ''
    return value
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;')
  }, [])

  const loadData = useCallback(async () => {
    try {
      setLoading(true)
      setError(null)

      const formOptions = await cmsService.getPageFormOptions()
      setAvailableTemplates(formOptions.templates || [])
      setAvailableHeaderComponents(formOptions.header_components || [])
      setAvailableFooterComponents(formOptions.footer_components || [])
      setAvailableCategories(formOptions.categories || [])

      if (isEditing) {
        const pageData = await pageStore.fetchPage(id)
        const draft = pageStore.drafts[draftKey]
        setForm({
          ...createDefaultForm(),
          ...pageData,
          ...(draft || {}),
        })
      } else {
        const draft = pageStore.drafts[draftKey]
        setForm({
          ...createDefaultForm(),
          ...(draft || {}),
        })
      }
    } catch (err) {
      console.error('Failed to load data:', err)
      setError(err.response?.data?.message || 'Failed to load data')
    } finally {
      setLoading(false)
    }
  // eslint-disable-next-line react-hooks/exhaustive-deps -- pageStore.drafts intentionally excluded to prevent infinite loop; draft is only read on initial load
  }, [draftKey, id, isEditing, pageStore.fetchPage])

  useEffect(() => {
    loadData()
  }, [loadData])

  useEffect(() => {
    pageStore.setDraft(draftKey, form)
  }, [draftKey, form, pageStore.setDraft])

  useEffect(() => {
    const searchTerm = linkQuery.trim()
    if (!searchTerm) {
      setLinkResults([])
      setLinkLoading(false)
      setLinkError(null)
      return
    }

    let isActive = true
    const timer = setTimeout(async () => {
      try {
        setLinkLoading(true)
        setLinkError(null)
        const response = await cmsService.getPages({ search: searchTerm, limit: 8, status: 'published' })
        const pages = Array.isArray(response?.data) ? response.data : response
        const filtered = (pages || []).filter((page) => `${page.id}` !== `${id}`)
        if (isActive) {
          setLinkResults(filtered)
        }
      } catch (err) {
        console.error('Failed to search CMS pages:', err)
        if (isActive) {
          setLinkError(err.response?.data?.message || 'Failed to search CMS pages')
        }
      } finally {
        if (isActive) {
          setLinkLoading(false)
        }
      }
    }, 250)

    return () => {
      isActive = false
      clearTimeout(timer)
    }
  }, [id, linkQuery])

  const generateSlug = (title) => {
    if (isEditing) return

    const slug = title
      .toLowerCase()
      .trim()
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-+|-+$/g, '')

    setForm((prev) => ({ ...prev, slug }))
  }

  const validateForm = () => {
    const errors = []
    if (!form.title) errors.push('Title is required')
    if (!form.slug) errors.push('Slug is required')
    return errors
  }

  const savePage = async (event) => {
    event.preventDefault()

    try {
      setSaving(true)
      setError(null)
      const errors = validateForm()
      setValidationErrors(errors)

      if (errors.length) {
        throw new Error(errors.join(', '))
      }

      if (isEditing) {
        await pageStore.updatePage(id, form)
        toast.success('Page updated')
      } else {
        const newPage = await pageStore.createPage(form)
        toast.success('Page created')
        navigate(`/cp/cms/pages/${newPage.id}`)
        return
      }

      pageStore.clearDraft(draftKey)
      await loadData()
    } catch (err) {
      console.error('Failed to save page:', err)
      const message = err.response?.data?.message || err.message || 'Failed to save page'
      setError(message)
      if (!validationErrors.length) {
        setValidationErrors([message])
      }
    } finally {
      setSaving(false)
    }
  }

  const publishPage = async () => {
    try {
      setSaving(true)
      setError(null)
      const errors = validateForm()
      setValidationErrors(errors)

      if (errors.length) {
        throw new Error(errors.join(', '))
      }

      if (isEditing) {
        await pageStore.publishPage(id)
        toast.success('Page published')
      } else {
        const newPage = await pageStore.createPage({ ...form, status: 'published' })
        toast.success('Page created and published')
        navigate(`/cp/cms/pages/${newPage.id}`)
        return
      }

      pageStore.clearDraft(draftKey)
      await loadData()
    } catch (err) {
      console.error('Failed to publish page:', err)
      const message = err.response?.data?.message || err.message || 'Failed to publish page'
      setError(message)
      if (!validationErrors.length) {
        setValidationErrors([message])
      }
    } finally {
      setSaving(false)
    }
  }

  const insertInternalLink = (page) => {
    if (!page) return
    const url = resolvePageUrl(page)
    const linkHtml = `<a href="${url}" data-cms-page-id="${page.id}">${escapeHtml(page.title)}</a>`
    const textarea = contentRef.current
    const currentValue = textarea?.value ?? form.content ?? ''
    const selectionStart = textarea?.selectionStart ?? currentValue.length
    const selectionEnd = textarea?.selectionEnd ?? currentValue.length
    const updated =
      currentValue.slice(0, selectionStart) + linkHtml + currentValue.slice(selectionEnd)

    setForm((prev) => ({ ...prev, content: updated }))

    requestAnimationFrame(() => {
      if (textarea) {
        const nextCursor = selectionStart + linkHtml.length
        textarea.focus()
        textarea.setSelectionRange(nextCursor, nextCursor)
      }
    })
  }

  return (
    <div>
      <div className="mb-8">
        <div className="flex items-center gap-4">
          <Button variant="ghost" onClick={() => navigate('/cp/cms/pages')}>
            <ArrowLeftIcon className="h-5 w-5" />
          </Button>
          <div>
            <h1 className="text-2xl font-bold text-gray-900">
              {isEditing ? 'Edit Page' : 'Create Page'}
            </h1>
            <p className="mt-1 text-sm text-gray-500">
              {isEditing
                ? 'Update your page content and settings'
                : 'Create a new page for your website'}
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
        <form onSubmit={savePage}>
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
              <Card header={<h3 className="text-lg font-medium text-gray-900">Page Details</h3>}>
                <div className="space-y-4">
                  <Input
                    label="Title *"
                    required
                    value={form.title}
                    onUpdateModelValue={(value) => {
                      setForm((prev) => ({ ...prev, title: value }))
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
                  </div>

                  <div>
                    <label className="block text-sm font-medium text-gray-700 mb-1">Category</label>
                    <select
                      value={form.category_id ?? ''}
                      className="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                      onChange={(event) =>
                        setForm((prev) => ({ ...prev, category_id: parseNullableId(event.target.value) }))
                      }
                    >
                      <option value="">No Category (Base URL)</option>
                      {categoryOptions.map((category) => (
                        <option key={category.id} value={category.id}>
                          {'— '.repeat(category.depth)}
                          {category.path.join(' / ')} ({category.slugPath.join('/')})
                        </option>
                      ))}
                    </select>
                    <p className="mt-1 text-xs text-gray-500">
                      {form.category_id && selectedCategory
                        ? `Page will be accessible at: /${selectedCategory.slugPath.join('/')}/${form.slug}`
                        : `Page will be accessible at: /${form.slug}`}
                    </p>
                  </div>

                  <div>
                    <label className="block text-sm font-medium text-gray-700 mb-1">Template</label>
                    <select
                      value={form.template_id ?? ''}
                      className="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                      onChange={(event) =>
                        setForm((prev) => ({ ...prev, template_id: parseNullableId(event.target.value) }))
                      }
                    >
                      <option value="">No Template</option>
                      {availableTemplates.map((template) => (
                        <option key={template.id} value={template.id}>
                          {template.name}
                        </option>
                      ))}
                    </select>
                    <p className="mt-1 text-xs text-gray-500">Choose a template to control the page layout</p>
                  </div>

                  <div>
                    <label className="block text-sm font-medium text-gray-700 mb-1">Header Component</label>
                    <select
                      value={form.header_component_id ?? ''}
                      className="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                      onChange={(event) =>
                        setForm((prev) => ({ ...prev, header_component_id: parseNullableId(event.target.value) }))
                      }
                    >
                      <option value="">No Header</option>
                      {availableHeaderComponents.map((component) => (
                        <option key={component.id} value={component.id}>
                          {component.name}
                        </option>
                      ))}
                    </select>
                    <p className="mt-1 text-xs text-gray-500">Choose a header component for this page</p>
                  </div>

                  <div>
                    <label className="block text-sm font-medium text-gray-700 mb-1">Footer Component</label>
                    <select
                      value={form.footer_component_id ?? ''}
                      className="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                      onChange={(event) =>
                        setForm((prev) => ({ ...prev, footer_component_id: parseNullableId(event.target.value) }))
                      }
                    >
                      <option value="">No Footer</option>
                      {availableFooterComponents.map((component) => (
                        <option key={component.id} value={component.id}>
                          {component.name}
                        </option>
                      ))}
                    </select>
                    <p className="mt-1 text-xs text-gray-500">Choose a footer component for this page</p>
                  </div>

                  <Textarea
                    label="Summary"
                    rows={2}
                    placeholder="Brief summary of the page..."
                    value={form.summary}
                    onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, summary: value }))}
                  />

                  <Textarea
                    label="Content"
                    rows={15}
                    className="font-mono text-sm"
                    placeholder="Enter HTML content..."
                    value={form.content}
                    onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, content: value }))}
                    textareaRef={contentRef}
                  />

                  <div className="rounded-md border border-gray-200 p-4">
                    <div className="flex items-center justify-between">
                      <div>
                        <h4 className="text-sm font-semibold text-gray-900">Internal Linking Tool</h4>
                        <p className="text-xs text-gray-500">
                          Search published pages by title and insert links directly into the editor.
                        </p>
                      </div>
                    </div>
                    <div className="mt-3">
                      <Input
                        label="Search pages"
                        placeholder="Start typing a page title..."
                        value={linkQuery}
                        onUpdateModelValue={setLinkQuery}
                      />
                    </div>
                    {linkLoading ? (
                      <p className="mt-3 text-sm text-gray-500">Searching pages...</p>
                    ) : linkError ? (
                      <p className="mt-3 text-sm text-red-600">{linkError}</p>
                    ) : linkQuery.trim() ? (
                      <div className="mt-3 space-y-2">
                        {linkResults.length ? (
                          linkResults.map((page) => (
                            <div
                              key={page.id}
                              className="flex items-center justify-between rounded-md border border-gray-100 bg-gray-50 px-3 py-2 text-sm"
                            >
                              <div>
                                <p className="font-medium text-gray-900">{page.title}</p>
                                <p className="text-xs text-gray-500">{resolvePageUrl(page)}</p>
                              </div>
                              <Button
                                type="button"
                                size="sm"
                                variant="secondary"
                                onClick={() => insertInternalLink(page)}
                              >
                                Insert Link
                              </Button>
                            </div>
                          ))
                        ) : (
                          <p className="text-sm text-gray-500">No pages found.</p>
                        )}
                      </div>
                    ) : (
                      <p className="mt-3 text-sm text-gray-500">
                        Start typing to search for published pages.
                      </p>
                    )}
                  </div>
                </div>
              </Card>

              <Card header={<h3 className="text-lg font-medium text-gray-900">SEO Settings</h3>}>
                <div className="space-y-4">
                  <Input
                    label="Meta Title"
                    placeholder="Custom title for search engines (defaults to page title)"
                    value={form.meta_title}
                    onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, meta_title: value }))}
                  />

                  <Textarea
                    label="Meta Description"
                    rows={3}
                    maxlength={160}
                    placeholder="Brief description for search engines..."
                    value={form.meta_description}
                    onUpdateModelValue={(value) =>
                      setForm((prev) => ({ ...prev, meta_description: value }))
                    }
                  />

                  <Input
                    label="Meta Keywords"
                    placeholder="keyword1, keyword2, keyword3"
                    value={form.meta_keywords}
                    onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, meta_keywords: value }))}
                  />
                </div>
              </Card>

              <Card header={<h3 className="text-lg font-medium text-gray-900">Custom Styling</h3>}>
                <div className="space-y-4">
                  <Textarea
                    label="Custom CSS"
                    rows={6}
                    className="font-mono text-sm"
                    placeholder="/* Page-specific CSS styles... */"
                    value={form.custom_css}
                    onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, custom_css: value }))}
                    helperText="CSS that will be applied only to this page"
                  />

                  <Textarea
                    label="Custom JavaScript"
                    rows={6}
                    className="font-mono text-sm"
                    placeholder="// Page-specific JavaScript..."
                    value={form.custom_js}
                    onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, custom_js: value }))}
                    helperText="JavaScript that will be executed only on this page"
                  />
                </div>
              </Card>
            </div>

            <div className="space-y-6">
              <Card header={<h3 className="text-lg font-medium text-gray-900">Publish</h3>}>
                <div className="space-y-4">
                  <div className="flex items-center justify-between">
                    <span className="text-sm text-gray-700">Status</span>
                    <label className="relative inline-flex items-center cursor-pointer">
                      <input
                        type="checkbox"
                        className="sr-only peer"
                        checked={form.status === 'published'}
                        onChange={(event) =>
                          setForm((prev) => ({
                            ...prev,
                            status: event.target.checked ? 'published' : 'draft',
                          }))
                        }
                      />
                      <div className="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-primary-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-primary-600"></div>
                      <span className={`ml-2 text-sm font-medium ${form.status === 'published' ? 'text-green-600' : 'text-gray-500'}`}>
                        {form.status === 'published' ? 'Published' : 'Draft'}
                      </span>
                    </label>
                  </div>

                  <div className="pt-4 border-t border-gray-200">
                    <Button type="submit" className="w-full" disabled={saving}>
                      {saving ? 'Saving...' : isEditing ? 'Update Page' : 'Create Page'}
                    </Button>
                    {form.status !== 'published' ? (
                      <Button
                        type="button"
                        className="w-full mt-3"
                        variant="secondary"
                        disabled={saving}
                        onClick={publishPage}
                      >
                        {saving ? 'Publishing...' : 'Save & Publish'}
                      </Button>
                    ) : null}
                  </div>
                </div>
              </Card>
            </div>
          </div>
        </form>
      )}
    </div>
  )
}
