<template>
  <div>
    <div class="mb-4 flex items-center justify-between">
      <h2 class="text-lg font-semibold text-gray-900">Integrations</h2>
      <Button :loading="saving" @click="save">Save Changes</Button>
    </div>

    <Alert v-if="message" variant="success" class="mb-4">{{ message }}</Alert>
    <Alert v-if="error" variant="danger" class="mb-4">{{ error }}</Alert>

    <div v-if="loading" class="text-gray-500">Loading settings...</div>

    <div v-else class="space-y-6">
      <Card>
        <h3 class="text-md font-medium text-gray-900 mb-4">reCAPTCHA</h3>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div class="flex items-center space-x-2 md:col-span-2">
            <input
              id="recaptcha-enabled"
              v-model="form.recaptchaEnabled"
              type="checkbox"
              class="h-4 w-4 text-indigo-600 border-gray-300 rounded"
            />
            <label for="recaptcha-enabled" class="block text-sm font-medium text-gray-700">Enable reCAPTCHA</label>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700">Site Key</label>
            <Input v-model="form.recaptchaSiteKey" placeholder="site key" class="mt-1" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700">Secret Key</label>
            <Input v-model="form.recaptchaSecretKey" placeholder="secret key" class="mt-1" />
          </div>
        </div>
      </Card>

      <Card>
        <h3 class="text-md font-medium text-gray-900 mb-4">Zoho</h3>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700">Client ID</label>
            <Input v-model="form.zohoClientId" placeholder="Zoho client id" class="mt-1" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700">Client Secret</label>
            <Input v-model="form.zohoClientSecret" placeholder="Zoho client secret" class="mt-1" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700">Refresh Token</label>
            <Input v-model="form.zohoRefreshToken" placeholder="Zoho refresh token" class="mt-1" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700">Org ID</label>
            <Input v-model="form.zohoOrgId" placeholder="Zoho org id" class="mt-1" />
          </div>
        </div>
      </Card>

      <Card>
        <h3 class="text-md font-medium text-gray-900 mb-4">PartsTech</h3>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700">API Base URL</label>
            <Input v-model="form.partsTechBase" placeholder="https://api.partstech.com" class="mt-1" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700">API Key</label>
            <Input v-model="form.partsTechKey" placeholder="PartsTech key" class="mt-1" />
          </div>
          <div class="md:col-span-2">
            <label class="block text-sm font-medium text-gray-700">Markup Tiers (JSON)</label>
            <Textarea
              v-model="form.partsTechMarkup"
              :rows="3"
              placeholder='[{"threshold":0,"markup":0.2}]'
              class="mt-1"
            />
          </div>
        </div>
      </Card>
    </div>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import Alert from '@/components/ui/Alert.vue'
import Button from '@/components/ui/Button.vue'
import Card from '@/components/ui/Card.vue'
import Input from '@/components/ui/Input.vue'
import Textarea from '@/components/ui/Textarea.vue'
import { fetchSettings, saveSettings } from '@/services/settings.service'

const loading = ref(true)
const saving = ref(false)
const message = ref('')
const error = ref('')

const form = reactive({
  recaptchaEnabled: false,
  recaptchaSiteKey: '',
  recaptchaSecretKey: '',
  zohoClientId: '',
  zohoClientSecret: '',
  zohoRefreshToken: '',
  zohoOrgId: '',
  partsTechBase: '',
  partsTechKey: '',
  partsTechMarkup: '',
})

const getSetting = (settings, key, fallback = null) => {
  return settings?.[key]?.value ?? fallback
}

const hydrate = async () => {
  loading.value = true
  error.value = ''
  try {
    const settings = await fetchSettings()
    form.recaptchaEnabled = !!getSetting(settings, 'integrations.recaptcha.enabled', false)
    form.recaptchaSiteKey = getSetting(settings, 'integrations.recaptcha.site_key', '')
    form.recaptchaSecretKey = getSetting(settings, 'integrations.recaptcha.secret_key', '')
    form.zohoClientId = getSetting(settings, 'integrations.zoho.client_id', '')
    form.zohoClientSecret = getSetting(settings, 'integrations.zoho.client_secret', '')
    form.zohoRefreshToken = getSetting(settings, 'integrations.zoho.refresh_token', '')
    form.zohoOrgId = getSetting(settings, 'integrations.zoho.org_id', '')
    form.partsTechBase = getSetting(settings, 'integrations.partstech.api_base', '')
    form.partsTechKey = getSetting(settings, 'integrations.partstech.api_key', '')
    const markup = getSetting(settings, 'integrations.partstech.markup_tiers', [])
    form.partsTechMarkup = markup && markup.length ? JSON.stringify(markup, null, 2) : ''
  } catch (e) {
    error.value = e?.message || 'Unable to load settings.'
  } finally {
    loading.value = false
  }
}

const parseMarkup = () => {
  if (!form.partsTechMarkup) {
    return []
  }
  try {
    const parsed = JSON.parse(form.partsTechMarkup)
    return Array.isArray(parsed) ? parsed : []
  } catch (e) {
    throw new Error('PartsTech markup tiers must be valid JSON.')
  }
}

const save = async () => {
  saving.value = true
  message.value = ''
  error.value = ''

  let markupTiers = []
  try {
    markupTiers = parseMarkup()
  } catch (e) {
    saving.value = false
    error.value = e.message
    return
  }

  const payload = {
    'integrations.recaptcha.enabled': !!form.recaptchaEnabled,
    'integrations.recaptcha.site_key': form.recaptchaSiteKey,
    'integrations.recaptcha.secret_key': form.recaptchaSecretKey,
    'integrations.zoho.client_id': form.zohoClientId,
    'integrations.zoho.client_secret': form.zohoClientSecret,
    'integrations.zoho.refresh_token': form.zohoRefreshToken,
    'integrations.zoho.org_id': form.zohoOrgId,
    'integrations.partstech.api_base': form.partsTechBase,
    'integrations.partstech.api_key': form.partsTechKey,
    'integrations.partstech.markup_tiers': markupTiers,
  }

  try {
    await saveSettings(payload)
    message.value = 'Settings saved successfully.'
  } catch (e) {
    error.value = e?.response?.data?.message || e?.message || 'Failed to save settings.'
  } finally {
    saving.value = false
  }
}

onMounted(hydrate)
</script>
