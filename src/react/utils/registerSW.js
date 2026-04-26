const SW_PATH = '/sw.js'
const SW_SCOPE = '/'

const swEvents = new EventTarget()

let registrationRef = null
let waitingWorkerRef = null

const dispatch = (type, detail = {}) => {
  swEvents.dispatchEvent(new CustomEvent(type, { detail }))
}

export const onServiceWorkerEvent = (type, handler) => {
  swEvents.addEventListener(type, handler)
  return () => swEvents.removeEventListener(type, handler)
}

const trackInstalling = (worker) => {
  if (!worker) return
  worker.addEventListener('statechange', () => {
    if (worker.state === 'installed') {
      if (navigator.serviceWorker.controller) {
        waitingWorkerRef = worker
        dispatch('update-available')
      } else {
        dispatch('ready')
      }
    }
  })
}

export const acceptUpdate = () => {
  const worker = waitingWorkerRef || (registrationRef && registrationRef.waiting)
  if (!worker) return false
  worker.postMessage({ type: 'SKIP_WAITING' })
  return true
}

export const getRegistration = () => registrationRef

export const isPwaInstalled = () => {
  if (typeof window === 'undefined') return false
  if (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) {
    return true
  }
  return Boolean(window.navigator && window.navigator.standalone)
}

export const registerServiceWorker = async (options = {}) => {
  const {
    enabled = true,
    onError = (err) => console.warn('Service worker registration failed:', err),
    swPath = SW_PATH,
    scope = SW_SCOPE,
  } = options

  if (!enabled) return null
  if (typeof window === 'undefined') return null
  if (!('serviceWorker' in navigator)) return null

  // Vite dev server doesn't ship the SW in /. Skip in dev by default unless
  // the caller explicitly forces enabled (e.g. for SW debugging).
  if (!options.force && import.meta && import.meta.env && import.meta.env.DEV) {
    return null
  }

  try {
    const registration = await navigator.serviceWorker.register(swPath, { scope })
    registrationRef = registration

    if (registration.waiting && navigator.serviceWorker.controller) {
      waitingWorkerRef = registration.waiting
      dispatch('update-available')
    }

    if (registration.installing) {
      trackInstalling(registration.installing)
    }

    registration.addEventListener('updatefound', () => {
      trackInstalling(registration.installing)
    })

    let reloading = false
    navigator.serviceWorker.addEventListener('controllerchange', () => {
      if (reloading) return
      reloading = true
      dispatch('controller-change')
      window.location.reload()
    })

    return registration
  } catch (err) {
    onError(err)
    return null
  }
}

export const unregisterServiceWorkers = async () => {
  if (typeof navigator === 'undefined' || !('serviceWorker' in navigator)) return 0
  const regs = await navigator.serviceWorker.getRegistrations()
  await Promise.all(regs.map((r) => r.unregister()))
  registrationRef = null
  waitingWorkerRef = null
  return regs.length
}

let deferredInstallPrompt = null

export const onInstallPromptAvailable = (handler) => {
  if (typeof window === 'undefined') return () => {}
  const listener = (event) => {
    event.preventDefault()
    deferredInstallPrompt = event
    handler(event)
  }
  window.addEventListener('beforeinstallprompt', listener)
  return () => window.removeEventListener('beforeinstallprompt', listener)
}

export const triggerInstallPrompt = async () => {
  if (!deferredInstallPrompt) return { available: false }
  const promptEvent = deferredInstallPrompt
  deferredInstallPrompt = null
  promptEvent.prompt()
  const choice = await promptEvent.userChoice
  return { available: true, outcome: choice.outcome }
}

export const hasDeferredInstallPrompt = () => deferredInstallPrompt !== null
