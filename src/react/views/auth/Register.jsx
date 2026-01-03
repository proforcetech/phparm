import { useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'

import { useAuthStore } from '../../stores/auth.jsx'

export default function Register() {
  const navigate = useNavigate()
  const { register, loading, error } = useAuthStore()
  const [form, setForm] = useState({
    first_name: '',
    last_name: '',
    email: '',
    role: '',
    password: '',
    passwordConfirm: '',
    sendEmail: true,
  })
  const [errorMessage, setErrorMessage] = useState(null)
  const [success, setSuccess] = useState(false)

  const isFormValid = useMemo(() => {
    return (
      form.first_name &&
      form.last_name &&
      form.email &&
      form.role &&
      form.password.length >= 8 &&
      form.password === form.passwordConfirm
    )
  }, [form])

  const handleChange = (field) => (event) => {
    const value = field === 'sendEmail' ? event.target.checked : event.target.value
    setForm((prev) => ({ ...prev, [field]: value }))
  }

  const handleSubmit = async (event) => {
    event.preventDefault()

    if (!isFormValid) {
      setErrorMessage('Please fill in all required fields')
      return
    }

    setErrorMessage(null)

    try {
      const { passwordConfirm, ...userData } = form
      await register(userData)
      setSuccess(true)
    } catch (err) {
      setErrorMessage(
        err.response?.data?.message || 'Failed to create account. Email may already be in use.'
      )
    }
  }

  const resetForm = () => {
    setForm({
      first_name: '',
      last_name: '',
      email: '',
      role: '',
      password: '',
      passwordConfirm: '',
      sendEmail: true,
    })
    setSuccess(false)
    setErrorMessage(null)
  }

  const displayError = errorMessage || error

  return (
    <div className="min-h-screen flex items-center justify-center bg-gray-50 py-12 px-4 sm:px-6 lg:px-8">
      <div className="max-w-md w-full space-y-8">
        <div>
          <h2 className="mt-6 text-center text-3xl font-extrabold text-gray-900">
            Register Staff Member
          </h2>
          <p className="mt-2 text-center text-sm text-gray-600">Create a new staff account</p>
        </div>

        {!success ? (
          <form className="mt-8 space-y-6" onSubmit={handleSubmit}>
            {displayError ? (
              <div className="rounded-md bg-red-50 p-4">
                <p className="text-sm text-red-800">{displayError}</p>
              </div>
            ) : null}

            <div className="space-y-4">
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label htmlFor="first-name" className="block text-sm font-medium text-gray-700">
                    First Name
                  </label>
                  <input
                    id="first-name"
                    value={form.first_name}
                    onChange={handleChange('first_name')}
                    name="first-name"
                    type="text"
                    required
                    className="mt-1 appearance-none relative block w-full px-3 py-2 border border-gray-300 placeholder-gray-500 text-gray-900 rounded-md focus:outline-none focus:ring-primary-500 focus:border-primary-500 sm:text-sm"
                    placeholder="John"
                  />
                </div>

                <div>
                  <label htmlFor="last-name" className="block text-sm font-medium text-gray-700">
                    Last Name
                  </label>
                  <input
                    id="last-name"
                    value={form.last_name}
                    onChange={handleChange('last_name')}
                    name="last-name"
                    type="text"
                    required
                    className="mt-1 appearance-none relative block w-full px-3 py-2 border border-gray-300 placeholder-gray-500 text-gray-900 rounded-md focus:outline-none focus:ring-primary-500 focus:border-primary-500 sm:text-sm"
                    placeholder="Doe"
                  />
                </div>
              </div>

              <div>
                <label htmlFor="email" className="block text-sm font-medium text-gray-700">
                  Email Address
                </label>
                <input
                  id="email"
                  value={form.email}
                  onChange={handleChange('email')}
                  name="email"
                  type="email"
                  autoComplete="email"
                  required
                  className="mt-1 appearance-none relative block w-full px-3 py-2 border border-gray-300 placeholder-gray-500 text-gray-900 rounded-md focus:outline-none focus:ring-primary-500 focus:border-primary-500 sm:text-sm"
                  placeholder="john.doe@example.com"
                />
              </div>

              <div>
                <label htmlFor="role" className="block text-sm font-medium text-gray-700">
                  Role
                </label>
                <select
                  id="role"
                  value={form.role}
                  onChange={handleChange('role')}
                  name="role"
                  required
                  className="mt-1 block w-full px-3 py-2 border border-gray-300 bg-white rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500 sm:text-sm"
                >
                  <option value="">Select a role</option>
                  <option value="admin">Administrator</option>
                  <option value="manager">Manager</option>
                  <option value="technician">Technician</option>
                  <option value="receptionist">Receptionist</option>
                </select>
              </div>

              <div>
                <label htmlFor="password" className="block text-sm font-medium text-gray-700">
                  Password
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
                  placeholder="Enter password (min 8 characters)"
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
                  placeholder="Confirm password"
                />
                {form.password &&
                form.passwordConfirm &&
                form.password !== form.passwordConfirm ? (
                  <p className="mt-1 text-sm text-red-600">Passwords do not match</p>
                ) : null}
              </div>

              <div className="flex items-center">
                <input
                  id="send-email"
                  checked={form.sendEmail}
                  onChange={handleChange('sendEmail')}
                  name="send-email"
                  type="checkbox"
                  className="h-4 w-4 text-primary-600 focus:ring-primary-500 border-gray-300 rounded"
                />
                <label htmlFor="send-email" className="ml-2 block text-sm text-gray-900">
                  Send welcome email with login credentials
                </label>
              </div>
            </div>

            <div className="flex gap-3">
              <button
                type="button"
                onClick={() => navigate(-1)}
                className="flex-1 py-2 px-4 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500"
              >
                Cancel
              </button>
              <button
                type="submit"
                disabled={loading || !isFormValid}
                className="flex-1 py-2 px-4 border border-transparent text-sm font-medium rounded-md text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 disabled:opacity-50 disabled:cursor-not-allowed"
              >
                <span>{loading ? 'Creating account...' : 'Create Account'}</span>
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
                  <h3 className="text-sm font-medium text-green-800">
                    Staff account created successfully!
                  </h3>
                  <div className="mt-2 text-sm text-green-700">
                    <p>
                      {form.first_name} {form.last_name} has been registered as {form.role}.
                    </p>
                    {form.sendEmail ? (
                      <p className="mt-1">A welcome email has been sent to {form.email}.</p>
                    ) : null}
                  </div>
                </div>
              </div>
            </div>

            <div className="flex gap-3">
              <button
                type="button"
                onClick={resetForm}
                className="flex-1 py-2 px-4 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500"
              >
                Register Another
              </button>
              <button
                type="button"
                onClick={() => navigate('/cp/dashboard')}
                className="flex-1 py-2 px-4 border border-transparent text-sm font-medium rounded-md text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500"
              >
                Go to Dashboard
              </button>
            </div>
          </div>
        )}
      </div>
    </div>
  )
}
