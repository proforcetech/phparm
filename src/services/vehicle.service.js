import api from './api'

export function listVehicles(params = {}) {
  return api.get('/vehicles', { params }).then((r) => r.data)
}

export function getVehicle(id) {
  return api.get(`/vehicles/${id}`).then((r) => r.data)
}

export function createVehicle(payload) {
  return api.post('/vehicles', payload).then((r) => r.data)
}

export function decodeVin(vin) {
  return api.post('/vehicles/decode-vin', { vin }).then((r) => r.data)
}

export function validateVin(vin) {
  return api.post('/vehicles/validate-vin', { vin }).then((r) => r.data)
}
