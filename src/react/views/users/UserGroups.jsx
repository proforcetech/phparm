import { useCallback, useEffect, useState } from 'react'
import { PlusIcon, TrashIcon, UserPlusIcon, XMarkIcon } from '@heroicons/react/24/outline'

import { userGroupService, moduleService } from '../../../services/modules.service'

const availablePermissions = [
  { value: 'customers.*', label: 'Customers - Full Access' },
  { value: 'customers.view', label: 'Customers - View Only' },
  { value: 'vehicles.*', label: 'Vehicles - Full Access' },
  { value: 'estimates.*', label: 'Estimates - Full Access' },
  { value: 'workorders.*', label: 'Work Orders - Full Access' },
  { value: 'invoices.*', label: 'Invoices - Full Access' },
  { value: 'appointments.*', label: 'Appointments - Full Access' },
  { value: 'inventory.*', label: 'Inventory - Full Access' },
  { value: 'inspections.*', label: 'Inspections - Full Access' },
  { value: 'time.*', label: 'Time Tracking - Full Access' },
  { value: 'reports.*', label: 'Reports - Full Access' },
  { value: 'settings.view', label: 'Settings - View Only' },
]

export default function UserGroups() {
  const [groups, setGroups] = useState([])
  const [modules, setModules] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [editingGroup, setEditingGroup] = useState(null)
  const [showForm, setShowForm] = useState(false)
  const [showMemberModal, setShowMemberModal] = useState(null)
  const [nonMembers, setNonMembers] = useState([])
  const [memberSearch, setMemberSearch] = useState('')
  const [saving, setSaving] = useState(false)

  const [formData, setFormData] = useState({
    name: '',
    description: '',
    permissions: [],
    disabled_modules: [],
    is_default: false,
  })

  const fetchData = useCallback(async () => {
    try {
      setLoading(true)
      setError(null)
      const [groupsData, modulesData] = await Promise.all([
        userGroupService.list(),
        moduleService.list(),
      ])
      setGroups(groupsData)
      setModules(modulesData.modules || [])
    } catch (err) {
      setError(err.response?.data?.message || 'Failed to load data')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    fetchData()
  }, [fetchData])

  const handleCreateNew = () => {
    setEditingGroup(null)
    setFormData({
      name: '',
      description: '',
      permissions: [],
      disabled_modules: [],
      is_default: false,
    })
    setShowForm(true)
  }

  const handleEdit = async (group) => {
    try {
      const fullGroup = await userGroupService.get(group.id)
      setEditingGroup(fullGroup)
      setFormData({
        name: fullGroup.name,
        description: fullGroup.description || '',
        permissions: fullGroup.permissions || [],
        disabled_modules: fullGroup.disabled_modules || [],
        is_default: fullGroup.is_default,
      })
      setShowForm(true)
    } catch (err) {
      setError(err.response?.data?.message || 'Failed to load group details')
    }
  }

  const handleSave = async () => {
    try {
      setSaving(true)
      setError(null)

      if (editingGroup) {
        await userGroupService.update(editingGroup.id, formData)
      } else {
        await userGroupService.create(formData)
      }

      setShowForm(false)
      await fetchData()
    } catch (err) {
      setError(err.response?.data?.message || 'Failed to save group')
    } finally {
      setSaving(false)
    }
  }

  const handleDelete = async (group) => {
    if (!window.confirm(`Are you sure you want to delete "${group.name}"?`)) {
      return
    }

    try {
      await userGroupService.delete(group.id)
      await fetchData()
    } catch (err) {
      setError(err.response?.data?.message || 'Failed to delete group')
    }
  }

  const handleManageMembers = async (group) => {
    try {
      const fullGroup = await userGroupService.get(group.id)
      setShowMemberModal(fullGroup)
      const nonMembersList = await userGroupService.getNonMembers(group.id)
      setNonMembers(nonMembersList)
    } catch (err) {
      setError(err.response?.data?.message || 'Failed to load members')
    }
  }

  const handleAddMember = async (userId) => {
    try {
      await userGroupService.addMember(showMemberModal.id, userId)
      const fullGroup = await userGroupService.get(showMemberModal.id)
      setShowMemberModal(fullGroup)
      const nonMembersList = await userGroupService.getNonMembers(showMemberModal.id)
      setNonMembers(nonMembersList)
      await fetchData()
    } catch (err) {
      setError(err.response?.data?.message || 'Failed to add member')
    }
  }

  const handleRemoveMember = async (userId) => {
    try {
      await userGroupService.removeMember(showMemberModal.id, userId)
      const fullGroup = await userGroupService.get(showMemberModal.id)
      setShowMemberModal(fullGroup)
      const nonMembersList = await userGroupService.getNonMembers(showMemberModal.id)
      setNonMembers(nonMembersList)
      await fetchData()
    } catch (err) {
      setError(err.response?.data?.message || 'Failed to remove member')
    }
  }

  const searchNonMembers = async (search) => {
    setMemberSearch(search)
    try {
      const nonMembersList = await userGroupService.getNonMembers(showMemberModal.id, search)
      setNonMembers(nonMembersList)
    } catch (err) {
      console.error('Failed to search users:', err)
    }
  }

  const togglePermission = (permission) => {
    setFormData((prev) => ({
      ...prev,
      permissions: prev.permissions.includes(permission)
        ? prev.permissions.filter((p) => p !== permission)
        : [...prev.permissions, permission],
    }))
  }

  const toggleDisabledModule = (moduleKey) => {
    setFormData((prev) => ({
      ...prev,
      disabled_modules: prev.disabled_modules.includes(moduleKey)
        ? prev.disabled_modules.filter((m) => m !== moduleKey)
        : [...prev.disabled_modules, moduleKey],
    }))
  }

  if (loading) {
    return (
      <div className="p-6">
        <div className="animate-pulse space-y-4">
          <div className="h-8 bg-gray-200 rounded w-1/4"></div>
          <div className="h-4 bg-gray-200 rounded w-1/2"></div>
          <div className="space-y-3">
            {[1, 2, 3].map((i) => (
              <div key={i} className="h-20 bg-gray-200 rounded"></div>
            ))}
          </div>
        </div>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <header className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold text-gray-900">User Groups</h1>
          <p className="text-sm text-gray-500">
            Manage user groups to control access to modules and grant additional permissions.
          </p>
        </div>
        <button
          type="button"
          onClick={handleCreateNew}
          className="inline-flex items-center px-4 py-2 bg-primary-600 text-white rounded-lg hover:bg-primary-700"
        >
          <PlusIcon className="w-5 h-5 mr-2" />
          New Group
        </button>
      </header>

      {error && (
        <div className="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded">
          {error}
          <button onClick={() => setError(null)} className="float-right">
            <XMarkIcon className="w-5 h-5" />
          </button>
        </div>
      )}

      <div className="bg-white shadow rounded-lg overflow-hidden">
        <table className="min-w-full divide-y divide-gray-200">
          <thead className="bg-gray-50">
            <tr>
              <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                Name
              </th>
              <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                Members
              </th>
              <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                Disabled Modules
              </th>
              <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                Default
              </th>
              <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                Actions
              </th>
            </tr>
          </thead>
          <tbody className="bg-white divide-y divide-gray-200">
            {groups.map((group) => (
              <tr key={group.id} className="hover:bg-gray-50">
                <td className="px-6 py-4 whitespace-nowrap">
                  <div className="font-medium text-gray-900">{group.name}</div>
                  {group.description && (
                    <div className="text-sm text-gray-500">{group.description}</div>
                  )}
                </td>
                <td className="px-6 py-4 whitespace-nowrap">
                  <button
                    type="button"
                    onClick={() => handleManageMembers(group)}
                    className="inline-flex items-center text-sm text-primary-600 hover:text-primary-800"
                  >
                    <UserPlusIcon className="w-4 h-4 mr-1" />
                    {group.member_count} members
                  </button>
                </td>
                <td className="px-6 py-4">
                  {group.disabled_modules?.length > 0 ? (
                    <div className="flex flex-wrap gap-1">
                      {group.disabled_modules.map((mod) => (
                        <span
                          key={mod}
                          className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-800"
                        >
                          {mod}
                        </span>
                      ))}
                    </div>
                  ) : (
                    <span className="text-gray-400 text-sm">None</span>
                  )}
                </td>
                <td className="px-6 py-4 whitespace-nowrap">
                  {group.is_default ? (
                    <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800">
                      Default
                    </span>
                  ) : (
                    <span className="text-gray-400">-</span>
                  )}
                </td>
                <td className="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                  <button
                    type="button"
                    onClick={() => handleEdit(group)}
                    className="text-primary-600 hover:text-primary-900 mr-3"
                  >
                    Edit
                  </button>
                  <button
                    type="button"
                    onClick={() => handleDelete(group)}
                    className="text-red-600 hover:text-red-900"
                  >
                    <TrashIcon className="w-4 h-4 inline" />
                  </button>
                </td>
              </tr>
            ))}
            {groups.length === 0 && (
              <tr>
                <td colSpan={5} className="px-6 py-12 text-center text-gray-500">
                  No user groups yet. Create one to get started.
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>

      {/* Create/Edit Form Modal */}
      {showForm && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 overflow-y-auto">
          <div className="bg-white rounded-lg p-6 max-w-2xl w-full mx-4 my-8 max-h-[90vh] overflow-y-auto">
            <div className="flex items-center justify-between mb-4">
              <h3 className="text-lg font-medium text-gray-900">
                {editingGroup ? 'Edit User Group' : 'New User Group'}
              </h3>
              <button type="button" onClick={() => setShowForm(false)}>
                <XMarkIcon className="w-5 h-5 text-gray-400" />
              </button>
            </div>

            <div className="space-y-4">
              <div>
                <label className="block text-sm font-medium text-gray-700">Name</label>
                <input
                  type="text"
                  value={formData.name}
                  onChange={(e) => setFormData((prev) => ({ ...prev, name: e.target.value }))}
                  className="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500"
                />
              </div>

              <div>
                <label className="block text-sm font-medium text-gray-700">Description</label>
                <textarea
                  value={formData.description}
                  onChange={(e) => setFormData((prev) => ({ ...prev, description: e.target.value }))}
                  rows={2}
                  className="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500"
                />
              </div>

              <div className="flex items-center">
                <input
                  type="checkbox"
                  id="is_default"
                  checked={formData.is_default}
                  onChange={(e) => setFormData((prev) => ({ ...prev, is_default: e.target.checked }))}
                  className="h-4 w-4 text-primary-600 focus:ring-primary-500 border-gray-300 rounded"
                />
                <label htmlFor="is_default" className="ml-2 block text-sm text-gray-700">
                  Auto-assign new staff to this group
                </label>
              </div>

              <div>
                <label className="block text-sm font-medium text-gray-700 mb-2">
                  Disabled Modules (users in this group cannot access these)
                </label>
                <div className="grid grid-cols-2 md:grid-cols-3 gap-2">
                  {modules
                    .filter((m) => m.can_disable)
                    .map((module) => (
                      <label
                        key={module.key}
                        className="flex items-center space-x-2 text-sm cursor-pointer"
                      >
                        <input
                          type="checkbox"
                          checked={formData.disabled_modules.includes(module.key)}
                          onChange={() => toggleDisabledModule(module.key)}
                          className="h-4 w-4 text-red-600 focus:ring-red-500 border-gray-300 rounded"
                        />
                        <span className={formData.disabled_modules.includes(module.key) ? 'text-red-600' : ''}>
                          {module.label}
                        </span>
                      </label>
                    ))}
                </div>
              </div>

              <div>
                <label className="block text-sm font-medium text-gray-700 mb-2">
                  Additional Permissions (granted to all group members)
                </label>
                <div className="grid grid-cols-2 gap-2">
                  {availablePermissions.map((perm) => (
                    <label
                      key={perm.value}
                      className="flex items-center space-x-2 text-sm cursor-pointer"
                    >
                      <input
                        type="checkbox"
                        checked={formData.permissions.includes(perm.value)}
                        onChange={() => togglePermission(perm.value)}
                        className="h-4 w-4 text-primary-600 focus:ring-primary-500 border-gray-300 rounded"
                      />
                      <span>{perm.label}</span>
                    </label>
                  ))}
                </div>
              </div>
            </div>

            <div className="flex justify-end space-x-3 mt-6">
              <button
                type="button"
                onClick={() => setShowForm(false)}
                className="px-4 py-2 text-sm text-gray-700 bg-gray-100 rounded hover:bg-gray-200"
              >
                Cancel
              </button>
              <button
                type="button"
                onClick={handleSave}
                disabled={saving || !formData.name.trim()}
                className="px-4 py-2 text-sm text-white bg-primary-600 rounded hover:bg-primary-700 disabled:opacity-50"
              >
                {saving ? 'Saving...' : 'Save'}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Member Management Modal */}
      {showMemberModal && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 overflow-y-auto">
          <div className="bg-white rounded-lg p-6 max-w-2xl w-full mx-4 my-8 max-h-[90vh] overflow-y-auto">
            <div className="flex items-center justify-between mb-4">
              <h3 className="text-lg font-medium text-gray-900">
                Manage Members: {showMemberModal.name}
              </h3>
              <button type="button" onClick={() => setShowMemberModal(null)}>
                <XMarkIcon className="w-5 h-5 text-gray-400" />
              </button>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
              {/* Current Members */}
              <div>
                <h4 className="text-sm font-medium text-gray-700 mb-2">
                  Current Members ({showMemberModal.members?.length || 0})
                </h4>
                <div className="border rounded-lg max-h-64 overflow-y-auto">
                  {showMemberModal.members?.length > 0 ? (
                    <ul className="divide-y divide-gray-200">
                      {showMemberModal.members.map((member) => (
                        <li
                          key={member.id}
                          className="flex items-center justify-between px-3 py-2 hover:bg-gray-50"
                        >
                          <div>
                            <div className="text-sm font-medium">{member.name}</div>
                            <div className="text-xs text-gray-500">{member.email}</div>
                          </div>
                          <button
                            type="button"
                            onClick={() => handleRemoveMember(member.id)}
                            className="text-red-600 hover:text-red-800"
                          >
                            <XMarkIcon className="w-4 h-4" />
                          </button>
                        </li>
                      ))}
                    </ul>
                  ) : (
                    <p className="px-3 py-4 text-sm text-gray-500 text-center">No members</p>
                  )}
                </div>
              </div>

              {/* Add Members */}
              <div>
                <h4 className="text-sm font-medium text-gray-700 mb-2">Add Members</h4>
                <input
                  type="text"
                  placeholder="Search users..."
                  value={memberSearch}
                  onChange={(e) => searchNonMembers(e.target.value)}
                  className="w-full mb-2 border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 text-sm"
                />
                <div className="border rounded-lg max-h-52 overflow-y-auto">
                  {nonMembers.length > 0 ? (
                    <ul className="divide-y divide-gray-200">
                      {nonMembers.map((user) => (
                        <li
                          key={user.id}
                          className="flex items-center justify-between px-3 py-2 hover:bg-gray-50"
                        >
                          <div>
                            <div className="text-sm font-medium">{user.name}</div>
                            <div className="text-xs text-gray-500">
                              {user.email} ({user.role})
                            </div>
                          </div>
                          <button
                            type="button"
                            onClick={() => handleAddMember(user.id)}
                            className="text-primary-600 hover:text-primary-800"
                          >
                            <PlusIcon className="w-4 h-4" />
                          </button>
                        </li>
                      ))}
                    </ul>
                  ) : (
                    <p className="px-3 py-4 text-sm text-gray-500 text-center">
                      {memberSearch ? 'No matching users' : 'All users are members'}
                    </p>
                  )}
                </div>
              </div>
            </div>

            <div className="flex justify-end mt-6">
              <button
                type="button"
                onClick={() => setShowMemberModal(null)}
                className="px-4 py-2 text-sm text-white bg-primary-600 rounded hover:bg-primary-700"
              >
                Done
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
