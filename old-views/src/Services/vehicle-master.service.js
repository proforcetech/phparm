import api from './api'

// List vehicle master records
export function listVehicleMaster(params = {}) {
  return api.get('/vehicles', { params }).then((r) => r.data)
}

// Get a single vehicle master record
export function getVehicleMaster(id) {
  return api.get(`/vehicles/${id}`).then((r) => r.data)
}

// Create a new vehicle master record
export function createVehicleMaster(payload) {
  return api.post('/vehicles', payload).then((r) => r.data)
}

// Update a vehicle master record
export function updateVehicleMaster(id, payload) {
  return api.put(`/vehicles/${id}`, payload).then((r) => r.data)
}

// Delete a vehicle master record
export function deleteVehicleMaster(id) {
  return api.delete(`/vehicles/${id}`).then((r) => r.data)
}

// Upload CSV file with vehicle data
export function uploadVehicleMasterCsv(file) {
  const formData = new FormData()
  formData.append('file', file)
  return api.post('/vehicles/upload-csv', formData, {
    headers: {
      'Content-Type': 'multipart/form-data'
    }
  }).then((r) => r.data)
}

// Get cascade dropdown data
export function getYears() {
  return api.get('/vehicles/years').then((r) => r.data)
}

const encodeSegment = (value) => encodeURIComponent(value)

export function getMakes(year) {
  return api.get(`/vehicles/${encodeSegment(year)}/makes`).then((r) => r.data)
}

export function getModels(year, make) {
  return api.get(`/vehicles/${encodeSegment(year)}/${encodeSegment(make)}/models`).then((r) => r.data)
}

export function getEngines(year, make, model) {
  return api.get(`/vehicles/${encodeSegment(year)}/${encodeSegment(make)}/${encodeSegment(model)}/engines`).then((r) => r.data)
}

export function getTransmissions(year, make, model, engine) {
  return api.get(`/vehicles/${encodeSegment(year)}/${encodeSegment(make)}/${encodeSegment(model)}/${encodeSegment(engine)}/transmissions`).then((r) => r.data)
}

export function getDrives(year, make, model, engine, transmission) {
  return api.get(`/vehicles/${encodeSegment(year)}/${encodeSegment(make)}/${encodeSegment(model)}/${encodeSegment(engine)}/${encodeSegment(transmission)}/drives`).then((r) => r.data)
}

export function getTrims(year, make, model, engine, transmission, drive) {
  return api.get(`/vehicles/${encodeSegment(year)}/${encodeSegment(make)}/${encodeSegment(model)}/${encodeSegment(engine)}/${encodeSegment(transmission)}/${encodeSegment(drive)}/trims`).then((r) => r.data)
}

// VIN operations
export function decodeVin(vin) {
  return api.post('/vehicles/decode-vin', { vin }).then((r) => r.data)
}

export function validateVin(vin) {
  return api.post('/vehicles/validate-vin', { vin }).then((r) => r.data)
}
