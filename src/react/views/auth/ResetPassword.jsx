import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'

import Alert from '../../components/ui/Alert'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import { useAuthStore } from '../../stores/auth.jsx'

export default function ResetPassword() {
  const { token } = useParams()
  const { resetPassword, loading, error } = useAuthStore()
  const [password, setPassword] = useState('')
  const [confirmPassword, setConfirmPassword] = useState('')
  const [formErrors, setFormErrors] = useState({})
  const [submitted, setSubmitted] = useState(false)

  const validate = () => {
    const errors = {}
    if (!password) {
      errors.password = 'Password is required'
    } else if (password.length < 8) {
      errors.password = 'Password must be at least 8 characters'
    }
    if (!confirmPassword) {
      errors.confirmPassword = 'Please confirm your password'
    } else if (password !== confirmPassword) {
      errors.confirmPassword = 'Passwords do not match'
    }
    setFormErrors(errors)
    return Object.keys(errors).length === 0
  }

  const handleSubmit = async (e) => {
    e.preventDefault()
    if (!validate()) return

    try {
      await resetPassword(token, password)
      setSubmitted(true)
    } catch {
      // Error is handled by auth store
    }
  }

  if (!token) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-gray-50 py-12 px-4 sm:px-6 lg:px-8">
        <div className="max-w-md w-full">
          <Card>
            <Alert variant="danger" closable={false}>
              Invalid or missing reset token. Please request a new password reset link.
            </Alert>

            <div className="mt-6 text-center">
              <Link to="/forgot-password" className="text-sm text-primary-600 hover:text-primary-500">
                Request new reset link
              </Link>
            </div>
          </Card>
        </div>
      </div>
    )
  }

  if (submitted) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-gray-50 py-12 px-4 sm:px-6 lg:px-8">
        <div className="max-w-md w-full">
          <div className="text-center mb-8">
            <h2 className="text-3xl font-bold text-gray-900">Password Reset</h2>
            <p className="mt-2 text-sm text-gray-600">Your password has been successfully reset</p>
          </div>

          <Card>
            <Alert variant="success" closable={false}>
              Your password has been updated. You can now sign in with your new password.
            </Alert>

            <div className="mt-6 text-center">
              <Link to="/login">
                <Button fullWidth>Go to Login</Button>
              </Link>
            </div>
          </Card>
        </div>
      </div>
    )
  }

  return (
    <div className="min-h-screen flex items-center justify-center bg-gray-50 py-12 px-4 sm:px-6 lg:px-8">
      <div className="max-w-md w-full">
        <div className="text-center mb-8">
          <h2 className="text-3xl font-bold text-gray-900">Reset Password</h2>
          <p className="mt-2 text-sm text-gray-600">Enter your new password</p>
        </div>

        <Card>
          <form onSubmit={handleSubmit} className="space-y-6">
            {error ? (
              <Alert variant="danger" closable={false}>
                {error}
              </Alert>
            ) : null}

            <Input
              label="New Password"
              type="password"
              value={password}
              onUpdateModelValue={setPassword}
              error={formErrors.password}
              placeholder="Enter new password"
              autocomplete="new-password"
              helperText="Must be at least 8 characters"
              required
            />

            <Input
              label="Confirm Password"
              type="password"
              value={confirmPassword}
              onUpdateModelValue={setConfirmPassword}
              error={formErrors.confirmPassword}
              placeholder="Confirm new password"
              autocomplete="new-password"
              required
            />

            <Button type="submit" fullWidth loading={loading}>
              Reset Password
            </Button>
          </form>

          <div className="mt-6 text-center text-sm text-gray-600">
            Remember your password?{' '}
            <Link to="/login" className="text-primary-600 hover:text-primary-500">
              Back to login
            </Link>
          </div>
        </Card>
      </div>
    </div>
  )
}
