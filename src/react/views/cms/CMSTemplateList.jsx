import { useCallback, useEffect, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  PencilIcon,
  PlusIcon,
  RectangleGroupIcon,
  TrashIcon,
} from '@heroicons/react/24/outline'

import Alert from '../../components/ui/Alert'
import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Modal from '../../components/ui/Modal'
import { cmsService } from '../../../services/cms.service'

export default function CMSTemplateList() {
  const navigate = useNavigate()
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [templates, setTemplates] = useState([])
  const [filters, setFilters] = useState({ search: '', active: '' })
  const [showDeleteModal, setShowDeleteModal] = useState(false)
  const [templateToDelete, setTemplateToDelete] = useState(null)
  const [deleting, setDeleting] = useState(false)
  const [deleteError, setDeleteError] = useState(null)
  const searchTimeout = useRef(null)

  const loadTemplates = useCallback(async (nextFilters) => {
    try {
      setLoading(true)
      setError(null)

      const data = await cmsService.getTemplates(nextFilters)
      setTemplates(data.data || [])
    } catch (err) {
      console.error('Failed to load templates:', err)
      setError(err.response?.data?.message || 'Failed to load templates')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    loadTemplates(filters)

    return () => {
      if (searchTimeout.current) {
        clearTimeout(searchTimeout.current)
      }
    }
  }, [loadTemplates])

  const debouncedSearch = (nextFilters) => {
    if (searchTimeout.current) {
      clearTimeout(searchTimeout.current)
    }
    searchTimeout.current = setTimeout(() => {
      loadTemplates(nextFilters)
    }, 300)
  }

  const confirmDelete = (template) => {
    setTemplateToDelete(template)
    setDeleteError(null)
    setShowDeleteModal(true)
  }

  const deleteTemplate = async () => {
    if (!templateToDelete) return

    try {
      setDeleting(true)
      setDeleteError(null)
      await cmsService.deleteTemplate(templateToDelete.id)
      setShowDeleteModal(false)
      setTemplateToDelete(null)
      await loadTemplates()
    } catch (err) {
      console.error('Failed to delete template:', err)
      setDeleteError(
        err.response?.data?.message || 'Failed to delete template. It may be in use by pages.'
      )
    } finally {
      setDeleting(false)
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
          <h1 className="text-2xl font-bold text-gray-900">CMS Templates</h1>
          <p className="mt-1 text-sm text-gray-500">Manage page layout templates</p>
        </div>
        <Button onClick={() => navigate('/cp/cms/templates/create')}>
          <PlusIcon className="h-5 w-5 mr-2" />
          New Template
        </Button>
      </div>

      <Card className="mb-6">
        <div className="flex flex-wrap gap-4">
          <div className="flex-1 min-w-[200px]">
            <Input
              value={filters.search}
              placeholder="Search templates..."
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
              value={filters.active}
              className="px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
              onChange={(event) => {
                const nextFilters = { ...filters, active: event.target.value }
                setFilters(nextFilters)
                loadTemplates(nextFilters)
              }}
            >
              <option value="">All Status</option>
              <option value="1">Active</option>
              <option value="0">Inactive</option>
            </select>
          </div>
        </div>
      </Card>

      {loading ? (
        <div className="flex justify-center py-12">
          <Loading size="xl" text="Loading templates..." />
        </div>
      ) : error ? (
        <Alert variant="danger" className="mb-6">
          {error}
        </Alert>
      ) : templates.length === 0 ? (
        <div className="text-center py-12">
          <Card>
            <RectangleGroupIcon className="h-12 w-12 mx-auto mb-4 text-gray-400" />
            <p className="text-lg font-medium text-gray-900">No templates found</p>
            <p className="text-sm mt-1 text-gray-500">Get started by creating your first template.</p>
            <Button className="mt-4" onClick={() => navigate('/cp/cms/templates/create')}>
              <PlusIcon className="h-5 w-5 mr-2" />
              Create Template
            </Button>
          </Card>
        </div>
      ) : (
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
          {templates.map((template) => (
            <Card
              key={template.id}
              className="hover:shadow-lg transition-shadow cursor-pointer"
              onClick={() => navigate(`/cp/cms/templates/${template.id}`)}
            >
              <div className="flex items-start justify-between">
                <div className="flex-1 min-w-0">
                  <div className="flex items-center gap-2 mb-2">
                    <h3 className="text-lg font-medium text-gray-900 truncate">{template.name}</h3>
                    <Badge variant={template.is_active ? 'success' : 'default'}>
                      {template.is_active ? 'Active' : 'Inactive'}
                    </Badge>
                  </div>
                  {template.description ? (
                    <p className="text-sm text-gray-500 line-clamp-2">{template.description}</p>
                  ) : null}
                  <p className="text-xs text-gray-400 mt-2">
                    Updated {formatDate(template.updated_at)}
                  </p>
                </div>
              </div>

              <div className="mt-4 p-3 bg-gray-100 rounded-md">
                <div className="text-xs text-gray-500 font-mono truncate">
                  {template.structure
                    ? `${template.structure.substring(0, 100)}...`
                    : 'No structure defined'}
                </div>
              </div>

              <div
                className="mt-4 pt-4 border-t border-gray-200 flex items-center justify-end gap-2"
                onClick={(event) => event.stopPropagation()}
              >
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() => navigate(`/cp/cms/templates/${template.id}`)}
                  title="Edit"
                >
                  <PencilIcon className="h-4 w-4" />
                </Button>
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() => confirmDelete(template)}
                  title="Delete"
                >
                  <TrashIcon className="h-4 w-4 text-red-500" />
                </Button>
              </div>
            </Card>
          ))}
        </div>
      )}

      <Modal
        open={showDeleteModal}
        title="Delete Template"
        onClose={() => setShowDeleteModal(false)}
        footer={(
          <div className="flex justify-end gap-3">
            <Button variant="outline" onClick={() => setShowDeleteModal(false)}>
              Cancel
            </Button>
            <Button variant="danger" onClick={deleteTemplate} disabled={deleting}>
              {deleting ? 'Deleting...' : 'Delete'}
            </Button>
          </div>
        )}
      >
        <p className="text-gray-600">
          Are you sure you want to delete the template "<strong>{templateToDelete?.name}</strong>"?
          This action cannot be undone.
        </p>
        {deleteError ? (
          <Alert variant="danger" className="mt-4">
            {deleteError}
          </Alert>
        ) : null}
      </Modal>
    </div>
  )
}
