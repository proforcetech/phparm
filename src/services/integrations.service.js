import api from './api'

/**
 * Third-party integrations — provider catalog, registered connections,
 * sync orchestration, and webhook event logs.
 */
export default {
  // Provider catalog
  listProviders() {
    return api.get('/integrations/providers').then((res) => res.data)
  },
  getProvider(key) {
    return api.get(`/integrations/providers/${key}`).then((res) => res.data)
  },

  // Registered integrations
  list(params = {}) {
    return api.get('/integrations', { params }).then((res) => res.data)
  },
  get(id) {
    return api.get(`/integrations/${id}`).then((res) => res.data)
  },
  create(payload) {
    return api.post('/integrations', payload).then((res) => res.data)
  },
  update(id, payload) {
    return api.put(`/integrations/${id}`, payload).then((res) => res.data)
  },
  delete(id) {
    return api.delete(`/integrations/${id}`).then((res) => res.data)
  },

  // Operations
  test(id, payload = {}) {
    return api.post(`/integrations/${id}/test`, payload).then((res) => res.data)
  },
  sync(id, payload = {}) {
    return api.post(`/integrations/${id}/sync`, payload).then((res) => res.data)
  },
  disconnect(id) {
    return api.post(`/integrations/${id}/disconnect`).then((res) => res.data)
  },

  // Logs
  listSyncLogs(id, params = {}) {
    return api.get(`/integrations/${id}/logs`, { params }).then((res) => res.data)
  },
  listWebhooks(id, params = {}) {
    return api.get(`/integrations/${id}/webhooks`, { params }).then((res) => res.data)
  },
}
