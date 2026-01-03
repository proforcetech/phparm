<template>
  <div>
    <div class="mb-4 flex items-center justify-between">
      <h2 class="text-lg font-semibold text-gray-900">Payments</h2>
      <Button :loading="saving" @click="save">Save Changes</Button>
    </div>

    <Alert v-if="message" variant="success" class="mb-4">{{ message }}</Alert>
    <Alert v-if="error" variant="danger" class="mb-4">{{ error }}</Alert>

    <div v-if="loading" class="text-gray-500">Loading settings...</div>

    <div v-else class="space-y-6">
      <Card>
        <h3 class="text-md font-medium text-gray-900 mb-4">Payment URLs</h3>
        <div class="grid grid-cols-1 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700">Success URL</label>
            <Input v-model="form.successUrl" placeholder="https://app.example.com/payment/success" class="mt-1" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700">Cancel URL</label>
            <Input v-model="form.cancelUrl" placeholder="https://app.example.com/payment/cancel" class="mt-1" />
          </div>
        </div>
      </Card>

      <Card>
        <h3 class="text-md font-medium text-gray-900 mb-4">Stripe</h3>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700">Publishable Key</label>
            <Input v-model="form.stripePublic" placeholder="pk_live_" class="mt-1" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700">Secret Key</label>
            <Input v-model="form.stripeSecret" placeholder="sk_live_" class="mt-1" />
          </div>
          <div class="md:col-span-2">
            <label class="block text-sm font-medium text-gray-700">Webhook Secret</label>
            <Input v-model="form.stripeWebhook" placeholder="whsec_" class="mt-1" />
          </div>
        </div>
      </Card>

      <Card>
        <h3 class="text-md font-medium text-gray-900 mb-4">Square</h3>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700">Access Token</label>
            <Input v-model="form.squareToken" placeholder="sq0atp-" class="mt-1" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700">Webhook Signature Key</label>
            <Input v-model="form.squareSignature" placeholder="sig_key" class="mt-1" />
          </div>
        </div>
      </Card>

      <Card>
        <h3 class="text-md font-medium text-gray-900 mb-4">PayPal</h3>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700">Client ID</label>
            <Input v-model="form.paypalClientId" placeholder="paypal client id" class="mt-1" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700">Client Secret</label>
            <Input v-model="form.paypalClientSecret" placeholder="paypal secret" class="mt-1" />
          </div>
          <div class="md:col-span-2">
            <label class="block text-sm font-medium text-gray-700">Webhook ID</label>
            <Input v-model="form.paypalWebhook" placeholder="WH-XXXX" class="mt-1" />
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
import { fetchSettings, saveSettings } from '@/services/settings.service'

const loading = ref(true)
const saving = ref(false)
const message = ref('')
const error = ref('')

const form = reactive({
  successUrl: '',
  cancelUrl: '',
  stripePublic: '',
  stripeSecret: '',
  stripeWebhook: '',
  squareToken: '',
  squareSignature: '',
  paypalClientId: '',
  paypalClientSecret: '',
  paypalWebhook: '',
})

const getSetting = (settings, key, fallback = null) => {
  return settings?.[key]?.value ?? fallback
}

const hydrate = async () => {
  loading.value = true
  error.value = ''
  try {
    const settings = await fetchSettings()
    form.successUrl = getSetting(settings, 'payments.urls.success', '')
    form.cancelUrl = getSetting(settings, 'payments.urls.cancel', '')
    form.stripePublic = getSetting(settings, 'integrations.stripe.public_key', '')
    form.stripeSecret = getSetting(settings, 'integrations.stripe.secret_key', '')
    form.stripeWebhook = getSetting(settings, 'integrations.stripe.webhook_secret', '')
    form.squareToken = getSetting(settings, 'integrations.square.token', '')
    form.squareSignature = getSetting(settings, 'integrations.square.webhook_signature_key', '')
    form.paypalClientId = getSetting(settings, 'integrations.paypal.client_id', '')
    form.paypalClientSecret = getSetting(settings, 'integrations.paypal.client_secret', '')
    form.paypalWebhook = getSetting(settings, 'integrations.paypal.webhook_id', '')
  } catch (e) {
    error.value = e?.message || 'Unable to load settings.'
  } finally {
    loading.value = false
  }
}

const save = async () => {
  saving.value = true
  message.value = ''
  error.value = ''

  const payload = {
    'payments.urls.success': form.successUrl,
    'payments.urls.cancel': form.cancelUrl,
    'integrations.stripe.public_key': form.stripePublic,
    'integrations.stripe.secret_key': form.stripeSecret,
    'integrations.stripe.webhook_secret': form.stripeWebhook,
    'integrations.square.token': form.squareToken,
    'integrations.square.webhook_signature_key': form.squareSignature,
    'integrations.paypal.client_id': form.paypalClientId,
    'integrations.paypal.client_secret': form.paypalClientSecret,
    'integrations.paypal.webhook_id': form.paypalWebhook,
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
