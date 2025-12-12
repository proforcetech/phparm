<template>
  <div class="min-h-screen flex items-center justify-center bg-gray-50 py-12 px-4 sm:px-6 lg:px-8">
    <div class="max-w-md w-full space-y-8">
      <div>
        <h2 class="mt-6 text-center text-3xl font-extrabold text-gray-900">
          Reset your password
        </h2>
        <p class="mt-2 text-center text-sm text-gray-600">
          Enter your email address and we'll send you a link to reset your password
        </p>
      </div>

      <form v-if="!success" class="mt-8 space-y-6" @submit.prevent="handleSubmit">
        <div v-if="error" class="rounded-md bg-red-50 p-4">
          <p class="text-sm text-red-800">{{ error }}</p>
        </div>

        <div>
          <label for="email" class="sr-only">Email address</label>
          <input
            id="email"
            v-model="email"
            name="email"
            type="email"
            autocomplete="email"
            required
            class="appearance-none relative block w-full px-3 py-2 border border-gray-300 placeholder-gray-500 text-gray-900 rounded-md focus:outline-none focus:ring-primary-500 focus:border-primary-500 focus:z-10 sm:text-sm"
            placeholder="Email address"
          />
        </div>

        <div>
          <div class="flex justify-center">
            <div ref="recaptchaContainer"></div>
          </div>

          <button
            type="submit"
            :disabled="loading"
            class="group relative w-full flex justify-center py-2 px-4 border border-transparent text-sm font-medium rounded-md text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 disabled:opacity-50 disabled:cursor-not-allowed"
          >
            <span v-if="loading">Sending...</span>
            <span v-else>Send reset link</span>
          </button>
        </div>

        <div class="text-center">
          <router-link to="/login" class="text-sm text-primary-600 hover:text-primary-500">
            Back to login
          </router-link>
        </div>
      </form>

      <div v-else class="mt-8 space-y-6">
        <div class="rounded-md bg-green-50 p-4">
          <div class="flex">
            <div class="flex-shrink-0">
              <svg class="h-5 w-5 text-green-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
              </svg>
            </div>
            <div class="ml-3">
              <h3 class="text-sm font-medium text-green-800">
                Check your email
              </h3>
              <div class="mt-2 text-sm text-green-700">
                <p>
                  We've sent a password reset link to <strong>{{ email }}</strong>.
                  Please check your inbox and follow the instructions.
                </p>
              </div>
              <div class="mt-4">
                <p class="text-xs text-green-700">
                  Didn't receive the email? Check your spam folder or
                  <button
                    @click="resetForm"
                    class="font-medium underline hover:text-green-600"
                  >
                    try again
                  </button>
                </p>
              </div>
            </div>
          </div>
        </div>

        <div class="text-center">
          <router-link to="/login" class="text-sm text-primary-600 hover:text-primary-500">
            Back to login
          </router-link>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { useRecaptcha } from '@/composables/useRecaptcha'

const authStore = useAuthStore()

const email = ref('')
const loading = ref(false)
const error = ref(null)
const success = ref(false)
const recaptchaSiteKey = import.meta.env.VITE_RECAPTCHA_SITE_KEY || ''
const { recaptchaContainer, recaptchaToken, resetRecaptcha } = useRecaptcha(recaptchaSiteKey)

async function handleSubmit() {
  loading.value = true
  error.value = null

  try {
    if (!recaptchaSiteKey) {
      throw new Error('reCAPTCHA is not configured')
    }

    if (!recaptchaToken.value) {
      throw new Error('Please complete the reCAPTCHA challenge.')
    }

    await authStore.requestPasswordReset(email.value, recaptchaToken.value)
    success.value = true
  } catch (err) {
    error.value =
      err.response?.data?.message || err.message || 'Failed to send reset email. Please try again.'
  } finally {
    loading.value = false
    resetRecaptcha()
  }
}

function resetForm() {
  success.value = false
  email.value = ''
  error.value = null
}
</script>
