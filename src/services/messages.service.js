import api from './api'

export const messagingService = {
  async listThreads() {
    const response = await api.get('/messages/threads')
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
  async markRead(threadId) {
    const response = await api.post(`/messages/threads/${threadId}/read`)
    return response.data
  },
  async unreadCounts() {
    const response = await api.get('/messages/unread')
    return response.data
  }
}
