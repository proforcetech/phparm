import api from './api'

export default {
  /**
   * Get dashboard statistics
   */
  async getStats() {
    const response = await api.get('/dashboard/stats')
    return response.data
  },

  /**
   * Get recent invoices
   */
  async getRecentInvoices(limit = 5) {
    const response = await api.get('/invoices', {
      params: { limit, sort: '-created_at' }
    })
    return response.data
  },

  /**
   * Get recent appointments
   */
  async getRecentAppointments(limit = 5) {
    const response = await api.get('/appointments', {
      params: { limit, sort: 'scheduled_date' }
    })
    return response.data
  },

  /**
   * Get revenue chart data
   */
  async getRevenueChart(period = '30days') {
    const response = await api.get('/dashboard/revenue-chart', {
      params: { period }
    })
    return response.data
  },

  /**
   * Get appointment chart data
   */
  async getAppointmentChart(period = '30days') {
    const response = await api.get('/dashboard/appointment-chart', {
      params: { period }
    })
    return response.data
  },

  /**
   * Get low-stock inventory tile data for dashboard widgets
   */
  async getInventoryLowStockTile(limit = 5) {
    const response = await api.get('/dashboard/inventory/low-stock', {
      params: { limit },
    })
    return response.data
  },
}
