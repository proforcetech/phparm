import api from './api'

export default {
  technicianMargins(params = {}) {
    return api.get('/reports/technician-margins', { params }).then((res) => res.data)
  },
}
