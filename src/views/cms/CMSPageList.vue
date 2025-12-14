<template>
  <div>
    <!-- Page Header -->
    <div class="mb-8 flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">CMS Pages</h1>
        <p class="mt-1 text-sm text-gray-500">Manage your website pages</p>
      </div>
      <Button @click="$router.push('/cp/cms/pages/create')">
        <PlusIcon class="h-5 w-5 mr-2" />
        New Page
      </Button>
    </div>

    <!-- Filters -->
    <Card class="mb-6">
      <div class="flex flex-wrap gap-4">
        <div class="flex-1 min-w-[200px]">
          <input
            v-model="filters.search"
            type="text"
            placeholder="Search pages..."
            class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
            @input="debouncedSearch"
          />
        </div>
        <div>
          <select
            v-model="filters.status"
            class="px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
            @change="loadPages"
          >
            <option value="">All Status</option>
            <option value="published">Published</option>
            <option value="draft">Draft</option>
          </select>
        </div>
      </div>
    </Card>

    <!-- Loading State -->
    <div v-if="loading" class="flex justify-center py-12">
      <Loading size="xl" text="Loading pages..." />
    </div>

    <!-- Error State -->
    <Alert v-else-if="error" variant="danger" class="mb-6">
      {{ error }}
    </Alert>

    <!-- Pages Table -->
    <Card v-else>
      <div v-if="pages.length === 0" class="text-center py-12 text-gray-500">
        <DocumentDuplicateIcon class="h-12 w-12 mx-auto mb-4 text-gray-400" />
        <p class="text-lg font-medium">No pages found</p>
        <p class="text-sm mt-1">Get started by creating your first page.</p>
        <Button class="mt-4" @click="$router.push('/cp/cms/pages/create')">
          <PlusIcon class="h-5 w-5 mr-2" />
          Create Page
        </Button>
      </div>

      <div v-else class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
          <thead class="bg-gray-50">
            <tr>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                Title
              </th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                Slug
              </th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                Template
              </th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                Status
              </th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                Updated
              </th>
              <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                Actions
              </th>
            </tr>
          </thead>
          <tbody class="bg-white divide-y divide-gray-200">
            <tr
              v-for="page in pages"
              :key="page.id"
              class="hover:bg-gray-50 cursor-pointer"
              @click="$router.push(`/cp/cms/pages/${page.id}`)"
            >
              <td class="px-6 py-4 whitespace-nowrap">
                <div class="text-sm font-medium text-gray-900">{{ page.title }}</div>
              </td>
              <td class="px-6 py-4 whitespace-nowrap">
                <div class="text-sm text-gray-500">/{{ page.slug }}</div>
              </td>
              <td class="px-6 py-4 whitespace-nowrap">
                <div class="text-sm text-gray-500">{{ page.template_name || 'None' }}</div>
              </td>
              <td class="px-6 py-4 whitespace-nowrap">
                <Badge :variant="page.is_published ? 'success' : 'warning'">
                  {{ page.is_published ? 'Published' : 'Draft' }}
                </Badge>
              </td>
              <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                {{ formatDate(page.updated_at) }}
              </td>
              <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                <div class="flex items-center justify-end gap-2" @click.stop>
                  <Button
                    variant="secondary"
                    size="sm"
                    @click="togglePublish(page)"
                  >
                    {{ page.is_published ? 'Unpublish' : 'Publish' }}
                  </Button>
                  <Button
                    variant="ghost"
                    size="sm"
                    @click="$router.push(`/cp/cms/pages/${page.id}`)"
                  >
                    <PencilIcon class="h-4 w-4" />
                  </Button>
                  <Button
                    variant="ghost"
                    size="sm"
                    @click="confirmDelete(page)"
                  >
                    <TrashIcon class="h-4 w-4 text-red-500" />
                  </Button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </Card>

    <!-- Delete Confirmation Modal -->
    <Modal v-model="showDeleteModal" title="Delete Page">
      <p class="text-gray-600">
        Are you sure you want to delete the page "<strong>{{ pageToDelete?.title }}</strong>"?
        This action cannot be undone.
      </p>
      <template #footer>
        <div class="flex justify-end gap-3">
          <Button variant="outline" @click="showDeleteModal = false">Cancel</Button>
          <Button variant="danger" @click="deletePage" :disabled="deleting">
            {{ deleting ? 'Deleting...' : 'Delete' }}
          </Button>
        </div>
      </template>
    </Modal>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import Card from '@/components/ui/Card.vue'
import Button from '@/components/ui/Button.vue'
import Badge from '@/components/ui/Badge.vue'
import Alert from '@/components/ui/Alert.vue'
import Loading from '@/components/ui/Loading.vue'
import Modal from '@/components/ui/Modal.vue'
import { useCmsPageStore } from '@/stores/cmsPages'
import { useToast } from '@/stores/toast'
import {
  PlusIcon,
  PencilIcon,
  TrashIcon,
  DocumentDuplicateIcon,
} from '@heroicons/vue/24/outline'

const pageStore = useCmsPageStore()
const toast = useToast()

const loading = computed(() => pageStore.loading)
const pages = computed(() => pageStore.pages)
const error = ref(null)
const filters = ref({
  search: '',
  status: '',
})

const showDeleteModal = ref(false)
const pageToDelete = ref(null)
const deleting = ref(false)

let searchTimeout = null

onMounted(async () => {
  await loadPages()
})

async function loadPages() {
  try {
    error.value = null
    await pageStore.fetchPages(filters.value)
  } catch (err) {
    console.error('Failed to load pages:', err)
    error.value = err.response?.data?.message || 'Failed to load pages'
  }
}

function debouncedSearch() {
  if (searchTimeout) clearTimeout(searchTimeout)
  searchTimeout = setTimeout(() => {
    loadPages()
  }, 300)
}

function confirmDelete(page) {
  pageToDelete.value = page
  showDeleteModal.value = true
}

async function deletePage() {
  if (!pageToDelete.value) return

  try {
    deleting.value = true
    await pageStore.deletePage(pageToDelete.value.id)
    toast.success('Page deleted')
    showDeleteModal.value = false
    pageToDelete.value = null
    await loadPages()
  } catch (err) {
    console.error('Failed to delete page:', err)
    error.value = err.response?.data?.message || 'Failed to delete page'
    toast.error(error.value)
  } finally {
    deleting.value = false
  }
}

async function togglePublish(page) {
  try {
    error.value = null
    if (page.is_published) {
      await pageStore.updatePage(page.id, { ...page, is_published: false })
      toast.info('Page moved to drafts')
    } else {
      await pageStore.publishPage(page.id)
      toast.success('Page published')
    }
    await loadPages()
  } catch (err) {
    console.error('Failed to update publish status:', err)
    error.value = err.response?.data?.message || 'Failed to update publish status'
    toast.error(error.value)
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
