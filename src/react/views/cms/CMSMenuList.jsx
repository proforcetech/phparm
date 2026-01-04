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
import { useCmsMenuStore } from '../../stores/cmsMenus'
import { useToast } from '../../stores/toast.jsx'

export default function CMSMenuList() {
  const navigate = useNavigate()
  const menuStore = useCmsMenuStore()
  const { fetchMenus } = menuStore
  const toast = useToast()

  const [error, setError] = useState(null)
  const [filters, setFilters] = useState({ search: '', status: '' })
  const [showDeleteModal, setShowDeleteModal] = useState(false)
  const [menuToDelete, setMenuToDelete] = useState(null)
  const [deleting, setDeleting] = useState(false)
  const searchTimeout = useRef(null)

  const loadMenus = useCallback(async (nextFilters = filters) => {
    try {
      setError(null)
      await fetchMenus(nextFilters)
    } catch (err) {
      console.error('Failed to load menus:', err)
      setError(err.response?.data?.message || 'Failed to load menus')
    }
  }, [fetchMenus, filters])

  useEffect(() => {
    loadMenus()

    return () => {
      if (searchTimeout.current) {
        clearTimeout(searchTimeout.current)
      }
    }
  }, [loadMenus])

  const debouncedSearch = (nextFilters) => {
    if (searchTimeout.current) {
      clearTimeout(searchTimeout.current)
    }
    searchTimeout.current = setTimeout(() => {
      loadMenus(nextFilters)
    }, 300)
  }

  const confirmDelete = (menu) => {
    setMenuToDelete(menu)
    setShowDeleteModal(true)
  }

  const deleteMenu = async () => {
    if (!menuToDelete) return

    try {
      setDeleting(true)
      await menuStore.deleteMenu(menuToDelete.id)
      toast.success('Menu deleted')
      setShowDeleteModal(false)
      setMenuToDelete(null)
      await loadMenus()
    } catch (err) {
      console.error('Failed to delete menu:', err)
      const message = err.response?.data?.message || 'Failed to delete menu'
      setError(message)
      toast.error(message)
    } finally {
      setDeleting(false)
    }
  }

  const togglePublish = async (menu) => {
    try {
      setError(null)
      if (menu.is_published) {
        await menuStore.updateMenu(menu.id, { ...menu, is_published: false })
        toast.info('Menu moved to drafts')
      } else {
        await menuStore.publishMenu(menu.id)
        toast.success('Menu published')
      }
      await loadMenus()
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
          <h1 className="text-2xl font-bold text-gray-900">CMS Menus</h1>
          <p className="mt-1 text-sm text-gray-500">Manage navigation menus and publish updates</p>
        </div>
        <Button onClick={() => navigate('/cp/cms/menus/create')}>
          <PlusIcon className="h-5 w-5 mr-2" />
          New Menu
        </Button>
      </div>

      <Card className="mb-6">
        <div className="flex flex-wrap gap-4 items-end">
          <div className="flex-1 min-w-[200px]">
            <Input
              value={filters.search}
              placeholder="Search menus..."
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
                loadMenus(nextFilters)
              }}
            >
              <option value="">All Status</option>
              <option value="published">Published</option>
              <option value="draft">Draft</option>
            </select>
          </div>
        </div>
      </Card>

      {menuStore.loading ? (
        <div className="flex justify-center py-12">
          <Loading size="xl" text="Loading menus..." />
        </div>
      ) : error ? (
        <Alert variant="danger" className="mb-6">
          {error}
        </Alert>
      ) : (
        <Card>
          {menuStore.menus.length === 0 ? (
            <div className="text-center py-12 text-gray-500">
              <DocumentDuplicateIcon className="h-12 w-12 mx-auto mb-4 text-gray-400" />
              <p className="text-lg font-medium">No menus found</p>
              <p className="text-sm mt-1">Create your first navigation menu to get started.</p>
              <Button className="mt-4" onClick={() => navigate('/cp/cms/menus/create')}>
                <PlusIcon className="h-5 w-5 mr-2" />
                Create Menu
              </Button>
            </div>
          ) : (
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-gray-200">
                <thead className="bg-gray-50">
                  <tr>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Location</th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Updated</th>
                    <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                  </tr>
                </thead>
                <tbody className="bg-white divide-y divide-gray-200">
                  {menuStore.menus.map((menu) => (
                    <tr
                      key={menu.id}
                      className="hover:bg-gray-50 cursor-pointer"
                      onClick={() => navigate(`/cp/cms/menus/${menu.id}`)}
                    >
                      <td className="px-6 py-4 whitespace-nowrap">
                        <div className="text-sm font-medium text-gray-900">{menu.name}</div>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <div className="text-sm text-gray-500">{menu.location || '—'}</div>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <Badge variant={menu.is_published ? 'success' : 'warning'}>
                          {menu.is_published ? 'Published' : 'Draft'}
                        </Badge>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                        {formatDate(menu.updated_at)}
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                        <div
                          className="flex items-center justify-end gap-2"
                          onClick={(event) => event.stopPropagation()}
                        >
                          <Button variant="secondary" size="sm" onClick={() => togglePublish(menu)}>
                            {menu.is_published ? 'Unpublish' : 'Publish'}
                          </Button>
                          <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => navigate(`/cp/cms/menus/${menu.id}`)}
                          >
                            <PencilIcon className="h-4 w-4" />
                          </Button>
                          <Button variant="ghost" size="sm" onClick={() => confirmDelete(menu)}>
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
        title="Delete Menu"
        onClose={() => setShowDeleteModal(false)}
        footer={(
          <div className="flex justify-end gap-3">
            <Button variant="outline" onClick={() => setShowDeleteModal(false)}>
              Cancel
            </Button>
            <Button variant="danger" disabled={deleting} onClick={deleteMenu}>
              {deleting ? 'Deleting...' : 'Delete'}
            </Button>
          </div>
        )}
      >
        <p className="text-gray-600">
          Are you sure you want to delete the menu "<strong>{menuToDelete?.name}</strong>"?
          This action cannot be undone.
        </p>
      </Modal>
    </div>
  )
}
