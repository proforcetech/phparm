<template>
  <div>
    <div class="mb-6">
      <h1 class="text-2xl font-bold text-gray-900">My Profile</h1>
      <p class="mt-1 text-sm text-gray-500">Update your contact information and reminder preferences.</p>
    </div>

    <Alert v-if="errorMessage" variant="danger" class="mb-4" @close="errorMessage = ''">
      {{ errorMessage }}
    </Alert>

    <Alert v-if="successMessage" variant="success" class="mb-4" @close="successMessage = ''">
      {{ successMessage }}
    </Alert>

    <form @submit.prevent="savePreferences">
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-6">
          <Card title="Personal Information">
            <div class="space-y-4">
              <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <Input v-model="profileForm.first_name" label="First Name" placeholder="John" required />
                <Input v-model="profileForm.last_name" label="Last Name" placeholder="Doe" required />
              </div>
              <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <Input v-model="profileForm.email" label="Email" type="email" placeholder="john@example.com" />
                <Input v-model="profileForm.phone" label="Phone" type="tel" placeholder="(555) 123-4567" />
              </div>
              <p class="text-sm text-gray-500">Provide at least one contact method so we can send reminders.</p>
            </div>
          </Card>

          <Card title="Reminder Preferences">
            <div class="space-y-4">
              <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <Select v-model="preferenceForm.preferred_channel" :options="channelOptions" label="Reminder Channel" />
                <Select v-model="preferenceForm.timezone" :options="timezoneOptions" label="Timezone" />
              </div>

              <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <Input
                  v-model.number="preferenceForm.lead_days"
                  type="number"
                  min="0"
                  label="Lead Days"
                  helper-text="How many days before to send notices"
                  required
                />
                <Select v-model.number="preferenceForm.preferred_hour" :options="hourOptions" label="Preferred Hour" />
              </div>

              <label class="flex items-center space-x-2 text-sm text-gray-700">
                <input
                  v-model="preferenceForm.is_active"
                  type="checkbox"
                  class="h-4 w-4 rounded border-gray-300 text-primary-600 focus:ring-primary-500"
                />
                <span>Enable automated reminders</span>
              </label>
            </div>
          </Card>
        </div>

        <div>
          <Card title="Actions">
            <div class="space-y-4">
              <Button :disabled="saving || loading" type="submit" class="w-full">
                <template v-if="saving">
                  Saving...
                </template>
                <template v-else>
                  Save Preferences
                </template>
              </Button>
              <p class="text-xs text-gray-500">Changes update your contact info and how you receive reminders.</p>
            </div>
          </Card>
        </div>
      </div>
    </form>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import Alert from '@/components/ui/Alert.vue'
import Button from '@/components/ui/Button.vue'
import Card from '@/components/ui/Card.vue'
import Input from '@/components/ui/Input.vue'
import Select from '@/components/ui/Select.vue'
import { reminderService } from '@/services/reminder.service'

const loading = ref(true)
const saving = ref(false)
const successMessage = ref('')
const errorMessage = ref('')

const profileForm = ref({
  first_name: '',
  last_name: '',
  email: '',
  phone: '',
})

const preferenceForm = ref({
  preferred_channel: 'both',
  timezone: 'UTC',
  lead_days: 3,
  preferred_hour: 9,
  is_active: true,
})

const channelOptions = [
  { label: 'Email', value: 'mail' },
  { label: 'SMS', value: 'sms' },
  { label: 'Email & SMS', value: 'both' },
  { label: 'Do Not Send', value: 'none' },
]

const timezoneOptions = [
  { label: 'UTC', value: 'UTC' },
  { label: 'Eastern Time (ET)', value: 'America/New_York' },
  { label: 'Central Time (CT)', value: 'America/Chicago' },
  { label: 'Mountain Time (MT)', value: 'America/Denver' },
  { label: 'Pacific Time (PT)', value: 'America/Los_Angeles' },
]

const hourOptions = Array.from({ length: 24 }).map((_, index) => ({
  label: new Date(0, 0, 0, index).toLocaleTimeString([], { hour: 'numeric', hour12: true }),
  value: index,
}))

onMounted(async () => {
  await loadPreferences()
})

async function loadPreferences() {
  loading.value = true
  errorMessage.value = ''

  try {
    const data = await reminderService.getPreferences()

    if (data.customer) {
      profileForm.value = {
        ...profileForm.value,
        first_name: data.customer.first_name || '',
        last_name: data.customer.last_name || '',
        email: data.customer.email || '',
        phone: data.customer.phone || '',
      }
    }

    if (data.preference) {
      preferenceForm.value = {
        ...preferenceForm.value,
        preferred_channel: data.preference.preferred_channel || 'both',
        timezone: data.preference.timezone || 'UTC',
        lead_days: data.preference.lead_days ?? 3,
        preferred_hour: data.preference.preferred_hour ?? 9,
        is_active: data.preference.is_active ?? true,
      }
    }
  } catch (error) {
    errorMessage.value = error.response?.data?.message || 'Unable to load preferences.'
  } finally {
    loading.value = false
  }
}

async function savePreferences() {
  saving.value = true
  successMessage.value = ''
  errorMessage.value = ''

  try {
    const payload = {
      ...profileForm.value,
      ...preferenceForm.value,
    }

    const data = await reminderService.updatePreferences(payload)

    successMessage.value = 'Preferences saved successfully.'

    if (data.preference) {
      preferenceForm.value = {
        ...preferenceForm.value,
        preferred_channel: data.preference.preferred_channel || 'both',
        timezone: data.preference.timezone || 'UTC',
        lead_days: data.preference.lead_days ?? 3,
        preferred_hour: data.preference.preferred_hour ?? 9,
        is_active: data.preference.is_active ?? true,
      }
    }

    if (data.customer) {
      profileForm.value = {
        ...profileForm.value,
        first_name: data.customer.first_name || '',
        last_name: data.customer.last_name || '',
        email: data.customer.email || '',
        phone: data.customer.phone || '',
      }
    }
  } catch (error) {
    errorMessage.value = error.response?.data?.message || 'Unable to save preferences.'
  } finally {
    saving.value = false
  }
}
</script>
