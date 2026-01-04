import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { FolderIcon, PlusIcon } from '@heroicons/react/24/outline'

import Alert from '../../components/ui/Alert'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import { cmsService } from '../../../services/cms.service'

export default function CMSCategoryList() {
  const navigate = useNavigate()
  const [categories, setCategories] = useState([])
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')
  const [filters, setFilters] = useState({ search: '', status: '' })
  const searchTimeout = useRef(null)

  const hierarchicalCategories = useMemo(() => {
    if (!categories.length) return []
    const categoryMap = new Map()
    categories.forEach((category) => {
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
  }, [categories])

  const loadCategories = useCallback(async (nextFilters) => {
    setLoading(true)
    setError('')
    try {
      const params = {}
      if (nextFilters.search) params.search = nextFilters.search
      if (nextFilters.status) params.status = nextFilters.status

      const response = await cmsService.getCategories(params)
      setCategories(response)
    } catch (err) {
      setError(err.message || 'Failed to load categories')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    loadCategories(filters)

    return () => {
      if (searchTimeout.current) {
        clearTimeout(searchTimeout.current)
      }
    }
  }, [loadCategories])

  const debouncedSearch = (nextFilters) => {
    if (searchTimeout.current) {
      clearTimeout(searchTimeout.current)
    }
    searchTimeout.current = setTimeout(() => {
      loadCategories(nextFilters)
    }, 300)
  }

  const editCategory = (id) => {
    navigate(`/cp/cms/categories/${id}`)
  }

  const deleteCategory = async (id) => {
    if (!window.confirm('Are you sure you want to delete this category? Pages in this category will not be deleted.')) {
      return
    }

    try {
      await cmsService.deleteCategory(id)
      await loadCategories()
    } catch (err) {
      if (err.response?.data?.message) {
        window.alert(err.response.data.message)
      } else {
        window.alert(`Failed to delete category: ${err.message || 'Unknown error'}`)
      }
    }
  }

  const formatDate = (dateString) => {
    if (!dateString) return ''
    const date = new Date(dateString)
    return date.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' })
  }

  return (
    <div>
      <div className="mb-8 flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">CMS Categories</h1>
          <p className="mt-1 text-sm text-gray-500">Organize pages into hierarchical categories</p>
        </div>
        <Button onClick={() => navigate('/cp/cms/categories/create')}>
          <PlusIcon className="h-5 w-5 mr-2" />
          New Category
        </Button>
      </div>

      <Card className="mb-6">
        <div className="flex flex-wrap gap-4">
          <div className="flex-1 min-w-[200px]">
            <Input
              value={filters.search}
              placeholder="Search categories..."
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
                loadCategories(nextFilters)
              }}
            >
              <option value="">All Status</option>
              <option value="published">Published</option>
              <option value="draft">Draft</option>
            </select>
          </div>
        </div>
      </Card>

      {loading ? (
        <div className="flex justify-center py-12">
          <Loading size="xl" text="Loading categories..." />
        </div>
      ) : error ? (
        <Alert variant="danger" className="mb-6">
          {error}
        </Alert>
      ) : (
        <Card>
          {categories.length === 0 ? (
            <div className="text-center py-12 text-gray-500">
              <FolderIcon className="h-12 w-12 mx-auto mb-4 text-gray-400" />
              <p className="text-lg font-medium">No categories found</p>
              <p className="text-sm mt-1">Get started by creating your first category.</p>
              <Button className="mt-4" onClick={() => navigate('/cp/cms/categories/create')}>
                <PlusIcon className="h-5 w-5 mr-2" />
                Create Category
              </Button>
            </div>
          ) : (
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-gray-200">
                <thead className="bg-gray-50">
                  <tr>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Name
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Slug
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Sort Order
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
                  {hierarchicalCategories.map((category) => (
                    <tr
                      key={category.id}
                      className="hover:bg-gray-50 cursor-pointer"
                      onClick={() => navigate(`/cp/cms/categories/${category.id}`)}
                    >
                      <td className="px-6 py-4 whitespace-nowrap">
                        <div className="text-sm font-medium text-gray-900">
                          <span className="text-gray-400">{'— '.repeat(category.depth)}</span>
                          {category.name}
                        </div>
                        {category.depth > 0 ? (
                          <div className="text-xs text-gray-400">{category.path.slice(0, -1).join(' / ')}</div>
                        ) : null}
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <div className="text-sm text-gray-500">/{category.slugPath.join('/')}</div>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <div className="text-sm text-gray-500">{category.sort_order}</div>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <span
                          className={`px-2 inline-flex text-xs leading-5 font-semibold rounded-full ${
                            category.status === 'published'
                              ? 'bg-green-100 text-green-800'
                              : category.status === 'draft'
                                ? 'bg-yellow-100 text-yellow-800'
                                : 'bg-gray-100 text-gray-800'
                          }`}
                        >
                          {category.status}
                        </span>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                        {formatDate(category.updated_at)}
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                        <button
                          onClick={(event) => {
                            event.stopPropagation()
                            editCategory(category.id)
                          }}
                          className="text-primary-600 hover:text-primary-900 mr-4"
                        >
                          Edit
                        </button>
                        <button
                          onClick={(event) => {
                            event.stopPropagation()
                            deleteCategory(category.id)
                          }}
                          className="text-red-600 hover:text-red-900"
                        >
                          Delete
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Card>
      )}
    </div>
  )
}
