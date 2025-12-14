<template>
  <div>
    <!-- Page Header -->
    <div class="mb-8 flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">CMS Templates</h1>
        <p class="mt-1 text-sm text-gray-500">Manage page layout templates</p>
      </div>
      <Button @click="$router.push('/cp/cms/templates/create')">
        <PlusIcon class="h-5 w-5 mr-2" />
        New Template
      </Button>
    </div>

    <!-- Filters -->
    <Card class="mb-6">
      <div class="flex flex-wrap gap-4">
        <div class="flex-1 min-w-[200px]">
          <input
            v-model="filters.search"
            type="text"
            placeholder="Search templates..."
            class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
            @input="debouncedSearch"
          />
        </div>
        <div>
          <select
            v-model="filters.active"
            class="px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
            @change="loadTemplates"
          >
            <option value="">All Status</option>
            <option value="1">Active</option>
            <option value="0">Inactive</option>
          </select>
        </div>
      </div>
    </Card>

    <!-- Loading State -->
    <div v-if="loading" class="flex justify-center py-12">
      <Loading size="xl" text="Loading templates..." />
    </div>

    <!-- Error State -->
    <Alert v-else-if="error" variant="danger" class="mb-6">
      {{ error }}
    </Alert>

    <!-- Templates Grid -->
    <div v-else-if="templates.length === 0" class="text-center py-12">
      <Card>
        <RectangleGroupIcon class="h-12 w-12 mx-auto mb-4 text-gray-400" />
        <p class="text-lg font-medium text-gray-900">No templates found</p>
        <p class="text-sm mt-1 text-gray-500">Get started by creating your first template.</p>
        <Button class="mt-4" @click="$router.push('/cp/cms/templates/create')">
          <PlusIcon class="h-5 w-5 mr-2" />
          Create Template
        </Button>
      </Card>
    </div>

    <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
      <Card
        v-for="template in templates"
        :key="template.id"
        class="hover:shadow-lg transition-shadow cursor-pointer"
        @click="$router.push(`/cp/cms/templates/${template.id}`)"
      >
        <div class="flex items-start justify-between">
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2 mb-2">
              <h3 class="text-lg font-medium text-gray-900 truncate">{{ template.name }}</h3>
              <Badge :variant="template.is_active ? 'success' : 'default'">
                {{ template.is_active ? 'Active' : 'Inactive' }}
              </Badge>
            </div>
            <p v-if="template.description" class="text-sm text-gray-500 line-clamp-2">
              {{ template.description }}
            </p>
            <p class="text-xs text-gray-400 mt-2">
              Updated {{ formatDate(template.updated_at) }}
            </p>
          </div>
        </div>

        <!-- Template Preview -->
        <div class="mt-4 p-3 bg-gray-100 rounded-md">
          <div class="text-xs text-gray-500 font-mono truncate">
            {{ template.structure ? template.structure.substring(0, 100) + '...' : 'No structure defined' }}
          </div>
        </div>

        <div class="mt-4 pt-4 border-t border-gray-200 flex items-center justify-end gap-2" @click.stop>
          <Button
            variant="ghost"
            size="sm"
            @click="$router.push(`/cp/cms/templates/${template.id}`)"
            title="Edit"
          >
            <PencilIcon class="h-4 w-4" />
          </Button>
          <Button
            variant="ghost"
            size="sm"
            @click="confirmDelete(template)"
            title="Delete"
          >
            <TrashIcon class="h-4 w-4 text-red-500" />
          </Button>
        </div>
      </Card>
    </div>

    <!-- Delete Confirmation Modal -->
    <Modal v-model="showDeleteModal" title="Delete Template">
      <p class="text-gray-600">
        Are you sure you want to delete the template "<strong>{{ templateToDelete?.name }}</strong>"?
        This action cannot be undone.
      </p>
      <Alert v-if="deleteError" variant="danger" class="mt-4">
        {{ deleteError }}
      </Alert>
      <template #footer>
        <div class="flex justify-end gap-3">
          <Button variant="outline" @click="showDeleteModal = false">Cancel</Button>
          <Button variant="danger" @click="deleteTemplate" :disabled="deleting">
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
import cmsService from '@/services/cms.service'
import {
  PlusIcon,
  PencilIcon,
  TrashIcon,
  RectangleGroupIcon,
} from '@heroicons/vue/24/outline'

const loading = ref(true)
const error = ref(null)
const templates = ref([])

const filters = ref({
  search: '',
  active: '',
})

const showDeleteModal = ref(false)
const templateToDelete = ref(null)
const deleting = ref(false)
const deleteError = ref(null)

let searchTimeout = null

onMounted(async () => {
  await loadTemplates()
})

async function loadTemplates() {
  try {
    loading.value = true
    error.value = null

    const data = await cmsService.getTemplates(filters.value)
    templates.value = data.data || []
  } catch (err) {
    console.error('Failed to load templates:', err)
    error.value = err.response?.data?.message || 'Failed to load templates'
  } finally {
    loading.value = false
  }
}

function debouncedSearch() {
  if (searchTimeout) clearTimeout(searchTimeout)
  searchTimeout = setTimeout(() => {
    loadTemplates()
  }, 300)
}

function confirmDelete(template) {
  templateToDelete.value = template
  deleteError.value = null
  showDeleteModal.value = true
}

async function deleteTemplate() {
  if (!templateToDelete.value) return

  try {
    deleting.value = true
    deleteError.value = null
    await cmsService.deleteTemplate(templateToDelete.value.id)
    showDeleteModal.value = false
    templateToDelete.value = null
    await loadTemplates()
  } catch (err) {
    console.error('Failed to delete template:', err)
    deleteError.value = err.response?.data?.message || 'Failed to delete template. It may be in use by pages.'
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
