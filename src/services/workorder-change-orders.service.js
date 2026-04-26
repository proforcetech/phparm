import api from './api'

/**
 * Workorder change orders — scope/price changes that need approval before
 * being added to the in-flight WO. Includes line items.
 */
export default {
  listForWorkorder(workorderId, params = {}) {
    return api
      .get(`/workorders/${workorderId}/change-orders`, { params })
      .then((res) => res.data)
  },
  summaryForWorkorder(workorderId) {
    return api
      .get(`/workorders/${workorderId}/change-orders/summary`)
      .then((res) => res.data)
  },
  createForWorkorder(workorderId, payload) {
    return api
      .post(`/workorders/${workorderId}/change-orders`, payload)
      .then((res) => res.data)
  },

  get(id) {
    return api.get(`/workorder-change-orders/${id}`).then((res) => res.data)
  },
  update(id, payload) {
    return api.put(`/workorder-change-orders/${id}`, payload).then((res) => res.data)
  },
  delete(id) {
    return api.delete(`/workorder-change-orders/${id}`).then((res) => res.data)
  },

  // Workflow
  submit(id, payload = {}) {
    return api.post(`/workorder-change-orders/${id}/submit`, payload).then((res) => res.data)
  },
  approve(id, payload = {}) {
    return api.post(`/workorder-change-orders/${id}/approve`, payload).then((res) => res.data)
  },
  reject(id, payload = {}) {
    return api.post(`/workorder-change-orders/${id}/reject`, payload).then((res) => res.data)
  },
  cancel(id, payload = {}) {
    return api.post(`/workorder-change-orders/${id}/cancel`, payload).then((res) => res.data)
  },

  // Line items
  addItem(changeOrderId, payload) {
    return api
      .post(`/workorder-change-orders/${changeOrderId}/items`, payload)
      .then((res) => res.data)
  },
  updateItem(itemId, payload) {
    return api.put(`/workorder-change-order-items/${itemId}`, payload).then((res) => res.data)
  },
  deleteItem(itemId) {
    return api.delete(`/workorder-change-order-items/${itemId}`).then((res) => res.data)
  },
}
