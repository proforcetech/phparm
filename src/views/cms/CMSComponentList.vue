<template>
  <div>
    <!-- Page Header -->
    <div class="mb-8 flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">CMS Components</h1>
        <p class="mt-1 text-sm text-gray-500">Manage reusable content blocks</p>
      </div>
      <Button @click="$router.push('/cp/cms/components/create')">
        <PlusIcon class="h-5 w-5 mr-2" />
        New Component
      </Button>
    </div>

    <!-- Filters -->
    <Card class="mb-6">
      <div class="flex flex-wrap gap-4">
        <div class="flex-1 min-w-[200px]">
          <input
            v-model="filters.search"
            type="text"
            placeholder="Search components..."
            class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
            @input="debouncedSearch"
          />
        </div>
        <div>
          <select
            v-model="filters.type"
            class="px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
            @change="loadComponents"
          >
            <option value="">All Types</option>
            <option v-for="type in componentTypes" :key="type.value" :value="type.value">
              {{ type.label }}
            </option>
          </select>
        </div>
      </div>
    </Card>

    <!-- Loading State -->
    <div v-if="loading" class="flex justify-center py-12">
      <Loading size="xl" text="Loading components..." />
    </div>

    <!-- Error State -->
    <Alert v-else-if="error" variant="danger" class="mb-6">
      {{ error }}
    </Alert>

    <!-- Components Grid -->
    <div v-else-if="components.length === 0" class="text-center py-12">
      <Card>
        <Squares2X2Icon class="h-12 w-12 mx-auto mb-4 text-gray-400" />
        <p class="text-lg font-medium text-gray-900">No components found</p>
        <p class="text-sm mt-1 text-gray-500">Get started by creating your first component.</p>
        <Button class="mt-4" @click="$router.push('/cp/cms/components/create')">
          <PlusIcon class="h-5 w-5 mr-2" />
          Create Component
        </Button>
      </Card>
    </div>

    <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
      <Card
        v-for="component in components"
        :key="component.id"
        class="hover:shadow-lg transition-shadow cursor-pointer"
        @click="$router.push(`/cp/cms/components/${component.id}`)"
      >
        <div class="flex items-start justify-between">
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2 mb-2">
              <h3 class="text-lg font-medium text-gray-900 truncate">{{ component.name }}</h3>
              <Badge :variant="component.is_active ? 'success' : 'default'">
                {{ component.is_active ? 'Active' : 'Inactive' }}
              </Badge>
            </div>
            <Badge variant="info" class="mb-2">{{ getTypeLabel(component.type) }}</Badge>
            <p v-if="component.description" class="text-sm text-gray-500 line-clamp-2">
              {{ component.description }}
            </p>
            <p class="text-xs text-gray-400 mt-2">
              Updated {{ formatDate(component.updated_at) }}
            </p>
          </div>
        </div>

        <div class="mt-4 pt-4 border-t border-gray-200 flex items-center justify-end gap-2" @click.stop>
          <Button
            variant="ghost"
            size="sm"
            @click="duplicateComponent(component)"
            title="Duplicate"
          >
            <DocumentDuplicateIcon class="h-4 w-4" />
          </Button>
          <Button
            variant="ghost"
            size="sm"
            @click="$router.push(`/cp/cms/components/${component.id}`)"
            title="Edit"
          >
            <PencilIcon class="h-4 w-4" />
          </Button>
          <Button
            variant="ghost"
            size="sm"
            @click="confirmDelete(component)"
            title="Delete"
          >
            <TrashIcon class="h-4 w-4 text-red-500" />
          </Button>
        </div>
      </Card>
    </div>

    <!-- Delete Confirmation Modal -->
    <Modal v-model="showDeleteModal" title="Delete Component">
      <p class="text-gray-600">
        Are you sure you want to delete the component "<strong>{{ componentToDelete?.name }}</strong>"?
        This action cannot be undone.
      </p>
      <template #footer>
        <div class="flex justify-end gap-3">
          <Button variant="outline" @click="showDeleteModal = false">Cancel</Button>
          <Button variant="danger" @click="deleteComponent" :disabled="deleting">
            {{ deleting ? 'Deleting...' : 'Delete' }}
          </Button>
        </div>
      </template>
    </Modal>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import Card from '@/components/ui/Card.vue'
import Button from '@/components/ui/Button.vue'
import Badge from '@/components/ui/Badge.vue'
import Alert from '@/components/ui/Alert.vue'
import Loading from '@/components/ui/Loading.vue'
import Modal from '@/components/ui/Modal.vue'
import { cmsService } from '@/services/cms.service'
import {
  PlusIcon,
  PencilIcon,
  TrashIcon,
  Squares2X2Icon,
  DocumentDuplicateIcon,
} from '@heroicons/vue/24/outline'

const loading = ref(true)
const error = ref(null)
const components = ref([])
const componentTypes = cmsService.getComponentTypes()

const filters = ref({
  search: '',
  type: '',
})

const showDeleteModal = ref(false)
const componentToDelete = ref(null)
const deleting = ref(false)

let searchTimeout = null

onMounted(async () => {
  await loadComponents()
})

async function loadComponents() {
  try {
    loading.value = true
    error.value = null

    const data = await cmsService.getComponents(filters.value)
    components.value = data.data || []
  } catch (err) {
    console.error('Failed to load components:', err)
    error.value = err.response?.data?.message || 'Failed to load components'
  } finally {
    loading.value = false
  }
}

function debouncedSearch() {
  if (searchTimeout) clearTimeout(searchTimeout)
  searchTimeout = setTimeout(() => {
    loadComponents()
  }, 300)
}

function getTypeLabel(type) {
  const found = componentTypes.find(t => t.value === type)
  return found ? found.label : type
}

async function duplicateComponent(component) {
  try {
    await cmsService.duplicateComponent(component.id)
    await loadComponents()
  } catch (err) {
    console.error('Failed to duplicate component:', err)
    error.value = err.response?.data?.message || 'Failed to duplicate component'
  }
}

function confirmDelete(component) {
  componentToDelete.value = component
  showDeleteModal.value = true
}

async function deleteComponent() {
  if (!componentToDelete.value) return

  try {
    deleting.value = true
    await cmsService.deleteComponent(componentToDelete.value.id)
    showDeleteModal.value = false
    componentToDelete.value = null
    await loadComponents()
  } catch (err) {
    console.error('Failed to delete component:', err)
    error.value = err.response?.data?.message || 'Failed to delete component'
  } finally {
    deleting.value = false
  }
}

function formatDate(date) {
  if (!date) return ''
  return new Intl.DateTimeFormat('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
  }).format(new Date(date))
}
</script>
