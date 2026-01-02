import Card from '../ui/Card'

export default function CustomerCard({ customer, name = customer?.name, email = customer?.email }) {
  return (
    <Card>
      <div className="space-y-1">
        <h4 className="text-sm font-semibold text-gray-900">{name || 'Customer'}</h4>
        {email ? <p className="text-xs text-gray-500">{email}</p> : null}
        {customer?.phone ? <p className="text-xs text-gray-500">{customer.phone}</p> : null}
      </div>
    </Card>
  )
}
