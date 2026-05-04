import { useCallback, useEffect, useState } from 'react'

import Alert from '../../components/ui/Alert'
import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Modal from '../../components/ui/Modal'
import Select from '../../components/ui/Select'
import api from '../../../services/api'
import subPortalTokens from '../../../services/sub-portal-tokens.service'

/**
 * Phase 18 / C2 — staff-side management page for subcontractor portal tokens.
 *
 * Pick a sub, see its outstanding tokens, issue a new one (we surface the
 * plaintext exactly once for copy), or revoke. Issued tokens are scoped
 * to ONE subcontractor and are the entire credential for the public
 * /sub-portal/:token page.
 *
 * Read perm: subcontractors.view; Write perm: subcontractors.manage
 * (enforced server-side; staff users without those perms get 403s here).
 */
export default function SubPortalTokens() {
  const [subs, setSubs] = useState([])
  const [subsLoading, setSubsLoading] = useState(true)
  const [subId, setSubId] = useState('')
  const [tokens, setTokens] = useState([])
  const [tokensLoading, setTokensLoading] = useState(false)
  const [includeRevoked, setIncludeRevoked] = useState(false)
  const [error, setError] = useState('')

  const [issueOpen, setIssueOpen] = useState(false)
  const [issueLabel, setIssueLabel] = useState('')
  const [issueExpires, setIssueExpires] = useState('')
  const [issueBusy, setIssueBusy] = useState(false)
  const [issuedSecret, setIssuedSecret] = useState(null) // { plaintext, link }

  useEffect(() => {
    setSubsLoading(true)
    api
      .get('/subcontractors', { params: { status: 'active', limit: 200 } })
      .then((res) => {
        const list = res.data?.data ?? []
        setSubs(list)
        if (!subId && list.length > 0) setSubId(String(list[0].id))
      })
      .catch((e) => setError(e?.response?.data?.message || e?.message || 'Failed to load subs'))
      .finally(() => setSubsLoading(false))
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  const loadTokens = useCallback(() => {
    if (!subId) return
    setTokensLoading(true)
    subPortalTokens
      .list(subId, includeRevoked)
      .then((res) => setTokens(res?.data ?? []))
      .catch((e) => setError(e?.response?.data?.message || e?.message || 'Failed to load tokens'))
      .finally(() => setTokensLoading(false))
  }, [subId, includeRevoked])

  useEffect(() => { loadTokens() }, [loadTokens])

  const submitIssue = async () => {
    if (!subId) return
    setIssueBusy(true)
    try {
      const res = await subPortalTokens.issue(subId, {
        label: issueLabel || undefined,
        expires_at: issueExpires || undefined,
      })
      const plaintext = res?.data?.plaintext
      const link = `${window.location.origin}/sub-portal/${plaintext}`
      setIssuedSecret({ plaintext, link })
      setIssueOpen(false)
      setIssueLabel('')
      setIssueExpires('')
      loadTokens()
    } catch (e) {
      setError(e?.response?.data?.message || e?.message || 'Failed to issue token')
    } finally {
      setIssueBusy(false)
    }
  }

  const revoke = async (tokenId) => {
    if (!confirm('Revoke this token? The subcontractor will lose portal access immediately.')) return
    try {
      await subPortalTokens.revoke(tokenId)
      loadTokens()
    } catch (e) {
      setError(e?.response?.data?.message || e?.message || 'Failed to revoke')
    }
  }

  const copy = async (text) => {
    try { await navigator.clipboard.writeText(text) } catch { /* no-op */ }
  }

  return (
    <div className="space-y-4 p-4">
      <header>
        <h1 className="text-xl font-semibold">Subcontractor Portal Tokens</h1>
        <p className="text-sm text-gray-500">
          Issue a long-lived bearer token a sub can use from the public self-service
          portal at <code>/sub-portal/&lt;token&gt;</code>. The plaintext is shown
          exactly once at issue time — store it before closing the dialog.
        </p>
      </header>

      {error && <Alert variant="danger" onClose={() => setError('')}>{error}</Alert>}

      <Card>
        <div className="p-4 flex items-end gap-3 flex-wrap">
          <Select
            label="Subcontractor"
            value={subId}
            onChange={(e) => setSubId(e?.target?.value ?? e)}
            options={[
              { value: '', label: subsLoading ? 'Loading…' : 'Select…' },
              ...subs.map((s) => ({ value: String(s.id), label: s.company_name })),
            ]}
          />
          <label className="flex items-center gap-2 text-sm">
            <input
              type="checkbox"
              checked={includeRevoked}
              onChange={(e) => setIncludeRevoked(e.target.checked)}
            />
            Show revoked
          </label>
          <Button onClick={() => setIssueOpen(true)} disabled={!subId}>Issue token</Button>
          <Button variant="secondary" onClick={loadTokens}>Refresh</Button>
        </div>

        {tokensLoading ? (
          <div className="p-6 text-center"><Loading /></div>
        ) : tokens.length === 0 ? (
          <div className="p-6 text-center text-gray-500">No tokens issued.</div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="bg-gray-50 text-xs uppercase text-gray-500">
                <tr>
                  <th className="text-left p-2">Label</th>
                  <th className="text-left p-2">Created</th>
                  <th className="text-left p-2">Expires</th>
                  <th className="text-left p-2">Last used</th>
                  <th className="text-left p-2">Status</th>
                  <th className="text-right p-2"> </th>
                </tr>
              </thead>
              <tbody>
                {tokens.map((t) => (
                  <tr key={t.id} className="border-t">
                    <td className="p-2">{t.label || <span className="text-gray-400">—</span>}</td>
                    <td className="p-2">{t.created_at}</td>
                    <td className="p-2">{t.expires_at || <span className="text-gray-400">never</span>}</td>
                    <td className="p-2">{t.last_used_at || <span className="text-gray-400">never</span>}</td>
                    <td className="p-2">
                      {t.revoked_at
                        ? <Badge variant="danger">Revoked</Badge>
                        : t.is_active
                          ? <Badge variant="success">Active</Badge>
                          : <Badge variant="warning">Expired</Badge>}
                    </td>
                    <td className="p-2 text-right">
                      {!t.revoked_at && (
                        <Button variant="danger" onClick={() => revoke(t.id)}>Revoke</Button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      <Modal isOpen={issueOpen} onClose={() => setIssueOpen(false)} title="Issue portal token">
        <div className="space-y-3">
          <Input
            label="Label (optional)"
            value={issueLabel}
            onChange={(e) => setIssueLabel(e.target.value)}
            placeholder="e.g. Field tablet"
          />
          <Input
            label="Expires (optional, YYYY-MM-DD or full datetime)"
            value={issueExpires}
            onChange={(e) => setIssueExpires(e.target.value)}
            placeholder="leave blank for no expiry"
          />
          <Alert variant="info">
            The plaintext token will be displayed exactly once on the next screen.
            Copy it before closing.
          </Alert>
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={() => setIssueOpen(false)}>Cancel</Button>
            <Button disabled={issueBusy} onClick={submitIssue}>
              {issueBusy ? 'Issuing…' : 'Issue token'}
            </Button>
          </div>
        </div>
      </Modal>

      <Modal
        isOpen={issuedSecret !== null}
        onClose={() => setIssuedSecret(null)}
        title="Token issued"
      >
        {issuedSecret && (
          <div className="space-y-3">
            <Alert variant="warning">
              This is the only time you'll see this token. Store it somewhere safe and
              hand it to the subcontractor.
            </Alert>
            <div>
              <div className="text-xs uppercase text-gray-500 tracking-wide">Token</div>
              <div className="font-mono text-xs break-all border rounded p-2 bg-gray-50">
                {issuedSecret.plaintext}
              </div>
              <Button variant="secondary" onClick={() => copy(issuedSecret.plaintext)} className="mt-2">
                Copy token
              </Button>
            </div>
            <div>
              <div className="text-xs uppercase text-gray-500 tracking-wide">Portal link</div>
              <div className="font-mono text-xs break-all border rounded p-2 bg-gray-50">
                {issuedSecret.link}
              </div>
              <Button variant="secondary" onClick={() => copy(issuedSecret.link)} className="mt-2">
                Copy link
              </Button>
            </div>
            <div className="flex justify-end">
              <Button onClick={() => setIssuedSecret(null)}>Done</Button>
            </div>
          </div>
        )}
      </Modal>
    </div>
  )
}
