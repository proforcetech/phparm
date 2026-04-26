import { useState } from 'react'

import { useAuthStore } from '../../stores/auth'
import { authService } from '../../../services/auth.service'

const minLength = 12

export default function PasswordRotationOverlay() {
  const { user, fetchCurrentUser, logout } = useAuthStore()
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState(null)
  const [currentPassword, setCurrentPassword] = useState('')
  const [newPassword, setNewPassword] = useState('')
  const [confirmPassword, setConfirmPassword] = useState('')
  const [twoFactorCode, setTwoFactorCode] = useState('')

  const handleSubmit = async (event) => {
    event.preventDefault()
    setError(null)

    if (newPassword !== confirmPassword) {
      setError('Passwords do not match.')
      return
    }
    if (newPassword.length < minLength) {
      setError(`Password must be at least ${minLength} characters.`)
      return
    }
    if (newPassword === currentPassword) {
      setError('New password must be different from your current password.')
      return
    }

    const payload = {
      current_password: currentPassword,
      password: newPassword,
      password_confirmation: confirmPassword,
    }
    if (user?.two_factor_enabled) {
      payload.two_factor_code = twoFactorCode.trim()
    }

    setSubmitting(true)
    try {
      await authService.updateProfile(payload)
      await fetchCurrentUser()
    } catch (err) {
      const message =
        err?.response?.data?.message ||
        err?.message ||
        'Unable to update password. Please try again.'
      setError(message)
    } finally {
      setSubmitting(false)
    }
  }

  const adminFlagged = !!user?.must_change_password
  const expiredByAge =
    typeof user?.password_expires_in_days === 'number' && user.password_expires_in_days <= 0

  return (
    <div className="fixed inset-0 z-[60] flex items-center justify-center bg-gray-900/70 px-4">
      <div className="w-full max-w-md rounded-lg bg-white shadow-xl">
        <div className="border-b border-gray-200 px-6 py-4">
          <h2 className="text-lg font-semibold text-gray-900">Change your password</h2>
          <p className="mt-1 text-sm text-gray-600">
            {adminFlagged && !expiredByAge
              ? 'An administrator has required you to update your password before continuing.'
              : 'Your password has expired. Please choose a new password to continue.'}
          </p>
        </div>

        <form onSubmit={handleSubmit} className="space-y-4 px-6 py-5">
          {error ? (
            <div className="rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-700">
              {error}
            </div>
          ) : null}

          <div>
            <label htmlFor="rotation-current" className="block text-sm font-medium text-gray-700">
              Current password
            </label>
            <input
              id="rotation-current"
              type="password"
              required
              autoComplete="current-password"
              value={currentPassword}
              onChange={(event) => setCurrentPassword(event.target.value)}
              className="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500"
            />
          </div>

          <div>
            <label htmlFor="rotation-new" className="block text-sm font-medium text-gray-700">
              New password
            </label>
            <input
              id="rotation-new"
              type="password"
              required
              autoComplete="new-password"
              minLength={minLength}
              value={newPassword}
              onChange={(event) => setNewPassword(event.target.value)}
              className="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500"
            />
            <p className="mt-1 text-xs text-gray-500">
              At least {minLength} characters. Must differ from your last several passwords.
            </p>
          </div>

          <div>
            <label htmlFor="rotation-confirm" className="block text-sm font-medium text-gray-700">
              Confirm new password
            </label>
            <input
              id="rotation-confirm"
              type="password"
              required
              autoComplete="new-password"
              minLength={minLength}
              value={confirmPassword}
              onChange={(event) => setConfirmPassword(event.target.value)}
              className="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500"
            />
          </div>

          {user?.two_factor_enabled ? (
            <div>
              <label htmlFor="rotation-2fa" className="block text-sm font-medium text-gray-700">
                Two-factor code
              </label>
              <input
                id="rotation-2fa"
                type="text"
                inputMode="numeric"
                pattern="[0-9]*"
                required
                autoComplete="one-time-code"
                value={twoFactorCode}
                onChange={(event) => setTwoFactorCode(event.target.value)}
                className="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500"
              />
            </div>
          ) : null}

          <div className="flex items-center justify-between gap-3 pt-2">
            <button
              type="button"
              onClick={logout}
              className="text-sm font-medium text-gray-600 hover:text-gray-800"
            >
              Sign out
            </button>
            <button
              type="submit"
              disabled={submitting}
              className="inline-flex items-center justify-center rounded-md bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500 disabled:cursor-not-allowed disabled:opacity-60"
            >
              {submitting ? 'Updating…' : 'Update password'}
            </button>
          </div>
        </form>
      </div>
    </div>
  )
}
