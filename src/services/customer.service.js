import api from './api'

export function listCustomers(params = {}) {
  return api.get('/customers', { params }).then((r) => r.data)
}

export function searchCustomers(query) {
  return api.get('/customers', {
    params: {
      query,
      limit: 10
    }
  }).then((r) => r.data)
}

export function getCustomer(id) {
  return api.get(`/customers/${id}`).then((r) => r.data)
}

export function createCustomer(payload) {
  return api.post('/customers', payload).then((r) => r.data)
}

export function updateCustomer(id, payload) {
  return api.put(`/customers/${id}`, payload).then((r) => r.data)
}

export function deleteCustomer(id) {
  return api.delete(`/customers/${id}`).then((r) => r.data)
}

const customerService = {
  listCustomers,
  searchCustomers,
  getCustomer,
  createCustomer,
  updateCustomer,
  deleteCustomer
}

export default customerService
