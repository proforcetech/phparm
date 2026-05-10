// @deprecated Phase 2a — frozen for legacy `customer` role. New portal lives at src/react/views/portal/*.
import { useCallback, useEffect, useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'

import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Loading from '../../components/ui/Loading'
import { portalService } from '../../../services/portal.service'

const perPage = 10

const statusClasses = (status) => {
  switch (status) {
    case 'completed':
      return 'bg-green-100 text-green-800'
    case 'in_progress':
      return 'bg-blue-100 text-blue-800'
    case 'on_hold':
      return 'bg-yellow-100 text-yellow-800'
    case 'cancelled':
      return 'bg-red-100 text-red-800'
    default:
      return 'bg-gray-100 text-gray-800'
  }
}

const formatDate = (date) => {
  if (!date) return '—'
  return new Date(date).toLocaleDateString()
}

export default function Workorders() {
  const navigate = useNavigate()
  const [workorders, setWorkorders] = useState([])
  const [loading, setLoading] = useState(false)
  const [page, setPage] = useState(1)

  const hasNext = useMemo(() => workorders.length === perPage, [workorders.length])

  const loadWorkorders = useCallback(async () => {
    setLoading(true)
    try {
      const response = await portalService.getWorkorders({ limit: perPage, offset: (page - 1) * perPage })
      setWorkorders(response?.data || [])
    } finally {
      setLoading(false)
    }
  }, [page])

  const changePage = (nextPage) => {
    const updatedPage = Math.max(1, nextPage)
    setPage(updatedPage)
  }

  useEffect(() => {
    loadWorkorders()
  }, [loadWorkorders])

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-gray-900">Communication Hub</h1>
        <p className="mt-1 text-sm text-gray-500">Track updates, approvals, and photos for your services</p>
      </div>

      <Card>
        {loading ? (
          <div className="py-10 flex justify-center">
            <Loading text="Loading workorders..." />
          </div>
        ) : workorders.length === 0 ? (
          <div className="text-center py-12">
            <svg className="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path
                strokeLinecap="round"
                strokeLinejoin="round"
                strokeWidth={2}
                d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
              />
            </svg>
            <h3 className="mt-2 text-sm font-medium text-gray-900">No Workorders</h3>
            <p className="mt-1 text-sm text-gray-500">You don&apos;t have any active service orders yet.</p>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Workorder #</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Created</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total</th>
                  <th className="px-4 py-3"></th>
                </tr>
              </thead>
              <tbody className="bg-white divide-y divide-gray-200">
                {workorders.map((workorder) => (
                  <tr key={workorder.id}>
                    <td className="px-4 py-3 text-sm text-gray-900">{workorder.number}</td>
                    <td className="px-4 py-3 text-sm">
                      <span className={`px-2 inline-flex text-xs leading-5 font-semibold rounded-full ${statusClasses(workorder.status)}`}>
                        {workorder.status_label || workorder.status}
                      </span>
                    </td>
                    <td className="px-4 py-3 text-sm text-gray-500">{formatDate(workorder.created_at)}</td>
                    <td className="px-4 py-3 text-sm text-gray-900">${Number(workorder.grand_total || 0).toFixed(2)}</td>
                    <td className="px-4 py-3 text-right text-sm">
                      <Button variant="ghost" size="sm" onClick={() => navigate(`/portal/workorders/${workorder.id}`)}>
                        View timeline
                      </Button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>

            <div className="flex justify-end items-center gap-3 px-4 py-3 border-t">
              <Button variant="ghost" size="sm" disabled={page === 1} onClick={() => changePage(page - 1)}>
                Previous
              </Button>
              <span className="text-sm text-gray-600">Page {page}</span>
              <Button variant="ghost" size="sm" disabled={!hasNext} onClick={() => changePage(page + 1)}>
                Next
              </Button>
            </div>
          </div>
        )}
      </Card>
    </div>
  )
}
