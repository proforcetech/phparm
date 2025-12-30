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

export function testSmtpConnection() {
  return api.post('/settings/notifications/smtp/test-connection').then((r) => r.data)
}

export function sendTestEmail(recipient) {
  return api.post('/settings/notifications/smtp/test-email', { recipient }).then((r) => r.data)
}

export function testTwilioConnection() {
  return api.post('/settings/notifications/twilio/test-connection').then((r) => r.data)
}

export function sendTestSms(recipient) {
  return api.post('/settings/notifications/twilio/test-sms', { recipient }).then((r) => r.data)
}
