import api from './api'

/**
 * Software CMDB — software_assets (catalog), license_seats (per-customer
 * license pools), license_assignments (seat ↔ user/asset), and
 * installed_software (machine ↔ title). Phase 14 / M9 of
 * docs/woms-expansion-plan.md.
 */
export default {
  // Software titles (catalog)
  listTitles(params = {}) {
    return api.get('/software-titles', { params }).then((res) => res.data)
  },
  getTitle(id) {
    return api.get(`/software-titles/${id}`).then((res) => res.data)
  },
  createTitle(payload) {
    return api.post('/software-titles', payload).then((res) => res.data)
  },
  updateTitle(id, payload) {
    return api.put(`/software-titles/${id}`, payload).then((res) => res.data)
  },
  deleteTitle(id) {
    return api.delete(`/software-titles/${id}`).then((res) => res.data)
  },

  // License pools
  listPools(params = {}) {
    return api.get('/software-license-pools', { params }).then((res) => res.data)
  },
  getPool(id) {
    return api.get(`/software-license-pools/${id}`).then((res) => res.data)
  },
  createPool(payload) {
    return api.post('/software-license-pools', payload).then((res) => res.data)
  },
  updatePool(id, payload) {
    return api.put(`/software-license-pools/${id}`, payload).then((res) => res.data)
  },
  deletePool(id) {
    return api.delete(`/software-license-pools/${id}`).then((res) => res.data)
  },

  // License assignments
  listAssignments(params = {}) {
    return api.get('/software-license-assignments', { params }).then((res) => res.data)
  },
  assign(payload) {
    return api.post('/software-license-assignments', payload).then((res) => res.data)
  },
  unassign(id, payload = {}) {
    return api.post(`/software-license-assignments/${id}/unassign`, payload).then((res) => res.data)
  },

  // Installed software
  listInstalls(params = {}) {
    return api.get('/software-installs', { params }).then((res) => res.data)
  },
  recordInstall(payload) {
    return api.post('/software-installs', payload).then((res) => res.data)
  },
  removeInstall(id) {
    return api.delete(`/software-installs/${id}`).then((res) => res.data)
  },
  linkInstall(id, payload) {
    return api.post(`/software-installs/${id}/link`, payload).then((res) => res.data)
  },

  // Compliance
  compliance(params = {}) {
    return api.get('/software-compliance', { params }).then((res) => res.data)
  },
  reconcile() {
    return api.post('/software-reconcile', {}).then((res) => res.data)
  },
}
