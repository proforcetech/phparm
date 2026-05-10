import { useCallback, useEffect, useState } from 'react'

import Card from '../../components/ui/Card'
import Alert from '../../components/ui/Alert'
import Loading from '../../components/ui/Loading'
import { portalAccountService } from '../../../services/portal/account.service'

/**
 * Phase 2f — read-only audit trail for the portal account.
 *
 * Server-side scoping: only events touching entities the account owns
 * (work orders, invoices, contracts, tickets, the account itself, the
 * account's CSAT/prefs/tokens) are returned. Sensitive context keys
 * (token/secret/password/ip/user_agent) are stripped before delivery.
 */
const formatDate = (s) => (s ? new Date(s).toLocaleString() : '')

const eventLabel = (event) => event.replace(/[._]/g, ' ')

function ContextDetails({ context }) {
  if (!context || typeof context !== 'object' || Object.keys(context).length === 0) {
    return null
  }
  return (
    <details className="mt-2 text-xs">
      <summary className="cursor-pointer text-gray-500 hover:text-gray-700">
        Details
      </summary>
      <pre className="mt-1 p-2 bg-gray-50 rounded text-[11px] overflow-x-auto">
        {JSON.stringify(context, null, 2)}
      </pre>
    </details>
  )
}

export default function PortalAuditTrail() {
  const [entries, setEntries] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)

  const reload = useCallback(async () => {
    setLoading(true)
    setError(null)
    try {
      const rows = await portalAccountService.listAuditTrail()
      setEntries(Array.isArray(rows) ? rows : [])
    } catch (err) {
      setError(err.response?.data?.message || 'Unable to load audit trail.')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => { reload() }, [reload])

  return (
    <div className="space-y-6">
      <header>
        <h1 className="text-2xl font-semibold">Activity log</h1>
        <p className="text-sm text-gray-600 mt-1">
          Recent activity on your account, work orders, invoices, contracts, and tickets.
        </p>
      </header>

      {error && <Alert variant="error" onClose={() => setError(null)}>{error}</Alert>}

      {loading ? (
        <div className="py-12 flex justify-center"><Loading text="Loading activity…" /></div>
      ) : entries.length === 0 ? (
        <Card>
          <p className="text-sm text-gray-500">No activity yet.</p>
        </Card>
      ) : (
        <Card padding={false}>
          <ol className="divide-y">
            {entries.map((entry) => (
              <li key={entry.id} className="p-4">
                <div className="flex items-start justify-between gap-4 flex-wrap">
                  <div className="min-w-0 flex-1">
                    <div className="text-sm font-medium text-gray-900 capitalize">
                      {eventLabel(entry.event)}
                    </div>
                    <div className="text-xs text-gray-500 mt-0.5">
                      {entry.entity_type}
                      {entry.entity_id ? ` #${entry.entity_id}` : ''}
                    </div>
                    <ContextDetails context={entry.context} />
                  </div>
                  <div className="text-xs text-gray-400 whitespace-nowrap">
                    {formatDate(entry.created_at)}
                  </div>
                </div>
              </li>
            ))}
          </ol>
        </Card>
      )}
    </div>
  )
}
