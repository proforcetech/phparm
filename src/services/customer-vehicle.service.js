import api from './api'

// Get a customer's vehicles
export function getCustomerVehicles(customerId) {
  return api.get(`/customers/${customerId}/vehicles`).then((r) => r.data)
}

// Get a single customer vehicle
export function getCustomerVehicle(customerId, vehicleId) {
  return api.get(`/customers/${customerId}/vehicles/${vehicleId}`).then((r) => r.data)
}

// Create a new customer vehicle
export function createCustomerVehicle(customerId, payload) {
  return api.post(`/customers/${customerId}/vehicles`, payload).then((r) => r.data)
}

// Update a customer vehicle
export function updateCustomerVehicle(customerId, vehicleId, payload) {
  return api.put(`/customers/${customerId}/vehicles/${vehicleId}`, payload).then((r) => r.data)
}

// Delete a customer vehicle
export function deleteCustomerVehicle(customerId, vehicleId) {
  return api.delete(`/customers/${customerId}/vehicles/${vehicleId}`).then((r) => r.data)
}
