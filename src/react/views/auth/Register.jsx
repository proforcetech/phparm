import { useState } from 'react'
import { Link } from 'react-router-dom'

import Alert from '../../components/ui/Alert'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import { useAuthStore } from '../../stores/auth.jsx'

export default function Register() {
  const { register, loading, error } = useAuthStore()
  const [formData, setFormData] = useState({
    name: '',
    email: '',
    password: '',
    confirmPassword: '',
  })
  const [formErrors, setFormErrors] = useState({})
  const [submitted, setSubmitted] = useState(false)

  const updateField = (field) => (value) => {
    setFormData((prev) => ({ ...prev, [field]: value }))
  }

  const validate = () => {
    const errors = {}
    if (!formData.name.trim()) {
      errors.name = 'Name is required'
    }
    if (!formData.email.trim()) {
      errors.email = 'Email is required'
    } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(formData.email)) {
      errors.email = 'Please enter a valid email address'
    }
    if (!formData.password) {
      errors.password = 'Password is required'
    } else if (formData.password.length < 8) {
      errors.password = 'Password must be at least 8 characters'
    }
    if (!formData.confirmPassword) {
      errors.confirmPassword = 'Please confirm your password'
    } else if (formData.password !== formData.confirmPassword) {
      errors.confirmPassword = 'Passwords do not match'
    }
    setFormErrors(errors)
    return Object.keys(errors).length === 0
  }

  const handleSubmit = async (e) => {
    e.preventDefault()
    if (!validate()) return

    try {
      await register({
        name: formData.name,
        email: formData.email,
        password: formData.password,
      })
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
            <h2 className="text-3xl font-bold text-gray-900">Registration Complete</h2>
            <p className="mt-2 text-sm text-gray-600">Your account has been created</p>
          </div>

          <Card>
            <Alert variant="success" closable={false}>
              Your account has been created successfully. You can now sign in with your credentials.
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
          <h2 className="text-3xl font-bold text-gray-900">Staff Registration</h2>
          <p className="mt-2 text-sm text-gray-600">Create your account</p>
        </div>

        <Card>
          <form onSubmit={handleSubmit} className="space-y-6">
            {error ? (
              <Alert variant="danger" closable={false}>
                {error}
              </Alert>
            ) : null}

            <Input
              label="Full Name"
              type="text"
              value={formData.name}
              onUpdateModelValue={updateField('name')}
              error={formErrors.name}
              placeholder="John Doe"
              autocomplete="name"
              required
            />

            <Input
              label="Email Address"
              type="email"
              value={formData.email}
              onUpdateModelValue={updateField('email')}
              error={formErrors.email}
              placeholder="you@example.com"
              autocomplete="email"
              required
            />

            <Input
              label="Password"
              type="password"
              value={formData.password}
              onUpdateModelValue={updateField('password')}
              error={formErrors.password}
              placeholder="Create a password"
              autocomplete="new-password"
              helperText="Must be at least 8 characters"
              required
            />

            <Input
              label="Confirm Password"
              type="password"
              value={formData.confirmPassword}
              onUpdateModelValue={updateField('confirmPassword')}
              error={formErrors.confirmPassword}
              placeholder="Confirm your password"
              autocomplete="new-password"
              required
            />

            <Button type="submit" fullWidth loading={loading}>
              Create Account
            </Button>
          </form>

          <div className="mt-6 text-center text-sm text-gray-600">
            Already have an account?{' '}
            <Link to="/login" className="text-primary-600 hover:text-primary-500">
              Sign in
            </Link>
          </div>
        </Card>
      </div>
    </div>
  )
}
