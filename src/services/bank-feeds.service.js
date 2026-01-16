import api from './api'

export function fetchBankFeedStatus() {
  return api.get('/bank-feeds/status').then((response) => response.data)
}

export function authorizeBankFeed(payload) {
  return api.post('/bank-feeds/authorize', payload).then((response) => response.data)
}

export function syncBankFeed() {
  return api.post('/bank-feeds/sync').then((response) => response.data)
}
