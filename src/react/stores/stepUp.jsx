import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useRef,
  useState,
} from 'react'

import { registerStepUpHandler, unregisterStepUpHandler } from '../../services/api'
import StepUpModal from '../components/auth/StepUpModal'

const StepUpContext = createContext(null)

export function StepUpProvider({ children }) {
  const [open, setOpen] = useState(false)
  const [message, setMessage] = useState(null)
  const pendingRef = useRef(null)

  const close = useCallback(() => {
    setOpen(false)
    setMessage(null)
  }, [])

  const requestStepUp = useCallback((options = {}) => {
    if (pendingRef.current) {
      return pendingRef.current.promise
    }

    let resolveFn
    let rejectFn
    const promise = new Promise((resolve, reject) => {
      resolveFn = resolve
      rejectFn = reject
    })

    pendingRef.current = { promise, resolve: resolveFn, reject: rejectFn }
    setMessage(options.message || null)
    setOpen(true)
    return promise
  }, [])

  const handleVerified = useCallback(() => {
    const pending = pendingRef.current
    pendingRef.current = null
    close()
    pending?.resolve()
  }, [close])

  const handleCancel = useCallback(() => {
    const pending = pendingRef.current
    pendingRef.current = null
    close()
    pending?.reject(new Error('step_up_cancelled'))
  }, [close])

  useEffect(() => {
    registerStepUpHandler(requestStepUp)
    return () => unregisterStepUpHandler(requestStepUp)
  }, [requestStepUp])

  const value = useMemo(() => ({ requestStepUp }), [requestStepUp])

  return (
    <StepUpContext.Provider value={value}>
      {children}
      <StepUpModal
        open={open}
        message={message}
        onVerified={handleVerified}
        onCancel={handleCancel}
      />
    </StepUpContext.Provider>
  )
}

export function useStepUp() {
  const context = useContext(StepUpContext)
  if (!context) {
    throw new Error('useStepUp must be used within a StepUpProvider.')
  }
  return context
}
