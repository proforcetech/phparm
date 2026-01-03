<template>
  <div>
    <div class="mb-6">
      <h1 class="text-2xl font-bold text-gray-900">My Profile</h1>
      <p class="mt-1 text-sm text-gray-500">Update your account details and security preferences.</p>
    </div>

    <Alert v-if="successMessage" variant="success" class="mb-4" @close="successMessage = ''">
      {{ successMessage }}
    </Alert>

    <Alert v-if="errorMessage" variant="danger" class="mb-4" @close="errorMessage = ''">
      {{ errorMessage }}
    </Alert>

    <div v-if="loading" class="flex justify-center py-12">
      <Loading size="xl" text="Loading profile..." />
    </div>

    <div v-else class="space-y-6">
      <Card>
        <template #header>
          <h3 class="text-lg font-medium text-gray-900">Account Information</h3>
        </template>

        <div class="space-y-6">
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <Input
              v-model="form.name"
              label="Full Name *"
              placeholder="Jane Doe"
              required
              :error="errors.name"
            />
            <Input
              v-model="form.email"
              type="email"
              label="Email"
              placeholder="jane@example.com"
              disabled
              helper-text="Contact an administrator to change your email address."
              :error="errors.email"
            />
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">New Password</label>
            <Input
              v-model="form.password"
              type="password"
              placeholder="Enter a new password"
              :error="errors.password"
              autocomplete="new-password"
            />
            <p class="mt-1 text-xs text-gray-500">Minimum 12 characters</p>
          </div>
        </div>
      </Card>

      <Card>
        <template #header>
          <h3 class="text-lg font-medium text-gray-900">Two-Factor Authentication</h3>
        </template>

        <div class="space-y-4">
          <div class="flex items-center gap-2">
            <Badge :variant="isTwoFactorEnabled ? 'success' : 'warning'" size="sm">
              {{ isTwoFactorEnabled ? 'Enabled' : 'Not enabled' }}
            </Badge>
            <span class="text-sm text-gray-600">
              {{ twoFactorLabel }}
            </span>
          </div>

          <p class="text-sm text-gray-600">
            Add an extra layer of security to your account by enabling two-factor authentication.
          </p>

          <div v-if="!isTwoFactorEnabled" class="space-y-4">
            <Button variant="secondary" :loading="twoFactorLoading" @click="startTwoFactorSetup">
              Enable 2FA
            </Button>

            <div v-if="twoFactorSetupOpen" class="rounded-lg border border-gray-200 p-4 space-y-4">
              <div v-if="qrCodeUrl" class="flex justify-center bg-gray-50 rounded-lg p-4">
                <img
                  :src="`https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=${encodeURIComponent(qrCodeUrl)}`"
                  alt="2FA QR Code"
                  class="border-4 border-white shadow-lg"
                >
              </div>

              <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                <p class="text-sm font-medium text-blue-900 mb-2">Manual setup</p>
                <p class="text-xs text-blue-700 mb-2">Enter this code in your authenticator app:</p>
                <div class="flex items-center justify-between bg-white border border-blue-300 rounded px-3 py-2">
                  <code class="text-sm font-mono text-gray-900">{{ secret }}</code>
                  <button
                    class="ml-2 text-blue-600 hover:text-blue-800 text-sm font-medium"
                    type="button"
                    @click="copySecret"
                  >
                    {{ secretCopied ? 'Copied!' : 'Copy' }}
                  </button>
                </div>
              </div>

              <Input
                v-model="verificationCode"
                label="Verification code"
                placeholder="000000"
                helper-text="Enter the 6-digit code from your authenticator app."
              />

              <p v-if="twoFactorError" class="text-sm text-red-600">{{ twoFactorError }}</p>
              <p v-if="twoFactorSuccess" class="text-sm text-green-600">{{ twoFactorSuccess }}</p>

              <div class="flex flex-wrap gap-2">
                <Button
                  variant="primary"
                  :loading="twoFactorLoading"
                  :disabled="verificationCode.length !== 6"
                  @click="completeTwoFactorSetup"
                >
                  Verify & Enable
                </Button>
                <Button variant="outline" type="button" @click="cancelTwoFactorSetup">Cancel</Button>
              </div>
            </div>
          </div>

          <div v-if="showRecoveryCodes" class="rounded-lg border border-yellow-300 bg-yellow-50 p-4 space-y-3">
            <p class="text-sm font-semibold text-yellow-900">Save your recovery codes</p>
            <div class="bg-white rounded border border-yellow-300 p-4">
              <div class="grid grid-cols-2 gap-2 font-mono text-sm">
                <div v-for="(code, index) in recoveryCodes" :key="index" class="text-gray-900">
                  {{ index + 1 }}. {{ code }}
                </div>
              </div>
            </div>
            <Button variant="secondary" type="button" @click="copyRecoveryCodes">
              {{ recoveryCodesCopied ? 'Copied!' : 'Copy Recovery Codes' }}
            </Button>
          </div>
        </div>
      </Card>

      <div class="flex justify-end">
        <Button :loading="saving" @click="handleSubmit">Save Profile</Button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import Alert from '@/components/ui/Alert.vue'
import Badge from '@/components/ui/Badge.vue'
import Button from '@/components/ui/Button.vue'
import Card from '@/components/ui/Card.vue'
import Input from '@/components/ui/Input.vue'
import Loading from '@/components/ui/Loading.vue'
import { authService } from '@/services/auth.service'
import userService from '@/services/user.service'
import { useAuthStore } from '@/stores/auth'

const authStore = useAuthStore()

const loading = ref(true)
const saving = ref(false)
const successMessage = ref('')
const errorMessage = ref('')

const form = reactive({
  name: '',
  email: '',
  password: '',
})

const errors = reactive({
  name: '',
  email: '',
  password: '',
})

const twoFactorLoading = ref(false)
const twoFactorSetupOpen = ref(false)
const twoFactorError = ref('')
const twoFactorSuccess = ref('')
const qrCodeUrl = ref('')
const secret = ref('')
const secretCopied = ref(false)
const verificationCode = ref('')
const recoveryCodes = ref([])
const recoveryCodesCopied = ref(false)
const showRecoveryCodes = ref(false)

const isTwoFactorEnabled = computed(() => !!authStore.user?.two_factor_enabled)
const twoFactorLabel = computed(() => {
  const type = authStore.user?.two_factor_type
  if (!authStore.user?.two_factor_enabled) {
    return 'Protect your account with an authenticator app.'
  }

  const labels = {
    totp: 'Authenticator app enabled.',
    sms: 'SMS-based authentication enabled.',
    email: 'Email-based authentication enabled.'
  }

  return labels[type] || 'Two-factor authentication is enabled.'
})

onMounted(async () => {
  await loadProfile()
})

async function loadProfile() {
  loading.value = true
  errorMessage.value = ''

  try {
    if (!authStore.user) {
      await authStore.fetchCurrentUser()
    }

    if (authStore.user) {
      form.name = authStore.user.name || ''
      form.email = authStore.user.email || ''
    }
  } catch (error) {
    errorMessage.value = error.response?.data?.message || 'Unable to load profile.'
  } finally {
    loading.value = false
  }
}

function validateForm() {
  let isValid = true

  Object.keys(errors).forEach(key => errors[key] = '')

  if (!form.name) {
    errors.name = 'Name is required'
    isValid = false
  }

  if (!form.email) {
    errors.email = 'Email is required'
    isValid = false
  } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email)) {
    errors.email = 'Invalid email format'
    isValid = false
  }

  if (form.password && form.password.length < 12) {
    errors.password = 'Password must be at least 12 characters'
    isValid = false
  }

  return isValid
}

async function handleSubmit() {
  if (!validateForm()) {
    errorMessage.value = 'Please fix the errors in the form.'
    return
  }

  saving.value = true
  successMessage.value = ''
  errorMessage.value = ''

  try {
    const payload = {
      name: form.name,
    }

    if (form.password) {
      payload.password = form.password
    }

    const data = await userService.updateProfile(payload)

    if (data.user) {
      authStore.user = data.user
      localStorage.setItem('user', JSON.stringify(data.user))
      form.name = data.user.name || ''
      form.email = data.user.email || form.email
    }

    form.password = ''
    successMessage.value = data.message || 'Profile updated successfully.'
  } catch (error) {
    errorMessage.value = error.response?.data?.message || 'Failed to update profile.'

    if (error.response?.data?.errors) {
      Object.assign(errors, error.response.data.errors)
    }
  } finally {
    saving.value = false
  }
}

async function startTwoFactorSetup() {
  twoFactorLoading.value = true
  twoFactorSetupOpen.value = true
  twoFactorError.value = ''
  twoFactorSuccess.value = ''
  showRecoveryCodes.value = false
  recoveryCodes.value = []
  verificationCode.value = ''

  try {
    const data = await authService.initiateTwoFactorSetup()
    qrCodeUrl.value = data.qr_code_url
    secret.value = data.secret
  } catch (error) {
    twoFactorError.value = error.response?.data?.message || 'Failed to start 2FA setup.'
  } finally {
    twoFactorLoading.value = false
  }
}

async function completeTwoFactorSetup() {
  if (verificationCode.value.length !== 6) {
    twoFactorError.value = 'Enter the 6-digit verification code.'
    return
  }

  twoFactorLoading.value = true
  twoFactorError.value = ''

  try {
    const data = await authService.completeTwoFactorSetup(verificationCode.value)
    recoveryCodes.value = data.recovery_codes || []
    showRecoveryCodes.value = recoveryCodes.value.length > 0
    twoFactorSuccess.value = data.message || 'Two-factor authentication enabled.'
    verificationCode.value = ''

    if (data.user) {
      authStore.user = data.user
      localStorage.setItem('user', JSON.stringify(data.user))
    }
  } catch (error) {
    twoFactorError.value = error.response?.data?.message || 'Invalid verification code. Please try again.'
  } finally {
    twoFactorLoading.value = false
  }
}

function cancelTwoFactorSetup() {
  twoFactorSetupOpen.value = false
  twoFactorError.value = ''
  twoFactorSuccess.value = ''
  verificationCode.value = ''
  qrCodeUrl.value = ''
  secret.value = ''
}

function copySecret() {
  if (!secret.value) return
  navigator.clipboard.writeText(secret.value)
  secretCopied.value = true
  setTimeout(() => {
    secretCopied.value = false
  }, 2000)
}

function copyRecoveryCodes() {
  const codes = recoveryCodes.value.map((code, index) => `${index + 1}. ${code}`).join('\n')
  navigator.clipboard.writeText(codes)
  recoveryCodesCopied.value = true
  setTimeout(() => {
    recoveryCodesCopied.value = false
  }, 2000)
}
</script>
