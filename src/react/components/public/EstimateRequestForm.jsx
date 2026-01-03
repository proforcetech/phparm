import { useCallback, useEffect, useRef, useState } from 'react'

import api from '../../../services/api'

const initialFormState = {
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
}

export default function EstimateRequestForm() {
  const [form, setForm] = useState(initialFormState)
  const [errors, setErrors] = useState({})
  const [vehicleYears, setVehicleYears] = useState([])
  const [vehicleMakes, setVehicleMakes] = useState([])
  const [vehicleModels, setVehicleModels] = useState([])
  const [serviceTypes, setServiceTypes] = useState([])
  const [selectedPhotos, setSelectedPhotos] = useState([])
  const [submitting, setSubmitting] = useState(false)
  const [successMessage, setSuccessMessage] = useState('')
  const [errorMessage, setErrorMessage] = useState('')
  const [recaptchaSiteKey, setRecaptchaSiteKey] = useState('')
  const photoInputRef = useRef(null)

  const loadRecaptchaScript = useCallback(() => {
    if (!recaptchaSiteKey || document.getElementById('recaptcha-script')) return

    const script = document.createElement('script')
    script.id = 'recaptcha-script'
    script.src = `https://www.google.com/recaptcha/api.js?render=${recaptchaSiteKey}`
    document.head.appendChild(script)
  }, [recaptchaSiteKey])

  useEffect(() => {
    const loadFormData = async () => {
      try {
        const yearsResponse = await api.get('/public/vehicle-years')
        setVehicleYears(yearsResponse.data.years || [])

        const typesResponse = await api.get('/public/service-types')
        setServiceTypes(typesResponse.data.service_types || [])

        const recaptchaResponse = await api.get('/public/security/recaptcha')
        if (recaptchaResponse.data.enabled) {
          setRecaptchaSiteKey(recaptchaResponse.data.site_key)
        }
      } catch (error) {
        console.error('Failed to load form data:', error)
      }
    }

    loadFormData()
  }, [])

  useEffect(() => {
    loadRecaptchaScript()
  }, [loadRecaptchaScript])

  const handleChange = (event) => {
    const { name, value, type, checked } = event.target
    setForm((prev) => ({
      ...prev,
      [name]: type === 'checkbox' ? checked : value,
    }))
  }

  const handleYearChange = async (event) => {
    const selectedYear = event.target.value
    setForm((prev) => ({
      ...prev,
      vehicle_year: selectedYear,
      vehicle_make: '',
      vehicle_model: '',
    }))
    setVehicleMakes([])
    setVehicleModels([])

    if (!selectedYear) return

    try {
      const response = await api.get(`/public/vehicle-makes?year=${selectedYear}`)
      setVehicleMakes(response.data.makes || [])
    } catch (error) {
      console.error('Failed to load makes:', error)
    }
  }

  const handleMakeChange = async (event) => {
    const selectedMake = event.target.value
    setForm((prev) => ({
      ...prev,
      vehicle_make: selectedMake,
      vehicle_model: '',
    }))
    setVehicleModels([])

    if (!selectedMake) return

    try {
      const response = await api.get(
        `/public/vehicle-models?year=${form.vehicle_year}&make=${selectedMake}`
      )
      setVehicleModels(response.data.models || [])
    } catch (error) {
      console.error('Failed to load models:', error)
    }
  }

  const handlePhotosChange = (event) => {
    const files = Array.from(event.target.files || [])
    setSelectedPhotos(files.slice(0, 5))
  }

  const formatFileSize = (bytes) => {
    if (bytes === 0) return '0 Bytes'
    const k = 1024
    const sizes = ['Bytes', 'KB', 'MB']
    const i = Math.floor(Math.log(bytes) / Math.log(k))
    return `${Math.round((bytes / Math.pow(k, i)) * 100) / 100} ${sizes[i]}`
  }

  const validateForm = () => {
    const newErrors = {}

    if (!form.name.trim()) newErrors.name = 'Name is required'
    if (!form.email.trim()) {
      newErrors.email = 'Email is required'
    } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email)) {
      newErrors.email = 'Invalid email format'
    }
    if (!form.phone.trim()) newErrors.phone = 'Phone is required'

    setErrors(newErrors)
    return Object.keys(newErrors).length === 0
  }

  const getRecaptchaToken = () =>
    new Promise((resolve) => {
      if (!recaptchaSiteKey || !window.grecaptcha) {
        resolve(null)
        return
      }

      window.grecaptcha.ready(() => {
        window.grecaptcha
          .execute(recaptchaSiteKey, { action: 'estimate_request' })
          .then((token) => resolve(token))
          .catch(() => resolve(null))
      })
    })

  const handleSubmit = async (event) => {
    event.preventDefault()
    setSuccessMessage('')
    setErrorMessage('')

    if (!validateForm()) {
      setErrorMessage('Please fix the errors in the form')
      return
    }

    setSubmitting(true)

    try {
      const recaptchaToken = await getRecaptchaToken()

      const formData = new FormData()
      Object.keys(form).forEach((key) => {
        if (form[key] !== '' && form[key] !== null) {
          formData.append(key, form[key])
        }
      })

      if (recaptchaToken) {
        formData.append('recaptcha_token', recaptchaToken)
      }

      selectedPhotos.forEach((photo, index) => {
        formData.append(`photos[${index}]`, photo)
      })

      const response = await api.post('/public/estimate-request', formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })

      if (response.data.success) {
        setSuccessMessage(response.data.message)
        setForm({ ...initialFormState })
        setSelectedPhotos([])
        if (photoInputRef.current) {
          photoInputRef.current.value = ''
        }
        window.scrollTo({ top: 0, behavior: 'smooth' })
      }
    } catch (error) {
      console.error('Submission error:', error)
      setErrorMessage(error.response?.data?.error || 'Failed to submit request. Please try again.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="mx-auto max-w-4xl rounded-lg bg-white p-6 shadow-lg">
      <h2 className="mb-6 text-3xl font-bold text-gray-900">Request an Estimate</h2>

      <form onSubmit={handleSubmit} className="space-y-8">
        <section className="border-b pb-6">
          <h3 className="mb-4 text-xl font-semibold text-gray-800">Contact Information</h3>
          <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
            <div>
              <label className="mb-1 block text-sm font-medium text-gray-700">Name *</label>
              <input
                name="name"
                value={form.name}
                onChange={handleChange}
                type="text"
                required
                className={`w-full rounded-md border px-3 py-2 focus:border-indigo-500 focus:ring-indigo-500 ${
                  errors.name ? 'border-red-500' : 'border-gray-300'
                }`}
              />
              {errors.name ? <p className="mt-1 text-sm text-red-500">{errors.name}</p> : null}
            </div>

            <div>
              <label className="mb-1 block text-sm font-medium text-gray-700">Email *</label>
              <input
                name="email"
                value={form.email}
                onChange={handleChange}
                type="email"
                required
                className={`w-full rounded-md border px-3 py-2 focus:border-indigo-500 focus:ring-indigo-500 ${
                  errors.email ? 'border-red-500' : 'border-gray-300'
                }`}
              />
              {errors.email ? <p className="mt-1 text-sm text-red-500">{errors.email}</p> : null}
            </div>

            <div>
              <label className="mb-1 block text-sm font-medium text-gray-700">Phone *</label>
              <input
                name="phone"
                value={form.phone}
                onChange={handleChange}
                type="tel"
                required
                className={`w-full rounded-md border px-3 py-2 focus:border-indigo-500 focus:ring-indigo-500 ${
                  errors.phone ? 'border-red-500' : 'border-gray-300'
                }`}
              />
              {errors.phone ? <p className="mt-1 text-sm text-red-500">{errors.phone}</p> : null}
            </div>
          </div>
        </section>

        <section className="border-b pb-6">
          <h3 className="mb-4 text-xl font-semibold text-gray-800">Your Address</h3>
          <div className="grid grid-cols-1 gap-4">
            <div>
              <label className="mb-1 block text-sm font-medium text-gray-700">
                Street Address *
              </label>
              <input
                name="address"
                value={form.address}
                onChange={handleChange}
                type="text"
                required
                className="w-full rounded-md border border-gray-300 px-3 py-2 focus:border-indigo-500 focus:ring-indigo-500"
              />
            </div>

            <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
              <div>
                <label className="mb-1 block text-sm font-medium text-gray-700">City *</label>
                <input
                  name="city"
                  value={form.city}
                  onChange={handleChange}
                  type="text"
                  required
                  className="w-full rounded-md border border-gray-300 px-3 py-2 focus:border-indigo-500 focus:ring-indigo-500"
                />
              </div>

              <div>
                <label className="mb-1 block text-sm font-medium text-gray-700">State *</label>
                <input
                  name="state"
                  value={form.state}
                  onChange={handleChange}
                  type="text"
                  required
                  maxLength={2}
                  className="w-full rounded-md border border-gray-300 px-3 py-2 focus:border-indigo-500 focus:ring-indigo-500"
                />
              </div>

              <div>
                <label className="mb-1 block text-sm font-medium text-gray-700">ZIP Code *</label>
                <input
                  name="zip"
                  value={form.zip}
                  onChange={handleChange}
                  type="text"
                  required
                  className="w-full rounded-md border border-gray-300 px-3 py-2 focus:border-indigo-500 focus:ring-indigo-500"
                />
              </div>
            </div>
          </div>
        </section>

        <section className="border-b pb-6">
          <h3 className="mb-4 text-xl font-semibold text-gray-800">Service Location</h3>

          <div className="mb-4">
            <label className="flex items-center space-x-2">
              <input
                name="service_address_same_as_customer"
                checked={form.service_address_same_as_customer}
                onChange={handleChange}
                type="checkbox"
                className="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
              />
              <span className="text-sm font-medium text-gray-700">
                Service location is same as my address
              </span>
            </label>
          </div>

          {!form.service_address_same_as_customer ? (
            <div className="grid grid-cols-1 gap-4">
              <div>
                <label className="mb-1 block text-sm font-medium text-gray-700">
                  Service Street Address
                </label>
                <input
                  name="service_address"
                  value={form.service_address}
                  onChange={handleChange}
                  type="text"
                  className="w-full rounded-md border border-gray-300 px-3 py-2 focus:border-indigo-500 focus:ring-indigo-500"
                />
              </div>

              <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
                <div>
                  <label className="mb-1 block text-sm font-medium text-gray-700">City</label>
                  <input
                    name="service_city"
                    value={form.service_city}
                    onChange={handleChange}
                    type="text"
                    className="w-full rounded-md border border-gray-300 px-3 py-2 focus:border-indigo-500 focus:ring-indigo-500"
                  />
                </div>

                <div>
                  <label className="mb-1 block text-sm font-medium text-gray-700">State</label>
                  <input
                    name="service_state"
                    value={form.service_state}
                    onChange={handleChange}
                    type="text"
                    maxLength={2}
                    className="w-full rounded-md border border-gray-300 px-3 py-2 focus:border-indigo-500 focus:ring-indigo-500"
                  />
                </div>

                <div>
                  <label className="mb-1 block text-sm font-medium text-gray-700">ZIP Code</label>
                  <input
                    name="service_zip"
                    value={form.service_zip}
                    onChange={handleChange}
                    type="text"
                    className="w-full rounded-md border border-gray-300 px-3 py-2 focus:border-indigo-500 focus:ring-indigo-500"
                  />
                </div>
              </div>
            </div>
          ) : null}
        </section>

        <section className="border-b pb-6">
          <h3 className="mb-4 text-xl font-semibold text-gray-800">Vehicle Information</h3>

          <div className="mb-4 grid grid-cols-1 gap-4 md:grid-cols-3">
            <div>
              <label className="mb-1 block text-sm font-medium text-gray-700">Year</label>
              <select
                name="vehicle_year"
                value={form.vehicle_year}
                onChange={handleYearChange}
                className="w-full rounded-md border border-gray-300 px-3 py-2 focus:border-indigo-500 focus:ring-indigo-500"
              >
                <option value="">Select Year</option>
                {vehicleYears.map((year) => (
                  <option key={year} value={year}>
                    {year}
                  </option>
                ))}
              </select>
            </div>

            <div>
              <label className="mb-1 block text-sm font-medium text-gray-700">Make</label>
              <select
                name="vehicle_make"
                value={form.vehicle_make}
                onChange={handleMakeChange}
                disabled={!form.vehicle_year}
                className="w-full rounded-md border border-gray-300 px-3 py-2 focus:border-indigo-500 focus:ring-indigo-500 disabled:bg-gray-100"
              >
                <option value="">Select Make</option>
                {vehicleMakes.map((make) => (
                  <option key={make} value={make}>
                    {make}
                  </option>
                ))}
              </select>
            </div>

            <div>
              <label className="mb-1 block text-sm font-medium text-gray-700">Model</label>
              <select
                name="vehicle_model"
                value={form.vehicle_model}
                onChange={handleChange}
                disabled={!form.vehicle_make}
                className="w-full rounded-md border border-gray-300 px-3 py-2 focus:border-indigo-500 focus:ring-indigo-500 disabled:bg-gray-100"
              >
                <option value="">Select Model</option>
                {vehicleModels.map((model) => (
                  <option key={model} value={model}>
                    {model}
                  </option>
                ))}
              </select>
            </div>
          </div>

          <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
            <div>
              <label className="mb-1 block text-sm font-medium text-gray-700">VIN (optional)</label>
              <input
                name="vin"
                value={form.vin}
                onChange={handleChange}
                type="text"
                maxLength={17}
                className="w-full rounded-md border border-gray-300 px-3 py-2 focus:border-indigo-500 focus:ring-indigo-500"
              />
            </div>

            <div>
              <label className="mb-1 block text-sm font-medium text-gray-700">
                License Plate (optional)
              </label>
              <input
                name="license_plate"
                value={form.license_plate}
                onChange={handleChange}
                type="text"
                className="w-full rounded-md border border-gray-300 px-3 py-2 focus:border-indigo-500 focus:ring-indigo-500"
              />
            </div>
          </div>
        </section>

        <section className="border-b pb-6">
          <h3 className="mb-4 text-xl font-semibold text-gray-800">Service Needed</h3>

          <div className="mb-4">
            <label className="mb-1 block text-sm font-medium text-gray-700">Service Type</label>
            <select
              name="service_type_id"
              value={form.service_type_id}
              onChange={handleChange}
              className="w-full rounded-md border border-gray-300 px-3 py-2 focus:border-indigo-500 focus:ring-indigo-500"
            >
              <option value="">Select Service Type</option>
              {serviceTypes.map((type) => (
                <option key={type.id} value={type.id}>
                  {type.name}
                </option>
              ))}
            </select>
          </div>

          <div>
            <label className="mb-1 block text-sm font-medium text-gray-700">Additional Details</label>
            <textarea
              name="description"
              value={form.description}
              onChange={handleChange}
              rows={4}
              placeholder="Please describe the issue or service you need..."
              className="w-full rounded-md border border-gray-300 px-3 py-2 focus:border-indigo-500 focus:ring-indigo-500"
            ></textarea>
          </div>
        </section>

        <section className="border-b pb-6">
          <h3 className="mb-4 text-xl font-semibold text-gray-800">Photos (Optional)</h3>
          <p className="mb-3 text-sm text-gray-600">
            Upload up to 5 photos of your vehicle or the issue.
          </p>

          <input
            ref={photoInputRef}
            type="file"
            multiple
            accept="image/*"
            onChange={handlePhotosChange}
            className="block w-full text-sm text-gray-500 file:mr-4 file:rounded-md file:border-0 file:bg-indigo-50 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-indigo-700 hover:file:bg-indigo-100"
          />

          {selectedPhotos.length > 0 ? (
            <div className="mt-3">
              <p className="mb-2 text-sm font-medium text-gray-700">
                Selected photos ({selectedPhotos.length}/5):
              </p>
              <ul className="space-y-1 text-sm text-gray-600">
                {selectedPhotos.map((photo) => (
                  <li key={photo.name}>
                    {photo.name} ({formatFileSize(photo.size)})
                  </li>
                ))}
              </ul>
            </div>
          ) : null}
        </section>

        {successMessage ? (
          <div className="rounded-md border border-green-200 bg-green-50 p-4">
            <p className="text-green-800">{successMessage}</p>
          </div>
        ) : null}

        {errorMessage ? (
          <div className="rounded-md border border-red-200 bg-red-50 p-4">
            <p className="text-red-800">{errorMessage}</p>
          </div>
        ) : null}

        <div className="text-xs text-gray-500">
          This site is protected by reCAPTCHA and the Google{' '}
          <a
            href="https://policies.google.com/privacy"
            target="_blank"
            rel="noreferrer"
            className="text-indigo-600 hover:underline"
          >
            Privacy Policy
          </a>{' '}
          and{' '}
          <a
            href="https://policies.google.com/terms"
            target="_blank"
            rel="noreferrer"
            className="text-indigo-600 hover:underline"
          >
            Terms of Service
          </a>{' '}
          apply.
        </div>

        <div>
          <button
            type="submit"
            disabled={submitting}
            className="w-full rounded-md bg-indigo-600 px-8 py-3 font-semibold text-white transition-colors hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:bg-gray-400 md:w-auto"
          >
            {submitting ? 'Submitting...' : 'Submit Estimate Request'}
          </button>
        </div>
      </form>
    </div>
  )
}
