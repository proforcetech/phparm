import { useCallback, useEffect, useMemo, useState } from 'react'
import {
  ActivityIndicator,
  RefreshControl,
  ScrollView,
  StyleSheet,
  Text,
  TouchableOpacity,
  View,
} from 'react-native'

import { useAuthStore } from '../stores/authStore'
import { useTimeTrackingStore } from '../stores/timeTrackingStore'

type TechnicianHomeScreenProps = {
  offlineAccess: boolean
}

type TabKey = 'clock' | 'jobs' | 'inspections'

export function TechnicianHomeScreen({ offlineAccess }: TechnicianHomeScreenProps) {
  const user = useAuthStore((state) => state.user)
  const logout = useAuthStore((state) => state.logout)

  const currentEntry = useTimeTrackingStore((state) => state.currentEntry)
  const assignedJobs = useTimeTrackingStore((state) => state.assignedJobs)
  const todayMinutes = useTimeTrackingStore((state) => state.todayMinutes)
  const weekMinutes = useTimeTrackingStore((state) => state.weekMinutes)
  const loading = useTimeTrackingStore((state) => state.loading)
  const clockingIn = useTimeTrackingStore((state) => state.clockingIn)
  const clockingOut = useTimeTrackingStore((state) => state.clockingOut)
  const error = useTimeTrackingStore((state) => state.error)
  const loadPortalData = useTimeTrackingStore((state) => state.loadPortalData)
  const clockIn = useTimeTrackingStore((state) => state.clockIn)
  const clockOut = useTimeTrackingStore((state) => state.clockOut)
  const clearError = useTimeTrackingStore((state) => state.clearError)

  const [activeTab, setActiveTab] = useState<TabKey>('clock')
  const [elapsedSeconds, setElapsedSeconds] = useState(0)
  const [refreshing, setRefreshing] = useState(false)

  const isClockedIn = useMemo(
    () => currentEntry !== null && currentEntry.ended_at === null,
    [currentEntry]
  )

  // Load portal data on mount
  useEffect(() => {
    loadPortalData().catch((err) => console.warn('Failed to load portal data:', err))
  }, [loadPortalData])

  // Update elapsed time every second when clocked in
  useEffect(() => {
    if (!isClockedIn || !currentEntry?.started_at) {
      setElapsedSeconds(0)
      return
    }

    const startTime = new Date(currentEntry.started_at).getTime()
    const updateElapsed = () => {
      const now = Date.now()
      setElapsedSeconds(Math.floor((now - startTime) / 1000))
    }

    updateElapsed()
    const interval = setInterval(updateElapsed, 1000)

    return () => clearInterval(interval)
  }, [isClockedIn, currentEntry?.started_at])

  const formatDuration = useCallback((totalSeconds: number): string => {
    const hours = Math.floor(totalSeconds / 3600)
    const minutes = Math.floor((totalSeconds % 3600) / 60)
    const seconds = totalSeconds % 60
    return `${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`
  }, [])

  const formatMinutes = useCallback((mins: number): string => {
    const hours = Math.floor(mins / 60)
    const minutes = mins % 60
    if (hours > 0) {
      return `${hours}h ${minutes}m`
    }
    return `${minutes}m`
  }, [])

  const handleClockIn = useCallback(
    async (jobId?: number) => {
      clearError()
      const success = await clockIn(jobId ? { estimate_job_id: jobId } : undefined)
      if (success) {
        loadPortalData().catch(() => {})
      }
    },
    [clearError, clockIn, loadPortalData]
  )

  const handleClockOut = useCallback(async () => {
    clearError()
    const success = await clockOut()
    if (success) {
      loadPortalData().catch(() => {})
    }
  }, [clearError, clockOut, loadPortalData])

  const handleRefresh = useCallback(async () => {
    setRefreshing(true)
    try {
      await loadPortalData()
    } finally {
      setRefreshing(false)
    }
  }, [loadPortalData])

  const renderClockTab = () => (
    <View style={styles.tabContent}>
      {/* Current Status Card */}
      <View style={[styles.statusCard, isClockedIn ? styles.statusCardActive : null]}>
        <Text style={styles.statusLabel}>
          {isClockedIn ? 'Currently Working' : 'Not Clocked In'}
        </Text>

        {isClockedIn && (
          <>
            <Text style={styles.elapsedTime}>{formatDuration(elapsedSeconds)}</Text>
            {currentEntry?.task_name && (
              <Text style={styles.taskName}>{currentEntry.task_name}</Text>
            )}
          </>
        )}

        {/* Main Clock Button */}
        <TouchableOpacity
          style={[
            styles.clockButton,
            isClockedIn ? styles.clockOutButton : styles.clockInButton,
            (clockingIn || clockingOut) && styles.clockButtonDisabled,
          ]}
          onPress={isClockedIn ? handleClockOut : () => handleClockIn()}
          disabled={clockingIn || clockingOut}
          activeOpacity={0.7}
        >
          {clockingIn || clockingOut ? (
            <ActivityIndicator color="#0f172a" size="small" />
          ) : (
            <Text style={styles.clockButtonText}>
              {isClockedIn ? 'Clock Out' : 'Clock In'}
            </Text>
          )}
        </TouchableOpacity>
      </View>

      {/* Today's Summary */}
      <View style={styles.summaryRow}>
        <View style={styles.summaryCard}>
          <Text style={styles.summaryValue}>{formatMinutes(todayMinutes)}</Text>
          <Text style={styles.summaryLabel}>Today</Text>
        </View>
        <View style={styles.summaryCard}>
          <Text style={styles.summaryValue}>{formatMinutes(weekMinutes)}</Text>
          <Text style={styles.summaryLabel}>This Week</Text>
        </View>
      </View>

      {/* Quick Clock-In to Job */}
      {!isClockedIn && assignedJobs.length > 0 && (
        <View style={styles.section}>
          <Text style={styles.sectionTitle}>Clock In to Job</Text>
          {assignedJobs.slice(0, 5).map((job) => (
            <TouchableOpacity
              key={job.id}
              style={styles.jobCard}
              onPress={() => handleClockIn(job.id)}
              disabled={clockingIn}
              activeOpacity={0.7}
            >
              <View style={styles.jobCardContent}>
                <Text style={styles.jobTitle}>{job.title || `Job #${job.id}`}</Text>
                <Text style={styles.jobSubtitle}>
                  {job.estimate_number} {job.customer_name ? `- ${job.customer_name}` : ''}
                </Text>
              </View>
              <View style={styles.jobClockButton}>
                <Text style={styles.jobClockButtonText}>Clock In</Text>
              </View>
            </TouchableOpacity>
          ))}
        </View>
      )}
    </View>
  )

  const renderJobsTab = () => (
    <View style={styles.tabContent}>
      <Text style={styles.sectionTitle}>Assigned Jobs</Text>
      {assignedJobs.length === 0 ? (
        <Text style={styles.emptyText}>No jobs assigned.</Text>
      ) : (
        assignedJobs.map((job) => (
          <View key={job.id} style={styles.jobCard}>
            <View style={styles.jobCardContent}>
              <Text style={styles.jobTitle}>{job.title || `Job #${job.id}`}</Text>
              <Text style={styles.jobSubtitle}>
                {job.estimate_number} {job.customer_name ? `- ${job.customer_name}` : ''}
              </Text>
              {job.vehicle_vin && (
                <Text style={styles.jobMeta}>VIN: {job.vehicle_vin}</Text>
              )}
              {job.customer_status && (
                <View style={[styles.statusBadge, getStatusStyle(job.customer_status)]}>
                  <Text style={styles.statusBadgeText}>{job.customer_status}</Text>
                </View>
              )}
            </View>
          </View>
        ))
      )}
    </View>
  )

  const renderInspectionsTab = () => (
    <View style={styles.tabContent}>
      <Text style={styles.sectionTitle}>Inspections</Text>
      <Text style={styles.emptyText}>
        Inspections feature coming soon.
      </Text>
    </View>
  )

  return (
    <View style={styles.container}>
      {/* Header */}
      <View style={styles.header}>
        <View>
          <Text style={styles.title}>Technician</Text>
          <Text style={styles.subtitle}>Welcome{user?.name ? `, ${user.name}` : ''}</Text>
        </View>
        <TouchableOpacity style={styles.logoutButton} onPress={logout}>
          <Text style={styles.logoutButtonText}>Sign Out</Text>
        </TouchableOpacity>
      </View>

      {/* Offline Banner */}
      {offlineAccess && (
        <View style={styles.offlineBanner}>
          <Text style={styles.offlineText}>Offline mode - changes will sync when online</Text>
        </View>
      )}

      {/* Error Banner */}
      {error && (
        <TouchableOpacity style={styles.errorBanner} onPress={clearError}>
          <Text style={styles.errorText}>{error}</Text>
          <Text style={styles.errorDismiss}>Tap to dismiss</Text>
        </TouchableOpacity>
      )}

      {/* Tab Bar */}
      <View style={styles.tabBar}>
        {(['clock', 'jobs', 'inspections'] as TabKey[]).map((tab) => (
          <TouchableOpacity
            key={tab}
            style={[styles.tab, activeTab === tab && styles.tabActive]}
            onPress={() => setActiveTab(tab)}
          >
            <Text style={[styles.tabText, activeTab === tab && styles.tabTextActive]}>
              {tab === 'clock' ? 'Time Clock' : tab === 'jobs' ? 'Jobs' : 'Inspections'}
            </Text>
          </TouchableOpacity>
        ))}
      </View>

      {/* Content */}
      <ScrollView
        style={styles.scrollView}
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
        {loading && !refreshing && (
          <View style={styles.loadingOverlay}>
            <ActivityIndicator color="#38bdf8" />
          </View>
        )}

        {activeTab === 'clock' && renderClockTab()}
        {activeTab === 'jobs' && renderJobsTab()}
        {activeTab === 'inspections' && renderInspectionsTab()}
      </ScrollView>
    </View>
  )
}

const getStatusStyle = (status: string) => {
  const statusLower = status.toLowerCase()
  if (statusLower.includes('approved') || statusLower.includes('complete')) {
    return styles.statusBadgeGreen
  }
  if (statusLower.includes('pending') || statusLower.includes('waiting')) {
    return styles.statusBadgeYellow
  }
  if (statusLower.includes('declined') || statusLower.includes('cancelled')) {
    return styles.statusBadgeRed
  }
  return styles.statusBadgeGray
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: '#0f172a',
  },
  header: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    padding: 20,
    paddingTop: 50,
    backgroundColor: '#1e293b',
  },
  title: {
    fontSize: 24,
    fontWeight: '700',
    color: '#f8fafc',
  },
  subtitle: {
    fontSize: 14,
    color: '#94a3b8',
    marginTop: 2,
  },
  logoutButton: {
    backgroundColor: '#334155',
    paddingVertical: 8,
    paddingHorizontal: 16,
    borderRadius: 8,
  },
  logoutButtonText: {
    color: '#e2e8f0',
    fontWeight: '600',
    fontSize: 14,
  },
  offlineBanner: {
    backgroundColor: '#1e3a8a',
    padding: 10,
  },
  offlineText: {
    color: '#e0f2fe',
    textAlign: 'center',
    fontSize: 12,
  },
  errorBanner: {
    backgroundColor: '#7f1d1d',
    padding: 12,
  },
  errorText: {
    color: '#fecaca',
    textAlign: 'center',
    fontSize: 13,
  },
  errorDismiss: {
    color: '#fca5a5',
    textAlign: 'center',
    fontSize: 11,
    marginTop: 4,
  },
  tabBar: {
    flexDirection: 'row',
    backgroundColor: '#1e293b',
    borderBottomWidth: 1,
    borderBottomColor: '#334155',
  },
  tab: {
    flex: 1,
    paddingVertical: 14,
    alignItems: 'center',
  },
  tabActive: {
    borderBottomWidth: 2,
    borderBottomColor: '#38bdf8',
  },
  tabText: {
    color: '#94a3b8',
    fontSize: 14,
    fontWeight: '600',
  },
  tabTextActive: {
    color: '#38bdf8',
  },
  scrollView: {
    flex: 1,
  },
  scrollContent: {
    padding: 16,
    paddingBottom: 32,
  },
  loadingOverlay: {
    padding: 20,
    alignItems: 'center',
  },
  tabContent: {
    flex: 1,
  },
  statusCard: {
    backgroundColor: '#1e293b',
    borderRadius: 16,
    padding: 24,
    alignItems: 'center',
    marginBottom: 16,
    borderWidth: 2,
    borderColor: '#334155',
  },
  statusCardActive: {
    borderColor: '#22c55e',
    backgroundColor: '#14532d',
  },
  statusLabel: {
    color: '#94a3b8',
    fontSize: 14,
    fontWeight: '600',
    marginBottom: 8,
  },
  elapsedTime: {
    color: '#f8fafc',
    fontSize: 48,
    fontWeight: '700',
    fontVariant: ['tabular-nums'],
    marginBottom: 8,
  },
  taskName: {
    color: '#a3e635',
    fontSize: 14,
    fontWeight: '500',
    marginBottom: 16,
  },
  clockButton: {
    paddingVertical: 16,
    paddingHorizontal: 48,
    borderRadius: 12,
    minWidth: 180,
    alignItems: 'center',
    marginTop: 8,
  },
  clockInButton: {
    backgroundColor: '#22c55e',
  },
  clockOutButton: {
    backgroundColor: '#ef4444',
  },
  clockButtonDisabled: {
    opacity: 0.6,
  },
  clockButtonText: {
    color: '#0f172a',
    fontSize: 18,
    fontWeight: '700',
  },
  summaryRow: {
    flexDirection: 'row',
    gap: 12,
    marginBottom: 24,
  },
  summaryCard: {
    flex: 1,
    backgroundColor: '#1e293b',
    borderRadius: 12,
    padding: 16,
    alignItems: 'center',
  },
  summaryValue: {
    color: '#f8fafc',
    fontSize: 24,
    fontWeight: '700',
  },
  summaryLabel: {
    color: '#94a3b8',
    fontSize: 12,
    marginTop: 4,
  },
  section: {
    marginTop: 8,
  },
  sectionTitle: {
    color: '#f8fafc',
    fontSize: 16,
    fontWeight: '700',
    marginBottom: 12,
  },
  emptyText: {
    color: '#64748b',
    fontSize: 14,
    textAlign: 'center',
    padding: 20,
  },
  jobCard: {
    backgroundColor: '#1e293b',
    borderRadius: 12,
    padding: 16,
    marginBottom: 10,
    flexDirection: 'row',
    alignItems: 'center',
    borderWidth: 1,
    borderColor: '#334155',
  },
  jobCardContent: {
    flex: 1,
  },
  jobTitle: {
    color: '#f8fafc',
    fontSize: 15,
    fontWeight: '600',
  },
  jobSubtitle: {
    color: '#94a3b8',
    fontSize: 13,
    marginTop: 2,
  },
  jobMeta: {
    color: '#64748b',
    fontSize: 12,
    marginTop: 4,
  },
  jobClockButton: {
    backgroundColor: '#22c55e',
    paddingVertical: 10,
    paddingHorizontal: 14,
    borderRadius: 8,
  },
  jobClockButtonText: {
    color: '#0f172a',
    fontSize: 13,
    fontWeight: '700',
  },
  statusBadge: {
    alignSelf: 'flex-start',
    paddingVertical: 4,
    paddingHorizontal: 8,
    borderRadius: 4,
    marginTop: 8,
  },
  statusBadgeGreen: {
    backgroundColor: '#14532d',
  },
  statusBadgeYellow: {
    backgroundColor: '#713f12',
  },
  statusBadgeRed: {
    backgroundColor: '#7f1d1d',
  },
  statusBadgeGray: {
    backgroundColor: '#374151',
  },
  statusBadgeText: {
    color: '#e2e8f0',
    fontSize: 11,
    fontWeight: '600',
  },
})
