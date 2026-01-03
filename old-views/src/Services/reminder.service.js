import api from './api'

export const reminderService = {
  async getPreferences() {
    const response = await api.get('/customer/reminder-preferences')
    return response.data
  },

  async updatePreferences(payload) {
    const response = await api.put('/customer/reminder-preferences', payload)
    return response.data
  },
}
