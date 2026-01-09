import { useEffect, useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'

import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Modal from '../../components/ui/Modal'
import roleService from '../../../services/role.service'
import { useToast } from '../../stores/toast.jsx'

const emptyForm = {
  name: '',
  label: '',
  description: '',
  permissions: [],
}

const formatGroupLabel = (group) => {
  if (group === '*') return 'System'
  return group
    .split('_')
    .map((segment) => segment.charAt(0).toUpperCase() + segment.slice(1))
    .join(' ')
}

export default function RoleManagement() {
  const navigate = useNavigate()
  const toast = useToast()

  const [roles, setRoles] = useState([])
  const [permissions, setPermissions] = useState([])
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [showForm, setShowForm] = useState(false)
  const [editingRole, setEditingRole] = useState(null)
  const [filters, setFilters] = useState({ query: '' })
  const [form, setForm] = useState(emptyForm)
  const [errors, setErrors] = useState({ name: '', label: '' })

  const permissionGroups = useMemo(() => {
    const groups = new Map()

    permissions.forEach((permission) => {
      const [prefix] = permission.split('.')
      const key = permission === '*' ? '*' : prefix
      const list = groups.get(key) || []
      list.push(permission)
      groups.set(key, list)
    })

    return Array.from(groups.entries())
      .map(([key, list]) => ({ key, permissions: list.sort() }))
      .sort((a, b) => a.key.localeCompare(b.key))
  }, [permissions])

  const loadData = async (nextFilters = filters) => {
    setLoading(true)
    try {
      const [rolesData, permissionsData] = await Promise.all([
        roleService.listRoles({ ...nextFilters, include_system: true }),
        roleService.getAvailablePermissions(),
      ])
      setRoles(rolesData)
      setPermissions(permissionsData || [])
    } catch (error) {
      console.error('Failed to load roles:', error)
      toast.error(error.response?.data?.message || 'Failed to load roles')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    loadData()
  }, [])

  const resetForm = () => {
    setForm(emptyForm)
    setErrors({ name: '', label: '' })
    setEditingRole(null)
  }

  const openCreate = () => {
    resetForm()
    setShowForm(true)
  }

  const openEdit = (role) => {
    setEditingRole(role)
    setForm({
      name: role.name,
      label: role.label,
      description: role.description || '',
      permissions: role.permissions || [],
    })
    setErrors({ name: '', label: '' })
    setShowForm(true)
  }

  const closeForm = () => {
    setShowForm(false)
    resetForm()
  }

  const validateForm = () => {
    let valid = true
    setErrors({ name: '', label: '' })

    if (!form.name.trim()) {
      valid = false
      setErrors((prev) => ({ ...prev, name: 'Role key is required' }))
    }

    if (!/^[a-z0-9_-]+$/.test(form.name.trim())) {
      valid = false
      setErrors((prev) => ({ ...prev, name: 'Use lowercase letters, numbers, dashes, or underscores' }))
    }

    if (!form.label.trim()) {
      valid = false
      setErrors((prev) => ({ ...prev, label: 'Role label is required' }))
    }

    return valid
  }

  const handleSave = async () => {
    if (!validateForm()) {
      toast.error('Please fix the form errors')
      return
    }

    setSaving(true)
    try {
      const payload = {
        name: form.name.trim(),
        label: form.label.trim(),
        description: form.description?.trim() || null,
        permissions: form.permissions,
      }

      if (editingRole) {
        await roleService.updateRole(editingRole.id, payload)
        toast.success('Role updated successfully')
      } else {
        await roleService.createRole(payload)
        toast.success('Role created successfully')
      }

      closeForm()
      await loadData()
    } catch (error) {
      console.error('Failed to save role:', error)
      toast.error(error.response?.data?.message || 'Failed to save role')
    } finally {
      setSaving(false)
    }
  }

  const handleDelete = async (role) => {
    if (role.is_system) return

    if (!window.confirm(`Delete the ${role.label} role?`)) {
      return
    }

    try {
      await roleService.deleteRole(role.id)
      toast.success('Role deleted')
      await loadData()
    } catch (error) {
      console.error('Failed to delete role:', error)
      toast.error(error.response?.data?.message || 'Failed to delete role')
    }
  }

  const togglePermission = (permission) => {
    setForm((prev) => {
      if (permission === '*') {
        const hasAll = prev.permissions.includes('*')
        return {
          ...prev,
          permissions: hasAll ? prev.permissions.filter((item) => item !== '*') : ['*'],
        }
      }

      const hasAll = prev.permissions.includes('*')
      const current = hasAll ? [] : prev.permissions
      const nextPermissions = current.includes(permission)
        ? current.filter((item) => item !== permission)
        : [...current, permission]

      return {
        ...prev,
        permissions: nextPermissions,
      }
    })
  }

  return (
    <div>
      <div className="mb-6">
        <div className="flex flex-wrap items-center justify-between gap-4">
          <div className="flex items-center gap-4">
            <Button variant="ghost" onClick={() => navigate('/cp/users')}>
              <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
              </svg>
            </Button>
            <div>
              <h1 className="text-2xl font-bold text-gray-900">Role Management</h1>
              <p className="mt-1 text-sm text-gray-500">Create custom roles and assign permission sets.</p>
            </div>
          </div>
          <Button onClick={openCreate}>Create Role</Button>
        </div>
      </div>

      <Card className="mb-6">
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Search</label>
            <Input
              modelValue={filters.query}
              placeholder="Search by role name or label..."
              onUpdateModelValue={(value) => {
                const nextFilters = { ...filters, query: value }
                setFilters(nextFilters)
                loadData(nextFilters)
              }}
            />
          </div>
        </div>
      </Card>

      {loading ? (
        <div className="flex justify-center py-12">
          <Loading size="xl" text="Loading roles..." />
        </div>
      ) : (
        <Card>
          <h3 className="text-lg font-medium text-gray-900">Roles ({roles.length})</h3>

          {roles.length === 0 ? (
            <div className="text-center py-12 text-gray-500">No roles found</div>
          ) : (
            <div className="divide-y divide-gray-200">
              {roles.map((role) => (
                <div key={role.id ?? role.name} className="py-5 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                  <div>
                    <div className="flex items-center gap-2">
                      <h4 className="text-base font-semibold text-gray-900">{role.label}</h4>
                      {role.is_system ? (
                        <Badge size="sm" variant="secondary">System</Badge>
                      ) : null}
                    </div>
                    <p className="text-sm text-gray-500">{role.description || 'No description provided.'}</p>
                    <div className="mt-3 flex flex-wrap gap-2">
                      {(role.permissions || []).slice(0, 6).map((permission) => (
                        <Badge key={permission} size="sm" variant="primary">{permission}</Badge>
                      ))}
                      {(role.permissions || []).length > 6 ? (
                        <Badge size="sm" variant="secondary">+{role.permissions.length - 6} more</Badge>
                      ) : null}
                    </div>
                  </div>
                  <div className="flex items-center gap-2">
                    <Button variant="outline" size="sm" onClick={() => openEdit(role)}>
                      {role.is_system ? 'View' : 'Edit'}
                    </Button>
                    {!role.is_system ? (
                      <Button variant="danger" size="sm" onClick={() => handleDelete(role)}>
                        Delete
                      </Button>
                    ) : null}
                  </div>
                </div>
              ))}
            </div>
          )}
        </Card>
      )}

      <Modal
        open={showForm}
        title={editingRole ? 'Edit Role' : 'Create Role'}
        size="xl"
        onClose={closeForm}
      >
        <div className="space-y-6">
          {editingRole?.is_system ? (
            <div className="rounded-md bg-yellow-50 border border-yellow-200 p-3 text-sm text-yellow-800">
              System roles are managed by the platform and cannot be edited. You can still review permissions below.
            </div>
          ) : null}

          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <Input
              modelValue={form.name}
              label="Role Key *"
              placeholder="dispatch_lead"
              required
              error={errors.name}
              disabled={!!editingRole}
              onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, name: value }))}
            />
            <Input
              modelValue={form.label}
              label="Role Label *"
              placeholder="Dispatch Lead"
              required
              error={errors.label}
              disabled={editingRole?.is_system}
              onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, label: value }))}
            />
          </div>

          <Input
            modelValue={form.description}
            label="Description"
            placeholder="Describe the scope of this role"
            disabled={editingRole?.is_system}
            onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, description: value }))}
          />

          <div>
            <div className="flex items-center justify-between">
              <h4 className="text-sm font-semibold text-gray-900">Permissions</h4>
              <span className="text-xs text-gray-500">{form.permissions.length} selected</span>
            </div>
            <p className="text-xs text-gray-500 mt-1">
              Permissions are sourced from the system role definitions. Toggle the access needed for this role.
            </p>
            <div className="mt-4 space-y-4">
              {permissionGroups.map((group) => (
                <div key={group.key} className="border border-gray-200 rounded-lg p-4">
                  <h5 className="text-sm font-semibold text-gray-900 mb-3">{formatGroupLabel(group.key)}</h5>
                  <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                    {group.permissions.map((permission) => (
                      <label key={permission} className="flex items-center gap-2 text-sm text-gray-700">
                        <input
                          type="checkbox"
                          className="h-4 w-4 text-indigo-600 rounded"
                          checked={form.permissions.includes(permission)}
                          disabled={editingRole?.is_system}
                          onChange={() => togglePermission(permission)}
                        />
                        <span>{permission}</span>
                      </label>
                    ))}
                  </div>
                </div>
              ))}
            </div>
          </div>
        </div>

        <div className="mt-6 flex justify-end gap-2">
          <Button variant="outline" onClick={closeForm}>Cancel</Button>
          <Button onClick={handleSave} loading={saving} disabled={editingRole?.is_system}>
            {editingRole ? 'Update Role' : 'Create Role'}
          </Button>
        </div>
      </Modal>
    </div>
  )
}
