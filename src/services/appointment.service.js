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
