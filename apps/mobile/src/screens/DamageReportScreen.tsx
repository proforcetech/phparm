import * as ImagePicker from 'expo-image-picker'
import * as ImageManipulator from 'expo-image-manipulator'
import { useCallback, useEffect, useState } from 'react'
import {
  ActivityIndicator,
  Alert,
  Image,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
  TouchableOpacity,
  View,
} from 'react-native'

import damageReportService, {
  DamagePhoto,
  DamageReportData,
} from '../services/damageReport.service'
import { useToastStore } from '../stores/toastStore'

type PhotoPosition = 'front' | 'rear' | 'left' | 'right' | 'additional'

type CornerPhoto = {
  position: PhotoPosition
  label: string
  required: boolean
}

const CORNER_PHOTOS: CornerPhoto[] = [
  { position: 'front', label: 'Front', required: true },
  { position: 'rear', label: 'Rear', required: true },
  { position: 'left', label: 'Left Side', required: true },
  { position: 'right', label: 'Right Side', required: true },
]

const MAX_ADDITIONAL_PHOTOS = 4
const IMAGE_MAX_WIDTH = 1024
const IMAGE_QUALITY = 0.8

type DamageReportScreenProps = {
  workorderId: number
  jobId: number
  jobTitle: string
  onBack: () => void
  onComplete: () => void
}

export function DamageReportScreen({
  workorderId,
  jobId,
  jobTitle,
  onBack,
  onComplete,
}: DamageReportScreenProps) {
  const [cornerPhotos, setCornerPhotos] = useState<Record<string, DamagePhoto | null>>({
    front: null,
    rear: null,
    left: null,
    right: null,
  })
  const [additionalPhotos, setAdditionalPhotos] = useState<DamagePhoto[]>([])
  const [notes, setNotes] = useState('')
  const [isSubmitting, setIsSubmitting] = useState(false)
  const [isLoadingDraft, setIsLoadingDraft] = useState(true)
  const [existingReportCount, setExistingReportCount] = useState(0)
  const [permissionStatus, setPermissionStatus] = useState<'granted' | 'denied' | 'undetermined'>(
    'undetermined'
  )

  const pushSuccess = useToastStore((state) => state.success)
  const pushError = useToastStore((state) => state.error)
  const pushInfo = useToastStore((state) => state.info)

  const capturedCornerCount = Object.values(cornerPhotos).filter(Boolean).length
  const allCornersComplete = capturedCornerCount === CORNER_PHOTOS.length
  const canSubmit = allCornersComplete && !isSubmitting

  /**
   * Request camera permissions on mount.
   */
  useEffect(() => {
    const requestPermission = async () => {
      const { status } = await ImagePicker.requestCameraPermissionsAsync()
      setPermissionStatus(status === 'granted' ? 'granted' : 'denied')
    }
    requestPermission()
  }, [])

  /**
   * Load any existing draft and check for existing reports.
   */
  useEffect(() => {
    const loadInitialData = async () => {
      try {
        // Load draft if exists
        const draft = await damageReportService.getDraft(workorderId, jobId)
        if (draft) {
          if (draft.photos) {
            const corners: Record<string, DamagePhoto | null> = {
              front: null,
              rear: null,
              left: null,
              right: null,
            }
            const additional: DamagePhoto[] = []

            draft.photos.forEach((photo) => {
              if (photo.position === 'additional') {
                additional.push(photo)
              } else if (photo.position in corners) {
                corners[photo.position] = photo
              }
            })

            setCornerPhotos(corners)
            setAdditionalPhotos(additional)
          }
          if (draft.notes) {
            setNotes(draft.notes)
          }
          pushInfo('Draft restored from previous session.')
        }

        // Check for existing reports
        const reports = await damageReportService.getDamageReports(workorderId, jobId)
        setExistingReportCount(reports.length)
      } catch (error) {
        console.warn('Failed to load initial data:', error)
      } finally {
        setIsLoadingDraft(false)
      }
    }

    loadInitialData()
  }, [workorderId, jobId, pushInfo])

  /**
   * Auto-save draft whenever photos or notes change.
   */
  useEffect(() => {
    if (isLoadingDraft) {
      return
    }

    const allPhotos: DamagePhoto[] = [
      ...Object.values(cornerPhotos).filter((p): p is DamagePhoto => p !== null),
      ...additionalPhotos,
    ]

    if (allPhotos.length > 0 || notes.trim()) {
      damageReportService.saveDraft(workorderId, jobId, {
        photos: allPhotos,
        notes,
      })
    }
  }, [cornerPhotos, additionalPhotos, notes, workorderId, jobId, isLoadingDraft])

  /**
   * Compress and resize an image for upload.
   */
  const compressImage = useCallback(async (uri: string): Promise<string> => {
    try {
      const result = await ImageManipulator.manipulateAsync(
        uri,
        [{ resize: { width: IMAGE_MAX_WIDTH } }],
        {
          compress: IMAGE_QUALITY,
          format: ImageManipulator.SaveFormat.JPEG,
        }
      )
      return result.uri
    } catch (error) {
      console.warn('Image compression failed, using original:', error)
      return uri
    }
  }, [])

  /**
   * Launch camera and capture a photo.
   */
  const capturePhoto = useCallback(
    async (position: PhotoPosition, label: string): Promise<DamagePhoto | null> => {
      if (permissionStatus !== 'granted') {
        const { status } = await ImagePicker.requestCameraPermissionsAsync()
        if (status !== 'granted') {
          Alert.alert(
            'Camera Permission Required',
            'Please grant camera access in your device settings to capture damage photos.',
            [{ text: 'OK' }]
          )
          return null
        }
        setPermissionStatus('granted')
      }

      try {
        const result = await ImagePicker.launchCameraAsync({
          mediaTypes: ImagePicker.MediaTypeOptions.Images,
          allowsEditing: false,
          quality: 1,
          exif: false,
        })

        if (result.canceled || !result.assets?.[0]) {
          return null
        }

        const originalUri = result.assets[0].uri
        const compressedUri = await compressImage(originalUri)

        return {
          uri: compressedUri,
          position,
          label,
          timestamp: new Date().toISOString(),
        }
      } catch (error) {
        console.warn('Photo capture failed:', error)
        pushError('Failed to capture photo. Please try again.')
        return null
      }
    },
    [permissionStatus, compressImage, pushError]
  )

  /**
   * Handle capturing a corner photo.
   */
  const handleCaptureCorner = useCallback(
    async (corner: CornerPhoto) => {
      const photo = await capturePhoto(corner.position, corner.label)
      if (photo) {
        setCornerPhotos((prev) => ({
          ...prev,
          [corner.position]: photo,
        }))
      }
    },
    [capturePhoto]
  )

  /**
   * Handle retaking a corner photo.
   */
  const handleRetakeCorner = useCallback(
    async (corner: CornerPhoto) => {
      Alert.alert('Retake Photo', `Replace the ${corner.label} photo?`, [
        { text: 'Cancel', style: 'cancel' },
        {
          text: 'Retake',
          onPress: async () => {
            const photo = await capturePhoto(corner.position, corner.label)
            if (photo) {
              setCornerPhotos((prev) => ({
                ...prev,
                [corner.position]: photo,
              }))
            }
          },
        },
      ])
    },
    [capturePhoto]
  )

  /**
   * Handle capturing an additional damage photo.
   */
  const handleCaptureAdditional = useCallback(async () => {
    if (additionalPhotos.length >= MAX_ADDITIONAL_PHOTOS) {
      pushInfo(`Maximum of ${MAX_ADDITIONAL_PHOTOS} additional photos allowed.`)
      return
    }

    const label = `Damage ${additionalPhotos.length + 1}`
    const photo = await capturePhoto('additional', label)
    if (photo) {
      setAdditionalPhotos((prev) => [...prev, photo])
    }
  }, [additionalPhotos.length, capturePhoto, pushInfo])

  /**
   * Handle removing an additional photo.
   */
  const handleRemoveAdditional = useCallback((index: number) => {
    Alert.alert('Remove Photo', 'Remove this damage photo?', [
      { text: 'Cancel', style: 'cancel' },
      {
        text: 'Remove',
        style: 'destructive',
        onPress: () => {
          setAdditionalPhotos((prev) => prev.filter((_, i) => i !== index))
        },
      },
    ])
  }, [])

  /**
   * Submit the damage report.
   */
  const handleSubmit = useCallback(async () => {
    if (!canSubmit) {
      return
    }

    setIsSubmitting(true)

    try {
      const allPhotos: DamagePhoto[] = [
        ...Object.values(cornerPhotos).filter((p): p is DamagePhoto => p !== null),
        ...additionalPhotos,
      ]

      const reportData: DamageReportData = {
        photos: allPhotos,
        notes: notes.trim(),
        reportedAt: new Date().toISOString(),
      }

      const result = await damageReportService.createDamageReport(workorderId, jobId, reportData)

      if (result.queued) {
        pushInfo('Damage report saved. It will upload when you are back online.')
      } else {
        pushSuccess('Damage report submitted successfully.')
      }

      onComplete()
    } catch (error) {
      console.warn('Submit failed:', error)
      pushError('Failed to submit damage report. Please try again.')
    } finally {
      setIsSubmitting(false)
    }
  }, [
    canSubmit,
    cornerPhotos,
    additionalPhotos,
    notes,
    workorderId,
    jobId,
    onComplete,
    pushSuccess,
    pushError,
    pushInfo,
  ])

  /**
   * Handle back navigation with draft confirmation.
   */
  const handleBack = useCallback(() => {
    const hasPhotos =
      Object.values(cornerPhotos).some(Boolean) || additionalPhotos.length > 0

    if (hasPhotos || notes.trim()) {
      Alert.alert(
        'Save Progress?',
        'Your photos and notes will be saved as a draft.',
        [
          {
            text: 'Discard',
            style: 'destructive',
            onPress: async () => {
              await damageReportService.deleteDraft(workorderId, jobId)
              onBack()
            },
          },
          {
            text: 'Save Draft',
            onPress: () => onBack(),
          },
        ]
      )
    } else {
      onBack()
    }
  }, [cornerPhotos, additionalPhotos, notes, workorderId, jobId, onBack])

  if (isLoadingDraft) {
    return (
      <View style={styles.container}>
        <View style={styles.loadingContainer}>
          <ActivityIndicator color="#38bdf8" size="large" />
          <Text style={styles.loadingText}>Loading...</Text>
        </View>
      </View>
    )
  }

  return (
    <View style={styles.container}>
      <ScrollView contentContainerStyle={styles.scrollContent}>
        {/* Header */}
        <View style={styles.header}>
          <TouchableOpacity style={styles.backButton} onPress={handleBack}>
            <Text style={styles.backButtonText}>Back</Text>
          </TouchableOpacity>
          <View style={styles.headerTitleContainer}>
            <Text style={styles.headerTitle}>Damage Report</Text>
            <Text style={styles.headerSubtitle}>{jobTitle}</Text>
          </View>
        </View>

        {/* Existing Report Warning */}
        {existingReportCount > 0 && (
          <View style={styles.warningBanner}>
            <Text style={styles.warningText}>
              {existingReportCount} damage report(s) already exist for this job.
            </Text>
          </View>
        )}

        {/* Progress Indicator */}
        <View style={styles.progressContainer}>
          <Text style={styles.progressLabel}>Required Photos</Text>
          <View style={styles.progressBar}>
            <View
              style={[
                styles.progressFill,
                { width: `${(capturedCornerCount / CORNER_PHOTOS.length) * 100}%` },
              ]}
            />
          </View>
          <Text style={styles.progressText}>
            {capturedCornerCount} of {CORNER_PHOTOS.length} captured
          </Text>
        </View>

        {/* Corner Photos Section */}
        <View style={styles.section}>
          <Text style={styles.sectionTitle}>Vehicle Corner Photos (Required)</Text>
          <Text style={styles.sectionHint}>
            Capture all 4 corners before towing. Tap to capture, long press to retake.
          </Text>
          <View style={styles.photoGrid}>
            {CORNER_PHOTOS.map((corner) => {
              const photo = cornerPhotos[corner.position]
              return (
                <TouchableOpacity
                  key={corner.position}
                  style={[styles.photoSlot, photo ? styles.photoSlotFilled : null]}
                  onPress={() => (photo ? handleRetakeCorner(corner) : handleCaptureCorner(corner))}
                  onLongPress={() => photo && handleRetakeCorner(corner)}
                  activeOpacity={0.7}
                >
                  {photo ? (
                    <>
                      <Image source={{ uri: photo.uri }} style={styles.photoPreview} />
                      <View style={styles.photoOverlay}>
                        <Text style={styles.photoLabel}>{corner.label}</Text>
                        <Text style={styles.photoCheck}>Captured</Text>
                      </View>
                    </>
                  ) : (
                    <>
                      <View style={styles.cameraIcon}>
                        <Text style={styles.cameraIconText}>+</Text>
                      </View>
                      <Text style={styles.photoSlotLabel}>{corner.label}</Text>
                    </>
                  )}
                </TouchableOpacity>
              )
            })}
          </View>
        </View>

        {/* Additional Damage Photos Section */}
        <View style={styles.section}>
          <Text style={styles.sectionTitle}>
            Additional Damage Photos ({additionalPhotos.length}/{MAX_ADDITIONAL_PHOTOS})
          </Text>
          <Text style={styles.sectionHint}>
            Capture close-up photos of any visible damage.
          </Text>
          <View style={styles.additionalPhotosRow}>
            {additionalPhotos.map((photo, index) => (
              <TouchableOpacity
                key={`additional-${index}`}
                style={styles.additionalPhotoSlot}
                onPress={() => handleRemoveAdditional(index)}
                activeOpacity={0.7}
              >
                <Image source={{ uri: photo.uri }} style={styles.additionalPhotoPreview} />
                <View style={styles.removeOverlay}>
                  <Text style={styles.removeOverlayText}>Tap to Remove</Text>
                </View>
              </TouchableOpacity>
            ))}
            {additionalPhotos.length < MAX_ADDITIONAL_PHOTOS && (
              <TouchableOpacity
                style={styles.addPhotoButton}
                onPress={handleCaptureAdditional}
                activeOpacity={0.7}
              >
                <Text style={styles.addPhotoButtonText}>+</Text>
                <Text style={styles.addPhotoButtonLabel}>Add Photo</Text>
              </TouchableOpacity>
            )}
          </View>
        </View>

        {/* Notes Section */}
        <View style={styles.section}>
          <Text style={styles.sectionTitle}>Notes (Optional)</Text>
          <TextInput
            style={styles.notesInput}
            placeholder="Describe any visible damage, dents, scratches, or concerns..."
            placeholderTextColor="#64748b"
            multiline
            numberOfLines={4}
            textAlignVertical="top"
            value={notes}
            onChangeText={setNotes}
          />
        </View>

        {/* Submit Button */}
        <View style={styles.submitContainer}>
          <TouchableOpacity
            style={[styles.submitButton, !canSubmit && styles.submitButtonDisabled]}
            onPress={handleSubmit}
            disabled={!canSubmit}
            activeOpacity={0.7}
          >
            {isSubmitting ? (
              <ActivityIndicator color="#0f172a" size="small" />
            ) : (
              <Text style={styles.submitButtonText}>
                {allCornersComplete ? 'Submit Damage Report' : 'Complete All Corner Photos'}
              </Text>
            )}
          </TouchableOpacity>
          {!allCornersComplete && (
            <Text style={styles.submitHint}>
              All 4 corner photos are required before submission.
            </Text>
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
    padding: 16,
    paddingBottom: 48,
  },
  loadingContainer: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
  },
  loadingText: {
    color: '#94a3b8',
    marginTop: 12,
    fontSize: 14,
  },
  header: {
    flexDirection: 'row',
    alignItems: 'center',
    marginBottom: 20,
    paddingTop: 8,
  },
  backButton: {
    paddingVertical: 8,
    paddingHorizontal: 12,
    backgroundColor: '#1e293b',
    borderRadius: 8,
  },
  backButtonText: {
    color: '#e2e8f0',
    fontWeight: '600',
  },
  headerTitleContainer: {
    flex: 1,
    marginLeft: 12,
  },
  headerTitle: {
    fontSize: 20,
    fontWeight: '700',
    color: '#f8fafc',
  },
  headerSubtitle: {
    fontSize: 14,
    color: '#94a3b8',
    marginTop: 2,
  },
  warningBanner: {
    backgroundColor: '#854d0e',
    padding: 12,
    borderRadius: 8,
    marginBottom: 16,
  },
  warningText: {
    color: '#fef3c7',
    fontSize: 13,
    textAlign: 'center',
  },
  progressContainer: {
    backgroundColor: '#1e293b',
    padding: 16,
    borderRadius: 12,
    marginBottom: 20,
  },
  progressLabel: {
    color: '#e2e8f0',
    fontSize: 14,
    fontWeight: '600',
    marginBottom: 8,
  },
  progressBar: {
    height: 8,
    backgroundColor: '#334155',
    borderRadius: 4,
    overflow: 'hidden',
  },
  progressFill: {
    height: '100%',
    backgroundColor: '#22c55e',
    borderRadius: 4,
  },
  progressText: {
    color: '#94a3b8',
    fontSize: 12,
    marginTop: 6,
    textAlign: 'right',
  },
  section: {
    marginBottom: 24,
  },
  sectionTitle: {
    fontSize: 16,
    fontWeight: '700',
    color: '#f8fafc',
    marginBottom: 4,
  },
  sectionHint: {
    fontSize: 13,
    color: '#94a3b8',
    marginBottom: 12,
  },
  photoGrid: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: 12,
  },
  photoSlot: {
    width: '47%',
    aspectRatio: 4 / 3,
    backgroundColor: '#1e293b',
    borderRadius: 12,
    borderWidth: 2,
    borderColor: '#334155',
    borderStyle: 'dashed',
    alignItems: 'center',
    justifyContent: 'center',
    overflow: 'hidden',
  },
  photoSlotFilled: {
    borderStyle: 'solid',
    borderColor: '#22c55e',
  },
  photoPreview: {
    width: '100%',
    height: '100%',
    resizeMode: 'cover',
  },
  photoOverlay: {
    position: 'absolute',
    bottom: 0,
    left: 0,
    right: 0,
    backgroundColor: 'rgba(0, 0, 0, 0.7)',
    padding: 8,
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
  },
  photoLabel: {
    color: '#f8fafc',
    fontSize: 12,
    fontWeight: '600',
  },
  photoCheck: {
    color: '#22c55e',
    fontSize: 11,
    fontWeight: '600',
  },
  cameraIcon: {
    width: 48,
    height: 48,
    borderRadius: 24,
    backgroundColor: '#334155',
    alignItems: 'center',
    justifyContent: 'center',
    marginBottom: 8,
  },
  cameraIconText: {
    color: '#94a3b8',
    fontSize: 24,
    fontWeight: '300',
  },
  photoSlotLabel: {
    color: '#94a3b8',
    fontSize: 13,
    fontWeight: '500',
  },
  additionalPhotosRow: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: 10,
  },
  additionalPhotoSlot: {
    width: 80,
    height: 80,
    borderRadius: 8,
    overflow: 'hidden',
    position: 'relative',
  },
  additionalPhotoPreview: {
    width: '100%',
    height: '100%',
    resizeMode: 'cover',
  },
  removeOverlay: {
    position: 'absolute',
    bottom: 0,
    left: 0,
    right: 0,
    backgroundColor: 'rgba(239, 68, 68, 0.85)',
    padding: 4,
  },
  removeOverlayText: {
    color: '#fff',
    fontSize: 9,
    textAlign: 'center',
    fontWeight: '500',
  },
  addPhotoButton: {
    width: 80,
    height: 80,
    borderRadius: 8,
    backgroundColor: '#1e293b',
    borderWidth: 1,
    borderColor: '#334155',
    borderStyle: 'dashed',
    alignItems: 'center',
    justifyContent: 'center',
  },
  addPhotoButtonText: {
    color: '#64748b',
    fontSize: 24,
    fontWeight: '300',
  },
  addPhotoButtonLabel: {
    color: '#64748b',
    fontSize: 10,
    marginTop: 2,
  },
  notesInput: {
    backgroundColor: '#1e293b',
    borderRadius: 10,
    padding: 14,
    color: '#f8fafc',
    fontSize: 14,
    minHeight: 100,
    borderWidth: 1,
    borderColor: '#334155',
  },
  submitContainer: {
    marginTop: 8,
  },
  submitButton: {
    backgroundColor: '#22c55e',
    paddingVertical: 16,
    borderRadius: 12,
    alignItems: 'center',
  },
  submitButtonDisabled: {
    backgroundColor: '#334155',
  },
  submitButtonText: {
    color: '#0f172a',
    fontWeight: '700',
    fontSize: 16,
  },
  submitHint: {
    color: '#94a3b8',
    fontSize: 12,
    textAlign: 'center',
    marginTop: 8,
  },
})
