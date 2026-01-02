import Card from '../components/ui/Card'

export default function PlaceholderPage({ title, description, children }) {
  return (
    <div className="space-y-4">
      <header>
        <h1 className="text-2xl font-semibold text-gray-900">{title}</h1>
        {description ? (
          <p className="text-sm text-gray-500">{description}</p>
        ) : null}
      </header>
      <Card>
        <div className="space-y-2 text-sm text-gray-600">
          {children ?? <p>This React screen is a placeholder during the migration.</p>}
        </div>
      </Card>
    </div>
  )
}
