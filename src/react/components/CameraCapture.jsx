import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import Button from './ui/Button'

const formatFileSize = (bytes) => {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

const formatDuration = (seconds) => {
  const mins = Math.floor(seconds / 60)
  const secs = Math.floor(seconds % 60)
  return `${mins}:${secs.toString().padStart(2, '0')}`
}

const bytesToMB = (bytes) => `${(bytes / (1024 * 1024)).toFixed(0)} MB`

const DEFAULT_ALLOWED_MIME_TYPES = [
  'image/jpeg',
  'image/png',
  'image/gif',
  'video/mp4',
  'video/quicktime',
  'video/webm',
]

const mimeToExtension = (type) => {
  if (type === 'video/quicktime') {
    return 'mov'
  }
  return type.split('/')[1]
}

const extensionFromName = (name) => {
  const parts = name.split('.')
  return parts.length > 1 ? parts.pop().toLowerCase() : ''
}

export default function CameraCapture({
  onCapture,
  maxVideoDuration = 60,
  allowedTypes = ['image', 'video'],
  allowedMimeTypes = DEFAULT_ALLOWED_MIME_TYPES,
  maxImageSizeBytes = 8 * 1024 * 1024,
  maxVideoSizeBytes = 50 * 1024 * 1024,
}) {
  const videoRef = useRef(null)
  const mediaRecorderRef = useRef(null)
  const streamRef = useRef(null)
  const recordedChunksRef = useRef([])

  const [cameraActive, setCameraActive] = useState(false)
  const [cameraError, setCameraError] = useState('')
  const [isRecording, setIsRecording] = useState(false)
  const [recordingTime, setRecordingTime] = useState(0)
  const [captures, setCaptures] = useState([])
  const [facingMode, setFacingMode] = useState('environment') // 'environment' = back camera, 'user' = front camera
  const [cameraSupported, setCameraSupported] = useState(true)
  const [permissionDenied, setPermissionDenied] = useState(false)
  const [validationError, setValidationError] = useState('')

  const recordingTimerRef = useRef(null)
  const allowedExtensions = useMemo(() => {
    const extensions = new Set()
    allowedMimeTypes.forEach((type) => {
      const extension = mimeToExtension(type)
      if (extension) {
        extensions.add(extension)
      }
      if (type === 'image/jpeg') {
        extensions.add('jpg')
      }
    })
    return Array.from(extensions)
  }, [allowedMimeTypes])
  const fileAccept = allowedMimeTypes.length ? allowedMimeTypes.join(',') : 'image/*,video/*'
  const maxSizeText = `Photos up to ${bytesToMB(maxImageSizeBytes)} • Videos up to ${bytesToMB(maxVideoSizeBytes)}`

  // Check for camera support
  useEffect(() => {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      setCameraSupported(false)
    }
  }, [])

  // Cleanup on unmount
  useEffect(() => {
    return () => {
      stopCamera()
      if (recordingTimerRef.current) {
        clearInterval(recordingTimerRef.current)
      }
    }
  }, [])

  // Recording timer
  useEffect(() => {
    if (isRecording) {
      recordingTimerRef.current = setInterval(() => {
        setRecordingTime((prev) => {
          if (prev >= maxVideoDuration) {
            stopRecording()
            return prev
          }
          return prev + 1
        })
      }, 1000)
    } else {
      if (recordingTimerRef.current) {
        clearInterval(recordingTimerRef.current)
      }
      setRecordingTime(0)
    }

    return () => {
      if (recordingTimerRef.current) {
        clearInterval(recordingTimerRef.current)
      }
    }
  }, [isRecording, maxVideoDuration])

  // Notify parent of captured files
  useEffect(() => {
    if (onCapture) {
      onCapture(captures.map((c) => c.file))
    }
  }, [captures, onCapture])

  const startCamera = useCallback(async () => {
    setCameraError('')
    setValidationError('')
    setPermissionDenied(false)

    try {
      const constraints = {
        video: {
          facingMode,
          width: { ideal: 1920 },
          height: { ideal: 1080 },
        },
        audio: allowedTypes.includes('video'),
      }

      const stream = await navigator.mediaDevices.getUserMedia(constraints)
      streamRef.current = stream

      if (videoRef.current) {
        videoRef.current.srcObject = stream
        await videoRef.current.play()
      }

      setCameraActive(true)
    } catch (err) {
      console.error('Camera access error:', err)
      if (err.name === 'NotAllowedError' || err.name === 'PermissionDeniedError') {
        setPermissionDenied(true)
        setCameraError('Camera access denied. Please grant camera permission and try again.')
      } else if (err.name === 'NotFoundError') {
        setCameraError('No camera found on this device.')
      } else if (err.name === 'NotReadableError') {
        setCameraError('Camera is already in use by another application.')
      } else {
        setCameraError(`Unable to access camera: ${err.message}`)
      }
    }
  }, [facingMode, allowedTypes])

  const stopCamera = useCallback(() => {
    if (streamRef.current) {
      streamRef.current.getTracks().forEach((track) => track.stop())
      streamRef.current = null
    }
    if (videoRef.current) {
      videoRef.current.srcObject = null
    }
    setCameraActive(false)
    setIsRecording(false)
  }, [])

  const switchCamera = useCallback(() => {
    setFacingMode((prev) => (prev === 'environment' ? 'user' : 'environment'))
    if (cameraActive) {
      stopCamera()
      setTimeout(startCamera, 100)
    }
  }, [cameraActive, stopCamera, startCamera])

  const validateFile = useCallback((file) => {
    const type = file.type.startsWith('video/') ? 'video' : 'image'
    const extension = extensionFromName(file.name)

    if (!allowedTypes.includes(type)) {
      return { valid: false, message: `Only ${allowedTypes.join(' and ')} files are allowed.` }
    }

    if (file.type && !allowedMimeTypes.includes(file.type)) {
      return { valid: false, message: 'Unsupported file format.' }
    }

    if (!file.type && extension && !allowedExtensions.includes(extension)) {
      return { valid: false, message: 'Unsupported file format.' }
    }

    const maxBytes = type === 'video' ? maxVideoSizeBytes : maxImageSizeBytes
    if (file.size > maxBytes) {
      return { valid: false, message: `${type === 'video' ? 'Video' : 'Photo'} is too large.` }
    }

    return { valid: true, type }
  }, [allowedExtensions, allowedMimeTypes, allowedTypes, maxImageSizeBytes, maxVideoSizeBytes])

  const capturePhoto = useCallback(() => {
    if (!videoRef.current || !cameraActive) return

    const canvas = document.createElement('canvas')
    canvas.width = videoRef.current.videoWidth
    canvas.height = videoRef.current.videoHeight

    const ctx = canvas.getContext('2d')
    ctx.drawImage(videoRef.current, 0, 0)

    canvas.toBlob((blob) => {
      if (blob) {
        const timestamp = Date.now()
        const file = new File([blob], `photo_${timestamp}.jpg`, { type: 'image/jpeg' })
        const validation = validateFile(file)
        if (!validation.valid) {
          setValidationError(validation.message)
          return
        }
        const preview = URL.createObjectURL(blob)

        setCaptures((prev) => [...prev, {
          id: timestamp,
          type: 'image',
          file,
          preview,
          size: blob.size,
        }])
      }
    }, 'image/jpeg', 0.9)
  }, [cameraActive, maxImageSizeBytes, maxVideoSizeBytes, allowedMimeTypes, allowedTypes, validateFile])

  const startRecording = useCallback(() => {
    if (!streamRef.current || !cameraActive) return

    recordedChunksRef.current = []

    const options = { mimeType: 'video/webm;codecs=vp9,opus' }
    if (!MediaRecorder.isTypeSupported(options.mimeType)) {
      options.mimeType = 'video/webm'
      if (!MediaRecorder.isTypeSupported(options.mimeType)) {
        options.mimeType = 'video/mp4'
      }
    }

    try {
      const mediaRecorder = new MediaRecorder(streamRef.current, options)
      mediaRecorderRef.current = mediaRecorder

      mediaRecorder.ondataavailable = (event) => {
        if (event.data.size > 0) {
          recordedChunksRef.current.push(event.data)
        }
      }

      mediaRecorder.onstop = () => {
        const blob = new Blob(recordedChunksRef.current, { type: options.mimeType })
        const timestamp = Date.now()
        const extension = options.mimeType.includes('mp4') ? 'mp4' : 'webm'
        const file = new File([blob], `video_${timestamp}.${extension}`, { type: options.mimeType })
        const validation = validateFile(file)
        if (!validation.valid) {
          setValidationError(validation.message)
          return
        }
        const preview = URL.createObjectURL(blob)

        setCaptures((prev) => [...prev, {
          id: timestamp,
          type: 'video',
          file,
          preview,
          size: blob.size,
          duration: recordingTime,
        }])
      }

      mediaRecorder.start(1000) // Collect data every second
      setIsRecording(true)
    } catch (err) {
      console.error('Recording error:', err)
      setCameraError('Unable to start video recording.')
    }
  }, [cameraActive, recordingTime, maxImageSizeBytes, maxVideoSizeBytes, allowedMimeTypes, allowedTypes, validateFile])

  const stopRecording = useCallback(() => {
    if (mediaRecorderRef.current && isRecording) {
      mediaRecorderRef.current.stop()
      setIsRecording(false)
    }
  }, [isRecording])

  const removeCapture = useCallback((id) => {
    setCaptures((prev) => {
      const capture = prev.find((c) => c.id === id)
      if (capture?.preview) {
        URL.revokeObjectURL(capture.preview)
      }
      return prev.filter((c) => c.id !== id)
    })
  }, [])

  const handleFileInput = useCallback((event) => {
    const files = Array.from(event.target.files || [])
    const newCaptures = []
    let errorMessage = ''

    files.forEach((file) => {
      const validation = validateFile(file)
      if (!validation.valid) {
        errorMessage = validation.message
        return
      }
      newCaptures.push({
        id: Date.now() + Math.random(),
        type: validation.type,
        file,
        preview: URL.createObjectURL(file),
        size: file.size,
      })
    })

    if (newCaptures.length) {
      setCaptures((prev) => [...prev, ...newCaptures])
      setValidationError('')
    } else if (errorMessage) {
      setValidationError(errorMessage)
    }
    event.target.value = '' // Reset input
  }, [validateFile])

  return (
    <div className="space-y-4">
      {/* Camera view or activation button */}
      {!cameraActive ? (
        <div className="border-2 border-dashed border-gray-300 rounded-lg p-6 text-center">
          {cameraSupported ? (
            <div className="space-y-4">
              <div className="text-gray-600">
                <svg className="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" />
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 13a3 3 0 11-6 0 3 3 0 016 0z" />
                </svg>
                <p className="mt-2 text-sm font-medium">Capture Visual Evidence</p>
                <p className="text-xs text-gray-500">Take photos or videos directly from your device camera</p>
              </div>

              <div className="flex flex-col sm:flex-row gap-2 justify-center">
                <Button onClick={startCamera} variant="primary">
                  Open Camera
                </Button>
                <label className="cursor-pointer">
                  <Button as="span" variant="outline">
                    Choose from Gallery
                  </Button>
                  <input
                    type="file"
                    multiple
                    accept={fileAccept}
                    onChange={handleFileInput}
                    className="hidden"
                  />
                </label>
              </div>
              <p className="text-xs text-gray-500">{maxSizeText}</p>

              {permissionDenied && (
                <p className="text-sm text-amber-600 mt-2">
                  Camera permission was denied. You can still upload files from your gallery.
                </p>
              )}
            </div>
          ) : (
            <div className="space-y-4">
              <p className="text-gray-600">Camera not supported on this device.</p>
              <label className="cursor-pointer">
                <Button as="span" variant="primary">
                  Upload from Device
                </Button>
                <input
                  type="file"
                  multiple
                  accept={fileAccept}
                  onChange={handleFileInput}
                  className="hidden"
                />
              </label>
              <p className="text-xs text-gray-500">{maxSizeText}</p>
            </div>
          )}

          {cameraError && (
            <p className="text-sm text-red-600 mt-2">{cameraError}</p>
          )}
          {validationError && (
            <p className="text-sm text-red-600 mt-2">{validationError}</p>
          )}
        </div>
      ) : (
        <div className="relative rounded-lg overflow-hidden bg-black">
          {/* Video preview */}
          <video
            ref={videoRef}
            autoPlay
            playsInline
            muted
            className="w-full h-auto max-h-96 object-contain"
          />

          {/* Recording indicator */}
          {isRecording && (
            <div className="absolute top-3 left-3 flex items-center gap-2 bg-red-600 text-white px-3 py-1 rounded-full text-sm">
              <span className="w-2 h-2 bg-white rounded-full animate-pulse" />
              <span>REC {formatDuration(recordingTime)}</span>
            </div>
          )}

          {/* Camera controls */}
          <div className="absolute bottom-0 left-0 right-0 bg-gradient-to-t from-black/70 to-transparent p-4">
            <div className="flex items-center justify-center gap-4">
              {/* Switch camera button */}
              <button
                onClick={switchCamera}
                className="p-3 bg-white/20 hover:bg-white/30 rounded-full text-white transition"
                title="Switch camera"
              >
                <svg className="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                </svg>
              </button>

              {/* Photo capture button */}
              {allowedTypes.includes('image') && (
                <button
                  onClick={capturePhoto}
                  disabled={isRecording}
                  className={`p-4 rounded-full text-white transition ${isRecording ? 'bg-gray-400' : 'bg-white hover:bg-gray-200'}`}
                  title="Take photo"
                >
                  <div className={`w-10 h-10 rounded-full border-4 ${isRecording ? 'border-gray-600' : 'border-gray-800'}`} />
                </button>
              )}

              {/* Video record button */}
              {allowedTypes.includes('video') && (
                <button
                  onClick={isRecording ? stopRecording : startRecording}
                  className={`p-4 rounded-full text-white transition ${isRecording ? 'bg-red-600 hover:bg-red-700' : 'bg-red-500 hover:bg-red-600'}`}
                  title={isRecording ? 'Stop recording' : 'Start recording'}
                >
                  {isRecording ? (
                    <div className="w-6 h-6 bg-white rounded" />
                  ) : (
                    <div className="w-6 h-6 bg-white rounded-full" />
                  )}
                </button>
              )}

              {/* Close camera button */}
              <button
                onClick={stopCamera}
                className="p-3 bg-white/20 hover:bg-white/30 rounded-full text-white transition"
                title="Close camera"
              >
                <svg className="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Captured media preview */}
      {captures.length > 0 && (
        <div className="space-y-2">
          <h4 className="text-sm font-medium text-gray-700">
            Captured Evidence ({captures.length} file{captures.length !== 1 ? 's' : ''})
          </h4>
          <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-3">
            {captures.map((capture) => (
              <div key={capture.id} className="relative group rounded-lg overflow-hidden border border-gray-200">
                {capture.type === 'image' ? (
                  <img
                    src={capture.preview}
                    alt="Captured evidence"
                    className="w-full h-24 object-cover"
                  />
                ) : (
                  <div className="relative">
                    <video
                      src={capture.preview}
                      className="w-full h-24 object-cover"
                    />
                    <div className="absolute inset-0 flex items-center justify-center bg-black/30">
                      <svg className="w-8 h-8 text-white" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M8 5v14l11-7z" />
                      </svg>
                    </div>
                    {capture.duration && (
                      <span className="absolute bottom-1 right-1 bg-black/70 text-white text-xs px-1 rounded">
                        {formatDuration(capture.duration)}
                      </span>
                    )}
                  </div>
                )}

                {/* File info and remove button */}
                <div className="absolute inset-0 bg-black/50 opacity-0 group-hover:opacity-100 transition flex flex-col items-center justify-center">
                  <span className="text-white text-xs mb-2">
                    {formatFileSize(capture.size)}
                  </span>
                  <button
                    onClick={() => removeCapture(capture.id)}
                    className="p-1 bg-red-500 hover:bg-red-600 rounded text-white"
                    title="Remove"
                  >
                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                    </svg>
                  </button>
                </div>

                {/* Type badge */}
                <span className={`absolute top-1 left-1 px-1.5 py-0.5 text-xs font-medium rounded ${
                  capture.type === 'video' ? 'bg-red-100 text-red-700' : 'bg-blue-100 text-blue-700'
                }`}>
                  {capture.type === 'video' ? 'Video' : 'Photo'}
                </span>
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  )
}
