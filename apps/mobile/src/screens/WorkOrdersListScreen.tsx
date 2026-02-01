import { useCallback, useEffect, useState } from 'react'
import {
  ActivityIndicator,
  RefreshControl,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
  TouchableOpacity,
  View,
} from 'react-native'

import { useWorkordersStore, WORKORDER_STATUSES, type Workorder } from '../stores/workordersStore'

type WorkOrdersListScreenProps = {
  onSelectWorkorder: (workorder: Workorder) => void
  onBack?: () => void
}

type StatusFilterKey = 'all' | 'pending' | 'in_progress' | 'on_hold' | 'completed'

const STATUS_FILTERS: { key: StatusFilterKey; label: string }[] = [
  { key: 'all', label: 'All' },
  { key: 'pending', label: 'Pending' },
  { key: 'in_progress', label: 'In Progress' },
  { key: 'on_hold', label: 'On Hold' },
  { key: 'completed', label: 'Completed' },
]

export function WorkOrdersListScreen({ onSelectWorkorder, onBack }: WorkOrdersListScreenProps) {
  const workorders = useWorkordersStore((state) => state.workorders)
  const loading = useWorkordersStore((state) => state.loading)
  const error = useWorkordersStore((state) => state.error)
  const loadWorkorders = useWorkordersStore((state) => state.loadWorkorders)

  const [statusFilter, setStatusFilter] = useState<StatusFilterKey>('all')
  const [searchTerm, setSearchTerm] = useState('')
  const [refreshing, setRefreshing] = useState(false)

  // Load workorders on mount
  useEffect(() => {
    loadWorkorders().catch((err) => console.warn('Failed to load workorders:', err))
  }, [loadWorkorders])

  const handleRefresh = useCallback(async () => {
    setRefreshing(true)
    try {
      await loadWorkorders()
    } finally {
      setRefreshing(false)
    }
  }, [loadWorkorders])

  // Filter workorders by status and search term
  const filteredWorkorders = workorders.filter((wo) => {
    // Status filter
    if (statusFilter !== 'all' && wo.status !== statusFilter) {
      return false
    }

    // Search filter (search by number, customer name, vehicle)
    if (searchTerm.trim()) {
      const term = searchTerm.toLowerCase()
      const matchesNumber = wo.number?.toLowerCase().includes(term)
      const matchesCustomer = wo.customer?.name?.toLowerCase().includes(term)
      const matchesVehicle =
        wo.vehicle?.make?.toLowerCase().includes(term) ||
        wo.vehicle?.model?.toLowerCase().includes(term) ||
        wo.vehicle?.license_plate?.toLowerCase().includes(term)

      if (!matchesNumber && !matchesCustomer && !matchesVehicle) {
        return false
      }
    }

    return true
  })

  const getStatusColor = (status: string) => {
    switch (status) {
      case WORKORDER_STATUSES.PENDING:
        return '#eab308'
      case WORKORDER_STATUSES.IN_PROGRESS:
        return '#3b82f6'
      case WORKORDER_STATUSES.ON_HOLD:
        return '#f97316'
      case WORKORDER_STATUSES.COMPLETED:
        return '#22c55e'
      case WORKORDER_STATUSES.CANCELLED:
        return '#ef4444'
      default:
        return '#64748b'
    }
  }

  const getPriorityColor = (priority: string) => {
    switch (priority) {
      case 'urgent':
        return '#ef4444'
      case 'high':
        return '#f97316'
      case 'normal':
        return '#64748b'
      case 'low':
        return '#94a3b8'
      default:
        return '#64748b'
    }
  }

  const formatVehicle = (vehicle: Workorder['vehicle']) => {
    if (!vehicle) return 'No vehicle'
    const parts = [vehicle.year, vehicle.make, vehicle.model].filter(Boolean)
    return parts.length > 0 ? parts.join(' ') : 'Unknown vehicle'
  }

  const formatDate = (dateStr: string | null | undefined) => {
    if (!dateStr) return ''
    try {
      const date = new Date(dateStr)
      return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' })
    } catch {
      return ''
    }
  }

  return (
    <View style={styles.container}>
      {/* Header */}
      <View style={styles.header}>
        <View style={styles.headerRow}>
          {onBack && (
            <TouchableOpacity style={styles.backButton} onPress={onBack}>
              <Text style={styles.backButtonText}>Back</Text>
            </TouchableOpacity>
          )}
          <View style={styles.headerTitleContainer}>
            <Text style={styles.title}>Work Orders</Text>
            <Text style={styles.subtitle}>
              {filteredWorkorders.length} {filteredWorkorders.length === 1 ? 'order' : 'orders'}
            </Text>
          </View>
        </View>

        {/* Search */}
        <TextInput
          style={styles.searchInput}
          placeholder="Search by WO#, customer, vehicle..."
          placeholderTextColor="#64748b"
          value={searchTerm}
          onChangeText={setSearchTerm}
          autoCapitalize="none"
          autoCorrect={false}
        />

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
              onPress={() => setStatusFilter(filter.key)}
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

      {/* Work Orders List */}
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
            <Text style={styles.loadingText}>Loading work orders...</Text>
          </View>
        )}

        {!loading && filteredWorkorders.length === 0 && (
          <View style={styles.emptyContainer}>
            <Text style={styles.emptyTitle}>No Work Orders</Text>
            <Text style={styles.emptyText}>
              {searchTerm || statusFilter !== 'all'
                ? 'Try adjusting your filters'
                : 'No work orders have been assigned yet'}
            </Text>
          </View>
        )}

        {filteredWorkorders.map((wo) => (
          <TouchableOpacity
            key={wo.id}
            style={styles.workorderCard}
            onPress={() => onSelectWorkorder(wo)}
            activeOpacity={0.7}
          >
            <View style={styles.cardHeader}>
              <View style={styles.cardHeaderLeft}>
                <Text style={styles.workorderNumber}>#{wo.number}</Text>
                {wo.priority && wo.priority !== 'normal' && (
                  <View
                    style={[
                      styles.priorityBadge,
                      { backgroundColor: getPriorityColor(wo.priority) + '30' },
                    ]}
                  >
                    <Text
                      style={[styles.priorityText, { color: getPriorityColor(wo.priority) }]}
                    >
                      {wo.priority.toUpperCase()}
                    </Text>
                  </View>
                )}
              </View>
              <View
                style={[
                  styles.statusBadge,
                  { backgroundColor: getStatusColor(wo.status) + '30' },
                ]}
              >
                <View
                  style={[styles.statusDot, { backgroundColor: getStatusColor(wo.status) }]}
                />
                <Text style={[styles.statusText, { color: getStatusColor(wo.status) }]}>
                  {wo.status.replace('_', ' ')}
                </Text>
              </View>
            </View>

            <View style={styles.cardBody}>
              {/* Customer Info */}
              {wo.customer && (
                <View style={styles.infoRow}>
                  <Text style={styles.infoLabel}>Customer</Text>
                  <Text style={styles.infoValue}>
                    {wo.customer.name ||
                      [wo.customer.first_name, wo.customer.last_name].filter(Boolean).join(' ') ||
                      'Unknown'}
                  </Text>
                </View>
              )}

              {/* Vehicle Info */}
              <View style={styles.infoRow}>
                <Text style={styles.infoLabel}>Vehicle</Text>
                <Text style={styles.infoValue}>{formatVehicle(wo.vehicle)}</Text>
              </View>

              {/* Due Date */}
              {wo.due_date && (
                <View style={styles.infoRow}>
                  <Text style={styles.infoLabel}>Due</Text>
                  <Text style={styles.infoValue}>{formatDate(wo.due_date)}</Text>
                </View>
              )}

              {/* Jobs Count */}
              {wo.jobs && wo.jobs.length > 0 && (
                <View style={styles.jobsInfo}>
                  <Text style={styles.jobsCount}>
                    {wo.jobs.length} {wo.jobs.length === 1 ? 'job' : 'jobs'}
                  </Text>
                </View>
              )}
            </View>

            <View style={styles.cardFooter}>
              <Text style={styles.viewDetails}>View Details</Text>
              <Text style={styles.chevron}>{'>'}</Text>
            </View>
          </TouchableOpacity>
        ))}
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
  searchInput: {
    backgroundColor: '#0f172a',
    borderRadius: 10,
    paddingHorizontal: 14,
    paddingVertical: 12,
    color: '#f8fafc',
    fontSize: 15,
    marginBottom: 12,
    borderWidth: 1,
    borderColor: '#334155',
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
  },
  workorderCard: {
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
  workorderNumber: {
    color: '#f8fafc',
    fontSize: 17,
    fontWeight: '700',
  },
  priorityBadge: {
    paddingHorizontal: 8,
    paddingVertical: 3,
    borderRadius: 4,
  },
  priorityText: {
    fontSize: 10,
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
  jobsInfo: {
    marginTop: 4,
  },
  jobsCount: {
    color: '#38bdf8',
    fontSize: 13,
    fontWeight: '600',
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
