import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const importFresh = async () => {
  vi.resetModules()
  return import('./registerSW.js')
}

class FakeWorker extends EventTarget {
  constructor(state = 'installing') {
    super()
    this.state = state
    this.postMessage = vi.fn()
  }

  setState(state) {
    this.state = state
    this.dispatchEvent(new Event('statechange'))
  }
}

class FakeRegistration extends EventTarget {
  constructor({ installing = null, waiting = null } = {}) {
    super()
    this.installing = installing
    this.waiting = waiting
    this.unregister = vi.fn(() => Promise.resolve(true))
  }
}

const installFakeServiceWorker = ({ controller = null } = {}) => {
  const listeners = {}
  const sw = {
    controller,
    register: vi.fn(),
    getRegistrations: vi.fn(() => Promise.resolve([])),
    addEventListener: (type, fn) => {
      listeners[type] = listeners[type] || []
      listeners[type].push(fn)
    },
    removeEventListener: (type, fn) => {
      listeners[type] = (listeners[type] || []).filter((f) => f !== fn)
    },
    dispatch: (type, detail = {}) => {
      ;(listeners[type] || []).forEach((fn) => fn(detail))
    },
  }
  Object.defineProperty(navigator, 'serviceWorker', {
    value: sw,
    configurable: true,
  })
  return sw
}

const removeServiceWorker = () => {
  delete navigator.serviceWorker
}

beforeEach(() => {
  // jsdom doesn't ship matchMedia
  if (!window.matchMedia) {
    window.matchMedia = vi.fn().mockReturnValue({ matches: false, addEventListener: () => {}, removeEventListener: () => {} })
  }
})

afterEach(() => {
  removeServiceWorker()
  vi.restoreAllMocks()
})

describe('registerServiceWorker', () => {
  it('no-ops when serviceWorker is unavailable', async () => {
    const mod = await importFresh()
    const result = await mod.registerServiceWorker({ force: true })
    expect(result).toBeNull()
  })

  it('skips registration in dev mode unless force is set', async () => {
    const mod = await importFresh()
    installFakeServiceWorker()
    vi.stubEnv('DEV', true)
    try {
      const result = await mod.registerServiceWorker()
      expect(result).toBeNull()
    } finally {
      vi.unstubAllEnvs()
    }
  })

  it('returns the registration on success and tracks installing worker', async () => {
    const mod = await importFresh()
    const sw = installFakeServiceWorker()
    const installing = new FakeWorker('installing')
    const registration = new FakeRegistration({ installing })
    sw.register.mockResolvedValue(registration)

    const result = await mod.registerServiceWorker({ force: true })
    expect(result).toBe(registration)
    expect(sw.register).toHaveBeenCalledWith('/sw.js', { scope: '/' })
    expect(mod.getRegistration()).toBe(registration)
  })

  it('fires update-available when an installed worker arrives with an existing controller', async () => {
    const mod = await importFresh()
    const sw = installFakeServiceWorker({ controller: { scriptURL: 'old' } })
    const installing = new FakeWorker('installing')
    const registration = new FakeRegistration({ installing })
    sw.register.mockResolvedValue(registration)

    const events = []
    const off = mod.onServiceWorkerEvent('update-available', (e) => events.push(e))

    await mod.registerServiceWorker({ force: true })
    installing.setState('installed')
    expect(events).toHaveLength(1)

    expect(mod.acceptUpdate()).toBe(true)
    expect(installing.postMessage).toHaveBeenCalledWith({ type: 'SKIP_WAITING' })
    off()
  })

  it('fires ready when an installed worker arrives without a prior controller', async () => {
    const mod = await importFresh()
    const sw = installFakeServiceWorker({ controller: null })
    const installing = new FakeWorker('installing')
    const registration = new FakeRegistration({ installing })
    sw.register.mockResolvedValue(registration)

    const ready = vi.fn()
    const update = vi.fn()
    mod.onServiceWorkerEvent('ready', ready)
    mod.onServiceWorkerEvent('update-available', update)

    await mod.registerServiceWorker({ force: true })
    installing.setState('installed')

    expect(ready).toHaveBeenCalled()
    expect(update).not.toHaveBeenCalled()
  })

  it('detects an already-waiting worker on registration', async () => {
    const mod = await importFresh()
    const sw = installFakeServiceWorker({ controller: { scriptURL: 'old' } })
    const waiting = new FakeWorker('installed')
    const registration = new FakeRegistration({ waiting })
    sw.register.mockResolvedValue(registration)

    const update = vi.fn()
    mod.onServiceWorkerEvent('update-available', update)

    await mod.registerServiceWorker({ force: true })

    expect(update).toHaveBeenCalledTimes(1)
    expect(mod.acceptUpdate()).toBe(true)
  })

  it('reloads on controllerchange exactly once', async () => {
    const mod = await importFresh()
    const sw = installFakeServiceWorker()
    const registration = new FakeRegistration()
    sw.register.mockResolvedValue(registration)

    const reload = vi.fn()
    Object.defineProperty(window, 'location', {
      value: { ...window.location, reload },
      configurable: true,
    })

    await mod.registerServiceWorker({ force: true })
    sw.dispatch('controllerchange')
    sw.dispatch('controllerchange')

    expect(reload).toHaveBeenCalledTimes(1)
  })

  it('returns null on register failure and reports through onError', async () => {
    const mod = await importFresh()
    const sw = installFakeServiceWorker()
    sw.register.mockRejectedValue(new Error('boom'))
    const onError = vi.fn()

    const result = await mod.registerServiceWorker({ force: true, onError })
    expect(result).toBeNull()
    expect(onError).toHaveBeenCalledTimes(1)
    expect(onError.mock.calls[0][0].message).toBe('boom')
  })
})

describe('isPwaInstalled', () => {
  it('returns true when display-mode is standalone', async () => {
    const mod = await importFresh()
    window.matchMedia = vi.fn().mockReturnValue({ matches: true })
    expect(mod.isPwaInstalled()).toBe(true)
  })

  it('returns true when navigator.standalone is set (iOS)', async () => {
    const mod = await importFresh()
    window.matchMedia = vi.fn().mockReturnValue({ matches: false })
    Object.defineProperty(navigator, 'standalone', { value: true, configurable: true })
    try {
      expect(mod.isPwaInstalled()).toBe(true)
    } finally {
      delete navigator.standalone
    }
  })

  it('returns false otherwise', async () => {
    const mod = await importFresh()
    window.matchMedia = vi.fn().mockReturnValue({ matches: false })
    expect(mod.isPwaInstalled()).toBe(false)
  })
})

describe('install prompt', () => {
  it('intercepts beforeinstallprompt and runs through user choice', async () => {
    const mod = await importFresh()
    const prompt = vi.fn()
    const userChoice = Promise.resolve({ outcome: 'accepted' })

    let registered
    const off = mod.onInstallPromptAvailable((e) => {
      registered = e
    })

    const event = new Event('beforeinstallprompt')
    Object.assign(event, { prompt, userChoice })
    event.preventDefault = vi.fn()
    window.dispatchEvent(event)

    expect(event.preventDefault).toHaveBeenCalled()
    expect(registered).toBe(event)
    expect(mod.hasDeferredInstallPrompt()).toBe(true)

    const result = await mod.triggerInstallPrompt()
    expect(prompt).toHaveBeenCalled()
    expect(result).toEqual({ available: true, outcome: 'accepted' })
    expect(mod.hasDeferredInstallPrompt()).toBe(false)
    off()
  })

  it('returns available:false when no deferred prompt is held', async () => {
    const mod = await importFresh()
    const result = await mod.triggerInstallPrompt()
    expect(result).toEqual({ available: false })
  })
})

describe('unregisterServiceWorkers', () => {
  it('unregisters every active registration', async () => {
    const mod = await importFresh()
    const reg1 = { unregister: vi.fn(() => Promise.resolve(true)) }
    const reg2 = { unregister: vi.fn(() => Promise.resolve(true)) }
    installFakeServiceWorker()
    navigator.serviceWorker.getRegistrations = vi.fn(() => Promise.resolve([reg1, reg2]))

    const count = await mod.unregisterServiceWorkers()
    expect(count).toBe(2)
    expect(reg1.unregister).toHaveBeenCalled()
    expect(reg2.unregister).toHaveBeenCalled()
  })
})
