<template>
  <div>
    <div class="mb-6">
      <div class="flex items-center justify-between">
        <div>
          <h1 class="text-2xl font-bold text-gray-900">User Management</h1>
          <p class="mt-1 text-sm text-gray-500">Manage system users, roles, and permissions</p>
        </div>
        <Button @click="$router.push('/cp/users/create')">
          <svg class="h-4 w-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
          </svg>
          Create User
        </Button>
      </div>
    </div>

    <!-- Filters -->
    <Card class="mb-6">
      <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Search</label>
          <Input
            v-model="filters.query"
            placeholder="Search by name, email, or ID..."
            @input="loadUsers"
          />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Role</label>
          <Select
            v-model="filters.role"
            :options="roleOptions"
            @change="loadUsers"
          />
        </div>
      </div>
    </Card>

    <div v-if="loading" class="flex justify-center py-12">
      <Loading size="xl" text="Loading users..." />
    </div>

    <Card v-else>
      <template #header>
        <h3 class="text-lg font-medium text-gray-900">Users ({{ users.length }})</h3>
      </template>

      <div v-if="users.length === 0" class="text-center py-12 text-gray-500">
        No users found
      </div>

      <div v-else class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
          <thead class="bg-gray-50">
            <tr>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">User</th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Role</th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Created</th>
              <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
            </tr>
          </thead>
          <tbody class="bg-white divide-y divide-gray-200">
            <tr v-for="user in users" :key="user.id" class="hover:bg-gray-50">
              <td class="px-6 py-4 whitespace-nowrap">
                <div class="flex items-center">
                  <div class="h-10 w-10 rounded-full bg-indigo-100 flex items-center justify-center">
                    <svg class="h-5 w-5 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                    </svg>
                  </div>
                  <div class="ml-4">
                    <div class="text-sm font-medium text-gray-900">{{ user.name }}</div>
                    <div class="text-sm text-gray-500">{{ user.email }}</div>
                  </div>
                </div>
              </td>
              <td class="px-6 py-4 whitespace-nowrap">
                <Badge :variant="getRoleBadgeVariant(user.role)">
                  {{ getRoleLabel(user.role) }}
                </Badge>
              </td>
              <td class="px-6 py-4 whitespace-nowrap">
                <div class="flex flex-col gap-1">
                  <Badge v-if="user.email_verified" variant="success" size="sm">Email Verified</Badge>
                  <Badge v-else variant="secondary" size="sm">Email Not Verified</Badge>
                  <Badge v-if="user.two_factor_enabled" variant="primary" size="sm">2FA Enabled</Badge>
                </div>
              </td>
              <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                {{ formatDate(user.created_at) }}
              </td>
              <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                <div class="flex items-center justify-end gap-2">
                  <Button variant="ghost" size="sm" @click="$router.push(`/cp/users/${user.id}`)">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                    </svg>
                  </Button>
                  <Button
                    v-if="user.two_factor_enabled"
                    variant="ghost"
                    size="sm"
                    @click="confirmReset2FA(user)"
                  >
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                    </svg>
                  </Button>
                  <Button
                    variant="ghost"
                    size="sm"
                    @click="confirmDelete(user)"
                  >
                    <svg class="h-4 w-4 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                    </svg>
                  </Button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </Card>

    <!-- Delete Confirmation Modal -->
    <div v-if="showDeleteModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50" @click.self="showDeleteModal = false">
      <Card class="max-w-md w-full mx-4">
        <template #header>
          <h3 class="text-lg font-medium text-gray-900">Confirm Delete</h3>
        </template>
        <p class="text-sm text-gray-600 mb-4">
          Are you sure you want to delete <strong>{{ userToDelete?.name }}</strong>? This action cannot be undone.
        </p>
        <div class="flex justify-end gap-2">
          <Button variant="outline" @click="showDeleteModal = false">Cancel</Button>
          <Button variant="danger" @click="handleDelete" :loading="deleting">Delete User</Button>
        </div>
      </Card>
    </div>

    <!-- Reset 2FA Confirmation Modal -->
    <div v-if="showReset2FAModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50" @click.self="showReset2FAModal = false">
      <Card class="max-w-md w-full mx-4">
        <template #header>
          <h3 class="text-lg font-medium text-gray-900">Reset Two-Factor Authentication</h3>
        </template>
        <p class="text-sm text-gray-600 mb-4">
          Are you sure you want to reset 2FA for <strong>{{ userToReset2FA?.name }}</strong>? They will need to set it up again.
        </p>
        <div class="flex justify-end gap-2">
          <Button variant="outline" @click="showReset2FAModal = false">Cancel</Button>
          <Button @click="handleReset2FA" :loading="resetting2FA">Reset 2FA</Button>
        </div>
      </Card>
    </div>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import Badge from '@/components/ui/Badge.vue'
import Button from '@/components/ui/Button.vue'
import Card from '@/components/ui/Card.vue'
import Input from '@/components/ui/Input.vue'
import Select from '@/components/ui/Select.vue'
import Loading from '@/components/ui/Loading.vue'
import userService from '@/services/user.service'
import { useToast } from '@/stores/toast'

const toast = useToast()

const users = ref([])
const loading = ref(true)
const deleting = ref(false)
const resetting2FA = ref(false)
const showDeleteModal = ref(false)
const showReset2FAModal = ref(false)
const userToDelete = ref(null)
const userToReset2FA = ref(null)

const filters = reactive({
  query: '',
  role: ''
})

const roleOptions = [
  { label: 'All Roles', value: '' },
  { label: 'Admin', value: 'admin' },
  { label: 'Manager', value: 'manager' },
  { label: 'Technician', value: 'technician' },
  { label: 'Customer', value: 'customer' }
]

function getRoleLabel(role) {
  const roleMap = {
    admin: 'Admin',
    manager: 'Manager',
    technician: 'Technician',
    customer: 'Customer'
  }
  return roleMap[role] || role
}

function getRoleBadgeVariant(role) {
  const variantMap = {
    admin: 'danger',
    manager: 'warning',
    technician: 'primary',
    customer: 'secondary'
  }
  return variantMap[role] || 'secondary'
}

function formatDate(dateString) {
  if (!dateString) return '—'
  const date = new Date(dateString)
  return date.toLocaleDateString('en-US', {
    year: 'numeric',
    month: 'short',
    day: 'numeric'
  })
}

async function loadUsers() {
  loading.value = true
  try {
    users.value = await userService.listUsers(filters)
  } catch (error) {
    console.error('Failed to load users:', error)
    toast.error('Failed to load users')
  } finally {
    loading.value = false
  }
}

function confirmDelete(user) {
  userToDelete.value = user
  showDeleteModal.value = true
}

async function handleDelete() {
  if (!userToDelete.value) return

  deleting.value = true
  try {
    await userService.deleteUser(userToDelete.value.id)
    toast.success('User deleted successfully')
    showDeleteModal.value = false
    userToDelete.value = null
    loadUsers()
  } catch (error) {
    console.error('Failed to delete user:', error)
    toast.error(error.response?.data?.message || 'Failed to delete user')
  } finally {
    deleting.value = false
  }
}

function confirmReset2FA(user) {
  userToReset2FA.value = user
  showReset2FAModal.value = true
}

async function handleReset2FA() {
  if (!userToReset2FA.value) return

  resetting2FA.value = true
  try {
    await userService.reset2FA(userToReset2FA.value.id)
    toast.success('2FA reset successfully')
    showReset2FAModal.value = false
    userToReset2FA.value = null
    loadUsers()
  } catch (error) {
    console.error('Failed to reset 2FA:', error)
    toast.error(error.response?.data?.message || 'Failed to reset 2FA')
  } finally {
    resetting2FA.value = false
  }
}

onMounted(loadUsers)
</script>
