import api from './api'

/**
 * Preventive maintenance — plans, schedules, compliance reporting, and
 * generation lifecycle (an "instance" of a schedule firing).
 */
export default {
  // Plans (PM templates)
  listPlans(params = {}) {
    return api.get('/pm/plans', { params }).then((res) => res.data)
  },
  getPlan(id) {
    return api.get(`/pm/plans/${id}`).then((res) => res.data)
  },
  createPlan(payload) {
    return api.post('/pm/plans', payload).then((res) => res.data)
  },
  updatePlan(id, payload) {
    return api.put(`/pm/plans/${id}`, payload).then((res) => res.data)
  },
  deletePlan(id) {
    return api.delete(`/pm/plans/${id}`).then((res) => res.data)
  },

  // Schedules (plan applied to a target on a cadence)
  listSchedules(params = {}) {
    return api.get('/pm/schedules', { params }).then((res) => res.data)
  },
  getSchedule(id) {
    return api.get(`/pm/schedules/${id}`).then((res) => res.data)
  },
  createSchedule(payload) {
    return api.post('/pm/schedules', payload).then((res) => res.data)
  },
  updateSchedule(id, payload) {
    return api.put(`/pm/schedules/${id}`, payload).then((res) => res.data)
  },
  deleteSchedule(id) {
    return api.delete(`/pm/schedules/${id}`).then((res) => res.data)
  },

  // Compliance reporting
  overdue(params = {}) {
    return api.get('/pm/compliance/overdue', { params }).then((res) => res.data)
  },
  scheduleCompliance(scheduleId, params = {}) {
    return api
      .get(`/pm/schedules/${scheduleId}/compliance`, { params })
      .then((res) => res.data)
  },
  companyCompliance(companyId, params = {}) {
    return api
      .get(`/pm/compliance/companies/${companyId}`, { params })
      .then((res) => res.data)
  },

  // Generation lifecycle
  completeGeneration(generationId, payload = {}) {
    return api.post(`/pm/generations/${generationId}/complete`, payload).then((res) => res.data)
  },
}
