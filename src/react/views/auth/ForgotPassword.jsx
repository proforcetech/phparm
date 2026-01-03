import { useState } from 'react'
import { Link } from 'react-router-dom'

import Alert from '../../components/ui/Alert'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import { useAuthStore } from '../../stores/auth.jsx'

export default function ForgotPassword() {
  const { requestPasswordReset, loading, error } = useAuthStore()
  const [email, setEmail] = useState('')
  const [formErrors, setFormErrors] = useState({})
  const [submitted, setSubmitted] = useState(false)

  const validate = () => {
    const errors = {}
    if (!email.trim()) {
      errors.email = 'Email is required'
    } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
      errors.email = 'Please enter a valid email address'
    }
    setFormErrors(errors)
    return Object.keys(errors).length === 0
  }

  const handleSubmit = async (e) => {
    e.preventDefault()
    if (!validate()) return

    try {
      await requestPasswordReset(email)
      setSubmitted(true)
    } catch {
      // Error is handled by auth store
    }
  }

  if (submitted) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-gray-50 py-12 px-4 sm:px-6 lg:px-8">
        <div className="max-w-md w-full">
          <div className="text-center mb-8">
            <h2 className="text-3xl font-bold text-gray-900">Check Your Email</h2>
            <p className="mt-2 text-sm text-gray-600">
              We&apos;ve sent password reset instructions to your email address
            </p>
          </div>

          <Card>
            <Alert variant="success" closable={false}>
              If an account exists with the email <strong>{email}</strong>, you will receive a
              password reset link shortly.
            </Alert>

            <div className="mt-6 text-center">
              <Link to="/login" className="text-sm text-primary-600 hover:text-primary-500">
                Return to login
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
          <h2 className="text-3xl font-bold text-gray-900">Forgot Password</h2>
          <p className="mt-2 text-sm text-gray-600">
            Enter your email address and we&apos;ll send you a reset link
          </p>
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

            <Button type="submit" fullWidth loading={loading}>
              Send Reset Link
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
