<template>
  <div>
    <div class="mb-8">
      <div class="flex items-center gap-4">
        <Button variant="ghost" @click="$router.push('/cp/cms/templates')">
          <ArrowLeftIcon class="h-5 w-5" />
        </Button>
        <div>
          <h1 class="text-2xl font-bold text-gray-900">
            {{ isEditing ? 'Edit Template' : 'Create Template' }}
          </h1>
          <p class="mt-1 text-sm text-gray-500">
            Define the HTML structure and default assets for your pages.
          </p>
        </div>
      </div>
    </div>

    <div v-if="loading" class="flex justify-center py-12">
      <Loading size="xl" text="Loading template..." />
    </div>

    <Alert v-else-if="error" variant="danger" class="mb-6">
      {{ error }}
    </Alert>

    <form v-else @submit.prevent="saveTemplate">
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        
        <div class="lg:col-span-2 space-y-6">
          <Card>
            <template #header>
              <h3 class="text-lg font-medium text-gray-900">Template Structure</h3>
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
                  <input
                    v-model="form.slug"
                    type="text"
                    required
                    class="flex-1 px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500 bg-gray-50"
                  />
                </div>
              </div>

              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                <textarea
                  v-model="form.description"
                  rows="2"
                  class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                ></textarea>
              </div>

              <div>
                <div class="flex items-center justify-between mb-1">
                  <label class="block text-sm font-medium text-gray-700">HTML Structure *</label>
                  <button 
                    type="button" 
                    class="text-xs text-primary-600 hover:text-primary-700"
                    @click="form.structure = structurePlaceholder"
                  >
                    Insert Default Boilerplate
                  </button>
                </div>
                <textarea
                  v-model="form.structure"
                  required
                  rows="20"
                  class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500 font-mono text-sm bg-gray-50"
                  placeholder="<html>...</html>"
                ></textarea>
                <p class="mt-1 text-xs text-gray-500">
                  Use placeholders like {{ '{{content}}' }} to inject page data.
                </p>
              </div>
            </div>
          </Card>

          <Card>
            <template #header>
              <h3 class="text-lg font-medium text-gray-900">Default Assets</h3>
            </template>
            <div class="space-y-4">
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Default CSS</label>
                <textarea
                  v-model="form.default_css"
                  rows="10"
                  class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500 font-mono text-sm"
                  placeholder="/* CSS applied to all pages using this template */"
                ></textarea>
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Default JavaScript</label>
                <textarea
                  v-model="form.default_js"
                  rows="10"
                  class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500 font-mono text-sm"
                  placeholder="// JS applied to all pages using this template"
                ></textarea>
              </div>
            </div>
          </Card>
        </div>

        <div class="space-y-6">
          <Card>
            <template #header>
              <h3 class="text-lg font-medium text-gray-900">Publishing</h3>
            </template>
            
            <div class="space-y-4">
              <div class="flex items-center justify-between">
                <span class="text-sm text-gray-700">Status</span>
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
                  {{ saving ? 'Saving...' : (isEditing ? 'Update Template' : 'Create Template') }}
                </Button>
              </div>
            </div>
          </Card>

          <Card>
            <template #header>
              <div class="flex items-center gap-2">
                <InformationCircleIcon class="h-5 w-5 text-gray-400" />
                <h3 class="text-lg font-medium text-gray-900">Available Placeholders</h3>
              </div>
            </template>
            
            <div class="space-y-2">
              <p class="text-xs text-gray-500 mb-3">
                Click to copy placeholders to clipboard.
              </p>
              <div 
                v-for="ph in placeholders" 
                :key="ph.token"
                class="group flex items-center justify-between p-2 rounded bg-gray-50 hover:bg-gray-100 cursor-pointer border border-transparent hover:border-gray-200 transition-colors"
                @click="navigator.clipboard.writeText(ph.token)"
              >
                <code class="text-xs text-primary-700 font-mono">{{ ph.token }}</code>
                <span class="text-xs text-gray-500">{{ ph.label }}</span>
              </div>
            </div>
          </Card>

          <div v-if="isEditing && templateData" class="text-xs text-gray-500 space-y-1 px-1">
            <p>Created: {{ formatDate(templateData.created_at) }}</p>
            <p>Updated: {{ formatDate(templateData.updated_at) }}</p>
          </div>
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
  '    <script>{{custom_js}}<' + '/script>',
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
      router.push(`/cp/cms/templates/${newTemplate.id}`)
      return
    }

    // Reload data to get fresh state
    await loadData()
  } catch (err) {
    console.error('Failed to save template:', err)
    
    // Check for specific status codes to provide better feedback
    if (err.response?.status === 409) {
      // 409 Conflict - Duplicate slug/name
      error.value = err.response.data.message || 'A template with this name or slug already exists. Please choose another.'
    } else if (err.response?.status === 403) {
      // 403 Forbidden - Permission denied
      error.value = 'You do not have permission to perform this action.'
    } else {
      // General error fallback
      error.value = err.response?.data?.message || 'Failed to save template. Please try again.'
    }
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
