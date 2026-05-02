import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'

import Alert from '../../components/ui/Alert'
import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Loading from '../../components/ui/Loading'
import { tenantPortalService } from '../../../services/propertyManagement.service'
import { useToast } from '../../stores/toast'

const STATUS_VARIANT = {
  pending: 'default',
  triaged: 'info',
  converted: 'success',
  declined: 'danger',
  cancelled: 'secondary',
}

const PRIORITY_VARIANT = {
  low: 'secondary',
  normal: 'default',
  high: 'warning',
  urgent: 'danger',
}

export default function MyRequests() {
  const [loading, setLoading] = useState(true)
  const [requests, setRequests] = useState([])
  const [error, setError] = useState(null)
  const [cancellingId, setCancellingId] = useState(null)
  const { success, error: toastError } = useToast()

  const load = useCallback(() => {
    setLoading(true)
    return tenantPortalService.listRequests({ per_page: 100 })
      .then(({ requests: rows }) => setRequests(rows))
      .catch((err) => {
        const message = err.response?.data?.error || 'Failed to load requests'
        setError(message)
        toastError(message)
      })
      .finally(() => setLoading(false))
  }, [toastError])

  useEffect(() => { load() }, [load])

  const cancel = async (id) => {
    setCancellingId(id)
    try {
      await tenantPortalService.cancelRequest(id)
      success('Request cancelled')
      await load()
    } catch (err) {
      toastError(err.response?.data?.error || 'Failed to cancel request')
    } finally {
      setCancellingId(null)
    }
  }

  if (loading) return <Loading />

  if (error) {
    return <Alert variant="error">{error}</Alert>
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-semibold text-gray-900">My Maintenance Requests</h1>
        <Link to="/tenant/requests/new">
          <Button>New Request</Button>
        </Link>
      </div>

      {requests.length === 0 ? (
        <Card>
          <p className="text-sm text-gray-600">
            You haven't submitted any maintenance requests yet.
          </p>
        </Card>
      ) : (
        <div className="space-y-3">
          {requests.map((req) => (
            <Card key={req.id}>
              <div className="flex items-start justify-between">
                <div className="flex-1 min-w-0">
                  <div className="flex items-center gap-2 mb-1">
                    <h3 className="text-base font-medium text-gray-900 truncate">{req.title}</h3>
                    <Badge variant={STATUS_VARIANT[req.status] || 'default'}>{req.status}</Badge>
                    <Badge variant={PRIORITY_VARIANT[req.priority] || 'default'}>{req.priority}</Badge>
                  </div>
                  {req.category ? (
                    <p className="text-xs text-gray-500 mb-2">Category: {req.category}</p>
                  ) : null}
                  {req.description ? (
                    <p className="text-sm text-gray-700 whitespace-pre-line">{req.description}</p>
                  ) : null}
                  <p className="mt-2 text-xs text-gray-400">
                    Submitted {req.created_at}
                    {req.workorder_id ? ` — converted to workorder #${req.workorder_id}` : null}
                    {req.declined_reason ? ` — declined: ${req.declined_reason}` : null}
                  </p>
                </div>
                {req.status === 'pending' ? (
                  <Button
                    variant="outline"
                    size="sm"
                    onClick={() => cancel(req.id)}
                    disabled={cancellingId === req.id}
                  >
                    {cancellingId === req.id ? 'Cancelling…' : 'Cancel'}
                  </Button>
                ) : null}
              </div>
            </Card>
          ))}
        </div>
      )}
    </div>
  )
}
