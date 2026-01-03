<template>
  <div>
    <div class="mb-4 flex items-center justify-between">
      <h2 class="text-lg font-semibold text-gray-900">Notifications & Mail</h2>
      <Button :loading="saving" @click="save">Save Changes</Button>
    </div>

    <Alert v-if="message" variant="success" class="mb-4">{{ message }}</Alert>
    <Alert v-if="error" variant="danger" class="mb-4">{{ error }}</Alert>

    <div v-if="loading" class="text-gray-500">Loading settings...</div>

    <div v-else class="space-y-6">
      <Card>
        <h3 class="text-md font-medium text-gray-900 mb-4">Email & SMS Configuration</h3>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700">From Name</label>
            <Input v-model="form.fromName" placeholder="Demo Auto Shop" class="mt-1" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700">From Email</label>
            <Input v-model="form.fromAddress" placeholder="noreply@example.com" class="mt-1" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700">SMS From Number</label>
            <Input v-model="form.smsNumber" placeholder="+15551234567" class="mt-1" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700">Twilio SID</label>
            <Input v-model="form.twilioSid" placeholder="ACXXXXXXXXXXXXXXXX" class="mt-1" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700">Twilio Token</label>
            <Input v-model="form.twilioToken" placeholder="••••••••" class="mt-1" />
          </div>
        </div>
      </Card>

      <Card>
        <h3 class="text-md font-medium text-gray-900 mb-4">SMTP Settings</h3>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
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

      <Card>
        <h3 class="text-md font-medium text-gray-900 mb-4">Test Notifications</h3>
        <p class="text-xs text-gray-500 mb-4">Save settings before running tests.</p>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
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
import {
  fetchSettings,
  saveSettings,
  sendTestEmail,
  sendTestSms,
  testSmtpConnection,
  testTwilioConnection,
} from '@/services/settings.service'

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

const form = reactive({
  fromName: '',
  fromAddress: '',
  smsNumber: '',
  twilioSid: '',
  twilioToken: '',
  smtp: { host: '', port: 587, username: '', password: '', encryption: 'tls' },
})

const getSetting = (settings, key, fallback = null) => {
  return settings?.[key]?.value ?? fallback
}

const hydrate = async () => {
  loading.value = true
  error.value = ''
  try {
    const settings = await fetchSettings()
    form.fromName = getSetting(settings, 'notifications.mail.from_name', '')
    form.fromAddress = getSetting(settings, 'notifications.mail.from_address', '')
    form.smsNumber = getSetting(settings, 'notifications.sms.from_number', '')
    form.twilioSid = getSetting(settings, 'integrations.twilio.sid', '')
    form.twilioToken = getSetting(settings, 'integrations.twilio.token', '')
    form.smtp.host = getSetting(settings, 'integrations.smtp.host', '')
    form.smtp.port = Number(getSetting(settings, 'integrations.smtp.port', 587))
    form.smtp.username = getSetting(settings, 'integrations.smtp.username', '')
    form.smtp.password = getSetting(settings, 'integrations.smtp.password', '')
    form.smtp.encryption = getSetting(settings, 'integrations.smtp.encryption', 'tls')
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
    'notifications.mail.from_name': form.fromName,
    'notifications.mail.from_address': form.fromAddress,
    'notifications.sms.from_number': form.smsNumber,
    'integrations.twilio.sid': form.twilioSid,
    'integrations.twilio.token': form.twilioToken,
    'integrations.smtp.host': form.smtp.host,
    'integrations.smtp.port': Number(form.smtp.port) || 0,
    'integrations.smtp.username': form.smtp.username,
    'integrations.smtp.password': form.smtp.password,
    'integrations.smtp.encryption': form.smtp.encryption,
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
