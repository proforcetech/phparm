import { useEffect, useMemo, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'

import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import { useAuthStore } from '../../stores/auth.jsx'
import { useToast } from '../../stores/toast.jsx'
import auditService from '../../../services/audit.service'

const formatDateTime = (value) => {
  if (!value) return '—'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return '—'
  return date.toLocaleString('en-US', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

export default function AuditLogs() {
  const navigate = useNavigate()
  const toast = useToast()
  const { isAdmin, hasPermission } = useAuthStore()
  const canViewAudit = isAdmin && hasPermission('audit.view')

  const [searchParams, setSearchParams] = useSearchParams()
  const [logs, setLogs] = useState([])
  const [loading, setLoading] = useState(false)
  const [filters, setFilters] = useState({
    actor_id: searchParams.get('actor_id') || '',
    entity_type: searchParams.get('entity_type') || '',
  })

  useEffect(() => {
    setFilters({
      actor_id: searchParams.get('actor_id') || '',
      entity_type: searchParams.get('entity_type') || '',
    })
  }, [searchParams])

  const activeFilters = useMemo(() => {
    const params = {}
    if (filters.actor_id) params.actor_id = filters.actor_id
    if (filters.entity_type) params.entity_type = filters.entity_type
    return params
  }, [filters])

  const updateFilters = (nextFilters) => {
    const params = {}
    if (nextFilters.actor_id) params.actor_id = nextFilters.actor_id
    if (nextFilters.entity_type) params.entity_type = nextFilters.entity_type
    setSearchParams(params)
  }

  useEffect(() => {
    if (!canViewAudit) return

    const loadLogs = async () => {
      setLoading(true)
      try {
        const data = await auditService.listAuditLogs(activeFilters)
        setLogs(data)
      } catch (error) {
        console.error('Failed to load audit logs:', error)
        toast.error('Failed to load audit logs')
      } finally {
        setLoading(false)
      }
    }

    loadLogs()
  }, [activeFilters, canViewAudit, toast])

  if (!canViewAudit) {
    return (
      <Card>
        <div className="flex flex-col items-start gap-4">
          <div>
            <h1 className="text-xl font-semibold text-gray-900">Audit Logs</h1>
            <p className="text-sm text-gray-500">You do not have permission to view audit logs.</p>
          </div>
          <Button variant="outline" onClick={() => navigate('/cp/users')}>Back to users</Button>
        </div>
      </Card>
    )
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Audit Logs</h1>
          <p className="mt-1 text-sm text-gray-500">Review system activity filtered by user or entity.</p>
        </div>
        <Button variant="outline" onClick={() => navigate('/cp/users')}>Back to users</Button>
      </div>

      <Card>
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Actor ID</label>
            <Input
              modelValue={filters.actor_id}
              placeholder="Filter by user ID"
              onUpdateModelValue={(value) => {
                const next = { ...filters, actor_id: value }
                setFilters(next)
                updateFilters(next)
              }}
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Entity Type</label>
            <Input
              modelValue={filters.entity_type}
              placeholder="Filter by entity type"
              onUpdateModelValue={(value) => {
                const next = { ...filters, entity_type: value }
                setFilters(next)
                updateFilters(next)
              }}
            />
          </div>
        </div>
      </Card>

      {loading ? (
        <div className="flex justify-center py-12">
          <Loading size="xl" text="Loading audit logs..." />
        </div>
      ) : (
        <Card>
          <h3 className="text-lg font-medium text-gray-900">Audit Entries ({logs.length})</h3>

          {logs.length === 0 ? (
            <div className="text-center py-12 text-gray-500">No audit logs found.</div>
          ) : (
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-gray-200">
                <thead className="bg-gray-50">
                  <tr>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Event</th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Entity</th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actor</th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Context</th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Timestamp</th>
                  </tr>
                </thead>
                <tbody className="bg-white divide-y divide-gray-200">
                  {logs.map((log, index) => {
                    const event = log.event || '—'
                    const entityType = log.entity_type || log.entityType || '—'
                    const entityId = log.entity_id ?? log.entityId
                    const actorId = log.actor_id ?? log.actorId ?? '—'
                    const createdAt = log.created_at || log.createdAt
                    const context = log.context || {}

                    return (
                      <tr key={`${event}-${entityId ?? index}`} className="hover:bg-gray-50">
                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{event}</td>
                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                          {entityType}
                          {entityId != null ? ` #${entityId}` : ''}
                        </td>
                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{actorId ?? '—'}</td>
                        <td className="px-6 py-4 text-xs text-gray-500">
                          <pre className="whitespace-pre-wrap break-words">{JSON.stringify(context, null, 2)}</pre>
                        </td>
                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{formatDateTime(createdAt)}</td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>
          )}
        </Card>
      )}
    </div>
  )
}
