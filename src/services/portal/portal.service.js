import portalApi from './api'

/**
 * Phase 2a — high-level portal data surface.
 *
 * Replaces the misrouted top-level src/services/portal.service.js for the
 * NEW portal tree (src/react/views/portal/*). The legacy file remains in
 * place for the deprecated customer-portal/* tree until those views are
 * removed in a later phase.
 *
 * Surfaces filled here cover what Phase 2a's foundation needs (theme +
 * basic reads). 2b/2c extend this with approvals, messaging, payment
 * methods, contracts, work orders, etc.
 */
export const portalService = {
  // Theme — reads from the portal-scoped /theme/me endpoint (returns the
  // signed-in account's company theme; falls back to platform default).
  async theme() {
    const response = await portalApi.get('/portal/theme/me')
    return response.data
  },

  // Sites visible to the signed-in account (respects allowed_site_ids).
  async listSites() {
    const response = await portalApi.get('/portal/sites')
    return response.data?.data ?? response.data ?? []
  },

  // Approvals queue — pending estimates + contracts.
  async listApprovals() {
    const response = await portalApi.get('/portal/approvals')
    return response.data?.data ?? response.data ?? []
  },

  // Invoices — paginated read.
  async listInvoices(params = {}) {
    const response = await portalApi.get('/portal/invoices', { params })
    return response.data
  },
}
