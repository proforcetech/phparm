import api from './api'

export default {
  /**
   * Get dashboard statistics
   */
  async getStats(params = {}) {
    const response = await api.get('/dashboard', { params })
    return response.data
  },

  /**
   * Get recent invoices
   */
  async getRecentInvoices(limit = 5, params = {}) {
    const response = await api.get('/invoices', {
      params: { limit, sort: '-created_at', ...params }
    })
    return response.data
  },

  /**
   * Get recent appointments
   */
  async getRecentAppointments(limit = 5, params = {}) {
    const response = await api.get('/appointments', {
      params: { limit, sort: 'scheduled_date', ...params }
    })
    return response.data
  },

  /**
   * Get monthly trends chart data (revenue/estimates)
   */
  async getMonthlyTrendsChart(params = {}) {
    const response = await api.get('/dashboard/charts', { params })
    return response.data
  },

  /**
   * Get service type breakdown chart data
   */
  async getServiceTypeChart(params = {}) {
    const response = await api.get('/dashboard/charts/service-types', { params })
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
