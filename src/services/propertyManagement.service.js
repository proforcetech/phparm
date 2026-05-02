import api from './api'

/**
 * Property Management vertical — Phase 12 of docs/woms-expansion-plan.md.
 * Backed by UnitController, TenantController, TenantLeaseController on PHP.
 *
 * Backend wraps every response in `{ data: <payload> }` to match the
 * divisions / service_lines convention; we unwrap once here so callers see
 * the controller payload directly.
 */

const unwrap = (response) => response.data?.data ?? response.data ?? {}

export const unitService = {
  async list(params = {}) {
    const response = await api.get('/units', { params })
    const payload = unwrap(response)
    return {
      units: payload.units ?? [],
      total: payload.total ?? 0,
      limit: payload.limit ?? 0,
      offset: payload.offset ?? 0,
    }
  },

  async get(id) {
    const response = await api.get(`/units/${id}`)
    return unwrap(response)
  },

  async create(payload) {
    const response = await api.post('/units', payload)
    return unwrap(response)
  },

  async update(id, payload) {
    const response = await api.put(`/units/${id}`, payload)
    return unwrap(response)
  },

  async delete(id) {
    const response = await api.delete(`/units/${id}`)
    return unwrap(response)
  },
}

export const tenantService = {
  async list(params = {}) {
    const response = await api.get('/tenants', { params })
    const payload = unwrap(response)
    return {
      tenants: payload.tenants ?? [],
      total: payload.total ?? 0,
      limit: payload.limit ?? 0,
      offset: payload.offset ?? 0,
    }
  },

  async get(id) {
    const response = await api.get(`/tenants/${id}`)
    return unwrap(response)
  },

  async create(payload) {
    const response = await api.post('/tenants', payload)
    return unwrap(response)
  },

  async update(id, payload) {
    const response = await api.put(`/tenants/${id}`, payload)
    return unwrap(response)
  },
}

export const tenantLeaseService = {
  async list(params = {}) {
    const response = await api.get('/tenant-leases', { params })
    const payload = unwrap(response)
    return {
      leases: payload.leases ?? [],
      total: payload.total ?? 0,
      limit: payload.limit ?? 0,
      offset: payload.offset ?? 0,
    }
  },

  async get(id) {
    const response = await api.get(`/tenant-leases/${id}`)
    return unwrap(response)
  },

  async create(payload) {
    const response = await api.post('/tenant-leases', payload)
    return unwrap(response)
  },

  async update(id, payload) {
    const response = await api.put(`/tenant-leases/${id}`, payload)
    return unwrap(response)
  },
}

/**
 * Staff-side maintenance request queue (gated server-side on
 * property.units.view / property.units.manage).
 */
export const maintenanceRequestService = {
  async list(params = {}) {
    const response = await api.get('/maintenance-requests', { params })
    const payload = unwrap(response)
    return {
      requests: payload.requests ?? [],
      total: payload.total ?? 0,
    }
  },

  async triage(id) {
    const response = await api.post(`/maintenance-requests/${id}/triage`)
    return unwrap(response)
  },

  async decline(id, reason) {
    const response = await api.post(`/maintenance-requests/${id}/decline`, { reason })
    return unwrap(response)
  },

  async convertToWorkorder(id, body = {}) {
    const response = await api.post(`/maintenance-requests/${id}/convert-to-workorder`, body)
    return unwrap(response)
  },
}

/**
 * Tenant-portal API. Authenticated via the standard JWT but resolves the
 * caller's tenant identity server-side via Tenant.portal_user_id, so no
 * tenant ID needs to be passed by the client.
 */
export const tenantPortalService = {
  async me() {
    const response = await api.get('/tenant/me')
    return unwrap(response)
  },

  async listRequests(params = {}) {
    const response = await api.get('/tenant/maintenance-requests', { params })
    const payload = unwrap(response)
    return {
      requests: payload.requests ?? [],
      total: payload.total ?? 0,
    }
  },

  async createRequest(payload) {
    const response = await api.post('/tenant/maintenance-requests', payload)
    return unwrap(response)
  },

  async cancelRequest(id) {
    const response = await api.post(`/tenant/maintenance-requests/${id}/cancel`)
    return unwrap(response)
  },
}

export default {
  units: unitService,
  tenants: tenantService,
  leases: tenantLeaseService,
  maintenanceRequests: maintenanceRequestService,
  tenantPortal: tenantPortalService,
}
