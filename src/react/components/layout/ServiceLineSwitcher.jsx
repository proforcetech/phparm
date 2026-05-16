import { useState } from 'react'

import { useAuthStore } from '../../stores/auth'
import { useToast } from '../../stores/toast'

/**
 * Phase 11: Sidebar dropdown that lets users with multiple service lines
 * switch between them. The choice is persisted to localStorage immediately
 * and synced to the server via `setCurrentServiceLine` (fire-and-forget).
 *
 * Renders nothing for users with 0 or 1 service line, so single-line
 * customers (current auto-repair shops) see no UI change.
 */
export default function ServiceLineSwitcher({ isCollapsed = false }) {
  const { serviceLines, currentServiceLineId, setCurrentServiceLine } = useAuthStore()
  const toast = useToast()
  const [isSaving, setIsSaving] = useState(false)

  if (!Array.isArray(serviceLines) || serviceLines.length <= 1) {
    return null
  }

  const handleChange = async (event) => {
    const nextId = Number.parseInt(event.target.value, 10)
    if (!Number.isFinite(nextId) || nextId === currentServiceLineId) {
      return
    }

    setIsSaving(true)
    try {
      // setCurrentServiceLine returns a Promise from the server-sync call.
      // We await it so we can show error feedback if persistence fails,
      // but the local UI state is already updated synchronously.
      await setCurrentServiceLine(nextId)
    } catch (err) {
      console.error('[ServiceLineSwitcher] Failed to persist primary:', err)
      toast.error("Couldn't save your selection — local change kept.")
    } finally {
      setIsSaving(false)
    }
  }

  // Collapsed mode: render a compact icon-style select if the sidebar is
  // collapsed on large screens. We still need a control so the user can
  // change lines; we shrink it to fit.
  const containerClasses = isCollapsed
    ? 'px-3 pt-3 pb-2 lg:px-1'
    : 'px-3 pt-3 pb-2'

  return (
    <div className={containerClasses}>
      {!isCollapsed ? (
        <label
          htmlFor="service-line-switcher"
          className="block text-[11px] uppercase tracking-wider text-gray-400 mb-1"
        >
          Service Line
        </label>
      ) : (
        <label
          htmlFor="service-line-switcher"
          className="block text-[11px] uppercase tracking-wider text-gray-400 mb-1 lg:hidden"
        >
          Service Line
        </label>
      )}
      <select
        id="service-line-switcher"
        value={currentServiceLineId ?? ''}
        onChange={handleChange}
        disabled={isSaving}
        aria-label="Switch service line"
        className={`w-full bg-gray-800 border border-gray-700 text-white text-sm rounded-md py-1.5 px-2 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-primary-500 disabled:opacity-60 disabled:cursor-wait ${
          isCollapsed ? 'lg:text-xs lg:px-1' : ''
        }`}
      >
        {currentServiceLineId === null ? (
          <option value="" disabled>
            Select a line
          </option>
        ) : null}
        {serviceLines.map((line) => (
          <option key={line.id} value={line.id}>
            {line.icon ? `${line.icon} ` : ''}
            {line.name}
          </option>
        ))}
      </select>
    </div>
  )
}
