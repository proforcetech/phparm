import api from './api'

export function fetchAvailability(params = {}) {
  return api.get('/appointments/availability', { params }).then((r) => r.data)
}

export function fetchPublicAvailability(params = {}) {
  return api.get('/public/appointments/availability', { params }).then((r) => r.data)
}

export function fetchAvailabilityConfig() {
  return api.get('/appointments/availability/config').then((r) => r.data)
}

export function saveAvailabilityConfig(payload) {
  return api.put('/appointments/availability/config', payload).then((r) => r.data)
}

export function createAppointment(payload) {
  return api.post('/appointments', payload).then((r) => r.data)
}

export function listAppointments(params = {}) {
  return api.get('/appointments', { params }).then((r) => r.data)
}

export function getAppointment(id) {
  return api.get(`/appointments/${id}`).then((r) => r.data)
}

export function updateAppointment(id, payload) {
  return api.put(`/appointments/${id}`, payload).then((r) => r.data)
}

export function deleteAppointment(id) {
  return api.delete(`/appointments/${id}`).then((r) => r.data)
}

export function updateAppointmentStatus(id, status) {
  return api.patch(`/appointments/${id}/status`, { status }).then((r) => r.data)
}
