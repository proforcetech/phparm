import { useEffect, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'

import Alert from '../../components/ui/Alert'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import subPortalService from '../../../services/sub-portal.service'

function formatDateTime(value) {
  if (!value) return ''
  const d = new Date(String(value).replace(' ', 'T'))
  return Number.isNaN(d.valueOf()) ? value : d.toLocaleString()
}

export default function SubPortalPasswordSetup() {
  const navigate = useNavigate()
  const [search] = useSearchParams()
  const token = (search.get('token') || '').trim()

  const [loading, setLoading] = useState(true)
  const [details, setDetails] = useState(null)
  const [error, setError] = useState('')
  const [password, setPassword] = useState('')
  const [confirm, setConfirm] = useState('')
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    if (!token) {
      setError('Setup token is missing.')
      setLoading(false)
      return
    }
    setLoading(true)
    setError('')
    subPortalService
      .inspectPasswordSetup(token)
      .then((res) => setDetails(res?.data ?? null))
      .catch((e) => setError(e?.response?.data?.message || e?.message || 'This setup link is invalid or expired.'))
      .finally(() => setLoading(false))
  }, [token])

  const submit = async (event) => {
    event.preventDefault()
    if (password.length < 8) {
      setError('Password must be at least 8 characters.')
      return
    }
    if (password !== confirm) {
      setError('Password confirmation does not match.')
      return
    }

    setBusy(true)
    setError('')
    try {
      await subPortalService.completePasswordSetup(token, password, confirm)
      navigate('/sub-portal', { replace: true })
    } catch (e) {
      setError(e?.response?.data?.message || e?.message || 'Could not set password.')
    } finally {
      setBusy(false)
    }
  }

  if (loading) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-gray-50 p-4">
        <Loading />
      </div>
    )
  }

  return (
    <div className="min-h-screen flex items-center justify-center bg-gray-50 p-4">
      <Card className="max-w-md w-full" padding={false}>
        <form onSubmit={submit} className="p-6 space-y-4">
          <div>
            <h1 className="text-xl font-semibold">Set portal password</h1>
            {details?.subcontractor?.company_name && (
              <p className="text-sm text-gray-500 mt-1">{details.subcontractor.company_name}</p>
            )}
          </div>
          {error && <Alert variant="danger">{error}</Alert>}
          {details && (
            <>
              <Input
                label="Password"
                type="password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                required
                autocomplete="new-password"
                helperText="At least 8 characters."
              />
              <Input
                label="Confirm password"
                type="password"
                value={confirm}
                onChange={(e) => setConfirm(e.target.value)}
                required
                autocomplete="new-password"
              />
              {details.expires_at && (
                <p className="text-xs text-gray-500">Link expires {formatDateTime(details.expires_at)}</p>
              )}
              <Button
                type="submit"
                fullWidth
                loading={busy}
                disabled={busy || password.length < 8 || confirm.length < 8}
              >
                Set password
              </Button>
            </>
          )}
        </form>
      </Card>
    </div>
  )
}
