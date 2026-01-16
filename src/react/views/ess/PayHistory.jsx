import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'

const payPeriods = [
  {
    id: 1,
    period: 'Sep 16 - Sep 30, 2024',
    payDate: 'Oct 4, 2024',
    gross: '$2,340.00',
    net: '$1,876.25',
    status: 'Paid',
    url: '/ess/paystubs/2024-10-04.pdf',
  },
  {
    id: 2,
    period: 'Oct 1 - Oct 15, 2024',
    payDate: 'Oct 18, 2024',
    gross: '$2,520.00',
    net: '$2,012.40',
    status: 'Processing',
    url: '/ess/paystubs/2024-10-18.pdf',
  },
  {
    id: 3,
    period: 'Oct 16 - Oct 31, 2024',
    payDate: 'Nov 1, 2024',
    gross: '$2,410.00',
    net: '$1,940.10',
    status: 'Scheduled',
    url: '/ess/paystubs/2024-11-01.pdf',
  },
]

const statusStyles = {
  Paid: 'bg-emerald-50 text-emerald-700',
  Processing: 'bg-amber-50 text-amber-700',
  Scheduled: 'bg-blue-50 text-blue-700',
}

export default function PayHistory() {
  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-gray-900">Pay History</h1>
        <p className="mt-1 text-sm text-gray-500">
          Review gross and net pay details. Download your pay stub PDFs anytime.
        </p>
      </div>

      <Card title="Recent pay stubs">
        <div className="space-y-4">
          {payPeriods.map((period) => (
            <div key={period.id} className="rounded-lg border border-gray-100 p-4">
              <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                  <p className="text-sm text-gray-500">Pay period</p>
                  <p className="text-base font-semibold text-gray-900">{period.period}</p>
                  <p className="text-xs text-gray-500">Pay date: {period.payDate}</p>
                </div>
                <span className={`inline-flex items-center rounded-full px-3 py-1 text-xs font-medium ${statusStyles[period.status]}`}>
                  {period.status}
                </span>
              </div>
              <div className="mt-4 flex flex-col gap-2 text-sm text-gray-600 sm:flex-row sm:items-center sm:justify-between">
                <div className="flex items-center gap-6">
                  <span>Gross: {period.gross}</span>
                  <span>Net: {period.net}</span>
                </div>
                <Button
                  type="button"
                  variant="outline"
                  onClick={() => {
                    window.open(period.url, '_blank', 'noopener,noreferrer')
                  }}
                >
                  View PDF
                </Button>
              </div>
            </div>
          ))}
        </div>
      </Card>
    </div>
  )
}
