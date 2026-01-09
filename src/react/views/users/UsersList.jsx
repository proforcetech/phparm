import { useEffect, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useEffect, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'

import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Modal from '../../components/ui/Modal'
import Select from '../../components/ui/Select'
import userService from '../../../services/user.service'
import { useAuthStore } from '../../stores/auth.jsx'
import { useToast } from '../../stores/toast.jsx'

const roleOptions = [
  { label: 'All Roles', value: '' },
  { label: 'Admin', value: 'admin' },
  { label: 'Dispatcher', value: 'dispatcher' },
  { label: 'Manager', value: 'manager' },
  { label: 'Technician', value: 'technician' },
  { label: 'Parts', value: 'parts' },
  { label: 'Roadside', value: 'roadside' },
  { label: 'CMS', value: 'cms' },
  { label: 'Customer', value: 'customer' },
]

const bulkRoleOptions = roleOptions.filter((option) => option.value !== '')
const statusOptions = [
  { label: 'All Statuses', value: 'all' },
  { label: 'Active', value: 'active' },
  { label: 'Inactive', value: 'inactive' },
]

const twoFactorOptions = [
  { label: 'All 2FA', value: '' },
  { label: '2FA Enabled', value: 'enabled' },
  { label: '2FA Disabled', value: 'disabled' },
]

const roleLabels = {
  admin: 'Admin',
  dispatcher: 'Dispatcher',
  manager: 'Manager',
  technician: 'Technician',
  parts: 'Parts',
  roadside: 'Roadside',
  cms: 'CMS',
  customer: 'Customer',
}

const roleVariants = {
  admin: 'danger',
  dispatcher: 'info',
  manager: 'warning',
  technician: 'primary',
  parts: 'secondary',
  roadside: 'warning',
  cms: 'primary',
  customer: 'secondary',
}

const formatDate = (dateString) => {
  if (!dateString) return '—'
  const date = new Date(dateString)
  return date.toLocaleDateString('en-US', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
  })
}

const formatDateTime = (dateString) => {
  if (!dateString) return '—'
  const date = new Date(dateString)
  return date.toLocaleString('en-US', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
  })
}

export default function UsersList() {
  const navigate = useNavigate()
  const [searchParams, setSearchParams] = useSearchParams()
  const toast = useToast()
  const { isAdmin, hasPermission } = useAuthStore()
  const canViewAudit = isAdmin && hasPermission('audit.view')

  const [users, setUsers] = useState([])
  const [loading, setLoading] = useState(true)
  const [deleting, setDeleting] = useState(false)
  const [resetting2FA, setResetting2FA] = useState(false)
  const [exporting, setExporting] = useState(false)
  const [showDeleteModal, setShowDeleteModal] = useState(false)
  const [showReset2FAModal, setShowReset2FAModal] = useState(false)
  const [showBulkDeactivateModal, setShowBulkDeactivateModal] = useState(false)
  const [showBulkRoleModal, setShowBulkRoleModal] = useState(false)
  const [userToDelete, setUserToDelete] = useState(null)
  const [userToReset2FA, setUserToReset2FA] = useState(null)
  const [filters, setFilters] = useState({ query: '', role: '' })
  const [selectedUserIds, setSelectedUserIds] = useState([])
  const [bulkRole, setBulkRole] = useState('')
  const [bulkDeactivating, setBulkDeactivating] = useState(false)
  const [bulkUpdatingRole, setBulkUpdatingRole] = useState(false)
  const selectAllRef = useRef(null)
  const [filters, setFilters] = useState({
    query: '',
    role: '',
    status: 'active',
    two_factor: '',
  })

  const loadUsers = async (nextFilters = filters) => {
    setLoading(true)
    try {
      const data = await userService.listUsers(nextFilters)
      setUsers(data)
      setSelectedUserIds([])
    } catch (error) {
      console.error('Failed to load users:', error)
      toast.error('Failed to load users')
    } finally {
      setLoading(false)
    }
  }

  const buildSearchParams = (nextFilters) => {
    const params = new URLSearchParams()
    Object.entries(nextFilters).forEach(([key, value]) => {
      if (value !== '' && value !== null && value !== undefined) {
        params.set(key, value)
      }
    })
    return params
  }

  const updateSearchParams = (nextFilters) => {
    setSearchParams(buildSearchParams(nextFilters))
  }

  useEffect(() => {
    const nextFilters = {
      query: searchParams.get('query') ?? '',
      role: searchParams.get('role') ?? '',
      status: searchParams.get('status') ?? 'active',
      two_factor: searchParams.get('two_factor') ?? '',
    }
    const normalizedParams = buildSearchParams(nextFilters)

    if (normalizedParams.toString() !== searchParams.toString()) {
      setSearchParams(normalizedParams, { replace: true })
      return
    }

    setFilters(nextFilters)
    loadUsers(nextFilters)
  }, [searchParams, setSearchParams])

  useEffect(() => {
    if (!selectAllRef.current) return
    const total = users.length
    const selected = selectedUserIds.length
    selectAllRef.current.indeterminate = selected > 0 && selected < total
  }, [selectedUserIds, users.length])

  const toggleSelectAll = (checked) => {
    if (checked) {
      setSelectedUserIds(users.map((user) => user.id))
    } else {
      setSelectedUserIds([])
    }
  }

  const toggleSelectUser = (id, checked) => {
    setSelectedUserIds((prev) => {
      if (checked) {
        return prev.includes(id) ? prev : [...prev, id]
      }
      return prev.filter((selectedId) => selectedId !== id)
    })
  }

  const clearSelection = () => {
    setSelectedUserIds([])
    setBulkRole('')
  }

  const handleExport = async () => {
    setExporting(true)
    try {
      const response = await userService.exportUsers(filters)
      const blob = new Blob([response.data], { type: 'text/csv' })
      if (blob.size === 0) {
        toast.error('No users found to export')
        return
      }
      const url = window.URL.createObjectURL(blob)
      const link = document.createElement('a')
      link.href = url
      link.download = `users_export_${new Date().toISOString().slice(0, 10)}.csv`
      document.body.appendChild(link)
      link.click()
      link.remove()
      window.URL.revokeObjectURL(url)
    } catch (error) {
      console.error('Failed to export users:', error)
      toast.error('Failed to export users')
    } finally {
      setExporting(false)
    }
  }

  const confirmDelete = (user) => {
    setUserToDelete(user)
    setShowDeleteModal(true)
  }

  const handleDelete = async () => {
    if (!userToDelete) return

    setDeleting(true)
    try {
      await userService.deleteUser(userToDelete.id)
      toast.success('User deleted successfully')
      setShowDeleteModal(false)
      setUserToDelete(null)
      loadUsers()
    } catch (error) {
      console.error('Failed to delete user:', error)
      toast.error(error.response?.data?.message || 'Failed to delete user')
    } finally {
      setDeleting(false)
    }
  }

  const confirmReset2FA = (user) => {
    setUserToReset2FA(user)
    setShowReset2FAModal(true)
  }

  const handleReset2FA = async () => {
    if (!userToReset2FA) return

    setResetting2FA(true)
    try {
      await userService.reset2FA(userToReset2FA.id)
      toast.success('2FA reset successfully')
      setShowReset2FAModal(false)
      setUserToReset2FA(null)
      loadUsers()
    } catch (error) {
      console.error('Failed to reset 2FA:', error)
      toast.error(error.response?.data?.message || 'Failed to reset 2FA')
    } finally {
      setResetting2FA(false)
    }
  }

  const confirmBulkDeactivate = () => {
    if (selectedUserIds.length === 0) return
    setShowBulkDeactivateModal(true)
  }

  const handleBulkDeactivate = async () => {
    if (selectedUserIds.length === 0) return

    setBulkDeactivating(true)
    try {
      const result = await userService.bulkDeactivateUsers(selectedUserIds)
      toast.success(`${result.deactivated} users deactivated`)
      setShowBulkDeactivateModal(false)
      clearSelection()
      loadUsers()
    } catch (error) {
      console.error('Failed to deactivate users:', error)
      toast.error(error.response?.data?.message || 'Failed to deactivate users')
    } finally {
      setBulkDeactivating(false)
    }
  }

  const confirmBulkRoleUpdate = () => {
    if (selectedUserIds.length === 0 || !bulkRole) return
    setShowBulkRoleModal(true)
  }

  const handleBulkRoleUpdate = async () => {
    if (selectedUserIds.length === 0 || !bulkRole) return

    setBulkUpdatingRole(true)
    try {
      const result = await userService.bulkUpdateRole(selectedUserIds, bulkRole)
      toast.success(`${result.updated} users updated to ${roleLabels[bulkRole] || bulkRole}`)
      setShowBulkRoleModal(false)
      clearSelection()
      loadUsers()
    } catch (error) {
      console.error('Failed to update roles:', error)
      toast.error(error.response?.data?.message || 'Failed to update roles')
    } finally {
      setBulkUpdatingRole(false)
    }
  }

  const selectedCount = selectedUserIds.length
  const allSelected = users.length > 0 && selectedCount === users.length

  return (
    <div>
      <div className="mb-6">
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-2xl font-bold text-gray-900">User Management</h1>
            <p className="mt-1 text-sm text-gray-500">Manage system users, roles, and permissions</p>
          </div>
          <div className="flex items-center gap-2">
            <Button variant="outline" onClick={handleExport} loading={exporting}>
              <svg className="h-4 w-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 16V4m0 12l-4-4m4 4l4-4M4 20h16" />
              </svg>
              Export CSV
            </Button>
            <Button onClick={() => navigate('/cp/users/create')}>
              <svg className="h-4 w-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 4v16m8-8H4" />
              </svg>
              Create User
            </Button>
          </div>
        </div>
      </div>

      <Card className="mb-6">
        <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Search</label>
            <Input
              modelValue={filters.query}
              placeholder="Search by name, email, or ID..."
              onUpdateModelValue={(value) => {
                const nextFilters = { ...filters, query: value }
                updateSearchParams(nextFilters)
              }}
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Role</label>
            <Select
              modelValue={filters.role}
              options={roleOptions}
              onUpdateModelValue={(value) => {
                const nextFilters = { ...filters, role: value }
                updateSearchParams(nextFilters)
              }}
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Status</label>
            <Select
              modelValue={filters.status}
              options={statusOptions}
              onUpdateModelValue={(value) => {
                const nextFilters = { ...filters, status: value }
                updateSearchParams(nextFilters)
              }}
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">2FA</label>
            <Select
              modelValue={filters.two_factor}
              options={twoFactorOptions}
              onUpdateModelValue={(value) => {
                const nextFilters = { ...filters, two_factor: value }
                updateSearchParams(nextFilters)
              }}
            />
          </div>
        </div>
      </Card>

      {loading ? (
        <div className="flex justify-center py-12">
          <Loading size="xl" text="Loading users..." />
        </div>
      ) : (
        <Card>
          <div className="flex flex-wrap items-center justify-between gap-4">
            <h3 className="text-lg font-medium text-gray-900">Users ({users.length})</h3>
            {selectedCount > 0 ? (
              <div className="flex flex-wrap items-center gap-2">
                <span className="text-sm text-gray-500">{selectedCount} selected</span>
                <div className="min-w-[180px]">
                  <Select
                    modelValue={bulkRole}
                    options={bulkRoleOptions}
                    onUpdateModelValue={setBulkRole}
                  />
                </div>
                <Button size="sm" onClick={confirmBulkRoleUpdate} disabled={!bulkRole}>
                  Update Role
                </Button>
                <Button variant="danger" size="sm" onClick={confirmBulkDeactivate}>
                  Deactivate
                </Button>
                <Button variant="ghost" size="sm" onClick={clearSelection}>
                  Clear
                </Button>
              </div>
            ) : null}
          </div>

          {users.length === 0 ? (
            <div className="text-center py-12 text-gray-500">No users found</div>
          ) : (
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-gray-200">
                <thead className="bg-gray-50">
                  <tr>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      <input
                        ref={selectAllRef}
                        type="checkbox"
                        className="h-4 w-4 rounded border-gray-300 text-primary-600 focus:ring-primary-500"
                        checked={allSelected}
                        onChange={(event) => toggleSelectAll(event.target.checked)}
                        aria-label="Select all users"
                      />
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">User</th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Role</th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Created</th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Last Active</th>
                    <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                  </tr>
                </thead>
                <tbody className="bg-white divide-y divide-gray-200">
                  {users.map((user) => (
                    <tr key={user.id} className="hover:bg-gray-50">
                      <td className="px-6 py-4 whitespace-nowrap">
                        <input
                          type="checkbox"
                          className="h-4 w-4 rounded border-gray-300 text-primary-600 focus:ring-primary-500"
                          checked={selectedUserIds.includes(user.id)}
                          onChange={(event) => toggleSelectUser(user.id, event.target.checked)}
                          aria-label={`Select ${user.name}`}
                        />
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <div className="flex items-center">
                          <div className="h-10 w-10 rounded-full bg-indigo-100 flex items-center justify-center">
                            <svg className="h-5 w-5 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                            </svg>
                          </div>
                          <div className="ml-4">
                            <div className="text-sm font-medium text-gray-900">{user.name}</div>
                            <div className="text-sm text-gray-500">{user.email}</div>
                          </div>
                        </div>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <Badge variant={roleVariants[user.role] || 'secondary'}>
                          {roleLabels[user.role] || user.role}
                        </Badge>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <div className="flex flex-col gap-1">
                          {user.email_verified ? (
                            <Badge variant="success" size="sm">Email Verified</Badge>
                          ) : (
                            <Badge variant="secondary" size="sm">Email Not Verified</Badge>
                          )}
                          {user.two_factor_enabled ? (
                            <Badge variant="primary" size="sm">2FA Enabled</Badge>
                          ) : null}
                        </div>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                        {formatDate(user.created_at)}
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                        {formatDateTime(user.last_activity_at)}
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                        <div className="flex items-center justify-end gap-2">
                          <Button variant="ghost" size="sm" onClick={() => navigate(`/cp/users/${user.id}`)}>
                            <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                            </svg>
                          </Button>
                          {canViewAudit ? (
                            <Button
                              variant="ghost"
                              size="sm"
                              onClick={() => navigate(`/cp/audit?actor_id=${user.id}`)}
                              title="View audit log"
                            >
                              <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 12h6m-6 4h6m-7 4h8a2 2 0 002-2V6a2 2 0 00-2-2h-5l-2-2H6a2 2 0 00-2 2v14a2 2 0 002 2z" />
                              </svg>
                            </Button>
                          ) : null}
                          {user.two_factor_enabled ? (
                            <Button variant="ghost" size="sm" onClick={() => confirmReset2FA(user)}>
                              <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                              </svg>
                            </Button>
                          ) : null}
                          <Button variant="ghost" size="sm" onClick={() => confirmDelete(user)}>
                            <svg className="h-4 w-4 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                            </svg>
                          </Button>
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Card>
      )}

      <Modal
        open={showDeleteModal}
        title="Confirm Delete"
        onClose={() => setShowDeleteModal(false)}
        footer={(
          <div className="flex justify-end gap-2">
            <Button variant="outline" onClick={() => setShowDeleteModal(false)}>Cancel</Button>
            <Button variant="danger" onClick={handleDelete} loading={deleting}>Delete User</Button>
          </div>
        )}
      >
        <p className="text-sm text-gray-600">
          Are you sure you want to delete <strong>{userToDelete?.name}</strong>? This action cannot be undone.
        </p>
      </Modal>

      <Modal
        open={showReset2FAModal}
        title="Reset Two-Factor Authentication"
        onClose={() => setShowReset2FAModal(false)}
        footer={(
          <div className="flex justify-end gap-2">
            <Button variant="outline" onClick={() => setShowReset2FAModal(false)}>Cancel</Button>
            <Button onClick={handleReset2FA} loading={resetting2FA}>Reset 2FA</Button>
          </div>
        )}
      >
        <p className="text-sm text-gray-600">
          Are you sure you want to reset 2FA for <strong>{userToReset2FA?.name}</strong>? They will need to set it up again.
        </p>
      </Modal>

      <Modal
        open={showBulkDeactivateModal}
        title="Deactivate Selected Users"
        onClose={() => setShowBulkDeactivateModal(false)}
        footer={(
          <div className="flex justify-end gap-2">
            <Button variant="outline" onClick={() => setShowBulkDeactivateModal(false)}>Cancel</Button>
            <Button variant="danger" onClick={handleBulkDeactivate} loading={bulkDeactivating}>
              Deactivate {selectedCount} Users
            </Button>
          </div>
        )}
      >
        <p className="text-sm text-gray-600">
          You are about to deactivate <strong>{selectedCount}</strong> users. They will no longer be able to log in.
        </p>
      </Modal>

      <Modal
        open={showBulkRoleModal}
        title="Update Roles for Selected Users"
        onClose={() => setShowBulkRoleModal(false)}
        footer={(
          <div className="flex justify-end gap-2">
            <Button variant="outline" onClick={() => setShowBulkRoleModal(false)}>Cancel</Button>
            <Button onClick={handleBulkRoleUpdate} loading={bulkUpdatingRole}>
              Update Roles
            </Button>
          </div>
        )}
      >
        <p className="text-sm text-gray-600">
          Update <strong>{selectedCount}</strong> users to <strong>{roleLabels[bulkRole] || bulkRole}</strong> role?
        </p>
      </Modal>
    </div>
  )
}
