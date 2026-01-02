import { Bars3Icon } from '@heroicons/react/24/outline'

export default function Navbar({ title = 'Shop Portal', onMenuToggle }) {
  return (
    <header className="flex items-center justify-between px-4 py-3 border-b bg-white">
      <div className="flex items-center gap-3">
        <button
          type="button"
          className="text-gray-600 hover:text-gray-900"
          onClick={onMenuToggle}
        >
          <Bars3Icon className="h-5 w-5" aria-hidden="true" />
        </button>
        <span className="text-sm font-semibold text-gray-900">{title}</span>
      </div>
      <div className="text-xs text-gray-500">React migration</div>
    </header>
  )
}
