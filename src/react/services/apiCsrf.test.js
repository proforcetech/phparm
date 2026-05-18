import { beforeEach, describe, expect, it, vi } from 'vitest'

const axiosMock = vi.hoisted(() => {
  const instance = {
    interceptors: {
      request: {
        use: vi.fn(),
      },
      response: {
        use: vi.fn((onFulfilled, onRejected) => {
          axiosMock.responseFulfilled = onFulfilled
          axiosMock.responseRejected = onRejected
        }),
      },
    },
    request: vi.fn(),
  }

  return {
    get: vi.fn(),
    create: vi.fn(() => instance),
    instance,
    responseFulfilled: null,
    responseRejected: null,
  }
})

vi.mock('axios', () => ({
  default: {
    create: axiosMock.create,
    get: axiosMock.get,
  },
}))

describe('api CSRF handling', () => {
  beforeEach(() => {
    vi.resetModules()
    axiosMock.get.mockReset()
    axiosMock.create.mockClear()
    axiosMock.instance.request.mockReset()
    axiosMock.instance.interceptors.request.use.mockClear()
    axiosMock.instance.interceptors.response.use.mockClear()
    axiosMock.responseFulfilled = null
    axiosMock.responseRejected = null
    document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/'
  })

  it('refreshes CSRF from the server and retries with the fresh token', async () => {
    document.cookie = 'XSRF-TOKEN=stale-token; path=/'
    axiosMock.get.mockResolvedValue({ data: { token: 'fresh-token' } })
    axiosMock.instance.request.mockResolvedValue({ data: { success: true } })

    await import('../../services/api')

    const originalConfig = {
      method: 'post',
      url: '/workorders/direct',
      headers: {
        'X-CSRF-Token': 'stale-token',
      },
    }

    const result = await axiosMock.responseRejected({
      response: {
        status: 403,
        data: {
          error: 'csrf_token_invalid',
        },
      },
      config: originalConfig,
    })

    expect(axiosMock.get).toHaveBeenCalledWith('/api/csrf-token', { withCredentials: true })
    expect(axiosMock.instance.request).toHaveBeenCalledWith({
      ...originalConfig,
      _csrfRetry: true,
      headers: {
        'X-CSRF-Token': 'fresh-token',
      },
    })
    expect(result).toEqual({ data: { success: true } })
  })
})
