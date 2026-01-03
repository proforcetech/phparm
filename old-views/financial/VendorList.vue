<template>
  <div>
    <div class="mb-6 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Vendors</h1>
        <p class="mt-1 text-sm text-gray-500">Manage vendor profiles for financial tracking.</p>
      </div>
      <Button @click="$router.push('/cp/financial/vendors/create')">New Vendor</Button>
    </div>

    <Card>
      <div class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
        <div class="flex flex-col gap-2 md:flex-row md:items-center">
          <Input
            v-model="filters.search"
            placeholder="Search vendors"
            class="w-full md:w-64"
            @input="loadVendors"
          />
        </div>
        <div class="text-sm text-gray-600">{{ vendors.length }} results</div>
      </div>

      <div v-if="loading" class="py-10">
        <Loading message="Loading vendors..." />
      </div>

      <div v-else>
        <div v-if="vendors.length === 0" class="py-10 text-center text-gray-500">No vendors found.</div>

        <div class="hidden md:block mt-4">
          <Table :columns="columns" :data="vendors" :loading="loading" hoverable>
            <template #cell(name)="{ value }">
              <div class="font-medium text-gray-900">{{ value }}</div>
            </template>
            <template #cell(contact_name)="{ value }">
              <span class="text-sm text-gray-600">{{ value || '—' }}</span>
            </template>
            <template #cell(phone)="{ value }">
              <span class="text-sm text-gray-600">{{ value || '—' }}</span>
            </template>
            <template #cell(email)="{ value }">
              <span class="text-sm text-gray-600">{{ value || '—' }}</span>
            </template>
            <template #actions="{ row }">
              <div class="flex justify-end gap-2">
                <Button size="sm" variant="secondary" @click="editVendor(row.id)">Edit</Button>
                <Button size="sm" variant="danger" @click="confirmDelete(row)">Delete</Button>
              </div>
            </template>
          </Table>
        </div>

        <div class="grid grid-cols-1 gap-4 md:hidden mt-4">
          <div
            v-for="vendor in vendors"
            :key="vendor.id"
            class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm"
          >
            <div class="flex items-start justify-between">
              <div>
                <div class="text-lg font-semibold text-gray-900">{{ vendor.name }}</div>
                <div class="text-sm text-gray-500">{{ vendor.contact_name || 'No contact listed' }}</div>
              </div>
            </div>
            <dl class="mt-3 grid grid-cols-2 gap-3 text-sm text-gray-700">
              <div>
                <dt class="text-gray-500">Phone</dt>
                <dd class="font-medium">{{ vendor.phone || '—' }}</dd>
              </div>
              <div>
                <dt class="text-gray-500">Email</dt>
                <dd class="font-medium">{{ vendor.email || '—' }}</dd>
              </div>
            </dl>
            <div class="mt-4 flex justify-end gap-2">
              <Button size="sm" variant="secondary" @click="editVendor(vendor.id)">Edit</Button>
              <Button size="sm" variant="danger" @click="confirmDelete(vendor)">Delete</Button>
            </div>
          </div>
        </div>
      </div>
    </Card>

    <Modal v-model="showDelete" title="Delete vendor?">
      <p class="text-sm text-gray-600">This action cannot be undone.</p>
      <template #footer>
        <div class="flex justify-end gap-3">
          <Button variant="secondary" @click="showDelete = false">Cancel</Button>
          <Button variant="danger" @click="deleteVendor">Delete</Button>
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
import Loading from '@/components/ui/Loading.vue'
import Modal from '@/components/ui/Modal.vue'
import Table from '@/components/ui/Table.vue'
import financialVendorService from '@/services/financial-vendor.service'
import { useToast } from '@/stores/toast'

const router = useRouter()
const toast = useToast()

const vendors = ref([])
const loading = ref(false)
const showDelete = ref(false)
const vendorToDelete = ref(null)

const filters = reactive({
  search: '',
})

const columns = [
  { key: 'name', label: 'Vendor' },
  { key: 'contact_name', label: 'Contact' },
  { key: 'phone', label: 'Phone' },
  { key: 'email', label: 'Email' },
]

const normalize = (payload) => {
  if (Array.isArray(payload)) return payload
  return payload?.data || []
}

const loadVendors = async () => {
  loading.value = true
  try {
    const data = await financialVendorService.list({ search: filters.search || undefined })
    vendors.value = normalize(data)
  } catch (err) {
    console.error('Failed to load vendors', err)
    toast.error('Failed to load vendors')
    vendors.value = []
  } finally {
    loading.value = false
  }
}

const editVendor = (id) => {
  if (!id) return
  router.push(`/cp/financial/vendors/${id}/edit`)
}

const confirmDelete = (vendor) => {
  vendorToDelete.value = vendor
  showDelete.value = true
}

const deleteVendor = async () => {
  if (!vendorToDelete.value) return
  try {
    await financialVendorService.destroy(vendorToDelete.value.id)
    toast.success('Vendor deleted')
    await loadVendors()
  } catch (err) {
    console.error('Failed to delete vendor', err)
    toast.error('Unable to delete vendor')
  } finally {
    showDelete.value = false
    vendorToDelete.value = null
  }
}

onMounted(loadVendors)
</script>
