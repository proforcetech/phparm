import { useEffect, useState } from 'react'

import {
  isPwaInstalled,
  onInstallPromptAvailable,
  triggerInstallPrompt,
} from '../utils/registerSW'

const DISMISS_KEY = 'phparm:pwa-install-dismissed'
const DISMISS_TTL_MS = 7 * 24 * 60 * 60 * 1000 // 7 days

const wasRecentlyDismissed = () => {
  try {
    const raw = window.localStorage.getItem(DISMISS_KEY)
    if (!raw) return false
    const ts = Number.parseInt(raw, 10)
    if (!Number.isFinite(ts)) return false
    return Date.now() - ts < DISMISS_TTL_MS
  } catch {
    return false
  }
}

const stampDismissed = () => {
  try {
    window.localStorage.setItem(DISMISS_KEY, String(Date.now()))
  } catch {
    /* ignore */
  }
}

export default function PWAInstallPrompt() {
  const [available, setAvailable] = useState(false)

  useEffect(() => {
    if (typeof window === 'undefined') return undefined
    if (isPwaInstalled()) return undefined
    if (wasRecentlyDismissed()) return undefined

    return onInstallPromptAvailable(() => setAvailable(true))
  }, [])

  if (!available) return null

  const handleInstall = async () => {
    const result = await triggerInstallPrompt()
    if (!result.available || result.outcome !== 'accepted') {
      stampDismissed()
    }
    setAvailable(false)
  }

  const handleDismiss = () => {
    stampDismissed()
    setAvailable(false)
  }

  return (
    <div
      role="dialog"
      aria-label="Install PHPArm"
      className="fixed bottom-4 right-4 z-50 w-[min(92vw,360px)] rounded-lg border border-slate-700 bg-slate-900 px-4 py-3 text-sm text-slate-100 shadow-2xl"
    >
      <div className="flex items-start gap-3">
        <div className="flex-1">
          <p className="font-semibold">Install PHPArm</p>
          <p className="mt-0.5 text-xs text-slate-300">
            Add PHPArm to your home screen for faster access and full offline support.
          </p>
        </div>
        <button
          type="button"
          onClick={handleDismiss}
          className="text-slate-500 hover:text-slate-300"
          aria-label="Dismiss install prompt"
        >
          ×
        </button>
      </div>
      <div className="mt-3 flex gap-2">
        <button
          type="button"
          onClick={handleInstall}
          className="flex-1 rounded bg-blue-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-blue-500"
        >
          Install
        </button>
        <button
          type="button"
          onClick={handleDismiss}
          className="rounded border border-slate-700 px-3 py-1.5 text-xs font-medium text-slate-200 hover:bg-slate-800"
        >
          Not now
        </button>
      </div>
    </div>
  )
}
