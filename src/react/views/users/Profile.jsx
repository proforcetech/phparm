import { useEffect, useState } from 'react'
import { authService } from '../../../services/auth.service'

export default function Profile() {
  const [sessions, setSessions] = useState([])
  const [isLoading, setIsLoading] = useState(true)
  const [error, setError] = useState(null)
  const [revokingId, setRevokingId] = useState(null)

  const loadSessions = async () => {
    setIsLoading(true)
    setError(null)
    try {
      const data = await authService.listSessions()
      setSessions(data.sessions || [])
    } catch (err) {
      setError(err.response?.data?.message || 'Failed to load sessions.')
    } finally {
      setIsLoading(false)
    }
  }

  useEffect(() => {
    loadSessions()
  }, [])

  const handleRevoke = async (sessionId) => {
    setRevokingId(sessionId)
    try {
      await authService.revokeSession(sessionId)
      await loadSessions()
    } catch (err) {
      setError(err.response?.data?.message || 'Failed to revoke session.')
    } finally {
      setRevokingId(null)
    }
  }

  const formatDate = (value) => {
    if (!value) return '—'
    return new Date(value).toLocaleString()
  }

  return (
    <div className="max-w-5xl mx-auto space-y-6 p-6">
      <div>
        <h1 className="text-2xl font-bold text-gray-900">Staff Profile</h1>
        <p className="text-sm text-gray-600">Manage your active sessions and security details.</p>
      </div>

      <section className="rounded-lg border border-gray-200 bg-white shadow-sm">
        <div className="border-b border-gray-100 px-6 py-4">
          <h2 className="text-lg font-semibold text-gray-900">Active Sessions</h2>
          <p className="text-sm text-gray-500">
            Review devices that have access to your account and sign out remotely if needed.
          </p>
        </div>

        {error ? (
          <div className="px-6 py-4 text-sm text-red-600">{error}</div>
        ) : null}

        {isLoading ? (
          <div className="px-6 py-6 text-sm text-gray-500">Loading sessions...</div>
        ) : sessions.length === 0 ? (
          <div className="px-6 py-6 text-sm text-gray-500">No active sessions found.</div>
        ) : (
          <div className="divide-y divide-gray-100">
            {sessions.map((session) => (
              <div key={session.id} className="flex flex-col gap-4 px-6 py-4 md:flex-row md:items-center">
                <div className="flex-1 space-y-1">
                  <div className="flex items-center gap-2">
                    <span className="text-sm font-semibold text-gray-900">
                      {session.device_label || 'Unknown device'}
                    </span>
                    {session.is_current ? (
                      <span className="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-700">
                        This device
                      </span>
                    ) : null}
                  </div>
                  <div className="text-sm text-gray-600">
                    IP: {session.ip_address || 'Unknown'} · Last seen {formatDate(session.last_seen_at)}
                  </div>
                  <div className="text-xs text-gray-400">
                    Logged in {formatDate(session.created_at)}
                  </div>
                  {session.user_agent ? (
                    <div className="text-xs text-gray-400 break-words">{session.user_agent}</div>
                  ) : null}
                </div>
                <div className="flex items-center gap-2">
                  <button
                    type="button"
                    className="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50"
                    onClick={() => handleRevoke(session.id)}
                    disabled={session.is_current || revokingId === session.id}
                  >
                    {revokingId === session.id ? 'Signing out...' : 'Sign out'}
                  </button>
                </div>
              </div>
            ))}
          </div>
        )}
      </section>
    </div>
  )
}
