import api from './api'

export function fetchServiceTypes(params = {}) {
  return api.get('/service-types', { params }).then((r) => r.data)
}

export function createServiceType(payload) {
  return api.post('/service-types', payload).then((r) => r.data)
}
