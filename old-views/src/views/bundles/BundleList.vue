<template>
  <div>
    <div class="mb-6 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Preset Bundles</h1>
        <p class="mt-1 text-sm text-gray-500">Create reusable groups of line items for faster estimate building.</p>
      </div>
      <Button @click="$router.push('/cp/bundles/create')">New Bundle</Button>
    </div>

    <Card>
      <div class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
        <div class="flex flex-col gap-2 md:flex-row md:items-center">
          <Input v-model="filters.query" placeholder="Search bundles" class="w-full md:w-64" @input="loadBundles" />
          <label class="inline-flex items-center gap-2 text-sm text-gray-700">
            <input v-model="filters.activeOnly" type="checkbox" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" @change="loadBundles" />
            Active only
          </label>
        </div>
        <div class="text-sm text-gray-600">{{ bundles.length }} results</div>
      </div>

      <div v-if="loading" class="py-10">
        <Loading message="Loading bundles..." />
      </div>

      <div v-else>
        <div v-if="bundles.length === 0" class="py-10 text-center text-gray-500">No bundles found.</div>

        <div class="hidden md:block mt-4">
          <Table :columns="columns" :data="bundles" :loading="loading" hoverable>
            <template #cell(name)="{ row }">
              <div class="py-3">
                <div class="font-medium text-gray-900">{{ row.name }}</div>
                <div class="text-sm text-gray-500">{{ row.description || 'No description' }}</div>
              </div>
            </template>
            <template #cell(service_type_name)="{ value }">
              <span class="text-sm text-gray-700">{{ value || '—' }}</span>
            </template>
            <template #cell(item_count)="{ value }">
              <span class="text-sm text-gray-700">{{ value ?? 0 }}</span>
            </template>
            <template #cell(sort_order)="{ value }">
              <span class="text-sm text-gray-700">{{ value }}</span>
            </template>
            <template #cell(is_active)="{ value }">
              <Badge :variant="value ? 'success' : 'secondary'">{{ value ? 'Active' : 'Inactive' }}</Badge>
            </template>
            <template #actions="{ row }">
              <div class="flex justify-end gap-2">
                <Button size="sm" variant="secondary" @click="editBundle(row.id)">Edit</Button>
                <Button size="sm" variant="danger" @click="confirmDelete(row)">Delete</Button>
              </div>
            </template>
          </Table>
        </div>

        <div class="grid grid-cols-1 gap-4 md:hidden mt-4">
          <div v-for="bundle in bundles" :key="bundle.id" class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
            <div class="flex items-start justify-between">
              <div>
                <div class="text-lg font-semibold text-gray-900">{{ bundle.name }}</div>
                <div class="text-sm text-gray-500">{{ bundle.description || 'No description' }}</div>
              </div>
              <Badge :variant="bundle.is_active ? 'success' : 'secondary'">{{ bundle.is_active ? 'Active' : 'Inactive' }}</Badge>
            </div>
            <dl class="mt-3 grid grid-cols-2 gap-3 text-sm text-gray-700">
              <div>
                <dt class="text-gray-500">Service Type</dt>
                <dd class="font-medium">{{ bundle.service_type_name || '—' }}</dd>
              </div>
              <div>
                <dt class="text-gray-500">Items</dt>
                <dd class="font-medium">{{ bundle.item_count ?? 0 }}</dd>
              </div>
              <div>
                <dt class="text-gray-500">Sort Order</dt>
                <dd class="font-medium">{{ bundle.sort_order }}</dd>
              </div>
            </dl>
            <div class="mt-4 flex justify-end gap-2">
              <Button size="sm" variant="secondary" @click="editBundle(bundle.id)">Edit</Button>
              <Button size="sm" variant="danger" @click="confirmDelete(bundle)">Delete</Button>
            </div>
          </div>
        </div>
      </div>
    </Card>

    <Modal v-model="showDelete" title="Delete bundle?">
      <p class="text-sm text-gray-600">This will remove the bundle and its items. This action cannot be undone.</p>
      <template #footer>
        <div class="flex justify-end gap-3">
          <Button variant="secondary" @click="showDelete = false">Cancel</Button>
          <Button variant="danger" @click="deleteBundle">Delete</Button>
        </div>
      </template>
    </Modal>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import Button from '@/components/ui/Button.vue'
import Card from '@/components/ui/Card.vue'
import Input from '@/components/ui/Input.vue'
import Table from '@/components/ui/Table.vue'
import Badge from '@/components/ui/Badge.vue'
import Loading from '@/components/ui/Loading.vue'
import Modal from '@/components/ui/Modal.vue'
import bundleService from '@/services/bundle.service'

const bundles = ref([])
const loading = ref(false)
const showDelete = ref(false)
const bundleToDelete = ref(null)
const router = useRouter()

const filters = reactive({
  query: '',
  activeOnly: true,
})

const columns = [
  { key: 'name', label: 'Name' },
  { key: 'service_type_name', label: 'Service Type' },
  { key: 'item_count', label: 'Items' },
  { key: 'sort_order', label: 'Sort Order' },
  { key: 'is_active', label: 'Status' },
]

const loadBundles = async () => {
  loading.value = true
  try {
    const params = {
      query: filters.query || undefined,
      active: filters.activeOnly ? 1 : undefined,
    }
    const { data } = await bundleService.list(params)
    bundles.value = data
  } catch (err) {
    console.error('Failed to load bundles', err)
    bundles.value = []
  } finally {
    loading.value = false
  }
}

const editBundle = (id) => {
  if (!id) return
  router.push(`/bundles/${id}/edit`)
}

const confirmDelete = (bundle) => {
  bundleToDelete.value = bundle
  showDelete.value = true
}

const deleteBundle = async () => {
  if (!bundleToDelete.value) return
  try {
    await bundleService.remove(bundleToDelete.value.id)
    await loadBundles()
  } catch (err) {
    console.error('Failed to delete bundle', err)
  } finally {
    showDelete.value = false
    bundleToDelete.value = null
  }
}

onMounted(loadBundles)
</script>
