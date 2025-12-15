<template>
  <div>
    <div class="mb-6 flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">{{ isEditing ? 'Edit Inventory Item' : 'New Inventory Item' }}</h1>
        <p class="mt-1 text-sm text-gray-500">{{ isEditing ? 'Update pricing and stock' : 'Add a new part or supply' }}</p>
      </div>
      <Button variant="secondary" @click="goBack">Back to list</Button>
    </div>

    <Card class="max-w-5xl">
      <form class="space-y-6" @submit.prevent="save">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
          <div>
            <label class="block text-sm font-medium text-gray-700">Name</label>
            <Input v-model="form.name" required placeholder="Brake pads" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700">SKU</label>
            <Input v-model="form.sku" placeholder="SKU-1234" />
          </div>
          <div>
            <div class="flex items-center justify-between text-sm font-medium text-gray-700">
              <span>Category</span>
              <RouterLink class="text-indigo-600 hover:text-indigo-500" to="/cp/inventory/categories">Manage</RouterLink>
            </div>
            <Select
              v-model="form.category"
              :options="categoryOptions"
              placeholder="Select a category"
              :disabled="lookupsLoading.categories"
            />
            <div v-if="lookupsLoading.categories" class="mt-1 flex items-center gap-2 text-xs text-gray-500">
              <Loading size="sm" />
              <span>Loading categories...</span>
            </div>
            <p v-else-if="lookupError.categories" class="mt-1 text-xs text-red-600">{{ lookupError.categories }}</p>
          </div>
          <div>
            <div class="flex items-center justify-between text-sm font-medium text-gray-700">
              <span>Location</span>
              <RouterLink class="text-indigo-600 hover:text-indigo-500" to="/cp/inventory/locations">Manage</RouterLink>
            </div>
            <Select
              v-model="form.location"
              :options="locationOptions"
              placeholder="Select a location"
              :disabled="lookupsLoading.locations"
            />
            <div v-if="lookupsLoading.locations" class="mt-1 flex items-center gap-2 text-xs text-gray-500">
              <Loading size="sm" />
              <span>Loading locations...</span>
            </div>
            <p v-else-if="lookupError.locations" class="mt-1 text-xs text-red-600">{{ lookupError.locations }}</p>
          </div>
          <div>
            <div class="flex items-center justify-between text-sm font-medium text-gray-700">
              <span>Vendor</span>
              <RouterLink class="text-indigo-600 hover:text-indigo-500" to="/cp/inventory/vendors">Manage</RouterLink>
            </div>
            <Select
              v-model="form.vendor"
              :options="vendorOptions"
              placeholder="Select a vendor"
              :disabled="lookupsLoading.vendors"
            />
            <div v-if="lookupsLoading.vendors" class="mt-1 flex items-center gap-2 text-xs text-gray-500">
              <Loading size="sm" />
              <span>Loading vendors...</span>
            </div>
            <p v-else-if="lookupError.vendors" class="mt-1 text-xs text-red-600">{{ lookupError.vendors }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700">Reorder quantity</label>
            <Input v-model.number="form.reorder_quantity" type="number" min="0" placeholder="10" />
          </div>
        </div>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
          <div>
            <label class="block text-sm font-medium text-gray-700">Stock quantity</label>
            <Input v-model.number="form.stock_quantity" type="number" min="0" placeholder="50" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700">Low stock threshold</label>
            <Input v-model.number="form.low_stock_threshold" type="number" min="0" placeholder="5" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700">Markup (%)</label>
            <Input v-model.number="form.markup" type="number" step="0.01" min="0" placeholder="20" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700">Cost</label>
            <Input v-model.number="form.cost" type="number" step="0.01" min="0" placeholder="15.00" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700">Sale price</label>
            <Input
              v-model.number="form.sale_price"
              type="number"
              step="0.01"
              min="0"
              placeholder="25.00"
              helperText="Calculated as cost × (1 + markup/100). You can override this if needed."
            />
          </div>
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700">Notes</label>
          <textarea
            v-model="form.notes"
            :rows="3"
            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
            placeholder="Internal notes about the part"
          ></textarea>
        </div>

        <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
          <div>
            <p class="text-sm text-gray-600">Fields marked with * are required. Pricing and stock will sync to alerts.</p>
            <p v-if="error" class="text-sm text-red-600">{{ error }}</p>
            <p v-if="success" class="text-sm text-green-600">{{ success }}</p>
          </div>
          <div class="flex gap-3">
            <Button type="button" variant="secondary" @click="goBack">Cancel</Button>
            <Button type="submit" :loading="saving">{{ isEditing ? 'Update item' : 'Create item' }}</Button>
          </div>
        </div>
      </form>
    </Card>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Button from '@/components/ui/Button.vue'
import Card from '@/components/ui/Card.vue'
import Input from '@/components/ui/Input.vue'
import Loading from '@/components/ui/Loading.vue'
import Select from '@/components/ui/Select.vue'
import inventoryMetaService from '@/services/inventory-meta.service'
import inventoryService from '@/services/inventory.service'

const router = useRouter()
const route = useRoute()
const saving = ref(false)
const success = ref('')
const error = ref('')
const isEditing = ref(false)

const form = reactive({
  name: '',
  sku: '',
  category: '',
  location: '',
  vendor: '',
  reorder_quantity: 0,
  stock_quantity: 0,
  low_stock_threshold: 0,
  markup: null,
  cost: 0,
  sale_price: 0,
  notes: '',
})

const isInitializing = ref(true)
const manualSalePrice = ref(false)
const isAutoUpdatingSalePrice = ref(false)

const categoryOptions = ref([])
const locationOptions = ref([])
const vendorOptions = ref([])
const lookupsLoading = reactive({ categories: false, locations: false, vendors: false })
const lookupError = reactive({ categories: '', locations: '', vendors: '' })
const lookupFieldMap = { categories: 'category', locations: 'location', vendors: 'vendor' }

const calculateSalePrice = () => {
  const cost = Number(form.cost)
  const markup = Number(form.markup ?? 0)

  if (!Number.isFinite(cost)) return null

  const salePrice = cost * (markup) + cost
  return Number.isFinite(salePrice) ? Number(salePrice.toFixed(2)) : null
}

const goBack = () => router.push('/cp/inventory')

const loadLookup = async (type, target) => {
  lookupsLoading[type] = true
  lookupError[type] = ''
  try {
    const params = type === 'vendors' ? { parts_supplier: true } : {}
    const data = await inventoryMetaService.list(type, params)
    const options = data.map((item) => ({ label: item.name, value: item.name }))
    const field = lookupFieldMap[type]
    const currentValue = form[field]

    if (currentValue && !options.some((option) => option.value === currentValue)) {
      options.push({ label: currentValue, value: currentValue })
    }

    target.value = options
  } catch (err) {
    console.error(err)
    lookupError[type] = `Could not load ${type}.`
  } finally {
    lookupsLoading[type] = false
  }
}

const loadLookups = async () => {
  await Promise.all([
    loadLookup('categories', categoryOptions),
    loadLookup('locations', locationOptions),
    loadLookup('vendors', vendorOptions),
  ])
}

const loadItem = async () => {
  const id = route.params.id
  if (!id) return
  isEditing.value = true
  const data = await inventoryService.get(id)
  Object.assign(form, data)
}

const updateSalePriceFromFormula = () => {
  if (isInitializing.value || manualSalePrice.value) return

  const salePrice = calculateSalePrice()
  if (salePrice === null) return

  isAutoUpdatingSalePrice.value = true
  form.sale_price = salePrice
  isAutoUpdatingSalePrice.value = false
}

watch(
  () => [form.cost, form.markup],
  () => {
    updateSalePriceFromFormula()
  },
)

watch(
  () => form.sale_price,
  (newValue) => {
    if (isInitializing.value || isAutoUpdatingSalePrice.value) return

    manualSalePrice.value = newValue !== '' && newValue !== null && newValue !== undefined
  },
)

const save = async () => {
  saving.value = true
  error.value = ''
  success.value = ''
  try {
    const payload = { ...form }
    if (isEditing.value && route.params.id) {
      await inventoryService.update(route.params.id, payload)
      success.value = 'Inventory item updated.'
    } else {
      await inventoryService.create(payload)
      success.value = 'Inventory item created.'
      goBack()
    }
  } catch (err) {
    console.error(err)
    error.value = 'Could not save inventory item.'
  } finally {
    saving.value = false
  }
}

onMounted(async () => {
  await loadItem()
  loadLookups()
  isInitializing.value = false
})
</script>
