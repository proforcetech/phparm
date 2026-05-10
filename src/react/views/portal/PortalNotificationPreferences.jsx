import { useCallback, useEffect, useMemo, useState } from 'react'

import Card from '../../components/ui/Card'
import Alert from '../../components/ui/Alert'
import Loading from '../../components/ui/Loading'
import { portalAccountService } from '../../../services/portal/account.service'

/**
 * Phase 2f — notification preferences matrix.
 *
 * The server returns the full grid (5 keys × 3 channels) merged over
 * defaults, so the UI never has to reason about defaults itself. Toggling
 * a checkbox PUTs the single cell; on success the local row is updated
 * in place to reflect the new stored value.
 */
const PREF_LABELS = {
  request_status: 'Request status updates',
  invoice_issued: 'Invoice issued',
  work_complete: 'Work order completed',
  csat_request: 'Satisfaction survey request',
  message_received: 'Message received',
}

const CHANNEL_LABELS = {
  email: 'Email',
  sms: 'SMS',
  in_app: 'In-app',
}

const CHANNELS = ['email', 'sms', 'in_app']
const KEYS = ['request_status', 'invoice_issued', 'work_complete', 'csat_request', 'message_received']

export default function PortalNotificationPreferences() {
  const [matrix, setMatrix] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [savingCell, setSavingCell] = useState(null)

  const reload = useCallback(async () => {
    setLoading(true)
    setError(null)
    try {
      const rows = await portalAccountService.listNotificationPrefs()
      setMatrix(Array.isArray(rows) ? rows : [])
    } catch (err) {
      setError(err.response?.data?.message || 'Unable to load notification preferences.')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => { reload() }, [reload])

  const lookup = useMemo(() => {
    const m = new Map()
    for (const row of matrix) m.set(`${row.pref_key}:${row.channel}`, row)
    return m
  }, [matrix])

  const cellOf = (key, channel) =>
    lookup.get(`${key}:${channel}`) || { pref_key: key, channel, enabled: false, is_default: true }

  const toggle = async (key, channel, current) => {
    const cellId = `${key}:${channel}`
    setSavingCell(cellId)
    setError(null)
    try {
      const updated = await portalAccountService.setNotificationPref({
        pref_key: key,
        channel,
        enabled: !current,
      })
      setMatrix((prev) => {
        const others = prev.filter((r) => !(r.pref_key === key && r.channel === channel))
        return [...others, { ...updated, is_default: false }]
      })
    } catch (err) {
      setError(err.response?.data?.message || 'Unable to update preference.')
    } finally {
      setSavingCell(null)
    }
  }

  return (
    <div className="space-y-6">
      <header>
        <h1 className="text-2xl font-semibold">Notification preferences</h1>
        <p className="text-sm text-gray-600 mt-1">
          Pick how you want us to reach out for each kind of update.
        </p>
      </header>

      {error && <Alert variant="error" onClose={() => setError(null)}>{error}</Alert>}

      {loading ? (
        <div className="py-12 flex justify-center"><Loading text="Loading preferences…" /></div>
      ) : (
        <Card padding={false}>
          <div className="overflow-x-auto">
            <table className="min-w-full text-sm">
              <thead className="bg-gray-50 text-gray-600">
                <tr>
                  <th className="text-left font-medium px-4 py-3">Notification</th>
                  {CHANNELS.map((c) => (
                    <th key={c} className="text-center font-medium px-4 py-3">
                      {CHANNEL_LABELS[c]}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody className="divide-y">
                {KEYS.map((key) => (
                  <tr key={key}>
                    <td className="px-4 py-3 font-medium text-gray-900">
                      {PREF_LABELS[key]}
                    </td>
                    {CHANNELS.map((channel) => {
                      const cell = cellOf(key, channel)
                      const cellId = `${key}:${channel}`
                      const busy = savingCell === cellId
                      return (
                        <td key={channel} className="px-4 py-3 text-center">
                          <label className="inline-flex items-center justify-center cursor-pointer">
                            <input
                              type="checkbox"
                              className="h-4 w-4 rounded text-blue-600"
                              checked={!!cell.enabled}
                              disabled={busy}
                              onChange={() => toggle(key, channel, !!cell.enabled)}
                            />
                            {cell.is_default && (
                              <span className="ml-2 text-xs text-gray-400 italic">default</span>
                            )}
                          </label>
                        </td>
                      )
                    })}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Card>
      )}

      <p className="text-xs text-gray-500">
        SMS is opt-in. Standard message and data rates may apply.
      </p>
    </div>
  )
}
