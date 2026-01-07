import { useCallback, useEffect, useMemo, useState } from 'react'
import { Link, useLocation } from 'react-router-dom'

import { securityService } from '../../../services/security.service'
import { useAuthStore } from '../../stores/auth.jsx'

const RECAPTCHA_SCRIPT_ID = 'recaptcha-v3-script'

export default function Login() {
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
  const [recaptchaReady, setRecaptchaReady] = useState(false)
  const [recaptchaLoading, setRecaptchaLoading] = useState(false)
  const [recaptchaLoadError, setRecaptchaLoadError] = useState(null)
  const [recaptchaAttempt, setRecaptchaAttempt] = useState(0)

  const isVerifying = useMemo(() => !!pendingChallenge, [pendingChallenge])

  const loadRecaptcha = useCallback(async () => {
    if (typeof window === 'undefined' || !recaptchaSiteKey) {
      return null
    }

    if (window.grecaptcha?.execute) {
      return window.grecaptcha
    }

    const existingScript = document.getElementById(RECAPTCHA_SCRIPT_ID)

    const scriptPromise = new Promise((resolve, reject) => {
      const handleLoad = () => resolve(window.grecaptcha)

      if (existingScript) {
        if (window.grecaptcha) {
          resolve(window.grecaptcha)
        } else if (existingScript.dataset.recaptchaStatus === 'error') {
          existingScript.remove()
        } else {
          existingScript.addEventListener('load', handleLoad, { once: true })
          existingScript.addEventListener(
            'error',
            () => reject(new Error('Failed to load reCAPTCHA script')),
            { once: true }
          )
          return
        }
      }

      const script = document.createElement('script')
      script.id = RECAPTCHA_SCRIPT_ID
      script.dataset.recaptchaStatus = 'loading'
      script.src = `https://www.google.com/recaptcha/api.js?render=${recaptchaSiteKey}`
      script.async = true
      script.defer = true
      script.onload = () => {
        script.dataset.recaptchaStatus = 'loaded'
        handleLoad()
      }
      script.onerror = () => {
        script.dataset.recaptchaStatus = 'error'
        reject(new Error('Failed to load reCAPTCHA script'))
      }
      document.head.appendChild(script)
    })

    return scriptPromise
  }, [recaptchaSiteKey])

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

  useEffect(() => {
    if (!recaptchaEnabled || !recaptchaSiteKey) {
      setRecaptchaReady(false)
      setRecaptchaLoading(false)
      setRecaptchaLoadError(null)
      return
    }

    let isActive = true
    setRecaptchaLoading(true)
    setRecaptchaLoadError(null)

    loadRecaptcha()
      .then((grecaptcha) => {
        if (!grecaptcha?.ready || !grecaptcha?.execute) {
          throw new Error('reCAPTCHA is not available')
        }

        return new Promise((resolve) => {
          grecaptcha.ready(resolve)
        })
      })
      .then(() => {
        if (!isActive) return
        setRecaptchaReady(true)
      })
      .catch((err) => {
        if (!isActive) return
        setRecaptchaReady(false)
        setRecaptchaLoadError(err)
      })
      .finally(() => {
        if (!isActive) return
        setRecaptchaLoading(false)
      })

    return () => {
      isActive = false
    }
  }, [loadRecaptcha, recaptchaEnabled, recaptchaSiteKey, recaptchaAttempt])

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

      let token = null

      if (recaptchaEnabled) {
        if (!recaptchaSiteKey) {
          throw new Error('reCAPTCHA is not configured')
        }

        if (recaptchaLoadError) {
          throw new Error('Unable to load reCAPTCHA. Please refresh and try again.')
        }

        if (!recaptchaReady) {
          throw new Error('reCAPTCHA is still loading. Please wait a moment and try again.')
        }

        if (recaptchaReady) {
          const grecaptcha = await loadRecaptcha()

          if (!grecaptcha?.execute) {
            throw new Error('reCAPTCHA is not available')
          }

          token = await grecaptcha.execute(recaptchaSiteKey, { action: 'login' })

          if (!token) {
            throw new Error('Failed to verify reCAPTCHA. Please try again.')
          }
        }
      }

      const result = await login(form.email, form.password, false, token)

      if (result?.status === '2fa_required') {
        return
      }
    } catch (err) {
      setErrorMessage(err.response?.data?.message || err.message || 'Invalid credentials')
    }
  }

  const displayError = errorMessage || error
  const disableSubmit =
    loading || (!isVerifying && recaptchaEnabled && !recaptchaReady && !recaptchaLoadError)

  return (
    <div className="min-h-screen flex items-center justify-center bg-gray-50 py-12 px-4 sm:px-6 lg:px-8">
      <div className="max-w-md w-full space-y-8">
        <div>
          <h2 className="mt-6 text-center text-3xl font-extrabold text-gray-900">
            Auto Repair Shop Management
          </h2>
          <p className="mt-2 text-center text-sm text-gray-600">Staff Login</p>
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
            <button
              type="submit"
              disabled={disableSubmit}
              className="group relative w-full flex justify-center py-2 px-4 border border-transparent text-sm font-medium rounded-md text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 disabled:opacity-50 disabled:cursor-not-allowed"
            >
              <span>
                {loading
                  ? 'Logging in...'
                  : !isVerifying && recaptchaEnabled && !recaptchaReady
                    ? 'Loading security check...'
                    : 'Sign in'}
              </span>
            </button>
          </div>

          {!isVerifying && recaptchaEnabled && (recaptchaLoading || recaptchaLoadError) ? (
            <div className="text-xs text-gray-500 text-center space-y-2">
              <p>
                {recaptchaLoadError
                  ? 'Trouble loading reCAPTCHA. You can retry or continue to sign in.'
                  : 'Loading reCAPTCHA...'}
              </p>
              {recaptchaLoadError ? (
                <button
                  type="button"
                  onClick={() => setRecaptchaAttempt((prev) => prev + 1)}
                  className="text-primary-600 hover:text-primary-500 font-medium"
                >
                  Retry security check
                </button>
              ) : null}
            </div>
          ) : null}

          <div className="text-center">
            <Link to="/customer-login" className="text-sm text-primary-600 hover:text-primary-500">
              Customer? Login here
            </Link>
          </div>
        </form>
      </div>
    </div>
  )
}
