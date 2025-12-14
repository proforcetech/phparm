<template>
  <div>
    <div class="mb-8 flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">CMS Menus</h1>
        <p class="mt-1 text-sm text-gray-500">Manage navigation menus and publish updates</p>
      </div>
      <Button @click="$router.push('/cp/cms/menus/create')">
        <PlusIcon class="h-5 w-5 mr-2" />
        New Menu
      </Button>
    </div>

    <Card class="mb-6">
      <div class="flex flex-wrap gap-4 items-end">
        <div class="flex-1 min-w-[200px]">
          <Input
            v-model="filters.search"
            placeholder="Search menus..."
            class="w-full"
            @input="debouncedSearch"
          />
        </div>
        <div>
          <select
            v-model="filters.status"
            class="px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
            @change="loadMenus"
          >
            <option value="">All Status</option>
            <option value="published">Published</option>
            <option value="draft">Draft</option>
          </select>
        </div>
      </div>
    </Card>

    <div v-if="loading" class="flex justify-center py-12">
      <Loading size="xl" text="Loading menus..." />
    </div>

    <Alert v-else-if="error" variant="danger" class="mb-6">{{ error }}</Alert>

    <Card v-else>
      <div v-if="menus.length === 0" class="text-center py-12 text-gray-500">
        <DocumentDuplicateIcon class="h-12 w-12 mx-auto mb-4 text-gray-400" />
        <p class="text-lg font-medium">No menus found</p>
        <p class="text-sm mt-1">Create your first navigation menu to get started.</p>
        <Button class="mt-4" @click="$router.push('/cp/cms/menus/create')">
          <PlusIcon class="h-5 w-5 mr-2" />
          Create Menu
        </Button>
      </div>

      <div v-else class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
          <thead class="bg-gray-50">
            <tr>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Location</th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Updated</th>
              <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
            </tr>
          </thead>
          <tbody class="bg-white divide-y divide-gray-200">
            <tr
              v-for="menu in menus"
              :key="menu.id"
              class="hover:bg-gray-50 cursor-pointer"
              @click="$router.push(`/cp/cms/menus/${menu.id}`)"
            >
              <td class="px-6 py-4 whitespace-nowrap">
                <div class="text-sm font-medium text-gray-900">{{ menu.name }}</div>
              </td>
              <td class="px-6 py-4 whitespace-nowrap">
                <div class="text-sm text-gray-500">{{ menu.location || '—' }}</div>
              </td>
              <td class="px-6 py-4 whitespace-nowrap">
                <Badge :variant="menu.is_published ? 'success' : 'warning'">
                  {{ menu.is_published ? 'Published' : 'Draft' }}
                </Badge>
              </td>
              <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                {{ formatDate(menu.updated_at) }}
              </td>
              <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                <div class="flex items-center justify-end gap-2" @click.stop>
                  <Button
                    variant="secondary"
                    size="sm"
                    @click="togglePublish(menu)"
                  >
                    {{ menu.is_published ? 'Unpublish' : 'Publish' }}
                  </Button>
                  <Button
                    variant="ghost"
                    size="sm"
                    @click="$router.push(`/cp/cms/menus/${menu.id}`)"
                  >
                    <PencilIcon class="h-4 w-4" />
                  </Button>
                  <Button
                    variant="ghost"
                    size="sm"
                    @click="confirmDelete(menu)"
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

    <Modal v-model="showDeleteModal" title="Delete Menu">
      <p class="text-gray-600">
        Are you sure you want to delete the menu "<strong>{{ menuToDelete?.name }}</strong>"?
        This action cannot be undone.
      </p>
      <template #footer>
        <div class="flex justify-end gap-3">
          <Button variant="outline" @click="showDeleteModal = false">Cancel</Button>
          <Button variant="danger" :disabled="deleting" @click="deleteMenu">
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
import Input from '@/components/ui/Input.vue'
import { useCmsMenuStore } from '@/stores/cmsMenus'
import { useToast } from '@/stores/toast'
import {
  PlusIcon,
  PencilIcon,
  TrashIcon,
  DocumentDuplicateIcon,
} from '@heroicons/vue/24/outline'

const menuStore = useCmsMenuStore()
const toast = useToast()

const loading = computed(() => menuStore.loading)
const menus = computed(() => menuStore.menus)
const error = ref(null)
const filters = ref({
  search: '',
  status: '',
})

const showDeleteModal = ref(false)
const menuToDelete = ref(null)
const deleting = ref(false)

let searchTimeout = null

onMounted(async () => {
  await loadMenus()
})

async function loadMenus() {
  try {
    error.value = null
    await menuStore.fetchMenus(filters.value)
  } catch (err) {
    console.error('Failed to load menus:', err)
    error.value = err.response?.data?.message || 'Failed to load menus'
  }
}

function debouncedSearch() {
  if (searchTimeout) clearTimeout(searchTimeout)
  searchTimeout = setTimeout(() => {
    loadMenus()
  }, 300)
}

function confirmDelete(menu) {
  menuToDelete.value = menu
  showDeleteModal.value = true
}

async function deleteMenu() {
  if (!menuToDelete.value) return

  try {
    deleting.value = true
    await menuStore.deleteMenu(menuToDelete.value.id)
    toast.success('Menu deleted')
    showDeleteModal.value = false
    menuToDelete.value = null
    await loadMenus()
  } catch (err) {
    console.error('Failed to delete menu:', err)
    error.value = err.response?.data?.message || 'Failed to delete menu'
    toast.error(error.value)
  } finally {
    deleting.value = false
  }
}

async function togglePublish(menu) {
  try {
    error.value = null
    if (menu.is_published) {
      await menuStore.updateMenu(menu.id, { ...menu, is_published: false })
      toast.info('Menu moved to drafts')
    } else {
      await menuStore.publishMenu(menu.id)
      toast.success('Menu published')
    }
    await loadMenus()
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
