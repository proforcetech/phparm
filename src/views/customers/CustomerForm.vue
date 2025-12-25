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
        <!-- Basic Information -->
        <div>
          <h3 class="text-lg font-medium text-gray-900 mb-4">Basic Information</h3>
          <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <div>
              <label class="block text-sm font-medium text-gray-700">First name *</label>
              <Input v-model="form.first_name" required placeholder="John" :error="errors.first_name" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700">Last name *</label>
              <Input v-model="form.last_name" required placeholder="Doe" :error="errors.last_name" />
            </div>
          </div>

          <div v-if="form.is_commercial" class="mt-4">
            <label class="block text-sm font-medium text-gray-700">Business name</label>
            <Input v-model="form.business_name" placeholder="ABC Company LLC" />
          </div>

          <div class="grid grid-cols-1 gap-4 md:grid-cols-2 mt-4">
            <div>
              <label class="block text-sm font-medium text-gray-700">Email *</label>
              <Input v-model="form.email" type="email" required placeholder="customer@example.com" :error="errors.email" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700">Phone</label>
              <Input v-model="form.phone" placeholder="(555) 123-4567" />
            </div>
          </div>

          <div class="grid grid-cols-1 gap-4 md:grid-cols-2 mt-4">
            <div>
              <label class="block text-sm font-medium text-gray-700">Customer type</label>
              <Select
                v-model="form.is_commercial"
                :options="customerTypeOptions"
                placeholder="Select type"
              />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700">Tax status</label>
              <Select
                v-model="form.tax_exempt"
                :options="taxOptions"
                placeholder="Select tax status"
              />
            </div>
          </div>

          <div class="mt-4">
            <label class="block text-sm font-medium text-gray-700">External reference</label>
            <Input v-model="form.external_reference" placeholder="CRM-12345" />
          </div>
        </div>

        <!-- Address Information -->
        <div class="pt-6 border-t border-gray-200">
          <h3 class="text-lg font-medium text-gray-900 mb-4">Address</h3>
          <div class="space-y-4">
            <div>
              <label class="block text-sm font-medium text-gray-700">Street address</label>
              <Input v-model="form.street" placeholder="123 Main St" />
            </div>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
              <div>
                <label class="block text-sm font-medium text-gray-700">City</label>
                <Input v-model="form.city" placeholder="Grand Rapids" />
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700">State</label>
                <Input v-model="form.state" placeholder="MI" />
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700">Postal code</label>
                <Input v-model="form.postal_code" placeholder="49503" />
              </div>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700">Country</label>
              <Input v-model="form.country" placeholder="USA" />
            </div>
          </div>
        </div>

        <!-- Billing Address (Commercial only) -->
        <div v-if="form.is_commercial" class="pt-6 border-t border-gray-200">
          <h3 class="text-lg font-medium text-gray-900 mb-4">Billing Address</h3>
          <div class="mb-4">
            <label class="flex items-center">
              <input
                v-model="billingAddressSameAsMain"
                type="checkbox"
                class="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded"
              />
              <span class="ml-2 text-sm text-gray-700">Same as main address</span>
            </label>
          </div>

          <div v-if="!billingAddressSameAsMain" class="space-y-4">
            <div>
              <label class="block text-sm font-medium text-gray-700">Billing street address</label>
              <Input v-model="form.billing_street" placeholder="456 Business Blvd" />
            </div>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
              <div>
                <label class="block text-sm font-medium text-gray-700">Billing city</label>
                <Input v-model="form.billing_city" placeholder="Grand Rapids" />
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700">Billing state</label>
                <Input v-model="form.billing_state" placeholder="MI" />
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700">Billing postal code</label>
                <Input v-model="form.billing_postal_code" placeholder="49503" />
              </div>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700">Billing country</label>
              <Input v-model="form.billing_country" placeholder="USA" />
            </div>
          </div>
        </div>

        <!-- Notes -->
        <div class="pt-6 border-t border-gray-200">
          <label class="block text-sm font-medium text-gray-700">Notes</label>
          <Textarea v-model="form.notes" :rows="4" placeholder="Customer preferences, notes, or important details" />
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
import { reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import Button from '@/components/ui/Button.vue'
import Card from '@/components/ui/Card.vue'
import Input from '@/components/ui/Input.vue'
import Select from '@/components/ui/Select.vue'
import Textarea from '@/components/ui/Textarea.vue'
import { createCustomer } from '@/services/customer.service'
import { useToast } from '@/stores/toast'

const router = useRouter()
const toast = useToast()

const submitting = ref(false)
const error = ref('')
const billingAddressSameAsMain = ref(true)

const form = reactive({
  first_name: '',
  last_name: '',
  business_name: '',
  email: '',
  phone: '',
  external_reference: '',
  is_commercial: null,
  tax_exempt: null,
  street: '',
  city: '',
  state: '',
  postal_code: '',
  country: '',
  billing_street: '',
  billing_city: '',
  billing_state: '',
  billing_postal_code: '',
  billing_country: '',
  notes: ''
})

const errors = reactive({
  first_name: '',
  last_name: '',
  email: ''
})

// Copy main address to billing address when checkbox is checked
watch(billingAddressSameAsMain, (sameAsMain) => {
  if (sameAsMain) {
    form.billing_street = form.street
    form.billing_city = form.city
    form.billing_state = form.state
    form.billing_postal_code = form.postal_code
    form.billing_country = form.country
  }
})

// Sync billing address with main address when checkbox is checked
watch([() => form.street, () => form.city, () => form.state, () => form.postal_code, () => form.country], () => {
  if (billingAddressSameAsMain.value) {
    form.billing_street = form.street
    form.billing_city = form.city
    form.billing_state = form.state
    form.billing_postal_code = form.postal_code
    form.billing_country = form.country
  }
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

const resetErrors = () => {
  Object.keys(errors).forEach((key) => {
    errors[key] = ''
  })
}

const validateForm = () => {
  resetErrors()
  let isValid = true

  if (!form.first_name) {
    errors.first_name = 'First name is required'
    isValid = false
  }

  if (!form.last_name) {
    errors.last_name = 'Last name is required'
    isValid = false
  }

  if (!form.email) {
    errors.email = 'Email is required'
    isValid = false
  }

  return isValid
}

const submit = async () => {
  submitting.value = true
  error.value = ''

  try {
    if (!validateForm()) {
      const message = 'Please fill in the required fields before saving.'
      error.value = message
      toast.error(message)
      return
    }

    const payload = { ...form }

    // If billing address is same as main address, copy values
    if (form.is_commercial && billingAddressSameAsMain.value) {
      payload.billing_street = form.street
      payload.billing_city = form.city
      payload.billing_state = form.state
      payload.billing_postal_code = form.postal_code
      payload.billing_country = form.country
    }

    // Clear billing fields for non-commercial customers
    if (!form.is_commercial) {
      payload.billing_street = null
      payload.billing_city = null
      payload.billing_state = null
      payload.billing_postal_code = null
      payload.billing_country = null
    }

    const customer = await createCustomer(payload)
    router.push(`/cp/customers/${customer.id}`)
  } catch (err) {
    error.value = err?.response?.data?.message || err?.message || 'Failed to create customer'
    toast.error(error.value)
    if (err?.response?.data?.errors) {
      Object.assign(errors, err.response.data.errors)
      if (err.response.data.errors._form) {
        error.value = err.response.data.errors._form
      }
    }
  } finally {
    submitting.value = false
  }
}
</script>
