import api from './api'

/**
 * Vendor master client (Phase 18 / S5).
 *
 * Server enforces `procurement.view` for reads and `procurement.manage`
 * for writes. Distinct from the legacy `inventory_lookups` (type='vendors')
 * rows used by the parts-supplier dropdown.
 */
export default {
  list(filters = {}) {
    return api.get('/vendors', { params: filters }).then((r) => r.data)
  },

  get(id) {
    return api.get(`/vendors/${id}`).then((r) => r.data)
  },

  create(payload) {
    return api.post('/vendors', payload).then((r) => r.data)
  },

  update(id, payload) {
    return api.patch(`/vendors/${id}`, payload).then((r) => r.data)
  },

  delete(id) {
    return api.delete(`/vendors/${id}`).then((r) => r.data)
  },
}
