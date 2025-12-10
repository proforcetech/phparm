<template>
  <div>
    <div class="mb-6 flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Vehicle Database</h1>
        <p class="mt-1 text-sm text-gray-500">Manage vehicle specifications in the master database</p>
      </div>
      <div class="flex gap-3">
        <Button variant="secondary" @click="showUploadModal = true">
          <svg class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
          </svg>
          Upload CSV
        </Button>
        <Button @click="$router.push('/vehicle-master/create')">
          <svg class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
          </svg>
          Add Vehicle
        </Button>
      </div>
    </div>

    <Card>
      <div class="flex flex-col gap-4">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700">Year</label>
            <Input v-model="filters.year" type="number" placeholder="2024" @input="loadVehicles" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700">Make</label>
            <Input v-model="filters.make" placeholder="Ford" @input="loadVehicles" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700">Model</label>
            <Input v-model="filters.model" placeholder="F-150" @input="loadVehicles" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700">Search term</label>
            <Input v-model="filters.term" placeholder="Engine, transmission..." @input="loadVehicles" />
          </div>
        </div>

        <Table :columns="columns" :data="vehicles" :loading="loading" hoverable>
          <template #cell(year)="{ value }">
            <span class="font-semibold">{{ value }}</span>
          </template>
          <template #cell(engine)="{ value }">
            <span class="text-sm text-gray-700">{{ value || 'N/A' }}</span>
          </template>
          <template #cell(transmission)="{ value }">
            <Badge variant="secondary">{{ value || 'Unknown' }}</Badge>
          </template>
          <template #cell(drive)="{ value }">
            <span class="text-sm">{{ value || '—' }}</span>
          </template>
          <template #actions="{ row }">
            <div class="flex gap-2">
              <Button size="sm" variant="secondary" @click="editVehicle(row)">Edit</Button>
              <Button size="sm" variant="danger" @click="confirmDelete(row)" :loading="deleting === row.id">Delete</Button>
            </div>
          </template>
          <template #empty>
            <p class="text-sm text-gray-500">No vehicles found for the current filters.</p>
          </template>
        </Table>
      </div>
    </Card>

    <!-- CSV Upload Modal -->
    <Modal v-if="showUploadModal" @close="showUploadModal = false">
      <template #title>Upload Vehicle CSV</template>
      <template #default>
        <div class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">CSV File</label>
            <input
              type="file"
              accept=".csv"
              @change="handleFileSelect"
              class="block w-full text-sm text-gray-500
                file:mr-4 file:py-2 file:px-4
                file:rounded-md file:border-0
                file:text-sm file:font-semibold
                file:bg-indigo-50 file:text-indigo-700
                hover:file:bg-indigo-100"
            />
            <p class="mt-2 text-xs text-gray-500">
              CSV should have columns: year, make, model, engine, transmission, drive, trim (optional)
            </p>
          </div>

          <div v-if="uploadError" class="rounded-md bg-red-50 p-3">
            <p class="text-sm text-red-800">{{ uploadError }}</p>
          </div>

          <div v-if="uploadSuccess" class="rounded-md bg-green-50 p-3">
            <p class="text-sm text-green-800">{{ uploadSuccess }}</p>
          </div>
        </div>
      </template>
      <template #footer>
        <div class="flex gap-3 justify-end">
          <Button variant="secondary" @click="showUploadModal = false">Cancel</Button>
          <Button @click="uploadCsv" :loading="uploading" :disabled="!selectedFile">Upload</Button>
        </div>
      </template>
    </Modal>

    <!-- Delete Confirmation Modal -->
    <Modal v-if="vehicleToDelete" @close="vehicleToDelete = null">
      <template #title>Delete Vehicle</template>
      <template #default>
        <p class="text-sm text-gray-700">
          Are you sure you want to delete this vehicle?
        </p>
        <div class="mt-3 rounded-md bg-gray-50 p-3">
          <p class="text-sm font-medium">{{ vehicleToDelete.year }} {{ vehicleToDelete.make }} {{ vehicleToDelete.model }}</p>
          <p class="text-xs text-gray-600 mt-1">{{ vehicleToDelete.engine }} • {{ vehicleToDelete.transmission }} • {{ vehicleToDelete.drive }}</p>
        </div>
        <p class="mt-3 text-xs text-red-600">This action cannot be undone.</p>
      </template>
      <template #footer>
        <div class="flex gap-3 justify-end">
          <Button variant="secondary" @click="vehicleToDelete = null">Cancel</Button>
          <Button variant="danger" @click="deleteVehicle" :loading="deleting">Delete</Button>
        </div>
      </template>
    </Modal>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import Badge from '@/components/ui/Badge.vue'
import Button from '@/components/ui/Button.vue'
import Card from '@/components/ui/Card.vue'
import Input from '@/components/ui/Input.vue'
import Table from '@/components/ui/Table.vue'
import Modal from '@/components/ui/Modal.vue'
import { listVehicleMaster, deleteVehicleMaster, uploadVehicleMasterCsv } from '@/services/vehicle-master.service'
import { useToast } from '@/stores/toast'

const loading = ref(false)
const vehicles = ref([])
const filters = reactive({ year: '', make: '', model: '', term: '' })
const router = useRouter()
const toast = useToast()

const showUploadModal = ref(false)
const selectedFile = ref(null)
const uploading = ref(false)
const uploadError = ref('')
const uploadSuccess = ref('')

const vehicleToDelete = ref(null)
const deleting = ref(null)

const columns = [
  { key: 'year', label: 'Year' },
  { key: 'make', label: 'Make' },
  { key: 'model', label: 'Model' },
  { key: 'engine', label: 'Engine' },
  { key: 'transmission', label: 'Transmission' },
  { key: 'drive', label: 'Drive' },
  { key: 'trim', label: 'Trim' }
]

const loadVehicles = async () => {
  loading.value = true
  const params = {}
  Object.entries(filters).forEach(([key, value]) => {
    if (value) params[key] = value
  })
  try {
    vehicles.value = await listVehicleMaster(params)
  } finally {
    loading.value = false
  }
}

const editVehicle = (row) => {
  if (!row?.id) return
  router.push(`/vehicle-master/${row.id}/edit`)
}

const confirmDelete = (row) => {
  vehicleToDelete.value = row
}

const deleteVehicle = async () => {
  if (!vehicleToDelete.value) return

  deleting.value = vehicleToDelete.value.id
  try {
    await deleteVehicleMaster(vehicleToDelete.value.id)
    toast.success('Vehicle deleted successfully')
    vehicleToDelete.value = null
    await loadVehicles()
  } catch (err) {
    toast.error('Failed to delete vehicle')
    console.error(err)
  } finally {
    deleting.value = null
  }
}

const handleFileSelect = (event) => {
  selectedFile.value = event.target.files[0]
  uploadError.value = ''
  uploadSuccess.value = ''
}

const uploadCsv = async () => {
  if (!selectedFile.value) return

  uploading.value = true
  uploadError.value = ''
  uploadSuccess.value = ''

  try {
    const result = await uploadVehicleMasterCsv(selectedFile.value)
    uploadSuccess.value = `Successfully uploaded ${result.imported || 0} vehicles. ${result.skipped || 0} duplicates skipped.`
    toast.success('CSV uploaded successfully')

    setTimeout(() => {
      showUploadModal.value = false
      selectedFile.value = null
      loadVehicles()
    }, 2000)
  } catch (err) {
    uploadError.value = err.response?.data?.message || 'Failed to upload CSV'
    toast.error('Failed to upload CSV')
    console.error(err)
  } finally {
    uploading.value = false
  }
}

onMounted(loadVehicles)
</script>
