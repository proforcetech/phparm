import { useMemo, useState } from 'react'
import { Link, useParams } from 'react-router-dom'

import { useAuthStore } from '../../stores/auth.jsx'

export default function ResetPassword() {
  const { token } = useParams()
  const { resetPassword, loading, error } = useAuthStore()
  const [form, setForm] = useState({
    password: '',
    passwordConfirm: '',
  })
  const [errorMessage, setErrorMessage] = useState(null)
  const [success, setSuccess] = useState(false)

  const isPasswordValid = useMemo(() => {
    return (
      form.password.length >= 8 &&
      form.password === form.passwordConfirm &&
      /[A-Z]/.test(form.password) &&
      /[a-z]/.test(form.password) &&
      /[0-9]/.test(form.password)
    )
  }, [form.password, form.passwordConfirm])

  const handleChange = (field) => (event) => {
    setForm((prev) => ({ ...prev, [field]: event.target.value }))
  }

  const handleSubmit = async (event) => {
    event.preventDefault()

    if (!isPasswordValid) {
      setErrorMessage('Please meet all password requirements')
      return
    }

    setErrorMessage(null)

    try {
      await resetPassword(token, form.password)
      setSuccess(true)
    } catch (err) {
      setErrorMessage(
        err.response?.data?.message || 'Failed to reset password. The reset link may have expired.'
      )
    }
  }

  const displayError = errorMessage || error

  return (
    <div className="min-h-screen flex items-center justify-center bg-gray-50 py-12 px-4 sm:px-6 lg:px-8">
      <div className="max-w-md w-full space-y-8">
        <div>
          <h2 className="mt-6 text-center text-3xl font-extrabold text-gray-900">
            Set new password
          </h2>
          <p className="mt-2 text-center text-sm text-gray-600">Enter your new password below</p>
        </div>

        {!success ? (
          <form className="mt-8 space-y-6" onSubmit={handleSubmit}>
            {displayError ? (
              <div className="rounded-md bg-red-50 p-4">
                <p className="text-sm text-red-800">{displayError}</p>
              </div>
            ) : null}

            <div className="space-y-4">
              <div>
                <label htmlFor="password" className="block text-sm font-medium text-gray-700">
                  New Password
                </label>
                <input
                  id="password"
                  value={form.password}
                  onChange={handleChange('password')}
                  name="password"
                  type="password"
                  autoComplete="new-password"
                  required
                  minLength={8}
                  className="mt-1 appearance-none relative block w-full px-3 py-2 border border-gray-300 placeholder-gray-500 text-gray-900 rounded-md focus:outline-none focus:ring-primary-500 focus:border-primary-500 sm:text-sm"
                  placeholder="Enter new password (min 8 characters)"
                />
              </div>

              <div>
                <label
                  htmlFor="password-confirm"
                  className="block text-sm font-medium text-gray-700"
                >
                  Confirm Password
                </label>
                <input
                  id="password-confirm"
                  value={form.passwordConfirm}
                  onChange={handleChange('passwordConfirm')}
                  name="password-confirm"
                  type="password"
                  autoComplete="new-password"
                  required
                  minLength={8}
                  className={`mt-1 appearance-none relative block w-full px-3 py-2 border border-gray-300 placeholder-gray-500 text-gray-900 rounded-md focus:outline-none focus:ring-primary-500 focus:border-primary-500 sm:text-sm ${
                    form.password &&
                    form.passwordConfirm &&
                    form.password !== form.passwordConfirm
                      ? 'border-red-500'
                      : ''
                  }`}
                  placeholder="Confirm new password"
                />
                {form.password &&
                form.passwordConfirm &&
                form.password !== form.passwordConfirm ? (
                  <p className="mt-1 text-sm text-red-600">Passwords do not match</p>
                ) : null}
              </div>
            </div>

            <div className="bg-gray-50 px-4 py-3 rounded-md">
              <p className="text-xs text-gray-600">Password requirements:</p>
              <ul className="mt-2 text-xs text-gray-600 list-disc list-inside space-y-1">
                <li className={form.password.length >= 8 ? 'text-green-600' : undefined}>
                  At least 8 characters long
                </li>
                <li className={/[A-Z]/.test(form.password) ? 'text-green-600' : undefined}>
                  Contains uppercase letter
                </li>
                <li className={/[a-z]/.test(form.password) ? 'text-green-600' : undefined}>
                  Contains lowercase letter
                </li>
                <li className={/[0-9]/.test(form.password) ? 'text-green-600' : undefined}>
                  Contains number
                </li>
              </ul>
            </div>

            <div>
              <button
                type="submit"
                disabled={loading || !isPasswordValid}
                className="group relative w-full flex justify-center py-2 px-4 border border-transparent text-sm font-medium rounded-md text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 disabled:opacity-50 disabled:cursor-not-allowed"
              >
                <span>{loading ? 'Resetting password...' : 'Reset password'}</span>
              </button>
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
                  <h3 className="text-sm font-medium text-green-800">Password reset successful!</h3>
                  <div className="mt-2 text-sm text-green-700">
                    <p>
                      Your password has been successfully reset. You can now log in with your new
                      password.
                    </p>
                  </div>
                </div>
              </div>
            </div>

            <div className="text-center">
              <Link
                to="/login"
                className="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500"
              >
                Go to login
              </Link>
            </div>
          </div>
        )}
      </div>
    </div>
  )
}
