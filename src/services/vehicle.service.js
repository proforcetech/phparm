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

export function updateVehicle(customerId, vehicleId, payload) {
  return api.put(`/customers/${customerId}/vehicles/${vehicleId}`, payload).then((r) => r.data)
}

export function deleteVehicle(customerId, vehicleId) {
  return api.delete(`/customers/${customerId}/vehicles/${vehicleId}`).then((r) => r.data)
}

export function decodeVin(vin) {
  return api.post('/vehicles/decode-vin', { vin }).then((r) => r.data)
}

export function validateVin(vin) {
  return api.post('/vehicles/validate-vin', { vin }).then((r) => r.data)
}

// VIN Decoder Settings
export function getVinDecoderSettings() {
  return api.get('/settings/vin-decoder')
}

export function saveVinDecoderSettings(settings) {
  return api.put('/settings/vin-decoder', settings)
}

export function getVinDecoderStats(days = 30) {
  return api.get('/vin-decoder/stats', { params: { days } })
}

export function clearVinDecoderCache(expiredOnly = false) {
  return api.post('/vin-decoder/cache/clear', { expired_only: expiredOnly })
}

export function getVinDecoderLogs(params = {}) {
  return api.get('/vin-decoder/logs', { params })
}
