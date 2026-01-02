import Card from '../ui/Card'
import Badge from '../ui/Badge'

export default function AppointmentCard({
  appointment,
  title = appointment?.title || 'Appointment',
  status = appointment?.status || 'scheduled',
  time = appointment?.time || appointment?.start_time,
  customer = appointment?.customer_name,
}) {
  return (
    <Card>
      <div className="space-y-2">
        <div className="flex items-center justify-between">
          <h4 className="text-sm font-semibold text-gray-900">{title}</h4>
          <Badge size="sm" variant="info">
            {status}
          </Badge>
        </div>
        <p className="text-xs text-gray-500">{time || 'Time TBD'}</p>
        {customer ? <p className="text-sm text-gray-600">Customer: {customer}</p> : null}
      </div>
    </Card>
  )
}
