import portalApi from './api'

/**
 * Phase 2a/2b — high-level portal data surface.
 *
 * Replaces the misrouted top-level src/services/portal.service.js for the
 * NEW portal tree (src/react/views/portal/*). The legacy file remains in
 * place for the deprecated customer-portal/* tree until those views are
 * removed in a later phase.
 *
 * 2b adds: approval actions, request-wizard reads, request submit,
 * invoice detail + checkout, payment-method CRUD, sites + assets reads.
 * 2c adds: contracts list/detail, workorders list/detail, standalone
 * messages inbox + per-thread reads/writes.
 */
export const portalService = {
  // ── Theme ────────────────────────────────────────────────────────────
  async theme() {
    const response = await portalApi.get('/portal/theme/me')
    return response.data
  },

  // ── Sites + assets (Phase 6.4 read surface) ──────────────────────────
  async listSites() {
    const response = await portalApi.get('/portal/sites')
    return response.data?.data ?? response.data ?? []
  },

  async getSite(siteId) {
    const response = await portalApi.get(`/portal/sites/${siteId}`)
    return response.data?.data ?? response.data ?? null
  },

  async listAssetsAtSite(siteId, params = {}) {
    const response = await portalApi.get(`/portal/sites/${siteId}/assets`, { params })
    // listAssetsAtSite returns { data: [...], total, limit, offset } — pass through
    return response.data ?? { data: [] }
  },

  // ── Approvals (Phase 6.3) ────────────────────────────────────────────
  // Listing returns { estimates: [...], contracts: [...] } under data.
  async listApprovals() {
    const response = await portalApi.get('/portal/approvals')
    return response.data?.data ?? { estimates: [], contracts: [] }
  },

  async approveEstimate(estimateId, note = null) {
    const body = note != null && note !== '' ? { note } : {}
    const response = await portalApi.post(
      `/portal/approvals/estimates/${estimateId}/approve`,
      body,
    )
    return response.data?.data ?? response.data
  },

  async rejectEstimate(estimateId, reason) {
    const response = await portalApi.post(
      `/portal/approvals/estimates/${estimateId}/reject`,
      { reason },
    )
    return response.data?.data ?? response.data
  },

  async approveContract(contractId, note = null) {
    const body = note != null && note !== '' ? { note } : {}
    const response = await portalApi.post(
      `/portal/approvals/contracts/${contractId}/approve`,
      body,
    )
    return response.data?.data ?? response.data
  },

  async rejectContract(contractId, reason) {
    const response = await portalApi.post(
      `/portal/approvals/contracts/${contractId}/reject`,
      { reason },
    )
    return response.data?.data ?? response.data
  },

  // ── Request wizard (Phase 6.2) ───────────────────────────────────────
  async listRequestTypes() {
    const response = await portalApi.get('/portal/request-types')
    return response.data?.data ?? []
  },

  async listRequestSubcategories(requestTypeId) {
    const response = await portalApi.get(
      `/portal/request-types/${requestTypeId}/subcategories`,
    )
    return response.data?.data ?? []
  },

  async submitRequest(payload) {
    const response = await portalApi.post('/portal/requests', payload)
    return response.data?.data ?? response.data
  },

  // ── Invoices + checkout (Phase 6.4) ──────────────────────────────────
  async listInvoices(params = {}) {
    const response = await portalApi.get('/portal/invoices', { params })
    return response.data?.data ?? response.data ?? []
  },

  async getInvoice(invoiceId) {
    const response = await portalApi.get(`/portal/invoices/${invoiceId}`)
    return response.data?.data ?? response.data ?? null
  },

  async startCheckout(invoiceId, body) {
    const response = await portalApi.post(
      `/portal/invoices/${invoiceId}/checkout`,
      body,
    )
    return response.data?.data ?? response.data
  },

  // ── Saved payment methods (Phase 6.4) ────────────────────────────────
  async listPaymentMethods() {
    const response = await portalApi.get('/portal/payment-methods')
    return response.data?.data ?? []
  },

  async savePaymentMethod(payload) {
    const response = await portalApi.post('/portal/payment-methods', payload)
    return response.data?.data ?? response.data
  },

  async setDefaultPaymentMethod(methodId) {
    const response = await portalApi.post(
      `/portal/payment-methods/${methodId}/default`,
    )
    return response.data?.data ?? response.data
  },

  async deletePaymentMethod(methodId) {
    await portalApi.delete(`/portal/payment-methods/${methodId}`)
  },

  // ── Contracts (Phase 2c, read-only) ──────────────────────────────────
  async listContracts(params = {}) {
    const response = await portalApi.get('/portal/contracts', { params })
    return response.data?.data ?? { data: [], total: 0 }
  },

  async getContract(contractId) {
    const response = await portalApi.get(`/portal/contracts/${contractId}`)
    return response.data?.data ?? response.data ?? null
  },

  // ── Work orders (Phase 2c, read-only) ────────────────────────────────
  async listWorkorders(params = {}) {
    const response = await portalApi.get('/portal/workorders', { params })
    return response.data?.data ?? { data: [], total: 0 }
  },

  async getWorkorder(workorderId) {
    const response = await portalApi.get(`/portal/workorders/${workorderId}`)
    return response.data?.data ?? response.data ?? null
  },

  async listWorkorderThreads(workorderId) {
    const response = await portalApi.get(`/portal/workorders/${workorderId}/threads`)
    return response.data?.data ?? []
  },

  async startWorkorderThread(workorderId, body, subject = null) {
    const payload = subject ? { body, subject } : { body }
    const response = await portalApi.post(
      `/portal/workorders/${workorderId}/threads`,
      payload,
    )
    return response.data?.data ?? response.data
  },

  // ── Messages (Phase 2c, standalone inbox + thread ops) ───────────────
  async listMessageInbox(params = {}) {
    const response = await portalApi.get('/portal/messages', { params })
    return response.data?.data ?? { data: [], total: 0 }
  },

  async listThreadMessages(threadId) {
    const response = await portalApi.get(`/portal/threads/${threadId}/messages`)
    return response.data?.data ?? []
  },

  async postThreadMessage(threadId, body) {
    const response = await portalApi.post(
      `/portal/threads/${threadId}/messages`,
      { body },
    )
    return response.data?.data ?? response.data
  },

  async markThreadRead(threadId) {
    await portalApi.post(`/portal/threads/${threadId}/read`)
  },
}
