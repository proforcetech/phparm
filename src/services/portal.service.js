import api from './api'

export const portalService = {
  async bootstrap() {
    const response = await api.get('/customer-portal/bootstrap')
    return response.data
  },
}
