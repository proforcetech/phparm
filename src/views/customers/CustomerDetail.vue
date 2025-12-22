<template>
  <div>
    <div class="mb-6">
      <div class="flex items-center justify-between">
        <div class="flex items-center gap-4">
          <Button variant="ghost" @click="$router.push('/cp/customers')">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
            </svg>
          </Button>
          <div>
            <h1 class="text-2xl font-bold text-gray-900">Customer Details</h1>
            <p class="mt-1 text-sm text-gray-500">View and manage customer information</p>
          </div>
        </div>
        <Button v-if="customer && !editing" @click="startEditing">
          <svg class="h-4 w-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
          </svg>
          Edit Customer
        </Button>
      </div>
    </div>

    <div v-if="loading" class="flex justify-center py-12">
      <Loading size="xl" text="Loading customer..." />
    </div>

    <div v-else-if="customer" class="space-y-6">
      <!-- Editing Mode -->
      <Card v-if="editing">
        <template #header>
          <div class="flex items-center justify-between">
            <h3 class="text-lg font-medium text-gray-900">Edit Customer Information</h3>
            <div class="flex gap-2">
              <Button variant="outline" @click="cancelEditing" :disabled="saving">Cancel</Button>
              <Button @click="saveCustomer" :loading="saving">Save Changes</Button>
            </div>
          </div>
        </template>

        <div class="space-y-6">
          <!-- Basic Info -->
          <div>
            <h4 class="text-sm font-medium text-gray-900 mb-3">Basic Information</h4>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <Input
                v-model="editForm.first_name"
                label="First Name"
                placeholder="John"
                required
              />
              <Input
                v-model="editForm.last_name"
                label="Last Name"
                placeholder="Doe"
                required
              />
            </div>

            <div v-if="editForm.is_commercial" class="mt-4">
              <Input
                v-model="editForm.business_name"
                label="Business Name"
                placeholder="ABC Company LLC"
              />
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
              <Input
                v-model="editForm.email"
                type="email"
                label="Email"
                placeholder="customer@example.com"
                required
              />
              <Input
                v-model="editForm.phone"
                label="Phone"
                placeholder="(555) 555-5555"
              />
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
              <div class="space-y-2">
                <label class="block text-sm font-medium text-gray-700">Customer Type</label>
                <div class="flex gap-4">
                  <label class="flex items-center gap-2">
                    <input v-model="editForm.is_commercial" type="checkbox" class="h-4 w-4 text-indigo-600 rounded" />
                    <span class="text-sm text-gray-700">Commercial</span>
                  </label>
                  <label class="flex items-center gap-2">
                    <input v-model="editForm.tax_exempt" type="checkbox" class="h-4 w-4 text-indigo-600 rounded" />
                    <span class="text-sm text-gray-700">Tax Exempt</span>
                  </label>
                </div>
              </div>
              <Input
                v-model="editForm.external_reference"
                label="External Reference"
                placeholder="CRM-12345"
              />
            </div>
          </div>

          <!-- Address -->
          <div class="pt-6 border-t">
            <h4 class="text-sm font-medium text-gray-900 mb-3">Address</h4>
            <div class="space-y-4">
              <Input
                v-model="editForm.street"
                label="Street Address"
                placeholder="123 Main St"
              />
              <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <Input
                  v-model="editForm.city"
                  label="City"
                  placeholder="Grand Rapids"
                />
                <Input
                  v-model="editForm.state"
                  label="State"
                  placeholder="MI"
                />
                <Input
                  v-model="editForm.postal_code"
                  label="Postal Code"
                  placeholder="49503"
                />
              </div>
              <Input
                v-model="editForm.country"
                label="Country"
                placeholder="USA"
              />
            </div>
          </div>

          <!-- Billing Address (Commercial Only) -->
          <div v-if="editForm.is_commercial" class="pt-6 border-t">
            <h4 class="text-sm font-medium text-gray-900 mb-3">Billing Address</h4>
            <div class="space-y-4">
              <Input
                v-model="editForm.billing_street"
                label="Billing Street Address"
                placeholder="456 Business Blvd"
              />
              <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <Input
                  v-model="editForm.billing_city"
                  label="Billing City"
                  placeholder="Grand Rapids"
                />
                <Input
                  v-model="editForm.billing_state"
                  label="Billing State"
                  placeholder="MI"
                />
                <Input
                  v-model="editForm.billing_postal_code"
                  label="Billing Postal Code"
                  placeholder="49503"
                />
              </div>
              <Input
                v-model="editForm.billing_country"
                label="Billing Country"
                placeholder="USA"
              />
            </div>
          </div>

          <!-- Notes -->
          <div class="pt-6 border-t">
            <Textarea
              v-model="editForm.notes"
              label="Notes"
              :rows="4"
              placeholder="Customer notes and special instructions..."
            />
          </div>
        </div>
      </Card>

      <!-- View Mode -->
      <div v-else>
        <!-- Customer Info Card -->
        <Card>
          <template #header>
            <div class="flex items-center justify-between">
              <div class="flex items-center gap-3">
                <div class="h-12 w-12 rounded-full bg-indigo-100 flex items-center justify-center">
                  <svg class="h-6 w-6 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                  </svg>
                </div>
                <div>
                  <h3 class="text-xl font-bold text-gray-900">
                    {{ customer.first_name }} {{ customer.last_name }}
                    <span v-if="customer.business_name" class="text-gray-500 text-base font-normal">
                      ({{ customer.business_name }})
                    </span>
                  </h3>
                  <p class="text-sm text-gray-500">Customer ID: {{ customer.id }}</p>
                </div>
              </div>
              <div class="flex gap-2">
                <Badge :variant="customer.is_commercial ? 'primary' : 'secondary'">
                  {{ customer.is_commercial ? 'Commercial' : 'Consumer' }}
                </Badge>
                <Badge :variant="customer.tax_exempt ? 'success' : 'secondary'">
                  {{ customer.tax_exempt ? 'Tax Exempt' : 'Taxable' }}
                </Badge>
              </div>
            </div>
          </template>

          <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <div>
              <div class="flex items-center gap-2 mb-1">
                <svg class="h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                </svg>
                <p class="text-xs font-medium text-gray-500 uppercase">Email</p>
              </div>
              <p class="text-sm text-gray-900">{{ customer.email || '—' }}</p>
            </div>

            <div>
              <div class="flex items-center gap-2 mb-1">
                <svg class="h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                </svg>
                <p class="text-xs font-medium text-gray-500 uppercase">Phone</p>
              </div>
              <p class="text-sm text-gray-900">{{ customer.phone || '—' }}</p>
            </div>

            <div>
              <div class="flex items-center gap-2 mb-1">
                <svg class="h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                </svg>
                <p class="text-xs font-medium text-gray-500 uppercase">Customer Since</p>
              </div>
              <p class="text-sm text-gray-900">{{ formatDate(customer.created_at) }}</p>
            </div>

            <div v-if="customer.external_reference">
              <div class="flex items-center gap-2 mb-1">
                <svg class="h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 20l4-16m2 16l4-16M6 9h14M4 15h14" />
                </svg>
                <p class="text-xs font-medium text-gray-500 uppercase">External Reference</p>
              </div>
              <p class="text-sm text-gray-900">{{ customer.external_reference }}</p>
            </div>
          </div>

          <!-- Address Section -->
          <div v-if="hasAddress" class="mt-6 pt-6 border-t border-gray-200">
            <div class="flex items-start gap-2">
              <svg class="h-5 w-5 text-gray-400 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
              </svg>
              <div class="flex-1">
                <p class="text-sm font-medium text-gray-700 mb-1">Address</p>
                <p class="text-sm text-gray-600">
                  {{ customer.street }}<br v-if="customer.street">
                  <span v-if="customer.city || customer.state || customer.postal_code">
                    {{ customer.city }}<span v-if="customer.city && customer.state">, </span>{{ customer.state }} {{ customer.postal_code }}
                  </span>
                  <br v-if="customer.country">
                  <span v-if="customer.country">{{ customer.country }}</span>
                </p>
              </div>
            </div>
          </div>

          <!-- Billing Address Section (Commercial Only, if different) -->
          <div v-if="customer.is_commercial && hasBillingAddress && !isBillingAddressSameAsMain" class="mt-6 pt-6 border-t border-gray-200">
            <div class="flex items-start gap-2">
              <svg class="h-5 w-5 text-gray-400 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
              </svg>
              <div class="flex-1">
                <p class="text-sm font-medium text-gray-700 mb-1">Billing Address</p>
                <p class="text-sm text-gray-600">
                  {{ customer.billing_street }}<br v-if="customer.billing_street">
                  <span v-if="customer.billing_city || customer.billing_state || customer.billing_postal_code">
                    {{ customer.billing_city }}<span v-if="customer.billing_city && customer.billing_state">, </span>{{ customer.billing_state }} {{ customer.billing_postal_code }}
                  </span>
                  <br v-if="customer.billing_country">
                  <span v-if="customer.billing_country">{{ customer.billing_country }}</span>
                </p>
              </div>
            </div>
          </div>

          <div v-if="customer.notes" class="mt-6 pt-6 border-t border-gray-200">
            <div class="flex items-start gap-2">
              <svg class="h-5 w-5 text-gray-400 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z" />
              </svg>
              <div class="flex-1">
                <p class="text-sm font-medium text-gray-700 mb-1">Notes</p>
                <p class="text-sm text-gray-600">{{ customer.notes }}</p>
              </div>
            </div>
          </div>
        </Card>

        <!-- Vehicles Card -->
        <Card v-if="vehicles.length > 0">
          <template #header>
            <h3 class="text-lg font-medium text-gray-900">Vehicles ({{ vehicles.length }})</h3>
          </template>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div
              v-for="vehicle in vehicles"
              :key="vehicle.id"
              class="border border-gray-200 rounded-lg p-4 hover:border-indigo-300 transition-colors"
            >
              <div class="flex items-start justify-between">
                <div class="flex-1">
                  <h4 class="font-medium text-gray-900">
                    {{ vehicle.year }} {{ vehicle.make }} {{ vehicle.model }}
                  </h4>
                  <p class="text-sm text-gray-500 mt-1">{{ vehicle.vin || 'No VIN' }}</p>
                  <div class="flex gap-4 mt-2 text-xs text-gray-600">
                    <span v-if="vehicle.color">Color: {{ vehicle.color }}</span>
                    <span v-if="vehicle.license_plate">{{ vehicle.license_plate }}</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </Card>
      </div>
    </div>

    <div v-else class="text-center py-12">
      <p class="text-gray-500">Customer not found.</p>
    </div>
  </div>
</template>

<script setup>
import { onMounted, ref, reactive, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Badge from '@/components/ui/Badge.vue'
import Button from '@/components/ui/Button.vue'
import Card from '@/components/ui/Card.vue'
import Input from '@/components/ui/Input.vue'
import Textarea from '@/components/ui/Textarea.vue'
import Loading from '@/components/ui/Loading.vue'
import customerService from '@/services/customer.service'
import { useToast } from '@/stores/toast'

const route = useRoute()
const router = useRouter()
const toast = useToast()

const customer = ref(null)
const vehicles = ref([])
const loading = ref(true)
const saving = ref(false)
const editing = ref(false)

const editForm = reactive({
  first_name: '',
  last_name: '',
  business_name: '',
  email: '',
  phone: '',
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
  is_commercial: false,
  tax_exempt: false,
  notes: '',
  external_reference: ''
})

// Check if customer has an address
const hasAddress = computed(() => {
  if (!customer.value) return false
  return !!(customer.value.street || customer.value.city || customer.value.state ||
            customer.value.postal_code || customer.value.country)
})

// Check if customer has a billing address
const hasBillingAddress = computed(() => {
  if (!customer.value) return false
  return !!(customer.value.billing_street || customer.value.billing_city || customer.value.billing_state ||
            customer.value.billing_postal_code || customer.value.billing_country)
})

// Check if billing address is same as main address
const isBillingAddressSameAsMain = computed(() => {
  if (!customer.value) return true
  return customer.value.billing_street === customer.value.street &&
         customer.value.billing_city === customer.value.city &&
         customer.value.billing_state === customer.value.state &&
         customer.value.billing_postal_code === customer.value.postal_code &&
         customer.value.billing_country === customer.value.country
})

function formatDate(dateString) {
  if (!dateString) return '—'
  const date = new Date(dateString)
  return date.toLocaleDateString('en-US', {
    year: 'numeric',
    month: 'long',
    day: 'numeric'
  })
}

function startEditing() {
  editing.value = true
  // Populate form with current customer data
  Object.assign(editForm, {
    first_name: customer.value.first_name || '',
    last_name: customer.value.last_name || '',
    business_name: customer.value.business_name || '',
    email: customer.value.email || '',
    phone: customer.value.phone || '',
    street: customer.value.street || '',
    city: customer.value.city || '',
    state: customer.value.state || '',
    postal_code: customer.value.postal_code || '',
    country: customer.value.country || '',
    billing_street: customer.value.billing_street || '',
    billing_city: customer.value.billing_city || '',
    billing_state: customer.value.billing_state || '',
    billing_postal_code: customer.value.billing_postal_code || '',
    billing_country: customer.value.billing_country || '',
    is_commercial: Boolean(customer.value.is_commercial),
    tax_exempt: Boolean(customer.value.tax_exempt),
    notes: customer.value.notes || '',
    external_reference: customer.value.external_reference || ''
  })
}

function cancelEditing() {
  editing.value = false
}

async function saveCustomer() {
  saving.value = true
  try {
    const updated = await customerService.updateCustomer(route.params.id, editForm)
    customer.value = updated
    editing.value = false
    toast.success('Customer updated successfully')
  } catch (error) {
    console.error('Failed to update customer:', error)
    toast.error(error.response?.data?.message || 'Failed to update customer')
  } finally {
    saving.value = false
  }
}

async function loadCustomer() {
  loading.value = true
  try {
    customer.value = await customerService.getCustomer(route.params.id)
    // Load customer vehicles
    vehicles.value = await customerService.getCustomerVehicles(route.params.id)
  } catch (error) {
    console.error('Failed to load customer:', error)
    toast.error('Failed to load customer details')
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  if (!route.params.id || route.params.id === 'create') {
    router.push('/cp/customers')
    return
  }
  loadCustomer()
})
</script>
