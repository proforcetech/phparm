import { useCallback, useEffect, useMemo, useRef, useState } from 'react'

import { messagingService } from '../../../services/messages.service'
import { useAuthStore } from '../../stores/auth.jsx'
import { useUIStore } from '../../stores/ui.jsx'

export default function ChatWidget() {
  const { isStaff, user } = useAuthStore()
  const { addChatNotification, chatNotifications, markChatNotificationRead } = useUIStore()
  const [isOpen, setIsOpen] = useState(false)
  const [threads, setThreads] = useState([])
  const [selectedThreadId, setSelectedThreadId] = useState(null)
  const [messages, setMessages] = useState([])
  const [newMessage, setNewMessage] = useState('')
  const [unreadTotal, setUnreadTotal] = useState(0)
  const [sending, setSending] = useState(false)
  const pollingRef = useRef(null)
  const unreadCountsRef = useRef(new Map())

  const currentUserId = user?.id
  const selectedThread = useMemo(
    () => threads.find((thread) => thread.id === selectedThreadId) || null,
    [threads, selectedThreadId]
  )

  const unreadNotifications = useMemo(
    () => chatNotifications.filter((notification) => !notification.read),
    [chatNotifications]
  )

  const toggleOpen = () => {
    setIsOpen((prev) => !prev)
  }

  const senderName = (message) => {
    return `${message.first_name ?? ''} ${message.last_name ?? ''}`.trim() || 'Staff'
  }

  const threadLabel = useCallback(
    (thread) => {
      if (thread.subject) {
        return thread.subject
      }

      const participants = thread.participants || []
      const otherNames = participants
        .filter((participant) => participant.id !== currentUserId)
        .map(
          (participant) =>
            participant.name ||
            `${participant.first_name ?? ''} ${participant.last_name ?? ''}`.trim()
        )
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

  const refreshThreads = useCallback(async () => {
    const data = await messagingService.listThreads()
    setThreads(data)

    if (!selectedThreadId && data.length) {
      setSelectedThreadId(data[0].id)
      return data[0].id
    }

    return selectedThreadId
  }, [selectedThreadId])

  const refreshUnreadCounts = useCallback(async () => {
    const data = await messagingService.unreadCounts()
    const nextTotal = data.total || 0
    setUnreadTotal(nextTotal)

    if (Array.isArray(data.threads)) {
      const lookup = new Map(data.threads.map((item) => [item.thread_id, item.unread_count]))
      const previousLookup = unreadCountsRef.current

      if (previousLookup.size > 0) {
        lookup.forEach((count, threadId) => {
          const previousCount = previousLookup.get(threadId) ?? 0
          if (count > previousCount && (!isOpen || threadId !== selectedThreadId)) {
            const thread = threads.find((item) => item.id === threadId)
            const label = thread ? threadLabel(thread) : `Thread #${threadId}`
            const delta = count - previousCount
            addChatNotification({
              title: 'New chat message',
              body: `${label} has ${delta} new message${delta > 1 ? 's' : ''}.`,
              threadId,
            })
          }
        })
      }

      unreadCountsRef.current = lookup
      setThreads((prev) =>
        prev.map((thread) => ({
          ...thread,
          unread_count: lookup.get(thread.id) ?? thread.unread_count ?? 0,
        }))
      )
    }
  }, [addChatNotification, isOpen, selectedThreadId, threadLabel, threads])

  const loadMessages = useCallback(async (threadId) => {
    if (!threadId) {
      setMessages([])
      return
    }

    const data = await messagingService.listMessages(threadId)
    setMessages(data)
  }, [])

  const markRead = useCallback(
    async (threadId) => {
      if (!threadId) return
      await messagingService.markRead(threadId)
      await refreshUnreadCounts()
    },
    [refreshUnreadCounts]
  )

  const selectThread = async (thread) => {
    setSelectedThreadId(thread.id)
    await loadMessages(thread.id)
    await markRead(thread.id)
  }

  const sendMessage = async () => {
    if (!selectedThreadId || !newMessage.trim()) return
    setSending(true)
    try {
      await messagingService.postMessage(selectedThreadId, { body: newMessage.trim() })
      setNewMessage('')
      await loadMessages(selectedThreadId)
      await refreshThreads()
      await refreshUnreadCounts()
    } finally {
      setSending(false)
    }
  }

  useEffect(() => {
    if (!isOpen) return

    const handleOpen = async () => {
      const activeThreadId = await refreshThreads()
      if (activeThreadId) {
        await loadMessages(activeThreadId)
        await markRead(activeThreadId)
      }
    }

    handleOpen()
  }, [isOpen, loadMessages, markRead, refreshThreads])

  useEffect(() => {
    if (!isStaff) return undefined

    const initialize = async () => {
      await refreshThreads()
      await refreshUnreadCounts()
    }

    initialize()

    pollingRef.current = setInterval(async () => {
      await refreshUnreadCounts()
      if (isOpen) {
        await refreshThreads()
        if (selectedThreadId) {
          await loadMessages(selectedThreadId)
          await markRead(selectedThreadId)
        }
      }
    }, 15000)

    return () => {
      if (pollingRef.current) {
        clearInterval(pollingRef.current)
      }
    }
  }, [isOpen, isStaff, loadMessages, refreshThreads, refreshUnreadCounts, selectedThreadId])

  if (!isStaff) {
    return null
  }

  return (
    <div className="fixed bottom-6 right-6 z-50">
      {unreadNotifications.length ? (
        <div className="mb-3 flex max-h-60 w-72 flex-col gap-2 overflow-y-auto">
          {unreadNotifications.slice(0, 3).map((notification) => (
            <button
              key={notification.id}
              type="button"
              className="rounded-2xl border border-blue-100 bg-white px-3 py-2 text-left text-sm text-gray-700 shadow-lg transition hover:border-blue-200 hover:bg-blue-50"
              onClick={async () => {
                markChatNotificationRead(notification.id)
                if (notification.threadId) {
                  setIsOpen(true)
                  setSelectedThreadId(notification.threadId)
                  await loadMessages(notification.threadId)
                  await markRead(notification.threadId)
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
      <button
        type="button"
        className="relative flex h-14 w-14 items-center justify-center rounded-full bg-blue-600 text-white shadow-lg transition hover:bg-blue-700"
        onClick={toggleOpen}
      >
        <span className="sr-only">Open chat</span>
        <svg className="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
          <path
            strokeLinecap="round"
            strokeLinejoin="round"
            d="M8 10h8M8 14h5M21 12c0 4.418-4.03 8-9 8a9.72 9.72 0 0 1-4-.84L3 20l1.09-3.27A7.8 7.8 0 0 1 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8Z"
          />
        </svg>
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

      {isOpen ? (
        <div className="mt-4 flex h-[32rem] w-[22rem] flex-col overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-2xl">
          <div className="flex items-center justify-between border-b border-gray-200 px-4 py-3">
            <div>
              <p className="text-sm font-semibold text-gray-900">Staff Chat</p>
              <p className="text-xs text-gray-500">Internal conversations</p>
            </div>
            <button
              type="button"
              className="rounded-full p-1 text-gray-500 transition hover:bg-gray-100"
              onClick={() => setIsOpen(false)}
            >
              <span className="sr-only">Close</span>
              <svg className="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                <path
                  fillRule="evenodd"
                  d="M4.293 4.293a1 1 0 0 1 1.414 0L10 8.586l4.293-4.293a1 1 0 1 1 1.414 1.414L11.414 10l4.293 4.293a1 1 0 0 1-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 0 1-1.414-1.414L8.586 10 4.293 5.707a1 1 0 0 1 0-1.414Z"
                  clipRule="evenodd"
                />
              </svg>
            </button>
          </div>

          <div className="flex flex-1 overflow-hidden">
            <aside className="w-32 border-r border-gray-200 bg-gray-50">
              <div className="flex items-center justify-between px-3 py-2">
                <span className="text-xs font-semibold uppercase text-gray-500">Threads</span>
                <button type="button" className="text-xs text-blue-600" onClick={refreshThreads}>
                  Refresh
                </button>
              </div>
              <div className="h-full overflow-y-auto">
                {threads.map((thread) => (
                  <button
                    key={thread.id}
                    type="button"
                    className={`flex w-full flex-col gap-1 border-b border-gray-100 px-3 py-2 text-left text-xs transition hover:bg-white ${
                      selectedThreadId === thread.id
                        ? 'bg-white text-gray-900'
                        : 'text-gray-600'
                    }`}
                    onClick={() => selectThread(thread)}
                  >
                    <span className="font-semibold">{threadLabel(thread)}</span>
                    <span className="truncate text-[11px] text-gray-500">
                      {thread.last_message || 'No messages yet'}
                    </span>
                    {thread.unread_count ? (
                      <span className="mt-1 inline-flex w-fit items-center rounded-full bg-blue-100 px-2 py-0.5 text-[10px] font-semibold text-blue-700">
                        {thread.unread_count} new
                      </span>
                    ) : null}
                  </button>
                ))}
                {!threads.length ? (
                  <div className="px-3 py-6 text-center text-xs text-gray-400">
                    No conversations yet.
                  </div>
                ) : null}
              </div>
            </aside>

            <section className="flex flex-1 flex-col">
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
                      className={`max-w-[75%] rounded-2xl px-3 py-2 text-sm ${
                        message.sender_id === currentUserId
                          ? 'bg-blue-600 text-white'
                          : 'bg-gray-100 text-gray-800'
                      }`}
                    >
                      <p className="text-[11px] font-semibold opacity-80">
                        {message.sender_id === currentUserId ? 'You' : senderName(message)}
                      </p>
                      <p className="whitespace-pre-wrap">{message.body}</p>
                      <p className="mt-1 text-[10px] opacity-70">
                        {formatTimestamp(message.created_at)}
                      </p>
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
                <div className="flex items-center gap-2">
                  <input
                    value={newMessage}
                    onChange={(event) => setNewMessage(event.target.value)}
                    type="text"
                    disabled={!selectedThread}
                    className="flex-1 rounded-full border border-gray-200 px-4 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100 disabled:bg-gray-100"
                    placeholder="Type a message"
                  />
                  <button
                    type="submit"
                    className="rounded-full bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:bg-blue-300"
                    disabled={!selectedThread || sending}
                  >
                    Send
                  </button>
                </div>
              </form>
            </section>
          </div>
        </div>
      ) : null}
    </div>
  )
}
