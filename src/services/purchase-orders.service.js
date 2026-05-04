import api from './api'

/**
 * Purchase order client (Phase 18 / S5).
 *
 * Lifecycle: draft → sent → partial → received → closed.
 * Server enforces `procurement.view` for reads, `procurement.manage` for
 * authoring/transitioning, and `procurement.receive` for the receive call
 * (so parts staff can receive without being able to author).
 */
export default {
  list(filters = {}) {
    return api.get('/purchase-orders', { params: filters }).then((r) => r.data)
  },

  get(id) {
    return api.get(`/purchase-orders/${id}`).then((r) => r.data)
  },

  create(payload) {
    return api.post('/purchase-orders', payload).then((r) => r.data)
  },

  update(id, payload) {
    return api.patch(`/purchase-orders/${id}`, payload).then((r) => r.data)
  },

  addLine(id, payload) {
    return api.post(`/purchase-orders/${id}/lines`, payload).then((r) => r.data)
  },

  updateLine(lineId, payload) {
    return api.patch(`/purchase-order-lines/${lineId}`, payload).then((r) => r.data)
  },

  removeLine(lineId) {
    return api.delete(`/purchase-order-lines/${lineId}`).then((r) => r.data)
  },

  send(id) {
    return api.post(`/purchase-orders/${id}/send`, {}).then((r) => r.data)
  },

  close(id) {
    return api.post(`/purchase-orders/${id}/close`, {}).then((r) => r.data)
  },

  cancel(id, reason = '') {
    return api.post(`/purchase-orders/${id}/cancel`, reason ? { reason } : {}).then((r) => r.data)
  },

  receive(id, items, meta = {}) {
    return api.post(`/purchase-orders/${id}/receive`, { ...meta, items }).then((r) => r.data)
  },
}
