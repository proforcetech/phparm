<template>
  <div>
    <!-- Page Header -->
    <div class="mb-8">
      <div class="flex items-center gap-4">
        <Button variant="ghost" @click="$router.push('/cp/cms/pages')">
          <ArrowLeftIcon class="h-5 w-5" />
        </Button>
        <div>
          <h1 class="text-2xl font-bold text-gray-900">
            {{ isEditing ? 'Edit Page' : 'Create Page' }}
          </h1>
          <p class="mt-1 text-sm text-gray-500">
            {{ isEditing ? 'Update your page content and settings' : 'Create a new page for your website' }}
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
    <form v-else @submit.prevent="savePage">
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
              <h3 class="text-lg font-medium text-gray-900">Page Details</h3>
            </template>

            <div class="space-y-4">
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Title *</label>
                <input
                  v-model="form.title"
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
              </div>

              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Summary</label>
                <textarea
                  v-model="form.summary"
                  :rows="2"
                  class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                  placeholder="Brief summary of the page..."
                ></textarea>
              </div>

              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Content</label>
                <textarea
                  v-model="form.content"
                  rows="15"
                  class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500 font-mono text-sm"
                  placeholder="Enter HTML content..."
                ></textarea>
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
                  placeholder="Custom title for search engines (defaults to page title)"
                />
              </div>

              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Meta Description</label>
                <textarea
                  v-model="form.meta_description"
                  :rows="3"
                  maxlength="160"
                  class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                  placeholder="Brief description for search engines..."
                ></textarea>
                <p class="mt-1 text-xs text-gray-500">{{ form.meta_description?.length || 0 }}/160 characters</p>
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
          <!-- Publish Settings -->
          <Card>
            <template #header>
              <h3 class="text-lg font-medium text-gray-900">Publish</h3>
            </template>

            <div class="space-y-4">
              <div class="flex items-center justify-between">
                <span class="text-sm text-gray-700">Status</span>
                <label class="relative inline-flex items-center cursor-pointer">
                  <input
                    :checked="form.status === 'published'"
                    type="checkbox"
                    class="sr-only peer"
                    @change="form.status = $event.target.checked ? 'published' : 'draft'"
                  />
                  <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-primary-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-primary-600"></div>
                  <span class="ml-2 text-sm font-medium" :class="form.status === 'published' ? 'text-green-600' : 'text-gray-500'">
                    {{ form.status === 'published' ? 'Published' : 'Draft' }}
                  </span>
                </label>
              </div>

              <div class="pt-4 border-t border-gray-200">
                <Button type="submit" class="w-full" :disabled="saving">
                  {{ saving ? 'Saving...' : (isEditing ? 'Update Page' : 'Create Page') }}
                </Button>
                <Button
                  v-if="form.status !== 'published'"
                  type="button"
                  class="w-full mt-3"
                  variant="secondary"
                  :disabled="saving"
                  @click="publishPage"
                >
                  {{ saving ? 'Publishing...' : 'Save & Publish' }}
                </Button>
              </div>
            </div>
          </Card>
        </div>
      </div>
    </form>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Card from '@/components/ui/Card.vue'
import Button from '@/components/ui/Button.vue'
import Alert from '@/components/ui/Alert.vue'
import Loading from '@/components/ui/Loading.vue'
import cmsService from '@/services/cms.service'
import { useCmsPageStore } from '@/stores/cmsPages'
import { useToast } from '@/stores/toast'
import { ArrowLeftIcon } from '@heroicons/vue/24/outline'

const route = useRoute()
const router = useRouter()
const pageStore = useCmsPageStore()
const toast = useToast()

const loading = ref(true)
const saving = ref(false)
const error = ref(null)
const validationErrors = ref([])

const pageId = computed(() => route.params.id)
const isEditing = computed(() => !!pageId.value && pageId.value !== 'create')
const draftKey = computed(() => pageId.value || 'new')

const form = ref(createDefaultForm())

onMounted(async () => {
  await loadData()
})

watch(form, (value) => {
  pageStore.setDraft(draftKey.value, value)
}, { deep: true })

function createDefaultForm() {
  return {
    title: '',
    slug: '',
    status: 'draft',
    meta_title: '',
    meta_description: '',
    meta_keywords: '',
    summary: '',
    content: '',
  }
}

async function loadData() {
  try {
    loading.value = true
    error.value = null

    // Load page if editing
    if (isEditing.value) {
      const pageData = await pageStore.fetchPage(pageId.value)
      const draft = pageStore.drafts[draftKey.value]
      form.value = {
        ...createDefaultForm(),
        ...pageData,
        ...(draft || {}),
      }
    } else {
      const draft = pageStore.drafts[draftKey.value]
      form.value = {
        ...createDefaultForm(),
        ...(draft || {}),
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

  const slug = form.value.title
    .toLowerCase()
    .trim()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')

  form.value.slug = slug
}

function validateForm() {
  const errors = []
  if (!form.value.title) errors.push('Title is required')
  if (!form.value.slug) errors.push('Slug is required')
  return errors
}

async function savePage() {
  try {
    saving.value = true
    error.value = null
    validationErrors.value = validateForm()

    if (validationErrors.value.length) {
      throw new Error(validationErrors.value.join(', '))
    }

    if (isEditing.value) {
      await pageStore.updatePage(pageId.value, form.value)
      toast.success('Page updated')
    } else {
      const newPage = await pageStore.createPage(form.value)
      toast.success('Page created')
      router.push(`/cp/cms/pages/${newPage.id}`)
      return
    }

    pageStore.clearDraft(draftKey.value)
    await loadData()
  } catch (err) {
    console.error('Failed to save page:', err)
    error.value = err.response?.data?.message || err.message || 'Failed to save page'
    if (!validationErrors.value.length) {
      validationErrors.value = [error.value]
    }
  } finally {
    saving.value = false
  }
}

async function publishPage() {
  try {
    saving.value = true
    error.value = null
    validationErrors.value = validateForm()

    if (validationErrors.value.length) {
      throw new Error(validationErrors.value.join(', '))
    }

    if (isEditing.value) {
      await pageStore.publishPage(pageId.value)
      toast.success('Page published')
    } else {
      const newPage = await pageStore.createPage({ ...form.value, status: 'published' })
      toast.success('Page created and published')
      router.push(`/cp/cms/pages/${newPage.id}`)
      return
    }

    pageStore.clearDraft(draftKey.value)
    await loadData()
  } catch (err) {
    console.error('Failed to publish page:', err)
    error.value = err.response?.data?.message || err.message || 'Failed to publish page'
    if (!validationErrors.value.length) {
      validationErrors.value = [error.value]
    }
  } finally {
    saving.value = false
  }
}
</script>
