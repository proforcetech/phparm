import Card from '../../components/ui/Card'

const schedule = [
  {
    id: 1,
    date: 'Mon, Oct 14',
    time: '8:00 AM - 5:00 PM',
    location: 'Main Shop - Bay 2',
    role: 'Lead Technician',
    manager: 'Dana Lopez',
  },
  {
    id: 2,
    date: 'Tue, Oct 15',
    time: '9:00 AM - 6:00 PM',
    location: 'Main Shop - Diagnostics',
    role: 'Technician',
    manager: 'Dana Lopez',
  },
  {
    id: 3,
    date: 'Wed, Oct 16',
    time: '10:00 AM - 7:00 PM',
    location: 'Mobile Unit - Route 3',
    role: 'Field Technician',
    manager: 'Chris Wong',
  },
]

const timeOff = [
  {
    id: 1,
    date: 'Fri, Oct 25',
    status: 'Approved',
    type: 'Personal Day',
    approver: 'Tanya Rivers',
  },
]

export default function Schedule() {
  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-gray-900">My Schedule</h1>
        <p className="mt-1 text-sm text-gray-500">Check upcoming shifts and time-off approvals.</p>
      </div>

      <Card title="Upcoming shifts">
        <div className="space-y-4">
          {schedule.map((shift) => (
            <div key={shift.id} className="rounded-lg border border-gray-100 p-4">
              <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <div>
                  <p className="text-sm text-gray-500">{shift.date}</p>
                  <p className="text-base font-semibold text-gray-900">{shift.time}</p>
                </div>
                <span className="inline-flex items-center rounded-full bg-emerald-50 px-3 py-1 text-xs font-medium text-emerald-700">
                  {shift.role}
                </span>
              </div>
              <div className="mt-3 grid grid-cols-1 gap-2 text-sm text-gray-500 sm:grid-cols-3">
                <span>Location: {shift.location}</span>
                <span>Manager: {shift.manager}</span>
                <span>On-call: No</span>
              </div>
            </div>
          ))}
        </div>
      </Card>

      <Card title="Time off">
        <div className="space-y-4">
          {timeOff.map((request) => (
            <div key={request.id} className="flex flex-col gap-1 rounded-lg border border-gray-100 p-4 sm:flex-row sm:items-center sm:justify-between">
              <div>
                <p className="text-sm font-medium text-gray-900">{request.type}</p>
                <p className="text-xs text-gray-500">{request.date}</p>
              </div>
              <div className="text-sm text-gray-500">Approved by {request.approver}</div>
              <span className="inline-flex items-center rounded-full bg-blue-50 px-3 py-1 text-xs font-medium text-blue-700">
                {request.status}
              </span>
            </div>
          ))}
        </div>
      </Card>
    </div>
  )
}
