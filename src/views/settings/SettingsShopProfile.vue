<template>
  <div>
    <div class="mb-4 flex items-center justify-between">
      <h2 class="text-lg font-semibold text-gray-900">Shop Profile</h2>
      <Button :loading="saving" @click="save">Save Changes</Button>
    </div>

    <Alert v-if="message" variant="success" class="mb-4">{{ message }}</Alert>
    <Alert v-if="error" variant="danger" class="mb-4">{{ error }}</Alert>

    <div v-if="loading" class="text-gray-500">Loading settings...</div>

    <Card v-else>
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-medium text-gray-700">Shop Name</label>
          <Input v-model="form.name" placeholder="Demo Auto Shop" class="mt-1" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Notification Email</label>
          <Input v-model="form.email" placeholder="noreply@example.com" class="mt-1" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Phone</label>
          <Input v-model="form.phone" placeholder="+1 (555) 123-4567" class="mt-1" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Logo URL</label>
          <Input v-model="form.logoUrl" placeholder="https://cdn.example.com/logo.png" class="mt-1" />
        </div>
      </div>

      <h3 class="text-md font-medium text-gray-900 mt-6 mb-4">Address</h3>
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-medium text-gray-700">Street</label>
          <Input v-model="form.address.street" placeholder="123 Main St" class="mt-1" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">City</label>
          <Input v-model="form.address.city" placeholder="Anytown" class="mt-1" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">State/Province</label>
          <Input v-model="form.address.state" placeholder="CA" class="mt-1" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Postal Code</label>
          <Input v-model="form.address.postal_code" placeholder="90210" class="mt-1" />
        </div>
        <div class="md:col-span-2">
          <label class="block text-sm font-medium text-gray-700">Country</label>
          <Input v-model="form.address.country" placeholder="United States" class="mt-1" />
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
  name: '',
  email: '',
  phone: '',
  logoUrl: '',
  address: { street: '', city: '', state: '', postal_code: '', country: '' },
})

const getSetting = (settings, key, fallback = null) => {
  return settings?.[key]?.value ?? fallback
}

const hydrate = async () => {
  loading.value = true
  error.value = ''
  try {
    const settings = await fetchSettings()
    form.name = getSetting(settings, 'shop.name', '')
    form.email = getSetting(settings, 'shop.email', '')
    form.phone = getSetting(settings, 'shop.phone', '')
    form.logoUrl = getSetting(settings, 'shop.logo_url', '')
    form.address = {
      street: getSetting(settings, 'shop.address', {})?.street ?? '',
      city: getSetting(settings, 'shop.address', {})?.city ?? '',
      state: getSetting(settings, 'shop.address', {})?.state ?? '',
      postal_code: getSetting(settings, 'shop.address', {})?.postal_code ?? '',
      country: getSetting(settings, 'shop.address', {})?.country ?? '',
    }
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
    'shop.name': form.name,
    'shop.email': form.email,
    'shop.phone': form.phone,
    'shop.logo_url': form.logoUrl,
    'shop.address': { ...form.address },
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
