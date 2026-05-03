import api from './api'

/**
 * Recurring service routes — Phase 15 / M7 of docs/woms-expansion-plan.md.
 *
 * Three layers:
 *   - service routes (template + cadence)
 *   - route stops (per-route waypoints; site/asset, sequence, photo reqs)
 *   - route visits (materialized occurrences; state machine arrive→complete)
 *
 * Visit state machine:
 *   planned → en_route → arrived → completed
 *   planned → skipped               (with reason)
 *   planned → missed                (cron-driven when window expires)
 */
export default {
  // ---- routes ---------------------------------------------------------
  list(params = {}) {
    return api.get('/service-routes', { params }).then((res) => res.data)
  },
  get(id) {
    return api.get(`/service-routes/${id}`).then((res) => res.data)
  },
  create(payload) {
    return api.post('/service-routes', payload).then((res) => res.data)
  },
  update(id, payload) {
    return api.put(`/service-routes/${id}`, payload).then((res) => res.data)
  },
  delete(id) {
    return api.delete(`/service-routes/${id}`).then((res) => res.data)
  },
  generate(id) {
    return api.post(`/service-routes/${id}/generate`).then((res) => res.data)
  },

  // ---- stops ----------------------------------------------------------
  listStops(routeId) {
    return api.get(`/service-routes/${routeId}/stops`).then((res) => res.data)
  },
  createStop(routeId, payload) {
    return api.post(`/service-routes/${routeId}/stops`, payload).then((res) => res.data)
  },
  updateStop(stopId, payload) {
    return api.put(`/route-stops/${stopId}`, payload).then((res) => res.data)
  },
  deleteStop(stopId) {
    return api.delete(`/route-stops/${stopId}`).then((res) => res.data)
  },
  reorderStops(routeId, order) {
    return api.post(`/service-routes/${routeId}/stops/reorder`, { order }).then((res) => res.data)
  },

  // ---- visits ---------------------------------------------------------
  listVisits(params = {}) {
    return api.get('/route-visits', { params }).then((res) => res.data)
  },
  getVisit(id) {
    return api.get(`/route-visits/${id}`).then((res) => res.data)
  },
  transitionVisit(id, payload) {
    return api.post(`/route-visits/${id}/transition`, payload).then((res) => res.data)
  },
  reassignVisit(id, payload) {
    return api.post(`/route-visits/${id}/reassign`, payload).then((res) => res.data)
  },
  scan(token) {
    return api.get(`/route-visits/scan/${encodeURIComponent(token)}`).then((res) => res.data)
  },

  // ---- photos ---------------------------------------------------------
  listPhotos(visitId) {
    return api.get(`/route-visits/${visitId}/photos`).then((res) => res.data)
  },
  recordPhoto(visitId, payload) {
    return api.post(`/route-visits/${visitId}/photos`, payload).then((res) => res.data)
  },
  // Multipart upload for the mobile/PWA flow. Pass a File/Blob and optional
  // metadata (caption, exif, perceptual_hash) and the backend will move the
  // file into public/uploads/route-visits/ before recording the row.
  uploadPhoto(visitId, file, extras = {}) {
    const form = new FormData()
    form.append('media', file, file?.name || `route_visit_${visitId}.jpg`)
    Object.entries(extras).forEach(([key, value]) => {
      if (value !== null && value !== undefined && value !== '') {
        form.append(key, value)
      }
    })
    return api
      .post(`/route-visits/${visitId}/photos/upload`, form, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      .then((res) => res.data)
  },
  deletePhoto(photoId) {
    return api.delete(`/route-visit-photos/${photoId}`).then((res) => res.data)
  },
}
