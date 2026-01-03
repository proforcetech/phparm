<template>
  <div>
    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Service Types</h1>
        <p class="mt-1 text-sm text-gray-500">Manage service categories used across bundles and estimates.</p>
      </div>
      <Button @click="startCreate">
        <svg class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
        </svg>
        New Service Type
      </Button>
    </div>

    <Alert v-if="errorMessage" variant="danger" class="mb-4">{{ errorMessage }}</Alert>

    <Card>
      <div class="flex flex-col gap-4">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
          <div>
            <label class="block text-sm font-medium text-gray-700">Search</label>
            <Input
              v-model="search"
              class="mt-1"
              placeholder="Search service types"
              @input="handleSearch"
            />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700">Status</label>
            <select
              v-model="statusFilter"
              class="mt-1 w-full rounded border-gray-300"
              @change="loadServiceTypes"
            >
              <option value="all">All</option>
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
            </select>
          </div>
        </div>

        <div class="relative">
          <Table :columns="columns" :data="serviceTypes" :loading="loading">
            <template #cell(name)="{ row }">
              <div class="flex items-start gap-3">
                <span
                  v-if="row.color"
                  class="mt-1 h-3 w-3 rounded-full border border-gray-200"
                  :style="{ backgroundColor: row.color }"
                ></span>
                <div>
                  <div class="font-semibold text-gray-900">{{ row.name }}</div>
                  <div v-if="row.description" class="text-xs text-gray-500">
                    {{ row.description }}
                  </div>
                </div>
              </div>
            </template>
            <template #cell(alias)="{ value }">
              <span class="text-sm text-gray-600">{{ value || '—' }}</span>
            </template>
            <template #cell(color)="{ value }">
              <span class="text-sm text-gray-600">{{ value || '—' }}</span>
            </template>
            <template #cell(active)="{ value }">
              <Badge :variant="value ? 'success' : 'secondary'" rounded>
                {{ value ? 'Active' : 'Inactive' }}
              </Badge>
            </template>
            <template #actions="{ row }">
              <div class="flex flex-wrap justify-end gap-2">
                <Button size="sm" variant="secondary" @click="startEdit(row)">Edit</Button>
                <Button
                  size="sm"
                  variant="secondary"
                  :loading="togglingId === row.id"
                  @click="toggleActive(row)"
                >
                  {{ row.active ? 'Deactivate' : 'Activate' }}
                </Button>
                <Button
                  size="sm"
                  variant="danger"
                  :loading="deletingId === row.id"
                  @click="deleteServiceType(row)"
                >
                  Delete
                </Button>
              </div>
            </template>
            <template #empty>
              <p class="text-sm text-gray-500">No service types found.</p>
            </template>
          </Table>
        </div>
      </div>
    </Card>

    <Modal v-model="showModal" :title="modalTitle" size="lg" @close="closeModal">
      <div class="space-y-4">
        <Alert v-if="formError" variant="danger">{{ formError }}</Alert>
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
          <div>
            <label class="block text-sm font-medium text-gray-700">Name</label>
            <Input v-model="form.name" class="mt-1" placeholder="General Service" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700">Alias</label>
            <Input v-model="form.alias" class="mt-1" placeholder="general" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700">Color</label>
            <Input v-model="form.color" class="mt-1" placeholder="#1D4ED8" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700">Icon</label>
            <Input v-model="form.icon" class="mt-1" placeholder="wrench" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700">Display Order</label>
            <Input v-model.number="form.display_order" class="mt-1" type="number" min="0" />
          </div>
          <div class="flex items-center gap-2 pt-7">
            <input
              id="service-type-active"
              v-model="form.active"
              type="checkbox"
              class="h-4 w-4 rounded border-gray-300 text-primary-600 focus:ring-primary-500"
            />
            <label for="service-type-active" class="text-sm text-gray-700">Active</label>
          </div>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Description</label>
          <Textarea v-model="form.description" class="mt-1" rows="3" placeholder="Optional description" />
        </div>
      </div>

      <template #footer>
        <div class="flex justify-end gap-3">
          <Button variant="secondary" @click="closeModal">Cancel</Button>
          <Button :loading="saving" @click="saveServiceType">
            {{ editingItem ? 'Save Changes' : 'Create Service Type' }}
          </Button>
        </div>
      </template>
    </Modal>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import Alert from '@/components/ui/Alert.vue'
import Badge from '@/components/ui/Badge.vue'
import Button from '@/components/ui/Button.vue'
import Card from '@/components/ui/Card.vue'
import Input from '@/components/ui/Input.vue'
import Modal from '@/components/ui/Modal.vue'
import Table from '@/components/ui/Table.vue'
import Textarea from '@/components/ui/Textarea.vue'
import api from '@/services/api'
import { useToast } from '@/stores/toast'

const toast = useToast()
const serviceTypes = ref([])
const loading = ref(false)
const saving = ref(false)
const deletingId = ref(null)
const togglingId = ref(null)
const showModal = ref(false)
const editingItem = ref(null)
const search = ref('')
const statusFilter = ref('all')
const errorMessage = ref('')
const formError = ref('')
const searchTimeout = ref(null)

const form = reactive({
  name: '',
  alias: '',
  color: '',
  icon: '',
  description: '',
  active: true,
  display_order: 0,
})

const columns = [
  { key: 'name', label: 'Name' },
  { key: 'alias', label: 'Alias' },
  { key: 'color', label: 'Color' },
  { key: 'display_order', label: 'Order' },
  { key: 'active', label: 'Status' },
]

const modalTitle = computed(() => (editingItem.value ? 'Edit Service Type' : 'New Service Type'))

const resetForm = () => {
  form.name = ''
  form.alias = ''
  form.color = ''
  form.icon = ''
  form.description = ''
  form.active = true
  form.display_order = 0
}

const loadServiceTypes = async () => {
  loading.value = true
  errorMessage.value = ''
  try {
    const params = {}
    if (search.value.trim()) {
      params.query = search.value.trim()
    }
    if (statusFilter.value === 'active') {
      params.active = 1
    }
    if (statusFilter.value === 'inactive') {
      params.active = 0
    }
    const response = await api.get('/service-types', { params })
    serviceTypes.value = response.data || []
  } catch (err) {
    console.error(err)
    errorMessage.value = 'Unable to load service types.'
    serviceTypes.value = []
  } finally {
    loading.value = false
  }
}

const handleSearch = () => {
  if (searchTimeout.value) {
    clearTimeout(searchTimeout.value)
  }
  searchTimeout.value = setTimeout(() => {
    loadServiceTypes()
  }, 300)
}

const startCreate = () => {
  editingItem.value = null
  resetForm()
  formError.value = ''
  showModal.value = true
}

const startEdit = (item) => {
  editingItem.value = item
  form.name = item.name || ''
  form.alias = item.alias || ''
  form.color = item.color || ''
  form.icon = item.icon || ''
  form.description = item.description || ''
  form.active = Boolean(item.active)
  form.display_order = item.display_order ?? 0
  formError.value = ''
  showModal.value = true
}

const closeModal = () => {
  showModal.value = false
  editingItem.value = null
  resetForm()
  formError.value = ''
}

const saveServiceType = async () => {
  saving.value = true
  formError.value = ''
  try {
    const payload = {
      name: form.name,
      alias: form.alias || null,
      color: form.color || null,
      icon: form.icon || null,
      description: form.description || null,
      active: form.active,
      display_order: Number(form.display_order) || 0,
    }

    if (editingItem.value) {
      await api.put(`/service-types/${editingItem.value.id}`, payload)
      toast.success('Service type updated')
    } else {
      await api.post('/service-types', payload)
      toast.success('Service type created')
    }

    closeModal()
    await loadServiceTypes()
  } catch (err) {
    console.error(err)
    formError.value = err.response?.data?.message || 'Unable to save service type.'
  } finally {
    saving.value = false
  }
}

const toggleActive = async (item) => {
  const nextActive = !item.active
  const actionLabel = nextActive ? 'activate' : 'deactivate'
  if (!confirm(`Are you sure you want to ${actionLabel} ${item.name}?`)) return

  togglingId.value = item.id
  try {
    await api.put(`/service-types/${item.id}`, { active: nextActive })
    toast.success(`Service type ${nextActive ? 'activated' : 'deactivated'}`)
    await loadServiceTypes()
  } catch (err) {
    console.error(err)
    toast.error('Unable to update service type status')
  } finally {
    togglingId.value = null
  }
}

const deleteServiceType = async (item) => {
  if (!confirm(`Delete ${item.name}? This cannot be undone.`)) return

  deletingId.value = item.id
  try {
    await api.delete(`/service-types/${item.id}`)
    toast.success('Service type deleted')
    await loadServiceTypes()
  } catch (err) {
    console.error(err)
    toast.error('Unable to delete service type')
  } finally {
    deletingId.value = null
  }
}

onMounted(() => {
  loadServiceTypes()
})
</script>
