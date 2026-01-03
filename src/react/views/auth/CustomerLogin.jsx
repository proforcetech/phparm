import { useEffect, useMemo, useState } from 'react'
import { Link, useLocation } from 'react-router-dom'

import { securityService } from '../../../services/security.service'
import { useRecaptcha } from '../../hooks/useRecaptcha'
import { useAuthStore } from '../../stores/auth.jsx'

export default function CustomerLogin() {
  const { login, verifyTwoFactor, loading, error, pendingChallenge } = useAuthStore()
  const location = useLocation()
  const [form, setForm] = useState({
    email: '',
    password: '',
    remember: false,
  })
  const [code, setCode] = useState('')
  const [errorMessage, setErrorMessage] = useState(null)
  const [sessionExpiredMessage, setSessionExpiredMessage] = useState(null)
  const [recaptchaEnabled, setRecaptchaEnabled] = useState(false)
  const [recaptchaSiteKey, setRecaptchaSiteKey] = useState('')
  const { recaptchaContainer, recaptchaToken, resetRecaptcha } = useRecaptcha(recaptchaSiteKey)

  const isVerifying = useMemo(() => !!pendingChallenge, [pendingChallenge])

  useEffect(() => {
    const params = new URLSearchParams(location.search)
    if (params.get('expired') === '1' && params.get('message')) {
      setSessionExpiredMessage(params.get('message'))
    }
  }, [location.search])

  useEffect(() => {
    const loadSettings = async () => {
      try {
        const settings = await securityService.getRecaptchaSettings()
        setRecaptchaEnabled(!!settings.enabled)
        setRecaptchaSiteKey(settings.site_key || '')
      } catch (err) {
        setRecaptchaEnabled(false)
        setRecaptchaSiteKey('')
        console.error('Failed to load reCAPTCHA settings', err)
      }
    }

    loadSettings()
  }, [])

  const handleChange = (field) => (event) => {
    const value = field === 'remember' ? event.target.checked : event.target.value
    setForm((prev) => ({ ...prev, [field]: value }))
  }

  const handleLogin = async (event) => {
    event.preventDefault()
    setErrorMessage(null)

    try {
      if (isVerifying) {
        if (!code.trim()) {
          setErrorMessage('Please enter the verification code')
          return
        }

        await verifyTwoFactor(code.trim())
        return
      }

      if (recaptchaEnabled) {
        if (!recaptchaSiteKey) {
          throw new Error('reCAPTCHA is not configured')
        }

        if (!recaptchaToken) {
          throw new Error('Please complete the reCAPTCHA challenge.')
        }
      }

      const token = recaptchaEnabled ? recaptchaToken : null
      const result = await login(form.email, form.password, true, token)

      if (result?.status === '2fa_required') {
        return
      }
    } catch (err) {
      setErrorMessage(
        err.response?.data?.message ||
          err.message ||
          'Invalid credentials. Please check your email and password.'
      )
    } finally {
      resetRecaptcha()
    }
  }

  const displayError = errorMessage || error

  return (
    <div className="min-h-screen flex items-center justify-center bg-gray-50 py-12 px-4 sm:px-6 lg:px-8">
      <div className="max-w-md w-full space-y-8">
        <div>
          <h2 className="mt-6 text-center text-3xl font-extrabold text-gray-900">
            Auto Repair Shop Management
          </h2>
          <p className="mt-2 text-center text-sm text-gray-600">Customer Portal Login</p>
        </div>

        <form className="mt-8 space-y-6" onSubmit={handleLogin}>
          {sessionExpiredMessage ? (
            <div className="rounded-md bg-yellow-50 p-4 border border-yellow-200">
              <p className="text-sm text-yellow-800">{sessionExpiredMessage}</p>
            </div>
          ) : null}

          {displayError ? (
            <div className="rounded-md bg-red-50 p-4">
              <p className="text-sm text-red-800">{displayError}</p>
            </div>
          ) : null}

          {!isVerifying ? (
            <div className="rounded-md shadow-sm -space-y-px">
              <div>
                <label htmlFor="email" className="sr-only">
                  Email address
                </label>
                <input
                  id="email"
                  value={form.email}
                  onChange={handleChange('email')}
                  name="email"
                  type="email"
                  autoComplete="email"
                  required
                  className="appearance-none rounded-none relative block w-full px-3 py-2 border border-gray-300 placeholder-gray-500 text-gray-900 rounded-t-md focus:outline-none focus:ring-primary-500 focus:border-primary-500 focus:z-10 sm:text-sm"
                  placeholder="Email address"
                  disabled={loading}
                />
              </div>
              <div>
                <label htmlFor="password" className="sr-only">
                  Password
                </label>
                <input
                  id="password"
                  value={form.password}
                  onChange={handleChange('password')}
                  name="password"
                  type="password"
                  autoComplete="current-password"
                  required
                  className="appearance-none rounded-none relative block w-full px-3 py-2 border border-gray-300 placeholder-gray-500 text-gray-900 rounded-b-md focus:outline-none focus:ring-primary-500 focus:border-primary-500 focus:z-10 sm:text-sm"
                  placeholder="Password"
                  disabled={loading}
                />
              </div>
            </div>
          ) : (
            <div className="space-y-2">
              <label htmlFor="code" className="block text-sm font-medium text-gray-700">
                Authentication code
              </label>
              <input
                id="code"
                value={code}
                onChange={(event) => setCode(event.target.value)}
                name="code"
                type="text"
                inputMode="numeric"
                autoComplete="one-time-code"
                required
                className="appearance-none block w-full px-3 py-2 border border-gray-300 rounded-md placeholder-gray-500 text-gray-900 focus:outline-none focus:ring-primary-500 focus:border-primary-500 focus:z-10 sm:text-sm"
                placeholder="Enter 6-digit code"
                disabled={loading}
              />
              <p className="text-xs text-gray-500">
                Open your authenticator app to retrieve the current code.
              </p>
            </div>
          )}

          {!isVerifying ? (
            <div className="flex items-center justify-between">
              <div className="flex items-center">
                <input
                  id="remember-me"
                  checked={form.remember}
                  onChange={handleChange('remember')}
                  name="remember-me"
                  type="checkbox"
                  className="h-4 w-4 text-primary-600 focus:ring-primary-500 border-gray-300 rounded"
                />
                <label htmlFor="remember-me" className="ml-2 block text-sm text-gray-900">
                  Remember me
                </label>
              </div>

              <div className="text-sm">
                <Link to="/forgot-password" className="font-medium text-primary-600 hover:text-primary-500">
                  Forgot your password?
                </Link>
              </div>
            </div>
          ) : null}

          <div>
            <div className="flex justify-center">
              {recaptchaEnabled ? <div ref={recaptchaContainer}></div> : null}
            </div>

            <button
              type="submit"
              disabled={loading}
              className="group relative w-full flex justify-center py-2 px-4 border border-transparent text-sm font-medium rounded-md text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 disabled:opacity-50 disabled:cursor-not-allowed"
            >
              <span>{loading ? 'Logging in...' : 'Sign in to Portal'}</span>
            </button>
          </div>

          <div className="text-center">
            <Link to="/login" className="text-sm text-primary-600 hover:text-primary-500">
              Staff member? Login here
            </Link>
          </div>

          <div className="mt-6 border-t border-gray-200 pt-6">
            <p className="text-center text-xs text-gray-500">
              Access your service history, invoices, and appointments
            </p>
          </div>
        </form>
      </div>
    </div>
  )
}
