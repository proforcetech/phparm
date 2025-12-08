import api from './api'

export function listCustomers(params = {}) {
  return api.get('/customers', { params }).then((r) => r.data)
}

export function getCustomer(id) {
  return api.get(`/customers/${id}`).then((r) => r.data)
}
