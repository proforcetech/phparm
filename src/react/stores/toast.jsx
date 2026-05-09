import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useRef,
  useState,
} from 'react'

const ToastContext = createContext(null)
const defaultTimeoutMs = 3500

export function ToastProvider({ children, timeoutMs = defaultTimeoutMs }) {
  const [messages, setMessages] = useState([])
  const counterRef = useRef(0)
  const timeoutsRef = useRef(new Map())

  const dismiss = useCallback((id) => {
    setMessages((prev) => prev.filter((item) => item.id !== id))

    const timeoutId = timeoutsRef.current.get(id)
    if (timeoutId) {
      clearTimeout(timeoutId)
      timeoutsRef.current.delete(id)
    }
  }, [])

  const push = useCallback(
    (message, type = 'info') => {
      const id = ++counterRef.current
      setMessages((prev) => [...prev, { id, message, type }])

      const timeoutId = setTimeout(() => dismiss(id), timeoutMs)
      timeoutsRef.current.set(id, timeoutId)

      return id
    },
    [dismiss, timeoutMs]
  )

  useEffect(() => () => {
    timeoutsRef.current.forEach((timeoutId) => clearTimeout(timeoutId))
    timeoutsRef.current.clear()
  }, [])

  // Stable identities so components can put `error`/`success`/`info` in
  // useCallback dep arrays without re-firing on every toast push. Each one
  // is memoized on `push`, which itself is stable across messages updates.
  const success = useCallback((message) => push(message, 'success'), [push])
  const error = useCallback((message) => push(message, 'error'), [push])
  const info = useCallback((message) => push(message, 'info'), [push])

  const value = useMemo(
    () => ({ messages, dismiss, success, error, info }),
    [messages, dismiss, success, error, info]
  )

  return <ToastContext.Provider value={value}>{children}</ToastContext.Provider>
}

export function useToast() {
  const context = useContext(ToastContext)

  if (!context) {
    throw new Error('useToast must be used within a ToastProvider.')
  }

  return context
}
