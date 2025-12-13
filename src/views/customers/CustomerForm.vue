<template>
  <div>
    <div class="mb-6 flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Create Customer</h1>
        <p class="mt-1 text-sm text-gray-500">Add a new customer profile</p>
      </div>
      <Button variant="secondary" @click="goBack">Back to list</Button>
    </div>

    <Card class="max-w-4xl">
      <form class="space-y-6" @submit.prevent="submit">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
          <div>
            <label class="block text-sm font-medium text-gray-700">Full name *</label>
            <Input v-model="form.name" required placeholder="John Doe" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700">Email</label>
            <Input v-model="form.email" type="email" placeholder="customer@example.com" />
          </div>
        </div>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
          <div>
            <label class="block text-sm font-medium text-gray-700">Phone</label>
            <Input v-model="form.phone" placeholder="(555) 123-4567" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700">External reference</label>
            <Input v-model="form.external_reference" placeholder="CRM-12345" />
          </div>
        </div>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
          <div>
            <label class="block text-sm font-medium text-gray-700">Customer type</label>
            <Select
              v-model="form.is_commercial"
              :options="customerTypeOptions"
              placeholder="Select type"
              @change="onSelectChange('is_commercial', $event)"
            />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700">Tax status</label>
            <Select
              v-model="form.tax_exempt"
              :options="taxOptions"
              placeholder="Select tax status"
              @change="onSelectChange('tax_exempt', $event)"
            />
          </div>
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700">Notes</label>
          <Textarea v-model="form.notes" rows="4" placeholder="Customer preferences, notes, or important details" />
        </div>

        <div class="flex items-center gap-3">
          <Button type="submit" :loading="submitting">Create customer</Button>
          <Button type="button" variant="secondary" @click="goBack">Cancel</Button>
          <p v-if="error" class="text-sm text-red-600">{{ error }}</p>
        </div>
      </form>
    </Card>
  </div>
</template>

<script setup>
import { reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import Button from '@/components/ui/Button.vue'
import Card from '@/components/ui/Card.vue'
import Input from '@/components/ui/Input.vue'
import Select from '@/components/ui/Select.vue'
import Textarea from '@/components/ui/Textarea.vue'
import { createCustomer } from '@/services/customer.service'

const router = useRouter()

const submitting = ref(false)
const error = ref('')

const form = reactive({
  name: '',
  email: '',
  phone: '',
  external_reference: '',
  is_commercial: null,
  tax_exempt: null,
  notes: ''
})

const customerTypeOptions = [
  { label: 'Consumer', value: false },
  { label: 'Commercial', value: true }
]

const taxOptions = [
  { label: 'Taxed', value: false },
  { label: 'Tax exempt', value: true }
]

const goBack = () => {
  router.push('/cp/customers')
}

const onSelectChange = (key, value) => {
  form[key] = value
}

const submit = async () => {
  submitting.value = true
  error.value = ''

  try {
    const payload = {
      ...form
    }

    const customer = await createCustomer(payload)
    router.push(`/cp/customers/${customer.id}`)
  } catch (err) {
    error.value = err?.response?.data?.message || err?.message || 'Failed to create customer'
  } finally {
    submitting.value = false
  }
}
</script>
