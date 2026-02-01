import { useCallback, useEffect, useMemo, useState } from 'react'
import {
  ActivityIndicator,
  Alert,
  Image,
  Modal,
  RefreshControl,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
  TouchableOpacity,
  View,
} from 'react-native'
import * as ImagePicker from 'expo-image-picker'

import {
  useInspectionsStore,
  startAutoSave,
  stopAutoSave,
} from '../stores/inspectionsStore'
import type {
  Inspection,
  InspectionItem,
  InspectionItemStatus,
  InspectionCategory,
} from '../services/inspection.service'

type InspectionFormScreenProps = {
  inspectionId: number | string
  onBack: () => void
  onComplete?: () => void
}

const CATEGORY_LABELS: Record<InspectionCategory, string> = {
  exterior: 'Exterior',
  interior: 'Interior',
  under_hood: 'Under Hood',
  under_vehicle: 'Under Vehicle',
}

const CATEGORY_ORDER: InspectionCategory[] = [
  'exterior',
  'interior',
  'under_hood',
  'under_vehicle',
]

const STATUS_OPTIONS: { key: InspectionItemStatus; label: string; color: string }[] = [
  { key: 'pass', label: 'Pass', color: '#22c55e' },
  { key: 'fail', label: 'Fail', color: '#ef4444' },
  { key: 'na', label: 'N/A', color: '#64748b' },
]

export function InspectionFormScreen({
  inspectionId,
  onBack,
  onComplete,
}: InspectionFormScreenProps) {
  const currentInspection = useInspectionsStore((state) => state.currentInspection)
  const loading = useInspectionsStore((state) => state.loading)
  const error = useInspectionsStore((state) => state.error)
  const loadInspection = useInspectionsStore((state) => state.loadInspection)
  const updateItemStatus = useInspectionsStore((state) => state.updateItemStatus)
  const updateItem = useInspectionsStore((state) => state.updateItem)
  const addItemPhoto = useInspectionsStore((state) => state.addItemPhoto)
  const completeInspection = useInspectionsStore((state) => state.completeInspection)
  const saveDraft = useInspectionsStore((state) => state.saveDraft)
  const getProgress = useInspectionsStore((state) => state.getProgress)
  const setError = useInspectionsStore((state) => state.setError)

  const [refreshing, setRefreshing] = useState(false)
  const [expandedCategory, setExpandedCategory] = useState<InspectionCategory | null>(null)
  const [notesModal, setNotesModal] = useState<{
    visible: boolean
    item: InspectionItem | null
  }>({ visible: false, item: null })
  const [itemNotes, setItemNotes] = useState('')
  const [photoModal, setPhotoModal] = useState<{
    visible: boolean
    url: string | null
  }>({ visible: false, url: null })
  const [completing, setCompleting] = useState(false)

  // Load inspection on mount
  useEffect(() => {
    loadInspection(inspectionId).catch((err) =>
      console.warn('Failed to load inspection:', err)
    )
    startAutoSave()

    return () => {
      stopAutoSave()
      saveDraft()
    }
  }, [inspectionId, loadInspection, saveDraft])

  // Expand first category with items
  useEffect(() => {
    if (currentInspection?.items?.length && !expandedCategory) {
      const firstCategoryWithItems = CATEGORY_ORDER.find((cat) =>
        currentInspection.items.some((item) => item.category === cat)
      )
      if (firstCategoryWithItems) {
        setExpandedCategory(firstCategoryWithItems)
      }
    }
  }, [currentInspection, expandedCategory])

  const handleRefresh = useCallback(async () => {
    setRefreshing(true)
    try {
      await loadInspection(inspectionId)
    } finally {
      setRefreshing(false)
    }
  }, [inspectionId, loadInspection])

  // Group items by category
  const itemsByCategory = useMemo(() => {
    if (!currentInspection?.items) return {}

    return currentInspection.items.reduce(
      (acc, item) => {
        const category = item.category || 'exterior'
        if (!acc[category]) {
          acc[category] = []
        }
        acc[category].push(item)
        return acc
      },
      {} as Record<InspectionCategory, InspectionItem[]>
    )
  }, [currentInspection])

  const handleStatusChange = async (item: InspectionItem, status: InspectionItemStatus) => {
    if (!currentInspection) return

    try {
      await updateItemStatus(currentInspection.id, item.id, status)
    } catch (err) {
      console.warn('Failed to update status:', err)
    }
  }

  const handleOpenNotes = (item: InspectionItem) => {
    setItemNotes(item.notes || '')
    setNotesModal({ visible: true, item })
  }

  const handleSaveNotes = async () => {
    if (!currentInspection || !notesModal.item) return

    try {
      await updateItem(currentInspection.id, notesModal.item.id, { notes: itemNotes })
      setNotesModal({ visible: false, item: null })
    } catch (err) {
      console.warn('Failed to save notes:', err)
    }
  }

  const handleTakePhoto = async (item: InspectionItem) => {
    if (!currentInspection) return

    try {
      const { status } = await ImagePicker.requestCameraPermissionsAsync()
      if (status !== 'granted') {
        Alert.alert('Permission Required', 'Camera permission is required to take photos.')
        return
      }

      const result = await ImagePicker.launchCameraAsync({
        mediaTypes: ImagePicker.MediaTypeOptions.Images,
        allowsEditing: false,
        quality: 0.7,
      })

      if (!result.canceled && result.assets[0]) {
        await addItemPhoto(currentInspection.id, item.id, result.assets[0].uri)
      }
    } catch (err) {
      console.warn('Failed to take photo:', err)
      setError('Failed to capture photo')
    }
  }

  const handlePickPhoto = async (item: InspectionItem) => {
    if (!currentInspection) return

    try {
      const { status } = await ImagePicker.requestMediaLibraryPermissionsAsync()
      if (status !== 'granted') {
        Alert.alert(
          'Permission Required',
          'Media library permission is required to select photos.'
        )
        return
      }

      const result = await ImagePicker.launchImageLibraryAsync({
        mediaTypes: ImagePicker.MediaTypeOptions.Images,
        allowsEditing: false,
        quality: 0.7,
      })

      if (!result.canceled && result.assets[0]) {
        await addItemPhoto(currentInspection.id, item.id, result.assets[0].uri)
      }
    } catch (err) {
      console.warn('Failed to pick photo:', err)
      setError('Failed to select photo')
    }
  }

  const handlePhotoOptions = (item: InspectionItem) => {
    Alert.alert('Add Photo', 'Choose a source', [
      { text: 'Camera', onPress: () => handleTakePhoto(item) },
      { text: 'Photo Library', onPress: () => handlePickPhoto(item) },
      { text: 'Cancel', style: 'cancel' },
    ])
  }

  const handleViewPhoto = (url: string) => {
    setPhotoModal({ visible: true, url })
  }

  const handleComplete = async () => {
    if (!currentInspection) return

    // Check if all items have been inspected
    const pendingItems = currentInspection.items.filter(
      (item) => item.status === 'pending'
    )

    if (pendingItems.length > 0) {
      Alert.alert(
        'Incomplete Inspection',
        `There are ${pendingItems.length} items that haven't been inspected yet. Complete all items before submitting.`,
        [{ text: 'OK' }]
      )
      return
    }

    Alert.alert(
      'Complete Inspection',
      'Are you sure you want to submit this inspection? This action cannot be undone.',
      [
        { text: 'Cancel', style: 'cancel' },
        {
          text: 'Complete',
          style: 'default',
          onPress: async () => {
            setCompleting(true)
            try {
              await completeInspection(currentInspection.id)
              Alert.alert('Success', 'Inspection completed successfully.', [
                { text: 'OK', onPress: onComplete || onBack },
              ])
            } catch (err) {
              console.warn('Failed to complete inspection:', err)
              setError('Failed to complete inspection')
            } finally {
              setCompleting(false)
            }
          },
        },
      ]
    )
  }

  const progress = getProgress()
  const isCompleted = currentInspection?.status === 'completed'

  const getStatusColor = (status: InspectionItemStatus) => {
    switch (status) {
      case 'pass':
        return '#22c55e'
      case 'fail':
        return '#ef4444'
      case 'na':
        return '#64748b'
      default:
        return '#475569'
    }
  }

  const getCategoryProgress = (category: InspectionCategory) => {
    const items = itemsByCategory[category] || []
    const total = items.length
    const completed = items.filter((item) => item.status !== 'pending').length
    return { completed, total }
  }

  return (
    <View style={styles.container}>
      {/* Header */}
      <View style={styles.header}>
        <TouchableOpacity style={styles.backButton} onPress={onBack}>
          <Text style={styles.backButtonText}>{'<'} Back</Text>
        </TouchableOpacity>
        <View style={styles.headerTitle}>
          <Text style={styles.title}>Inspection</Text>
          {currentInspection && (
            <Text style={styles.inspectionId}>#{currentInspection.id}</Text>
          )}
        </View>
        <View style={styles.headerSpacer} />
      </View>

      {/* Progress Bar */}
      {currentInspection && (
        <View style={styles.progressSection}>
          <View style={styles.progressInfo}>
            <Text style={styles.progressText}>
              {progress.completed} of {progress.total} items completed
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

      {/* Error Banner */}
      {error && (
        <TouchableOpacity style={styles.errorBanner} onPress={() => setError(null)}>
          <Text style={styles.errorText}>{error}</Text>
          <Text style={styles.errorDismiss}>Tap to dismiss</Text>
        </TouchableOpacity>
      )}

      {/* Loading */}
      {loading && !refreshing && !currentInspection && (
        <View style={styles.loadingContainer}>
          <ActivityIndicator color="#38bdf8" size="large" />
          <Text style={styles.loadingText}>Loading inspection...</Text>
        </View>
      )}

      {/* Content */}
      {currentInspection && (
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
          {/* Vehicle Info Card */}
          {currentInspection.vehicle && (
            <View style={styles.vehicleCard}>
              <Text style={styles.vehicleName}>
                {[
                  currentInspection.vehicle.year,
                  currentInspection.vehicle.make,
                  currentInspection.vehicle.model,
                ]
                  .filter(Boolean)
                  .join(' ')}
              </Text>
              {currentInspection.vehicle.license_plate && (
                <Text style={styles.vehiclePlate}>
                  {currentInspection.vehicle.license_plate}
                </Text>
              )}
            </View>
          )}

          {/* Categories */}
          {CATEGORY_ORDER.map((category) => {
            const items = itemsByCategory[category]
            if (!items || items.length === 0) return null

            const categoryProgress = getCategoryProgress(category)
            const isExpanded = expandedCategory === category

            return (
              <View key={category} style={styles.categorySection}>
                <TouchableOpacity
                  style={styles.categoryHeader}
                  onPress={() => setExpandedCategory(isExpanded ? null : category)}
                  activeOpacity={0.7}
                >
                  <View style={styles.categoryHeaderLeft}>
                    <Text style={styles.categoryTitle}>{CATEGORY_LABELS[category]}</Text>
                    <Text style={styles.categoryCount}>
                      {categoryProgress.completed}/{categoryProgress.total}
                    </Text>
                  </View>
                  <Text style={styles.categoryChevron}>{isExpanded ? '−' : '+'}</Text>
                </TouchableOpacity>

                {isExpanded && (
                  <View style={styles.itemsList}>
                    {items.map((item) => (
                      <View
                        key={item.id}
                        style={[
                          styles.itemCard,
                          item.status === 'fail' && styles.itemCardFail,
                        ]}
                      >
                        <View style={styles.itemHeader}>
                          <Text style={styles.itemName}>{item.name}</Text>
                          {item.status !== 'pending' && (
                            <View
                              style={[
                                styles.itemStatusBadge,
                                { backgroundColor: getStatusColor(item.status) + '30' },
                              ]}
                            >
                              <Text
                                style={[
                                  styles.itemStatusText,
                                  { color: getStatusColor(item.status) },
                                ]}
                              >
                                {item.status.toUpperCase()}
                              </Text>
                            </View>
                          )}
                        </View>

                        {item.description && (
                          <Text style={styles.itemDescription}>{item.description}</Text>
                        )}

                        {/* Status Buttons */}
                        {!isCompleted && (
                          <View style={styles.statusButtons}>
                            {STATUS_OPTIONS.map((opt) => (
                              <TouchableOpacity
                                key={opt.key}
                                style={[
                                  styles.statusButton,
                                  item.status === opt.key && {
                                    backgroundColor: opt.color,
                                    borderColor: opt.color,
                                  },
                                ]}
                                onPress={() => handleStatusChange(item, opt.key)}
                              >
                                <Text
                                  style={[
                                    styles.statusButtonText,
                                    item.status === opt.key && styles.statusButtonTextActive,
                                  ]}
                                >
                                  {opt.label}
                                </Text>
                              </TouchableOpacity>
                            ))}
                          </View>
                        )}

                        {/* Notes */}
                        {item.notes && (
                          <View style={styles.notesPreview}>
                            <Text style={styles.notesPreviewLabel}>Notes:</Text>
                            <Text style={styles.notesPreviewText} numberOfLines={2}>
                              {item.notes}
                            </Text>
                          </View>
                        )}

                        {/* Photos */}
                        {item.photos && item.photos.length > 0 && (
                          <ScrollView
                            horizontal
                            showsHorizontalScrollIndicator={false}
                            style={styles.photosScroll}
                            contentContainerStyle={styles.photosContainer}
                          >
                            {item.photos.map((photo, idx) => (
                              <TouchableOpacity
                                key={idx}
                                onPress={() => handleViewPhoto(photo)}
                              >
                                <Image source={{ uri: photo }} style={styles.photoThumb} />
                              </TouchableOpacity>
                            ))}
                          </ScrollView>
                        )}

                        {/* Action Buttons */}
                        {!isCompleted && (
                          <View style={styles.itemActions}>
                            <TouchableOpacity
                              style={styles.actionButton}
                              onPress={() => handlePhotoOptions(item)}
                            >
                              <Text style={styles.actionButtonText}>Add Photo</Text>
                            </TouchableOpacity>
                            <TouchableOpacity
                              style={styles.actionButton}
                              onPress={() => handleOpenNotes(item)}
                            >
                              <Text style={styles.actionButtonText}>
                                {item.notes ? 'Edit Notes' : 'Add Notes'}
                              </Text>
                            </TouchableOpacity>
                          </View>
                        )}
                      </View>
                    ))}
                  </View>
                )}
              </View>
            )
          })}

          {/* Complete Button */}
          {!isCompleted && (
            <TouchableOpacity
              style={[
                styles.completeButton,
                progress.percentage < 100 && styles.completeButtonDisabled,
              ]}
              onPress={handleComplete}
              disabled={completing || progress.percentage < 100}
            >
              {completing ? (
                <ActivityIndicator color="#0f172a" />
              ) : (
                <Text style={styles.completeButtonText}>
                  {progress.percentage < 100 ? 'Complete All Items First' : 'Submit Inspection'}
                </Text>
              )}
            </TouchableOpacity>
          )}

          {isCompleted && (
            <View style={styles.completedBanner}>
              <Text style={styles.completedText}>Inspection Completed</Text>
              {currentInspection.completed_at && (
                <Text style={styles.completedDate}>
                  {new Date(currentInspection.completed_at).toLocaleDateString('en-US', {
                    weekday: 'long',
                    month: 'long',
                    day: 'numeric',
                    year: 'numeric',
                  })}
                </Text>
              )}
            </View>
          )}
        </ScrollView>
      )}

      {/* Notes Modal */}
      <Modal
        visible={notesModal.visible}
        animationType="slide"
        transparent
        onRequestClose={() => setNotesModal({ visible: false, item: null })}
      >
        <View style={styles.modalOverlay}>
          <View style={styles.modalContent}>
            <View style={styles.modalHeader}>
              <Text style={styles.modalTitle}>
                Notes: {notesModal.item?.name}
              </Text>
              <TouchableOpacity
                onPress={() => setNotesModal({ visible: false, item: null })}
              >
                <Text style={styles.modalClose}>Cancel</Text>
              </TouchableOpacity>
            </View>
            <TextInput
              style={styles.notesInput}
              value={itemNotes}
              onChangeText={setItemNotes}
              placeholder="Enter notes for this item..."
              placeholderTextColor="#64748b"
              multiline
              textAlignVertical="top"
            />
            <TouchableOpacity style={styles.saveNotesButton} onPress={handleSaveNotes}>
              <Text style={styles.saveNotesButtonText}>Save Notes</Text>
            </TouchableOpacity>
          </View>
        </View>
      </Modal>

      {/* Photo Viewer Modal */}
      <Modal
        visible={photoModal.visible}
        animationType="fade"
        transparent
        onRequestClose={() => setPhotoModal({ visible: false, url: null })}
      >
        <View style={styles.photoViewerOverlay}>
          <TouchableOpacity
            style={styles.photoViewerClose}
            onPress={() => setPhotoModal({ visible: false, url: null })}
          >
            <Text style={styles.photoViewerCloseText}>Close</Text>
          </TouchableOpacity>
          {photoModal.url && (
            <Image
              source={{ uri: photoModal.url }}
              style={styles.photoViewerImage}
              resizeMode="contain"
            />
          )}
        </View>
      </Modal>
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
  inspectionId: {
    fontSize: 20,
    fontWeight: '700',
    color: '#f8fafc',
  },
  headerSpacer: {
    width: 60,
  },
  progressSection: {
    backgroundColor: '#1e293b',
    paddingHorizontal: 16,
    paddingVertical: 12,
    borderBottomWidth: 1,
    borderBottomColor: '#334155',
  },
  progressInfo: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    marginBottom: 8,
  },
  progressText: {
    color: '#94a3b8',
    fontSize: 13,
  },
  progressPercentage: {
    color: '#38bdf8',
    fontSize: 13,
    fontWeight: '700',
  },
  progressBarBg: {
    height: 8,
    backgroundColor: '#334155',
    borderRadius: 4,
    overflow: 'hidden',
  },
  progressBarFill: {
    height: '100%',
    backgroundColor: '#38bdf8',
    borderRadius: 4,
  },
  progressBarComplete: {
    backgroundColor: '#22c55e',
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
  vehicleCard: {
    backgroundColor: '#1e293b',
    borderRadius: 12,
    padding: 16,
    marginBottom: 16,
    borderWidth: 1,
    borderColor: '#334155',
  },
  vehicleName: {
    color: '#f8fafc',
    fontSize: 18,
    fontWeight: '700',
  },
  vehiclePlate: {
    color: '#94a3b8',
    fontSize: 14,
    marginTop: 4,
  },
  categorySection: {
    marginBottom: 12,
  },
  categoryHeader: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    backgroundColor: '#1e293b',
    padding: 14,
    borderRadius: 10,
    borderWidth: 1,
    borderColor: '#334155',
  },
  categoryHeaderLeft: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 12,
  },
  categoryTitle: {
    color: '#f8fafc',
    fontSize: 16,
    fontWeight: '700',
  },
  categoryCount: {
    color: '#94a3b8',
    fontSize: 14,
  },
  categoryChevron: {
    color: '#38bdf8',
    fontSize: 20,
    fontWeight: '600',
  },
  itemsList: {
    marginTop: 8,
    gap: 8,
  },
  itemCard: {
    backgroundColor: '#1e293b',
    borderRadius: 10,
    padding: 14,
    borderWidth: 1,
    borderColor: '#334155',
  },
  itemCardFail: {
    borderColor: '#ef4444',
    borderWidth: 2,
  },
  itemHeader: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'flex-start',
    marginBottom: 8,
  },
  itemName: {
    color: '#f8fafc',
    fontSize: 15,
    fontWeight: '600',
    flex: 1,
    marginRight: 10,
  },
  itemStatusBadge: {
    paddingHorizontal: 8,
    paddingVertical: 3,
    borderRadius: 6,
  },
  itemStatusText: {
    fontSize: 10,
    fontWeight: '700',
  },
  itemDescription: {
    color: '#94a3b8',
    fontSize: 13,
    marginBottom: 12,
    lineHeight: 18,
  },
  statusButtons: {
    flexDirection: 'row',
    gap: 8,
    marginBottom: 12,
  },
  statusButton: {
    flex: 1,
    paddingVertical: 10,
    borderRadius: 8,
    borderWidth: 2,
    borderColor: '#475569',
    alignItems: 'center',
  },
  statusButtonText: {
    color: '#94a3b8',
    fontSize: 14,
    fontWeight: '600',
  },
  statusButtonTextActive: {
    color: '#0f172a',
  },
  notesPreview: {
    backgroundColor: '#0f172a',
    padding: 10,
    borderRadius: 8,
    marginBottom: 10,
  },
  notesPreviewLabel: {
    color: '#64748b',
    fontSize: 11,
    fontWeight: '600',
    marginBottom: 4,
  },
  notesPreviewText: {
    color: '#e2e8f0',
    fontSize: 13,
  },
  photosScroll: {
    marginBottom: 10,
  },
  photosContainer: {
    flexDirection: 'row',
    gap: 8,
  },
  photoThumb: {
    width: 70,
    height: 70,
    borderRadius: 8,
    backgroundColor: '#334155',
  },
  itemActions: {
    flexDirection: 'row',
    gap: 10,
  },
  actionButton: {
    flex: 1,
    backgroundColor: '#334155',
    paddingVertical: 10,
    borderRadius: 8,
    alignItems: 'center',
  },
  actionButtonText: {
    color: '#38bdf8',
    fontSize: 13,
    fontWeight: '600',
  },
  completeButton: {
    backgroundColor: '#22c55e',
    paddingVertical: 18,
    borderRadius: 12,
    alignItems: 'center',
    marginTop: 16,
  },
  completeButtonDisabled: {
    backgroundColor: '#475569',
  },
  completeButtonText: {
    color: '#0f172a',
    fontSize: 16,
    fontWeight: '700',
  },
  completedBanner: {
    backgroundColor: '#14532d',
    borderRadius: 12,
    padding: 20,
    alignItems: 'center',
    marginTop: 16,
    borderWidth: 2,
    borderColor: '#22c55e',
  },
  completedText: {
    color: '#22c55e',
    fontSize: 18,
    fontWeight: '700',
  },
  completedDate: {
    color: '#86efac',
    fontSize: 14,
    marginTop: 6,
  },
  modalOverlay: {
    flex: 1,
    backgroundColor: 'rgba(0, 0, 0, 0.8)',
    justifyContent: 'flex-end',
  },
  modalContent: {
    backgroundColor: '#1e293b',
    borderTopLeftRadius: 20,
    borderTopRightRadius: 20,
    padding: 20,
    maxHeight: '70%',
  },
  modalHeader: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    marginBottom: 16,
  },
  modalTitle: {
    color: '#f8fafc',
    fontSize: 16,
    fontWeight: '700',
    flex: 1,
    marginRight: 16,
  },
  modalClose: {
    color: '#ef4444',
    fontSize: 15,
    fontWeight: '600',
  },
  notesInput: {
    backgroundColor: '#0f172a',
    borderRadius: 10,
    padding: 14,
    color: '#f8fafc',
    fontSize: 15,
    minHeight: 120,
    borderWidth: 1,
    borderColor: '#334155',
    marginBottom: 16,
  },
  saveNotesButton: {
    backgroundColor: '#38bdf8',
    paddingVertical: 14,
    borderRadius: 10,
    alignItems: 'center',
  },
  saveNotesButtonText: {
    color: '#0f172a',
    fontSize: 15,
    fontWeight: '700',
  },
  photoViewerOverlay: {
    flex: 1,
    backgroundColor: 'rgba(0, 0, 0, 0.95)',
    justifyContent: 'center',
    alignItems: 'center',
  },
  photoViewerClose: {
    position: 'absolute',
    top: 50,
    right: 20,
    zIndex: 10,
    padding: 10,
  },
  photoViewerCloseText: {
    color: '#f8fafc',
    fontSize: 16,
    fontWeight: '600',
  },
  photoViewerImage: {
    width: '100%',
    height: '80%',
  },
})
