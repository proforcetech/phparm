<template>
  <div>
    <!-- Page Header -->
    <div class="mb-8">
      <h1 class="text-2xl font-bold text-gray-900">CMS Dashboard</h1>
      <p class="mt-1 text-sm text-gray-500">Manage your website content</p>
    </div>

    <!-- Loading State -->
    <div v-if="loading" class="flex justify-center py-12">
      <Loading size="xl" text="Loading CMS dashboard..." />
    </div>

    <!-- Error State -->
    <Alert v-else-if="error" variant="danger" class="mb-6">
      {{ error }}
    </Alert>

    <!-- Dashboard Content -->
    <div v-else>
      <!-- Stats Cards -->
      <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-4 mb-8">
        <!-- Total Pages -->
        <Card>
          <div class="flex items-center">
            <div class="flex-shrink-0">
              <div class="flex items-center justify-center h-12 w-12 rounded-md bg-blue-500 text-white">
                <DocumentDuplicateIcon class="h-6 w-6" />
              </div>
            </div>
            <div class="ml-5 w-0 flex-1">
              <dl>
                <dt class="text-sm font-medium text-gray-500 truncate">Total Pages</dt>
                <dd class="text-2xl font-semibold text-gray-900">
                  {{ stats.pages || 0 }}
                </dd>
              </dl>
            </div>
          </div>
        </Card>

        <!-- Published Pages -->
        <Card>
          <div class="flex items-center">
            <div class="flex-shrink-0">
              <div class="flex items-center justify-center h-12 w-12 rounded-md bg-green-500 text-white">
                <CheckCircleIcon class="h-6 w-6" />
              </div>
            </div>
            <div class="ml-5 w-0 flex-1">
              <dl>
                <dt class="text-sm font-medium text-gray-500 truncate">Published</dt>
                <dd class="text-2xl font-semibold text-gray-900">
                  {{ stats.published || 0 }}
                </dd>
              </dl>
            </div>
          </div>
        </Card>

        <!-- Components -->
        <Card>
          <div class="flex items-center">
            <div class="flex-shrink-0">
              <div class="flex items-center justify-center h-12 w-12 rounded-md bg-purple-500 text-white">
                <Squares2X2Icon class="h-6 w-6" />
              </div>
            </div>
            <div class="ml-5 w-0 flex-1">
              <dl>
                <dt class="text-sm font-medium text-gray-500 truncate">Components</dt>
                <dd class="text-2xl font-semibold text-gray-900">
                  {{ stats.components || 0 }}
                </dd>
              </dl>
            </div>
          </div>
        </Card>

        <!-- Templates -->
        <Card>
          <div class="flex items-center">
            <div class="flex-shrink-0">
              <div class="flex items-center justify-center h-12 w-12 rounded-md bg-orange-500 text-white">
                <RectangleGroupIcon class="h-6 w-6" />
              </div>
            </div>
            <div class="ml-5 w-0 flex-1">
              <dl>
                <dt class="text-sm font-medium text-gray-500 truncate">Templates</dt>
                <dd class="text-2xl font-semibold text-gray-900">
                  {{ stats.templates || 0 }}
                </dd>
              </dl>
            </div>
          </div>
        </Card>
      </div>

      <!-- Quick Actions & Recent Pages -->
      <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <!-- Quick Actions -->
        <Card>
          <template #header>
            <h3 class="text-lg font-medium text-gray-900">Quick Actions</h3>
          </template>
          <div class="grid grid-cols-2 gap-4">
            <Button
              variant="outline"
              @click="$router.push('/cp/cms/pages/create')"
              class="justify-center"
            >
              <PlusIcon class="h-5 w-5 mr-2" />
              New Page
            </Button>
            <Button
              variant="outline"
              @click="$router.push('/cp/cms/components/create')"
              class="justify-center"
            >
              <PlusIcon class="h-5 w-5 mr-2" />
              New Component
            </Button>
            <Button
              variant="outline"
              @click="$router.push('/cp/cms/templates/create')"
              class="justify-center"
            >
              <PlusIcon class="h-5 w-5 mr-2" />
              New Template
            </Button>
            <Button
              variant="outline"
              @click="clearCache"
              :disabled="clearingCache"
              class="justify-center"
            >
              <ArrowPathIcon class="h-5 w-5 mr-2" :class="{ 'animate-spin': clearingCache }" />
              Clear Cache
            </Button>
          </div>
        </Card>

        <!-- Recent Pages -->
        <Card>
          <template #header>
            <div class="flex items-center justify-between">
              <h3 class="text-lg font-medium text-gray-900">Recent Pages</h3>
              <router-link to="/cp/cms/pages" class="text-sm font-medium text-primary-600 hover:text-primary-500">
                View all
              </router-link>
            </div>
          </template>

          <div v-if="recentPages.length === 0" class="text-center py-6 text-gray-500">
            No pages yet
          </div>

          <div v-else class="divide-y divide-gray-200">
            <div
              v-for="page in recentPages"
              :key="page.id"
              class="py-3 flex items-center justify-between hover:bg-gray-50 cursor-pointer px-2 -mx-2 rounded"
              @click="$router.push(`/cp/cms/pages/${page.id}`)"
            >
              <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2">
                  <p class="text-sm font-medium text-gray-900 truncate">
                    {{ page.title }}
                  </p>
                  <Badge :variant="page.is_published ? 'success' : 'warning'">
                    {{ page.is_published ? 'Published' : 'Draft' }}
                  </Badge>
                </div>
                <p class="text-xs text-gray-500">
                  /{{ page.slug }}
                </p>
              </div>
              <div class="ml-4 text-sm text-gray-500">
                {{ formatDate(page.updated_at) }}
              </div>
            </div>
          </div>
        </Card>
      </div>

      <!-- User Role Info -->
      <div class="mt-6">
        <Alert variant="info">
          <div class="flex items-center">
            <InformationCircleIcon class="h-5 w-5 mr-2" />
            <span>You have <strong>{{ userRole }}</strong> access to the CMS.</span>
          </div>
        </Alert>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import Card from '@/components/ui/Card.vue'
import Button from '@/components/ui/Button.vue'
import Badge from '@/components/ui/Badge.vue'
import Alert from '@/components/ui/Alert.vue'
import Loading from '@/components/ui/Loading.vue'
import cmsService from '@/services/cms.service'
import {
  DocumentDuplicateIcon,
  CheckCircleIcon,
  Squares2X2Icon,
  RectangleGroupIcon,
  PlusIcon,
  ArrowPathIcon,
  InformationCircleIcon,
} from '@heroicons/vue/24/outline'

const loading = ref(true)
const error = ref(null)
const stats = ref({})
const recentPages = ref([])
const userRole = ref('')
const clearingCache = ref(false)

onMounted(async () => {
  await loadDashboard()
})

async function loadDashboard() {
  try {
    loading.value = true
    error.value = null

    const data = await cmsService.getDashboard()
    stats.value = data.stats || {}
    recentPages.value = data.recent_pages || []
    userRole.value = data.user_role || 'unknown'
  } catch (err) {
    console.error('Failed to load CMS dashboard:', err)
    error.value = err.response?.data?.message || 'Failed to load CMS dashboard'
  } finally {
    loading.value = false
  }
}

async function clearCache() {
  try {
    clearingCache.value = true
    await cmsService.clearCache()
    // Show success message or reload
  } catch (err) {
    console.error('Failed to clear cache:', err)
    error.value = 'Failed to clear cache'
  } finally {
    clearingCache.value = false
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
