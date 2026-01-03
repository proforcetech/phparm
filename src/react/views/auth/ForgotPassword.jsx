import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'

import { securityService } from '../../../services/security.service'
import { useRecaptcha } from '../../hooks/useRecaptcha'
import { useAuthStore } from '../../stores/auth.jsx'

export default function ForgotPassword() {
  const { requestPasswordReset, loading, error } = useAuthStore()
  const [email, setEmail] = useState('')
  const [errorMessage, setErrorMessage] = useState(null)
  const [success, setSuccess] = useState(false)
  const [recaptchaEnabled, setRecaptchaEnabled] = useState(false)
  const [recaptchaSiteKey, setRecaptchaSiteKey] = useState('')
  const { recaptchaContainer, recaptchaToken, resetRecaptcha } = useRecaptcha(recaptchaSiteKey)

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

  const handleSubmit = async (event) => {
    event.preventDefault()
    setErrorMessage(null)

    try {
      if (recaptchaEnabled) {
        if (!recaptchaSiteKey) {
          throw new Error('reCAPTCHA is not configured')
        }

        if (!recaptchaToken) {
          throw new Error('Please complete the reCAPTCHA challenge.')
        }
      }

      const token = recaptchaEnabled ? recaptchaToken : null
      await requestPasswordReset(email, token)
      setSuccess(true)
    } catch (err) {
      setErrorMessage(
        err.response?.data?.message ||
          err.message ||
          'Failed to send reset email. Please try again.'
      )
    } finally {
      resetRecaptcha()
    }
  }

  const resetForm = () => {
    setSuccess(false)
    setEmail('')
    setErrorMessage(null)
  }

  const displayError = errorMessage || error

  return (
    <div className="min-h-screen flex items-center justify-center bg-gray-50 py-12 px-4 sm:px-6 lg:px-8">
      <div className="max-w-md w-full space-y-8">
        <div>
          <h2 className="mt-6 text-center text-3xl font-extrabold text-gray-900">
            Reset your password
          </h2>
          <p className="mt-2 text-center text-sm text-gray-600">
            Enter your email address and we&apos;ll send you a link to reset your password
          </p>
        </div>

        {!success ? (
          <form className="mt-8 space-y-6" onSubmit={handleSubmit}>
            {displayError ? (
              <div className="rounded-md bg-red-50 p-4">
                <p className="text-sm text-red-800">{displayError}</p>
              </div>
            ) : null}

            <div>
              <label htmlFor="email" className="sr-only">
                Email address
              </label>
              <input
                id="email"
                value={email}
                onChange={(event) => setEmail(event.target.value)}
                name="email"
                type="email"
                autoComplete="email"
                required
                className="appearance-none relative block w-full px-3 py-2 border border-gray-300 placeholder-gray-500 text-gray-900 rounded-md focus:outline-none focus:ring-primary-500 focus:border-primary-500 focus:z-10 sm:text-sm"
                placeholder="Email address"
              />
            </div>

            <div>
              <div className="flex justify-center">
                {recaptchaEnabled ? <div ref={recaptchaContainer}></div> : null}
              </div>

              <button
                type="submit"
                disabled={loading}
                className="group relative w-full flex justify-center py-2 px-4 border border-transparent text-sm font-medium rounded-md text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 disabled:opacity-50 disabled:cursor-not-allowed"
              >
                <span>{loading ? 'Sending...' : 'Send reset link'}</span>
              </button>
            </div>

            <div className="text-center">
              <Link to="/login" className="text-sm text-primary-600 hover:text-primary-500">
                Back to login
              </Link>
            </div>
          </form>
        ) : (
          <div className="mt-8 space-y-6">
            <div className="rounded-md bg-green-50 p-4">
              <div className="flex">
                <div className="flex-shrink-0">
                  <svg
                    className="h-5 w-5 text-green-400"
                    xmlns="http://www.w3.org/2000/svg"
                    viewBox="0 0 20 20"
                    fill="currentColor"
                  >
                    <path
                      fillRule="evenodd"
                      d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                      clipRule="evenodd"
                    />
                  </svg>
                </div>
                <div className="ml-3">
                  <h3 className="text-sm font-medium text-green-800">Check your email</h3>
                  <div className="mt-2 text-sm text-green-700">
                    <p>
                      We&apos;ve sent a password reset link to <strong>{email}</strong>. Please
                      check your inbox and follow the instructions.
                    </p>
                  </div>
                  <div className="mt-4">
                    <p className="text-xs text-green-700">
                      Didn&apos;t receive the email? Check your spam folder or{' '}
                      <button
                        type="button"
                        onClick={resetForm}
                        className="font-medium underline hover:text-green-600"
                      >
                        try again
                      </button>
                    </p>
                  </div>
                </div>
              </div>
            </div>

            <div className="text-center">
              <Link to="/login" className="text-sm text-primary-600 hover:text-primary-500">
                Back to login
              </Link>
            </div>
          </div>
        )}
      </div>
    </div>
  )
}
