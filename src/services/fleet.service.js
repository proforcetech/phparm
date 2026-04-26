import api from './api'

/**
 * Fleet — units (assets/vehicles/equipment), readings (odometer/hours),
 * assignments, downtime, external repairs, PM bindings, and cost reports.
 */
export default {
  // Units
  listUnits(params = {}) {
    return api.get('/fleet/units', { params }).then((res) => res.data)
  },
  getUnit(id) {
    return api.get(`/fleet/units/${id}`).then((res) => res.data)
  },
  createUnit(payload) {
    return api.post('/fleet/units', payload).then((res) => res.data)
  },
  updateUnit(id, payload) {
    return api.put(`/fleet/units/${id}`, payload).then((res) => res.data)
  },
  deleteUnit(id) {
    return api.delete(`/fleet/units/${id}`).then((res) => res.data)
  },

  // Readings
  listReadings(unitId, params = {}) {
    return api.get(`/fleet/units/${unitId}/readings`, { params }).then((res) => res.data)
  },
  createReading(unitId, payload) {
    return api.post(`/fleet/units/${unitId}/readings`, payload).then((res) => res.data)
  },

  // Assignments (driver/operator/responsible party)
  listAssignments(unitId, params = {}) {
    return api.get(`/fleet/units/${unitId}/assignments`, { params }).then((res) => res.data)
  },
  startAssignment(unitId, payload) {
    return api.post(`/fleet/units/${unitId}/assignments`, payload).then((res) => res.data)
  },
  endAssignment(unitId, payload = {}) {
    return api.post(`/fleet/units/${unitId}/assignments/end`, payload).then((res) => res.data)
  },

  // Downtime (out-of-service tracking)
  listDowntime(unitId, params = {}) {
    return api.get(`/fleet/units/${unitId}/downtime`, { params }).then((res) => res.data)
  },
  currentDowntime(unitId) {
    return api.get(`/fleet/units/${unitId}/downtime/current`).then((res) => res.data)
  },
  startDowntime(unitId, payload) {
    return api.post(`/fleet/units/${unitId}/downtime/start`, payload).then((res) => res.data)
  },
  endDowntime(unitId, payload = {}) {
    return api.post(`/fleet/units/${unitId}/downtime/end`, payload).then((res) => res.data)
  },

  // External repairs (work farmed out to third parties)
  listExternalRepairs(params = {}) {
    return api.get('/fleet/external-repairs', { params }).then((res) => res.data)
  },
  getExternalRepair(id) {
    return api.get(`/fleet/external-repairs/${id}`).then((res) => res.data)
  },
  listExternalRepairsForUnit(unitId, params = {}) {
    return api
      .get(`/fleet/units/${unitId}/external-repairs`, { params })
      .then((res) => res.data)
  },
  createExternalRepair(unitId, payload) {
    return api.post(`/fleet/units/${unitId}/external-repairs`, payload).then((res) => res.data)
  },
  updateExternalRepair(id, payload) {
    return api.put(`/fleet/external-repairs/${id}`, payload).then((res) => res.data)
  },
  deleteExternalRepair(id) {
    return api.delete(`/fleet/external-repairs/${id}`).then((res) => res.data)
  },

  // PM ↔ fleet bindings (which PM plans apply to which units)
  listPmBindings(params = {}) {
    return api.get('/pm/fleet-bindings', { params }).then((res) => res.data)
  },
  createPmBinding(payload) {
    return api.post('/pm/fleet-bindings', payload).then((res) => res.data)
  },
  updatePmBinding(id, payload) {
    return api.put(`/pm/fleet-bindings/${id}`, payload).then((res) => res.data)
  },
  deletePmBinding(id) {
    return api.delete(`/pm/fleet-bindings/${id}`).then((res) => res.data)
  },

  // Reports
  costPerMile(params = {}) {
    return api.get('/fleet/reports/cost-per-mile', { params }).then((res) => res.data)
  },
  costPerHour(params = {}) {
    return api.get('/fleet/reports/cost-per-hour', { params }).then((res) => res.data)
  },
}
