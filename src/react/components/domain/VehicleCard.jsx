import Card from '../ui/Card'

export default function VehicleCard({ vehicle }) {
  return (
    <Card>
      <div className="space-y-1">
        <h4 className="text-sm font-semibold text-gray-900">
          {vehicle?.year} {vehicle?.make} {vehicle?.model}
        </h4>
        {vehicle?.vin ? <p className="text-xs text-gray-500">VIN: {vehicle.vin}</p> : null}
        {vehicle?.license_plate ? (
          <p className="text-xs text-gray-500">Plate: {vehicle.license_plate}</p>
        ) : null}
      </div>
    </Card>
  )
}
