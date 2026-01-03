const cleanValue = (value) => {
  if (value === null || value === undefined) return null
  if (typeof value === 'string') {
    const cleaned = value.trim()
    return cleaned.length ? cleaned : null
  }
  return value
}

const toNumber = (value) => {
  const numeric = Number(value)
  return Number.isFinite(numeric) ? numeric : null
}

/**
 * Normalize VIN decoder responses to a consistent shape for UI consumption.
 * @param {Record<string, any>} result
 * @returns {{ vin: string|null, year: number|null, make: string|null, model: string|null, trim: string|null, engine: string|null, transmission: string|null, drive: string|null, fuel: string|null, bodyStyle: string|null, vehicleType: string|null, plantCountry: string|null, manufacturer: string|null }}
 */
export function normalizeVinData(result = {}) {
  const source = result.decoded || result.basic_info || result.vehicle || result || {}

  return {
    vin: cleanValue(result.vin ?? source.vin),
    year: toNumber(source.year ?? source.ModelYear),
    make: cleanValue(source.make ?? source.manufacturer ?? source.Make),
    model: cleanValue(source.model ?? source.Model),
    trim: cleanValue(source.trim ?? source.series ?? source.Series),
    engine: cleanValue(source.engine ?? source.Engine),
    transmission: cleanValue(source.transmission ?? source.Transmission),
    drive: cleanValue(source.drive ?? source.drive_type ?? source.driveType ?? source.DriveType),
    fuel: cleanValue(source.fuel_type ?? source.fuel ?? source.fuelType ?? source.FuelTypePrimary),
    bodyStyle: cleanValue(source.body_style ?? source.bodyStyle ?? source.BodyClass),
    vehicleType: cleanValue(source.vehicle_type ?? source.vehicleType ?? source.VehicleType),
    plantCountry: cleanValue(source.plant_country ?? source.plantCountry ?? source.PlantCountry),
    manufacturer: cleanValue(source.manufacturer ?? source.Manufacturer ?? source.Make),
  }
}
