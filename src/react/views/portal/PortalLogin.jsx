import { useEffect, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'

import { usePortalAuth } from '../../stores/portalAuth'
import { usePortalTheme } from '../../stores/portalTheme'
import { portalAuthService } from '../../../services/portal/auth.service'
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

  const [ssoProviders, setSsoProviders] = useState([])
  const [ssoBusySlug, setSsoBusySlug] = useState(null)

  const expired = params.get('expired') === '1'
  const ssoError = params.get('sso_error')
  const displayName = theme?.display_name || 'Customer Portal'
  const companyId = theme?.company_id || null

  // Fetch SSO providers as soon as we know the tenant. A null company_id
  // (platform default theme — no host match) means we can't scope the list,
  // so we skip the SSO row entirely rather than show all globals.
  useEffect(() => {
    if (!companyId) return
    let cancelled = false
    portalAuthService
      .listSsoProviders(companyId)
      .then((list) => { if (!cancelled) setSsoProviders(Array.isArray(list) ? list : []) })
      .catch(() => { if (!cancelled) setSsoProviders([]) })
    return () => { cancelled = true }
  }, [companyId])

  const startSso = async (slug) => {
    if (!companyId) return
    setSsoBusySlug(slug)
    setSubmitError(null)
    try {
      const result = await portalAuthService.startSso(slug, {
        companyId,
        redirectUri: `${window.location.origin}/p`,
      })
      const url = result?.authorize_url
      if (!url) throw new Error('SSO start did not return an authorize URL.')
      window.location.assign(url)
    } catch (err) {
      const msg = err.response?.data?.message || err.message || 'Unable to start SSO sign-in.'
      setSubmitError(msg)
      setSsoBusySlug(null)
    }
  }

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

        {expired && !submitError && !ssoError && (
          <div className="mb-4">
            <Alert variant="warning">Your session has expired. Please sign in again.</Alert>
          </div>
        )}
        {ssoError && !submitError && (
          <div className="mb-4">
            <Alert variant="error">{decodeURIComponent(ssoError)}</Alert>
          </div>
        )}
        {submitError && (
          <div className="mb-4">
            <Alert variant="error">{submitError}</Alert>
          </div>
        )}

        {ssoProviders.length > 0 && (
          <div className="mb-6 space-y-2">
            {ssoProviders.map((p) => (
              <Button
                key={p.slug}
                type="button"
                variant="outline"
                fullWidth
                loading={ssoBusySlug === p.slug}
                disabled={ssoBusySlug !== null}
                onClick={() => startSso(p.slug)}
              >
                Continue with {p.name}
              </Button>
            ))}
            <div className="relative pt-2">
              <div className="absolute inset-0 flex items-center" aria-hidden="true">
                <div className="w-full border-t border-gray-200" />
              </div>
              <div className="relative flex justify-center">
                <span className="bg-white px-2 text-xs text-gray-500">or sign in with email</span>
              </div>
            </div>
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
