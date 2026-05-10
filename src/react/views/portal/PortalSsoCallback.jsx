import { useEffect, useRef, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'

import { usePortalAuth } from '../../stores/portalAuth'
import { usePortalTheme } from '../../stores/portalTheme'
import Loading from '../../components/ui/Loading'
import Alert from '../../components/ui/Alert'
import Button from '../../components/ui/Button'

/**
 * Phase 2e — landing page for the IdP redirect-back.
 *
 * The IdP sends ?state=&code= here; we exchange them with the backend for a
 * portal-scoped JWT pair and seed the auth context so the user lands on the
 * dashboard authenticated. On error we bounce back to /p/login with a
 * sso_error query so the login page can render the failure inline.
 *
 * Public route (no requirePortalAuth) — the user is unauthenticated until the
 * exchange succeeds.
 */
export default function PortalSsoCallback() {
  const [params] = useSearchParams()
  const navigate = useNavigate()
  const { completeSso } = usePortalAuth()
  const { theme } = usePortalTheme()
  const [error, setError] = useState(null)
  const ranRef = useRef(false)

  useEffect(() => {
    if (ranRef.current) return
    ranRef.current = true

    const state = params.get('state')
    const code = params.get('code')
    const idpError = params.get('error_description') || params.get('error')

    if (idpError) {
      navigate(`/p/login?sso_error=${encodeURIComponent(idpError)}`, { replace: true })
      return
    }
    if (!state || !code) {
      setError('SSO callback is missing state or code parameters.')
      return
    }

    completeSso({ state, code })
      .then(() => {
        navigate('/p', { replace: true })
      })
      .catch((err) => {
        const msg = err.response?.data?.message || err.message || 'SSO sign-in failed.'
        setError(msg)
      })
  }, [completeSso, navigate, params])

  const displayName = theme?.display_name || 'Customer Portal'

  return (
    <div
      className="min-h-screen flex items-center justify-center px-4"
      style={{ backgroundColor: 'var(--portal-bg, #f1f5f9)' }}
    >
      <div className="w-full max-w-md bg-white rounded-2xl shadow-lg p-8 text-center">
        <h1 className="text-xl font-semibold mb-4">{displayName}</h1>
        {error ? (
          <>
            <Alert variant="error">{error}</Alert>
            <div className="mt-6">
              <Button onClick={() => navigate('/p/login', { replace: true })} fullWidth>
                Back to sign in
              </Button>
            </div>
          </>
        ) : (
          <Loading text="Completing sign-in…" />
        )}
      </div>
    </div>
  )
}
