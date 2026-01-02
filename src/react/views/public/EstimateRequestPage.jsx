import EstimateRequestForm from '../../components/public/EstimateRequestForm'

export default function EstimateRequestPage() {
  return (
    <div className="space-y-4">
      <header>
        <h1 className="text-2xl font-semibold text-gray-900">Estimate request</h1>
        <p className="text-sm text-gray-500">React mirror of Vue /request-estimate.</p>
      </header>
      <EstimateRequestForm />
    </div>
  )
}
