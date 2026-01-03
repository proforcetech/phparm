<template>
  <div>
    <!-- Page Header -->
    <div class="mb-8">
      <div class="flex items-center gap-4">
        <Button variant="ghost" @click="$router.push('/cp/cms/categories')">
          <ArrowLeftIcon class="h-5 w-5" />
        </Button>
        <div>
          <h1 class="text-2xl font-bold text-gray-900">
            {{ isEditing ? 'Edit Category' : 'Create Category' }}
          </h1>
          <p class="mt-1 text-sm text-gray-500">
            {{ isEditing ? 'Update category settings' : 'Create a new category for organizing pages' }}
          </p>
        </div>
      </div>
    </div>

    <!-- Loading State -->
    <div v-if="loading" class="flex justify-center py-12">
      <Loading size="xl" text="Loading..." />
    </div>

    <!-- Error State -->
    <Alert v-else-if="error" variant="danger" class="mb-6">
      {{ error }}
    </Alert>

    <!-- Form -->
    <form v-else @submit.prevent="saveCategory">
      <Alert v-if="validationErrors.length" variant="warning" class="mb-4">
        <ul class="list-disc pl-5">
          <li v-for="message in validationErrors" :key="message">{{ message }}</li>
        </ul>
      </Alert>

      <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Main Content -->
        <div class="lg:col-span-2 space-y-6">
          <!-- Basic Info -->
          <Card>
            <template #header>
              <h3 class="text-lg font-medium text-gray-900">Category Details</h3>
            </template>

            <div class="space-y-4">
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Name *</label>
                <input
                  v-model="form.name"
                  type="text"
                  required
                  class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                  @input="generateSlug"
                />
              </div>

              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Slug *</label>
                <div class="flex items-center">
                  <span class="text-gray-500 text-sm mr-1">/</span>
                  <input
                    v-model="form.slug"
                    type="text"
                    required
                    class="flex-1 px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                  />
                </div>
                <p class="mt-1 text-xs text-gray-500">
                  Used in URLs: /{form.slug}/page-name
                </p>
              </div>

              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                <textarea
                  v-model="form.description"
                  rows="3"
                  class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                  placeholder="Brief description of this category..."
                ></textarea>
              </div>

              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Sort Order</label>
                <input
                  v-model.number="form.sort_order"
                  type="number"
                  min="0"
                  class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                />
                <p class="mt-1 text-xs text-gray-500">Lower numbers appear first</p>
              </div>
            </div>
          </Card>

          <!-- SEO Settings -->
          <Card>
            <template #header>
              <h3 class="text-lg font-medium text-gray-900">SEO Settings</h3>
            </template>

            <div class="space-y-4">
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Meta Title</label>
                <input
                  v-model="form.meta_title"
                  type="text"
                  class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                  placeholder="Custom title for search engines"
                />
              </div>

              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Meta Description</label>
                <textarea
                  v-model="form.meta_description"
                  rows="3"
                  class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                  placeholder="Description for search results"
                ></textarea>
              </div>

              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Meta Keywords</label>
                <input
                  v-model="form.meta_keywords"
                  type="text"
                  class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                  placeholder="keyword1, keyword2, keyword3"
                />
              </div>
            </div>
          </Card>
        </div>

        <!-- Sidebar -->
        <div class="space-y-6">
          <!-- Actions -->
          <Card>
            <template #header>
              <h3 class="text-lg font-medium text-gray-900">Actions</h3>
            </template>

            <div class="space-y-3">
              <Button type="submit" :disabled="saving" class="w-full">
                {{ saving ? 'Saving...' : (isEditing ? 'Update Category' : 'Create Category') }}
              </Button>
              <Button
                v-if="isEditing"
                variant="outline"
                type="button"
                @click="$router.push('/cp/cms/categories')"
                class="w-full"
              >
                Cancel
              </Button>
            </div>
          </Card>

          <!-- Status -->
          <Card>
            <template #header>
              <h3 class="text-lg font-medium text-gray-900">Status</h3>
            </template>

            <div>
              <select
                v-model="form.status"
                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
              >
                <option value="draft">Draft</option>
                <option value="published">Published</option>
                <option value="archived">Archived</option>
              </select>
            </div>
          </Card>
        </div>
      </div>
    </form>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { cmsService } from '@/services/cms.service'
import Button from '@/components/ui/Button.vue'
import Card from '@/components/ui/Card.vue'
import Alert from '@/components/ui/Alert.vue'
import Loading from '@/components/ui/Loading.vue'
import { ArrowLeftIcon } from '@heroicons/vue/24/outline'

const route = useRoute()
const router = useRouter()

const categoryId = computed(() => route.params.id ? parseInt(route.params.id) : null)
const isEditing = computed(() => !!categoryId.value)

const form = ref({
  name: '',
  slug: '',
  description: '',
  sort_order: 0,
  status: 'published',
  meta_title: '',
  meta_description: '',
  meta_keywords: ''
})

const loading = ref(false)
const saving = ref(false)
const error = ref('')
const validationErrors = ref([])

const generateSlug = () => {
  if (!isEditing.value && form.value.name) {
    form.value.slug = form.value.name
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-|-$/g, '')
  }
}

const loadCategory = async () => {
  if (!categoryId.value) return

  loading.value = true
  error.value = ''
  try {
    const category = await cmsService.getCategory(categoryId.value)
    form.value = {
      name: category.name || '',
      slug: category.slug || '',
      description: category.description || '',
      sort_order: category.sort_order || 0,
      status: category.status || 'published',
      meta_title: category.meta_title || '',
      meta_description: category.meta_description || '',
      meta_keywords: category.meta_keywords || ''
    }
  } catch (err) {
    error.value = err.message || 'Failed to load category'
  } finally {
    loading.value = false
  }
}

const validate = () => {
  validationErrors.value = []

  if (!form.value.name?.trim()) {
    validationErrors.value.push('Name is required')
  }

  if (!form.value.slug?.trim()) {
    validationErrors.value.push('Slug is required')
  }

  return validationErrors.value.length === 0
}

const saveCategory = async () => {
  if (!validate()) {
    return
  }

  saving.value = true
  error.value = ''

  try {
    if (isEditing.value) {
      await cmsService.updateCategory(categoryId.value, form.value)
    } else {
      await cmsService.createCategory(form.value)
    }

    router.push('/cp/cms/categories')
  } catch (err) {
    error.value = err.message || 'Failed to save category'
  } finally {
    saving.value = false
  }
}

onMounted(() => {
  if (isEditing.value) {
    loadCategory()
  }
})
</script>
