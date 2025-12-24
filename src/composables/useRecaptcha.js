import { onBeforeUnmount, onMounted, ref, watch } from 'vue'

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
  const siteKeyRef = typeof siteKey === 'string' ? ref(siteKey) : siteKey
  const recaptchaContainer = ref(null)
  const widgetId = ref(null)
  const token = ref(null)

  const renderRecaptcha = async () => {
    const grecaptcha = await loadRecaptcha(siteKeyRef?.value)
    if (!grecaptcha || !recaptchaContainer.value || widgetId.value !== null) {
      return
    }

    widgetId.value = grecaptcha.render(recaptchaContainer.value, {
      sitekey: siteKeyRef?.value,
      callback: (value) => {
        token.value = value
      },
      'expired-callback': () => {
        token.value = null
      },
    })
  }

  const resetRecaptcha = () => {
    if (widgetId.value !== null && window.grecaptcha) {
      window.grecaptcha.reset(widgetId.value)
      token.value = null
    }
  }

  onMounted(() => {
    renderRecaptcha()
  })

  watch(
    siteKeyRef,
    () => {
      resetRecaptcha()
      widgetId.value = null
      renderRecaptcha()
    },
    { immediate: false }
  )

  onBeforeUnmount(() => {
    resetRecaptcha()
  })

  return {
    recaptchaContainer,
    recaptchaToken: token,
    renderRecaptcha,
    resetRecaptcha,
  }
}
