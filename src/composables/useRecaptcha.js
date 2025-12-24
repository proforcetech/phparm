import { onMounted, ref } from 'vue'

let recaptchaScriptPromise

/**
 * Loads the reCAPTCHA v3 script.
 * In v3, the script URL must include the site key in the 'render' parameter.
 */
function loadRecaptcha(siteKey) {
  if (typeof window === 'undefined') return Promise.resolve(null)

  if (!siteKey) {
    return Promise.resolve(null)
  }

  if (!recaptchaScriptPromise) {
    recaptchaScriptPromise = new Promise((resolve, reject) => {
      // Check if global grecaptcha is already available and initialized
      if (window.grecaptcha && typeof window.grecaptcha.execute === 'function') {
        resolve(window.grecaptcha)
        return
      }

      const script = document.createElement('script')
      // reCAPTCHA v3 requires render=SITE_KEY
      script.src = `https://www.google.com/recaptcha/api.js?render=${siteKey}`
      script.async = true
      script.defer = true
      
      script.onload = () => {
        // Ensure the API is fully ready before resolving
        window.grecaptcha.ready(() => {
          resolve(window.grecaptcha)
        })
      }
      
      script.onerror = reject
      document.head.appendChild(script)
    })
  }

  return recaptchaScriptPromise
}

export function useRecaptcha(siteKey) {
  const siteKeyRef = typeof siteKey === 'string' ? ref(siteKey) : siteKey
  const token = ref(null)

  /**
   * Executes the reCAPTCHA v3 challenge for a specific action.
   * reCAPTCHA v3 tokens are short-lived (2 minutes), so this should be
   * called right before submitting your form.
   * * @param {string} action - The context for the request (e.g., 'login', 'submit')
   * @returns {Promise<string|null>} The generated token or null on failure
   */
  const executeRecaptcha = async (action = 'submit') => {
    const grecaptcha = await loadRecaptcha(siteKeyRef?.value)
    
    if (!grecaptcha || typeof grecaptcha.execute !== 'function') {
      console.error('reCAPTCHA failed to load or execute method is missing')
      return null
    }

    try {
      const responseToken = await grecaptcha.execute(siteKeyRef.value, { action })
      token.value = responseToken
      return responseToken
    } catch (error) {
      console.error('reCAPTCHA execution failed:', error)
      return null
    }
  }

  // Pre-load the script when the component is mounted
  onMounted(() => {
    if (siteKeyRef?.value) {
      loadRecaptcha(siteKeyRef.value)
    }
  })

  return {
    recaptchaToken: token,
    executeRecaptcha,
  }
}