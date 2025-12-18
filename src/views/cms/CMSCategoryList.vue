<template>
  <div>
    <!-- Page Header -->
    <div class="mb-8 flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">CMS Categories</h1>
        <p class="mt-1 text-sm text-gray-500">Organize pages into hierarchical categories</p>
      </div>
      <Button @click="$router.push('/cp/cms/categories/create')">
        <PlusIcon class="h-5 w-5 mr-2" />
        New Category
      </Button>
    </div>

    <!-- Filters -->
    <Card class="mb-6">
      <div class="flex flex-wrap gap-4">
        <div class="flex-1 min-w-[200px]">
          <input
            v-model="filters.search"
            type="text"
            placeholder="Search categories..."
            class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
            @input="debouncedSearch"
          />
        </div>
        <div>
          <select
            v-model="filters.status"
            class="px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
            @change="loadCategories"
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
      <Loading size="xl" text="Loading categories..." />
    </div>

    <!-- Error State -->
    <Alert v-else-if="error" variant="danger" class="mb-6">
      {{ error }}
    </Alert>

    <!-- Categories Table -->
    <Card v-else>
      <div v-if="categories.length === 0" class="text-center py-12 text-gray-500">
        <FolderIcon class="h-12 w-12 mx-auto mb-4 text-gray-400" />
        <p class="text-lg font-medium">No categories found</p>
        <p class="text-sm mt-1">Get started by creating your first category.</p>
        <Button class="mt-4" @click="$router.push('/cp/cms/categories/create')">
          <PlusIcon class="h-5 w-5 mr-2" />
          Create Category
        </Button>
      </div>

      <div v-else class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
          <thead class="bg-gray-50">
            <tr>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                Name
              </th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                Slug
              </th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                Sort Order
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
              v-for="category in categories"
              :key="category.id"
              class="hover:bg-gray-50 cursor-pointer"
              @click="$router.push(`/cp/cms/categories/${category.id}`)"
            >
              <td class="px-6 py-4 whitespace-nowrap">
                <div class="text-sm font-medium text-gray-900">{{ category.name }}</div>
              </td>
              <td class="px-6 py-4 whitespace-nowrap">
                <div class="text-sm text-gray-500">/{{ category.slug }}</div>
              </td>
              <td class="px-6 py-4 whitespace-nowrap">
                <div class="text-sm text-gray-500">{{ category.sort_order }}</div>
              </td>
              <td class="px-6 py-4 whitespace-nowrap">
                <span
                  :class="{
                    'bg-green-100 text-green-800': category.status === 'published',
                    'bg-yellow-100 text-yellow-800': category.status === 'draft',
                    'bg-gray-100 text-gray-800': category.status === 'archived'
                  }"
                  class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full"
                >
                  {{ category.status }}
                </span>
              </td>
              <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                {{ formatDate(category.updated_at) }}
              </td>
              <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                <button
                  @click.stop="editCategory(category.id)"
                  class="text-primary-600 hover:text-primary-900 mr-4"
                >
                  Edit
                </button>
                <button
                  @click.stop="deleteCategory(category.id)"
                  class="text-red-600 hover:text-red-900"
                >
                  Delete
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </Card>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { cmsService } from '@/services/cms.service'
import Button from '@/components/common/Button.vue'
import Card from '@/components/common/Card.vue'
import Alert from '@/components/common/Alert.vue'
import Loading from '@/components/common/Loading.vue'
import { PlusIcon, FolderIcon } from '@heroicons/vue/24/outline'

const router = useRouter()

const categories = ref([])
const loading = ref(false)
const error = ref('')
const filters = ref({
  search: '',
  status: ''
})

let searchTimeout = null

const loadCategories = async () => {
  loading.value = true
  error.value = ''
  try {
    const params = {}
    if (filters.value.search) params.search = filters.value.search
    if (filters.value.status) params.status = filters.value.status

    const response = await cmsService.getCategories(params)
    categories.value = response
  } catch (err) {
    error.value = err.message || 'Failed to load categories'
  } finally {
    loading.value = false
  }
}

function debouncedSearch() {
  if (searchTimeout) clearTimeout(searchTimeout)
  searchTimeout = setTimeout(() => {
    loadCategories()
  }, 300)
}

const editCategory = (id) => {
  router.push(`/cp/cms/categories/${id}`)
}

const deleteCategory = async (id) => {
  if (!confirm('Are you sure you want to delete this category? Pages in this category will not be deleted.')) {
    return
  }

  try {
    await cmsService.deleteCategory(id)
    await loadCategories()
  } catch (err) {
    if (err.response?.data?.message) {
      alert(err.response.data.message)
    } else {
      alert('Failed to delete category: ' + (err.message || 'Unknown error'))
    }
  }
}

const formatDate = (dateString) => {
  if (!dateString) return ''
  const date = new Date(dateString)
  return date.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' })
}

onMounted(() => {
  loadCategories()
})
</script>
