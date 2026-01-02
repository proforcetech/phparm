import Card from '../ui/Card'
import Badge from '../ui/Badge'

export default function InvoiceCard({ invoice }) {
  return (
    <Card>
      <div className="space-y-2">
        <div className="flex items-center justify-between">
          <h4 className="text-sm font-semibold text-gray-900">
            Invoice {invoice?.number || invoice?.invoice_number || '#'}
          </h4>
          <Badge size="sm" variant="info">
            {invoice?.status || 'pending'}
          </Badge>
        </div>
        <p className="text-xs text-gray-500">{invoice?.customer_name || 'Customer'}</p>
        <p className="text-sm text-gray-700">
          Total: ${Number(invoice?.total || 0).toFixed(2)}
        </p>
      </div>
    </Card>
  )
}
