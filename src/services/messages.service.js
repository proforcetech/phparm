import api from './api'

export const messagingService = {
  async listThreads() {
    const response = await api.get('/messages/threads')
    return response.data
  },
  async listParticipants(query = '') {
    const params = query ? { query } : {}
    const response = await api.get('/messages/participants', { params })
    return response.data
  },
  async createThread(payload) {
    const response = await api.post('/messages/threads', payload)
    return response.data
  },
  async listMessages(threadId) {
    const response = await api.get(`/messages/threads/${threadId}/messages`)
    return response.data
  },
  async postMessage(threadId, payload) {
    const response = await api.post(`/messages/threads/${threadId}/messages`, payload)
    return response.data
  },
  async postMessageWithAttachments(threadId, payload = {}) {
    const formData = new FormData()
    if (payload.body) {
      formData.append('body', payload.body)
    }
    if (Array.isArray(payload.files)) {
      payload.files.forEach((file) => {
        formData.append('files[]', file)
      })
    }
    const response = await api.post(`/messages/threads/${threadId}/attachments`, formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
    return response.data
  },
  async markRead(threadId) {
    const response = await api.post(`/messages/threads/${threadId}/read`)
    return response.data
  },
  async threadState(threadId) {
    const response = await api.get(`/messages/threads/${threadId}/state`)
    return response.data
  },
  async unreadCounts() {
    const response = await api.get('/messages/unread')
    return response.data
  }
}
