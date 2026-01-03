import { useMemo } from 'react'

function getVehicleTitle(vehicle) {
  if (vehicle?.year && vehicle?.make && vehicle?.model) {
    return `${vehicle.year} ${vehicle.make} ${vehicle.model}`
  }
  if (vehicle?.vin) {
    return vehicle.vin.substring(0, 17)
  }
  if (vehicle?.plate) {
    return vehicle.plate
  }
  return `Vehicle #${vehicle?.id || 'Unknown'}`
}

function formatMileage(mileage) {
  return new Intl.NumberFormat('en-US').format(mileage)
}

export default function VehicleCard({
  vehicle,
  showActions = true,
  showCustomer = true,
  showNotes = true,
  onClick,
  onAction,
  actions,
  footer,
}) {
  const vehicleTitle = useMemo(() => getVehicleTitle(vehicle), [vehicle])
  const mileage = useMemo(() => formatMileage(vehicle?.mileage), [vehicle?.mileage])

  const handleClick = () => {
    onClick?.(vehicle)
  }

  const handleAction = (event) => {
    event.stopPropagation()
    onAction?.(vehicle)
  }

  return (
    <div
      className="bg-white border border-gray-200 rounded-lg shadow-sm hover:shadow-md transition-shadow duration-200 cursor-pointer"
      onClick={handleClick}
      role={onClick ? 'button' : undefined}
      tabIndex={onClick ? 0 : undefined}
    >
      <div className="p-4 sm:p-6">
        <div className="flex items-start justify-between mb-4">
          <div className="flex-1 min-w-0">
            <div className="flex items-center gap-2 mb-2">
              <svg className="h-6 w-6 text-gray-400 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path
                  strokeLinecap="round"
                  strokeLinejoin="round"
                  strokeWidth="2"
                  d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"
                />
              </svg>
              <h3 className="text-lg font-semibold text-gray-900 truncate">{vehicleTitle}</h3>
            </div>
            {vehicle?.year || vehicle?.make || vehicle?.model ? (
              <p className="text-sm text-gray-600">
                {vehicle?.year} {vehicle?.make} {vehicle?.model}
              </p>
            ) : null}
          </div>
          <div className="flex-shrink-0 ml-4">
            {actions ??
              (showActions ? (
                <button
                  type="button"
                  onClick={handleAction}
                  className="text-gray-400 hover:text-gray-600"
                >
                  <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path
                      strokeLinecap="round"
                      strokeLinejoin="round"
                      strokeWidth="2"
                      d="M12 5v.01M12 12v.01M12 19v.01M12 6a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2z"
                    />
                  </svg>
                </button>
              ) : null)}
          </div>
        </div>

        <div className="space-y-2 mb-4">
          {vehicle?.vin ? (
            <div className="flex items-center justify-between text-sm">
              <span className="text-gray-500">VIN</span>
              <span className="text-gray-900 font-mono text-xs">{vehicle.vin}</span>
            </div>
          ) : null}
          {vehicle?.plate ? (
            <div className="flex items-center justify-between text-sm">
              <span className="text-gray-500">License Plate</span>
              <span className="text-gray-900 font-semibold">{vehicle.plate}</span>
            </div>
          ) : null}
        </div>

        <div className="border-t border-gray-200 pt-3 space-y-2">
          {vehicle?.engine ? (
            <div className="flex items-center justify-between text-sm">
              <span className="text-gray-500">Engine</span>
              <span className="text-gray-900">{vehicle.engine}</span>
            </div>
          ) : null}
          {vehicle?.transmission ? (
            <div className="flex items-center justify-between text-sm">
              <span className="text-gray-500">Transmission</span>
              <span className="text-gray-900">{vehicle.transmission}</span>
            </div>
          ) : null}
          {vehicle?.drive ? (
            <div className="flex items-center justify-between text-sm">
              <span className="text-gray-500">Drive</span>
              <span className="text-gray-900">{vehicle.drive}</span>
            </div>
          ) : null}
          {vehicle?.trim ? (
            <div className="flex items-center justify-between text-sm">
              <span className="text-gray-500">Trim</span>
              <span className="text-gray-900">{vehicle.trim}</span>
            </div>
          ) : null}
          {vehicle?.color ? (
            <div className="flex items-center justify-between text-sm">
              <span className="text-gray-500">Color</span>
              <span className="text-gray-900">{vehicle.color}</span>
            </div>
          ) : null}
        </div>

        {showCustomer && vehicle?.customer_id ? (
          <div className="border-t border-gray-200 pt-3 mt-3">
            <div className="flex items-center justify-between text-sm">
              <span className="text-gray-500">Owner</span>
              {vehicle?.customer_name ? (
                <span className="text-gray-900 font-medium">{vehicle.customer_name}</span>
              ) : (
                <span className="text-gray-900">Customer #{vehicle.customer_id}</span>
              )}
            </div>
          </div>
        ) : null}

        {vehicle?.mileage ? (
          <div className="border-t border-gray-200 pt-3 mt-3">
            <div className="flex items-center justify-between text-sm">
              <span className="text-gray-500">Mileage</span>
              <span className="text-gray-900 font-medium">{mileage} mi</span>
            </div>
          </div>
        ) : null}

        {vehicle?.notes && showNotes ? (
          <div className="border-t border-gray-200 pt-3 mt-3">
            <p className="text-xs text-gray-500 uppercase tracking-wide mb-1">Notes</p>
            <p
              className="text-sm text-gray-700"
              style={{
                display: '-webkit-box',
                WebkitLineClamp: 2,
                WebkitBoxOrient: 'vertical',
                overflow: 'hidden',
              }}
            >
              {vehicle.notes}
            </p>
          </div>
        ) : null}

        {footer ? <div>{footer}</div> : null}
      </div>
    </div>
  )
}
