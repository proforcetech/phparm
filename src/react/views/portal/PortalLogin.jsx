import { useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'

import { usePortalAuth } from '../../stores/portalAuth'
import { usePortalTheme } from '../../stores/portalTheme'
import Button from '../../components/ui/Button'
import Input from '../../components/ui/Input'
import Alert from '../../components/ui/Alert'

export default function PortalLogin() {
  const { login, loading } = usePortalAuth()
  const { theme } = usePortalTheme()
  const navigate = useNavigate()
  const [params] = useSearchParams()

  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [submitError, setSubmitError] = useState(null)

  const expired = params.get('expired') === '1'
  const displayName = theme?.display_name || 'Customer Portal'

  const handleSubmit = async (event) => {
    event.preventDefault()
    setSubmitError(null)
    try {
      await login(email.trim(), password)
      navigate('/p', { replace: true })
    } catch (err) {
      const msg = err.response?.data?.message || 'Unable to sign in. Check your credentials and try again.'
      setSubmitError(msg)
    }
  }

  return (
    <div
      className="min-h-screen flex items-center justify-center px-4"
      style={{ backgroundColor: 'var(--portal-bg, #f1f5f9)' }}
    >
      <div className="w-full max-w-md bg-white rounded-2xl shadow-lg p-8">
        <div className="flex flex-col items-center mb-6">
          {theme?.logo_url ? (
            <img src={theme.logo_url} alt={displayName} className="h-12 mb-4" />
          ) : (
            <div
              className="h-12 w-12 rounded-xl mb-4 flex items-center justify-center text-white text-xl font-semibold"
              style={{ backgroundColor: 'var(--portal-primary, #2563eb)' }}
            >
              {displayName.charAt(0).toUpperCase()}
            </div>
          )}
          <h1 className="text-2xl font-semibold">{displayName}</h1>
          <p className="text-sm text-gray-500 mt-1">Sign in to your portal</p>
        </div>

        {expired && !submitError && (
          <div className="mb-4">
            <Alert variant="warning">Your session has expired. Please sign in again.</Alert>
          </div>
        )}
        {submitError && (
          <div className="mb-4">
            <Alert variant="error">{submitError}</Alert>
          </div>
        )}

        <form onSubmit={handleSubmit} className="space-y-4">
          <Input
            id="portal-email"
            type="email"
            label="Email"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            required
            autocomplete="email"
            placeholder="you@company.com"
          />
          <Input
            id="portal-password"
            type="password"
            label="Password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            required
            autocomplete="current-password"
          />
          <Button
            type="submit"
            fullWidth
            loading={loading}
            disabled={loading || !email || !password}
          >
            Sign in
          </Button>
        </form>

        {theme?.support_email && (
          <p className="mt-6 text-xs text-center text-gray-500">
            Need help?{' '}
            <a
              href={`mailto:${theme.support_email}`}
              className="underline"
              style={{ color: 'var(--portal-primary, #2563eb)' }}
            >
              Contact support
            </a>
          </p>
        )}
      </div>
    </div>
  )
}
