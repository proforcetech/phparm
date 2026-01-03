const apiBaseUrl = import.meta.env.VITE_API_URL || '/api'
const appName = import.meta.env.VITE_APP_NAME || 'PHPArm'
const recaptchaSiteKey = import.meta.env.VITE_RECAPTCHA_SITE_KEY || ''

export default {
  API_BASE_URL: apiBaseUrl,
  APP_NAME: appName,
  RECAPTCHA_SITE_KEY: recaptchaSiteKey,
}
