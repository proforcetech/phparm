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

  /**
   * Get inventory pull request notifications for the dashboard
   */
  async getInventoryPullRequests(limit = 5, statuses = []) {
    const params = { limit }
    if (statuses.length > 0) {
      params.statuses = statuses.join(',')
    }
    const response = await api.get('/dashboard/inventory/pull-requests', { params })
    return response.data
  },

  /**
   * Get WIP aging buckets for parts pending/authorized workorders
   */
  async getWipAging(params = {}) {
    const response = await api.get('/dashboard/workorders/wip-aging', { params })
    return response.data
  },
}
