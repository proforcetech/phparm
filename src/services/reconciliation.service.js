import api from './api'

export default {
  listSessions(params = {}) {
    return api.get('/financial/reconciliation/sessions', { params }).then((res) => res.data)
  },
  createSession(payload) {
    return api.post('/financial/reconciliation/sessions', payload).then((res) => res.data)
  },
  updateSession(id, payload) {
    return api.put(`/financial/reconciliation/sessions/${id}`, payload).then((res) => res.data)
  },
  fetchSession(id) {
    return api.get(`/financial/reconciliation/sessions/${id}`).then((res) => res.data)
  },
  listBankTransactions(sessionId) {
    return api.get(`/financial/reconciliation/sessions/${sessionId}/bank-transactions`).then((res) => res.data)
  },
  createBankTransaction(sessionId, payload) {
    return api
      .post(`/financial/reconciliation/sessions/${sessionId}/bank-transactions`, payload)
      .then((res) => res.data)
  },
  listLedgerEntries(sessionId, params = {}) {
    return api
      .get(`/financial/reconciliation/sessions/${sessionId}/ledger-entries`, { params })
      .then((res) => res.data)
  },
  createMatch(sessionId, payload) {
    return api.post(`/financial/reconciliation/sessions/${sessionId}/matches`, payload).then((res) => res.data)
  },
  deleteMatch(matchId) {
    return api.delete(`/financial/reconciliation/matches/${matchId}`).then((res) => res.data)
  },
}
