import api from './api'

export default {
  list(params = {}) {
    return api.get('/branches', { params }).then((res) => res.data)
  },
}
