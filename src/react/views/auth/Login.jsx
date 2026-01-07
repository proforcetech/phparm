import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Link, useLocation } from 'react-router-dom'

import { securityService } from '../../../services/security.service'
import { useAuthStore } from '../../stores/auth.jsx'

const RECAPTCHA_SCRIPT_ID = 'recaptcha-v3-script'
const MAX_RECAPTCHA_RETRIES = 3
const RECAPTCHA_TIMEOUT_MS = 10000

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
  const [submitting, setSubmitting] = useState(false)
  const [waitingForRecaptcha, setWaitingForRecaptcha] = useState(false)
  const retryCountRef = useRef(0)
  const retryTimeoutRef = useRef(null)

  const isVerifying = useMemo(() => !!pendingChallenge, [pendingChallenge])

  const loadRecaptchaScript = useCallback((siteKey) => {
    return new Promise((resolve, reject) => {
      if (typeof window === 'undefined' || !siteKey) {
        reject(new Error('Invalid environment or site key'))
        return
      }

      // Check if already loaded
      if (window.grecaptcha?.execute) {
        resolve(window.grecaptcha)
        return
      }

      const existingScript = document.getElementById(RECAPTCHA_SCRIPT_ID)

      if (existingScript) {
        if (window.grecaptcha?.execute) {
          resolve(window.grecaptcha)
          return
        }
        // Remove failed script to retry
        if (existingScript.dataset.recaptchaStatus === 'error') {
          existingScript.remove()
        } else if (existingScript.dataset.recaptchaStatus === 'loading') {
          // Wait for existing load
          const handleLoad = () => {
            if (window.grecaptcha?.execute) {
              resolve(window.grecaptcha)
            } else {
              reject(new Error('reCAPTCHA failed to initialize'))
            }
          }
          existingScript.addEventListener('load', handleLoad, { once: true })
          existingScript.addEventListener('error', () => reject(new Error('Script load failed')), { once: true })
          return
        }
      }

      const script = document.createElement('script')
      script.id = RECAPTCHA_SCRIPT_ID
      script.dataset.recaptchaStatus = 'loading'
      script.src = `https://www.google.com/recaptcha/api.js?render=${siteKey}`
      script.async = true
      script.defer = true

      script.onload = () => {
        script.dataset.recaptchaStatus = 'loaded'
        // Wait for grecaptcha.ready
        if (window.grecaptcha?.ready) {
          window.grecaptcha.ready(() => {
            resolve(window.grecaptcha)
          })
        } else {
          reject(new Error('reCAPTCHA not available after load'))
        }
      }

      script.onerror = () => {
        script.dataset.recaptchaStatus = 'error'
        reject(new Error('Failed to load reCAPTCHA script'))
      }

      document.head.appendChild(script)
    })
  }, [])

  const initRecaptcha = useCallback(async (siteKey, isRetry = false) => {
    if (!siteKey) return

    if (!isRetry) {
      retryCountRef.current = 0
    }

    setRecaptchaLoading(true)

    try {
      await loadRecaptchaScript(siteKey)
      setRecaptchaReady(true)
      retryCountRef.current = 0
    } catch (err) {
      console.error('reCAPTCHA load error:', err)
      setRecaptchaReady(false)

      // Auto-retry with exponential backoff
      if (retryCountRef.current < MAX_RECAPTCHA_RETRIES) {
        const delay = Math.pow(2, retryCountRef.current) * 1000 // 1s, 2s, 4s
        retryCountRef.current++

        retryTimeoutRef.current = setTimeout(() => {
          initRecaptcha(siteKey, true)
        }, delay)
      }
    } finally {
      setRecaptchaLoading(false)
    }
  }, [loadRecaptchaScript])

  // Load settings on mount
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

    return () => {
      if (retryTimeoutRef.current) {
        clearTimeout(retryTimeoutRef.current)
      }
    }
  }, [])

  // Initialize reCAPTCHA when settings are loaded
  useEffect(() => {
    if (recaptchaEnabled && recaptchaSiteKey) {
      initRecaptcha(recaptchaSiteKey)
    }
  }, [recaptchaEnabled, recaptchaSiteKey, initRecaptcha])

  // Check for session expired message
  useEffect(() => {
    const params = new URLSearchParams(location.search)
    if (params.get('expired') === '1' && params.get('message')) {
      setSessionExpiredMessage(params.get('message'))
    }
  }, [location.search])

  const getRecaptchaToken = useCallback(async () => {
    if (!recaptchaEnabled || !recaptchaSiteKey) {
      return null
    }

    // If ready, get token immediately
    if (recaptchaReady && window.grecaptcha?.execute) {
      try {
        return await window.grecaptcha.execute(recaptchaSiteKey, { action: 'login' })
      } catch (err) {
        console.error('reCAPTCHA execute error:', err)
        return null
      }
    }

    // If not ready, wait for it with timeout
    setWaitingForRecaptcha(true)

    return new Promise((resolve) => {
      const startTime = Date.now()

      const checkReady = () => {
        // Check if ready now
        if (window.grecaptcha?.execute) {
          window.grecaptcha.execute(recaptchaSiteKey, { action: 'login' })
            .then(resolve)
            .catch(() => resolve(null))
            .finally(() => setWaitingForRecaptcha(false))
          return
        }

        // Check timeout
        if (Date.now() - startTime > RECAPTCHA_TIMEOUT_MS) {
          console.warn('reCAPTCHA timeout - proceeding without token')
          setWaitingForRecaptcha(false)
          resolve(null)
          return
        }

        // Retry check
        setTimeout(checkReady, 500)
      }

      checkReady()
    })
  }, [recaptchaEnabled, recaptchaSiteKey, recaptchaReady])

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

      setSubmitting(true)

      // Get reCAPTCHA token (waits if still loading)
      const token = await getRecaptchaToken()

      const result = await login(form.email, form.password, false, token)

      if (result?.status === '2fa_required') {
        return
      }
    } catch (err) {
      setErrorMessage(err.response?.data?.message || err.message || 'Invalid credentials')
    } finally {
      setSubmitting(false)
    }
  }

  const displayError = errorMessage || error
  const isProcessing = loading || submitting

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
                  disabled={isProcessing}
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
                  disabled={isProcessing}
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
                disabled={isProcessing}
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
              disabled={isProcessing}
              className="group relative w-full flex justify-center py-2 px-4 border border-transparent text-sm font-medium rounded-md text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 disabled:opacity-50 disabled:cursor-not-allowed"
            >
              {isProcessing ? (
                <span className="flex items-center">
                  <svg className="animate-spin -ml-1 mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                    <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                  </svg>
                  {waitingForRecaptcha ? 'Verifying...' : 'Signing in...'}
                </span>
              ) : (
                <span>Sign in</span>
              )}
            </button>
          </div>

          {/* Subtle reCAPTCHA loading indicator - only shows if taking long */}
          {!isVerifying && recaptchaEnabled && recaptchaLoading && !isProcessing ? (
            <p className="text-xs text-gray-400 text-center">
              Loading security verification...
            </p>
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
