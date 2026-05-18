import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import Pusher from 'pusher-js'
import {
  ArrowPathIcon,
  ChatBubbleLeftRightIcon,
  PaperAirplaneIcon,
  PaperClipIcon,
  PlusIcon,
  XMarkIcon,
} from '@heroicons/react/24/outline'

import api from '../../../services/api'
import { messagingService } from '../../../services/messages.service'
import { useAuthStore } from '../../stores/auth.jsx'
import { useUIStore } from '../../stores/ui.jsx'

const CUSTOMER_SCOPES = new Set(['warranty_claim', 'appointment', 'estimate', 'invoice', 'workorder', 'ticket'])
const RATE_LIMIT_BACKOFF_MS = 60000

export default function ChatWidget({
  variant = 'floating',
  title = 'Staff Chat',
  subtitle = 'Internal conversations and alerts',
  className = '',
}) {
  const { isStaff, user } = useAuthStore()
  const { addChatNotification, chatNotifications, markChatNotificationRead } = useUIStore()
  const [isOpen, setIsOpen] = useState(variant !== 'floating')
  const [threads, setThreads] = useState([])
  const [selectedThreadId, setSelectedThreadId] = useState(null)
  const [messages, setMessages] = useState([])
  const [newMessage, setNewMessage] = useState('')
  const [unreadTotal, setUnreadTotal] = useState(0)
  const [sending, setSending] = useState(false)
  const [pendingFiles, setPendingFiles] = useState([])
  const [showNewThread, setShowNewThread] = useState(false)
  const [participants, setParticipants] = useState([])
  const [participantQuery, setParticipantQuery] = useState('')
  const [selectedParticipants, setSelectedParticipants] = useState([])
  const [newThreadSubject, setNewThreadSubject] = useState('')
  const [newThreadMessage, setNewThreadMessage] = useState('')
  const [creatingThread, setCreatingThread] = useState(false)
  const [realtimeStatus, setRealtimeStatus] = useState('disabled')
  const rateLimitUntilRef = useRef(0)
  const threadsRef = useRef([])
  const unreadCountsRef = useRef(new Map())
  const threadStateRef = useRef({ last_message_id: 0, last_read_update: null })
  const isOpenRef = useRef(isOpen)
  const selectedThreadIdRef = useRef(selectedThreadId)
  const showNewThreadRef = useRef(showNewThread)

  const currentUserId = user?.id
  const userRole = user?.role?.toLowerCase()
  const isInternalUser = Boolean(isStaff && userRole !== 'customer' && userRole !== 'portal_user')
  const selectedThread = useMemo(
    () => threads.find((thread) => thread.id === selectedThreadId) || null,
    [threads, selectedThreadId]
  )
  const unreadNotifications = useMemo(
    () => chatNotifications.filter((notification) => !notification.read),
    [chatNotifications]
  )
  const isFloating = variant === 'floating'
  const websocketConfig = useMemo(
    () => ({
      key: import.meta.env.VITE_PUSHER_KEY,
      cluster: import.meta.env.VITE_PUSHER_CLUSTER,
      host: import.meta.env.VITE_PUSHER_HOST,
      channel: currentUserId ? `private-messages-user-${currentUserId}` : null,
      wsPort: Number(import.meta.env.VITE_PUSHER_PORT || 6001),
      forceTLS: (import.meta.env.VITE_PUSHER_FORCE_TLS || 'false') === 'true',
    }),
    [currentUserId]
  )

  const toggleOpen = () => {
    setIsOpen((prev) => {
      const next = !prev
      isOpenRef.current = next
      return next
    })
  }

  useEffect(() => {
    isOpenRef.current = isOpen
  }, [isOpen])

  useEffect(() => {
    selectedThreadIdRef.current = selectedThreadId
  }, [selectedThreadId])

  useEffect(() => {
    showNewThreadRef.current = showNewThread
  }, [showNewThread])

  const parseRealtimePayload = useCallback((data) => {
    if (typeof data !== 'string') {
      return data || null
    }

    try {
      return JSON.parse(data)
    } catch {
      return null
    }
  }, [])

  const isRateLimited = useCallback(() => Date.now() < rateLimitUntilRef.current, [])

  const trackRateLimit = useCallback((error, context) => {
    if (error.response?.status !== 429) {
      return false
    }

    const headerValue = error.response?.headers?.['retry-after']
    const payloadValue = error.response?.data?.retry_after
    const retryAfterSeconds = Number(headerValue ?? payloadValue)
    const backoffMs = Number.isFinite(retryAfterSeconds) && retryAfterSeconds > 0
      ? retryAfterSeconds * 1000
      : RATE_LIMIT_BACKOFF_MS

    rateLimitUntilRef.current = Math.max(rateLimitUntilRef.current, Date.now() + backoffMs)
    console.warn(`Rate limited on ${context}, backing off before retry`)
    return true
  }, [])

  const participantName = (participant) => participant?.name || participant?.email || 'Staff'

  const senderName = (message) => message?.name || message?.email || 'Staff'

  const roleLabel = (role) => {
    if (!role) return 'Staff'
    return role
      .split('_')
      .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
      .join(' ')
  }

  const realtimeMeta = () => {
    if (realtimeStatus === 'connected') {
      return { label: 'Live', className: 'bg-emerald-500' }
    }
    if (realtimeStatus === 'connecting') {
      return { label: 'Connecting', className: 'bg-amber-500' }
    }
    return { label: 'Offline', className: 'bg-gray-400' }
  }

  const scopeMeta = (thread) => {
    if (thread?.scope_type === 'department') {
      return {
        label: 'System',
        className: 'bg-amber-100 text-amber-700',
      }
    }

    if (
      CUSTOMER_SCOPES.has(thread?.scope_type) ||
      thread?.ticket_id ||
      thread?.workorder_id
    ) {
      return {
        label: 'Customer',
        className: 'bg-emerald-100 text-emerald-700',
      }
    }

    return {
      label: 'Internal',
      className: 'bg-slate-100 text-slate-700',
    }
  }

  const threadLabel = useCallback(
    (thread) => {
      if (thread.subject) {
        return thread.subject
      }

      const participants = thread.participants || []
      const otherNames = participants
        .filter((participant) => participant.id !== currentUserId)
        .map(participantName)
        .filter(Boolean)

      return otherNames.join(', ') || `Thread #${thread.id}`
    },
    [currentUserId]
  )

  const formatTimestamp = (value) => {
    if (!value) return ''
    const date = new Date(value)
    return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
  }

  const formatFileSize = (bytes) => {
    if (!bytes) return ''
    if (bytes < 1024) return `${bytes} B`
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
  }

  const handleFileChange = (event) => {
    const files = Array.from(event.target.files || [])
    if (files.length) {
      setPendingFiles((prev) => [...prev, ...files])
    }
    event.target.value = ''
  }

  const removePendingFile = (indexToRemove) => {
    setPendingFiles((prev) => prev.filter((_, index) => index !== indexToRemove))
  }

  const resetNewThread = () => {
    setSelectedParticipants([])
    setNewThreadSubject('')
    setNewThreadMessage('')
    setParticipantQuery('')
  }

  const refreshThreads = useCallback(async () => {
    if (isRateLimited()) {
      return selectedThreadIdRef.current
    }

    try {
      const data = await messagingService.listThreads()
      threadsRef.current = data
      setThreads(data)

      if (!selectedThreadIdRef.current && data.length && !showNewThreadRef.current) {
        setSelectedThreadId(data[0].id)
        selectedThreadIdRef.current = data[0].id
        return data[0].id
      }

      return selectedThreadIdRef.current
    } catch (error) {
      trackRateLimit(error, 'threads fetch')
      return selectedThreadIdRef.current
    }
  }, [isRateLimited, trackRateLimit])

  const upsertThread = useCallback((thread) => {
    if (!thread?.id) return

    setThreads((prev) => {
      const nextThreads = [thread, ...prev.filter((item) => item.id !== thread.id)]
      threadsRef.current = nextThreads
      return nextThreads
    })
  }, [])

  const applyUnreadSnapshot = useCallback(
    (data, { notify = true } = {}) => {
      const nextTotal = data?.total || 0
      setUnreadTotal(nextTotal)

      if (!Array.isArray(data?.threads)) {
        return
      }

      const lookup = new Map(data.threads.map((item) => [Number(item.thread_id), Number(item.unread_count) || 0]))
      const previousLookup = unreadCountsRef.current

      if (notify && previousLookup.size > 0) {
        lookup.forEach((count, threadId) => {
          const previousCount = previousLookup.get(threadId) ?? 0
          if (count > previousCount && (!isOpenRef.current || threadId !== selectedThreadIdRef.current)) {
            const thread = threadsRef.current.find((item) => Number(item.id) === threadId)
            const label = thread ? threadLabel(thread) : `Thread #${threadId}`
            const delta = count - previousCount
            addChatNotification({
              title: 'New message',
              body: `${label} has ${delta} new message${delta > 1 ? 's' : ''}.`,
              threadId,
            })
          }
        })
      }

      unreadCountsRef.current = lookup
      setThreads((prev) => {
        const nextThreads = prev.map((thread) => ({
          ...thread,
          unread_count: lookup.get(Number(thread.id)) ?? thread.unread_count ?? 0,
        }))
        threadsRef.current = nextThreads
        return nextThreads
      })
    },
    [addChatNotification, threadLabel]
  )

  const refreshUnreadCounts = useCallback(async () => {
    if (isRateLimited()) {
      return
    }

    try {
      const data = await messagingService.unreadCounts()
      applyUnreadSnapshot(data)
    } catch (error) {
      trackRateLimit(error, 'unread counts fetch')
    }
  }, [applyUnreadSnapshot, isRateLimited, trackRateLimit])

  const loadMessages = useCallback(async (threadId) => {
    if (!threadId) {
      setMessages([])
      return
    }
    if (isRateLimited()) {
      return
    }

    try {
      const data = await messagingService.listMessages(threadId)
      setMessages(data)
    } catch (error) {
      trackRateLimit(error, 'messages fetch')
    }
  }, [isRateLimited, trackRateLimit])

  const markRead = useCallback(
    async (threadId) => {
      if (!threadId) return
      if (isRateLimited()) return
      try {
        await messagingService.markRead(threadId)
        await refreshUnreadCounts()
      } catch (error) {
        trackRateLimit(error, 'mark read')
      }
    },
    [isRateLimited, refreshUnreadCounts, trackRateLimit]
  )

  const refreshThreadState = useCallback(
    async (threadId, { silent } = {}) => {
      if (!threadId) return
      if (isRateLimited()) return

      try {
        const nextState = await messagingService.threadState(threadId)
        const previousState = threadStateRef.current
        const hasPrevious = previousState.last_message_id || previousState.last_read_update
        const hasChanged =
          nextState.last_message_id !== previousState.last_message_id ||
          nextState.last_read_update !== previousState.last_read_update

        threadStateRef.current = nextState

        if (hasPrevious && hasChanged && !silent) {
          await loadMessages(threadId)
          await markRead(threadId)
          await refreshThreads()
          await refreshUnreadCounts()
        }
      } catch (error) {
        trackRateLimit(error, 'thread state fetch')
      }
    },
    [isRateLimited, loadMessages, markRead, refreshThreads, refreshUnreadCounts, trackRateLimit]
  )

  const loadParticipants = useCallback(async () => {
    if (isRateLimited()) {
      return
    }

    try {
      const data = await messagingService.listParticipants(participantQuery.trim())
      setParticipants(data)
    } catch (error) {
      trackRateLimit(error, 'participants fetch')
    }
  }, [isRateLimited, participantQuery, trackRateLimit])

  const selectThread = async (thread) => {
    setShowNewThread(false)
    showNewThreadRef.current = false
    setSelectedThreadId(thread.id)
    selectedThreadIdRef.current = thread.id
    await loadMessages(thread.id)
    await markRead(thread.id)
    await refreshThreadState(thread.id, { silent: true })
  }

  const startNewThread = () => {
    setShowNewThread(true)
    showNewThreadRef.current = true
    setSelectedThreadId(null)
    selectedThreadIdRef.current = null
    setMessages([])
    loadParticipants()
  }

  const toggleParticipant = (participantId) => {
    setSelectedParticipants((prev) =>
      prev.includes(participantId)
        ? prev.filter((id) => id !== participantId)
        : [...prev, participantId]
    )
  }

  const createThread = async () => {
    if (!selectedParticipants.length || !newThreadMessage.trim()) return

    setCreatingThread(true)
    try {
      const thread = await messagingService.createThread({
        participant_ids: selectedParticipants,
        subject: newThreadSubject.trim() || null,
        message: newThreadMessage.trim(),
      })
      resetNewThread()
      setShowNewThread(false)
      showNewThreadRef.current = false
      setThreads((prev) => {
        const nextThreads = [thread, ...prev.filter((item) => item.id !== thread.id)]
        threadsRef.current = nextThreads
        return nextThreads
      })
      setSelectedThreadId(thread.id)
      selectedThreadIdRef.current = thread.id
      await loadMessages(thread.id)
      await markRead(thread.id)
      await refreshThreads()
    } finally {
      setCreatingThread(false)
    }
  }

  const sendMessage = async () => {
    if (!selectedThreadId || (!newMessage.trim() && pendingFiles.length === 0)) return
    setSending(true)
    try {
      if (pendingFiles.length) {
        await messagingService.postMessageWithAttachments(selectedThreadId, {
          body: newMessage.trim(),
          files: pendingFiles,
        })
        setPendingFiles([])
      } else {
        await messagingService.postMessage(selectedThreadId, { body: newMessage.trim() })
      }
      setNewMessage('')
      await loadMessages(selectedThreadId)
      await refreshThreads()
      await refreshUnreadCounts()
    } finally {
      setSending(false)
    }
  }

  useEffect(() => {
    if (!isOpen || !showNewThread) return undefined

    const timer = setTimeout(() => {
      loadParticipants()
    }, 200)

    return () => clearTimeout(timer)
  }, [isOpen, loadParticipants, showNewThread])

  useEffect(() => {
    if (!isOpen) return

    const handleOpen = async () => {
      const activeThreadId = await refreshThreads()
      if (activeThreadId) {
        await loadMessages(activeThreadId)
        await markRead(activeThreadId)
        await refreshThreadState(activeThreadId, { silent: true })
      }
    }

    handleOpen()
  }, [isOpen, loadMessages, markRead, refreshThreadState, refreshThreads])

  useEffect(() => {
    if (!isInternalUser) return undefined

    const initialize = async () => {
      await refreshThreads()
      await refreshUnreadCounts()
    }

    initialize()
  }, [isInternalUser, refreshThreads, refreshUnreadCounts])

  useEffect(() => {
    if (!isInternalUser || !currentUserId || !websocketConfig.key || !websocketConfig.channel) {
      setRealtimeStatus(websocketConfig.key ? 'disabled' : 'unconfigured')
      return undefined
    }

    setRealtimeStatus('connecting')

    const pusher = new Pusher(websocketConfig.key, {
      cluster: websocketConfig.cluster || undefined,
      wsHost: websocketConfig.host || undefined,
      wsPort: websocketConfig.wsPort,
      wssPort: websocketConfig.wsPort,
      forceTLS: websocketConfig.forceTLS,
      enabledTransports: ['ws', 'wss'],
      disableStats: true,
      authorizer: (channel) => ({
        authorize: async (socketId, callback) => {
          try {
            const response = await api.post('/messages/realtime/auth', {
              socket_id: socketId,
              channel_name: channel.name,
            })
            callback(null, response.data)
          } catch (error) {
            callback(error, null)
          }
        },
      }),
    })

    const channel = pusher.subscribe(websocketConfig.channel)

    const handleMessageCreated = async (data) => {
      const payload = parseRealtimePayload(data)
      if (!payload?.thread_id) return

      if (payload.thread) {
        upsertThread(payload.thread)
      }
      if (payload.unread) {
        applyUnreadSnapshot(payload.unread)
      }

      const message = payload.message
      const threadId = Number(payload.thread_id)
      if (!message?.id || !Number.isFinite(threadId)) {
        return
      }

      const activeThreadOpen =
        isOpenRef.current &&
        !showNewThreadRef.current &&
        Number(selectedThreadIdRef.current) === threadId

      if (!activeThreadOpen) {
        return
      }

      setMessages((prev) => {
        if (prev.some((item) => Number(item.id) === Number(message.id))) {
          return prev
        }
        return [...prev, message]
      })

      if (Number(message.sender_id) !== Number(currentUserId)) {
        await markRead(threadId)
      }
    }

    const handleMessageRead = async (data) => {
      const payload = parseRealtimePayload(data)
      if (!payload?.thread_id) return

      if (payload.unread) {
        applyUnreadSnapshot(payload.unread, { notify: false })
      }
      if (payload.state) {
        threadStateRef.current = payload.state
      }

      const threadId = Number(payload.thread_id)
      const activeThreadOpen =
        isOpenRef.current &&
        !showNewThreadRef.current &&
        Number(selectedThreadIdRef.current) === threadId

      if (activeThreadOpen && Number(payload.participant_id) !== Number(currentUserId)) {
        await loadMessages(threadId)
      }
    }

    const handleConnected = () => {
      setRealtimeStatus('connected')
      refreshThreads()
      refreshUnreadCounts()
    }
    const handleDisconnected = () => setRealtimeStatus('unavailable')

    channel.bind('message.created', handleMessageCreated)
    channel.bind('message.read', handleMessageRead)
    channel.bind('pusher:subscription_error', handleDisconnected)
    pusher.connection.bind('connected', handleConnected)
    pusher.connection.bind('error', handleDisconnected)
    pusher.connection.bind('unavailable', handleDisconnected)
    pusher.connection.bind('disconnected', handleDisconnected)

    return () => {
      channel.unbind('message.created', handleMessageCreated)
      channel.unbind('message.read', handleMessageRead)
      channel.unbind('pusher:subscription_error', handleDisconnected)
      pusher.connection.unbind('connected', handleConnected)
      pusher.connection.unbind('error', handleDisconnected)
      pusher.connection.unbind('unavailable', handleDisconnected)
      pusher.connection.unbind('disconnected', handleDisconnected)
      pusher.unsubscribe(websocketConfig.channel)
      pusher.disconnect()
    }
  }, [
    applyUnreadSnapshot,
    currentUserId,
    isInternalUser,
    loadMessages,
    markRead,
    parseRealtimePayload,
    refreshThreads,
    refreshUnreadCounts,
    upsertThread,
    websocketConfig,
  ])

  if (!isInternalUser) {
    return null
  }

  return (
    <div className={isFloating ? 'fixed bottom-6 right-6 z-50' : `w-full ${className}`}>
      {isFloating && unreadNotifications.length ? (
        <div className="mb-3 flex max-h-60 w-80 flex-col gap-2 overflow-y-auto">
          {unreadNotifications.slice(0, 3).map((notification) => (
            <button
              key={notification.id}
              type="button"
              className="rounded-lg border border-blue-100 bg-white px-3 py-2 text-left text-sm text-gray-700 shadow-lg transition hover:border-blue-200 hover:bg-blue-50"
              onClick={async () => {
                markChatNotificationRead(notification.id)
                if (notification.threadId) {
                  setIsOpen(true)
                  isOpenRef.current = true
                  setShowNewThread(false)
                  showNewThreadRef.current = false
                  setSelectedThreadId(notification.threadId)
                  selectedThreadIdRef.current = notification.threadId
                  await loadMessages(notification.threadId)
                  await markRead(notification.threadId)
                  await refreshThreadState(notification.threadId, { silent: true })
                  await refreshThreads()
                }
              }}
            >
              <p className="text-xs font-semibold text-blue-600">{notification.title}</p>
              <p className="mt-1 text-xs text-gray-600">{notification.body}</p>
            </button>
          ))}
        </div>
      ) : null}

      {isFloating ? (
        <button
          type="button"
          className="relative flex h-14 w-14 items-center justify-center rounded-full bg-blue-600 text-white shadow-lg transition hover:bg-blue-700"
          onClick={toggleOpen}
        >
          <span className="sr-only">Open chat</span>
          <ChatBubbleLeftRightIcon className="h-6 w-6" />
          {unreadTotal > 0 ? (
            <span className="absolute -right-1 -top-1 flex h-6 min-w-[1.5rem] items-center justify-center rounded-full bg-red-500 px-1 text-xs font-semibold">
              {unreadTotal}
            </span>
          ) : null}
          {unreadNotifications.length ? (
            <span className="absolute -left-1 -top-1 flex h-3 w-3">
              <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-blue-400 opacity-75" />
              <span className="relative inline-flex h-3 w-3 rounded-full bg-blue-500" />
            </span>
          ) : null}
        </button>
      ) : null}

      {isOpen ? (
        <div
          className={`${isFloating ? 'mt-4 h-[34rem] w-[27rem] max-w-[calc(100vw-2rem)] shadow-2xl' : 'h-[34rem] shadow-sm'} flex flex-col overflow-hidden rounded-lg border border-gray-200 bg-white`}
        >
          <div className="flex items-center justify-between border-b border-gray-200 px-4 py-3">
            <div className="min-w-0">
              <p className="truncate text-sm font-semibold text-gray-900">{title}</p>
              <p className="flex items-center gap-2 truncate text-xs text-gray-500">
                <span className="truncate">{subtitle}</span>
                <span className="inline-flex shrink-0 items-center gap-1">
                  <span className={`h-2 w-2 rounded-full ${realtimeMeta().className}`} />
                  <span>{realtimeMeta().label}</span>
                </span>
              </p>
            </div>
            {isFloating ? (
              <button
                type="button"
                className="rounded-full p-1 text-gray-500 transition hover:bg-gray-100"
                onClick={() => {
                  setIsOpen(false)
                  isOpenRef.current = false
                }}
              >
                <span className="sr-only">Close</span>
                <XMarkIcon className="h-5 w-5" />
              </button>
            ) : null}
          </div>

          <div className="flex flex-1 overflow-hidden">
            <aside className="w-44 shrink-0 border-r border-gray-200 bg-gray-50">
              <div className="flex items-center justify-between gap-2 px-3 py-2">
                <span className="text-xs font-semibold uppercase text-gray-500">Threads</span>
                <div className="flex items-center gap-1">
                  <button
                    type="button"
                    className="rounded-full p-1 text-gray-500 transition hover:bg-white hover:text-blue-600"
                    onClick={refreshThreads}
                    title="Refresh"
                  >
                    <span className="sr-only">Refresh</span>
                    <ArrowPathIcon className="h-4 w-4" />
                  </button>
                  <button
                    type="button"
                    className="rounded-full bg-blue-600 p-1 text-white transition hover:bg-blue-700"
                    onClick={startNewThread}
                    title="New conversation"
                  >
                    <span className="sr-only">New conversation</span>
                    <PlusIcon className="h-4 w-4" />
                  </button>
                </div>
              </div>
              <div className="h-full overflow-y-auto pb-10">
                {threads.map((thread) => {
                  const meta = scopeMeta(thread)
                  return (
                    <button
                      key={thread.id}
                      type="button"
                      className={`flex w-full flex-col gap-1 border-b border-gray-100 px-3 py-2 text-left text-xs transition hover:bg-white ${
                        selectedThreadId === thread.id && !showNewThread
                          ? 'bg-white text-gray-900'
                          : 'text-gray-600'
                      }`}
                      onClick={() => selectThread(thread)}
                    >
                      <span className="line-clamp-2 font-semibold">{threadLabel(thread)}</span>
                      <span className="flex flex-wrap items-center gap-1">
                        <span className={`rounded-full px-2 py-0.5 text-[10px] font-semibold ${meta.className}`}>
                          {meta.label}
                        </span>
                        {thread.unread_count ? (
                          <span className="rounded-full bg-blue-100 px-2 py-0.5 text-[10px] font-semibold text-blue-700">
                            {thread.unread_count} new
                          </span>
                        ) : null}
                      </span>
                      <span className="truncate text-[11px] text-gray-500">
                        {thread.last_message || 'No messages yet'}
                      </span>
                    </button>
                  )
                })}
                {!threads.length ? (
                  <div className="px-3 py-6 text-center text-xs text-gray-400">
                    No conversations yet.
                  </div>
                ) : null}
              </div>
            </aside>

            <section className="flex min-w-0 flex-1 flex-col">
              {showNewThread ? (
                <div className="flex h-full flex-col overflow-y-auto px-4 py-3">
                  <div className="mb-3 flex items-center justify-between gap-3">
                    <div>
                      <p className="text-sm font-semibold text-gray-900">New conversation</p>
                      <p className="text-xs text-gray-500">Choose internal users and send the first message.</p>
                    </div>
                    <button
                      type="button"
                      className="rounded-full p-1 text-gray-500 transition hover:bg-gray-100"
                    onClick={() => {
                      setShowNewThread(false)
                      showNewThreadRef.current = false
                      resetNewThread()
                      if (threads.length) {
                        setSelectedThreadId(threads[0].id)
                        selectedThreadIdRef.current = threads[0].id
                      }
                    }}
                    >
                      <span className="sr-only">Cancel</span>
                      <XMarkIcon className="h-5 w-5" />
                    </button>
                  </div>

                  <input
                    type="text"
                    value={participantQuery}
                    onChange={(event) => setParticipantQuery(event.target.value)}
                    className="mb-2 rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100"
                    placeholder="Search people"
                  />

                  <div className="mb-3 max-h-40 overflow-y-auto rounded-lg border border-gray-200">
                    {participants.map((participant) => {
                      const selected = selectedParticipants.includes(participant.id)
                      return (
                        <button
                          key={participant.id}
                          type="button"
                          className={`flex w-full items-center justify-between gap-3 border-b border-gray-100 px-3 py-2 text-left text-sm last:border-b-0 ${
                            selected ? 'bg-blue-50 text-blue-700' : 'text-gray-700 hover:bg-gray-50'
                          }`}
                          onClick={() => toggleParticipant(participant.id)}
                        >
                          <span className="min-w-0">
                            <span className="block truncate font-medium">{participantName(participant)}</span>
                            <span className="block truncate text-xs text-gray-500">
                              {participant.email} - {roleLabel(participant.role)}
                            </span>
                          </span>
                          <span
                            className={`h-4 w-4 shrink-0 rounded-full border ${
                              selected ? 'border-blue-600 bg-blue-600' : 'border-gray-300'
                            }`}
                          />
                        </button>
                      )
                    })}
                    {!participants.length ? (
                      <div className="px-3 py-6 text-center text-xs text-gray-400">
                        No internal users found.
                      </div>
                    ) : null}
                  </div>

                  <input
                    type="text"
                    value={newThreadSubject}
                    onChange={(event) => setNewThreadSubject(event.target.value)}
                    className="mb-2 rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100"
                    placeholder="Subject"
                  />
                  <textarea
                    value={newThreadMessage}
                    onChange={(event) => setNewThreadMessage(event.target.value)}
                    className="min-h-[7rem] flex-1 resize-none rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100"
                    placeholder="Message"
                  />
                  <div className="mt-3 flex items-center justify-end gap-2">
                    <button
                      type="button"
                      className="rounded-lg border border-gray-200 px-3 py-2 text-sm font-semibold text-gray-700 transition hover:bg-gray-50"
                      onClick={() => {
                        setShowNewThread(false)
                        showNewThreadRef.current = false
                        resetNewThread()
                      }}
                    >
                      Cancel
                    </button>
                    <button
                      type="button"
                      className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-3 py-2 text-sm font-semibold text-white transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:bg-blue-300"
                      disabled={!selectedParticipants.length || !newThreadMessage.trim() || creatingThread}
                      onClick={createThread}
                    >
                      <PaperAirplaneIcon className="h-4 w-4" />
                      Send
                    </button>
                  </div>
                </div>
              ) : (
                <>
                  <div className="flex-1 space-y-4 overflow-y-auto px-4 py-3">
                    {!selectedThread ? (
                      <div className="flex h-full items-center justify-center text-sm text-gray-400">
                        Select a thread to view messages.
                      </div>
                    ) : null}
                    {messages.map((message) => (
                      <div
                        key={message.id}
                        className={`flex ${
                          message.sender_id === currentUserId ? 'justify-end' : 'justify-start'
                        }`}
                      >
                        <div
                          className={`max-w-[78%] rounded-2xl px-3 py-2 text-sm ${
                            message.sender_id === currentUserId
                              ? 'bg-blue-600 text-white'
                              : 'bg-gray-100 text-gray-800'
                          }`}
                        >
                          <p className="text-[11px] font-semibold opacity-80">
                            {message.sender_id === currentUserId ? 'You' : senderName(message)}
                          </p>
                          <p className="whitespace-pre-wrap break-words">{message.body}</p>
                          {message.attachments?.length ? (
                            <div className="mt-2 space-y-1">
                              {message.attachments.map((attachment) => (
                                <a
                                  key={attachment.id}
                                  href={attachment.file_path}
                                  target="_blank"
                                  rel="noreferrer"
                                  className={`flex items-center gap-2 rounded-lg px-2 py-1 text-xs ${
                                    message.sender_id === currentUserId
                                      ? 'bg-blue-500/30 text-white'
                                      : 'bg-white text-gray-700'
                                  }`}
                                >
                                  <PaperClipIcon className="h-4 w-4" />
                                  <span className="truncate">{attachment.file_name}</span>
                                  {attachment.size_bytes ? (
                                    <span className="opacity-70">({formatFileSize(attachment.size_bytes)})</span>
                                  ) : null}
                                </a>
                              ))}
                            </div>
                          ) : null}
                          <div className="mt-1 flex items-center justify-between gap-3 text-[10px] opacity-70">
                            <span>{formatTimestamp(message.created_at)}</span>
                            {message.sender_id === currentUserId && message.recipient_count ? (
                              <span className="truncate">
                                {message.read_count === message.recipient_count
                                  ? `Read by ${message.read_by?.map((reader) => reader.name).join(', ') || 'all'}`
                                  : `${message.read_count}/${message.recipient_count} read`}
                              </span>
                            ) : null}
                          </div>
                        </div>
                      </div>
                    ))}
                  </div>

                  <form
                    className="border-t border-gray-200 px-3 py-2"
                    onSubmit={(event) => {
                      event.preventDefault()
                      sendMessage()
                    }}
                  >
                    {pendingFiles.length ? (
                      <div className="mb-2 flex flex-wrap gap-2">
                        {pendingFiles.map((file, index) => (
                          <span
                            key={`${file.name}-${index}`}
                            className="flex items-center gap-2 rounded-full bg-gray-100 px-3 py-1 text-xs text-gray-700"
                          >
                            <span className="max-w-[140px] truncate">{file.name}</span>
                            <button
                              type="button"
                              className="text-gray-400 hover:text-gray-600"
                              onClick={() => removePendingFile(index)}
                            >
                              <XMarkIcon className="h-3 w-3" />
                            </button>
                          </span>
                        ))}
                      </div>
                    ) : null}
                    <div className="flex items-center gap-2">
                      <label className="flex h-10 w-10 cursor-pointer items-center justify-center rounded-full border border-gray-200 text-gray-500 transition hover:bg-gray-100">
                        <span className="sr-only">Attach files</span>
                        <PaperClipIcon className="h-5 w-5" />
                        <input type="file" multiple className="hidden" onChange={handleFileChange} />
                      </label>
                      <input
                        value={newMessage}
                        onChange={(event) => setNewMessage(event.target.value)}
                        type="text"
                        disabled={!selectedThread}
                        className="min-w-0 flex-1 rounded-full border border-gray-200 px-4 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100 disabled:bg-gray-100"
                        placeholder="Type a message"
                      />
                      <button
                        type="submit"
                        className="inline-flex h-10 w-10 items-center justify-center rounded-full bg-blue-600 text-white transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:bg-blue-300"
                        disabled={!selectedThread || sending}
                      >
                        <span className="sr-only">Send</span>
                        <PaperAirplaneIcon className="h-5 w-5" />
                      </button>
                    </div>
                  </form>
                </>
              )}
            </section>
          </div>
        </div>
      ) : null}
    </div>
  )
}
