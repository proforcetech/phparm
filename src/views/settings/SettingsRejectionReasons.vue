<template>
  <div>
    <div class="mb-4 flex items-center justify-between">
      <h2 class="text-lg font-semibold text-gray-900">Estimate Rejection Reasons</h2>
      <Button :loading="saving" @click="save">Save Changes</Button>
    </div>

    <Alert v-if="message" variant="success" class="mb-4">{{ message }}</Alert>
    <Alert v-if="error" variant="danger" class="mb-4">{{ error }}</Alert>

    <div v-if="loading" class="text-gray-500">Loading settings...</div>

    <Card v-else>
      <p class="text-sm text-gray-500 mb-4">
        Configure the dropdown options customers see when rejecting an estimate or individual job.
        The "Other" option should always be last to allow custom input.
      </p>

      <div class="space-y-2">
        <div
          v-for="(reason, index) in form.reasons"
          :key="index"
          class="flex items-center gap-2"
        >
          <Input
            v-model="form.reasons[index]"
            :placeholder="`Reason ${index + 1}`"
            class="flex-1"
          />
          <Button
            variant="ghost"
            size="sm"
            @click="removeReason(index)"
            :disabled="form.reasons.length <= 1"
            class="text-red-600 hover:text-red-800"
          >
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
            </svg>
          </Button>
        </div>
      </div>

      <div class="mt-4">
        <Button variant="outline" size="sm" @click="addReason">
          <svg class="h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
          </svg>
          Add Reason
        </Button>
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
import { fetchSettings, saveSettings } from '@/services/settings.service'

const loading = ref(true)
const saving = ref(false)
const message = ref('')
const error = ref('')

const defaultReasons = [
  'Price too high',
  'Found a better deal elsewhere',
  'Decided not to proceed with repairs',
  'Going to a different shop',
  'Vehicle no longer owned',
  'Other'
]

const form = reactive({
  reasons: [],
})

const getSetting = (settings, key, fallback = null) => {
  return settings?.[key]?.value ?? fallback
}

const hydrate = async () => {
  loading.value = true
  error.value = ''
  try {
    const settings = await fetchSettings()
    form.reasons = getSetting(settings, 'estimates.rejection_reasons', defaultReasons) || defaultReasons
  } catch (e) {
    error.value = e?.message || 'Unable to load settings.'
  } finally {
    loading.value = false
  }
}

const addReason = () => {
  form.reasons.push('')
}

const removeReason = (index) => {
  if (form.reasons.length > 1) {
    form.reasons.splice(index, 1)
  }
}

const save = async () => {
  saving.value = true
  message.value = ''
  error.value = ''

  const payload = {
    'estimates.rejection_reasons': form.reasons,
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
