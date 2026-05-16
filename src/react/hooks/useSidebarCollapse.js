import { useCallback, useState } from 'react'

export default function useSidebarCollapse(storageKey) {
  const [isSidebarCollapsed, setIsSidebarCollapsed] = useState(() => {
    if (typeof window === 'undefined') {
      return false
    }

    return window.localStorage.getItem(storageKey) === 'true'
  })

  const toggleSidebarCollapsed = useCallback(() => {
    setIsSidebarCollapsed((prev) => {
      const next = !prev

      if (typeof window !== 'undefined') {
        window.localStorage.setItem(storageKey, String(next))
      }

      return next
    })
  }, [storageKey])

  return {
    isSidebarCollapsed,
    toggleSidebarCollapsed,
  }
}
