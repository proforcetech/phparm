import { api } from './api'

export function listCustomers(params: Record<string, unknown> = {}) {
  return api.get('/customers', { params }).then((r) => r.data)
}

export function searchCustomers(query: string) {
  return api
    .get('/customers', {
      params: {
        query,
        limit: 10,
      },
    })
    .then((r) => r.data)
}

export function getCustomer(id: number | string) {
  return api.get(`/customers/${id}`).then((r) => r.data)
}

export function getCustomerVehicles(customerId: number | string) {
  return api.get(`/customers/${customerId}/vehicles`).then((r) => r.data)
}

export function createCustomer(payload: Record<string, unknown>) {
  return api.post('/customers', payload).then((r) => r.data)
}

export function updateCustomer(id: number | string, payload: Record<string, unknown>) {
  return api.put(`/customers/${id}`, payload).then((r) => r.data)
}

export function deleteCustomer(id: number | string) {
  return api.delete(`/customers/${id}`).then((r) => r.data)
}

const customerService = {
  listCustomers,
  searchCustomers,
  getCustomer,
  getCustomerVehicles,
  createCustomer,
  updateCustomer,
  deleteCustomer,
}

export default customerService
