import { useState } from 'react'
import { Link } from 'react-router-dom'

import Alert from '../../components/ui/Alert'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import { useAuthStore } from '../../stores/auth.jsx'

export default function Login() {
  const { login, verifyTwoFactor, loading, error, pendingChallenge } = useAuthStore()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [twoFactorCode, setTwoFactorCode] = useState('')
  const [formErrors, setFormErrors] = useState({})

  const validate = () => {
    const errors = {}
    if (!email.trim()) {
      errors.email = 'Email is required'
    } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
      errors.email = 'Please enter a valid email address'
    }
    if (!password) {
      errors.password = 'Password is required'
    }
    setFormErrors(errors)
    return Object.keys(errors).length === 0
  }

  const handleSubmit = async (e) => {
    e.preventDefault()
    if (!validate()) return

    try {
      await login(email, password, false)
    } catch {
      // Error is handled by auth store
    }
  }

  const handleTwoFactorSubmit = async (e) => {
    e.preventDefault()
    if (!twoFactorCode.trim()) {
      setFormErrors({ twoFactorCode: 'Please enter the verification code' })
      return
    }

    try {
      await verifyTwoFactor(twoFactorCode)
    } catch {
      // Error is handled by auth store
    }
  }

  if (pendingChallenge) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-gray-50 py-12 px-4 sm:px-6 lg:px-8">
        <div className="max-w-md w-full">
          <div className="text-center mb-8">
            <h2 className="text-3xl font-bold text-gray-900">Two-Factor Authentication</h2>
            <p className="mt-2 text-sm text-gray-600">
              Enter the verification code from your authenticator app
            </p>
          </div>

          <Card>
            <form onSubmit={handleTwoFactorSubmit} className="space-y-6">
              {error ? (
                <Alert variant="danger" closable={false}>
                  {error}
                </Alert>
              ) : null}

              <Input
                label="Verification Code"
                type="text"
                value={twoFactorCode}
                onUpdateModelValue={setTwoFactorCode}
                error={formErrors.twoFactorCode}
                placeholder="Enter 6-digit code"
                autocomplete="one-time-code"
                required
              />

              <Button type="submit" fullWidth loading={loading}>
                Verify
              </Button>
            </form>
          </Card>
        </div>
      </div>
    )
  }

  return (
    <div className="min-h-screen flex items-center justify-center bg-gray-50 py-12 px-4 sm:px-6 lg:px-8">
      <div className="max-w-md w-full">
        <div className="text-center mb-8">
          <h2 className="text-3xl font-bold text-gray-900">Staff Login</h2>
          <p className="mt-2 text-sm text-gray-600">Sign in to your account</p>
        </div>

        <Card>
          <form onSubmit={handleSubmit} className="space-y-6">
            {error ? (
              <Alert variant="danger" closable={false}>
                {error}
              </Alert>
            ) : null}

            <Input
              label="Email Address"
              type="email"
              value={email}
              onUpdateModelValue={setEmail}
              error={formErrors.email}
              placeholder="you@example.com"
              autocomplete="email"
              required
            />

            <Input
              label="Password"
              type="password"
              value={password}
              onUpdateModelValue={setPassword}
              error={formErrors.password}
              placeholder="Enter your password"
              autocomplete="current-password"
              required
            />

            <div className="flex items-center justify-end">
              <Link
                to="/forgot-password"
                className="text-sm text-primary-600 hover:text-primary-500"
              >
                Forgot your password?
              </Link>
            </div>

            <Button type="submit" fullWidth loading={loading}>
              Sign In
            </Button>
          </form>

          <div className="mt-6 text-center text-sm text-gray-600">
            Are you a customer?{' '}
            <Link to="/customer-login" className="text-primary-600 hover:text-primary-500">
              Customer Login
            </Link>
          </div>
        </Card>
      </div>
    </div>
  )
}
