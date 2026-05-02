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

export default {
  units: unitService,
  tenants: tenantService,
  leases: tenantLeaseService,
}
