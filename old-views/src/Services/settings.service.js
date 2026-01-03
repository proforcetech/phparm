import api from './api'

export function fetchSettings() {
  return api.get('/settings').then((r) => r.data)
}

export function saveSettings(payload) {
  return api.put('/settings', payload).then((r) => r.data)
}
