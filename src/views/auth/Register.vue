<template>
  <div class="min-h-screen flex items-center justify-center bg-gray-50 py-12 px-4 sm:px-6 lg:px-8">
    <div class="max-w-md w-full space-y-8">
      <div>
        <h2 class="mt-6 text-center text-3xl font-extrabold text-gray-900">
          Register Staff Member
        </h2>
        <p class="mt-2 text-center text-sm text-gray-600">
          Create a new staff account
        </p>
      </div>

      <form v-if="!success" class="mt-8 space-y-6" @submit.prevent="handleSubmit">
        <div v-if="error" class="rounded-md bg-red-50 p-4">
          <p class="text-sm text-red-800">{{ error }}</p>
        </div>

        <div class="space-y-4">
          <div class="grid grid-cols-2 gap-4">
            <div>
              <label for="first-name" class="block text-sm font-medium text-gray-700">
                First Name
              </label>
              <input
                id="first-name"
                v-model="form.first_name"
                name="first-name"
                type="text"
                required
                class="mt-1 appearance-none relative block w-full px-3 py-2 border border-gray-300 placeholder-gray-500 text-gray-900 rounded-md focus:outline-none focus:ring-primary-500 focus:border-primary-500 sm:text-sm"
                placeholder="John"
              />
            </div>

            <div>
              <label for="last-name" class="block text-sm font-medium text-gray-700">
                Last Name
              </label>
              <input
                id="last-name"
                v-model="form.last_name"
                name="last-name"
                type="text"
                required
                class="mt-1 appearance-none relative block w-full px-3 py-2 border border-gray-300 placeholder-gray-500 text-gray-900 rounded-md focus:outline-none focus:ring-primary-500 focus:border-primary-500 sm:text-sm"
                placeholder="Doe"
              />
            </div>
          </div>

          <div>
            <label for="email" class="block text-sm font-medium text-gray-700">
              Email Address
            </label>
            <input
              id="email"
              v-model="form.email"
              name="email"
              type="email"
              autocomplete="email"
              required
              class="mt-1 appearance-none relative block w-full px-3 py-2 border border-gray-300 placeholder-gray-500 text-gray-900 rounded-md focus:outline-none focus:ring-primary-500 focus:border-primary-500 sm:text-sm"
              placeholder="john.doe@example.com"
            />
          </div>

          <div>
            <label for="role" class="block text-sm font-medium text-gray-700">
              Role
            </label>
            <select
              id="role"
              v-model="form.role"
              name="role"
              required
              class="mt-1 block w-full px-3 py-2 border border-gray-300 bg-white rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500 sm:text-sm"
            >
              <option value="">Select a role</option>
              <option value="admin">Administrator</option>
              <option value="manager">Manager</option>
              <option value="technician">Technician</option>
              <option value="receptionist">Receptionist</option>
            </select>
          </div>

          <div>
            <label for="password" class="block text-sm font-medium text-gray-700">
              Password
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
              placeholder="Enter password (min 8 characters)"
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
              placeholder="Confirm password"
              :class="{ 'border-red-500': form.password && form.passwordConfirm && form.password !== form.passwordConfirm }"
            />
            <p v-if="form.password && form.passwordConfirm && form.password !== form.passwordConfirm" class="mt-1 text-sm text-red-600">
              Passwords do not match
            </p>
          </div>

          <div class="flex items-center">
            <input
              id="send-email"
              v-model="form.sendEmail"
              name="send-email"
              type="checkbox"
              class="h-4 w-4 text-primary-600 focus:ring-primary-500 border-gray-300 rounded"
            />
            <label for="send-email" class="ml-2 block text-sm text-gray-900">
              Send welcome email with login credentials
            </label>
          </div>
        </div>

        <div class="flex gap-3">
          <button
            type="button"
            @click="$router.back()"
            class="flex-1 py-2 px-4 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500"
          >
            Cancel
          </button>
          <button
            type="submit"
            :disabled="loading || !isFormValid"
            class="flex-1 py-2 px-4 border border-transparent text-sm font-medium rounded-md text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 disabled:opacity-50 disabled:cursor-not-allowed"
          >
            <span v-if="loading">Creating account...</span>
            <span v-else>Create Account</span>
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
                Staff account created successfully!
              </h3>
              <div class="mt-2 text-sm text-green-700">
                <p>
                  {{ form.first_name }} {{ form.last_name }} has been registered as {{ form.role }}.
                </p>
                <p v-if="form.sendEmail" class="mt-1">
                  A welcome email has been sent to {{ form.email }}.
                </p>
              </div>
            </div>
          </div>
        </div>

        <div class="flex gap-3">
          <button
            @click="resetForm"
            class="flex-1 py-2 px-4 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500"
          >
            Register Another
          </button>
          <button
            @click="$router.push('/dashboard')"
            class="flex-1 py-2 px-4 border border-transparent text-sm font-medium rounded-md text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500"
          >
            Go to Dashboard
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { useAuthStore } from '@/stores/auth'

const authStore = useAuthStore()

const form = ref({
  first_name: '',
  last_name: '',
  email: '',
  role: '',
  password: '',
  passwordConfirm: '',
  sendEmail: true,
})

const loading = ref(false)
const error = ref(null)
const success = ref(false)

const isFormValid = computed(() => {
  return (
    form.value.first_name &&
    form.value.last_name &&
    form.value.email &&
    form.value.role &&
    form.value.password.length >= 8 &&
    form.value.password === form.value.passwordConfirm
  )
})

async function handleSubmit() {
  if (!isFormValid.value) {
    error.value = 'Please fill in all required fields'
    return
  }

  loading.value = true
  error.value = null

  try {
    const { passwordConfirm, ...userData } = form.value
    await authStore.register(userData)
    success.value = true
  } catch (err) {
    error.value = err.response?.data?.message || 'Failed to create account. Email may already be in use.'
  } finally {
    loading.value = false
  }
}

function resetForm() {
  form.value = {
    first_name: '',
    last_name: '',
    email: '',
    role: '',
    password: '',
    passwordConfirm: '',
    sendEmail: true,
  }
  success.value = false
  error.value = null
}
</script>
