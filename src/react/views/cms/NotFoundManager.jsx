import { useCallback, useEffect, useState } from 'react'

import api from '../../../services/api'

export default function NotFoundManager() {
  const [activeTab, setActiveTab] = useState('404-logs')

  const [logs, setLogs] = useState([])
  const [logsPagination, setLogsPagination] = useState(null)
  const [logsPage, setLogsPage] = useState(1)
  const [logsSearch, setLogsSearch] = useState('')
  const [minHits, setMinHits] = useState('')
  const [statistics, setStatistics] = useState(null)

  const [redirects, setRedirects] = useState([])
  const [redirectsPagination, setRedirectsPagination] = useState(null)
  const [redirectsPage, setRedirectsPage] = useState(1)
  const [redirectsSearch, setRedirectsSearch] = useState('')
  const [showRedirectForm, setShowRedirectForm] = useState(false)
  const [editingRedirect, setEditingRedirect] = useState(null)
  const [redirectForm, setRedirectForm] = useState({
    source_path: '',
    destination_path: '',
    redirect_type: '301',
    match_type: 'exact',
    description: '',
    is_active: true,
  })

  const [loading, setLoading] = useState(false)
  const [submitting, setSubmitting] = useState(false)

  const loadLogs = useCallback(async () => {
    setLoading(true)
    try {
      const params = new URLSearchParams({
        page: logsPage,
        per_page: 50,
      })

      if (logsSearch) params.append('uri', logsSearch)
      if (minHits) params.append('min_hits', minHits)

      const response = await api.get(`/404-logs?${params}`)

      const data = response.data || {}
      setLogs(Array.isArray(data.logs) ? data.logs : [])
      setLogsPagination(data.pagination ?? null)
      setStatistics(data.statistics ?? null)
    } catch (error) {
      console.error('Failed to load 404 logs:', error)
      setLogs([])
      setLogsPagination(null)
      setStatistics(null)
    } finally {
      setLoading(false)
    }
  }, [logsPage, logsSearch, minHits])

  const loadRedirects = useCallback(async () => {
    setLoading(true)
    try {
      const params = new URLSearchParams({
        page: redirectsPage,
        per_page: 50,
      })

      if (redirectsSearch) params.append('search', redirectsSearch)

      const response = await api.get(`/redirects?${params}`)
      setRedirects(response.data.redirects)
      setRedirectsPagination(response.data.pagination)
    } catch (error) {
      console.error('Failed to load redirects:', error)
    } finally {
      setLoading(false)
    }
  }, [redirectsPage, redirectsSearch])

  useEffect(() => {
    loadLogs()
  }, [loadLogs])

  useEffect(() => {
    loadRedirects()
  }, [loadRedirects])

  const deleteLog = async (logId) => {
    if (!window.confirm('Delete this 404 log entry?')) return

    try {
      await api.delete(`/404-logs/${logId}`)
      await loadLogs()
    } catch (error) {
      console.error('Failed to delete log:', error)
      window.alert('Failed to delete log')
    }
  }

  const clearAllLogs = async () => {
    if (!window.confirm('Clear ALL 404 logs? This cannot be undone.')) return

    try {
      await api.post('/404-logs/clear')
      await loadLogs()
    } catch (error) {
      console.error('Failed to clear logs:', error)
      window.alert('Failed to clear logs')
    }
  }

  const createRedirectFrom = (log) => {
    setRedirectForm({
      source_path: log.uri,
      destination_path: '',
      redirect_type: '301',
      match_type: 'exact',
      description: `Redirect from 404 (${log.hits} hits)`,
      is_active: true,
    })
    setShowRedirectForm(true)
    setActiveTab('redirects')
  }

  const editRedirect = (redirect) => {
    setEditingRedirect(redirect)
    setRedirectForm({
      source_path: redirect.source_path,
      destination_path: redirect.destination_path,
      redirect_type: redirect.redirect_type,
      match_type: redirect.match_type,
      description: redirect.description || '',
      is_active: redirect.is_active,
    })
    setShowRedirectForm(true)
  }

  const saveRedirect = async () => {
    if (!redirectForm.source_path || !redirectForm.destination_path) {
      window.alert('Source and destination paths are required')
      return
    }

    setSubmitting(true)
    try {
      if (editingRedirect) {
        await api.put(`/redirects/${editingRedirect.id}`, redirectForm)
      } else {
        await api.post('/redirects', redirectForm)
      }

      setShowRedirectForm(false)
      setEditingRedirect(null)
      await loadRedirects()
    } catch (error) {
      console.error('Failed to save redirect:', error)
      window.alert(error.response?.data?.error || 'Failed to save redirect')
    } finally {
      setSubmitting(false)
    }
  }

  const deleteRedirect = async (redirectId) => {
    if (!window.confirm('Delete this redirect?')) return

    try {
      await api.delete(`/redirects/${redirectId}`)
      await loadRedirects()
    } catch (error) {
      console.error('Failed to delete redirect:', error)
      window.alert('Failed to delete redirect')
    }
  }

  const formatDate = (dateString) => {
    if (!dateString) return 'N/A'
    return new Date(dateString).toLocaleString()
  }

  return (
    <div className="p-6">
      <div className="mb-6">
        <h1 className="text-3xl font-bold text-gray-900">404 Errors & Redirects</h1>
        <p className="mt-2 text-sm text-gray-600">
          Monitor 404 errors and create redirects to fix broken links
        </p>
      </div>

      <div className="border-b border-gray-200 mb-6">
        <nav className="-mb-px flex space-x-8">
          <button
            onClick={() => setActiveTab('404-logs')}
            className={[
              activeTab === '404-logs'
                ? 'border-indigo-500 text-indigo-600'
                : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300',
              'whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm',
            ].join(' ')}
          >
            404 Logs
            {statistics ? (
              <span className="ml-2 py-0.5 px-2.5 rounded-full text-xs font-medium bg-gray-100">
                {statistics.total_unique_uris}
              </span>
            ) : null}
          </button>
          <button
            onClick={() => setActiveTab('redirects')}
            className={[
              activeTab === 'redirects'
                ? 'border-indigo-500 text-indigo-600'
                : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300',
              'whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm',
            ].join(' ')}
          >
            Redirects
            {redirectsPagination ? (
              <span className="ml-2 py-0.5 px-2.5 rounded-full text-xs font-medium bg-gray-100">
                {redirectsPagination.total}
              </span>
            ) : null}
          </button>
        </nav>
      </div>

      {activeTab === '404-logs' ? (
        <div>
          {statistics ? (
            <div className="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
              <div className="bg-white p-4 rounded-lg shadow">
                <div className="text-sm text-gray-600">Unique URIs</div>
                <div className="text-2xl font-bold text-gray-900">{statistics.total_unique_uris}</div>
              </div>
              <div className="bg-white p-4 rounded-lg shadow">
                <div className="text-sm text-gray-600">Total Hits</div>
                <div className="text-2xl font-bold text-gray-900">{statistics.total_hits}</div>
              </div>
              <div className="bg-white p-4 rounded-lg shadow">
                <div className="text-sm text-gray-600">Avg Hits</div>
                <div className="text-2xl font-bold text-gray-900">
                  {Number(statistics.avg_hits).toFixed(1)}
                </div>
              </div>
              <div className="bg-white p-4 rounded-lg shadow">
                <div className="text-sm text-gray-600">Max Hits</div>
                <div className="text-2xl font-bold text-gray-900">{statistics.max_hits}</div>
              </div>
            </div>
          ) : null}

          <div className="bg-white p-4 rounded-lg shadow mb-4 flex justify-between items-center">
            <div className="flex space-x-4">
              <input
                value={logsSearch}
                onChange={(event) => setLogsSearch(event.target.value)}
                onKeyUp={(event) => {
                  if (event.key === 'Enter') loadLogs()
                }}
                type="text"
                placeholder="Search URIs..."
                className="px-3 py-2 border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"
              />
              <select
                value={minHits}
                onChange={(event) => {
                  setMinHits(event.target.value)
                  loadLogs()
                }}
                className="px-3 py-2 border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"
              >
                <option value="">All hits</option>
                <option value="5">5+ hits</option>
                <option value="10">10+ hits</option>
                <option value="50">50+ hits</option>
                <option value="100">100+ hits</option>
              </select>
            </div>
            <button
              onClick={clearAllLogs}
              className="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700"
              disabled={loading}
            >
              Clear All Logs
            </button>
          </div>

          <div className="bg-white shadow overflow-hidden sm:rounded-lg">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    URI
                  </th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Hits
                  </th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    First Seen
                  </th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Last Seen
                  </th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Actions
                  </th>
                </tr>
              </thead>
              <tbody className="bg-white divide-y divide-gray-200">
                {loading ? (
                  <tr>
                    <td colSpan="5" className="px-6 py-4 text-center text-sm text-gray-500">
                      Loading...
                    </td>
                  </tr>
                ) : logs.length === 0 ? (
                  <tr>
                    <td colSpan="5" className="px-6 py-4 text-center text-sm text-gray-500">
                      No 404 errors logged
                    </td>
                  </tr>
                ) : (
                  logs.map((log) => (
                    <tr key={log.id} className="hover:bg-gray-50">
                      <td className="px-6 py-4">
                        <div className="text-sm font-medium text-gray-900 font-mono">{log.uri}</div>
                        {log.referrer ? (
                          <div className="text-xs text-gray-500 mt-1">From: {log.referrer}</div>
                        ) : null}
                      </td>
                      <td className="px-6 py-4">
                        <span className="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">
                          {log.hits}
                        </span>
                      </td>
                      <td className="px-6 py-4 text-sm text-gray-500">{formatDate(log.first_seen)}</td>
                      <td className="px-6 py-4 text-sm text-gray-500">{formatDate(log.last_seen)}</td>
                      <td className="px-6 py-4 text-sm font-medium space-x-2">
                        <button
                          onClick={() => createRedirectFrom(log)}
                          className="text-indigo-600 hover:text-indigo-900"
                        >
                          Create Redirect
                        </button>
                        <button onClick={() => deleteLog(log.id)} className="text-red-600 hover:text-red-900">
                          Delete
                        </button>
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>

            {logsPagination && logsPagination.total_pages > 1 ? (
              <div className="bg-gray-50 px-4 py-3 flex items-center justify-between border-t border-gray-200">
                <div className="text-sm text-gray-700">
                  Page {logsPagination.page} of {logsPagination.total_pages}
                </div>
                <div className="flex space-x-2">
                  <button
                    onClick={() => {
                      setLogsPage((prev) => prev - 1)
                    }}
                    disabled={logsPage === 1}
                    className="px-3 py-1 border border-gray-300 rounded-md text-sm disabled:opacity-50"
                  >
                    Previous
                  </button>
                  <button
                    onClick={() => {
                      setLogsPage((prev) => prev + 1)
                    }}
                    disabled={logsPage >= logsPagination.total_pages}
                    className="px-3 py-1 border border-gray-300 rounded-md text-sm disabled:opacity-50"
                  >
                    Next
                  </button>
                </div>
              </div>
            ) : null}
          </div>
        </div>
      ) : null}

      {activeTab === 'redirects' ? (
        <div>
          <div className="mb-4 flex justify-between items-center">
            <input
              value={redirectsSearch}
              onChange={(event) => setRedirectsSearch(event.target.value)}
              onKeyUp={(event) => {
                if (event.key === 'Enter') loadRedirects()
              }}
              type="text"
              placeholder="Search redirects..."
              className="px-3 py-2 border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"
            />
            <button
              onClick={() => {
                setShowRedirectForm(true)
                setEditingRedirect(null)
              }}
              className="px-4 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700"
            >
              + Add Redirect
            </button>
          </div>

          <div className="bg-white shadow overflow-hidden sm:rounded-lg">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Source</th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Destination</th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Hits</th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                </tr>
              </thead>
              <tbody className="bg-white divide-y divide-gray-200">
                {loading ? (
                  <tr>
                    <td colSpan="6" className="px-6 py-4 text-center text-sm text-gray-500">
                      Loading...
                    </td>
                  </tr>
                ) : redirects.length === 0 ? (
                  <tr>
                    <td colSpan="6" className="px-6 py-4 text-center text-sm text-gray-500">
                      No redirects configured
                    </td>
                  </tr>
                ) : (
                  redirects.map((redirect) => (
                    <tr key={redirect.id} className="hover:bg-gray-50">
                      <td className="px-6 py-4">
                        <div className="text-sm font-medium text-gray-900 font-mono">
                          {redirect.source_path}
                        </div>
                        {redirect.description ? (
                          <div className="text-xs text-gray-500 mt-1">{redirect.description}</div>
                        ) : null}
                      </td>
                      <td className="px-6 py-4">
                        <div className="text-sm text-gray-900 font-mono">{redirect.destination_path}</div>
                      </td>
                      <td className="px-6 py-4">
                        <span className="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-blue-100 text-blue-800">
                          {redirect.redirect_type} ({redirect.match_type})
                        </span>
                      </td>
                      <td className="px-6 py-4 text-sm text-gray-500">{redirect.hits}</td>
                      <td className="px-6 py-4">
                        <span
                          className={`px-2 inline-flex text-xs leading-5 font-semibold rounded-full ${
                            redirect.is_active ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'
                          }`}
                        >
                          {redirect.is_active ? 'Active' : 'Inactive'}
                        </span>
                      </td>
                      <td className="px-6 py-4 text-sm font-medium space-x-2">
                        <button
                          onClick={() => editRedirect(redirect)}
                          className="text-indigo-600 hover:text-indigo-900"
                        >
                          Edit
                        </button>
                        <button
                          onClick={() => deleteRedirect(redirect.id)}
                          className="text-red-600 hover:text-red-900"
                        >
                          Delete
                        </button>
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>

            {redirectsPagination && redirectsPagination.total_pages > 1 ? (
              <div className="bg-gray-50 px-4 py-3 flex items-center justify-between border-t border-gray-200">
                <div className="text-sm text-gray-700">
                  Page {redirectsPagination.page} of {redirectsPagination.total_pages}
                </div>
                <div className="flex space-x-2">
                  <button
                    onClick={() => setRedirectsPage((prev) => prev - 1)}
                    disabled={redirectsPage === 1}
                    className="px-3 py-1 border border-gray-300 rounded-md text-sm disabled:opacity-50"
                  >
                    Previous
                  </button>
                  <button
                    onClick={() => setRedirectsPage((prev) => prev + 1)}
                    disabled={redirectsPage >= redirectsPagination.total_pages}
                    className="px-3 py-1 border border-gray-300 rounded-md text-sm disabled:opacity-50"
                  >
                    Next
                  </button>
                </div>
              </div>
            ) : null}
          </div>
        </div>
      ) : null}

      {showRedirectForm ? (
        <div className="fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center z-50">
          <div className="bg-white rounded-lg shadow-xl max-w-2xl w-full mx-4">
            <div className="px-6 py-4 border-b border-gray-200">
              <h3 className="text-lg font-medium text-gray-900">
                {editingRedirect ? 'Edit Redirect' : 'Create Redirect'}
              </h3>
            </div>

            <div className="px-6 py-4 space-y-4">
              <div>
                <label className="block text-sm font-medium text-gray-700">Source Path *</label>
                <input
                  value={redirectForm.source_path}
                  onChange={(event) =>
                    setRedirectForm((prev) => ({ ...prev, source_path: event.target.value }))
                  }
                  type="text"
                  required
                  placeholder="/old-page"
                  className="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"
                />
              </div>

              <div>
                <label className="block text-sm font-medium text-gray-700">Destination Path *</label>
                <input
                  value={redirectForm.destination_path}
                  onChange={(event) =>
                    setRedirectForm((prev) => ({ ...prev, destination_path: event.target.value }))
                  }
                  type="text"
                  required
                  placeholder="/new-page"
                  className="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"
                />
              </div>

              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700">Redirect Type</label>
                  <select
                    value={redirectForm.redirect_type}
                    onChange={(event) =>
                      setRedirectForm((prev) => ({ ...prev, redirect_type: event.target.value }))
                    }
                    className="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"
                  >
                    <option value="301">301 (Permanent)</option>
                    <option value="302">302 (Temporary)</option>
                    <option value="307">307 (Temporary, Keep Method)</option>
                    <option value="308">308 (Permanent, Keep Method)</option>
                  </select>
                </div>

                <div>
                  <label className="block text-sm font-medium text-gray-700">Match Type</label>
                  <select
                    value={redirectForm.match_type}
                    onChange={(event) =>
                      setRedirectForm((prev) => ({ ...prev, match_type: event.target.value }))
                    }
                    className="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"
                  >
                    <option value="exact">Exact Match</option>
                    <option value="prefix">Prefix Match</option>
                    <option value="regex">Regular Expression</option>
                  </select>
                </div>
              </div>

              <div>
                <label className="block text-sm font-medium text-gray-700">Description (optional)</label>
                <input
                  value={redirectForm.description}
                  onChange={(event) =>
                    setRedirectForm((prev) => ({ ...prev, description: event.target.value }))
                  }
                  type="text"
                  placeholder="What this redirect is for..."
                  className="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"
                />
              </div>

              <div className="flex items-center">
                <input
                  checked={redirectForm.is_active}
                  onChange={(event) =>
                    setRedirectForm((prev) => ({ ...prev, is_active: event.target.checked }))
                  }
                  type="checkbox"
                  id="is_active"
                  className="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded"
                />
                <label htmlFor="is_active" className="ml-2 block text-sm text-gray-700">
                  Active (redirect immediately)
                </label>
              </div>
            </div>

            <div className="px-6 py-4 border-t border-gray-200 flex justify-end space-x-3">
              <button
                onClick={() => {
                  setShowRedirectForm(false)
                  setEditingRedirect(null)
                }}
                className="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50"
              >
                Cancel
              </button>
              <button
                onClick={saveRedirect}
                disabled={submitting}
                className="px-4 py-2 bg-indigo-600 border border-transparent rounded-md text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50"
              >
                {submitting ? 'Saving...' : 'Save Redirect'}
              </button>
            </div>
          </div>
        </div>
      ) : null}
    </div>
  )
}
