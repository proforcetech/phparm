<template>
  <div>
    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">{{ copy.title }}</h1>
        <p class="mt-1 text-sm text-gray-500">{{ copy.subtitle }}</p>
      </div>
      <div class="flex gap-3 flex-wrap">
        <Button variant="secondary" @click="$router.push('/cp/inventory')">Back to inventory</Button>
        <Button @click="startCreate">
          <svg class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
          </svg>
          Add {{ copy.singular }}
        </Button>
      </div>
    </div>

    <Card>
      <div class="flex flex-col gap-4">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700">Search</label>
            <Input
              v-model="search"
              :placeholder="`Search ${copy.plural.toLowerCase()}`"
              @input="handleSearch"
            />
          </div>
        </div>

        <div class="relative">
          <Loading v-if="loading" overlay :text="`Loading ${copy.plural.toLowerCase()}...`" />
          <Table :columns="columns" :data="filteredItems" :loading="loading" hoverable>
            <template #cell(name)="{ value }">
              <div class="font-semibold text-gray-900">{{ value }}</div>
            </template>
            <template #cell(description)="{ value }">
              <span class="text-sm text-gray-600">{{ value || '—' }}</span>
            </template>
            <template #actions="{ row }">
              <div class="flex gap-2">
                <Button size="sm" variant="secondary" @click="startEdit(row)">Edit</Button>
                <Button
                  size="sm"
                  variant="danger"
                  :loading="deletingId === row.id"
                  @click="deleteItem(row)">
                  Delete
                </Button>
              </div>
            </template>
            <template #empty>
              <p class="text-sm text-gray-500">No {{ copy.plural.toLowerCase() }} found.</p>
            </template>
          </Table>
        </div>
      </div>
    </Card>

    <div v-if="showModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-lg overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center bg-gray-50">
          <h3 class="text-lg font-medium text-gray-900">
            {{ editingItem ? `Edit ${copy.singular}` : `New ${copy.singular}` }}
          </h3>
          <button @click="closeModal" class="text-gray-400 hover:text-gray-500 focus:outline-none">
            <span class="sr-only">Close</span>
            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        <div class="px-6 py-4 space-y-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Name</label>
            <Input v-model="form.name" required :placeholder="copy.placeholder" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
            <textarea
              v-model="form.description"
              rows="3"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500 sm:text-sm"
              placeholder="Optional details"
            ></textarea>
          </div>
          <div v-if="props.type === 'vendors'" class="flex items-center gap-2 pt-2">
            <input
              id="partsSupplier"
              v-model="form.is_parts_supplier"
              type="checkbox"
              class="h-4 w-4 rounded border-gray-300 text-primary-600 focus:ring-primary-500"
            />
            <label for="partsSupplier" class="text-sm text-gray-700">Parts supplier</label>
          </div>
        </div>

        <div class="px-6 py-4 bg-gray-50 flex justify-end gap-3 border-t border-gray-200">
          <Button variant="secondary" @click="closeModal">Cancel</Button>
          <Button :loading="saving" @click="saveItem">
            {{ editingItem ? 'Save changes' : `Create ${copy.singular}` }}
          </Button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import Button from '@/components/ui/Button.vue'
import Card from '@/components/ui/Card.vue'
import Input from '@/components/ui/Input.vue'
import Loading from '@/components/ui/Loading.vue'
import Table from '@/components/ui/Table.vue'
import inventoryMetaService from '@/services/inventory-meta.service'
import { useToast } from '@/stores/toast'

const props = defineProps({
  type: {
    type: String,
    required: true,
  },
})

const labels = {
  categories: {
    title: 'Inventory Categories',
    subtitle: 'Create categories to group similar items',
    singular: 'Category',
    plural: 'Categories',
    placeholder: 'Brakes',
  },
  vendors: {
    title: 'Inventory Vendors',
    subtitle: 'Track vendors for ordering and pricing',
    singular: 'Vendor',
    plural: 'Vendors',
    placeholder: 'ACME Parts Co.',
  },
  locations: {
    title: 'Inventory Locations',
    subtitle: 'Keep locations updated for quick picking',
    singular: 'Location',
    plural: 'Locations',
    placeholder: 'Aisle 3',
  },
}

const copy = computed(() => labels[props.type] || labels.categories)

const toast = useToast()
const items = ref([])
const filteredItems = ref([])
const search = ref('')
const loading = ref(false)
const saving = ref(false)
const deletingId = ref(null)
const showModal = ref(false)
const editingItem = ref(null)

const form = reactive({
  name: '',
  description: '',
  is_parts_supplier: false,
})

const columns = [
  { key: 'name', label: 'Name' },
  { key: 'description', label: 'Description' },
]

const handleSearch = () => {
  const term = search.value.toLowerCase()
  filteredItems.value = !term
    ? items.value
    : items.value.filter(
        (item) => {
          const name = (item.name || '').toLowerCase()
          const description = (item.description || '').toLowerCase()
          return name.includes(term) || description.includes(term)
        }
      )
}

const resetForm = () => {
  form.name = ''
  form.description = ''
  form.is_parts_supplier = false
}

const loadItems = async () => {
  loading.value = true
  try {
    items.value = await inventoryMetaService.list(props.type)
    filteredItems.value = items.value
    if (search.value) {
      handleSearch()
    }
  } catch (err) {
    console.error(err)
    toast.error(`Unable to load ${copy.value.plural.toLowerCase()}`)
  } finally {
    loading.value = false
  }
}

const startCreate = () => {
  editingItem.value = null
  resetForm()
  showModal.value = true
}

const startEdit = (item) => {
  editingItem.value = item
  form.name = item.name
  form.description = item.description || ''
  form.is_parts_supplier = Boolean(item.is_parts_supplier)
  showModal.value = true
}

const closeModal = () => {
  showModal.value = false
  resetForm()
  editingItem.value = null
}

const saveItem = async () => {
  saving.value = true
  try {
    const payload = { name: form.name, description: form.description }
    if (props.type === 'vendors') {
      payload.is_parts_supplier = form.is_parts_supplier
    }
    if (editingItem.value) {
      await inventoryMetaService.update(props.type, editingItem.value.id, payload)
      toast.success(`${copy.value.singular} updated`)
    } else {
      await inventoryMetaService.create(props.type, payload)
      toast.success(`${copy.value.singular} created`)
    }
    closeModal()
    await loadItems()
  } catch (err) {
    console.error(err)
    toast.error(`Unable to save ${copy.value.singular.toLowerCase()}`)
  } finally {
    saving.value = false
  }
}

const deleteItem = async (item) => {
  if (!confirm(`Delete this ${copy.value.singular.toLowerCase()}?`)) return
  deletingId.value = item.id
  try {
    await inventoryMetaService.remove(props.type, item.id)
    toast.success(`${copy.value.singular} deleted`)
    await loadItems()
  } catch (err) {
    console.error(err)
    toast.error(`Unable to delete ${copy.value.singular.toLowerCase()}`)
  } finally {
    deletingId.value = null
  }
}

onMounted(() => {
  loadItems()
})

watch(
  () => props.type,
  () => {
    resetForm()
    search.value = ''
    loadItems()
  }
)
</script>
