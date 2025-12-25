import api from './api'

const financialVendorService = {
  list(params = {}) {
    return api.get('/financial/vendors', { params }).then((res) => res.data)
  },
  get(id) {
    return api.get(`/financial/vendors/${id}`).then((res) => res.data)
  },
  create(payload) {
    return api.post('/financial/vendors', payload).then((res) => res.data)
  },
  update(id, payload) {
    return api.put(`/financial/vendors/${id}`, payload).then((res) => res.data)
  },
  destroy(id) {
    return api.delete(`/financial/vendors/${id}`).then((res) => res.data)
  },
}

export default financialVendorService
