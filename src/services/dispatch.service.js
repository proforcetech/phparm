import api from './api'

export default {
  getSuggestions(params = {}) {
    return api.get('/dispatch/suggestions', { params })
  },
}
