import api from './api'

const htmlSettingKeys = new Set(['documents.terms.estimates', 'documents.terms.invoices'])

const normalizeSettings = (settings) => {
  if (!settings || typeof settings !== 'object') {
    return settings
  }

  const normalized = { ...settings }
  htmlSettingKeys.forEach((key) => {
    if (normalized[key]) {
      normalized[key] = {
        ...normalized[key],
        value: normalized[key].value ?? '',
      }
    }
  })

  return normalized
}

const normalizePayload = (payload = {}) => {
  const normalized = { ...payload }
  htmlSettingKeys.forEach((key) => {
    if (key in normalized) {
      normalized[key] = normalized[key] ?? ''
    }
  })

  return normalized
}

export function fetchSettings() {
  return api.get('/settings').then((r) => normalizeSettings(r.data))
}

export function saveSettings(payload) {
  return api.put('/settings', normalizePayload(payload)).then((r) => r.data)
}
