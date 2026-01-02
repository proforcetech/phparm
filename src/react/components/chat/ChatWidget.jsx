import { useMemo, useState } from 'react'

import Button from '../ui/Button'
import Input from '../ui/Input'
import Card from '../ui/Card'

const defaultMessages = [
  { id: 1, author: 'Support', text: 'Hi! How can we help today?' },
]

export default function ChatWidget({
  title = 'Live chat',
  initialMessages = defaultMessages,
  onSend,
}) {
  const [messages, setMessages] = useState(initialMessages)
  const [draft, setDraft] = useState('')

  const canSend = useMemo(() => draft.trim().length > 0, [draft])

  const handleSend = () => {
    if (!canSend) return
    const message = { id: Date.now(), author: 'You', text: draft }
    setMessages((prev) => [...prev, message])
    setDraft('')
    if (onSend) {
      onSend(message)
    }
  }

  return (
    <Card className="max-w-md">
      <div className="space-y-4">
        <header>
          <h3 className="text-base font-semibold text-gray-900">{title}</h3>
        </header>
        <div className="space-y-2 max-h-64 overflow-y-auto">
          {messages.map((message) => (
            <div key={message.id} className="text-sm">
              <span className="font-semibold text-gray-700">{message.author}:</span>{' '}
              <span className="text-gray-600">{message.text}</span>
            </div>
          ))}
        </div>
        <div className="flex items-end gap-2">
          <Input
            label="Message"
            value={draft}
            onChange={(event) => setDraft(event.target.value)}
            onKeyDown={(event) => {
              if (event.key === 'Enter' && !event.shiftKey) {
                event.preventDefault()
                handleSend()
              }
            }}
          />
          <Button type="button" onClick={handleSend} disabled={!canSend}>
            Send
          </Button>
        </div>
      </div>
    </Card>
  )
}
