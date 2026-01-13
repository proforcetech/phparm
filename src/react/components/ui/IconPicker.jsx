import { useEffect, useId, useMemo, useRef, useState } from 'react'
import * as OutlineIcons from '@heroicons/react/24/outline'

const iconList = [
  'AcademicCapIcon',
  'AdjustmentsHorizontalIcon',
  'AdjustmentsVerticalIcon',
  'ArchiveBoxIcon',
  'ArrowPathIcon',
  'BanknotesIcon',
  'Bars3Icon',
  'BellIcon',
  'BoltIcon',
  'BookOpenIcon',
  'BriefcaseIcon',
  'BuildingOfficeIcon',
  'BuildingStorefrontIcon',
  'CalendarIcon',
  'CameraIcon',
  'ChartBarIcon',
  'ChartPieIcon',
  'ChatBubbleLeftIcon',
  'CheckCircleIcon',
  'ClipboardDocumentCheckIcon',
  'ClipboardDocumentListIcon',
  'ClockIcon',
  'CloudIcon',
  'Cog6ToothIcon',
  'CogIcon',
  'CpuChipIcon',
  'CreditCardIcon',
  'CubeIcon',
  'CurrencyDollarIcon',
  'DevicePhoneMobileIcon',
  'DocumentDuplicateIcon',
  'DocumentTextIcon',
  'EnvelopeIcon',
  'ExclamationTriangleIcon',
  'EyeIcon',
  'FaceSmileIcon',
  'FireIcon',
  'FlagIcon',
  'FolderIcon',
  'GiftIcon',
  'GlobeAltIcon',
  'HandThumbUpIcon',
  'HeartIcon',
  'HomeIcon',
  'IdentificationIcon',
  'InboxIcon',
  'KeyIcon',
  'LightBulbIcon',
  'LinkIcon',
  'LockClosedIcon',
  'MagnifyingGlassIcon',
  'MapIcon',
  'MapPinIcon',
  'MegaphoneIcon',
  'MicrophoneIcon',
  'MusicalNoteIcon',
  'NewspaperIcon',
  'PaintBrushIcon',
  'PaperAirplaneIcon',
  'PencilIcon',
  'PhoneIcon',
  'PhotoIcon',
  'PlayIcon',
  'PlusIcon',
  'PowerIcon',
  'PresentationChartLineIcon',
  'PrinterIcon',
  'PuzzlePieceIcon',
  'QrCodeIcon',
  'QuestionMarkCircleIcon',
  'RectangleGroupIcon',
  'RectangleStackIcon',
  'RocketLaunchIcon',
  'ScaleIcon',
  'ScissorsIcon',
  'ServerIcon',
  'ShieldCheckIcon',
  'ShoppingBagIcon',
  'ShoppingCartIcon',
  'SignalIcon',
  'SparklesIcon',
  'Squares2X2Icon',
  'StarIcon',
  'StopIcon',
  'SunIcon',
  'SwatchIcon',
  'TableCellsIcon',
  'TagIcon',
  'TicketIcon',
  'TrophyIcon',
  'TruckIcon',
  'UserCircleIcon',
  'UserGroupIcon',
  'UserIcon',
  'UsersIcon',
  'VideoCameraIcon',
  'WalletIcon',
  'WifiIcon',
  'WrenchIcon',
  'WrenchScrewdriverIcon',
  'XMarkIcon',
]

const iconNameToLabel = (name) => {
  return name
    .replace(/Icon$/, '')
    .replace(/([A-Z])/g, ' $1')
    .trim()
}

export default function IconPicker({
  id,
  value = '',
  label = '',
  error = '',
  required = false,
  disabled = false,
  onChange,
  className = '',
}) {
  const fallbackId = useId()
  const inputId = id || `icon-picker-${fallbackId}`
  const [showPicker, setShowPicker] = useState(false)
  const [search, setSearch] = useState('')
  const containerRef = useRef(null)

  useEffect(() => {
    const handleClickOutside = (event) => {
      if (containerRef.current && !containerRef.current.contains(event.target)) {
        setShowPicker(false)
      }
    }

    document.addEventListener('mousedown', handleClickOutside)
    return () => document.removeEventListener('mousedown', handleClickOutside)
  }, [])

  const filteredIcons = useMemo(() => {
    if (!search.trim()) return iconList
    const query = search.toLowerCase()
    return iconList.filter((name) =>
      name.toLowerCase().includes(query) ||
      iconNameToLabel(name).toLowerCase().includes(query)
    )
  }, [search])

  const handleSelect = (iconName) => {
    onChange?.(iconName)
    setShowPicker(false)
    setSearch('')
  }

  const handleClear = () => {
    onChange?.('')
  }

  const SelectedIcon = value && OutlineIcons[value] ? OutlineIcons[value] : null

  return (
    <div className={`w-full ${className}`} ref={containerRef}>
      {label ? (
        <label htmlFor={inputId} className="block text-sm font-medium text-gray-700 mb-1">
          {label}
          {required ? <span className="text-red-500">*</span> : null}
        </label>
      ) : null}

      <div className="relative">
        <button
          id={inputId}
          type="button"
          disabled={disabled}
          onClick={() => setShowPicker(!showPicker)}
          className={`w-full flex items-center justify-between px-3 py-2 border rounded-md shadow-sm bg-white text-left sm:text-sm disabled:opacity-50 disabled:cursor-not-allowed ${
            error ? 'border-red-300' : 'border-gray-300 focus:ring-primary-500 focus:border-primary-500'
          }`}
        >
          <span className="flex items-center gap-2">
            {SelectedIcon ? (
              <>
                <SelectedIcon className="h-5 w-5 text-gray-600" />
                <span className="text-gray-900">{iconNameToLabel(value)}</span>
              </>
            ) : (
              <span className="text-gray-400">Select an icon...</span>
            )}
          </span>
          {value && !disabled ? (
            <button
              type="button"
              onClick={(event) => {
                event.stopPropagation()
                handleClear()
              }}
              className="text-gray-400 hover:text-gray-600"
            >
              <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          ) : (
            <svg className="h-5 w-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M19 9l-7 7-7-7" />
            </svg>
          )}
        </button>

        {showPicker && !disabled ? (
          <div className="absolute z-10 mt-1 w-full bg-white border border-gray-200 rounded-lg shadow-lg">
            <div className="p-2 border-b border-gray-100">
              <input
                type="text"
                value={search}
                placeholder="Search icons..."
                className="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-primary-500 focus:border-primary-500"
                onChange={(event) => setSearch(event.target.value)}
              />
            </div>
            <div className="max-h-64 overflow-y-auto p-2">
              {filteredIcons.length === 0 ? (
                <div className="text-center py-4 text-gray-500 text-sm">No icons found</div>
              ) : (
                <div className="grid grid-cols-4 gap-1">
                  {filteredIcons.map((iconName) => {
                    const IconComponent = OutlineIcons[iconName]
                    if (!IconComponent) return null
                    return (
                      <button
                        key={iconName}
                        type="button"
                        onClick={() => handleSelect(iconName)}
                        className={`flex flex-col items-center p-2 rounded hover:bg-gray-100 ${
                          value === iconName ? 'bg-primary-50 ring-1 ring-primary-500' : ''
                        }`}
                        title={iconNameToLabel(iconName)}
                      >
                        <IconComponent className="h-6 w-6 text-gray-700" />
                      </button>
                    )
                  })}
                </div>
              )}
            </div>
          </div>
        ) : null}
      </div>

      {error ? <p className="mt-1 text-sm text-red-600">{error}</p> : null}
    </div>
  )
}
