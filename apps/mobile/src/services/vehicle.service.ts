import { api } from './api'

export function listVehicles(params: Record<string, unknown> = {}) {
  return api.get('/vehicles', { params }).then((r) => r.data)
}

export function getVehicle(id: number | string) {
  return api.get(`/vehicles/${id}`).then((r) => r.data)
}

export function createVehicle(payload: Record<string, unknown>) {
  return api.post('/vehicles', payload).then((r) => r.data)
}

export function updateVehicle(
  customerId: number | string,
  vehicleId: number | string,
  payload: Record<string, unknown>
) {
  return api.put(`/customers/${customerId}/vehicles/${vehicleId}`, payload).then((r) => r.data)
}

export function deleteVehicle(customerId: number | string, vehicleId: number | string) {
  return api.delete(`/customers/${customerId}/vehicles/${vehicleId}`).then((r) => r.data)
}

export function decodeVin(vin: string) {
  return api.post('/vehicles/decode-vin', { vin }).then((r) => r.data)
}

export function validateVin(vin: string) {
  return api.post('/vehicles/validate-vin', { vin }).then((r) => r.data)
}
