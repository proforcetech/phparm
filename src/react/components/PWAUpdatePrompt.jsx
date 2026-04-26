import { useEffect, useState } from 'react'

import { acceptUpdate, onServiceWorkerEvent } from '../utils/registerSW'

export default function PWAUpdatePrompt() {
  const [visible, setVisible] = useState(false)

  useEffect(() => {
    const off = onServiceWorkerEvent('update-available', () => setVisible(true))
    return off
  }, [])

  if (!visible) return null

  const handleReload = () => {
    if (!acceptUpdate()) {
      window.location.reload()
    }
  }

  return (
    <div
      role="status"
      aria-live="polite"
      className="fixed bottom-4 left-1/2 z-50 w-[min(92vw,420px)] -translate-x-1/2 rounded-lg border border-blue-500/40 bg-slate-900 px-4 py-3 text-sm text-slate-100 shadow-2xl"
    >
      <div className="flex items-center gap-3">
        <div className="flex-1">
          <p className="font-semibold">A new version is ready.</p>
          <p className="mt-0.5 text-xs text-slate-300">
            Reload to pick up the latest features and fixes.
          </p>
        </div>
        <button
          type="button"
          onClick={handleReload}
          className="rounded bg-blue-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-blue-500"
        >
          Reload
        </button>
        <button
          type="button"
          onClick={() => setVisible(false)}
          className="text-xs text-slate-400 hover:text-slate-200"
          aria-label="Dismiss update notification"
        >
          Later
        </button>
      </div>
    </div>
  )
}
