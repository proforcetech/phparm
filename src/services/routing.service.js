import api from './api'

/**
 * Routing & geofencing — geo-fences, fence events, route plans, and
 * stop lifecycle (en-route / arrived / completed / skipped).
 */
export default {
  // Geo-fences
  listFences(params = {}) {
    return api.get('/geo-fences', { params }).then((res) => res.data)
  },
  getFence(id) {
    return api.get(`/geo-fences/${id}`).then((res) => res.data)
  },
  createFence(payload) {
    return api.post('/geo-fences', payload).then((res) => res.data)
  },
  updateFence(id, payload) {
    return api.put(`/geo-fences/${id}`, payload).then((res) => res.data)
  },
  deleteFence(id) {
    return api.delete(`/geo-fences/${id}`).then((res) => res.data)
  },

  // Fence events
  listFenceEvents(params = {}) {
    return api.get('/geo-fence-events', { params }).then((res) => res.data)
  },
  recordFenceEvent(payload) {
    return api.post('/geo-fence-events', payload).then((res) => res.data)
  },
  fenceEventFromPosition(payload) {
    return api.post('/geo-fence-events/from-position', payload).then((res) => res.data)
  },
  evaluateFenceEvent(payload) {
    return api.post('/geo-fence-events/evaluate', payload).then((res) => res.data)
  },

  // Route plans
  listPlans(params = {}) {
    return api.get('/route-plans', { params }).then((res) => res.data)
  },
  getPlan(id) {
    return api.get(`/route-plans/${id}`).then((res) => res.data)
  },
  createPlan(payload) {
    return api.post('/route-plans', payload).then((res) => res.data)
  },
  updatePlan(id, payload) {
    return api.put(`/route-plans/${id}`, payload).then((res) => res.data)
  },
  deletePlan(id) {
    return api.delete(`/route-plans/${id}`).then((res) => res.data)
  },
  activatePlan(id) {
    return api.post(`/route-plans/${id}/activate`).then((res) => res.data)
  },
  completePlan(id) {
    return api.post(`/route-plans/${id}/complete`).then((res) => res.data)
  },
  cancelPlan(id, payload = {}) {
    return api.post(`/route-plans/${id}/cancel`, payload).then((res) => res.data)
  },
  optimizePlan(id, payload = {}) {
    return api.post(`/route-plans/${id}/optimize`, payload).then((res) => res.data)
  },

  // Stops
  listStops(planId, params = {}) {
    return api.get(`/route-plans/${planId}/stops`, { params }).then((res) => res.data)
  },
  addStop(planId, payload) {
    return api.post(`/route-plans/${planId}/stops`, payload).then((res) => res.data)
  },
  getStop(stopId) {
    return api.get(`/route-plan-stops/${stopId}`).then((res) => res.data)
  },
  updateStop(stopId, payload) {
    return api.put(`/route-plan-stops/${stopId}`, payload).then((res) => res.data)
  },
  deleteStop(stopId) {
    return api.delete(`/route-plan-stops/${stopId}`).then((res) => res.data)
  },
  enRouteStop(stopId, payload = {}) {
    return api.post(`/route-plan-stops/${stopId}/en-route`, payload).then((res) => res.data)
  },
  arrivedStop(stopId, payload = {}) {
    return api.post(`/route-plan-stops/${stopId}/arrived`, payload).then((res) => res.data)
  },
  completedStop(stopId, payload = {}) {
    return api.post(`/route-plan-stops/${stopId}/completed`, payload).then((res) => res.data)
  },
  skippedStop(stopId, payload = {}) {
    return api.post(`/route-plan-stops/${stopId}/skipped`, payload).then((res) => res.data)
  },
}
