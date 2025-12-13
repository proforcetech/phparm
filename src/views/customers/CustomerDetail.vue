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
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <Input
              v-model="editForm.name"
              label="Full Name"
              placeholder="Customer name"
              required
            />
            <Input
              v-model="editForm.email"
              type="email"
              label="Email"
              placeholder="customer@example.com"
            />
          </div>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <Input
              v-model="editForm.phone"
              label="Phone"
              placeholder="(555) 555-5555"
            />
            <div class="space-y-2">
              <label class="block text-sm font-medium text-gray-700">Customer Type</label>
              <div class="flex gap-4">
                <label class="flex items-center gap-2">
                  <input v-model="editForm.commercial" type="checkbox" class="h-4 w-4 text-indigo-600 rounded" />
                  <span class="text-sm text-gray-700">Commercial</span>
                </label>
                <label class="flex items-center gap-2">
                  <input v-model="editForm.tax_exempt" type="checkbox" class="h-4 w-4 text-indigo-600 rounded" />
                  <span class="text-sm text-gray-700">Tax Exempt</span>
                </label>
              </div>
            </div>
          </div>

          <Textarea
            v-model="editForm.notes"
            label="Notes"
            :rows="4"
            placeholder="Customer notes and special instructions..."
          />
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
                  <h3 class="text-xl font-bold text-gray-900">{{ customer.name }}</h3>
                  <p class="text-sm text-gray-500">Customer ID: {{ customer.id }}</p>
                </div>
              </div>
              <div class="flex gap-2">
                <Badge :variant="customer.commercial ? 'primary' : 'secondary'">
                  {{ customer.commercial ? 'Commercial' : 'Consumer' }}
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
import { onMounted, ref, reactive } from 'vue'
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
  name: '',
  email: '',
  phone: '',
  commercial: false,
  tax_exempt: false,
  notes: ''
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
    name: customer.value.name || '',
    email: customer.value.email || '',
    phone: customer.value.phone || '',
    commercial: Boolean(customer.value.commercial),
    tax_exempt: Boolean(customer.value.tax_exempt),
    notes: customer.value.notes || ''
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
