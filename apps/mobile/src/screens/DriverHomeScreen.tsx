import { useCallback, useEffect, useMemo, useState } from 'react'
import {
  ActivityIndicator,
  Linking,
  Platform,
  RefreshControl,
  ScrollView,
  StyleSheet,
  Text,
  TouchableOpacity,
  View,
} from 'react-native'

import dispatchService from '../services/dispatch.service'
import damageReportService from '../services/damageReport.service'
import { useAuthStore } from '../stores/authStore'
import workorderService from '../services/workorder.service'
import { DamageReportScreen } from './DamageReportScreen'

type DispatchOffer = {
  id: number
  job_reference?: string | number | null
  job_type?: string | null
  status?: string | null
  dropoff_latitude?: number | string | null
  dropoff_longitude?: number | string | null
}

type DispatchJob = DispatchOffer & {
  job?: {
    job_id?: number | null
    job_title?: string | null
    job_status?: string | null
    workorder_id?: number | null
    workorder_number?: string | null
  }
  hasDamageReport?: boolean
}

type DriverHomeScreenProps = {
  offlineAccess: boolean
}

type DamageReportContext = {
  workorderId: number
  jobId: number
  jobTitle: string
} | null

export function DriverHomeScreen({ offlineAccess }: DriverHomeScreenProps) {
  const user = useAuthStore((state) => state.user)
  const logout = useAuthStore((state) => state.logout)
  const [offers, setOffers] = useState<DispatchOffer[]>([])
  const [jobs, setJobs] = useState<DispatchJob[]>([])
  const [loading, setLoading] = useState(false)
  const [refreshing, setRefreshing] = useState(false)
  const [statusUpdates, setStatusUpdates] = useState<Record<string, boolean>>({})
  const [errorMessage, setErrorMessage] = useState<string | null>(null)
  const [damageReportContext, setDamageReportContext] = useState<DamageReportContext>(null)

  const statusSteps = useMemo(
    () => [
      { key: 'in_progress', label: 'En Route' },
      { key: 'arrived', label: 'Arrived' },
      { key: 'hooked', label: 'Hooked' },
      { key: 'completed', label: 'Dropped' },
    ],
    []
  )

  const statusIndex = useCallback((status?: string | null) => {
    if (!status) return -1
    return statusSteps.findIndex((step) => step.key === status)
  }, [statusSteps])

  const loadDispatchData = useCallback(async () => {
    setLoading(true)
    setErrorMessage(null)
    try {
      const [offersResponse, jobsResponse] = await Promise.all([
        dispatchService.getJobOffers({ status: 'pending' }),
        dispatchService.getJobs({ status: 'accepted' }),
      ])

      // Check for existing damage reports on each job
      const jobsWithReports = await Promise.all(
        (jobsResponse.data ?? []).map(async (job: DispatchJob) => {
          if (job.job?.workorder_id && job.job?.job_id) {
            try {
              const hasDamageReport = await damageReportService.hasDamageReport(
                job.job.workorder_id,
                job.job.job_id
              )
              return { ...job, hasDamageReport }
            } catch {
              return { ...job, hasDamageReport: false }
            }
          }
          return { ...job, hasDamageReport: false }
        })
      )

      setOffers(offersResponse.data ?? [])
      setJobs(jobsWithReports)
    } catch (error) {
      console.warn('Failed to load dispatch data', error)
      setErrorMessage('Unable to load dispatch jobs. Pull to refresh or try again shortly.')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    loadDispatchData()
  }, [loadDispatchData])

  const handleRefresh = useCallback(async () => {
    setRefreshing(true)
    try {
      await loadDispatchData()
    } finally {
      setRefreshing(false)
    }
  }, [loadDispatchData])

  const handleAccept = async (offerId: number) => {
    try {
      await dispatchService.acceptOffer(offerId)
      await loadDispatchData()
    } catch (error) {
      console.warn('Offer accept failed', error)
      setErrorMessage('Unable to accept the offer. Please try again.')
    }
  }

  const handleDecline = async (offerId: number) => {
    try {
      await dispatchService.declineOffer(offerId)
      await loadDispatchData()
    } catch (error) {
      console.warn('Offer decline failed', error)
      setErrorMessage('Unable to decline the offer. Please try again.')
    }
  }

  const handleStatusUpdate = async (
    job: DispatchJob,
    status: string,
    jobKey: string
  ) => {
    if (!job.job?.workorder_id || !job.job.job_id) {
      setErrorMessage('Job details are missing workorder data.')
      return
    }
    setStatusUpdates((prev) => ({ ...prev, [jobKey]: true }))
    try {
      await workorderService.updateJobStatus(job.job.workorder_id, job.job.job_id, status)
      await loadDispatchData()
    } catch (error) {
      console.warn('Status update failed', error)
      setErrorMessage('Unable to update job status. Please try again.')
    } finally {
      setStatusUpdates((prev) => ({ ...prev, [jobKey]: false }))
    }
  }

  const handleNavigate = async (job: DispatchJob) => {
    const lat = Number(job.dropoff_latitude)
    const lng = Number(job.dropoff_longitude)
    if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
      setErrorMessage('No navigation coordinates are available for this job.')
      return
    }
    const url =
      Platform.OS === 'ios'
        ? `http://maps.apple.com/?daddr=${lat},${lng}`
        : `https://www.google.com/maps/dir/?api=1&destination=${lat},${lng}`
    try {
      const supported = await Linking.canOpenURL(url)
      if (supported) {
        await Linking.openURL(url)
      } else {
        setErrorMessage('No maps application is available to open directions.')
      }
    } catch (error) {
      console.warn('Navigation launch failed', error)
      setErrorMessage('Unable to open navigation. Please try again.')
    }
  }

  const handleOpenDamageReport = useCallback((job: DispatchJob) => {
    if (!job.job?.workorder_id || !job.job?.job_id) {
      setErrorMessage('Job details are missing. Cannot open damage report.')
      return
    }
    setDamageReportContext({
      workorderId: job.job.workorder_id,
      jobId: job.job.job_id,
      jobTitle: job.job?.job_title ?? `Job ${job.job_reference ?? job.id}`,
    })
  }, [])

  const handleDamageReportBack = useCallback(() => {
    setDamageReportContext(null)
  }, [])

  const handleDamageReportComplete = useCallback(() => {
    setDamageReportContext(null)
    loadDispatchData()
  }, [loadDispatchData])

  // Show Damage Report Screen if context is set
  if (damageReportContext) {
    return (
      <DamageReportScreen
        workorderId={damageReportContext.workorderId}
        jobId={damageReportContext.jobId}
        jobTitle={damageReportContext.jobTitle}
        onBack={handleDamageReportBack}
        onComplete={handleDamageReportComplete}
      />
    )
  }

  return (
    <View style={styles.container}>
      <ScrollView
        contentContainerStyle={styles.scrollContent}
        refreshControl={
          <RefreshControl
            refreshing={refreshing}
            onRefresh={handleRefresh}
            tintColor="#38bdf8"
            colors={['#38bdf8']}
          />
        }
      >
        {offlineAccess ? (
          <View style={styles.offlineBanner}>
            <Text style={styles.offlineText}>Offline mode enabled - changes will sync when online</Text>
          </View>
        ) : null}
        <Text style={styles.title}>Driver Interface</Text>
        <Text style={styles.subtitle}>Welcome{user?.name ? `, ${user.name}` : ''}</Text>
        <Text style={styles.helper}>Manage dispatch jobs and driver workflows.</Text>

        <View style={styles.actionsRow}>
          <TouchableOpacity style={styles.secondaryButton} onPress={loadDispatchData}>
            <Text style={styles.secondaryButtonLabel}>Refresh</Text>
          </TouchableOpacity>
          <TouchableOpacity style={styles.button} onPress={logout}>
            <Text style={styles.buttonLabel}>Sign out</Text>
          </TouchableOpacity>
        </View>

        {loading && !refreshing ? (
          <View style={styles.loadingRow}>
            <ActivityIndicator color="#38bdf8" />
            <Text style={styles.loadingText}>Syncing dispatch jobs...</Text>
          </View>
        ) : null}
        {errorMessage ? <Text style={styles.errorText}>{errorMessage}</Text> : null}

        <View style={styles.section}>
          <Text style={styles.sectionTitle}>Job Offers</Text>
          {offers.length === 0 ? (
            <Text style={styles.sectionEmpty}>No pending offers right now.</Text>
          ) : (
            offers.map((offer) => (
              <View key={offer.id} style={styles.card}>
                <Text style={styles.cardTitle}>Offer #{offer.id}</Text>
                <Text style={styles.cardSubtitle}>
                  Job: {offer.job_reference ?? 'N/A'} - {offer.job_type ?? 'workorder'}
                </Text>
                <View style={styles.cardActions}>
                  <TouchableOpacity
                    style={styles.acceptButton}
                    onPress={() => handleAccept(offer.id)}
                  >
                    <Text style={styles.acceptButtonLabel}>Accept</Text>
                  </TouchableOpacity>
                  <TouchableOpacity
                    style={styles.declineButton}
                    onPress={() => handleDecline(offer.id)}
                  >
                    <Text style={styles.declineButtonLabel}>Decline</Text>
                  </TouchableOpacity>
                </View>
              </View>
            ))
          )}
        </View>

        <View style={styles.section}>
          <Text style={styles.sectionTitle}>Active Dispatch Jobs</Text>
          {jobs.length === 0 ? (
            <Text style={styles.sectionEmpty}>No accepted jobs yet.</Text>
          ) : (
            jobs.map((job) => {
              const key = `${job.id}-${job.job?.job_id ?? 'unknown'}`
              const currentStatus = job.job?.job_status ?? job.status ?? 'pending'
              const currentIndex = statusIndex(currentStatus)
              const isUpdating = statusUpdates[key] ?? false
              return (
                <View key={key} style={styles.card}>
                  <View style={styles.cardHeader}>
                    <Text style={styles.cardTitle}>
                      {job.job?.job_title ?? `Job ${job.job_reference ?? job.id}`}
                    </Text>
                    {job.hasDamageReport && (
                      <View style={styles.damageReportBadge}>
                        <Text style={styles.damageReportBadgeText}>Report Filed</Text>
                      </View>
                    )}
                  </View>
                  <Text style={styles.cardSubtitle}>
                    Workorder {job.job?.workorder_number ?? job.job?.workorder_id ?? 'N/A'}
                  </Text>
                  <Text style={styles.cardMeta}>Status: {currentStatus.replace('_', ' ')}</Text>
                  <View style={styles.statusRow}>
                    {statusSteps.map((step, index) => {
                      const isActive = index <= currentIndex && currentIndex >= 0
                      const canAdvance = index === currentIndex + 1 || (currentIndex === -1 && index === 0)
                      return (
                        <TouchableOpacity
                          key={step.key}
                          style={[
                            styles.statusButton,
                            isActive ? styles.statusButtonActive : null,
                            canAdvance ? styles.statusButtonNext : null,
                          ]}
                          disabled={!canAdvance || isUpdating}
                          onPress={() => handleStatusUpdate(job, step.key, key)}
                        >
                          <Text style={styles.statusButtonLabel}>{step.label}</Text>
                        </TouchableOpacity>
                      )
                    })}
                  </View>
                  <View style={styles.cardActions}>
                    <TouchableOpacity
                      style={styles.secondaryButton}
                      onPress={() => handleNavigate(job)}
                    >
                      <Text style={styles.secondaryButtonLabel}>Navigate</Text>
                    </TouchableOpacity>
                    <TouchableOpacity
                      style={[
                        styles.damageReportButton,
                        job.hasDamageReport && styles.damageReportButtonFiled,
                      ]}
                      onPress={() => handleOpenDamageReport(job)}
                    >
                      <Text style={styles.damageReportButtonLabel}>
                        {job.hasDamageReport ? 'View Report' : 'Damage Report'}
                      </Text>
                    </TouchableOpacity>
                    {isUpdating ? (
                      <View style={styles.loadingRow}>
                        <ActivityIndicator color="#38bdf8" size="small" />
                        <Text style={styles.loadingText}>Updating...</Text>
                      </View>
                    ) : null}
                  </View>
                </View>
              )
            })
          )}
        </View>
      </ScrollView>
    </View>
  )
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: '#0f172a',
  },
  scrollContent: {
    padding: 24,
    paddingBottom: 32,
  },
  title: {
    fontSize: 24,
    fontWeight: '700',
    color: '#f8fafc',
    marginBottom: 8,
  },
  subtitle: {
    fontSize: 16,
    color: '#e2e8f0',
    marginBottom: 12,
  },
  helper: {
    fontSize: 14,
    color: '#94a3b8',
    marginBottom: 24,
  },
  button: {
    backgroundColor: '#f8fafc',
    paddingVertical: 12,
    paddingHorizontal: 16,
    borderRadius: 10,
    alignItems: 'center',
  },
  buttonLabel: {
    color: '#0f172a',
    fontWeight: '700',
  },
  actionsRow: {
    flexDirection: 'row',
    gap: 12,
    marginBottom: 16,
  },
  secondaryButton: {
    backgroundColor: '#1e293b',
    paddingVertical: 12,
    paddingHorizontal: 16,
    borderRadius: 10,
    alignItems: 'center',
  },
  secondaryButtonLabel: {
    color: '#e2e8f0',
    fontWeight: '600',
  },
  offlineBanner: {
    backgroundColor: '#1e3a8a',
    padding: 10,
    borderRadius: 8,
    marginBottom: 16,
  },
  offlineText: {
    color: '#e0f2fe',
    textAlign: 'center',
    fontSize: 12,
  },
  section: {
    marginTop: 16,
  },
  sectionTitle: {
    fontSize: 16,
    fontWeight: '700',
    color: '#f8fafc',
    marginBottom: 8,
  },
  sectionEmpty: {
    color: '#94a3b8',
    fontSize: 13,
  },
  card: {
    backgroundColor: '#111827',
    borderRadius: 12,
    padding: 16,
    marginBottom: 12,
    borderWidth: 1,
    borderColor: '#1f2937',
  },
  cardHeader: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    marginBottom: 4,
  },
  cardTitle: {
    fontSize: 15,
    fontWeight: '700',
    color: '#f8fafc',
    flex: 1,
  },
  cardSubtitle: {
    fontSize: 13,
    color: '#cbd5f5',
    marginTop: 4,
  },
  cardMeta: {
    fontSize: 12,
    color: '#94a3b8',
    marginTop: 6,
  },
  cardActions: {
    flexDirection: 'row',
    alignItems: 'center',
    flexWrap: 'wrap',
    gap: 10,
    marginTop: 12,
  },
  acceptButton: {
    backgroundColor: '#22c55e',
    paddingVertical: 10,
    paddingHorizontal: 16,
    borderRadius: 10,
  },
  acceptButtonLabel: {
    color: '#041f0f',
    fontWeight: '700',
  },
  declineButton: {
    backgroundColor: '#ef4444',
    paddingVertical: 10,
    paddingHorizontal: 16,
    borderRadius: 10,
  },
  declineButtonLabel: {
    color: '#fff',
    fontWeight: '700',
  },
  damageReportButton: {
    backgroundColor: '#854d0e',
    paddingVertical: 10,
    paddingHorizontal: 16,
    borderRadius: 10,
  },
  damageReportButtonFiled: {
    backgroundColor: '#166534',
  },
  damageReportButtonLabel: {
    color: '#fef3c7',
    fontWeight: '600',
    fontSize: 13,
  },
  damageReportBadge: {
    backgroundColor: '#166534',
    paddingVertical: 4,
    paddingHorizontal: 8,
    borderRadius: 4,
  },
  damageReportBadgeText: {
    color: '#bbf7d0',
    fontSize: 10,
    fontWeight: '600',
  },
  statusRow: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: 8,
    marginTop: 12,
  },
  statusButton: {
    borderWidth: 1,
    borderColor: '#334155',
    borderRadius: 999,
    paddingVertical: 6,
    paddingHorizontal: 12,
  },
  statusButtonActive: {
    backgroundColor: '#0f766e',
    borderColor: '#14b8a6',
  },
  statusButtonNext: {
    backgroundColor: '#1d4ed8',
    borderColor: '#3b82f6',
  },
  statusButtonLabel: {
    color: '#e2e8f0',
    fontSize: 12,
    fontWeight: '600',
  },
  loadingRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
    marginTop: 8,
  },
  loadingText: {
    color: '#94a3b8',
    fontSize: 12,
  },
  errorText: {
    color: '#fca5a5',
    fontSize: 12,
    marginBottom: 8,
  },
})
