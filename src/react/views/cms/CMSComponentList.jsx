import { useCallback, useEffect, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  DocumentDuplicateIcon,
  PencilIcon,
  PlusIcon,
  Squares2X2Icon,
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

export default function CMSComponentList() {
  const navigate = useNavigate()
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [components, setComponents] = useState([])
  const [filters, setFilters] = useState({ search: '', type: '' })
  const [showDeleteModal, setShowDeleteModal] = useState(false)
  const [componentToDelete, setComponentToDelete] = useState(null)
  const [deleting, setDeleting] = useState(false)
  const searchTimeout = useRef(null)

  const componentTypes = cmsService.getComponentTypes()

  const loadComponents = useCallback(async (nextFilters) => {
    try {
      setLoading(true)
      setError(null)

      const data = await cmsService.getComponents(nextFilters)
      setComponents(data.data || [])
    } catch (err) {
      console.error('Failed to load components:', err)
      setError(err.response?.data?.message || 'Failed to load components')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    loadComponents(filters)

    return () => {
      if (searchTimeout.current) {
        clearTimeout(searchTimeout.current)
      }
    }
  }, [loadComponents])

  const debouncedSearch = (nextFilters) => {
    if (searchTimeout.current) {
      clearTimeout(searchTimeout.current)
    }
    searchTimeout.current = setTimeout(() => {
      loadComponents(nextFilters)
    }, 300)
  }

  const getTypeLabel = (type) => {
    const found = componentTypes.find((item) => item.value === type)
    return found ? found.label : type
  }

  const duplicateComponent = async (component) => {
    try {
      await cmsService.duplicateComponent(component.id)
      await loadComponents()
    } catch (err) {
      console.error('Failed to duplicate component:', err)
      setError(err.response?.data?.message || 'Failed to duplicate component')
    }
  }

  const confirmDelete = (component) => {
    setComponentToDelete(component)
    setShowDeleteModal(true)
  }

  const deleteComponent = async () => {
    if (!componentToDelete) return

    try {
      setDeleting(true)
      await cmsService.deleteComponent(componentToDelete.id)
      setShowDeleteModal(false)
      setComponentToDelete(null)
      await loadComponents()
    } catch (err) {
      console.error('Failed to delete component:', err)
      setError(err.response?.data?.message || 'Failed to delete component')
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
          <h1 className="text-2xl font-bold text-gray-900">CMS Components</h1>
          <p className="mt-1 text-sm text-gray-500">Manage reusable content blocks</p>
        </div>
        <Button onClick={() => navigate('/cp/cms/components/create')}>
          <PlusIcon className="h-5 w-5 mr-2" />
          New Component
        </Button>
      </div>

      <Card className="mb-6">
        <div className="flex flex-wrap gap-4">
          <div className="flex-1 min-w-[200px]">
            <Input
              value={filters.search}
              onUpdateModelValue={(value) => {
                setFilters((prev) => {
                  const nextFilters = { ...prev, search: value }
                  debouncedSearch(nextFilters)
                  return nextFilters
                })
              }}
              placeholder="Search components..."
            />
          </div>
          <div>
            <select
              value={filters.type}
              className="px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
              onChange={(event) => {
                const nextFilters = { ...filters, type: event.target.value }
                setFilters(nextFilters)
                loadComponents(nextFilters)
              }}
            >
              <option value="">All Types</option>
              {componentTypes.map((type) => (
                <option key={type.value} value={type.value}>
                  {type.label}
                </option>
              ))}
            </select>
          </div>
        </div>
      </Card>

      {loading ? (
        <div className="flex justify-center py-12">
          <Loading size="xl" text="Loading components..." />
        </div>
      ) : error ? (
        <Alert variant="danger" className="mb-6">
          {error}
        </Alert>
      ) : components.length === 0 ? (
        <div className="text-center py-12">
          <Card>
            <Squares2X2Icon className="h-12 w-12 mx-auto mb-4 text-gray-400" />
            <p className="text-lg font-medium text-gray-900">No components found</p>
            <p className="text-sm mt-1 text-gray-500">Get started by creating your first component.</p>
            <Button className="mt-4" onClick={() => navigate('/cp/cms/components/create')}>
              <PlusIcon className="h-5 w-5 mr-2" />
              Create Component
            </Button>
          </Card>
        </div>
      ) : (
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
          {components.map((component) => (
            <Card
              key={component.id}
              className="hover:shadow-lg transition-shadow cursor-pointer"
              onClick={() => navigate(`/cp/cms/components/${component.id}`)}
            >
              <div className="flex items-start justify-between">
                <div className="flex-1 min-w-0">
                  <div className="flex items-center gap-2 mb-2">
                    <h3 className="text-lg font-medium text-gray-900 truncate">{component.name}</h3>
                    <Badge variant={component.is_active ? 'success' : 'default'}>
                      {component.is_active ? 'Active' : 'Inactive'}
                    </Badge>
                  </div>
                  <Badge variant="info" className="mb-2">
                    {getTypeLabel(component.type)}
                  </Badge>
                  {component.description ? (
                    <p className="text-sm text-gray-500 line-clamp-2">{component.description}</p>
                  ) : null}
                  <p className="text-xs text-gray-400 mt-2">
                    Updated {formatDate(component.updated_at)}
                  </p>
                </div>
              </div>

              <div
                className="mt-4 pt-4 border-t border-gray-200 flex items-center justify-end gap-2"
                onClick={(event) => event.stopPropagation()}
              >
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() => duplicateComponent(component)}
                  title="Duplicate"
                >
                  <DocumentDuplicateIcon className="h-4 w-4" />
                </Button>
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() => navigate(`/cp/cms/components/${component.id}`)}
                  title="Edit"
                >
                  <PencilIcon className="h-4 w-4" />
                </Button>
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() => confirmDelete(component)}
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
        title="Delete Component"
        onClose={() => setShowDeleteModal(false)}
        footer={(
          <div className="flex justify-end gap-3">
            <Button variant="outline" onClick={() => setShowDeleteModal(false)}>
              Cancel
            </Button>
            <Button variant="danger" onClick={deleteComponent} disabled={deleting}>
              {deleting ? 'Deleting...' : 'Delete'}
            </Button>
          </div>
        )}
      >
        <p className="text-gray-600">
          Are you sure you want to delete the component "<strong>{componentToDelete?.name}</strong>"?
          This action cannot be undone.
        </p>
      </Modal>
    </div>
  )
}
