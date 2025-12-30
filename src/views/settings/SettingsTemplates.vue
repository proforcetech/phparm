<template>
  <div>
    <div class="mb-4 flex items-center justify-between">
      <h2 class="text-lg font-semibold text-gray-900">Estimate Sharing Templates</h2>
      <Button :loading="saving" @click="save">Save Changes</Button>
    </div>

    <Alert v-if="message" variant="success" class="mb-4">{{ message }}</Alert>
    <Alert v-if="error" variant="danger" class="mb-4">{{ error }}</Alert>

    <div v-if="loading" class="text-gray-500">Loading settings...</div>

    <Card v-else>
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
          <div class="space-y-3">
            <div>
              <label class="block text-xs font-medium text-gray-500 mb-1">Subject Line</label>
              <Input
                v-model="form.emailSubject"
                placeholder="Your Estimate #{estimate_number} from {shop_name}"
              />
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-500 mb-1">Email Body</label>
              <div class="template-editor">
                <QuillEditor
                  v-model:content="form.emailBody"
                  content-type="html"
                  theme="snow"
                  :toolbar="toolbar"
                />
              </div>
            </div>
          </div>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-2">SMS Template</label>
          <p class="text-xs text-gray-500 mb-2">Keep SMS messages concise (160 characters recommended)</p>
          <Textarea
            v-model="form.smsBody"
            :rows="6"
            placeholder="Hi {customer}, your estimate #{estimate_number} for {total} is ready. View it here: {link} - {shop_name}"
          />
          <p class="text-xs text-gray-400 mt-1">
            {{ (form.smsBody || '').length }} characters
          </p>
        </div>
      </div>
    </Card>
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
import { QuillEditor } from '@vueup/vue-quill'
import '@vueup/vue-quill/dist/vue-quill.snow.css'

const loading = ref(true)
const saving = ref(false)
const message = ref('')
const error = ref('')

const toolbar = [
  [{ header: [2, 3, false] }],
  ['bold', 'italic', 'underline'],
  [{ list: 'ordered' }, { list: 'bullet' }],
  ['link'],
  ['clean'],
]

const form = reactive({
  emailSubject: '',
  emailBody: '',
  smsBody: '',
})

const getSetting = (settings, key, fallback = null) => {
  return settings?.[key]?.value ?? fallback
}

const hydrate = async () => {
  loading.value = true
  error.value = ''
  try {
    const settings = await fetchSettings()
    form.emailSubject = getSetting(settings, 'templates.estimate.email_subject', 'Your Estimate #{estimate_number} from {shop_name}')
    form.emailBody = getSetting(settings, 'templates.estimate.email_body', '')
    form.smsBody = getSetting(settings, 'templates.estimate.sms_body', '')
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
    'templates.estimate.email_subject': form.emailSubject,
    'templates.estimate.email_body': form.emailBody,
    'templates.estimate.sms_body': form.smsBody,
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

<style scoped>
:deep(.template-editor .ql-container) {
  border-radius: 0.375rem;
}

:deep(.template-editor .ql-editor) {
  min-height: 180px;
  font-family: inherit;
  font-size: 0.875rem;
}
</style>
