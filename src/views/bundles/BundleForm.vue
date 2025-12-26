<template>
  <div>
    <div class="mb-6 flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">{{ isEditing ? 'Edit Bundle' : 'New Bundle' }}</h1>
        <p class="mt-1 text-sm text-gray-500">Bundle common line items for quick estimate creation.</p>
      </div>
      <Button variant="secondary" @click="goBack">Back to list</Button>
    </div>

    <Card class="max-w-5xl">
      <form class="space-y-6" @submit.prevent="save">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
          <div>
            <label class="block text-sm font-medium text-gray-700">Name</label>
            <Input v-model="form.name" required placeholder="Brake Service Bundle" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700">Default Job Title</label>
            <Input v-model="form.default_job_title" required placeholder="Brake Service" />
          </div>
          <div>
            <div class="flex items-center justify-between mb-1">
              <label class="block text-sm font-medium text-gray-700">Service Type</label>
              <router-link
                to="/cp/settings/services"
                class="text-xs text-indigo-600 hover:text-indigo-800 font-medium"
              >
                Manage Service Types
              </router-link>
            </div>
            <select
              v-model="form.service_type_id"
              class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
            >
              <option :value="null">No service type</option>
              <option v-for="type in serviceTypes" :key="type.id" :value="type.id">{{ type.name }}</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700">Sort Order</label>
            <Input v-model.number="form.sort_order" type="number" min="0" />
          </div>
          <div class="md:col-span-2">
            <label class="block text-sm font-medium text-gray-700">Description</label>
            <Textarea v-model="form.description" :rows="3" placeholder="Optional description for the bundle" />
          </div>
          <div class="md:col-span-2">
            <label class="block text-sm font-medium text-gray-700">Internal Notes</label>
            <Textarea v-model="form.internal_notes" :rows="3" placeholder="Private notes for internal use" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700">Discount Type</label>
            <select
              v-model="form.discount_type"
              class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
            >
              <option :value="null">No discount</option>
              <option value="fixed">Fixed amount</option>
              <option value="percent">Percent</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700">Discount Value</label>
            <Input v-model.number="form.discount_value" type="number" min="0" step="0.01" :disabled="!form.discount_type" />
          </div>
          <div class="flex items-center gap-2 md:col-span-2">
            <input v-model="form.is_active" type="checkbox" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
            <span class="text-sm text-gray-700">Bundle is active</span>
          </div>
        </div>

        <div>
          <div class="flex items-center justify-between mb-3">
            <h3 class="text-lg font-semibold text-gray-900">Bundle Items</h3>
            <Button type="button" variant="outline" @click="addItem">Add Item</Button>
          </div>
          <div v-if="form.items.length === 0" class="rounded border border-dashed border-gray-300 p-4 text-sm text-gray-500">
            No items added yet.
          </div>
          <div v-for="(item, index) in form.items" :key="index" class="mb-4 rounded-lg border border-gray-200 p-4 shadow-sm">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-6">
              <div class="md:col-span-2">
                <label class="block text-sm font-medium text-gray-700">Type</label>
                <select
                  v-model="item.type"
                  class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                  required
                >
                  <option value="LABOR">Labor</option>
                  <option value="PART">Part</option>
                  <option value="FEE">Fee</option>
                  <option value="DISCOUNT">Discount</option>
                </select>
              </div>
              <div class="md:col-span-4">
                <label class="block text-sm font-medium text-gray-700">Description</label>
                <Input v-model="item.description" required placeholder="Pad replacement" />
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700">Quantity</label>
                <Input v-model.number="item.quantity" type="number" min="0" step="0.01" />
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700">Unit Price</label>
                <Input v-model.number="item.unit_price" type="number" min="0" step="0.01" />
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700">List Price</label>
                <Input v-model.number="item.list_price" type="number" min="0" step="0.01" />
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700">Sort Order</label>
                <Input v-model.number="item.sort_order" type="number" min="0" />
              </div>
              <div class="flex items-center gap-2 pt-6">
                <input v-model="item.taxable" type="checkbox" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                <span class="text-sm text-gray-700">Taxable</span>
              </div>
            </div>
            <div class="mt-3 flex justify-end">
              <Button type="button" variant="danger" size="sm" @click="removeItem(index)">Remove</Button>
            </div>
          </div>
        </div>

        <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
          <div>
            <p v-if="error" class="text-sm text-red-600">{{ error }}</p>
            <p v-if="success" class="text-sm text-green-600">{{ success }}</p>
          </div>
          <div class="flex gap-3">
            <Button type="button" variant="secondary" @click="goBack">Cancel</Button>
            <Button type="submit" :loading="saving">{{ isEditing ? 'Update Bundle' : 'Create Bundle' }}</Button>
          </div>
        </div>
      </form>
    </Card>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Button from '@/components/ui/Button.vue'
import Card from '@/components/ui/Card.vue'
import Input from '@/components/ui/Input.vue'
import Textarea from '@/components/ui/Textarea.vue'
import bundleService from '@/services/bundle.service'
import api from '@/services/api'

const router = useRouter()
const route = useRoute()
const saving = ref(false)
const success = ref('')
const error = ref('')
const isEditing = ref(false)
const serviceTypes = ref([])

const form = reactive({
  name: '',
  description: '',
  internal_notes: '',
  discount_type: null,
  discount_value: null,
  service_type_id: null,
  default_job_title: '',
  is_active: true,
  sort_order: 0,
  items: [],
})

const goBack = () => router.push('/cp/bundles')

const addItem = () => {
  form.items.push({
    type: 'LABOR',
    description: '',
    quantity: 1,
    unit_price: 0,
    list_price: 0,
    taxable: true,
    sort_order: form.items.length,
  })
}

const removeItem = (index) => {
  form.items.splice(index, 1)
}

const loadServiceTypes = async () => {
  try {
    const response = await api.get('/service-types', { params: { active: 1 } })
    serviceTypes.value = response.data?.data || []
  } catch (err) {
    console.error('Failed to load service types', err)
    serviceTypes.value = []
  }
}

const loadBundle = async () => {
  const id = route.params.id
  if (!id) return
  isEditing.value = true
  const data = await bundleService.get(id)
  Object.assign(form, {
    name: data.name,
    description: data.description || '',
    internal_notes: data.internal_notes || '',
    discount_type: data.discount_type || null,
    discount_value: data.discount_value ?? null,
    service_type_id: data.service_type_id,
    default_job_title: data.default_job_title,
    is_active: Boolean(data.is_active),
    sort_order: data.sort_order || 0,
    items: (data.items || []).map((item, index) => ({
      type: item.type,
      description: item.description,
      quantity: item.quantity,
      unit_price: item.unit_price,
      list_price: item.list_price || 0,
      taxable: Boolean(item.taxable),
      sort_order: item.sort_order ?? index,
    })),
  })
}

const save = async () => {
  saving.value = true
  error.value = ''
  success.value = ''
  try {
    const payload = {
      ...form,
      internal_notes: form.internal_notes || null,
      discount_type: form.discount_type || null,
      discount_value: form.discount_type ? form.discount_value : null,
      items: form.items,
    }
    if (isEditing.value && route.params.id) {
      await bundleService.update(route.params.id, payload)
      success.value = 'Bundle updated.'
    } else {
      await bundleService.create(payload)
      success.value = 'Bundle created.'
      goBack()
    }
  } catch (err) {
    console.error(err)
    error.value = 'Could not save bundle.'
  } finally {
    saving.value = false
  }
}

onMounted(async () => {
  await loadServiceTypes()
  await loadBundle()
  if (form.items.length === 0) {
    addItem()
  }
})
</script>
