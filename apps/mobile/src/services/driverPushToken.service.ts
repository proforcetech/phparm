import { api } from './api'

export const driverPushTokenService = {
  async registerToken(token: string, platform: string) {
    const response = await api.post('/driver/push-tokens', {
      token,
      platform,
    })
    return response.data
  },
}
