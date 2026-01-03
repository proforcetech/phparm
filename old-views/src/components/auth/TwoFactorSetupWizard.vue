<template>
  <div class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50 flex items-center justify-center">
    <div class="relative bg-white rounded-lg shadow-xl max-w-2xl w-full mx-4">
      <!-- Header -->
      <div class="bg-primary-600 text-white px-6 py-4 rounded-t-lg">
        <h3 class="text-lg font-semibold">
          Two-Factor Authentication Setup Required
        </h3>
        <p class="text-sm text-primary-100 mt-1">
          Your administrator has required you to set up two-factor authentication
        </p>
      </div>

      <!-- Content -->
      <div class="p-6">
        <!-- Step 1: QR Code -->
        <div v-if="currentStep === 'setup' && !showRecoveryCodes" class="space-y-4">
          <div class="text-center">
            <h4 class="text-lg font-medium text-gray-900 mb-2">Scan the QR Code</h4>
            <p class="text-sm text-gray-600 mb-4">
              Use an authenticator app like Google Authenticator, Authy, or 1Password to scan this QR code
            </p>
          </div>

          <!-- QR Code Display -->
          <div v-if="qrCodeUrl" class="flex justify-center p-6 bg-gray-50 rounded-lg">
            <img :src="`https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=${encodeURIComponent(qrCodeUrl)}`" alt="2FA QR Code" class="border-4 border-white shadow-lg">
          </div>

          <!-- Manual Entry -->
          <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
            <p class="text-sm font-medium text-blue-900 mb-2">Can't scan the QR code?</p>
            <p class="text-xs text-blue-700 mb-2">Enter this code manually in your authenticator app:</p>
            <div class="flex items-center justify-between bg-white border border-blue-300 rounded px-3 py-2">
              <code class="text-sm font-mono text-gray-900">{{ secret }}</code>
              <button
                @click="copySecret"
                class="ml-2 text-blue-600 hover:text-blue-800 text-sm font-medium"
              >
                {{ copied ? 'Copied!' : 'Copy' }}
              </button>
            </div>
          </div>

          <!-- Verification Code Input -->
          <div class="mt-6">
            <label for="verification-code" class="block text-sm font-medium text-gray-700 mb-2">
              Enter the 6-digit code from your authenticator app
            </label>
            <input
              id="verification-code"
              v-model="verificationCode"
              type="text"
              inputmode="numeric"
              maxlength="6"
              placeholder="000000"
              class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500 text-center text-2xl tracking-widest"
              :disabled="loading"
              @keyup.enter="completeSetup"
            >
          </div>

          <!-- Error Message -->
          <div v-if="error" class="bg-red-50 border border-red-200 rounded-lg p-4">
            <p class="text-sm text-red-800">{{ error }}</p>
          </div>

          <!-- Action Buttons -->
          <div class="flex justify-end space-x-3 mt-6 pt-6 border-t">
            <button
              @click="completeSetup"
              :disabled="loading || verificationCode.length !== 6"
              class="px-4 py-2 bg-primary-600 text-white rounded-md hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500 disabled:opacity-50 disabled:cursor-not-allowed"
            >
              {{ loading ? 'Verifying...' : 'Verify and Enable 2FA' }}
            </button>
          </div>
        </div>

        <!-- Step 2: Recovery Codes -->
        <div v-if="showRecoveryCodes" class="space-y-4">
          <div class="text-center mb-4">
            <div class="mx-auto flex items-center justify-center h-12 w-12 rounded-full bg-green-100 mb-4">
              <svg class="h-6 w-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
              </svg>
            </div>
            <h4 class="text-lg font-medium text-gray-900">2FA Successfully Enabled!</h4>
            <p class="text-sm text-gray-600 mt-2">
              Save these recovery codes in a safe place. You can use them to access your account if you lose your authenticator device.
            </p>
          </div>

          <!-- Recovery Codes Display -->
          <div class="bg-yellow-50 border-2 border-yellow-400 rounded-lg p-4">
            <p class="text-sm font-semibold text-yellow-900 mb-3">⚠️ Recovery Codes - Save These Now!</p>
            <div class="bg-white rounded border border-yellow-300 p-4">
              <div class="grid grid-cols-2 gap-2 font-mono text-sm">
                <div v-for="(code, index) in recoveryCodes" :key="index" class="text-gray-900">
                  {{ index + 1 }}. {{ code }}
                </div>
              </div>
            </div>
            <button
              @click="copyRecoveryCodes"
              class="mt-3 w-full px-4 py-2 bg-yellow-600 text-white rounded-md hover:bg-yellow-700 focus:outline-none focus:ring-2 focus:ring-yellow-500"
            >
              {{ codesCopied ? 'Copied!' : 'Copy All Recovery Codes' }}
            </button>
          </div>

          <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 text-sm text-blue-800">
            <p class="font-medium mb-1">Important:</p>
            <ul class="list-disc list-inside space-y-1">
              <li>Each recovery code can only be used once</li>
              <li>Store them in a secure password manager or print them</li>
              <li>You won't be able to see these codes again</li>
            </ul>
          </div>

          <!-- Continue Button -->
          <div class="flex justify-end mt-6 pt-6 border-t">
            <button
              @click="finishSetup"
              class="px-6 py-2 bg-primary-600 text-white rounded-md hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500"
            >
              Continue to Dashboard
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { authService } from '@/services/auth.service'
import { useAuthStore } from '@/stores/auth'
import router from '@/router'

const authStore = useAuthStore()

const currentStep = ref('setup')
const qrCodeUrl = ref('')
const secret = ref('')
const verificationCode = ref('')
const recoveryCodes = ref([])
const showRecoveryCodes = ref(false)

const loading = ref(false)
const error = ref(null)
const copied = ref(false)
const codesCopied = ref(false)

onMounted(async () => {
  try {
    loading.value = true
    const data = await authService.initiateTwoFactorSetup()
    qrCodeUrl.value = data.qr_code_url
    secret.value = data.secret
  } catch (err) {
    error.value = err.response?.data?.message || 'Failed to initialize 2FA setup'
  } finally {
    loading.value = false
  }
})

async function completeSetup() {
  if (verificationCode.value.length !== 6) {
    return
  }

  loading.value = true
  error.value = null

  try {
    const data = await authService.completeTwoFactorSetup(verificationCode.value)
    recoveryCodes.value = data.recovery_codes
    showRecoveryCodes.value = true

    // Update user in store
    if (data.user) {
      authStore.user.two_factor_enabled = data.user.two_factor_enabled
      authStore.user.two_factor_type = data.user.two_factor_type
      authStore.user.two_factor_setup_pending = data.user.two_factor_setup_pending
      localStorage.setItem('user', JSON.stringify(authStore.user))
    }
  } catch (err) {
    error.value = err.response?.data?.message || 'Invalid verification code. Please try again.'
    verificationCode.value = ''
  } finally {
    loading.value = false
  }
}

function copySecret() {
  navigator.clipboard.writeText(secret.value)
  copied.value = true
  setTimeout(() => {
    copied.value = false
  }, 2000)
}

function copyRecoveryCodes() {
  const codes = recoveryCodes.value.map((code, index) => `${index + 1}. ${code}`).join('\n')
  navigator.clipboard.writeText(codes)
  codesCopied.value = true
  setTimeout(() => {
    codesCopied.value = false
  }, 2000)
}

function finishSetup() {
  // Redirect based on user role
  if (authStore.isCustomer) {
    router.push('/portal')
  } else {
    router.push('/cp/dashboard')
  }
}
</script>
