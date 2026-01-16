import api from './api'

const documentVaultService = {
  async list(params = {}) {
    const response = await api.get('/document-vault', { params })
    return response.data
  },

  async alerts(params = {}) {
    const response = await api.get('/document-vault/alerts', { params })
    return response.data
  },

  async create(payload, file) {
    const formData = new FormData()
    Object.entries(payload).forEach(([key, value]) => {
      if (value !== null && value !== undefined && value !== '') {
        formData.append(key, value)
      }
    })
    if (file) {
      formData.append('file', file)
    }

    const response = await api.post('/document-vault', formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
    return response.data
  },

  async update(id, payload, file = null) {
    const formData = new FormData()
    Object.entries(payload).forEach(([key, value]) => {
      if (value !== null && value !== undefined && value !== '') {
        formData.append(key, value)
      }
    })
    if (file) {
      formData.append('file', file)
    }

    const response = await api.put(`/document-vault/${id}`, formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
    return response.data
  },

  async remove(id) {
    const response = await api.delete(`/document-vault/${id}`)
    return response.data
  },
}

export default documentVaultService
