<template>
  <div class="p-6 space-y-6">
    <div class="flex items-center justify-between">
      <h1 class="text-2xl font-semibold">Technician Inspections</h1>
      <p class="text-sm text-gray-600">Complete inspections and upload supporting media.</p>
    </div>

    <div class="p-4 bg-white rounded shadow space-y-4">
      <div class="grid gap-4 md:grid-cols-2">
        <div>
          <label class="block text-sm font-medium text-gray-700">Template</label>
          <select v-model.number="selectedTemplateId" class="w-full p-2 border rounded" @change="prepareResponses">
            <option disabled value="">Select template</option>
            <option v-for="template in templates" :key="template.id" :value="template.id">{{ template.name }}</option>
          </select>
        </div>
        <div>
          <Autocomplete
            v-model="customerId"
            label="Customer"
            placeholder="Search by name or phone..."
            :search-fn="searchCustomers"
            :item-value="(item) => item.id"
            :item-label="(item) => item.name"
            :item-subtext="(item) => item.phone || ''"
            @select="onCustomerSelect"
          />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Vehicle (optional)</label>
          <select
            v-model="vehicleId"
            class="w-full p-2 border rounded"
            :disabled="!customerId || loadingVehicles"
          >
            <option value="">
              {{ loadingVehicles ? 'Loading vehicles...' : 'Select vehicle' }}
            </option>
            <option v-for="vehicle in vehicles" :key="vehicle.id" :value="vehicle.id">
              {{ formatVehicleLabel(vehicle) }}
            </option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Summary</label>
          <input v-model="summary" type="text" class="w-full p-2 border rounded" />
        </div>
      </div>

      <div v-if="selectedTemplate" class="space-y-4">
        <div
          v-for="section in selectedTemplate.sections"
          :key="section.id"
          class="p-3 border rounded"
        >
          <h3 class="font-semibold">{{ section.name }}</h3>
          <div class="mt-2 space-y-3">
            <div v-for="item in section.items" :key="item.id" class="space-y-1">
              <label class="block text-sm font-medium">{{ item.name }}</label>

              <!-- Yes/No -->
              <template v-if="item.input_type === 'boolean'">
                <select v-model="responses[item.id].response" class="w-full p-2 border rounded">
                  <option value="yes">Yes</option>
                  <option value="no">No</option>
                </select>
              </template>

              <!-- Yes/No/N/A -->
              <template v-else-if="item.input_type === 'boolean_na'">
                <select v-model="responses[item.id].response" class="w-full p-2 border rounded">
                  <option value="yes">Yes</option>
                  <option value="no">No</option>
                  <option value="na">N/A</option>
                </select>
              </template>

              <!-- Textarea -->
              <template v-else-if="item.input_type === 'textarea'">
                <textarea
                  v-model="responses[item.id].response"
                  class="w-full p-2 border rounded"
                  rows="3"
                ></textarea>
              </template>

              <!-- Number Scale (range slider) -->
              <template v-else-if="item.input_type === 'number_scale'">
                <div class="space-y-2">
                  <div class="flex items-center space-x-4">
                    <input
                      v-model.number="responses[item.id].response"
                      type="range"
                      class="flex-1"
                      :min="item.options?.min || 0"
                      :max="item.options?.max || 10"
                      :step="item.options?.step || 1"
                    />
                    <span class="text-lg font-semibold text-indigo-600 min-w-[3rem] text-center">
                      {{ responses[item.id].response }}
                    </span>
                  </div>
                  <div class="flex justify-between text-xs text-gray-500">
                    <span>{{ item.options?.min || 0 }}</span>
                    <span>{{ item.options?.max || 10 }}</span>
                  </div>
                </div>
              </template>

              <!-- Written Scale (select dropdown) -->
              <template v-else-if="item.input_type === 'select_scale'">
                <select v-model="responses[item.id].response" class="w-full p-2 border rounded">
                  <option value="" disabled>Select...</option>
                  <option v-for="choice in item.options?.choices || []" :key="choice" :value="choice">
                    {{ choice }}
                  </option>
                </select>
              </template>

              <!-- Text or Number (free input) -->
              <template v-else>
                <input
                  v-model="responses[item.id].response"
                  class="w-full p-2 border rounded"
                  :type="item.input_type === 'number' ? 'number' : 'text'"
                />
              </template>

              <textarea
                v-model="responses[item.id].note"
                class="w-full p-2 border rounded text-sm"
                rows="2"
                placeholder="Notes (optional)"
              ></textarea>
            </div>
          </div>
        </div>
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700">Attach photos/videos</label>
        <input type="file" multiple accept="image/*,video/*" @change="onFiles" />
      </div>

      <div class="flex space-x-2">
        <button class="px-4 py-2 text-white bg-indigo-600 rounded" @click="submit" :disabled="loading">
          {{ loading ? 'Submitting...' : 'Complete Inspection' }}
        </button>
        <p v-if="message" class="text-sm text-green-600">{{ message }}</p>
        <p v-if="error" class="text-sm text-red-600">{{ error }}</p>
      </div>
    </div>

    <div v-if="lastReport" class="p-4 bg-white rounded shadow">
      <h2 class="text-lg font-semibold mb-2">Last Submitted Report</h2>
      <pre class="text-sm bg-gray-50 p-3 rounded overflow-auto">{{ JSON.stringify(lastReport, null, 2) }}</pre>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import Autocomplete from '@/components/ui/Autocomplete.vue'
import customerService from '@/services/customer.service'
import { computed, onMounted, reactive, ref, watch } from 'vue'
import inspectionService from '@/services/inspection.service'
import customerService from '@/services/customer.service'

const templates = ref([])
const selectedTemplateId = ref('')
const customerId = ref(null)
const selectedCustomer = ref(null)
const vehicleId = ref('')
const summary = ref('')
const vehicles = ref([])
const loadingVehicles = ref(false)
const responses = reactive({})
const mediaFiles = ref([])
const loading = ref(false)
const message = ref('')
const error = ref('')
const lastReport = ref(null)

const selectedTemplate = computed(() => templates.value.find((t) => t.id === Number(selectedTemplateId.value)))

const loadTemplates = async () => {
  try {
    templates.value = await inspectionService.listTemplates()
  } catch (err) {
    console.error(err)
    error.value = 'Unable to load templates'
  }
}

onMounted(loadTemplates)

const formatVehicleLabel = (vehicle) => {
  const details = [vehicle.year, vehicle.make, vehicle.model].filter(Boolean).join(' ')
  const vin = vehicle.vin ? `VIN ${vehicle.vin}` : ''
  return [details, vin].filter(Boolean).join(' • ')
}

const loadCustomerVehicles = async (customer) => {
  if (!customer) {
    vehicles.value = []
    vehicleId.value = ''
    return
  }

  loadingVehicles.value = true
  try {
    vehicles.value = await customerService.getCustomerVehicles(customer)
    if (vehicleId.value && !vehicles.value.find((vehicle) => vehicle.id === Number(vehicleId.value))) {
      vehicleId.value = ''
    }
  } catch (err) {
    console.error(err)
    error.value = 'Unable to load vehicles for customer'
    vehicles.value = []
    vehicleId.value = ''
  } finally {
    loadingVehicles.value = false
  }
}

watch(customerId, (newCustomerId) => {
  error.value = ''
  loadCustomerVehicles(newCustomerId ? Number(newCustomerId) : null)
})

const prepareResponses = () => {
  Object.keys(responses).forEach((key) => delete responses[key])
  if (!selectedTemplate.value) return
  selectedTemplate.value.sections.forEach((section) => {
    section.items.forEach((item) => {
      let defaultResponse = item.default_value || ''

      // Set smart defaults for new field types
      if (item.input_type === 'number_scale' && item.options) {
        // Default to middle of range
        const min = item.options.min || 0
        const max = item.options.max || 10
        defaultResponse = Math.floor((min + max) / 2)
      } else if (item.input_type === 'select_scale' && item.options?.choices?.length) {
        // Default to first option
        defaultResponse = ''
      } else if (item.input_type === 'boolean_na') {
        defaultResponse = 'na'
      }

      responses[item.id] = {
        template_item_id: item.id,
        label: item.name,
        response: defaultResponse,
        note: '',
      }
    })
  })
}

const onFiles = (event) => {
  mediaFiles.value = Array.from(event.target.files || [])
}

const uploadMedia = async (reportId) => {
  for (const file of mediaFiles.value) {
    await inspectionService.uploadMedia(reportId, file)
  }
}

const submit = async () => {
  error.value = ''
  message.value = ''
  loading.value = true
  try {
    const report = await inspectionService.startInspection({
      template_id: Number(selectedTemplateId.value),
      customer_id: Number(customerId.value),
      vehicle_id: vehicleId.value ? Number(vehicleId.value) : null,
      summary: summary.value,
    })

    if (mediaFiles.value.length) {
      await uploadMedia(report.id)
    }

    const payload = {
      responses: Object.values(responses),
    }
    const completed = await inspectionService.completeInspection(report.id, payload)
    lastReport.value = completed
    message.value = 'Inspection completed'
  } catch (err) {
    console.error(err)
    error.value = err.response?.data?.message || 'Unable to complete inspection'
  } finally {
    loading.value = false
  }
}

const searchCustomers = async (query) => {
  try {
    return await customerService.searchCustomers(query)
  } catch (err) {
    console.error('Customer search failed:', err)
    return []
  }
}

const onCustomerSelect = (customer) => {
  selectedCustomer.value = customer
  customerId.value = customer?.id ?? null
}
</script>
