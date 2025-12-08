<template>
  <div>
    <div class="mb-6 flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Settings</h1>
        <p class="mt-1 text-sm text-gray-500">Manage shop profile, terms, pricing defaults, and integrations.</p>
      </div>
      <Button :loading="saving" @click="save">Save Settings</Button>
    </div>

    <Alert v-if="message" variant="success" class="mb-4">{{ message }}</Alert>
    <Alert v-if="error" variant="danger" class="mb-4">{{ error }}</Alert>

    <div v-if="loading" class="text-gray-500">Loading settings...</div>

    <div v-else class="space-y-6">
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <Card>
          <h2 class="text-lg font-semibold text-gray-900 mb-4">Shop Profile</h2>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-medium text-gray-700">Shop Name</label>
              <Input v-model="form.profile.name" placeholder="Demo Auto Shop" class="mt-1" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700">Notification Email</label>
              <Input v-model="form.profile.email" placeholder="noreply@example.com" class="mt-1" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700">Phone</label>
              <Input v-model="form.profile.phone" placeholder="+1 (555) 123-4567" class="mt-1" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700">Logo URL</label>
              <Input v-model="form.profile.logoUrl" placeholder="https://cdn.example.com/logo.png" class="mt-1" />
            </div>
          </div>
          <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-medium text-gray-700">Street</label>
              <Input v-model="form.profile.address.street" placeholder="123 Main St" class="mt-1" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700">City</label>
              <Input v-model="form.profile.address.city" placeholder="Anytown" class="mt-1" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700">State/Province</label>
              <Input v-model="form.profile.address.state" placeholder="CA" class="mt-1" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700">Postal Code</label>
              <Input v-model="form.profile.address.postal_code" placeholder="90210" class="mt-1" />
            </div>
            <div class="md:col-span-2">
              <label class="block text-sm font-medium text-gray-700">Country</label>
              <Input v-model="form.profile.address.country" placeholder="United States" class="mt-1" />
            </div>
          </div>
        </Card>

        <Card>
          <h2 class="text-lg font-semibold text-gray-900 mb-4">Terms & Documents</h2>
          <div class="space-y-4">
            <div>
              <label class="block text-sm font-medium text-gray-700">Estimate Terms</label>
              <Textarea v-model="form.terms.estimates" rows="4" placeholder="Terms shown on estimates" class="mt-1" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700">Invoice Terms</label>
              <Textarea v-model="form.terms.invoices" rows="4" placeholder="Terms shown on invoices" class="mt-1" />
            </div>
          </div>
        </Card>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <Card>
          <h2 class="text-lg font-semibold text-gray-900 mb-4">Pricing Defaults</h2>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-medium text-gray-700">Tax Rate (%)</label>
              <Input v-model.number="form.pricing.taxRate" type="number" step="0.01" min="0" class="mt-1" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700">Labor Rate (per hour)</label>
              <Input v-model.number="form.pricing.laborRate" type="number" step="0.01" min="0" class="mt-1" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700">Call-out Fee</label>
              <Input v-model.number="form.pricing.callOutFee" type="number" step="0.01" min="0" class="mt-1" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700">Mileage Rate</label>
              <Input v-model.number="form.pricing.mileageRate" type="number" step="0.01" min="0" class="mt-1" />
            </div>
          </div>
        </Card>

        <Card>
          <h2 class="text-lg font-semibold text-gray-900 mb-4">Notifications & Mail</h2>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-medium text-gray-700">From Name</label>
              <Input v-model="form.notifications.fromName" placeholder="Demo Auto Shop" class="mt-1" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700">From Email</label>
              <Input v-model="form.notifications.fromAddress" placeholder="noreply@example.com" class="mt-1" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700">SMS From Number</label>
              <Input v-model="form.notifications.smsNumber" placeholder="+15551234567" class="mt-1" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700">Twilio SID</label>
              <Input v-model="form.notifications.twilioSid" placeholder="ACXXXXXXXXXXXXXXXX" class="mt-1" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700">Twilio Token</label>
              <Input v-model="form.notifications.twilioToken" placeholder="••••••••" class="mt-1" />
            </div>
          </div>
          <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-medium text-gray-700">SMTP Host</label>
              <Input v-model="form.smtp.host" placeholder="smtp.mailgun.org" class="mt-1" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700">SMTP Port</label>
              <Input v-model.number="form.smtp.port" type="number" min="1" class="mt-1" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700">SMTP Username</label>
              <Input v-model="form.smtp.username" placeholder="user" class="mt-1" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700">SMTP Password</label>
              <Input v-model="form.smtp.password" placeholder="••••••••" class="mt-1" />
            </div>
            <div class="md:col-span-2">
              <label class="block text-sm font-medium text-gray-700">SMTP Encryption</label>
              <Input v-model="form.smtp.encryption" placeholder="tls" class="mt-1" />
            </div>
          </div>
        </Card>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <Card>
          <h2 class="text-lg font-semibold text-gray-900 mb-4">Payments</h2>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="md:col-span-2">
              <label class="block text-sm font-medium text-gray-700">Success URL</label>
              <Input v-model="form.payments.successUrl" placeholder="https://app.example.com/payment/success" class="mt-1" />
            </div>
            <div class="md:col-span-2">
              <label class="block text-sm font-medium text-gray-700">Cancel URL</label>
              <Input v-model="form.payments.cancelUrl" placeholder="https://app.example.com/payment/cancel" class="mt-1" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700">Stripe Publishable Key</label>
              <Input v-model="form.payments.stripePublic" placeholder="pk_live_" class="mt-1" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700">Stripe Secret Key</label>
              <Input v-model="form.payments.stripeSecret" placeholder="sk_live_" class="mt-1" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700">Stripe Webhook Secret</label>
              <Input v-model="form.payments.stripeWebhook" placeholder="whsec_" class="mt-1" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700">Square Token</label>
              <Input v-model="form.payments.squareToken" placeholder="sq0atp-" class="mt-1" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700">Square Webhook Signature Key</label>
              <Input v-model="form.payments.squareSignature" placeholder="sig_key" class="mt-1" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700">PayPal Client ID</label>
              <Input v-model="form.payments.paypalClientId" placeholder="paypal client id" class="mt-1" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700">PayPal Client Secret</label>
              <Input v-model="form.payments.paypalClientSecret" placeholder="paypal secret" class="mt-1" />
            </div>
            <div class="md:col-span-2">
              <label class="block text-sm font-medium text-gray-700">PayPal Webhook ID</label>
              <Input v-model="form.payments.paypalWebhook" placeholder="WH-XXXX" class="mt-1" />
            </div>
          </div>
        </Card>

        <Card>
          <h2 class="text-lg font-semibold text-gray-900 mb-4">Integrations</h2>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-medium text-gray-700">reCAPTCHA Site Key</label>
              <Input v-model="form.security.recaptchaSiteKey" placeholder="site key" class="mt-1" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700">reCAPTCHA Secret Key</label>
              <Input v-model="form.security.recaptchaSecretKey" placeholder="secret key" class="mt-1" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700">Zoho Client ID</label>
              <Input v-model="form.integrations.zohoClientId" placeholder="Zoho client id" class="mt-1" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700">Zoho Client Secret</label>
              <Input v-model="form.integrations.zohoClientSecret" placeholder="Zoho client secret" class="mt-1" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700">Zoho Refresh Token</label>
              <Input v-model="form.integrations.zohoRefreshToken" placeholder="Zoho refresh token" class="mt-1" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700">Zoho Org ID</label>
              <Input v-model="form.integrations.zohoOrgId" placeholder="Zoho org id" class="mt-1" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700">PartsTech API Base</label>
              <Input v-model="form.integrations.partsTechBase" placeholder="https://api.partstech.com" class="mt-1" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700">PartsTech API Key</label>
              <Input v-model="form.integrations.partsTechKey" placeholder="PartsTech key" class="mt-1" />
            </div>
          </div>
          <div class="mt-4">
            <label class="block text-sm font-medium text-gray-700">PartsTech Markup Tiers (JSON)</label>
            <Textarea
              v-model="form.integrations.partsTechMarkup"
              rows="3"
              placeholder='[{"threshold":0,"markup":0.2}]'
              class="mt-1"
            />
          </div>
        </Card>
      </div>
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
  profile: {
    name: '',
    email: '',
    phone: '',
    logoUrl: '',
    address: { street: '', city: '', state: '', postal_code: '', country: '' },
  },
  terms: { estimates: '', invoices: '' },
  pricing: { taxRate: 0, laborRate: 0, callOutFee: 0, mileageRate: 0 },
  notifications: { fromName: '', fromAddress: '', smsNumber: '', twilioSid: '', twilioToken: '' },
  smtp: { host: '', port: 587, username: '', password: '', encryption: 'tls' },
  payments: {
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
  },
  security: { recaptchaSiteKey: '', recaptchaSecretKey: '' },
  integrations: {
    zohoClientId: '',
    zohoClientSecret: '',
    zohoRefreshToken: '',
    zohoOrgId: '',
    partsTechBase: '',
    partsTechKey: '',
    partsTechMarkup: '',
  },
})

const getSetting = (settings, key, fallback = null) => {
  return settings?.[key]?.value ?? fallback
}

const hydrate = async () => {
  loading.value = true
  error.value = ''
  try {
    const settings = await fetchSettings()
    form.profile.name = getSetting(settings, 'shop.name', '')
    form.profile.email = getSetting(settings, 'shop.email', '')
    form.profile.phone = getSetting(settings, 'shop.phone', '')
    form.profile.logoUrl = getSetting(settings, 'shop.logo_url', '')
    form.profile.address = {
      street: getSetting(settings, 'shop.address', {})?.street ?? '',
      city: getSetting(settings, 'shop.address', {})?.city ?? '',
      state: getSetting(settings, 'shop.address', {})?.state ?? '',
      postal_code: getSetting(settings, 'shop.address', {})?.postal_code ?? '',
      country: getSetting(settings, 'shop.address', {})?.country ?? '',
    }

    form.terms.estimates = getSetting(settings, 'documents.terms.estimates', '')
    form.terms.invoices = getSetting(settings, 'documents.terms.invoices', '')

    form.pricing.taxRate = Number(getSetting(settings, 'pricing.tax_rate', 0))
    form.pricing.laborRate = Number(getSetting(settings, 'pricing.labor_rate', 0))
    form.pricing.callOutFee = Number(getSetting(settings, 'pricing.call_out_fee', 0))
    form.pricing.mileageRate = Number(getSetting(settings, 'pricing.mileage_rate', 0))

    form.notifications.fromName = getSetting(settings, 'notifications.mail.from_name', '')
    form.notifications.fromAddress = getSetting(settings, 'notifications.mail.from_address', '')
    form.notifications.smsNumber = getSetting(settings, 'notifications.sms.from_number', '')
    form.notifications.twilioSid = getSetting(settings, 'integrations.twilio.sid', '')
    form.notifications.twilioToken = getSetting(settings, 'integrations.twilio.token', '')

    form.smtp.host = getSetting(settings, 'integrations.smtp.host', '')
    form.smtp.port = Number(getSetting(settings, 'integrations.smtp.port', 587))
    form.smtp.username = getSetting(settings, 'integrations.smtp.username', '')
    form.smtp.password = getSetting(settings, 'integrations.smtp.password', '')
    form.smtp.encryption = getSetting(settings, 'integrations.smtp.encryption', 'tls')

    form.payments.successUrl = getSetting(settings, 'payments.urls.success', '')
    form.payments.cancelUrl = getSetting(settings, 'payments.urls.cancel', '')
    form.payments.stripePublic = getSetting(settings, 'integrations.stripe.public_key', '')
    form.payments.stripeSecret = getSetting(settings, 'integrations.stripe.secret_key', '')
    form.payments.stripeWebhook = getSetting(settings, 'integrations.stripe.webhook_secret', '')
    form.payments.squareToken = getSetting(settings, 'integrations.square.token', '')
    form.payments.squareSignature = getSetting(settings, 'integrations.square.webhook_signature_key', '')
    form.payments.paypalClientId = getSetting(settings, 'integrations.paypal.client_id', '')
    form.payments.paypalClientSecret = getSetting(settings, 'integrations.paypal.client_secret', '')
    form.payments.paypalWebhook = getSetting(settings, 'integrations.paypal.webhook_id', '')

    form.security.recaptchaSiteKey = getSetting(settings, 'integrations.recaptcha.site_key', '')
    form.security.recaptchaSecretKey = getSetting(settings, 'integrations.recaptcha.secret_key', '')

    form.integrations.zohoClientId = getSetting(settings, 'integrations.zoho.client_id', '')
    form.integrations.zohoClientSecret = getSetting(settings, 'integrations.zoho.client_secret', '')
    form.integrations.zohoRefreshToken = getSetting(settings, 'integrations.zoho.refresh_token', '')
    form.integrations.zohoOrgId = getSetting(settings, 'integrations.zoho.org_id', '')
    form.integrations.partsTechBase = getSetting(settings, 'integrations.partstech.api_base', '')
    form.integrations.partsTechKey = getSetting(settings, 'integrations.partstech.api_key', '')
    const markup = getSetting(settings, 'integrations.partstech.markup_tiers', [])
    form.integrations.partsTechMarkup = markup && markup.length ? JSON.stringify(markup, null, 2) : ''
  } catch (e) {
    error.value = e?.message || 'Unable to load settings.'
  } finally {
    loading.value = false
  }
}

const parseMarkup = () => {
  if (!form.integrations.partsTechMarkup) {
    return []
  }

  try {
    const parsed = JSON.parse(form.integrations.partsTechMarkup)
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
    'shop.name': form.profile.name,
    'shop.email': form.profile.email,
    'shop.phone': form.profile.phone,
    'shop.logo_url': form.profile.logoUrl,
    'shop.address': { ...form.profile.address },
    'documents.terms.estimates': form.terms.estimates,
    'documents.terms.invoices': form.terms.invoices,
    'pricing.tax_rate': Number(form.pricing.taxRate) || 0,
    'pricing.labor_rate': Number(form.pricing.laborRate) || 0,
    'pricing.call_out_fee': Number(form.pricing.callOutFee) || 0,
    'pricing.mileage_rate': Number(form.pricing.mileageRate) || 0,
    'notifications.mail.from_name': form.notifications.fromName,
    'notifications.mail.from_address': form.notifications.fromAddress,
    'notifications.sms.from_number': form.notifications.smsNumber,
    'integrations.twilio.sid': form.notifications.twilioSid,
    'integrations.twilio.token': form.notifications.twilioToken,
    'integrations.smtp.host': form.smtp.host,
    'integrations.smtp.port': Number(form.smtp.port) || 0,
    'integrations.smtp.username': form.smtp.username,
    'integrations.smtp.password': form.smtp.password,
    'integrations.smtp.encryption': form.smtp.encryption,
    'payments.urls.success': form.payments.successUrl,
    'payments.urls.cancel': form.payments.cancelUrl,
    'integrations.stripe.public_key': form.payments.stripePublic,
    'integrations.stripe.secret_key': form.payments.stripeSecret,
    'integrations.stripe.webhook_secret': form.payments.stripeWebhook,
    'integrations.square.token': form.payments.squareToken,
    'integrations.square.webhook_signature_key': form.payments.squareSignature,
    'integrations.paypal.client_id': form.payments.paypalClientId,
    'integrations.paypal.client_secret': form.payments.paypalClientSecret,
    'integrations.paypal.webhook_id': form.payments.paypalWebhook,
    'integrations.recaptcha.site_key': form.security.recaptchaSiteKey,
    'integrations.recaptcha.secret_key': form.security.recaptchaSecretKey,
    'integrations.zoho.client_id': form.integrations.zohoClientId,
    'integrations.zoho.client_secret': form.integrations.zohoClientSecret,
    'integrations.zoho.refresh_token': form.integrations.zohoRefreshToken,
    'integrations.zoho.org_id': form.integrations.zohoOrgId,
    'integrations.partstech.api_base': form.integrations.partsTechBase,
    'integrations.partstech.api_key': form.integrations.partsTechKey,
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
