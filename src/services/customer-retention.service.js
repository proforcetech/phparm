import api from './api'

export default {
  list(params = {}) {
    return api.get('/reports/customer-retention', { params }).then((res) => res.data)
  },
  exportReport(params = {}) {
    return api.get('/reports/customer-retention/export', { params }).then((res) => res.data)
  },
  dispatchCampaign(payload = {}) {
    return api.post('/reports/customer-retention/hooks', payload).then((res) => res.data)
  },
}
