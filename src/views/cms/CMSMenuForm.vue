<template>
  <div>
    <div class="mb-8 flex items-center gap-4">
      <Button variant="ghost" @click="$router.push('/cms/menus')">
        <ArrowLeftIcon class="h-5 w-5" />
      </Button>
      <div>
        <h1 class="text-2xl font-bold text-gray-900">{{ isEditing ? 'Edit Menu' : 'Create Menu' }}</h1>
        <p class="mt-1 text-sm text-gray-500">Configure navigation links and publish when ready</p>
      </div>
    </div>

    <div v-if="loading" class="flex justify-center py-12">
      <Loading size="xl" text="Loading menu..." />
    </div>

    <Alert v-else-if="error" variant="danger" class="mb-6">{{ error }}</Alert>

    <form v-else class="grid grid-cols-1 gap-6 lg:grid-cols-3" @submit.prevent="saveMenu">
      <div class="lg:col-span-2 space-y-6">
        <Card>
          <template #header>
            <h3 class="text-lg font-medium text-gray-900">Menu Details</h3>
          </template>

          <div class="space-y-4">
            <div>
              <label class="block text-sm font-medium text-gray-700">Name *</label>
              <Input v-model="form.name" placeholder="Main navigation" required />
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700">Location / Key *</label>
              <Input v-model="form.location" placeholder="header" required />
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700">Description</label>
              <Input v-model="form.description" placeholder="Shown in site header" />
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700">Menu Items (JSON)</label>
              <Textarea
                v-model="form.items"
                rows="8"
                placeholder='[{ "label": "Home", "url": "/" }]' />
              <p class="mt-1 text-xs text-gray-500">Provide an array of menu item objects with label and url.</p>
            </div>
          </div>
        </Card>
      </div>

      <div class="space-y-6">
        <Card>
          <template #header>
            <h3 class="text-lg font-medium text-gray-900">Publish</h3>
          </template>

          <div class="space-y-4">
            <div class="flex items-center justify-between">
              <span class="text-sm text-gray-700">Status</span>
              <label class="relative inline-flex items-center cursor-pointer">
                <input v-model="form.is_published" type="checkbox" class="sr-only peer" />
                <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-primary-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-primary-600"></div>
                <span class="ml-2 text-sm font-medium" :class="form.is_published ? 'text-green-600' : 'text-gray-500'">
                  {{ form.is_published ? 'Published' : 'Draft' }}
                </span>
              </label>
            </div>

            <div>
              <Alert v-if="validationErrors.length" variant="warning" class="mb-2">
                <ul class="list-disc pl-5">
                  <li v-for="message in validationErrors" :key="message">{{ message }}</li>
                </ul>
              </Alert>
              <div class="flex flex-col gap-3">
                <Button type="submit" :disabled="saving">{{ saving ? 'Saving...' : isEditing ? 'Update Menu' : 'Create Menu' }}</Button>
                <Button
                  v-if="!form.is_published"
                  type="button"
                  variant="secondary"
                  :disabled="saving"
                  @click="publishMenu"
                >
                  {{ saving ? 'Publishing...' : 'Save & Publish' }}
                </Button>
              </div>
            </div>
          </div>
        </Card>
      </div>
    </form>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Card from '@/components/ui/Card.vue'
import Button from '@/components/ui/Button.vue'
import Alert from '@/components/ui/Alert.vue'
import Loading from '@/components/ui/Loading.vue'
import Input from '@/components/ui/Input.vue'
import Textarea from '@/components/ui/Textarea.vue'
import { useCmsMenuStore } from '@/stores/cmsMenus'
import { useToast } from '@/stores/toast'
import { ArrowLeftIcon } from '@heroicons/vue/24/outline'

const route = useRoute()
const router = useRouter()
const menuStore = useCmsMenuStore()
const toast = useToast()

const loading = computed(() => menuStore.loading)
const saving = ref(false)
const error = ref(null)
const validationErrors = ref([])

const menuId = computed(() => route.params.id)
const isEditing = computed(() => !!menuId.value && menuId.value !== 'create')

const form = ref(createDefaultForm())

function createDefaultForm() {
  return {
    name: '',
    location: '',
    description: '',
    items: '[]',
    is_published: false,
  }
}

onMounted(async () => {
  if (isEditing.value) {
    await loadMenu()
  }
})

async function loadMenu() {
  try {
    error.value = null
    const data = await menuStore.fetchMenu(menuId.value)
    form.value = {
      ...createDefaultForm(),
      ...data,
      items: typeof data.items === 'string' ? data.items : JSON.stringify(data.items || []),
      is_published: !!data.is_published,
    }
  } catch (err) {
    console.error('Failed to load menu:', err)
    error.value = err.response?.data?.message || 'Failed to load menu'
  }
}

function validateForm() {
  const errors = []
  if (!form.value.name) errors.push('Name is required')
  if (!form.value.location) errors.push('Location is required')
  try {
    JSON.parse(form.value.items || '[]')
  } catch (err) {
    errors.push('Menu items must be valid JSON')
  }
  return errors
}

async function saveMenu() {
  try {
    saving.value = true
    error.value = null
    validationErrors.value = validateForm()

    if (validationErrors.value.length) {
      throw new Error(validationErrors.value.join(', '))
    }

    const payload = {
      ...form.value,
      items: JSON.parse(form.value.items || '[]'),
    }

    if (isEditing.value) {
      await menuStore.updateMenu(menuId.value, payload)
      toast.success('Menu updated')
    } else {
      const newMenu = await menuStore.createMenu(payload)
      toast.success('Menu created')
      router.push(`/cms/menus/${newMenu.id}`)
      return
    }
  } catch (err) {
    console.error('Failed to save menu:', err)
    error.value = err.response?.data?.message || err.message || 'Failed to save menu'
    if (!validationErrors.value.length) {
      validationErrors.value = [error.value]
    }
  } finally {
    saving.value = false
  }
}

async function publishMenu() {
  try {
    saving.value = true
    error.value = null
    validationErrors.value = validateForm()

    if (validationErrors.value.length) {
      throw new Error(validationErrors.value.join(', '))
    }

    const payload = {
      ...form.value,
      is_published: true,
      items: JSON.parse(form.value.items || '[]'),
    }

    if (isEditing.value) {
      await menuStore.publishMenu(menuId.value)
      toast.success('Menu published')
    } else {
      const newMenu = await menuStore.createMenu(payload)
      toast.success('Menu created and published')
      router.push(`/cms/menus/${newMenu.id}`)
      return
    }
  } catch (err) {
    console.error('Failed to publish menu:', err)
    error.value = err.response?.data?.message || err.message || 'Failed to publish menu'
    if (!validationErrors.value.length) {
      validationErrors.value = [error.value]
    }
  } finally {
    saving.value = false
  }
}
</script>
