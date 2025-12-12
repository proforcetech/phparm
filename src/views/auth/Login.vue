<template>
  <div class="min-h-screen flex items-center justify-center bg-gray-50 py-12 px-4 sm:px-6 lg:px-8">
    <div class="max-w-md w-full space-y-8">
      <div>
        <h2 class="mt-6 text-center text-3xl font-extrabold text-gray-900">
          Auto Repair Shop Management
        </h2>
        <p class="mt-2 text-center text-sm text-gray-600">
          Staff Login
        </p>
      </div>

      <form class="mt-8 space-y-6" @submit.prevent="handleLogin">
        <div v-if="error" class="rounded-md bg-red-50 p-4">
          <p class="text-sm text-red-800">{{ error }}</p>
        </div>

        <div v-if="!isVerifying" class="rounded-md shadow-sm -space-y-px">
          <div>
            <label for="email" class="sr-only">Email address</label>
            <input
              id="email"
              v-model="form.email"
              name="email"
              type="email"
              autocomplete="email"
              required
              class="appearance-none rounded-none relative block w-full px-3 py-2 border border-gray-300 placeholder-gray-500 text-gray-900 rounded-t-md focus:outline-none focus:ring-primary-500 focus:border-primary-500 focus:z-10 sm:text-sm"
              placeholder="Email address"
              :disabled="loading"
            />
          </div>
          <div>
            <label for="password" class="sr-only">Password</label>
            <input
              id="password"
              v-model="form.password"
              name="password"
              type="password"
              autocomplete="current-password"
              required
              class="appearance-none rounded-none relative block w-full px-3 py-2 border border-gray-300 placeholder-gray-500 text-gray-900 rounded-b-md focus:outline-none focus:ring-primary-500 focus:border-primary-500 focus:z-10 sm:text-sm"
              placeholder="Password"
              :disabled="loading"
            />
          </div>
        </div>

        <div v-else class="space-y-2">
          <label for="code" class="block text-sm font-medium text-gray-700">Authentication code</label>
          <input
            id="code"
            v-model="code"
            name="code"
            type="text"
            inputmode="numeric"
            autocomplete="one-time-code"
            required
            class="appearance-none block w-full px-3 py-2 border border-gray-300 rounded-md placeholder-gray-500 text-gray-900 focus:outline-none focus:ring-primary-500 focus:border-primary-500 focus:z-10 sm:text-sm"
            placeholder="Enter 6-digit code"
            :disabled="loading"
          />
          <p class="text-xs text-gray-500">Open your authenticator app to retrieve the current code.</p>
        </div>

        <div v-if="!isVerifying" class="flex items-center justify-between">
          <div class="flex items-center">
            <input
              id="remember-me"
              v-model="form.remember"
              name="remember-me"
              type="checkbox"
              class="h-4 w-4 text-primary-600 focus:ring-primary-500 border-gray-300 rounded"
            />
            <label for="remember-me" class="ml-2 block text-sm text-gray-900">
              Remember me
            </label>
          </div>

          <div class="text-sm">
            <router-link to="/forgot-password" class="font-medium text-primary-600 hover:text-primary-500">
              Forgot your password?
            </router-link>
          </div>
        </div>

        <div>
          <div class="flex justify-center">
            <div v-if="recaptchaEnabled" ref="recaptchaContainer"></div>
          </div>

          <button
            type="submit"
            :disabled="loading"
            class="group relative w-full flex justify-center py-2 px-4 border border-transparent text-sm font-medium rounded-md text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 disabled:opacity-50 disabled:cursor-not-allowed"
          >
            <span v-if="loading">Logging in...</span>
            <span v-else>Sign in</span>
          </button>
        </div>

        <div class="text-center">
          <router-link to="/customer-login" class="text-sm text-primary-600 hover:text-primary-500">
            Customer? Login here
          </router-link>
        </div>
      </form>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { securityService } from '@/services/security.service'
import { useRecaptcha } from '@/composables/useRecaptcha'

const authStore = useAuthStore()

const form = ref({
  email: '',
  password: '',
  remember: false,
})

const code = ref('')

const loading = ref(false)
const error = ref(null)
const recaptchaEnabled = ref(false)
const recaptchaSiteKey = ref('')
const { recaptchaContainer, recaptchaToken, resetRecaptcha } = useRecaptcha(recaptchaSiteKey)

const isVerifying = computed(() => !!authStore.pendingChallenge)

onMounted(async () => {
  try {
    const settings = await securityService.getRecaptchaSettings()
    recaptchaEnabled.value = !!settings.enabled
    recaptchaSiteKey.value = settings.site_key || ''
  } catch (err) {
    recaptchaEnabled.value = false
    recaptchaSiteKey.value = ''
    console.error('Failed to load reCAPTCHA settings', err)
  }
})

async function handleLogin() {
  loading.value = true
  error.value = null

  try {
    if (isVerifying.value) {
      await authStore.verifyTwoFactor(code.value)
      return
    }

    if (recaptchaEnabled.value) {
      if (!recaptchaSiteKey.value) {
        throw new Error('reCAPTCHA is not configured')
      }

      if (!recaptchaToken.value) {
        throw new Error('Please complete the reCAPTCHA challenge.')
      }
    }

    const token = recaptchaEnabled.value ? recaptchaToken.value : null
    const result = await authStore.login(form.value.email, form.value.password, false, token)

    if (result?.status === '2fa_required') {
      return
    }
  } catch (err) {
    error.value = err.response?.data?.message || err.message || 'Invalid credentials'
  } finally {
    loading.value = false
    resetRecaptcha()
  }
}
</script>
