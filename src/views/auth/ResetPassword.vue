<template>
  <div class="min-h-screen flex items-center justify-center bg-gray-50 py-12 px-4 sm:px-6 lg:px-8">
    <div class="max-w-md w-full space-y-8">
      <div>
        <h2 class="mt-6 text-center text-3xl font-extrabold text-gray-900">
          Set new password
        </h2>
        <p class="mt-2 text-center text-sm text-gray-600">
          Enter your new password below
        </p>
      </div>

      <form v-if="!success" class="mt-8 space-y-6" @submit.prevent="handleSubmit">
        <div v-if="error" class="rounded-md bg-red-50 p-4">
          <p class="text-sm text-red-800">{{ error }}</p>
        </div>

        <div class="space-y-4">
          <div>
            <label for="password" class="block text-sm font-medium text-gray-700">
              New Password
            </label>
            <input
              id="password"
              v-model="form.password"
              name="password"
              type="password"
              autocomplete="new-password"
              required
              minlength="8"
              class="mt-1 appearance-none relative block w-full px-3 py-2 border border-gray-300 placeholder-gray-500 text-gray-900 rounded-md focus:outline-none focus:ring-primary-500 focus:border-primary-500 sm:text-sm"
              placeholder="Enter new password (min 8 characters)"
            />
          </div>

          <div>
            <label for="password-confirm" class="block text-sm font-medium text-gray-700">
              Confirm Password
            </label>
            <input
              id="password-confirm"
              v-model="form.passwordConfirm"
              name="password-confirm"
              type="password"
              autocomplete="new-password"
              required
              minlength="8"
              class="mt-1 appearance-none relative block w-full px-3 py-2 border border-gray-300 placeholder-gray-500 text-gray-900 rounded-md focus:outline-none focus:ring-primary-500 focus:border-primary-500 sm:text-sm"
              placeholder="Confirm new password"
              :class="{ 'border-red-500': form.password && form.passwordConfirm && form.password !== form.passwordConfirm }"
            />
            <p v-if="form.password && form.passwordConfirm && form.password !== form.passwordConfirm" class="mt-1 text-sm text-red-600">
              Passwords do not match
            </p>
          </div>
        </div>

        <div class="bg-gray-50 px-4 py-3 rounded-md">
          <p class="text-xs text-gray-600">
            Password requirements:
          </p>
          <ul class="mt-2 text-xs text-gray-600 list-disc list-inside space-y-1">
            <li :class="{ 'text-green-600': form.password.length >= 8 }">
              At least 8 characters long
            </li>
            <li :class="{ 'text-green-600': /[A-Z]/.test(form.password) }">
              Contains uppercase letter
            </li>
            <li :class="{ 'text-green-600': /[a-z]/.test(form.password) }">
              Contains lowercase letter
            </li>
            <li :class="{ 'text-green-600': /[0-9]/.test(form.password) }">
              Contains number
            </li>
          </ul>
        </div>

        <div>
          <button
            type="submit"
            :disabled="loading || !isPasswordValid"
            class="group relative w-full flex justify-center py-2 px-4 border border-transparent text-sm font-medium rounded-md text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 disabled:opacity-50 disabled:cursor-not-allowed"
          >
            <span v-if="loading">Resetting password...</span>
            <span v-else>Reset password</span>
          </button>
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
                Password reset successful!
              </h3>
              <div class="mt-2 text-sm text-green-700">
                <p>
                  Your password has been successfully reset. You can now log in with your new password.
                </p>
              </div>
            </div>
          </div>
        </div>

        <div class="text-center">
          <router-link
            to="/login"
            class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500"
          >
            Go to login
          </router-link>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const route = useRoute()
const authStore = useAuthStore()

const form = ref({
  password: '',
  passwordConfirm: '',
})

const loading = ref(false)
const error = ref(null)
const success = ref(false)

const isPasswordValid = computed(() => {
  return (
    form.value.password.length >= 8 &&
    form.value.password === form.value.passwordConfirm &&
    /[A-Z]/.test(form.value.password) &&
    /[a-z]/.test(form.value.password) &&
    /[0-9]/.test(form.value.password)
  )
})

async function handleSubmit() {
  if (!isPasswordValid.value) {
    error.value = 'Please meet all password requirements'
    return
  }

  loading.value = true
  error.value = null

  try {
    const token = route.params.token
    await authStore.resetPassword(token, form.value.password)
    success.value = true
  } catch (err) {
    error.value = err.response?.data?.message || 'Failed to reset password. The reset link may have expired.'
  } finally {
    loading.value = false
  }
}
</script>
