import { useCallback, useEffect, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  DocumentDuplicateIcon,
  PencilIcon,
  PlusIcon,
  TrashIcon,
} from '@heroicons/react/24/outline'

import Alert from '../../components/ui/Alert'
import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Modal from '../../components/ui/Modal'
import { useCmsPageStore } from '../../stores/cmsPages'
import { useToast } from '../../stores/toast.jsx'

export default function CMSPageList() {
  const navigate = useNavigate()
  const pageStore = useCmsPageStore()
  const { fetchPages } = pageStore
  const toast = useToast()
  const [error, setError] = useState(null)
  const [filters, setFilters] = useState({ search: '', status: '' })
  const [showDeleteModal, setShowDeleteModal] = useState(false)
  const [pageToDelete, setPageToDelete] = useState(null)
  const [deleting, setDeleting] = useState(false)
  const searchTimeout = useRef(null)

  const loadPages = useCallback(async (nextFilters = filters) => {
    try {
      setError(null)
      await fetchPages(nextFilters)
    } catch (err) {
      console.error('Failed to load pages:', err)
      setError(err.response?.data?.message || 'Failed to load pages')
    }
  }, [fetchPages, filters])

  useEffect(() => {
    loadPages()

    return () => {
      if (searchTimeout.current) {
        clearTimeout(searchTimeout.current)
      }
    }
  }, [loadPages])

  const debouncedSearch = (nextFilters) => {
    if (searchTimeout.current) {
      clearTimeout(searchTimeout.current)
    }
    searchTimeout.current = setTimeout(() => {
      loadPages(nextFilters)
    }, 300)
  }

  const confirmDelete = (page) => {
    setPageToDelete(page)
    setShowDeleteModal(true)
  }

  const deletePage = async () => {
    if (!pageToDelete) return

    try {
      setDeleting(true)
      await pageStore.deletePage(pageToDelete.id)
      toast.success('Page deleted')
      setShowDeleteModal(false)
      setPageToDelete(null)
      await loadPages()
    } catch (err) {
      console.error('Failed to delete page:', err)
      const message = err.response?.data?.message || 'Failed to delete page'
      setError(message)
      toast.error(message)
    } finally {
      setDeleting(false)
    }
  }

  const togglePublish = async (page) => {
    try {
      setError(null)
      if (page.status === 'published') {
        await pageStore.updatePage(page.id, { ...page, status: 'draft' })
        toast.info('Page moved to drafts')
      } else {
        await pageStore.publishPage(page.id)
        toast.success('Page published')
      }
      await loadPages()
    } catch (err) {
      console.error('Failed to update publish status:', err)
      const message = err.response?.data?.message || 'Failed to update publish status'
      setError(message)
      toast.error(message)
    }
  }

  const formatDate = (date) => {
    if (!date) return ''
    return new Intl.DateTimeFormat('en-US', {
      month: 'short',
      day: 'numeric',
      year: 'numeric',
    }).format(new Date(date))
  }

  return (
    <div>
      <div className="mb-8 flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">CMS Pages</h1>
          <p className="mt-1 text-sm text-gray-500">Manage your website pages</p>
        </div>
        <Button onClick={() => navigate('/cp/cms/pages/create')}>
          <PlusIcon className="h-5 w-5 mr-2" />
          New Page
        </Button>
      </div>

      <Card className="mb-6">
        <div className="flex flex-wrap gap-4">
          <div className="flex-1 min-w-[200px]">
            <Input
              value={filters.search}
              placeholder="Search pages..."
              onUpdateModelValue={(value) => {
                setFilters((prev) => {
                  const nextFilters = { ...prev, search: value }
                  debouncedSearch(nextFilters)
                  return nextFilters
                })
              }}
            />
          </div>
          <div>
            <select
              value={filters.status}
              className="px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
              onChange={(event) => {
                const nextFilters = { ...filters, status: event.target.value }
                setFilters(nextFilters)
                loadPages(nextFilters)
              }}
            >
              <option value="">All Status</option>
              <option value="published">Published</option>
              <option value="draft">Draft</option>
            </select>
          </div>
        </div>
      </Card>

      {pageStore.loading ? (
        <div className="flex justify-center py-12">
          <Loading size="xl" text="Loading pages..." />
        </div>
      ) : error ? (
        <Alert variant="danger" className="mb-6">
          {error}
        </Alert>
      ) : (
        <Card>
          {pageStore.pages.length === 0 ? (
            <div className="text-center py-12 text-gray-500">
              <DocumentDuplicateIcon className="h-12 w-12 mx-auto mb-4 text-gray-400" />
              <p className="text-lg font-medium">No pages found</p>
              <p className="text-sm mt-1">Get started by creating your first page.</p>
              <Button className="mt-4" onClick={() => navigate('/cp/cms/pages/create')}>
                <PlusIcon className="h-5 w-5 mr-2" />
                Create Page
              </Button>
            </div>
          ) : (
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-gray-200">
                <thead className="bg-gray-50">
                  <tr>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Title
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Slug
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Template
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Status
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Updated
                    </th>
                    <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Actions
                    </th>
                  </tr>
                </thead>
                <tbody className="bg-white divide-y divide-gray-200">
                  {pageStore.pages.map((page) => (
                    <tr
                      key={page.id}
                      className="hover:bg-gray-50 cursor-pointer"
                      onClick={() => navigate(`/cp/cms/pages/${page.id}`)}
                    >
                      <td className="px-6 py-4 whitespace-nowrap">
                        <div className="text-sm font-medium text-gray-900">{page.title}</div>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <div className="text-sm text-gray-500">/{page.slug}</div>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <div className="text-sm text-gray-500">{page.template_name || 'None'}</div>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <Badge variant={page.status === 'published' ? 'success' : 'warning'}>
                          {page.status === 'published' ? 'Published' : 'Draft'}
                        </Badge>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                        {formatDate(page.updated_at)}
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                        <div
                          className="flex items-center justify-end gap-2"
                          onClick={(event) => event.stopPropagation()}
                        >
                          <Button variant="secondary" size="sm" onClick={() => togglePublish(page)}>
                            {page.status === 'published' ? 'Unpublish' : 'Publish'}
                          </Button>
                          <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => navigate(`/cp/cms/pages/${page.id}`)}
                          >
                            <PencilIcon className="h-4 w-4" />
                          </Button>
                          <Button variant="ghost" size="sm" onClick={() => confirmDelete(page)}>
                            <TrashIcon className="h-4 w-4 text-red-500" />
                          </Button>
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Card>
      )}

      <Modal
        open={showDeleteModal}
        title="Delete Page"
        onClose={() => setShowDeleteModal(false)}
        footer={(
          <div className="flex justify-end gap-3">
            <Button variant="outline" onClick={() => setShowDeleteModal(false)}>
              Cancel
            </Button>
            <Button variant="danger" onClick={deletePage} disabled={deleting}>
              {deleting ? 'Deleting...' : 'Delete'}
            </Button>
          </div>
        )}
      >
        <p className="text-gray-600">
          Are you sure you want to delete the page "<strong>{pageToDelete?.title}</strong>"?
          This action cannot be undone.
        </p>
      </Modal>
    </div>
  )
}
