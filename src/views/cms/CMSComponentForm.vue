<template>
  <div>
    <!-- Page Header -->
    <div class="mb-8">
      <div class="flex items-center gap-4">
        <Button variant="ghost" @click="$router.push('/cms/components')">
          <ArrowLeftIcon class="h-5 w-5" />
        </Button>
        <div>
          <h1 class="text-2xl font-bold text-gray-900">
            {{ isEditing ? 'Edit Component' : 'Create Component' }}
          </h1>
          <p class="mt-1 text-sm text-gray-500">
            {{ isEditing ? 'Update your component content' : 'Create a new reusable component' }}
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
    <form v-else @submit.prevent="saveComponent">
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Main Content -->
        <div class="lg:col-span-2 space-y-6">
          <!-- Basic Info -->
          <Card>
            <template #header>
              <h3 class="text-lg font-medium text-gray-900">Component Details</h3>
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
                <input
                  v-model="form.slug"
                  type="text"
                  required
                  class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                />
              </div>

              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                <textarea
                  v-model="form.description"
                  rows="2"
                  class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                  placeholder="Brief description of this component..."
                ></textarea>
              </div>

              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">HTML Content</label>
                <textarea
                  v-model="form.content"
                  rows="12"
                  class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500 font-mono text-sm"
                  placeholder="Enter HTML content..."
                ></textarea>
              </div>
            </div>
          </Card>

          <!-- CSS & JavaScript -->
          <Card>
            <template #header>
              <h3 class="text-lg font-medium text-gray-900">Styles & Scripts</h3>
            </template>

            <div class="space-y-4">
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">CSS</label>
                <textarea
                  v-model="form.css"
                  rows="8"
                  class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500 font-mono text-sm"
                  placeholder="/* Component CSS styles */"
                ></textarea>
              </div>

              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">JavaScript</label>
                <textarea
                  v-model="form.javascript"
                  rows="8"
                  class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500 font-mono text-sm"
                  placeholder="// Component JavaScript code"
                ></textarea>
              </div>
            </div>
          </Card>
        </div>

        <!-- Sidebar -->
        <div class="space-y-6">
          <!-- Publish Settings -->
          <Card>
            <template #header>
              <h3 class="text-lg font-medium text-gray-900">Status</h3>
            </template>

            <div class="space-y-4">
              <div class="flex items-center justify-between">
                <span class="text-sm text-gray-700">Active</span>
                <label class="relative inline-flex items-center cursor-pointer">
                  <input
                    v-model="form.is_active"
                    type="checkbox"
                    class="sr-only peer"
                  />
                  <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-primary-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-primary-600"></div>
                  <span class="ml-2 text-sm font-medium" :class="form.is_active ? 'text-green-600' : 'text-gray-500'">
                    {{ form.is_active ? 'Active' : 'Inactive' }}
                  </span>
                </label>
              </div>

              <div class="pt-4 border-t border-gray-200">
                <Button type="submit" class="w-full" :disabled="saving">
                  {{ saving ? 'Saving...' : (isEditing ? 'Update Component' : 'Create Component') }}
                </Button>
              </div>
            </div>
          </Card>

          <!-- Component Type -->
          <Card>
            <template #header>
              <h3 class="text-lg font-medium text-gray-900">Component Type</h3>
            </template>

            <div class="space-y-4">
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Type *</label>
                <select
                  v-model="form.type"
                  required
                  class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                >
                  <option v-for="type in componentTypes" :key="type.value" :value="type.value">
                    {{ type.label }}
                  </option>
                </select>
                <p class="mt-1 text-xs text-gray-500">
                  <span v-if="form.type === 'header'">Used as page header</span>
                  <span v-else-if="form.type === 'footer'">Used as page footer</span>
                  <span v-else-if="form.type === 'navigation'">Navigation menus</span>
                  <span v-else-if="form.type === 'sidebar'">Sidebar content</span>
                  <span v-else-if="form.type === 'widget'">Reusable widgets</span>
                  <span v-else>Custom component</span>
                </p>
              </div>
            </div>
          </Card>

          <!-- Cache Settings -->
          <Card>
            <template #header>
              <h3 class="text-lg font-medium text-gray-900">Cache Settings</h3>
            </template>

            <div class="space-y-4">
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Cache TTL (seconds)</label>
                <input
                  v-model.number="form.cache_ttl"
                  type="number"
                  min="0"
                  class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                />
                <p class="mt-1 text-xs text-gray-500">
                  Set to 0 to disable caching
                </p>
              </div>
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
import Card from '@/components/ui/Card.vue'
import Button from '@/components/ui/Button.vue'
import Alert from '@/components/ui/Alert.vue'
import Loading from '@/components/ui/Loading.vue'
import cmsService from '@/services/cms.service'
import { ArrowLeftIcon } from '@heroicons/vue/24/outline'

const route = useRoute()
const router = useRouter()

const loading = ref(true)
const saving = ref(false)
const error = ref(null)

const componentId = computed(() => route.params.id)
const isEditing = computed(() => !!componentId.value && componentId.value !== 'create')

const componentTypes = cmsService.getComponentTypes()

const form = ref({
  name: '',
  slug: '',
  type: 'custom',
  description: '',
  content: '',
  css: '',
  javascript: '',
  cache_ttl: 3600,
  is_active: true,
})

onMounted(async () => {
  await loadData()
})

async function loadData() {
  try {
    loading.value = true
    error.value = null

    // Load component if editing
    if (isEditing.value) {
      const componentData = await cmsService.getComponent(componentId.value)
      form.value = {
        name: componentData.name || '',
        slug: componentData.slug || '',
        type: componentData.type || 'custom',
        description: componentData.description || '',
        content: componentData.content || '',
        css: componentData.css || '',
        javascript: componentData.javascript || '',
        cache_ttl: componentData.cache_ttl || 3600,
        is_active: !!componentData.is_active,
      }
    }
  } catch (err) {
    console.error('Failed to load data:', err)
    error.value = err.response?.data?.message || 'Failed to load data'
  } finally {
    loading.value = false
  }
}

function generateSlug() {
  if (isEditing.value) return // Don't auto-generate when editing

  const slug = form.value.name
    .toLowerCase()
    .trim()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')

  form.value.slug = slug
}

async function saveComponent() {
  try {
    saving.value = true
    error.value = null

    if (isEditing.value) {
      await cmsService.updateComponent(componentId.value, form.value)
    } else {
      const newComponent = await cmsService.createComponent(form.value)
      router.push(`/cms/components/${newComponent.id}`)
      return
    }

    // Reload data to get fresh state
    await loadData()
  } catch (err) {
    console.error('Failed to save component:', err)
    error.value = err.response?.data?.message || 'Failed to save component'
  } finally {
    saving.value = false
  }
}
</script>
