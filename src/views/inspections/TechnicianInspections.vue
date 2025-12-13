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
          <label class="block text-sm font-medium text-gray-700">Customer ID</label>
          <input v-model.number="customerId" type="number" class="w-full p-2 border rounded" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Vehicle ID (optional)</label>
          <input v-model.number="vehicleId" type="number" class="w-full p-2 border rounded" />
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
              <template v-if="item.input_type === 'boolean'">
                <select v-model="responses[item.id].response" class="w-full p-2 border rounded">
                  <option value="yes">Yes</option>
                  <option value="no">No</option>
                </select>
              </template>
              <template v-else-if="item.input_type === 'textarea'">
                <textarea
                  v-model="responses[item.id].response"
                  class="w-full p-2 border rounded"
                  rows="3"
                ></textarea>
              </template>
              <template v-else>
                <input
                  v-model="responses[item.id].response"
                  class="w-full p-2 border rounded"
                  :type="item.input_type === 'number' ? 'number' : 'text'"
                />
              </template>
              <textarea
                v-model="responses[item.id].note"
                class="w-full p-2 border rounded"
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
import { computed, onMounted, reactive, ref } from 'vue'
import inspectionService from '@/services/inspection.service'

const templates = ref([])
const selectedTemplateId = ref('')
const customerId = ref('')
const vehicleId = ref('')
const summary = ref('')
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

const prepareResponses = () => {
  Object.keys(responses).forEach((key) => delete responses[key])
  if (!selectedTemplate.value) return
  selectedTemplate.value.sections.forEach((section) => {
    section.items.forEach((item) => {
      responses[item.id] = {
        template_item_id: item.id,
        label: item.name,
        response: item.default_value || '',
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
</script>
