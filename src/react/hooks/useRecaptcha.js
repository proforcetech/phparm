import { useCallback, useEffect, useRef, useState } from 'react'

let recaptchaScriptPromise

function loadRecaptcha(siteKey) {
  if (typeof window === 'undefined') return Promise.resolve(null)

  if (window.grecaptcha) {
    return Promise.resolve(window.grecaptcha)
  }

  if (!siteKey) {
    return Promise.resolve(null)
  }

  if (!recaptchaScriptPromise) {
    recaptchaScriptPromise = new Promise((resolve, reject) => {
      const script = document.createElement('script')
      script.src = 'https://www.google.com/recaptcha/api.js?render=explicit'
      script.async = true
      script.defer = true
      script.onload = () => resolve(window.grecaptcha)
      script.onerror = reject
      document.head.appendChild(script)
    })
  }

  return recaptchaScriptPromise
}

export function useRecaptcha(siteKey) {
  const siteKeyRef = useRef(siteKey)
  const recaptchaContainer = useRef(null)
  const widgetId = useRef(null)
  const [token, setToken] = useState(null)

  const renderRecaptcha = useCallback(async () => {
    const grecaptcha = await loadRecaptcha(siteKeyRef.current)
    if (!grecaptcha || !recaptchaContainer.current || widgetId.current !== null) {
      return
    }

    widgetId.current = grecaptcha.render(recaptchaContainer.current, {
      sitekey: siteKeyRef.current,
      callback: (value) => {
        setToken(value)
      },
      'expired-callback': () => {
        setToken(null)
      },
    })
  }, [])

  const resetRecaptcha = useCallback(() => {
    if (widgetId.current !== null && window.grecaptcha) {
      window.grecaptcha.reset(widgetId.current)
      setToken(null)
    }
  }, [])

  useEffect(() => {
    renderRecaptcha()

    return () => {
      resetRecaptcha()
    }
  }, [renderRecaptcha, resetRecaptcha])

  useEffect(() => {
    siteKeyRef.current = siteKey
    resetRecaptcha()
    widgetId.current = null
    renderRecaptcha()
  }, [renderRecaptcha, resetRecaptcha, siteKey])

  return {
    recaptchaContainer,
    recaptchaToken: token,
    renderRecaptcha,
    resetRecaptcha,
  }
}
