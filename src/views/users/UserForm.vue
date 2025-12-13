<template>
  <div>
    <div class="mb-6">
      <div class="flex items-center justify-between">
        <div class="flex items-center gap-4">
          <Button variant="ghost" @click="$router.push('/cp/users')">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
            </svg>
          </Button>
          <div>
            <h1 class="text-2xl font-bold text-gray-900">{{ isEditMode ? 'Edit User' : 'Create User' }}</h1>
            <p class="mt-1 text-sm text-gray-500">{{ isEditMode ? 'Update user information and permissions' : 'Add a new user to the system' }}</p>
          </div>
        </div>
      </div>
    </div>

    <div v-if="loading" class="flex justify-center py-12">
      <Loading size="xl" text="Loading user..." />
    </div>

    <div v-else class="space-y-6">
      <Card>
        <template #header>
          <h3 class="text-lg font-medium text-gray-900">User Information</h3>
        </template>

        <div class="space-y-6">
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <Input
              v-model="form.name"
              label="Full Name *"
              placeholder="John Doe"
              required
              :error="errors.name"
            />
            <Input
              v-model="form.email"
              type="email"
              label="Email *"
              placeholder="john@example.com"
              required
              :error="errors.email"
            />
          </div>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Password {{ isEditMode ? '' : '*' }}</label>
              <Input
                v-model="form.password"
                type="password"
                :placeholder="isEditMode ? 'Leave blank to keep current password' : 'Enter password'"
                :required="!isEditMode"
                :error="errors.password"
              />
              <p class="mt-1 text-xs text-gray-500">Minimum 12 characters</p>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Role *</label>
              <Select
                v-model="form.role"
                :options="roleOptions"
                required
                :error="errors.role"
              />
            </div>
          </div>

          <div class="border-t border-gray-200 pt-6">
            <h4 class="text-sm font-medium text-gray-900 mb-4">Account Status</h4>
            <div class="space-y-4">
              <label class="flex items-center gap-2">
                <input v-model="form.email_verified" type="checkbox" class="h-4 w-4 text-indigo-600 rounded" />
                <span class="text-sm text-gray-700">Email Verified</span>
              </label>

              <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Two-Factor Authentication</label>
                <Select
                  v-model="form.two_factor_type"
                  :options="twoFactorOptions"
                />
                <p v-if="form.two_factor_type !== 'none'" class="mt-1 text-xs text-gray-500">
                  {{ getTwoFactorDescription(form.two_factor_type) }}
                </p>
              </div>
            </div>
          </div>
        </div>
      </Card>

      <Card v-if="form.role">
        <template #header>
          <h3 class="text-lg font-medium text-gray-900">Role Permissions</h3>
        </template>

        <div class="space-y-4">
          <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
            <h4 class="text-sm font-semibold text-blue-900 mb-2">{{ getRoleLabel(form.role) }}</h4>
            <p class="text-sm text-blue-800 mb-3">{{ getRoleDescription(form.role) }}</p>
            <div class="flex flex-wrap gap-2">
              <Badge v-for="permission in getRolePermissions(form.role)" :key="permission" size="sm" variant="primary">
                {{ permission }}
              </Badge>
            </div>
          </div>
        </div>
      </Card>

      <div class="flex justify-end gap-2">
        <Button variant="outline" @click="$router.push('/cp/users')">Cancel</Button>
        <Button @click="handleSubmit" :loading="saving">
          {{ isEditMode ? 'Update User' : 'Create User' }}
        </Button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Badge from '@/components/ui/Badge.vue'
import Button from '@/components/ui/Button.vue'
import Card from '@/components/ui/Card.vue'
import Input from '@/components/ui/Input.vue'
import Select from '@/components/ui/Select.vue'
import Loading from '@/components/ui/Loading.vue'
import userService from '@/services/user.service'
import { useToast } from '@/stores/toast'

const route = useRoute()
const router = useRouter()
const toast = useToast()

const loading = ref(false)
const saving = ref(false)
const isEditMode = computed(() => route.params.id !== 'create')

const form = reactive({
  name: '',
  email: '',
  password: '',
  role: 'technician',
  email_verified: false,
  two_factor_type: 'none'
})

const errors = reactive({
  name: '',
  email: '',
  password: '',
  role: ''
})

const roleOptions = [
  { label: 'Admin', value: 'admin' },
  { label: 'Manager', value: 'manager' },
  { label: 'Technician', value: 'technician' },
  { label: 'Customer', value: 'customer' }
]

const twoFactorOptions = [
  { label: 'Disabled', value: 'none' },
  { label: 'Authenticator App (TOTP)', value: 'totp' },
  { label: 'SMS', value: 'sms' },
  { label: 'Email', value: 'email' }
]

const roleInfo = {
  admin: {
    label: 'Admin',
    description: 'Full control across all modules',
    permissions: ['*']
  },
  manager: {
    label: 'Manager',
    description: 'Manage shop operations, estimates, invoices, schedules, inventory',
    permissions: ['users.view', 'users.invite', 'users.update', 'customers.*', 'vehicles.*', 'estimates.*', 'invoices.*', 'payments.*', 'appointments.*', 'inventory.*', 'inspections.*', 'cms.*']
  },
  technician: {
    label: 'Technician',
    description: 'Work estimates, inspections, jobs, and time tracking',
    permissions: ['customers.view', 'vehicles.view', 'estimates.view', 'estimates.create', 'estimates.update', 'inspections.*', 'time.*', 'appointments.view']
  },
  customer: {
    label: 'Customer',
    description: 'Customer portal scoped to their profile and documents',
    permissions: ['portal.profile', 'portal.vehicles', 'portal.estimates', 'portal.invoices', 'portal.warranty', 'portal.reminders']
  }
}

function getRoleLabel(role) {
  return roleInfo[role]?.label || role
}

function getRoleDescription(role) {
  return roleInfo[role]?.description || ''
}

function getRolePermissions(role) {
  return roleInfo[role]?.permissions || []
}

function getTwoFactorDescription(type) {
  const descriptions = {
    totp: 'User will need an authenticator app like Google Authenticator or Authy',
    sms: 'User will receive verification codes via SMS',
    email: 'User will receive verification codes via email'
  }
  return descriptions[type] || ''
}

function validateForm() {
  let isValid = true

  // Reset errors
  Object.keys(errors).forEach(key => errors[key] = '')

  if (!form.name) {
    errors.name = 'Name is required'
    isValid = false
  }

  if (!form.email) {
    errors.email = 'Email is required'
    isValid = false
  } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email)) {
    errors.email = 'Invalid email format'
    isValid = false
  }

  if (!isEditMode.value && !form.password) {
    errors.password = 'Password is required'
    isValid = false
  }

  if (form.password && form.password.length < 12) {
    errors.password = 'Password must be at least 12 characters'
    isValid = false
  }

  if (!form.role) {
    errors.role = 'Role is required'
    isValid = false
  }

  return isValid
}

async function handleSubmit() {
  if (!validateForm()) {
    toast.error('Please fix the errors in the form')
    return
  }

  saving.value = true
  try {
    const payload = {
      name: form.name,
      email: form.email,
      role: form.role,
      email_verified: form.email_verified,
      two_factor_type: form.two_factor_type,
      two_factor_enabled: form.two_factor_type !== 'none'
    }

    // Only include password if it's set
    if (form.password) {
      payload.password = form.password
    }

    if (isEditMode.value) {
      await userService.updateUser(route.params.id, payload)
      toast.success('User updated successfully')
    } else {
      await userService.createUser(payload)
      toast.success('User created successfully')
    }

    router.push('/cp/users')
  } catch (error) {
    console.error('Failed to save user:', error)
    const errorMessage = error.response?.data?.message || 'Failed to save user'
    toast.error(errorMessage)

    // Handle specific field errors
    if (error.response?.data?.errors) {
      Object.assign(errors, error.response.data.errors)
    }
  } finally {
    saving.value = false
  }
}

async function loadUser() {
  if (!isEditMode.value) return

  loading.value = true
  try {
    const user = await userService.getUser(route.params.id)
    Object.assign(form, {
      name: user.name,
      email: user.email,
      role: user.role,
      email_verified: user.email_verified,
      two_factor_type: user.two_factor_type || 'none',
      password: '' // Never load password
    })
  } catch (error) {
    console.error('Failed to load user:', error)
    toast.error('Failed to load user')
    router.push('/cp/users')
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  if (isEditMode.value) {
    loadUser()
  }
})
</script>
