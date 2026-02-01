import { useCallback, useEffect, useState } from 'react'
import {
  ActivityIndicator,
  Linking,
  RefreshControl,
  ScrollView,
  StyleSheet,
  Text,
  TouchableOpacity,
  View,
} from 'react-native'

import { useWorkordersStore, WORKORDER_STATUSES, type Workorder } from '../stores/workordersStore'

type WorkOrderDetailScreenProps = {
  workorderId: number | string
  onBack: () => void
  onStartInspection?: (workorderId: number | string) => void
}

export function WorkOrderDetailScreen({
  workorderId,
  onBack,
  onStartInspection,
}: WorkOrderDetailScreenProps) {
  const selectedWorkorder = useWorkordersStore((state) => state.selectedWorkorder)
  const loading = useWorkordersStore((state) => state.loading)
  const error = useWorkordersStore((state) => state.error)
  const loadWorkorderDetails = useWorkordersStore((state) => state.loadWorkorderDetails)
  const clearSelectedWorkorder = useWorkordersStore((state) => state.clearSelectedWorkorder)

  const [refreshing, setRefreshing] = useState(false)

  useEffect(() => {
    loadWorkorderDetails(workorderId).catch((err) =>
      console.warn('Failed to load workorder details:', err)
    )

    return () => {
      clearSelectedWorkorder()
    }
  }, [workorderId, loadWorkorderDetails, clearSelectedWorkorder])

  const handleRefresh = useCallback(async () => {
    setRefreshing(true)
    try {
      await loadWorkorderDetails(workorderId)
    } finally {
      setRefreshing(false)
    }
  }, [workorderId, loadWorkorderDetails])

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
    if (!vehicle) return 'No vehicle assigned'
    const parts = [vehicle.year, vehicle.make, vehicle.model].filter(Boolean)
    return parts.length > 0 ? parts.join(' ') : 'Unknown vehicle'
  }

  const formatDate = (dateStr: string | null | undefined) => {
    if (!dateStr) return '-'
    try {
      const date = new Date(dateStr)
      return date.toLocaleDateString('en-US', {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
        year: 'numeric',
      })
    } catch {
      return '-'
    }
  }

  const formatCurrency = (amount: number | null | undefined) => {
    if (amount === null || amount === undefined) return '-'
    return new Intl.NumberFormat('en-US', {
      style: 'currency',
      currency: 'USD',
    }).format(amount)
  }

  const handleCallCustomer = () => {
    const phone = selectedWorkorder?.customer?.phone
    if (phone) {
      Linking.openURL(`tel:${phone.replace(/\D/g, '')}`)
    }
  }

  const handleEmailCustomer = () => {
    const email = selectedWorkorder?.customer?.email
    if (email) {
      Linking.openURL(`mailto:${email}`)
    }
  }

  const wo = selectedWorkorder

  return (
    <View style={styles.container}>
      {/* Header */}
      <View style={styles.header}>
        <TouchableOpacity style={styles.backButton} onPress={onBack}>
          <Text style={styles.backButtonText}>{'<'} Back</Text>
        </TouchableOpacity>
        <View style={styles.headerTitle}>
          <Text style={styles.title}>Work Order</Text>
          {wo && <Text style={styles.workorderNumber}>#{wo.number}</Text>}
        </View>
        <View style={styles.headerSpacer} />
      </View>

      {/* Error Banner */}
      {error && (
        <View style={styles.errorBanner}>
          <Text style={styles.errorText}>{error}</Text>
        </View>
      )}

      {/* Loading */}
      {loading && !refreshing && !wo && (
        <View style={styles.loadingContainer}>
          <ActivityIndicator color="#38bdf8" size="large" />
          <Text style={styles.loadingText}>Loading work order...</Text>
        </View>
      )}

      {/* Content */}
      {wo && (
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
          {/* Status Card */}
          <View style={styles.statusCard}>
            <View style={styles.statusRow}>
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

            {wo.due_date && (
              <View style={styles.dueDate}>
                <Text style={styles.dueDateLabel}>Due Date:</Text>
                <Text style={styles.dueDateValue}>{formatDate(wo.due_date)}</Text>
              </View>
            )}
          </View>

          {/* Vehicle Section */}
          <View style={styles.section}>
            <Text style={styles.sectionTitle}>Vehicle</Text>
            <View style={styles.card}>
              <Text style={styles.vehicleName}>{formatVehicle(wo.vehicle)}</Text>
              {wo.vehicle?.license_plate && (
                <View style={styles.detailRow}>
                  <Text style={styles.detailLabel}>License Plate</Text>
                  <Text style={styles.detailValue}>{wo.vehicle.license_plate}</Text>
                </View>
              )}
              {wo.vehicle?.vin && (
                <View style={styles.detailRow}>
                  <Text style={styles.detailLabel}>VIN</Text>
                  <Text style={styles.detailValue}>{wo.vehicle.vin}</Text>
                </View>
              )}
            </View>
          </View>

          {/* Customer Section */}
          {wo.customer && (
            <View style={styles.section}>
              <Text style={styles.sectionTitle}>Customer</Text>
              <View style={styles.card}>
                <Text style={styles.customerName}>
                  {wo.customer.name ||
                    [wo.customer.first_name, wo.customer.last_name].filter(Boolean).join(' ') ||
                    'Unknown Customer'}
                </Text>

                <View style={styles.contactButtons}>
                  {wo.customer.phone && (
                    <TouchableOpacity
                      style={styles.contactButton}
                      onPress={handleCallCustomer}
                    >
                      <Text style={styles.contactButtonText}>Call</Text>
                    </TouchableOpacity>
                  )}
                  {wo.customer.email && (
                    <TouchableOpacity
                      style={styles.contactButton}
                      onPress={handleEmailCustomer}
                    >
                      <Text style={styles.contactButtonText}>Email</Text>
                    </TouchableOpacity>
                  )}
                </View>

                {wo.customer.phone && (
                  <View style={styles.detailRow}>
                    <Text style={styles.detailLabel}>Phone</Text>
                    <Text style={styles.detailValue}>{wo.customer.phone}</Text>
                  </View>
                )}
                {wo.customer.email && (
                  <View style={styles.detailRow}>
                    <Text style={styles.detailLabel}>Email</Text>
                    <Text style={styles.detailValue}>{wo.customer.email}</Text>
                  </View>
                )}
              </View>
            </View>
          )}

          {/* Jobs Section */}
          {wo.jobs && wo.jobs.length > 0 && (
            <View style={styles.section}>
              <Text style={styles.sectionTitle}>
                Jobs ({wo.jobs.length})
              </Text>
              {wo.jobs.map((job, index) => (
                <View key={job.id} style={styles.jobCard}>
                  <View style={styles.jobHeader}>
                    <Text style={styles.jobTitle}>{job.title}</Text>
                    <View
                      style={[
                        styles.jobStatusBadge,
                        { backgroundColor: getStatusColor(job.status) + '30' },
                      ]}
                    >
                      <Text
                        style={[styles.jobStatusText, { color: getStatusColor(job.status) }]}
                      >
                        {job.status.replace('_', ' ')}
                      </Text>
                    </View>
                  </View>

                  {job.description && (
                    <Text style={styles.jobDescription}>{job.description}</Text>
                  )}

                  <View style={styles.jobDetails}>
                    {job.labor_hours != null && (
                      <View style={styles.jobDetailItem}>
                        <Text style={styles.jobDetailLabel}>Hours</Text>
                        <Text style={styles.jobDetailValue}>{job.labor_hours}h</Text>
                      </View>
                    )}
                    {job.technician_name && (
                      <View style={styles.jobDetailItem}>
                        <Text style={styles.jobDetailLabel}>Tech</Text>
                        <Text style={styles.jobDetailValue}>{job.technician_name}</Text>
                      </View>
                    )}
                  </View>
                </View>
              ))}
            </View>
          )}

          {/* Notes Section */}
          {(wo.customer_notes || wo.internal_notes) && (
            <View style={styles.section}>
              <Text style={styles.sectionTitle}>Notes</Text>
              <View style={styles.card}>
                {wo.customer_notes && (
                  <View style={styles.noteBlock}>
                    <Text style={styles.noteLabel}>Customer Notes</Text>
                    <Text style={styles.noteText}>{wo.customer_notes}</Text>
                  </View>
                )}
                {wo.internal_notes && (
                  <View style={styles.noteBlock}>
                    <Text style={styles.noteLabel}>Internal Notes</Text>
                    <Text style={styles.noteText}>{wo.internal_notes}</Text>
                  </View>
                )}
              </View>
            </View>
          )}

          {/* Totals Section */}
          {(wo.subtotal != null || wo.tax != null || wo.grand_total != null) && (
            <View style={styles.section}>
              <Text style={styles.sectionTitle}>Totals</Text>
              <View style={styles.card}>
                {wo.subtotal != null && (
                  <View style={styles.totalRow}>
                    <Text style={styles.totalLabel}>Subtotal</Text>
                    <Text style={styles.totalValue}>{formatCurrency(wo.subtotal)}</Text>
                  </View>
                )}
                {wo.tax != null && (
                  <View style={styles.totalRow}>
                    <Text style={styles.totalLabel}>Tax</Text>
                    <Text style={styles.totalValue}>{formatCurrency(wo.tax)}</Text>
                  </View>
                )}
                {wo.grand_total != null && (
                  <View style={[styles.totalRow, styles.grandTotalRow]}>
                    <Text style={styles.grandTotalLabel}>Grand Total</Text>
                    <Text style={styles.grandTotalValue}>{formatCurrency(wo.grand_total)}</Text>
                  </View>
                )}
              </View>
            </View>
          )}

          {/* Action Buttons */}
          {onStartInspection && wo.status !== WORKORDER_STATUSES.COMPLETED && (
            <View style={styles.actionsSection}>
              <TouchableOpacity
                style={styles.inspectionButton}
                onPress={() => onStartInspection(wo.id)}
              >
                <Text style={styles.inspectionButtonText}>Start Inspection</Text>
              </TouchableOpacity>
            </View>
          )}

          {/* Dates Footer */}
          <View style={styles.datesFooter}>
            {wo.created_at && (
              <Text style={styles.dateText}>Created: {formatDate(wo.created_at)}</Text>
            )}
            {wo.updated_at && (
              <Text style={styles.dateText}>Updated: {formatDate(wo.updated_at)}</Text>
            )}
          </View>
        </ScrollView>
      )}
    </View>
  )
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: '#0f172a',
  },
  header: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    backgroundColor: '#1e293b',
    paddingTop: 50,
    paddingHorizontal: 16,
    paddingBottom: 16,
    borderBottomWidth: 1,
    borderBottomColor: '#334155',
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
  headerTitle: {
    flex: 1,
    alignItems: 'center',
  },
  title: {
    fontSize: 16,
    fontWeight: '600',
    color: '#94a3b8',
  },
  workorderNumber: {
    fontSize: 20,
    fontWeight: '700',
    color: '#f8fafc',
  },
  headerSpacer: {
    width: 60,
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
  loadingContainer: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    paddingVertical: 40,
  },
  loadingText: {
    color: '#94a3b8',
    marginTop: 12,
    fontSize: 14,
  },
  scrollView: {
    flex: 1,
  },
  scrollContent: {
    padding: 16,
    paddingBottom: 32,
  },
  statusCard: {
    backgroundColor: '#1e293b',
    borderRadius: 12,
    padding: 16,
    marginBottom: 16,
    borderWidth: 1,
    borderColor: '#334155',
  },
  statusRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 12,
  },
  statusBadge: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingHorizontal: 12,
    paddingVertical: 6,
    borderRadius: 16,
    gap: 8,
  },
  statusDot: {
    width: 8,
    height: 8,
    borderRadius: 4,
  },
  statusText: {
    fontSize: 14,
    fontWeight: '600',
    textTransform: 'capitalize',
  },
  priorityBadge: {
    paddingHorizontal: 10,
    paddingVertical: 4,
    borderRadius: 4,
  },
  priorityText: {
    fontSize: 11,
    fontWeight: '700',
  },
  dueDate: {
    flexDirection: 'row',
    alignItems: 'center',
    marginTop: 12,
    gap: 8,
  },
  dueDateLabel: {
    color: '#94a3b8',
    fontSize: 14,
  },
  dueDateValue: {
    color: '#f8fafc',
    fontSize: 14,
    fontWeight: '600',
  },
  section: {
    marginBottom: 16,
  },
  sectionTitle: {
    color: '#f8fafc',
    fontSize: 16,
    fontWeight: '700',
    marginBottom: 10,
  },
  card: {
    backgroundColor: '#1e293b',
    borderRadius: 12,
    padding: 16,
    borderWidth: 1,
    borderColor: '#334155',
  },
  vehicleName: {
    color: '#f8fafc',
    fontSize: 17,
    fontWeight: '600',
    marginBottom: 12,
  },
  customerName: {
    color: '#f8fafc',
    fontSize: 17,
    fontWeight: '600',
    marginBottom: 8,
  },
  contactButtons: {
    flexDirection: 'row',
    gap: 10,
    marginBottom: 12,
  },
  contactButton: {
    backgroundColor: '#38bdf8',
    paddingVertical: 8,
    paddingHorizontal: 20,
    borderRadius: 8,
  },
  contactButtonText: {
    color: '#0f172a',
    fontSize: 14,
    fontWeight: '700',
  },
  detailRow: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    paddingVertical: 6,
    borderTopWidth: 1,
    borderTopColor: '#334155',
  },
  detailLabel: {
    color: '#64748b',
    fontSize: 14,
  },
  detailValue: {
    color: '#e2e8f0',
    fontSize: 14,
    fontWeight: '500',
  },
  jobCard: {
    backgroundColor: '#1e293b',
    borderRadius: 12,
    padding: 14,
    marginBottom: 10,
    borderWidth: 1,
    borderColor: '#334155',
  },
  jobHeader: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'flex-start',
    marginBottom: 8,
  },
  jobTitle: {
    color: '#f8fafc',
    fontSize: 15,
    fontWeight: '600',
    flex: 1,
    marginRight: 10,
  },
  jobStatusBadge: {
    paddingHorizontal: 8,
    paddingVertical: 3,
    borderRadius: 8,
  },
  jobStatusText: {
    fontSize: 11,
    fontWeight: '600',
    textTransform: 'capitalize',
  },
  jobDescription: {
    color: '#94a3b8',
    fontSize: 13,
    marginBottom: 10,
    lineHeight: 18,
  },
  jobDetails: {
    flexDirection: 'row',
    gap: 20,
  },
  jobDetailItem: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
  },
  jobDetailLabel: {
    color: '#64748b',
    fontSize: 12,
  },
  jobDetailValue: {
    color: '#e2e8f0',
    fontSize: 12,
    fontWeight: '600',
  },
  noteBlock: {
    marginBottom: 12,
  },
  noteLabel: {
    color: '#94a3b8',
    fontSize: 12,
    fontWeight: '600',
    marginBottom: 4,
  },
  noteText: {
    color: '#e2e8f0',
    fontSize: 14,
    lineHeight: 20,
  },
  totalRow: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    paddingVertical: 6,
  },
  grandTotalRow: {
    borderTopWidth: 1,
    borderTopColor: '#334155',
    marginTop: 6,
    paddingTop: 10,
  },
  totalLabel: {
    color: '#94a3b8',
    fontSize: 14,
  },
  totalValue: {
    color: '#e2e8f0',
    fontSize: 14,
    fontWeight: '500',
  },
  grandTotalLabel: {
    color: '#f8fafc',
    fontSize: 15,
    fontWeight: '600',
  },
  grandTotalValue: {
    color: '#22c55e',
    fontSize: 17,
    fontWeight: '700',
  },
  actionsSection: {
    marginTop: 8,
    marginBottom: 16,
  },
  inspectionButton: {
    backgroundColor: '#22c55e',
    paddingVertical: 16,
    borderRadius: 12,
    alignItems: 'center',
  },
  inspectionButtonText: {
    color: '#0f172a',
    fontSize: 16,
    fontWeight: '700',
  },
  datesFooter: {
    alignItems: 'center',
    paddingTop: 16,
    gap: 4,
  },
  dateText: {
    color: '#64748b',
    fontSize: 12,
  },
})
