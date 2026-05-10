import { Link } from 'react-router-dom'

import Card from '../../components/ui/Card'

/**
 * Phase 2a — placeholder for routes whose surfaces are filled by 2b/2c.
 * Keeps the navigation walkable end-to-end before those phases land.
 */
export default function PortalSoon({ title, blurb }) {
  return (
    <Card>
      <h1 className="text-xl font-semibold">{title}</h1>
      <p className="text-sm text-gray-600 mt-2">
        {blurb || 'This area is being built. Check back soon.'}
      </p>
      <Link
        to="/p"
        className="inline-block mt-4 text-sm font-medium underline"
        style={{ color: 'var(--portal-primary, #2563eb)' }}
      >
        Back to home
      </Link>
    </Card>
  )
}
