<template>
  <div>
    <div class="mb-4 flex items-center justify-between">
      <h2 class="text-lg font-semibold text-gray-900">Pricing Defaults</h2>
      <Button :loading="saving" @click="save">Save Changes</Button>
    </div>

    <Alert v-if="message" variant="success" class="mb-4">{{ message }}</Alert>
    <Alert v-if="error" variant="danger" class="mb-4">{{ error }}</Alert>

    <div v-if="loading" class="text-gray-500">Loading settings...</div>

    <Card v-else>
      <p class="text-sm text-gray-500 mb-4">
        Configure default pricing values used for estimates and invoices.
      </p>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-medium text-gray-700">Tax Rate (%)</label>
          <Input v-model.number="form.taxRate" type="number" step="0.01" min="0" class="mt-1" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Labor Rate (per hour)</label>
          <Input v-model.number="form.laborRate" type="number" step="0.01" min="0" class="mt-1" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Call-out Fee</label>
          <Input v-model.number="form.callOutFee" type="number" step="0.01" min="0" class="mt-1" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Mileage Rate</label>
          <Input v-model.number="form.mileageRate" type="number" step="0.01" min="0" class="mt-1" />
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
import { fetchSettings, saveSettings } from '@/services/settings.service'

const loading = ref(true)
const saving = ref(false)
const message = ref('')
const error = ref('')

const form = reactive({
  taxRate: 0,
  laborRate: 0,
  callOutFee: 0,
  mileageRate: 0,
})

const getSetting = (settings, key, fallback = null) => {
  return settings?.[key]?.value ?? fallback
}

const hydrate = async () => {
  loading.value = true
  error.value = ''
  try {
    const settings = await fetchSettings()
    form.taxRate = Number(getSetting(settings, 'pricing.tax_rate', 0))
    form.laborRate = Number(getSetting(settings, 'pricing.labor_rate', 0))
    form.callOutFee = Number(getSetting(settings, 'pricing.call_out_fee', 0))
    form.mileageRate = Number(getSetting(settings, 'pricing.mileage_rate', 0))
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
    'pricing.tax_rate': Number(form.taxRate) || 0,
    'pricing.labor_rate': Number(form.laborRate) || 0,
    'pricing.call_out_fee': Number(form.callOutFee) || 0,
    'pricing.mileage_rate': Number(form.mileageRate) || 0,
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
