import { useEffect, useMemo, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'

import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Select from '../../components/ui/Select'
import userService from '../../../services/user.service'
import roleService from '../../../services/role.service'
import { useToast } from '../../stores/toast.jsx'

const twoFactorOptions = [
  { label: 'Disabled', value: 'none' },
  { label: 'Authenticator App (TOTP)', value: 'totp' },
  { label: 'SMS', value: 'sms' },
  { label: 'Email', value: 'email' },
]

const twoFactorDescriptions = {
  totp: 'User will need an authenticator app like Google Authenticator or Authy',
  sms: 'User will receive verification codes via SMS',
  email: 'User will receive verification codes via email',
}

export default function UserForm() {
  const { id } = useParams()
  const navigate = useNavigate()
  const toast = useToast()

  const [loading, setLoading] = useState(false)
  const [saving, setSaving] = useState(false)
  const [form, setForm] = useState({
    name: '',
    email: '',
    password: '',
    role: 'technician',
    email_verified: false,
    two_factor_type: 'none',
  })

  const [errors, setErrors] = useState({
    name: '',
    email: '',
    password: '',
    role: '',
  })
  const [roleOptions, setRoleOptions] = useState([])
  const [roleInfo, setRoleInfo] = useState({})

  const isEditMode = useMemo(() => id && id !== 'create', [id])

  const getRoleLabel = (role) => roleInfo[role]?.label || role
  const getRoleDescription = (role) => roleInfo[role]?.description || ''
  const getRolePermissions = (role) => roleInfo[role]?.permissions || []

  const validateForm = () => {
    let isValid = true

    setErrors({ name: '', email: '', password: '', role: '' })

    if (!form.name) {
      setErrors((prev) => ({ ...prev, name: 'Name is required' }))
      isValid = false
    }

    if (!form.email) {
      setErrors((prev) => ({ ...prev, email: 'Email is required' }))
      isValid = false
    } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email)) {
      setErrors((prev) => ({ ...prev, email: 'Invalid email format' }))
      isValid = false
    }

    if (form.password && form.password.length < 12) {
      setErrors((prev) => ({ ...prev, password: 'Password must be at least 12 characters' }))
      isValid = false
    }

    if (!form.role) {
      setErrors((prev) => ({ ...prev, role: 'Role is required' }))
      isValid = false
    }

    return isValid
  }

  const handleSubmit = async () => {
    if (!validateForm()) {
      toast.error('Please fix the errors in the form')
      return
    }

    setSaving(true)
    try {
      const payload = {
        name: form.name,
        email: form.email,
        role: form.role,
        email_verified: form.email_verified,
        two_factor_type: form.two_factor_type,
        two_factor_enabled: form.two_factor_type !== 'none',
      }

      if (form.password) {
        payload.password = form.password
      }

      if (isEditMode) {
        await userService.updateUser(id, payload)
        toast.success('User updated successfully')
      } else {
        await userService.inviteUser(payload)
        toast.success('Invitation sent successfully')
      }

      navigate('/cp/users')
    } catch (error) {
      console.error('Failed to save user:', error)
      const errorMessage = error.response?.data?.message || 'Failed to save user'
      toast.error(errorMessage)

      if (error.response?.data?.errors) {
        setErrors((prev) => ({ ...prev, ...error.response.data.errors }))
      }
    } finally {
      setSaving(false)
    }
  }

  const loadUser = async () => {
    if (!isEditMode) return

    setLoading(true)
    try {
      const user = await userService.getUser(id)
      setForm({
        name: user.name,
        email: user.email,
        role: user.role,
        email_verified: user.email_verified,
        two_factor_type: user.two_factor_type || 'none',
        password: '',
      })
    } catch (error) {
      console.error('Failed to load user:', error)
      toast.error('Failed to load user')
      navigate('/cp/users')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    const loadRoles = async () => {
      try {
        const roles = await roleService.listRoles({ include_system: true })
        setRoleOptions(roles.map((role) => ({ label: role.label, value: role.name })))
        setRoleInfo(
          roles.reduce((acc, role) => {
            acc[role.name] = {
              label: role.label,
              description: role.description || '',
              permissions: role.permissions || [],
            }
            return acc
          }, {})
        )
      } catch (error) {
        console.error('Failed to load roles:', error)
        toast.error('Failed to load roles')
      }
    }

    loadRoles()

    if (isEditMode) {
      loadUser()
    }
  }, [isEditMode])

  return (
    <div>
      <div className="mb-6">
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-4">
            <Button variant="ghost" onClick={() => navigate('/cp/users')}>
              <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
              </svg>
            </Button>
            <div>
              <h1 className="text-2xl font-bold text-gray-900">{isEditMode ? 'Edit User' : 'Invite User'}</h1>
              <p className="mt-1 text-sm text-gray-500">
                {isEditMode
                  ? 'Update user information and permissions'
                  : 'Send a secure invitation link to a new user'}
              </p>
            </div>
          </div>
        </div>
      </div>

      {loading ? (
        <div className="flex justify-center py-12">
          <Loading size="xl" text="Loading user..." />
        </div>
      ) : (
        <div className="space-y-6">
          <Card>
            <h3 className="text-lg font-medium text-gray-900">User Information</h3>

            <div className="space-y-6">
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <Input
                  modelValue={form.name}
                  label="Full Name *"
                  placeholder="John Doe"
                  required
                  error={errors.name}
                  onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, name: value }))}
                />
                <Input
                  modelValue={form.email}
                  type="email"
                  label="Email *"
                  placeholder="john@example.com"
                  required
                  error={errors.email}
                  onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, email: value }))}
                />
              </div>

              {isEditMode ? (
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <div>
                    <label className="block text-sm font-medium text-gray-700 mb-1">Password</label>
                    <Input
                      modelValue={form.password}
                      type="password"
                      placeholder="Leave blank to keep current password"
                      error={errors.password}
                      onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, password: value }))}
                    />
                    <p className="mt-1 text-xs text-gray-500">Minimum 12 characters</p>
                  </div>
                  <div>
                    <label className="block text-sm font-medium text-gray-700 mb-1">Role *</label>
                    <Select
                      modelValue={form.role}
                      options={roleOptions}
                      required
                      error={errors.role}
                      onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, role: value }))}
                    />
                  </div>
                </div>
              ) : (
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <div className="rounded-md bg-blue-50 border border-blue-200 p-4">
                    <p className="text-sm text-blue-800">
                      A secure invitation link will be emailed to this user so they can set their
                      own password.
                    </p>
                  </div>
                  <div>
                    <label className="block text-sm font-medium text-gray-700 mb-1">Role *</label>
                    <Select
                      modelValue={form.role}
                      options={roleOptions}
                      required
                      error={errors.role}
                      onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, role: value }))}
                    />
                  </div>
                </div>
              )}

              <div className="border-t border-gray-200 pt-6">
                <h4 className="text-sm font-medium text-gray-900 mb-4">Account Status</h4>
                <div className="space-y-4">
                  {isEditMode ? (
                    <label className="flex items-center gap-2">
                      <input
                        checked={form.email_verified}
                        onChange={(event) => setForm((prev) => ({ ...prev, email_verified: event.target.checked }))}
                        type="checkbox"
                        className="h-4 w-4 text-indigo-600 rounded"
                      />
                      <span className="text-sm text-gray-700">Email Verified</span>
                    </label>
                  ) : (
                    <div className="rounded-md bg-gray-50 border border-gray-200 p-3 text-sm text-gray-600">
                      Email verification will be completed when the invitation is accepted.
                    </div>
                  )}

                  <div>
                    <label className="block text-sm font-medium text-gray-700 mb-2">Two-Factor Authentication</label>
                    <Select
                      modelValue={form.two_factor_type}
                      options={twoFactorOptions}
                      onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, two_factor_type: value }))}
                    />
                    {form.two_factor_type !== 'none' ? (
                      <p className="mt-1 text-xs text-gray-500">
                        {twoFactorDescriptions[form.two_factor_type] || ''}
                      </p>
                    ) : null}
                  </div>
                </div>
              </div>
            </div>
          </Card>

          {form.role ? (
            <Card>
              <h3 className="text-lg font-medium text-gray-900">Role Permissions</h3>
              <div className="space-y-4">
                <div className="bg-blue-50 border border-blue-200 rounded-lg p-4">
                  <h4 className="text-sm font-semibold text-blue-900 mb-2">{getRoleLabel(form.role)}</h4>
                  <p className="text-sm text-blue-800 mb-3">{getRoleDescription(form.role)}</p>
                  <div className="flex flex-wrap gap-2">
                    {getRolePermissions(form.role).map((permission) => (
                      <Badge key={permission} size="sm" variant="primary">{permission}</Badge>
                    ))}
                  </div>
                </div>
              </div>
            </Card>
          ) : null}

          <div className="flex justify-end gap-2">
            <Button variant="outline" onClick={() => navigate('/cp/users')}>Cancel</Button>
            <Button onClick={handleSubmit} loading={saving}>
              {isEditMode ? 'Update User' : 'Send Invite'}
            </Button>
          </div>
        </div>
      )}
    </div>
  )
}
