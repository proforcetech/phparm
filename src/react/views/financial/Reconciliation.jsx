import { useEffect, useMemo, useState } from 'react'

import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Select from '../../components/ui/Select'
import Textarea from '../../components/ui/Textarea'
import reconciliationService from '../../../services/reconciliation.service'
import { useToast } from '../../stores/toast.jsx'

const emptySessionForm = {
  name: '',
  start_date: '',
  end_date: '',
}

const emptyBankForm = {
  transaction_date: '',
  description: '',
  reference: '',
  amount: '',
}

const formatCurrency = (value) => {
  const number = Number.parseFloat(value ?? 0)
  if (Number.isNaN(number)) return '$0.00'
  return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(number)
}

const matchBadgeStyles = {
  matched: 'bg-emerald-100 text-emerald-700',
  discrepancy: 'bg-amber-100 text-amber-700',
}

export default function Reconciliation() {
  const toast = useToast()
  const [loading, setLoading] = useState(false)
  const [sessions, setSessions] = useState([])
  const [activeSessionId, setActiveSessionId] = useState('')
  const [activeSession, setActiveSession] = useState(null)
  const [sessionSummary, setSessionSummary] = useState(null)
  const [sessionForm, setSessionForm] = useState({ ...emptySessionForm })
  const [bankForm, setBankForm] = useState({ ...emptyBankForm })
  const [bankTransactions, setBankTransactions] = useState([])
  const [ledgerEntries, setLedgerEntries] = useState([])
  const [ledgerSearch, setLedgerSearch] = useState('')
  const [selectedBankId, setSelectedBankId] = useState(null)
  const [selectedLedgerId, setSelectedLedgerId] = useState(null)
  const [discrepancyReason, setDiscrepancyReason] = useState('')
  const [discrepancyNotes, setDiscrepancyNotes] = useState('')
  const [amountDifference, setAmountDifference] = useState('')

  useEffect(() => {
    fetchSessions()
  }, [])

  useEffect(() => {
    if (activeSessionId) {
      fetchSessionData(activeSessionId)
    }
  }, [activeSessionId])

  const fetchSessions = () => {
    setLoading(true)
    reconciliationService
      .listSessions()
      .then((res) => {
        setSessions(res.data || [])
        if (!activeSessionId && res.data?.length) {
          setActiveSessionId(res.data[0].id)
        }
      })
      .catch(() => toast.error('Unable to load reconciliation sessions'))
      .finally(() => setLoading(false))
  }

  const fetchSessionData = (sessionId) => {
    setLoading(true)
    Promise.all([
      reconciliationService.fetchSession(sessionId),
      reconciliationService.listBankTransactions(sessionId),
      reconciliationService.listLedgerEntries(sessionId, { search: ledgerSearch }),
    ])
      .then(([sessionData, bankData, ledgerData]) => {
        setActiveSession(sessionData.session)
        setSessionSummary(sessionData.summary)
        setBankTransactions(bankData.data || [])
        setLedgerEntries(ledgerData.data || [])
        setSelectedBankId(null)
        setSelectedLedgerId(null)
      })
      .catch(() => toast.error('Unable to load reconciliation data'))
      .finally(() => setLoading(false))
  }

  const refreshLedger = () => {
    if (!activeSessionId) return
    reconciliationService
      .listLedgerEntries(activeSessionId, { search: ledgerSearch })
      .then((res) => setLedgerEntries(res.data || []))
      .catch(() => toast.error('Unable to load ledger entries'))
  }

  const refreshBank = () => {
    if (!activeSessionId) return
    reconciliationService
      .listBankTransactions(activeSessionId)
      .then((res) => setBankTransactions(res.data || []))
      .catch(() => toast.error('Unable to load bank transactions'))
  }

  const refreshSummary = () => {
    if (!activeSessionId) return
    reconciliationService
      .fetchSession(activeSessionId)
      .then((res) => setSessionSummary(res.summary))
      .catch(() => null)
  }

  const createSession = () => {
    if (!sessionForm.name || !sessionForm.start_date || !sessionForm.end_date) {
      toast.error('Name, start date, and end date are required')
      return
    }
    reconciliationService
      .createSession(sessionForm)
      .then((session) => {
        toast.success('Session created')
        setSessions((prev) => [session, ...prev])
        setActiveSessionId(session.id)
        setSessionForm({ ...emptySessionForm })
      })
      .catch(() => toast.error('Unable to create session'))
  }

  const updateSessionStatus = (status) => {
    if (!activeSessionId || !activeSession) return
    reconciliationService
      .updateSession(activeSessionId, { ...activeSession, status })
      .then(() => {
        toast.success('Session updated')
        fetchSessions()
        fetchSessionData(activeSessionId)
      })
      .catch(() => toast.error('Unable to update session'))
  }

  const addBankTransaction = () => {
    if (!activeSessionId) return
    if (!bankForm.transaction_date || !bankForm.description || bankForm.amount === '') {
      toast.error('Date, description, and amount are required')
      return
    }
    reconciliationService
      .createBankTransaction(activeSessionId, bankForm)
      .then(() => {
        toast.success('Bank transaction added')
        setBankForm({ ...emptyBankForm })
        refreshBank()
        refreshSummary()
      })
      .catch(() => toast.error('Unable to add bank transaction'))
  }

  const selectedBank = useMemo(
    () => bankTransactions.find((item) => item.id === selectedBankId),
    [bankTransactions, selectedBankId]
  )

  const selectedLedger = useMemo(
    () => ledgerEntries.find((item) => item.id === selectedLedgerId),
    [ledgerEntries, selectedLedgerId]
  )

  const computedDifference = useMemo(() => {
    if (selectedBank && selectedLedger) {
      return selectedBank.amount - selectedLedger.amount
    }
    if (selectedBank && !selectedLedger) {
      return selectedBank.amount
    }
    if (!selectedBank && selectedLedger) {
      return -selectedLedger.amount
    }
    return 0
  }, [selectedBank, selectedLedger])

  useEffect(() => {
    setAmountDifference(computedDifference.toFixed(2))
  }, [computedDifference])

  const hasSelection = selectedBank || selectedLedger
  const hasBothSelected = selectedBank && selectedLedger
  const selectionHasMatch =
    (selectedBank && selectedBank.match_id) || (selectedLedger && selectedLedger.match_id)

  const createMatch = (status) => {
    if (!activeSessionId) return
    if (!hasSelection) {
      toast.error('Select a bank transaction or ledger entry')
      return
    }
    if (selectionHasMatch) {
      toast.error('Selected items already matched')
      return
    }

    reconciliationService
      .createMatch(activeSessionId, {
        bank_transaction_id: selectedBank?.id ?? null,
        ledger_entry_id: selectedLedger?.id ?? null,
        status,
        amount_difference: Number.parseFloat(amountDifference || '0'),
        discrepancy_reason: status === 'discrepancy' ? discrepancyReason : null,
        notes: status === 'discrepancy' ? discrepancyNotes : null,
      })
      .then(() => {
        toast.success(status === 'matched' ? 'Match created' : 'Discrepancy recorded')
        setSelectedBankId(null)
        setSelectedLedgerId(null)
        setDiscrepancyReason('')
        setDiscrepancyNotes('')
        refreshBank()
        refreshLedger()
        refreshSummary()
      })
      .catch(() => toast.error('Unable to save match'))
  }

  const removeMatch = (matchId) => {
    if (!matchId) return
    if (!window.confirm('Remove this match?')) return
    reconciliationService
      .deleteMatch(matchId)
      .then(() => {
        toast.success('Match removed')
        refreshBank()
        refreshLedger()
        refreshSummary()
      })
      .catch(() => toast.error('Unable to remove match'))
  }

  const sessionOptions = sessions.map((session) => ({
    label: `${session.name} (${session.start_date} → ${session.end_date})`,
    value: session.id,
  }))

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
        <div>
          <h1 className="text-2xl font-semibold text-gray-900">Bank Reconciliation</h1>
          <p className="text-sm text-gray-500">Match bank statement transactions to ledger entries.</p>
        </div>
        <div className="flex flex-wrap gap-3">
          <Select
            label="Active session"
            value={activeSessionId}
            onChange={(event) => {
              const value = event.target.value
              setActiveSessionId(value ? Number(value) : '')
            }}
            options={sessionOptions}
            placeholder="Select session"
          />
          <Button
            variant="outline"
            onClick={() => updateSessionStatus('completed')}
            disabled={!activeSessionId || !activeSession}
          >
            Mark Completed
          </Button>
        </div>
      </div>

      <Card>
        <div className="grid gap-4 lg:grid-cols-4">
          <Input
            label="Session name"
            value={sessionForm.name}
            onChange={(event) => setSessionForm((prev) => ({ ...prev, name: event.target.value }))}
          />
          <Input
            label="Start date"
            type="date"
            value={sessionForm.start_date}
            onChange={(event) => setSessionForm((prev) => ({ ...prev, start_date: event.target.value }))}
          />
          <Input
            label="End date"
            type="date"
            value={sessionForm.end_date}
            onChange={(event) => setSessionForm((prev) => ({ ...prev, end_date: event.target.value }))}
          />
          <div className="flex items-end">
            <Button onClick={createSession}>Create session</Button>
          </div>
        </div>
      </Card>

      {loading && (
        <div className="flex justify-center py-6">
          <Loading size="lg" />
        </div>
      )}

      {sessionSummary && (
        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
          <Card>
            <p className="text-sm text-gray-500">Bank total</p>
            <p className="text-xl font-semibold text-gray-900">{formatCurrency(sessionSummary.bank_total)}</p>
            <p className="text-xs text-gray-400">{sessionSummary.bank_count} transactions</p>
          </Card>
          <Card>
            <p className="text-sm text-gray-500">Ledger total</p>
            <p className="text-xl font-semibold text-gray-900">{formatCurrency(sessionSummary.ledger_total)}</p>
            <p className="text-xs text-gray-400">{sessionSummary.ledger_count} entries</p>
          </Card>
          <Card>
            <p className="text-sm text-gray-500">Matched</p>
            <p className="text-xl font-semibold text-gray-900">{sessionSummary.matched_count}</p>
            <p className="text-xs text-gray-400">Matched items</p>
          </Card>
          <Card>
            <p className="text-sm text-gray-500">Discrepancies</p>
            <p className="text-xl font-semibold text-gray-900">{sessionSummary.discrepancy_count}</p>
            <p className="text-xs text-gray-400">Needs review</p>
          </Card>
        </div>
      )}

      <div className="grid gap-6 lg:grid-cols-2">
        <Card>
          <div className="flex items-center justify-between">
            <div>
              <h2 className="text-lg font-semibold text-gray-900">Bank statement</h2>
              <p className="text-sm text-gray-500">Imported or manually entered transactions.</p>
            </div>
          </div>

          <div className="mt-4 grid gap-3 md:grid-cols-4">
            <Input
              label="Date"
              type="date"
              value={bankForm.transaction_date}
              onChange={(event) => setBankForm((prev) => ({ ...prev, transaction_date: event.target.value }))}
            />
            <Input
              label="Description"
              value={bankForm.description}
              onChange={(event) => setBankForm((prev) => ({ ...prev, description: event.target.value }))}
            />
            <Input
              label="Reference"
              value={bankForm.reference}
              onChange={(event) => setBankForm((prev) => ({ ...prev, reference: event.target.value }))}
            />
            <Input
              label="Amount"
              type="number"
              value={bankForm.amount}
              onChange={(event) => setBankForm((prev) => ({ ...prev, amount: event.target.value }))}
            />
            <div className="md:col-span-4">
              <Button onClick={addBankTransaction} disabled={!activeSessionId}>
                Add bank transaction
              </Button>
            </div>
          </div>

          <div className="mt-6 space-y-3">
            {bankTransactions.length === 0 ? (
              <p className="text-sm text-gray-500">No bank transactions yet.</p>
            ) : (
              bankTransactions.map((transaction) => {
                const isSelected = transaction.id === selectedBankId
                return (
                  <button
                    key={transaction.id}
                    type="button"
                    className={`w-full rounded-lg border px-4 py-3 text-left transition ${isSelected ? 'border-primary-500 bg-primary-50' : 'border-gray-200 hover:border-primary-200'}`}
                    onClick={() => setSelectedBankId(isSelected ? null : transaction.id)}
                  >
                    <div className="flex items-start justify-between gap-3">
                      <div>
                        <p className="text-sm font-semibold text-gray-900">{transaction.description}</p>
                        <p className="text-xs text-gray-500">
                          {transaction.transaction_date} · {transaction.reference || 'No reference'}
                        </p>
                        {transaction.match_status && (
                          <span
                            className={`mt-2 inline-flex items-center rounded-full px-2 py-1 text-xs font-semibold ${matchBadgeStyles[transaction.match_status]}`}
                          >
                            {transaction.match_status}
                          </span>
                        )}
                      </div>
                      <div className="text-right">
                        <p className="text-sm font-semibold text-gray-900">{formatCurrency(transaction.amount)}</p>
                        {transaction.match_id && (
                          <Button
                            variant="outline"
                            size="sm"
                            onClick={(event) => {
                              event.stopPropagation()
                              removeMatch(transaction.match_id)
                            }}
                          >
                            Unmatch
                          </Button>
                        )}
                      </div>
                    </div>
                  </button>
                )
              })
            )}
          </div>
        </Card>

        <Card>
          <div className="flex items-center justify-between">
            <div>
              <h2 className="text-lg font-semibold text-gray-900">Ledger entries</h2>
              <p className="text-sm text-gray-500">Financial entries within the session range.</p>
            </div>
          </div>

          <div className="mt-4 flex gap-3">
            <Input
              label="Search ledger"
              value={ledgerSearch}
              onChange={(event) => setLedgerSearch(event.target.value)}
            />
            <div className="flex items-end">
              <Button variant="outline" onClick={refreshLedger} disabled={!activeSessionId}>
                Refresh
              </Button>
            </div>
          </div>

          <div className="mt-6 space-y-3">
            {ledgerEntries.length === 0 ? (
              <p className="text-sm text-gray-500">No ledger entries found.</p>
            ) : (
              ledgerEntries.map((entry) => {
                const isSelected = entry.id === selectedLedgerId
                return (
                  <button
                    key={entry.id}
                    type="button"
                    className={`w-full rounded-lg border px-4 py-3 text-left transition ${isSelected ? 'border-primary-500 bg-primary-50' : 'border-gray-200 hover:border-primary-200'}`}
                    onClick={() => setSelectedLedgerId(isSelected ? null : entry.id)}
                  >
                    <div className="flex items-start justify-between gap-3">
                      <div>
                        <p className="text-sm font-semibold text-gray-900">{entry.reference || entry.category}</p>
                        <p className="text-xs text-gray-500">
                          {entry.entry_date} · {entry.vendor || 'No vendor'}
                        </p>
                        {entry.match_status && (
                          <span
                            className={`mt-2 inline-flex items-center rounded-full px-2 py-1 text-xs font-semibold ${matchBadgeStyles[entry.match_status]}`}
                          >
                            {entry.match_status}
                          </span>
                        )}
                      </div>
                      <div className="text-right">
                        <p className="text-sm font-semibold text-gray-900">{formatCurrency(entry.amount)}</p>
                        {entry.match_id && (
                          <Button
                            variant="outline"
                            size="sm"
                            onClick={(event) => {
                              event.stopPropagation()
                              removeMatch(entry.match_id)
                            }}
                          >
                            Unmatch
                          </Button>
                        )}
                      </div>
                    </div>
                  </button>
                )
              })
            )}
          </div>
        </Card>
      </div>

      <Card>
        <h2 className="text-lg font-semibold text-gray-900">Match &amp; discrepancy handling</h2>
        <p className="text-sm text-gray-500">
          Select a bank transaction and ledger entry to match. Use discrepancies for missing or mismatched entries.
        </p>

        <div className="mt-4 grid gap-4 lg:grid-cols-3">
          <div className="rounded-lg border border-gray-200 p-4">
            <p className="text-sm font-semibold text-gray-700">Selected bank</p>
            {selectedBank ? (
              <div className="mt-2 text-sm text-gray-600">
                <p>{selectedBank.description}</p>
                <p className="text-xs text-gray-400">{selectedBank.transaction_date}</p>
                <p className="mt-2 font-semibold text-gray-900">{formatCurrency(selectedBank.amount)}</p>
              </div>
            ) : (
              <p className="mt-2 text-sm text-gray-400">No bank transaction selected.</p>
            )}
          </div>
          <div className="rounded-lg border border-gray-200 p-4">
            <p className="text-sm font-semibold text-gray-700">Selected ledger</p>
            {selectedLedger ? (
              <div className="mt-2 text-sm text-gray-600">
                <p>{selectedLedger.reference || selectedLedger.category}</p>
                <p className="text-xs text-gray-400">{selectedLedger.entry_date}</p>
                <p className="mt-2 font-semibold text-gray-900">{formatCurrency(selectedLedger.amount)}</p>
              </div>
            ) : (
              <p className="mt-2 text-sm text-gray-400">No ledger entry selected.</p>
            )}
          </div>
          <div className="rounded-lg border border-gray-200 p-4">
            <p className="text-sm font-semibold text-gray-700">Amount difference</p>
            <Input
              type="number"
              value={amountDifference}
              onChange={(event) => setAmountDifference(event.target.value)}
            />
            <p className="mt-2 text-xs text-gray-400">
              Positive means bank is higher; negative means ledger is higher.
            </p>
          </div>
        </div>

        <div className="mt-4 grid gap-4 lg:grid-cols-2">
          <Input
            label="Discrepancy reason"
            value={discrepancyReason}
            onChange={(event) => setDiscrepancyReason(event.target.value)}
          />
          <Textarea
            label="Discrepancy notes"
            value={discrepancyNotes}
            onChange={(event) => setDiscrepancyNotes(event.target.value)}
          />
        </div>

        <div className="mt-4 flex flex-wrap gap-3">
          <Button onClick={() => createMatch('matched')} disabled={!hasBothSelected || selectionHasMatch}>
            Match selected
          </Button>
          <Button
            variant="outline"
            onClick={() => createMatch('discrepancy')}
            disabled={!hasSelection || selectionHasMatch}
          >
            Record discrepancy
          </Button>
        </div>
      </Card>
    </div>
  )
}
