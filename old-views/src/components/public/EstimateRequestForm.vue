<template>
  <div class="max-w-4xl mx-auto p-6 bg-white rounded-lg shadow-lg">
    <h2 class="text-3xl font-bold text-gray-900 mb-6">Request an Estimate</h2>

    <form @submit.prevent="handleSubmit" class="space-y-8">
      <!-- Contact Information -->
      <section class="border-b pb-6">
        <h3 class="text-xl font-semibold text-gray-800 mb-4">Contact Information</h3>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Name *</label>
            <input
              v-model="form.name"
              type="text"
              required
              class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"
              :class="{ 'border-red-500': errors.name }"
            />
            <p v-if="errors.name" class="text-red-500 text-sm mt-1">{{ errors.name }}</p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Email *</label>
            <input
              v-model="form.email"
              type="email"
              required
              class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"
              :class="{ 'border-red-500': errors.email }"
            />
            <p v-if="errors.email" class="text-red-500 text-sm mt-1">{{ errors.email }}</p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Phone *</label>
            <input
              v-model="form.phone"
              type="tel"
              required
              class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"
              :class="{ 'border-red-500': errors.phone }"
            />
            <p v-if="errors.phone" class="text-red-500 text-sm mt-1">{{ errors.phone }}</p>
          </div>
        </div>
      </section>

      <!-- Customer Address -->
      <section class="border-b pb-6">
        <h3 class="text-xl font-semibold text-gray-800 mb-4">Your Address</h3>
        <div class="grid grid-cols-1 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Street Address *</label>
            <input
              v-model="form.address"
              type="text"
              required
              class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"
            />
          </div>

          <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">City *</label>
              <input
                v-model="form.city"
                type="text"
                required
                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"
              />
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">State *</label>
              <input
                v-model="form.state"
                type="text"
                required
                maxlength="2"
                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"
              />
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">ZIP Code *</label>
              <input
                v-model="form.zip"
                type="text"
                required
                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"
              />
            </div>
          </div>
        </div>
      </section>

      <!-- Service Address -->
      <section class="border-b pb-6">
        <h3 class="text-xl font-semibold text-gray-800 mb-4">Service Location</h3>

        <div class="mb-4">
          <label class="flex items-center space-x-2">
            <input
              v-model="form.service_address_same_as_customer"
              type="checkbox"
              class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
            />
            <span class="text-sm font-medium text-gray-700">Service location is same as my address</span>
          </label>
        </div>

        <div v-if="!form.service_address_same_as_customer" class="grid grid-cols-1 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Service Street Address</label>
            <input
              v-model="form.service_address"
              type="text"
              class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"
            />
          </div>

          <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">City</label>
              <input
                v-model="form.service_city"
                type="text"
                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"
              />
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">State</label>
              <input
                v-model="form.service_state"
                type="text"
                maxlength="2"
                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"
              />
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">ZIP Code</label>
              <input
                v-model="form.service_zip"
                type="text"
                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"
              />
            </div>
          </div>
        </div>
      </section>

      <!-- Vehicle Information -->
      <section class="border-b pb-6">
        <h3 class="text-xl font-semibold text-gray-800 mb-4">Vehicle Information</h3>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Year</label>
            <select
              v-model="form.vehicle_year"
              @change="onYearChange"
              class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"
            >
              <option value="">Select Year</option>
              <option v-for="year in vehicleYears" :key="year" :value="year">{{ year }}</option>
            </select>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Make</label>
            <select
              v-model="form.vehicle_make"
              @change="onMakeChange"
              :disabled="!form.vehicle_year"
              class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500 disabled:bg-gray-100"
            >
              <option value="">Select Make</option>
              <option v-for="make in vehicleMakes" :key="make" :value="make">{{ make }}</option>
            </select>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Model</label>
            <select
              v-model="form.vehicle_model"
              :disabled="!form.vehicle_make"
              class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500 disabled:bg-gray-100"
            >
              <option value="">Select Model</option>
              <option v-for="model in vehicleModels" :key="model" :value="model">{{ model }}</option>
            </select>
          </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">VIN (optional)</label>
            <input
              v-model="form.vin"
              type="text"
              maxlength="17"
              class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"
            />
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">License Plate (optional)</label>
            <input
              v-model="form.license_plate"
              type="text"
              class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"
            />
          </div>
        </div>
      </section>

      <!-- Service Request -->
      <section class="border-b pb-6">
        <h3 class="text-xl font-semibold text-gray-800 mb-4">Service Needed</h3>

        <div class="mb-4">
          <label class="block text-sm font-medium text-gray-700 mb-1">Service Type</label>
          <select
            v-model="form.service_type_id"
            class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"
          >
            <option value="">Select Service Type</option>
            <option v-for="type in serviceTypes" :key="type.id" :value="type.id">
              {{ type.name }}
            </option>
          </select>
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Additional Details</label>
          <textarea
            v-model="form.description"
            rows="4"
            placeholder="Please describe the issue or service you need..."
            class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"
          ></textarea>
        </div>
      </section>

      <!-- Photo Upload -->
      <section class="border-b pb-6">
        <h3 class="text-xl font-semibold text-gray-800 mb-4">Photos (Optional)</h3>
        <p class="text-sm text-gray-600 mb-3">Upload up to 5 photos of your vehicle or the issue.</p>

        <input
          ref="photoInput"
          type="file"
          multiple
          accept="image/*"
          @change="onPhotosChange"
          class="block w-full text-sm text-gray-500
            file:mr-4 file:py-2 file:px-4
            file:rounded-md file:border-0
            file:text-sm file:font-semibold
            file:bg-indigo-50 file:text-indigo-700
            hover:file:bg-indigo-100"
        />

        <div v-if="selectedPhotos.length > 0" class="mt-3">
          <p class="text-sm text-gray-700 font-medium mb-2">Selected photos ({{ selectedPhotos.length }}/5):</p>
          <ul class="text-sm text-gray-600 space-y-1">
            <li v-for="(photo, index) in selectedPhotos" :key="index">
              {{ photo.name }} ({{ formatFileSize(photo.size) }})
            </li>
          </ul>
        </div>
      </section>

      <!-- Success/Error Messages -->
      <div v-if="successMessage" class="p-4 bg-green-50 border border-green-200 rounded-md">
        <p class="text-green-800">{{ successMessage }}</p>
      </div>

      <div v-if="errorMessage" class="p-4 bg-red-50 border border-red-200 rounded-md">
        <p class="text-red-800">{{ errorMessage }}</p>
      </div>

      <!-- reCAPTCHA Badge Info -->
      <div class="text-xs text-gray-500">
        This site is protected by reCAPTCHA and the Google
        <a href="https://policies.google.com/privacy" target="_blank" class="text-indigo-600 hover:underline">Privacy Policy</a> and
        <a href="https://policies.google.com/terms" target="_blank" class="text-indigo-600 hover:underline">Terms of Service</a> apply.
      </div>

      <!-- Submit Button -->
      <div>
        <button
          type="submit"
          :disabled="submitting"
          class="w-full md:w-auto px-8 py-3 bg-indigo-600 text-white font-semibold rounded-md
            hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2
            disabled:bg-gray-400 disabled:cursor-not-allowed transition-colors"
        >
          {{ submitting ? 'Submitting...' : 'Submit Estimate Request' }}
        </button>
      </div>
    </form>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import api from '@/services/api'

const form = reactive({
  name: '',
  email: '',
  phone: '',
  address: '',
  city: '',
  state: '',
  zip: '',
  service_address_same_as_customer: true,
  service_address: '',
  service_city: '',
  service_state: '',
  service_zip: '',
  vehicle_year: '',
  vehicle_make: '',
  vehicle_model: '',
  vin: '',
  license_plate: '',
  service_type_id: '',
  description: '',
})

const errors = reactive({})
const vehicleYears = ref([])
const vehicleMakes = ref([])
const vehicleModels = ref([])
const serviceTypes = ref([])
const selectedPhotos = ref([])
const photoInput = ref(null)
const submitting = ref(false)
const successMessage = ref('')
const errorMessage = ref('')
const recaptchaSiteKey = ref('')

// Load initial data
onMounted(async () => {
  try {
    // Load vehicle years
    const yearsResponse = await api.get('/public/vehicle-years')
    vehicleYears.value = yearsResponse.data.years

    // Load service types
    const typesResponse = await api.get('/public/service-types')
    serviceTypes.value = typesResponse.data.service_types

    // Load reCAPTCHA config
    const recaptchaResponse = await api.get('/public/security/recaptcha')
    if (recaptchaResponse.data.enabled) {
      recaptchaSiteKey.value = recaptchaResponse.data.site_key
      loadRecaptchaScript()
    }
  } catch (error) {
    console.error('Failed to load form data:', error)
  }
})

// Load reCAPTCHA script
const loadRecaptchaScript = () => {
  if (document.getElementById('recaptcha-script')) return

  const script = document.createElement('script')
  script.id = 'recaptcha-script'
  script.src = `https://www.google.com/recaptcha/api.js?render=${recaptchaSiteKey.value}`
  document.head.appendChild(script)
}

// Vehicle dropdown handlers
const onYearChange = async () => {
  form.vehicle_make = ''
  form.vehicle_model = ''
  vehicleMakes.value = []
  vehicleModels.value = []

  if (!form.vehicle_year) return

  try {
    const response = await api.get(`/public/vehicle-makes?year=${form.vehicle_year}`)
    vehicleMakes.value = response.data.makes
  } catch (error) {
    console.error('Failed to load makes:', error)
  }
}

const onMakeChange = async () => {
  form.vehicle_model = ''
  vehicleModels.value = []

  if (!form.vehicle_make) return

  try {
    const response = await api.get(`/public/vehicle-models?year=${form.vehicle_year}&make=${form.vehicle_make}`)
    vehicleModels.value = response.data.models
  } catch (error) {
    console.error('Failed to load models:', error)
  }
}

// Photo upload handler
const onPhotosChange = (event) => {
  const files = Array.from(event.target.files || [])
  selectedPhotos.value = files.slice(0, 5)
}

// File size formatter
const formatFileSize = (bytes) => {
  if (bytes === 0) return '0 Bytes'
  const k = 1024
  const sizes = ['Bytes', 'KB', 'MB']
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i]
}

// Form validation
const validateForm = () => {
  Object.keys(errors).forEach(key => delete errors[key])

  if (!form.name.trim()) errors.name = 'Name is required'
  if (!form.email.trim()) {
    errors.email = 'Email is required'
  } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email)) {
    errors.email = 'Invalid email format'
  }
  if (!form.phone.trim()) errors.phone = 'Phone is required'

  return Object.keys(errors).length === 0
}

// Get reCAPTCHA token
const getRecaptchaToken = () => {
  return new Promise((resolve) => {
    if (!recaptchaSiteKey.value || !window.grecaptcha) {
      resolve(null)
      return
    }

    window.grecaptcha.ready(() => {
      window.grecaptcha.execute(recaptchaSiteKey.value, { action: 'estimate_request' })
        .then(token => resolve(token))
        .catch(() => resolve(null))
    })
  })
}

// Form submission
const handleSubmit = async () => {
  successMessage.value = ''
  errorMessage.value = ''

  if (!validateForm()) {
    errorMessage.value = 'Please fix the errors in the form'
    return
  }

  submitting.value = true

  try {
    // Get reCAPTCHA token
    const recaptchaToken = await getRecaptchaToken()

    // Prepare form data
    const formData = new FormData()
    Object.keys(form).forEach(key => {
      if (form[key] !== '' && form[key] !== null) {
        formData.append(key, form[key])
      }
    })

    if (recaptchaToken) {
      formData.append('recaptcha_token', recaptchaToken)
    }

    // Add photos
    selectedPhotos.value.forEach((photo, index) => {
      formData.append(`photos[${index}]`, photo)
    })

    // Submit request
    const response = await api.post('/public/estimate-request', formData, {
      headers: { 'Content-Type': 'multipart/form-data' }
    })

    if (response.data.success) {
      successMessage.value = response.data.message

      // Reset form
      Object.keys(form).forEach(key => {
        if (typeof form[key] === 'boolean') {
          form[key] = key === 'service_address_same_as_customer'
        } else {
          form[key] = ''
        }
      })
      selectedPhotos.value = []
      if (photoInput.value) {
        photoInput.value.value = ''
      }

      // Scroll to success message
      window.scrollTo({ top: 0, behavior: 'smooth' })
    }
  } catch (error) {
    console.error('Submission error:', error)
    errorMessage.value = error.response?.data?.error || 'Failed to submit request. Please try again.'
  } finally {
    submitting.value = false
  }
}
</script>
