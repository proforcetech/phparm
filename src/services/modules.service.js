import api from './api'

/**
 * Module Settings Service
 * Admin endpoints for managing application modules
 */
export const moduleService = {
  /**
   * Get all modules with their current status
   */
  async list() {
    const response = await api.get('/modules')
    return response.data
  },

  /**
   * Get a single module's status and configuration
   */
  async get(moduleKey) {
    const response = await api.get(`/modules/${moduleKey}`)
    return response.data
  },

  /**
   * Enable or disable a module
   */
  async update(moduleKey, enabled, force = false) {
    const response = await api.put(`/modules/${moduleKey}`, { enabled, force })
    return response.data
  },

  /**
   * Bulk update multiple modules
   */
  async bulkUpdate(modules) {
    const response = await api.put('/modules', modules)
    return response.data
  },

  /**
   * Get accessible modules for current user
   */
  async getAccessible() {
    const response = await api.get('/modules/accessible')
    return response.data
  },
}

/**
 * User Groups Service
 * Admin endpoints for managing user groups
 */
export const userGroupService = {
  /**
   * List all user groups
   */
  async list() {
    const response = await api.get('/user-groups')
    return response.data.data
  },

  /**
   * Get a single user group with members
   */
  async get(id) {
    const response = await api.get(`/user-groups/${id}`)
    return response.data
  },

  /**
   * Create a new user group
   */
  async create(data) {
    const response = await api.post('/user-groups', data)
    return response.data
  },

  /**
   * Update a user group
   */
  async update(id, data) {
    const response = await api.put(`/user-groups/${id}`, data)
    return response.data
  },

  /**
   * Delete a user group
   */
  async delete(id) {
    const response = await api.delete(`/user-groups/${id}`)
    return response.data
  },

  /**
   * Get members of a group
   */
  async getMembers(groupId) {
    const response = await api.get(`/user-groups/${groupId}/members`)
    return response.data.data
  },

  /**
   * Add a member to a group
   */
  async addMember(groupId, userId) {
    const response = await api.post(`/user-groups/${groupId}/members`, { user_id: userId })
    return response.data
  },

  /**
   * Remove a member from a group
   */
  async removeMember(groupId, userId) {
    const response = await api.delete(`/user-groups/${groupId}/members/${userId}`)
    return response.data
  },

  /**
   * Set all members of a group
   */
  async setMembers(groupId, userIds) {
    const response = await api.put(`/user-groups/${groupId}/members`, { user_ids: userIds })
    return response.data
  },

  /**
   * Get users not in a group
   */
  async getNonMembers(groupId, search = '') {
    const response = await api.get(`/user-groups/${groupId}/non-members`, { params: { search } })
    return response.data.data
  },

  /**
   * Get groups for a specific user
   */
  async getUserGroups(userId) {
    const response = await api.get(`/users/${userId}/groups`)
    return response.data.data
  },

  /**
   * Set groups for a specific user
   */
  async setUserGroups(userId, groupIds) {
    const response = await api.put(`/users/${userId}/groups`, { group_ids: groupIds })
    return response.data
  },
}

export default { moduleService, userGroupService }
