import { useCallback, useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import {
  ArrowPathIcon,
  CheckCircleIcon,
  DocumentDuplicateIcon,
  InformationCircleIcon,
  PlusIcon,
  RectangleGroupIcon,
  Squares2X2Icon,
} from '@heroicons/react/24/outline'

import Alert from '../../components/ui/Alert'
import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Loading from '../../components/ui/Loading'
import { cmsService } from '../../../services/cms.service'

export default function CMSDashboard() {
  const navigate = useNavigate()
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [stats, setStats] = useState({})
  const [recentPages, setRecentPages] = useState([])
  const [userRole, setUserRole] = useState('')
  const [clearingCache, setClearingCache] = useState(false)

  const loadDashboard = useCallback(async () => {
    try {
      setLoading(true)
      setError(null)

      const data = await cmsService.getDashboard()
      setStats(data.stats || {})
      setRecentPages(data.recent_pages || [])
      setUserRole(data.user_role || 'unknown')
    } catch (err) {
      console.error('Failed to load CMS dashboard:', err)
      setError(err.response?.data?.message || 'Failed to load CMS dashboard')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    loadDashboard()
  }, [loadDashboard])

  const clearCache = async () => {
    try {
      setClearingCache(true)
      await cmsService.clearCache()
    } catch (err) {
      console.error('Failed to clear cache:', err)
      setError('Failed to clear cache')
    } finally {
      setClearingCache(false)
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
      <div className="mb-8">
        <h1 className="text-2xl font-bold text-gray-900">CMS Dashboard</h1>
        <p className="mt-1 text-sm text-gray-500">Manage your website content</p>
      </div>

      {loading ? (
        <div className="flex justify-center py-12">
          <Loading size="xl" text="Loading CMS dashboard..." />
        </div>
      ) : error ? (
        <Alert variant="danger" className="mb-6">
          {error}
        </Alert>
      ) : (
        <div>
          <div className="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-4 mb-8">
            <Card>
              <div className="flex items-center">
                <div className="flex-shrink-0">
                  <div className="flex items-center justify-center h-12 w-12 rounded-md bg-blue-500 text-white">
                    <DocumentDuplicateIcon className="h-6 w-6" />
                  </div>
                </div>
                <div className="ml-5 w-0 flex-1">
                  <dl>
                    <dt className="text-sm font-medium text-gray-500 truncate">Total Pages</dt>
                    <dd className="text-2xl font-semibold text-gray-900">{stats.pages || 0}</dd>
                  </dl>
                </div>
              </div>
            </Card>

            <Card>
              <div className="flex items-center">
                <div className="flex-shrink-0">
                  <div className="flex items-center justify-center h-12 w-12 rounded-md bg-green-500 text-white">
                    <CheckCircleIcon className="h-6 w-6" />
                  </div>
                </div>
                <div className="ml-5 w-0 flex-1">
                  <dl>
                    <dt className="text-sm font-medium text-gray-500 truncate">Published</dt>
                    <dd className="text-2xl font-semibold text-gray-900">{stats.published || 0}</dd>
                  </dl>
                </div>
              </div>
            </Card>

            <Card>
              <div className="flex items-center">
                <div className="flex-shrink-0">
                  <div className="flex items-center justify-center h-12 w-12 rounded-md bg-purple-500 text-white">
                    <Squares2X2Icon className="h-6 w-6" />
                  </div>
                </div>
                <div className="ml-5 w-0 flex-1">
                  <dl>
                    <dt className="text-sm font-medium text-gray-500 truncate">Components</dt>
                    <dd className="text-2xl font-semibold text-gray-900">{stats.components || 0}</dd>
                  </dl>
                </div>
              </div>
            </Card>

            <Card>
              <div className="flex items-center">
                <div className="flex-shrink-0">
                  <div className="flex items-center justify-center h-12 w-12 rounded-md bg-orange-500 text-white">
                    <RectangleGroupIcon className="h-6 w-6" />
                  </div>
                </div>
                <div className="ml-5 w-0 flex-1">
                  <dl>
                    <dt className="text-sm font-medium text-gray-500 truncate">Templates</dt>
                    <dd className="text-2xl font-semibold text-gray-900">{stats.templates || 0}</dd>
                  </dl>
                </div>
              </div>
            </Card>
          </div>

          <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <Card
              header={<h3 className="text-lg font-medium text-gray-900">Quick Actions</h3>}
            >
              <div className="grid grid-cols-2 gap-4">
                <Button variant="outline" onClick={() => navigate('/cp/cms/pages/create')} className="justify-center">
                  <PlusIcon className="h-5 w-5 mr-2" />
                  New Page
                </Button>
                <Button
                  variant="outline"
                  onClick={() => navigate('/cp/cms/components/create')}
                  className="justify-center"
                >
                  <PlusIcon className="h-5 w-5 mr-2" />
                  New Component
                </Button>
                <Button
                  variant="outline"
                  onClick={() => navigate('/cp/cms/templates/create')}
                  className="justify-center"
                >
                  <PlusIcon className="h-5 w-5 mr-2" />
                  New Template
                </Button>
                <Button
                  variant="outline"
                  onClick={clearCache}
                  disabled={clearingCache}
                  className="justify-center"
                >
                  <ArrowPathIcon
                    className={`h-5 w-5 mr-2 ${clearingCache ? 'animate-spin' : ''}`}
                  />
                  Clear Cache
                </Button>
              </div>
            </Card>

            <Card
              header={(
                <div className="flex items-center justify-between">
                  <h3 className="text-lg font-medium text-gray-900">Recent Pages</h3>
                  <Link
                    to="/cp/cms/pages"
                    className="text-sm font-medium text-primary-600 hover:text-primary-500"
                  >
                    View all
                  </Link>
                </div>
              )}
            >
              {recentPages.length === 0 ? (
                <div className="text-center py-6 text-gray-500">No pages yet</div>
              ) : (
                <div className="divide-y divide-gray-200">
                  {recentPages.map((page) => (
                    <div
                      key={page.id}
                      className="py-3 flex items-center justify-between hover:bg-gray-50 cursor-pointer px-2 -mx-2 rounded"
                      onClick={() => navigate(`/cp/cms/pages/${page.id}`)}
                    >
                      <div className="flex-1 min-w-0">
                        <div className="flex items-center gap-2">
                          <p className="text-sm font-medium text-gray-900 truncate">{page.title}</p>
                          <Badge variant={page.status === 'published' ? 'success' : 'warning'}>
                            {page.status === 'published' ? 'Published' : 'Draft'}
                          </Badge>
                        </div>
                        <p className="text-xs text-gray-500">/{page.slug}</p>
                      </div>
                      <div className="ml-4 text-sm text-gray-500">{formatDate(page.updated_at)}</div>
                    </div>
                  ))}
                </div>
              )}
            </Card>
          </div>

          <div className="mt-6">
            <Alert variant="info">
              <div className="flex items-center">
                <InformationCircleIcon className="h-5 w-5 mr-2" />
                <span>
                  You have <strong>{userRole}</strong> access to the CMS.
                </span>
              </div>
            </Alert>
          </div>
        </div>
      )}
    </div>
  )
}
