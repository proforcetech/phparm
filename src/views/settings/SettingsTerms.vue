<template>
  <div>
    <div class="mb-4 flex items-center justify-between">
      <h2 class="text-lg font-semibold text-gray-900">Terms & Documents</h2>
      <Button :loading="saving" @click="save">Save Changes</Button>
    </div>

    <Alert v-if="message" variant="success" class="mb-4">{{ message }}</Alert>
    <Alert v-if="error" variant="danger" class="mb-4">{{ error }}</Alert>

    <div v-if="loading" class="text-gray-500">Loading settings...</div>

    <Card v-else>
      <div class="space-y-6">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-2">Estimate Terms</label>
          <p class="text-xs text-gray-500 mb-2">Terms displayed on estimate documents.</p>
          <div class="terms-editor">
            <QuillEditor
              v-model:content="form.estimates"
              content-type="html"
              theme="snow"
              :toolbar="toolbar"
            />
          </div>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-2">Invoice Terms</label>
          <p class="text-xs text-gray-500 mb-2">Terms displayed on invoice documents.</p>
          <div class="terms-editor">
            <QuillEditor
              v-model:content="form.invoices"
              content-type="html"
              theme="snow"
              :toolbar="toolbar"
            />
          </div>
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
  estimates: '',
  invoices: '',
})

const getSetting = (settings, key, fallback = null) => {
  return settings?.[key]?.value ?? fallback
}

const hydrate = async () => {
  loading.value = true
  error.value = ''
  try {
    const settings = await fetchSettings()
    form.estimates = getSetting(settings, 'documents.terms.estimates', '')
    form.invoices = getSetting(settings, 'documents.terms.invoices', '')
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
    'documents.terms.estimates': form.estimates,
    'documents.terms.invoices': form.invoices,
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
:deep(.terms-editor .ql-container) {
  border-radius: 0.375rem;
}

:deep(.terms-editor .ql-editor) {
  min-height: 180px;
  font-family: inherit;
  font-size: 0.875rem;
}
</style>
