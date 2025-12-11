<template>
  <div>
    <!-- Page Header -->
    <div class="mb-8">
      <div class="flex items-center gap-4">
        <Button variant="ghost" @click="$router.push('/cms/templates')">
          <ArrowLeftIcon class="h-5 w-5" />
        </Button>
        <div>
          <h1 class="text-2xl font-bold text-gray-900">
            {{ isEditing ? 'Edit Template' : 'Create Template' }}
          </h1>
          <p class="mt-1 text-sm text-gray-500">
            {{ isEditing ? 'Update your template structure' : 'Create a new page layout template' }}
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
    <form v-else @submit.prevent="saveTemplate">
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Main Content -->
        <div class="lg:col-span-2 space-y-6">
          <!-- Basic Info -->
          <Card>
            <template #header>
              <h3 class="text-lg font-medium text-gray-900">Template Details</h3>
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
                  placeholder="Brief description of this template..."
                ></textarea>
              </div>
            </div>
          </Card>

          <!-- Template Structure -->
          <Card>
            <template #header>
              <div class="flex items-center justify-between">
                <h3 class="text-lg font-medium text-gray-900">Template Structure</h3>
                <Button variant="ghost" size="sm" @click="showPlaceholders = !showPlaceholders">
                  <InformationCircleIcon class="h-4 w-4 mr-1" />
                  {{ showPlaceholders ? 'Hide' : 'Show' }} Placeholders
                </Button>
              </div>
            </template>

              <Alert v-if="showPlaceholders" variant="info" class="mb-4">
                <div class="text-sm">
                  <p class="font-medium mb-2">Available placeholders:</p>
                  <ul class="list-disc list-inside space-y-1">
                    <li v-for="placeholder in placeholders" :key="placeholder.token" class="flex items-center gap-2">
                      <code class="bg-gray-100 px-1 rounded">{{ placeholder.token }}</code>
                      <span>- {{ placeholder.label }}</span>
                    </li>
                  </ul>
                </div>
              </Alert>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">HTML Structure *</label>
              <textarea
                v-model="form.structure"
                rows="20"
                required
                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500 font-mono text-sm"
                :placeholder="structurePlaceholder"
              ></textarea>
            </div>
          </Card>

          <!-- Default Styles & Scripts -->
          <Card>
            <template #header>
              <h3 class="text-lg font-medium text-gray-900">Default Styles & Scripts</h3>
            </template>

            <div class="space-y-4">
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Default CSS</label>
                <textarea
                  v-model="form.default_css"
                  rows="8"
                  class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500 font-mono text-sm"
                  placeholder="/* Default CSS for all pages using this template */"
                ></textarea>
              </div>

              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Default JavaScript</label>
                <textarea
                  v-model="form.default_js"
                  rows="8"
                  class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500 font-mono text-sm"
                  placeholder="// Default JavaScript for all pages using this template"
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

              <p class="text-xs text-gray-500">
                Only active templates can be assigned to pages.
              </p>

              <div class="pt-4 border-t border-gray-200">
                <Button type="submit" class="w-full" :disabled="saving">
                  {{ saving ? 'Saving...' : (isEditing ? 'Update Template' : 'Create Template') }}
                </Button>
              </div>
            </div>
          </Card>

          <!-- Template Info -->
          <Card v-if="isEditing">
            <template #header>
              <h3 class="text-lg font-medium text-gray-900">Template Info</h3>
            </template>

            <div class="space-y-2 text-sm">
              <div class="flex justify-between">
                <span class="text-gray-500">Created</span>
                <span class="text-gray-900">{{ formatDate(templateData?.created_at) }}</span>
              </div>
              <div class="flex justify-between">
                <span class="text-gray-500">Updated</span>
                <span class="text-gray-900">{{ formatDate(templateData?.updated_at) }}</span>
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
import { ArrowLeftIcon, InformationCircleIcon } from '@heroicons/vue/24/outline'

const route = useRoute()
const router = useRouter()

const loading = ref(true)
const saving = ref(false)
const error = ref(null)
const showPlaceholders = ref(false)
const templateData = ref(null)

const templateId = computed(() => route.params.id)
const isEditing = computed(() => !!templateId.value && templateId.value !== 'create')

const form = ref({
  name: '',
  slug: '',
  description: '',
  structure: '',
  default_css: '',
  default_js: '',
  is_active: true,
})

const structurePlaceholder = [
  '<!DOCTYPE html>',
  '<html>',
  '<head>',
  '    <title>{{title}}</title>',
  '    <meta name="description" content="{{meta_description}}">',
  '    <style>{{custom_css}}</style>',
  '</head>',
  '<body>',
  '    {{header}}',
  '    <main>{{content}}</main>',
  '    {{footer}}',
  '    <script>{{custom_js}}<\\/script>',
  '</body>',
  '</html>',
].join('\n')

const placeholders = [
  { token: '{{header}}', label: 'Header component' },
  { token: '{{content}}', label: 'Page content' },
  { token: '{{footer}}', label: 'Footer component' },
  { token: '{{title}}', label: 'Page title' },
  { token: '{{meta_description}}', label: 'Meta description' },
  { token: '{{custom_css}}', label: 'Page custom CSS' },
  { token: '{{custom_js}}', label: 'Page custom JavaScript' },
]

onMounted(async () => {
  await loadData()
})

async function loadData() {
  try {
    loading.value = true
    error.value = null

    // Load template if editing
    if (isEditing.value) {
      templateData.value = await cmsService.getTemplate(templateId.value)
      form.value = {
        name: templateData.value.name || '',
        slug: templateData.value.slug || '',
        description: templateData.value.description || '',
        structure: templateData.value.structure || '',
        default_css: templateData.value.default_css || '',
        default_js: templateData.value.default_js || '',
        is_active: !!templateData.value.is_active,
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

async function saveTemplate() {
  try {
    saving.value = true
    error.value = null

    if (isEditing.value) {
      await cmsService.updateTemplate(templateId.value, form.value)
    } else {
      const newTemplate = await cmsService.createTemplate(form.value)
      router.push(`/cms/templates/${newTemplate.id}`)
      return
    }

    // Reload data to get fresh state
    await loadData()
  } catch (err) {
    console.error('Failed to save template:', err)
    error.value = err.response?.data?.message || 'Failed to save template'
  } finally {
    saving.value = false
  }
}

function formatDate(date) {
  if (!date) return '-'
  return new Intl.DateTimeFormat('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
  }).format(new Date(date))
}
</script>
