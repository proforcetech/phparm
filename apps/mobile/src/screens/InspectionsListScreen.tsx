import { useCallback, useEffect, useState } from 'react'
import {
  ActivityIndicator,
  RefreshControl,
  ScrollView,
  StyleSheet,
  Text,
  TouchableOpacity,
  View,
} from 'react-native'

import { useInspectionsStore } from '../stores/inspectionsStore'
import type { Inspection, InspectionStatus } from '../services/inspection.service'

type InspectionsListScreenProps = {
  onSelectInspection: (inspection: Inspection) => void
  onCreateInspection?: () => void
  onBack?: () => void
  workorderId?: number | string
}

type StatusFilterKey = InspectionStatus | 'all'

const STATUS_FILTERS: { key: StatusFilterKey; label: string }[] = [
  { key: 'all', label: 'All' },
  { key: 'pending', label: 'Pending' },
  { key: 'in_progress', label: 'In Progress' },
  { key: 'completed', label: 'Completed' },
]

export function InspectionsListScreen({
  onSelectInspection,
  onCreateInspection,
  onBack,
  workorderId,
}: InspectionsListScreenProps) {
  const inspections = useInspectionsStore((state) => state.inspections)
  const loading = useInspectionsStore((state) => state.loading)
  const error = useInspectionsStore((state) => state.error)
  const statusFilter = useInspectionsStore((state) => state.statusFilter)
  const loadInspections = useInspectionsStore((state) => state.loadInspections)
  const setStatusFilter = useInspectionsStore((state) => state.setStatusFilter)
  const filteredInspections = useInspectionsStore((state) => state.filteredInspections)

  const [refreshing, setRefreshing] = useState(false)

  // Load inspections on mount
  useEffect(() => {
    const params = workorderId ? { workorder_id: Number(workorderId) } : {}
    loadInspections(params).catch((err) =>
      console.warn('Failed to load inspections:', err)
    )
  }, [loadInspections, workorderId])

  const handleRefresh = useCallback(async () => {
    setRefreshing(true)
    try {
      const params = workorderId ? { workorder_id: Number(workorderId) } : {}
      await loadInspections(params)
    } finally {
      setRefreshing(false)
    }
  }, [loadInspections, workorderId])

  const handleFilterChange = (filter: StatusFilterKey) => {
    setStatusFilter(filter)
  }

  const displayInspections = filteredInspections()

  const getStatusColor = (status: InspectionStatus) => {
    switch (status) {
      case 'pending':
        return '#eab308'
      case 'in_progress':
        return '#3b82f6'
      case 'completed':
        return '#22c55e'
      case 'cancelled':
        return '#ef4444'
      default:
        return '#64748b'
    }
  }

  const formatVehicle = (vehicle: Inspection['vehicle']) => {
    if (!vehicle) return 'No vehicle'
    const parts = [vehicle.year, vehicle.make, vehicle.model].filter(Boolean)
    return parts.length > 0 ? parts.join(' ') : 'Unknown vehicle'
  }

  const formatDate = (dateStr: string | undefined) => {
    if (!dateStr) return ''
    try {
      const date = new Date(dateStr)
      return date.toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
      })
    } catch {
      return ''
    }
  }

  const getProgress = (inspection: Inspection) => {
    const total = inspection.items_total || inspection.items?.length || 0
    const completed = inspection.items_completed ||
      inspection.items?.filter((item) => item.status !== 'pending').length || 0
    const percentage = total > 0 ? Math.round((completed / total) * 100) : 0
    return { completed, total, percentage }
  }

  return (
    <View style={styles.container}>
      {/* Header */}
      <View style={styles.header}>
        <View style={styles.headerRow}>
          {onBack && (
            <TouchableOpacity style={styles.backButton} onPress={onBack}>
              <Text style={styles.backButtonText}>{'<'} Back</Text>
            </TouchableOpacity>
          )}
          <View style={styles.headerTitleContainer}>
            <Text style={styles.title}>Inspections</Text>
            <Text style={styles.subtitle}>
              {displayInspections.length}{' '}
              {displayInspections.length === 1 ? 'inspection' : 'inspections'}
            </Text>
          </View>
          {onCreateInspection && (
            <TouchableOpacity style={styles.createButton} onPress={onCreateInspection}>
              <Text style={styles.createButtonText}>+ New</Text>
            </TouchableOpacity>
          )}
        </View>

        {/* Status Filter Pills */}
        <ScrollView
          horizontal
          showsHorizontalScrollIndicator={false}
          contentContainerStyle={styles.filterContainer}
        >
          {STATUS_FILTERS.map((filter) => (
            <TouchableOpacity
              key={filter.key}
              style={[
                styles.filterPill,
                statusFilter === filter.key && styles.filterPillActive,
              ]}
              onPress={() => handleFilterChange(filter.key)}
            >
              <Text
                style={[
                  styles.filterPillText,
                  statusFilter === filter.key && styles.filterPillTextActive,
                ]}
              >
                {filter.label}
              </Text>
            </TouchableOpacity>
          ))}
        </ScrollView>
      </View>

      {/* Error Banner */}
      {error && (
        <View style={styles.errorBanner}>
          <Text style={styles.errorText}>{error}</Text>
        </View>
      )}

      {/* Inspections List */}
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
          <View style={styles.loadingContainer}>
            <ActivityIndicator color="#38bdf8" size="large" />
            <Text style={styles.loadingText}>Loading inspections...</Text>
          </View>
        )}

        {!loading && displayInspections.length === 0 && (
          <View style={styles.emptyContainer}>
            <Text style={styles.emptyTitle}>No Inspections</Text>
            <Text style={styles.emptyText}>
              {statusFilter !== 'all'
                ? 'Try adjusting your filter'
                : 'No inspections have been created yet'}
            </Text>
            {onCreateInspection && (
              <TouchableOpacity
                style={styles.emptyCreateButton}
                onPress={onCreateInspection}
              >
                <Text style={styles.emptyCreateButtonText}>Create Inspection</Text>
              </TouchableOpacity>
            )}
          </View>
        )}

        {displayInspections.map((inspection) => {
          const progress = getProgress(inspection)

          return (
            <TouchableOpacity
              key={inspection.id}
              style={styles.inspectionCard}
              onPress={() => onSelectInspection(inspection)}
              activeOpacity={0.7}
            >
              <View style={styles.cardHeader}>
                <View style={styles.cardHeaderLeft}>
                  <Text style={styles.inspectionId}>Inspection #{inspection.id}</Text>
                </View>
                <View
                  style={[
                    styles.statusBadge,
                    { backgroundColor: getStatusColor(inspection.status) + '30' },
                  ]}
                >
                  <View
                    style={[
                      styles.statusDot,
                      { backgroundColor: getStatusColor(inspection.status) },
                    ]}
                  />
                  <Text
                    style={[styles.statusText, { color: getStatusColor(inspection.status) }]}
                  >
                    {inspection.status.replace('_', ' ')}
                  </Text>
                </View>
              </View>

              <View style={styles.cardBody}>
                {/* Vehicle Info */}
                <View style={styles.infoRow}>
                  <Text style={styles.infoLabel}>Vehicle</Text>
                  <Text style={styles.infoValue}>{formatVehicle(inspection.vehicle)}</Text>
                </View>

                {/* Customer Info */}
                {inspection.customer && (
                  <View style={styles.infoRow}>
                    <Text style={styles.infoLabel}>Customer</Text>
                    <Text style={styles.infoValue}>{inspection.customer.name}</Text>
                  </View>
                )}

                {/* Mileage */}
                {inspection.mileage != null && (
                  <View style={styles.infoRow}>
                    <Text style={styles.infoLabel}>Mileage</Text>
                    <Text style={styles.infoValue}>
                      {inspection.mileage.toLocaleString()} mi
                    </Text>
                  </View>
                )}

                {/* Progress Bar */}
                {progress.total > 0 && (
                  <View style={styles.progressContainer}>
                    <View style={styles.progressHeader}>
                      <Text style={styles.progressLabel}>
                        {progress.completed} of {progress.total} items
                      </Text>
                      <Text style={styles.progressPercentage}>{progress.percentage}%</Text>
                    </View>
                    <View style={styles.progressBarBg}>
                      <View
                        style={[
                          styles.progressBarFill,
                          { width: `${progress.percentage}%` },
                          progress.percentage === 100 && styles.progressBarComplete,
                        ]}
                      />
                    </View>
                  </View>
                )}

                {/* Date */}
                {inspection.created_at && (
                  <Text style={styles.dateText}>{formatDate(inspection.created_at)}</Text>
                )}
              </View>

              <View style={styles.cardFooter}>
                <Text style={styles.viewDetails}>
                  {inspection.status === 'completed' ? 'View Report' : 'Continue'}
                </Text>
                <Text style={styles.chevron}>{'>'}</Text>
              </View>
            </TouchableOpacity>
          )
        })}
      </ScrollView>
    </View>
  )
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: '#0f172a',
  },
  header: {
    backgroundColor: '#1e293b',
    paddingTop: 50,
    paddingHorizontal: 16,
    paddingBottom: 12,
    borderBottomWidth: 1,
    borderBottomColor: '#334155',
  },
  headerRow: {
    flexDirection: 'row',
    alignItems: 'center',
    marginBottom: 12,
  },
  backButton: {
    paddingVertical: 8,
    paddingRight: 16,
  },
  backButtonText: {
    color: '#38bdf8',
    fontSize: 16,
    fontWeight: '600',
  },
  headerTitleContainer: {
    flex: 1,
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
  createButton: {
    backgroundColor: '#22c55e',
    paddingVertical: 10,
    paddingHorizontal: 16,
    borderRadius: 8,
  },
  createButtonText: {
    color: '#0f172a',
    fontSize: 14,
    fontWeight: '700',
  },
  filterContainer: {
    flexDirection: 'row',
    gap: 8,
    paddingBottom: 4,
  },
  filterPill: {
    paddingHorizontal: 14,
    paddingVertical: 8,
    borderRadius: 20,
    backgroundColor: '#334155',
  },
  filterPillActive: {
    backgroundColor: '#38bdf8',
  },
  filterPillText: {
    color: '#94a3b8',
    fontSize: 13,
    fontWeight: '600',
  },
  filterPillTextActive: {
    color: '#0f172a',
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
  scrollView: {
    flex: 1,
  },
  scrollContent: {
    padding: 16,
    paddingBottom: 32,
  },
  loadingContainer: {
    alignItems: 'center',
    paddingVertical: 40,
  },
  loadingText: {
    color: '#94a3b8',
    marginTop: 12,
    fontSize: 14,
  },
  emptyContainer: {
    alignItems: 'center',
    paddingVertical: 60,
  },
  emptyTitle: {
    color: '#f8fafc',
    fontSize: 18,
    fontWeight: '700',
    marginBottom: 8,
  },
  emptyText: {
    color: '#64748b',
    fontSize: 14,
    textAlign: 'center',
    marginBottom: 20,
  },
  emptyCreateButton: {
    backgroundColor: '#22c55e',
    paddingVertical: 12,
    paddingHorizontal: 24,
    borderRadius: 8,
  },
  emptyCreateButtonText: {
    color: '#0f172a',
    fontSize: 15,
    fontWeight: '700',
  },
  inspectionCard: {
    backgroundColor: '#1e293b',
    borderRadius: 12,
    marginBottom: 12,
    borderWidth: 1,
    borderColor: '#334155',
    overflow: 'hidden',
  },
  cardHeader: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    padding: 14,
    borderBottomWidth: 1,
    borderBottomColor: '#334155',
  },
  cardHeaderLeft: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 10,
  },
  inspectionId: {
    color: '#f8fafc',
    fontSize: 16,
    fontWeight: '700',
  },
  statusBadge: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingHorizontal: 10,
    paddingVertical: 5,
    borderRadius: 12,
    gap: 6,
  },
  statusDot: {
    width: 7,
    height: 7,
    borderRadius: 4,
  },
  statusText: {
    fontSize: 12,
    fontWeight: '600',
    textTransform: 'capitalize',
  },
  cardBody: {
    padding: 14,
    gap: 8,
  },
  infoRow: {
    flexDirection: 'row',
    justifyContent: 'space-between',
  },
  infoLabel: {
    color: '#64748b',
    fontSize: 13,
  },
  infoValue: {
    color: '#e2e8f0',
    fontSize: 13,
    fontWeight: '500',
    textAlign: 'right',
    flex: 1,
    marginLeft: 16,
  },
  progressContainer: {
    marginTop: 8,
  },
  progressHeader: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    marginBottom: 6,
  },
  progressLabel: {
    color: '#94a3b8',
    fontSize: 12,
  },
  progressPercentage: {
    color: '#38bdf8',
    fontSize: 12,
    fontWeight: '700',
  },
  progressBarBg: {
    height: 6,
    backgroundColor: '#334155',
    borderRadius: 3,
    overflow: 'hidden',
  },
  progressBarFill: {
    height: '100%',
    backgroundColor: '#38bdf8',
    borderRadius: 3,
  },
  progressBarComplete: {
    backgroundColor: '#22c55e',
  },
  dateText: {
    color: '#64748b',
    fontSize: 12,
    marginTop: 4,
  },
  cardFooter: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    paddingHorizontal: 14,
    paddingVertical: 10,
    backgroundColor: '#0f172a',
  },
  viewDetails: {
    color: '#38bdf8',
    fontSize: 14,
    fontWeight: '600',
  },
  chevron: {
    color: '#38bdf8',
    fontSize: 16,
    fontWeight: '600',
  },
})
