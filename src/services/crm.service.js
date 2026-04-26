import api from './api'

/**
 * CRM — companies, sites, site contacts, billing contacts, blackout windows,
 * site codes, and customer→company linkage.
 */
export default {
  // Companies
  listCompanies(params = {}) {
    return api.get('/companies', { params }).then((res) => res.data)
  },
  getCompany(id) {
    return api.get(`/companies/${id}`).then((res) => res.data)
  },
  createCompany(payload) {
    return api.post('/companies', payload).then((res) => res.data)
  },
  updateCompany(id, payload) {
    return api.put(`/companies/${id}`, payload).then((res) => res.data)
  },
  deleteCompany(id) {
    return api.delete(`/companies/${id}`).then((res) => res.data)
  },

  // Sites
  listSitesForCompany(companyId, params = {}) {
    return api.get(`/companies/${companyId}/sites`, { params }).then((res) => res.data)
  },
  createSite(payload) {
    return api.post('/sites', payload).then((res) => res.data)
  },
  getSite(id) {
    return api.get(`/sites/${id}`).then((res) => res.data)
  },
  updateSite(id, payload) {
    return api.put(`/sites/${id}`, payload).then((res) => res.data)
  },
  deleteSite(id) {
    return api.delete(`/sites/${id}`).then((res) => res.data)
  },

  // Site contacts
  listSiteContacts(siteId) {
    return api.get(`/sites/${siteId}/contacts`).then((res) => res.data)
  },
  createSiteContact(payload) {
    return api.post('/site-contacts', payload).then((res) => res.data)
  },
  updateSiteContact(id, payload) {
    return api.put(`/site-contacts/${id}`, payload).then((res) => res.data)
  },
  deleteSiteContact(id) {
    return api.delete(`/site-contacts/${id}`).then((res) => res.data)
  },

  // Billing contacts (per-company)
  listBillingContacts(companyId) {
    return api.get(`/companies/${companyId}/billing-contacts`).then((res) => res.data)
  },
  createBillingContact(payload) {
    return api.post('/billing-contacts', payload).then((res) => res.data)
  },
  updateBillingContact(id, payload) {
    return api.put(`/billing-contacts/${id}`, payload).then((res) => res.data)
  },
  deleteBillingContact(id) {
    return api.delete(`/billing-contacts/${id}`).then((res) => res.data)
  },

  // Site codes (numbering, gate codes, etc)
  listSiteCodes(siteId) {
    return api.get(`/sites/${siteId}/codes`).then((res) => res.data)
  },

  // Blackout windows (no-service times for a site)
  listBlackoutWindows(siteId) {
    return api.get(`/sites/${siteId}/blackout-windows`).then((res) => res.data)
  },
  createBlackoutWindow(payload) {
    return api.post('/site-blackout-windows', payload).then((res) => res.data)
  },
  updateBlackoutWindow(id, payload) {
    return api.put(`/site-blackout-windows/${id}`, payload).then((res) => res.data)
  },
  deleteBlackoutWindow(id) {
    return api.delete(`/site-blackout-windows/${id}`).then((res) => res.data)
  },

  // Customer ↔ company linkage
  customerCompanyLinkage(customerId) {
    return api.get(`/customers/${customerId}/company-linkage`).then((res) => res.data)
  },
  promoteCustomerToCompany(customerId, payload = {}) {
    return api.post(`/customers/${customerId}/promote-to-company`, payload).then((res) => res.data)
  },
}
