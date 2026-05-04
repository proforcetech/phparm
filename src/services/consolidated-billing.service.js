import api from './api'

/**
 * Consolidated monthly statements — Phase 17 / M11 of
 * docs/woms-expansion-plan.md.
 *
 * One bundled statement per chain customer per period rather than N invoices.
 * The child invoices keep their own status, public token, and payment history;
 * this surface manages the rolled-up envelope (totals, status flips, sent
 * timestamps).
 */
export const STATUS_OPTIONS = [
  { value: 'draft', label: 'Draft' },
  { value: 'sent', label: 'Sent' },
  { value: 'partial', label: 'Partially Paid' },
  { value: 'paid', label: 'Paid' },
  { value: 'cancelled', label: 'Cancelled' },
]

export default {
  list(params = {}) {
    return api.get('/consolidated-statements', { params }).then((res) => res.data)
  },
  get(id) {
    return api.get(`/consolidated-statements/${id}`).then((res) => res.data)
  },
  generate(payload) {
    return api.post('/consolidated-statements', payload).then((res) => res.data)
  },
  runBatch(payload) {
    return api.post('/consolidated-statements/run-batch', payload).then((res) => res.data)
  },
  markSent(id) {
    return api.post(`/consolidated-statements/${id}/mark-sent`).then((res) => res.data)
  },
  cancel(id) {
    return api.post(`/consolidated-statements/${id}/cancel`).then((res) => res.data)
  },
  detachInvoice(statementId, invoiceId) {
    return api
      .delete(`/consolidated-statements/${statementId}/invoices/${invoiceId}`)
      .then((res) => res.data)
  },
}
