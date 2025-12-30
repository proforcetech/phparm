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
              <div class="mt-1 terms-editor">
                <QuillEditor
                  v-model:content="form.terms.estimates"
                  content-type="html"
                  theme="snow"
                  :toolbar="termsToolbar"
                />
              </div>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700">Invoice Terms</label>
              <div class="mt-1 terms-editor">
                <QuillEditor
                  v-model:content="form.terms.invoices"
                  content-type="html"
                  theme="snow"
                  :toolbar="termsToolbar"
                />
              </div>
            </div>
          </div>
        </Card>
      </div>

      <!-- Estimate Sharing Templates -->
      <div class="grid grid-cols-1 gap-6">
        <Card>
          <h2 class="text-lg font-semibold text-gray-900 mb-4">Estimate Sharing Templates</h2>
          <p class="text-sm text-gray-500 mb-4">
            Configure templates for sharing estimates with customers. Available variables:
            <code class="bg-gray-100 px-1 rounded">{customer}</code>,
            <code class="bg-gray-100 px-1 rounded">{estimate_number}</code>,
            <code class="bg-gray-100 px-1 rounded">{total}</code>,
            <code class="bg-gray-100 px-1 rounded">{vehicle}</code>,
            <code class="bg-gray-100 px-1 rounded">{expiration_date}</code>,
            <code class="bg-gray-100 px-1 rounded">{link}</code>,
            <code class="bg-gray-100 px-1 rounded">{shop_name}</code>,
            <code class="bg-gray-100 px-1 rounded">{shop_phone}</code>
          </p>
          <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">Email Template</label>
              <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Subject Line</label>
                <Input
                  v-model="form.templates.estimateEmailSubject"
                  placeholder="Your Estimate #{estimate_number} from {shop_name}"
                  class="mb-2"
                />
              </div>
              <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Email Body</label>
                <div class="template-editor">
                  <QuillEditor
                    v-model:content="form.templates.estimateEmail"
                    content-type="html"
                    theme="snow"
                    :toolbar="templateToolbar"
                  />
                </div>
              </div>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">SMS Template</label>
              <p class="text-xs text-gray-500 mb-2">Keep SMS messages concise (160 characters recommended)</p>
              <Textarea
                v-model="form.templates.estimateSms"
                :rows="4"
                placeholder="Hi {customer}, your estimate #{estimate_number} for {total} is ready. View it here: {link} - {shop_name}"
                class="mt-1"
              />
              <p class="text-xs text-gray-400 mt-1">
                {{ (form.templates.estimateSms || '').length }} characters
              </p>
            </div>
          </div>
        </Card>
      </div>

      <!-- Estimate Rejection Reasons -->
      <div class="grid grid-cols-1 gap-6">
        <Card>
          <h2 class="text-lg font-semibold text-gray-900 mb-4">Estimate Rejection Reasons</h2>
          <p class="text-sm text-gray-500 mb-4">
            Configure the dropdown options customers see when rejecting an estimate or individual job.
            The "Other" option should always be last to allow custom input.
          </p>
          <div class="space-y-2">
            <div
              v-for="(reason, index) in form.estimates.rejectionReasons"
              :key="index"
              class="flex items-center gap-2"
            >
              <Input
                v-model="form.estimates.rejectionReasons[index]"
                :placeholder="`Reason ${index + 1}`"
                class="flex-1"
              />
              <Button
                variant="ghost"
                size="sm"
                @click="removeRejectionReason(index)"
                :disabled="form.estimates.rejectionReasons.length <= 1"
                class="text-red-600 hover:text-red-800"
              >
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                </svg>
              </Button>
            </div>
          </div>
          <div class="mt-4">
            <Button variant="outline" size="sm" @click="addRejectionReason">
              <svg class="h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
              </svg>
              Add Reason
            </Button>
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
          <div class="mt-6 border-t border-gray-200 pt-4">
            <div class="flex items-start justify-between gap-4">
              <div>
                <h3 class="text-sm font-semibold text-gray-900">Test Notifications</h3>
                <p class="text-xs text-gray-500">Save settings before running tests.</p>
              </div>
            </div>
            <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-gray-700">Test Email Recipient</label>
                <Input v-model="smtpTestRecipient" placeholder="you@example.com" class="mt-1" />
                <div class="mt-2 flex flex-wrap gap-2">
                  <Button size="sm" variant="outline" :loading="smtpConnectionLoading" @click="runSmtpConnectionTest">
                    Test SMTP Connection
                  </Button>
                  <Button size="sm" :loading="smtpEmailLoading" @click="runSmtpEmailTest">
                    Send Test Email
                  </Button>
                </div>
                <p v-if="smtpConnectionMessage" class="mt-2 text-xs text-green-600">{{ smtpConnectionMessage }}</p>
                <p v-if="smtpConnectionError" class="mt-2 text-xs text-red-600">{{ smtpConnectionError }}</p>
                <p v-if="smtpEmailMessage" class="mt-2 text-xs text-green-600">{{ smtpEmailMessage }}</p>
                <p v-if="smtpEmailError" class="mt-2 text-xs text-red-600">{{ smtpEmailError }}</p>
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700">Test SMS Recipient</label>
                <Input v-model="smsTestRecipient" placeholder="+15551234567" class="mt-1" />
                <div class="mt-2 flex flex-wrap gap-2">
                  <Button size="sm" variant="outline" :loading="twilioConnectionLoading" @click="runTwilioConnectionTest">
                    Test Twilio Connection
                  </Button>
                  <Button size="sm" :loading="twilioSmsLoading" @click="runTwilioSmsTest">
                    Send Test SMS
                  </Button>
                </div>
                <p v-if="twilioConnectionMessage" class="mt-2 text-xs text-green-600">{{ twilioConnectionMessage }}</p>
                <p v-if="twilioConnectionError" class="mt-2 text-xs text-red-600">{{ twilioConnectionError }}</p>
                <p v-if="twilioSmsMessage" class="mt-2 text-xs text-green-600">{{ twilioSmsMessage }}</p>
                <p v-if="twilioSmsError" class="mt-2 text-xs text-red-600">{{ twilioSmsError }}</p>
              </div>
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
            <div class="flex items-center space-x-2 md:col-span-2">
              <input
                id="recaptcha-enabled"
                v-model="form.security.recaptchaEnabled"
                type="checkbox"
                class="h-4 w-4 text-indigo-600 border-gray-300 rounded"
              />
              <label for="recaptcha-enabled" class="block text-sm font-medium text-gray-700">Enable reCAPTCHA</label>
            </div>
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
              :rows="3"
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
import {
  fetchSettings,
  saveSettings,
  sendTestEmail,
  sendTestSms,
  testSmtpConnection,
  testTwilioConnection,
} from '@/services/settings.service'
import { QuillEditor } from '@vueup/vue-quill'
import '@vueup/vue-quill/dist/vue-quill.snow.css'

const loading = ref(true)
const saving = ref(false)
const message = ref('')
const error = ref('')
const smtpTestRecipient = ref('')
const smsTestRecipient = ref('')
const smtpConnectionLoading = ref(false)
const smtpEmailLoading = ref(false)
const twilioConnectionLoading = ref(false)
const twilioSmsLoading = ref(false)
const smtpConnectionMessage = ref('')
const smtpConnectionError = ref('')
const smtpEmailMessage = ref('')
const smtpEmailError = ref('')
const twilioConnectionMessage = ref('')
const twilioConnectionError = ref('')
const twilioSmsMessage = ref('')
const twilioSmsError = ref('')

const termsToolbar = [
  [{ header: [2, 3, false] }],
  ['bold', 'italic', 'underline'],
  [{ list: 'ordered' }, { list: 'bullet' }],
  ['link'],
  ['clean'],
]

const templateToolbar = [
  [{ header: [2, 3, false] }],
  ['bold', 'italic', 'underline'],
  [{ list: 'ordered' }, { list: 'bullet' }],
  ['link'],
  ['clean'],
]

const form = reactive({
  profile: {
    name: '',
    email: '',
    phone: '',
    logoUrl: '',
    address: { street: '', city: '', state: '', postal_code: '', country: '' },
  },
  terms: { estimates: '', invoices: '' },
  templates: {
    estimateEmailSubject: '',
    estimateEmail: '',
    estimateSms: '',
  },
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
  security: { recaptchaEnabled: false, recaptchaSiteKey: '', recaptchaSecretKey: '' },
  integrations: {
    zohoClientId: '',
    zohoClientSecret: '',
    zohoRefreshToken: '',
    zohoOrgId: '',
    partsTechBase: '',
    partsTechKey: '',
    partsTechMarkup: '',
  },
  estimates: {
    rejectionReasons: [],
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

    form.templates.estimateEmailSubject = getSetting(settings, 'templates.estimate.email_subject', 'Your Estimate #{estimate_number} from {shop_name}')
    form.templates.estimateEmail = getSetting(settings, 'templates.estimate.email_body', '')
    form.templates.estimateSms = getSetting(settings, 'templates.estimate.sms_body', '')

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

    form.security.recaptchaEnabled = !!getSetting(settings, 'integrations.recaptcha.enabled', false)
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

    const defaultReasons = ['Price too high', 'Found a better deal elsewhere', 'Decided not to proceed with repairs', 'Going to a different shop', 'Vehicle no longer owned', 'Other']
    form.estimates.rejectionReasons = getSetting(settings, 'estimates.rejection_reasons', defaultReasons) || defaultReasons
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

const addRejectionReason = () => {
  form.estimates.rejectionReasons.push('')
}

const removeRejectionReason = (index) => {
  if (form.estimates.rejectionReasons.length > 1) {
    form.estimates.rejectionReasons.splice(index, 1)
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
    'templates.estimate.email_subject': form.templates.estimateEmailSubject,
    'templates.estimate.email_body': form.templates.estimateEmail,
    'templates.estimate.sms_body': form.templates.estimateSms,
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
    'integrations.recaptcha.enabled': !!form.security.recaptchaEnabled,
    'integrations.recaptcha.site_key': form.security.recaptchaSiteKey,
    'integrations.recaptcha.secret_key': form.security.recaptchaSecretKey,
    'integrations.zoho.client_id': form.integrations.zohoClientId,
    'integrations.zoho.client_secret': form.integrations.zohoClientSecret,
    'integrations.zoho.refresh_token': form.integrations.zohoRefreshToken,
    'integrations.zoho.org_id': form.integrations.zohoOrgId,
    'integrations.partstech.api_base': form.integrations.partsTechBase,
    'integrations.partstech.api_key': form.integrations.partsTechKey,
    'integrations.partstech.markup_tiers': markupTiers,
    'estimates.rejection_reasons': form.estimates.rejectionReasons,
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

const resetTestMessages = () => {
  smtpConnectionMessage.value = ''
  smtpConnectionError.value = ''
  smtpEmailMessage.value = ''
  smtpEmailError.value = ''
  twilioConnectionMessage.value = ''
  twilioConnectionError.value = ''
  twilioSmsMessage.value = ''
  twilioSmsError.value = ''
}

const runSmtpConnectionTest = async () => {
  resetTestMessages()
  smtpConnectionLoading.value = true

  try {
    const result = await testSmtpConnection()
    smtpConnectionMessage.value = result?.message || 'SMTP connection successful.'
  } catch (e) {
    smtpConnectionError.value = e?.response?.data?.error || e?.message || 'Failed to test SMTP connection.'
  } finally {
    smtpConnectionLoading.value = false
  }
}

const runSmtpEmailTest = async () => {
  resetTestMessages()
  smtpEmailLoading.value = true

  try {
    const result = await sendTestEmail(smtpTestRecipient.value)
    smtpEmailMessage.value = result?.message || 'Test email sent.'
  } catch (e) {
    smtpEmailError.value = e?.response?.data?.error || e?.message || 'Failed to send test email.'
  } finally {
    smtpEmailLoading.value = false
  }
}

const runTwilioConnectionTest = async () => {
  resetTestMessages()
  twilioConnectionLoading.value = true

  try {
    const result = await testTwilioConnection()
    twilioConnectionMessage.value = result?.message || 'Twilio connection successful.'
  } catch (e) {
    twilioConnectionError.value = e?.response?.data?.error || e?.message || 'Failed to test Twilio connection.'
  } finally {
    twilioConnectionLoading.value = false
  }
}

const runTwilioSmsTest = async () => {
  resetTestMessages()
  twilioSmsLoading.value = true

  try {
    const result = await sendTestSms(smsTestRecipient.value)
    twilioSmsMessage.value = result?.message || 'Test SMS sent.'
  } catch (e) {
    twilioSmsError.value = e?.response?.data?.error || e?.message || 'Failed to send test SMS.'
  } finally {
    twilioSmsLoading.value = false
  }
}

onMounted(hydrate)
</script>

<style scoped>
:deep(.terms-editor .ql-container),
:deep(.template-editor .ql-container) {
  border-radius: 0.375rem;
}

:deep(.terms-editor .ql-editor) {
  min-height: 140px;
  font-family: inherit;
  font-size: 0.875rem;
}

:deep(.template-editor .ql-editor) {
  min-height: 180px;
  font-family: inherit;
  font-size: 0.875rem;
}
</style>
