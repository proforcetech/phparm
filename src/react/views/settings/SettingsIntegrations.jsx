import { useEffect, useState } from 'react'

import Alert from '../../components/ui/Alert'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import {
  authorizeBankFeed,
  fetchBankFeedStatus,
  syncBankFeed,
} from '../../../services/bank-feeds.service'
import SettingsFormShell from './SettingsFormShell'
import { IntegrationsForm } from './SettingsFormSections'

function BankFeedsStatusCard({ form }) {
  const [status, setStatus] = useState(null)
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(true)
  const [authorizing, setAuthorizing] = useState(false)
  const [syncing, setSyncing] = useState(false)

  const loadStatus = async () => {
    setLoading(true)
    setError('')
    try {
      const response = await fetchBankFeedStatus()
      setStatus(response)
    } catch (statusError) {
      setError(statusError?.response?.data?.message || statusError?.message || 'Failed to load status.')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    loadStatus()
  }, [])

  const handleAuthorize = async () => {
    setAuthorizing(true)
    setError('')
    try {
      const response = await authorizeBankFeed({
        provider: form.integrations.bankFeedsProvider || 'demo',
        access_token: form.integrations.bankFeedsAccessToken,
      })
      setStatus(response)
    } catch (authorizeError) {
      setError(
        authorizeError?.response?.data?.message || authorizeError?.message || 'Failed to authorize.'
      )
    } finally {
      setAuthorizing(false)
    }
  }

  const handleSync = async () => {
    setSyncing(true)
    setError('')
    try {
      const response = await syncBankFeed()
      setStatus(response.status)
    } catch (syncError) {
      setError(syncError?.response?.data?.message || syncError?.message || 'Failed to sync.')
    } finally {
      setSyncing(false)
    }
  }

  return (
    <Card>
      <h2 className="text-lg font-semibold text-gray-900 mb-2">Bank Feeds</h2>
      <p className="text-sm text-gray-500 mb-4">
        Authorize a provider and monitor transaction sync status.
      </p>

      {error ? <Alert variant="danger" className="mb-4">{error}</Alert> : null}

      {loading ? (
        <div className="text-gray-500">Loading bank feed status...</div>
      ) : (
        <div className="space-y-3 text-sm text-gray-700">
          <div className="flex flex-wrap items-center gap-4">
            <div>
              <span className="font-medium text-gray-900">Status:</span>{' '}
              {status?.status || 'disconnected'}
            </div>
            <div>
              <span className="font-medium text-gray-900">Provider:</span>{' '}
              {status?.provider || 'demo'}
            </div>
          </div>
          <div className="flex flex-wrap items-center gap-4">
            <div>
              <span className="font-medium text-gray-900">Last Sync:</span>{' '}
              {status?.last_sync_at || 'Not synced yet'}
            </div>
            <div>
              <span className="font-medium text-gray-900">Last Result:</span>{' '}
              {status?.last_sync_status || '—'}
            </div>
          </div>
          <div className="flex flex-wrap items-center gap-4">
            <div>
              <span className="font-medium text-gray-900">Transactions:</span>{' '}
              {status?.last_sync_count ?? 0}
            </div>
            <div>
              <span className="font-medium text-gray-900">Matched:</span>{' '}
              {status?.last_match_count ?? 0}
            </div>
          </div>
        </div>
      )}

      <div className="mt-4 flex flex-wrap gap-3">
        <Button
          variant="primary"
          loading={authorizing}
          onClick={handleAuthorize}
          disabled={!form.integrations.bankFeedsProvider}
        >
          Authorize Bank Feed
        </Button>
        <Button
          variant="secondary"
          loading={syncing}
          onClick={handleSync}
          disabled={status?.status !== 'connected'}
        >
          Sync Now
        </Button>
        <Button variant="ghost" onClick={loadStatus}>
          Refresh Status
        </Button>
      </div>
    </Card>
  )
}

export default function SettingsIntegrations() {
  return (
    <SettingsFormShell
      title="Integrations"
      description="Connect third-party platforms and configure advanced integrations."
    >
      {({ form, updateField }) => (
        <div className="space-y-6">
          <IntegrationsForm form={form} updateField={updateField} />
          <BankFeedsStatusCard form={form} />
        </div>
      )}
    </SettingsFormShell>
  )
}
