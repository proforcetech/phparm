import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'

import Card from '../../components/ui/Card'
import Alert from '../../components/ui/Alert'
import Loading from '../../components/ui/Loading'
import Button from '../../components/ui/Button'
import Textarea from '../../components/ui/Textarea'
import { portalAccountService } from '../../../services/portal/account.service'

/**
 * Phase 2f — CSAT (customer-satisfaction) surface.
 *
 * Pending list at top: every completed work order in the lookback window
 * the account hasn't already responded to. History below: every prior
 * response (read-only). Submission is idempotent server-side, so a
 * comment edit before refresh just overwrites cleanly.
 */
const formatDate = (s) => (s ? new Date(s).toLocaleString() : '')

function StarPicker({ value, onChange, disabled = false }) {
  const stars = [1, 2, 3, 4, 5]
  return (
    <div className="inline-flex gap-1" role="radiogroup" aria-label="Rating">
      {stars.map((n) => (
        <button
          key={n}
          type="button"
          role="radio"
          aria-checked={value === n}
          disabled={disabled}
          onClick={() => onChange(n)}
          className={`text-2xl leading-none transition-colors ${
            value && value >= n ? 'text-yellow-400' : 'text-gray-300'
          } hover:text-yellow-500 disabled:cursor-not-allowed`}
          title={`${n} star${n > 1 ? 's' : ''}`}
        >
          {'★'}
        </button>
      ))}
    </div>
  )
}

function PendingRow({ row, onSubmit }) {
  const [rating, setRating] = useState(0)
  const [comment, setComment] = useState('')
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState(null)

  const submit = async () => {
    if (!rating) {
      setErr('Please pick a rating.')
      return
    }
    setBusy(true)
    setErr(null)
    try {
      await onSubmit(row.workorder_id, { rating, comment: comment.trim() || null })
    } catch (e) {
      setErr(e.response?.data?.message || 'Unable to submit response.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <Card className="space-y-3">
      <div className="flex items-start justify-between gap-4 flex-wrap">
        <div>
          <Link
            to={`/p/work-orders/${row.workorder_id}`}
            className="text-base font-semibold hover:underline"
            style={{ color: 'var(--portal-primary, #2563eb)' }}
          >
            Work order #{row.workorder_id}
          </Link>
          {row.workorder_title && (
            <div className="text-sm text-gray-700 mt-0.5">{row.workorder_title}</div>
          )}
          <div className="text-xs text-gray-500 mt-0.5">
            Completed {formatDate(row.completed_at)}
          </div>
        </div>
        <StarPicker value={rating} onChange={setRating} disabled={busy} />
      </div>
      <Textarea
        placeholder="Optional comment (up to 2000 characters)"
        modelValue={comment}
        onUpdateModelValue={setComment}
        rows={2}
      />
      {err && <Alert variant="error" closable={false}>{err}</Alert>}
      <div className="flex justify-end">
        <Button size="sm" onClick={submit} loading={busy} disabled={busy || !rating}>
          Submit feedback
        </Button>
      </div>
    </Card>
  )
}

export default function PortalCsat() {
  const [pending, setPending] = useState([])
  const [history, setHistory] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [success, setSuccess] = useState(null)

  const reload = useCallback(async () => {
    setLoading(true)
    setError(null)
    try {
      const [p, h] = await Promise.all([
        portalAccountService.listCsatPending(),
        portalAccountService.listCsatHistory(),
      ])
      setPending(Array.isArray(p) ? p : [])
      setHistory(Array.isArray(h) ? h : [])
    } catch (err) {
      setError(err.response?.data?.message || 'Unable to load satisfaction surveys.')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => { reload() }, [reload])

  const handleSubmit = async (workorderId, body) => {
    await portalAccountService.submitCsat(workorderId, body)
    setSuccess('Thanks — your feedback was recorded.')
    await reload()
  }

  return (
    <div className="space-y-6">
      <header>
        <h1 className="text-2xl font-semibold">Satisfaction surveys</h1>
        <p className="text-sm text-gray-600 mt-1">
          Rate your recent completed work and review past responses.
        </p>
      </header>

      {error && <Alert variant="error" onClose={() => setError(null)}>{error}</Alert>}
      {success && <Alert variant="success" onClose={() => setSuccess(null)}>{success}</Alert>}

      {loading ? (
        <div className="py-12 flex justify-center"><Loading text="Loading surveys…" /></div>
      ) : (
        <>
          <section className="space-y-3">
            <h2 className="text-sm font-semibold uppercase tracking-wide text-gray-500">
              Awaiting your feedback {pending.length > 0 && `(${pending.length})`}
            </h2>
            {pending.length === 0 ? (
              <Card>
                <p className="text-sm text-gray-500">No pending surveys. Thanks for staying current.</p>
              </Card>
            ) : (
              <div className="space-y-3">
                {pending.map((row) => (
                  <PendingRow
                    key={row.workorder_id}
                    row={row}
                    onSubmit={handleSubmit}
                  />
                ))}
              </div>
            )}
          </section>

          <section className="space-y-3">
            <h2 className="text-sm font-semibold uppercase tracking-wide text-gray-500">
              History {history.length > 0 && `(${history.length})`}
            </h2>
            {history.length === 0 ? (
              <Card>
                <p className="text-sm text-gray-500">No prior responses.</p>
              </Card>
            ) : (
              <Card padding={false}>
                <ul className="divide-y">
                  {history.map((row) => (
                    <li key={row.id} className="p-4">
                      <div className="flex items-center justify-between gap-4 flex-wrap">
                        <div>
                          <Link
                            to={`/p/work-orders/${row.workorder_id}`}
                            className="text-sm font-medium hover:underline"
                            style={{ color: 'var(--portal-primary, #2563eb)' }}
                          >
                            Work order #{row.workorder_id}
                          </Link>
                          <div className="text-xs text-gray-500 mt-0.5">
                            Responded {formatDate(row.responded_at)}
                          </div>
                        </div>
                        <StarPicker value={row.rating || 0} onChange={() => {}} disabled />
                      </div>
                      {row.comment && (
                        <p className="mt-2 text-sm text-gray-700 whitespace-pre-wrap">
                          {row.comment}
                        </p>
                      )}
                    </li>
                  ))}
                </ul>
              </Card>
            )}
          </section>
        </>
      )}
    </div>
  )
}
