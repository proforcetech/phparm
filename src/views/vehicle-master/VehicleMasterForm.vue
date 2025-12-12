<template>
  <div>
    <div class="mb-6 flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">{{ isEditing ? 'Edit Vehicle' : 'Add Vehicle to Database' }}</h1>
        <p class="mt-1 text-sm text-gray-500">{{ isEditing ? 'Update vehicle specifications' : 'Add a new vehicle to the master database' }}</p>
      </div>
      <Button variant="secondary" @click="goBack">Back to list</Button>
    </div>

    <Card class="max-w-5xl">
      <form class="space-y-6" @submit.prevent="save">
        <!-- Vehicle Information -->
        <div>
          <h3 class="text-lg font-medium text-gray-900 mb-4">Vehicle Specifications</h3>
          <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
            <div>
              <label class="block text-sm font-medium text-gray-700">Year *</label>
              <Input v-model.number="form.year" type="number" required placeholder="2024" min="1950" :max="maxYear" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700">Make *</label>
              <Input v-model="form.make" required placeholder="Ford" maxlength="120" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700">Model *</label>
              <Input v-model="form.model" required placeholder="F-150" maxlength="120" />
            </div>
          </div>

          <div class="grid grid-cols-1 gap-4 md:grid-cols-4 mt-4">
            <div>
              <label class="block text-sm font-medium text-gray-700">Engine *</label>
              <Input v-model="form.engine" required placeholder="5.0L V8" maxlength="120" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700">Transmission *</label>
              <Input v-model="form.transmission" required placeholder="10-Speed Automatic" maxlength="120" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700">Drive *</label>
              <Input v-model="form.drive" required placeholder="4WD" maxlength="20" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700">Trim</label>
              <Input v-model="form.trim" placeholder="Lariat" maxlength="120" />
              <p class="mt-1 text-xs text-gray-500">Optional</p>
            </div>
          </div>
        </div>

        <!-- Form Actions -->
        <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between border-t border-gray-200 pt-6">
          <div>
            <p class="text-sm text-gray-600">Fields marked with * are required.</p>
            <p v-if="error" class="text-sm text-red-600 mt-1">{{ error }}</p>
            <p v-if="success" class="text-sm text-green-600 mt-1">{{ success }}</p>
          </div>
          <div class="flex gap-3">
            <Button type="button" variant="secondary" @click="goBack">Cancel</Button>
            <Button type="submit" :loading="saving">{{ isEditing ? 'Update Vehicle' : 'Add to Database' }}</Button>
          </div>
        </div>
      </form>
    </Card>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Button from '@/components/ui/Button.vue'
import Card from '@/components/ui/Card.vue'
import Input from '@/components/ui/Input.vue'
import { getVehicleMaster, createVehicleMaster, updateVehicleMaster } from '@/services/vehicle-master.service'
import { useToast } from '@/stores/toast'

const router = useRouter()
const route = useRoute()
const toast = useToast()

const saving = ref(false)
const success = ref('')
const error = ref('')

const maxYear = new Date().getFullYear() + 1

const form = reactive({
  year: null,
  make: '',
  model: '',
  engine: '',
  transmission: '',
  drive: '',
  trim: '',
})

const isEditing = computed(() => !!route.params.id)

onMounted(async () => {
  if (isEditing.value) {
    await loadVehicle()
  }
})

async function loadVehicle() {
  try {
    const vehicle = await getVehicleMaster(route.params.id)
    Object.assign(form, {
      year: vehicle.year,
      make: vehicle.make,
      model: vehicle.model,
      engine: vehicle.engine || '',
      transmission: vehicle.transmission || '',
      drive: vehicle.drive || '',
      trim: vehicle.trim || '',
    })
  } catch (err) {
    error.value = 'Failed to load vehicle'
    console.error(err)
  }
}

async function save() {
  saving.value = true
  error.value = ''
  success.value = ''

  try {
    if (isEditing.value) {
      await updateVehicleMaster(route.params.id, form)
      success.value = 'Vehicle updated successfully!'
      toast.success('Vehicle updated successfully!')
    } else {
      await createVehicleMaster(form)
      success.value = 'Vehicle added to database successfully!'
      toast.success('Vehicle added to database successfully!')
    }

    setTimeout(() => {
      router.push('/cp/vehicle-master')
    }, 1500)
  } catch (err) {
    error.value = err.response?.data?.message || 'Failed to save vehicle'
    toast.error('Failed to save vehicle')
    console.error(err)
  } finally {
    saving.value = false
  }
}

function goBack() {
  router.push('/cp/vehicle-master')
}
</script>
