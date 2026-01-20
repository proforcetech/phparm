import { useCallback, useEffect, useState } from 'react'

import CsvUploadModal from '../../components/import/CsvUploadModal'
import Button from '../../components/ui/Button'
import { moduleService } from '../../../services/modules.service'
import { uploadImportCsv } from '../../../services/import.service'
import { useAuthStore } from '../../stores/auth'

const moduleIcons = {
  core: 'M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21',
  estimates: 'M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z',
  workorders: 'M11.35 3.836c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m8.9-4.414c.376.023.75.05 1.124.08 1.131.094 1.976 1.057 1.976 2.192V16.5A2.25 2.25 0 0118 18.75h-2.25m-7.5-10.5H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V18.75m-7.5-10.5h6.375c.621 0 1.125.504 1.125 1.125v9.375m-8.25-3l1.5 1.5 3-3.75',
  invoicing: 'M2.25 18.75a60.07 60.07 0 0115.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 013 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 00-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 01-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 003 15h-.75M15 10.5a3 3 0 11-6 0 3 3 0 016 0zm3 0h.008v.008H18V10.5zm-12 0h.008v.008H6V10.5z',
  appointments: 'M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5',
  inventory: 'M21 7.5l-9-5.25L3 7.5m18 0l-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9',
  towing: 'M8.25 18.75a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 01-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 00-3.213-9.193 2.056 2.056 0 00-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.958c0-.568-.422-1.048-.987-1.106a48.554 48.554 0 00-10.026 0 1.106 1.106 0 00-.987 1.106v7.635m12-6.677v6.677m0 4.5v-4.5m0 0h-12',
  impound: 'M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z',
  inspections: 'M11.35 3.836c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m8.9-4.414c.376.023.75.05 1.124.08 1.131.094 1.976 1.057 1.976 2.192V16.5A2.25 2.25 0 0118 18.75h-2.25m-7.5-10.5H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V18.75m-7.5-10.5h6.375c.621 0 1.125.504 1.125 1.125v9.375m-8.25-3l1.5 1.5 3-3.75',
  warranty: 'M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z',
  time_tracking: 'M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z',
  messaging: 'M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a5.969 5.969 0 01-.474-.065 4.48 4.48 0 00.978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z',
  reminders: 'M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75v-.7V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0',
  cms: 'M12 21a9.004 9.004 0 008.716-6.747M12 21a9.004 9.004 0 01-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 017.843 4.582M12 3a8.997 8.997 0 00-7.843 4.582m15.686 0A11.953 11.953 0 0112 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0121 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0112 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 013 12c0-1.605.42-3.113 1.157-4.418',
  customer_portal: 'M17.982 18.725A7.488 7.488 0 0012 15.75a7.488 7.488 0 00-5.982 2.975m11.963 0a9 9 0 10-11.963 0m11.963 0A8.966 8.966 0 0112 21a8.966 8.966 0 01-5.982-2.275M15 9.75a3 3 0 11-6 0 3 3 0 016 0z',
  reports: 'M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z',
  bundles: 'M13.5 16.875h3.375m0 0h3.375m-3.375 0V13.5m0 3.375v3.375M6 10.5h2.25a2.25 2.25 0 002.25-2.25V6a2.25 2.25 0 00-2.25-2.25H6A2.25 2.25 0 003.75 6v2.25A2.25 2.25 0 006 10.5zm0 9.75h2.25A2.25 2.25 0 0010.5 18v-2.25a2.25 2.25 0 00-2.25-2.25H6a2.25 2.25 0 00-2.25 2.25V18A2.25 2.25 0 006 20.25zm9.75-9.75H18a2.25 2.25 0 002.25-2.25V6A2.25 2.25 0 0018 3.75h-2.25A2.25 2.25 0 0013.5 6v2.25a2.25 2.25 0 002.25 2.25z',
  documents: 'M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z',
  financial: 'M2.25 18.75a60.07 60.07 0 0115.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 013 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 00-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 01-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 003 15h-.75M15 10.5a3 3 0 11-6 0 3 3 0 016 0zm3 0h.008v.008H18V10.5zm-12 0h.008v.008H6V10.5z',
}

const categoryTemplate = {
  fileName: 'inventory-category-template.csv',
  headers: ['name', 'description'],
  sampleRows: [
    {
      name: 'Brakes',
      description: 'Brake components and kits',
    },
  ],
  note: 'Categories help organize inventory items and services.',
}

const locationTemplate = {
  fileName: 'inventory-location-template.csv',
  headers: ['name', 'description'],
  sampleRows: [
    {
      name: 'Aisle 3',
      description: 'Front warehouse aisle',
    },
  ],
  note: 'Locations should match the storage or bin names used by the team.',
}

function ModuleIcon({ moduleKey, className = 'w-6 h-6' }) {
  const path = moduleIcons[moduleKey] || moduleIcons.core

  return (
    <svg
      xmlns="http://www.w3.org/2000/svg"
      fill="none"
      viewBox="0 0 24 24"
      strokeWidth={1.5}
      stroke="currentColor"
      className={className}
    >
      <path strokeLinecap="round" strokeLinejoin="round" d={path} />
    </svg>
  )
}

export default function ModuleSettings() {
  const { fetchAccessibleModules } = useAuthStore()
  const [modules, setModules] = useState([])
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(null)
  const [error, setError] = useState(null)
  const [confirmDisable, setConfirmDisable] = useState(null)
  const [importTarget, setImportTarget] = useState(null)

  const fetchModules = useCallback(async () => {
    try {
      setLoading(true)
      setError(null)
      const data = await moduleService.list()
      setModules(data.modules || [])
    } catch (err) {
      setError(err.response?.data?.message || 'Failed to load modules')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    fetchModules()
  }, [fetchModules])

  const handleToggle = async (moduleKey, currentEnabled) => {
    const module = modules.find((m) => m.key === moduleKey)

    // If disabling and has dependent modules enabled, show confirmation
    if (currentEnabled && module?.dependent_modules?.length > 0) {
      const enabledDependents = module.dependent_modules.filter((depKey) =>
        modules.find((m) => m.key === depKey && m.enabled)
      )
      if (enabledDependents.length > 0) {
        setConfirmDisable({ moduleKey, dependents: enabledDependents })
        return
      }
    }

    await toggleModule(moduleKey, !currentEnabled)
  }

  const toggleModule = async (moduleKey, enabled, force = false) => {
    try {
      setSaving(moduleKey)
      setError(null)
      const result = await moduleService.update(moduleKey, enabled, force)

      if (result.warning && result.requires_force) {
        setConfirmDisable({
          moduleKey,
          dependents: result.affected_modules,
          message: result.message,
        })
        return
      }

      // Refresh the list
      await fetchModules()
      // Refresh user's accessible modules
      await fetchAccessibleModules()
    } catch (err) {
      setError(err.response?.data?.message || 'Failed to update module')
    } finally {
      setSaving(null)
      setConfirmDisable(null)
    }
  }

  const handleConfirmDisable = async () => {
    if (confirmDisable) {
      await toggleModule(confirmDisable.moduleKey, false, true)
    }
  }

  const handleLookupImport = async (entity, file, dryRun) => {
    return uploadImportCsv(entity, file, { dryRun })
  }

  if (loading) {
    return (
      <div className="p-6">
        <div className="animate-pulse space-y-4">
          <div className="h-8 bg-gray-200 rounded w-1/4"></div>
          <div className="h-4 bg-gray-200 rounded w-1/2"></div>
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            {[1, 2, 3, 4, 5, 6].map((i) => (
              <div key={i} className="h-32 bg-gray-200 rounded"></div>
            ))}
          </div>
        </div>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <header>
        <h1 className="text-2xl font-semibold text-gray-900">Module Settings</h1>
        <p className="text-sm text-gray-500">
          Enable or disable application modules. Disabled modules will be hidden from all users.
        </p>
      </header>

      {error && (
        <div className="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded">
          {error}
        </div>
      )}

      <div className="rounded-lg border border-gray-200 bg-white p-4 space-y-3">
        <div>
          <h2 className="text-lg font-semibold text-gray-900">Import Inventory Lookups</h2>
          <p className="text-sm text-gray-500">Upload CSV files to manage inventory categories and locations.</p>
        </div>
        <div className="flex flex-wrap gap-2">
          <Button variant="secondary" onClick={() => setImportTarget('categories')}>
            Import Categories CSV
          </Button>
          <Button variant="secondary" onClick={() => setImportTarget('locations')}>
            Import Locations CSV
          </Button>
        </div>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        {modules.map((module) => (
          <div
            key={module.key}
            className={`border rounded-lg p-4 ${
              module.enabled ? 'bg-white border-gray-200' : 'bg-gray-50 border-gray-300'
            }`}
          >
            <div className="flex items-start justify-between">
              <div className="flex items-center space-x-3">
                <div
                  className={`p-2 rounded-lg ${
                    module.enabled ? 'bg-primary-100 text-primary-600' : 'bg-gray-200 text-gray-500'
                  }`}
                >
                  <ModuleIcon moduleKey={module.key} className="w-5 h-5" />
                </div>
                <div>
                  <h3 className="font-medium text-gray-900">{module.label}</h3>
                  <p className="text-xs text-gray-500">{module.description}</p>
                </div>
              </div>

              {module.is_protected || !module.can_disable ? (
                <span className="text-xs text-gray-400 bg-gray-100 px-2 py-1 rounded">Required</span>
              ) : (
                <button
                  type="button"
                  onClick={() => handleToggle(module.key, module.enabled)}
                  disabled={saving === module.key}
                  className={`relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 ${
                    module.enabled ? 'bg-primary-600' : 'bg-gray-200'
                  } ${saving === module.key ? 'opacity-50' : ''}`}
                >
                  <span
                    className={`pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out ${
                      module.enabled ? 'translate-x-5' : 'translate-x-0'
                    }`}
                  />
                </button>
              )}
            </div>

            {module.missing_dependencies?.length > 0 && (
              <div className="mt-3 text-xs text-amber-600 bg-amber-50 px-2 py-1 rounded">
                Requires: {module.missing_dependencies.join(', ')}
              </div>
            )}

            {module.dependent_modules?.length > 0 && module.enabled && (
              <div className="mt-3 text-xs text-gray-500">
                Used by: {module.dependent_modules.join(', ')}
              </div>
            )}
          </div>
        ))}
      </div>

      {/* Confirmation Modal */}
      {confirmDisable && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
          <div className="bg-white rounded-lg p-6 max-w-md w-full mx-4">
            <h3 className="text-lg font-medium text-gray-900 mb-2">Confirm Disable Module</h3>
            <p className="text-sm text-gray-600 mb-4">
              {confirmDisable.message ||
                `Disabling this module will affect these modules: ${confirmDisable.dependents.join(', ')}`}
            </p>
            <div className="flex justify-end space-x-3">
              <button
                type="button"
                onClick={() => setConfirmDisable(null)}
                className="px-4 py-2 text-sm text-gray-700 bg-gray-100 rounded hover:bg-gray-200"
              >
                Cancel
              </button>
              <button
                type="button"
                onClick={handleConfirmDisable}
                className="px-4 py-2 text-sm text-white bg-red-600 rounded hover:bg-red-700"
              >
                Disable Anyway
              </button>
            </div>
          </div>
        </div>
      )}

      <CsvUploadModal
        open={importTarget === 'categories'}
        onClose={() => setImportTarget(null)}
        title="Import Categories CSV"
        description="Upload a CSV file to create or update inventory categories."
        template={categoryTemplate}
        confirmLabel="Import Categories"
        onUpload={(file, dryRun) => handleLookupImport('categories', file, dryRun)}
      />

      <CsvUploadModal
        open={importTarget === 'locations'}
        onClose={() => setImportTarget(null)}
        title="Import Locations CSV"
        description="Upload a CSV file to create or update inventory locations."
        template={locationTemplate}
        confirmLabel="Import Locations"
        onUpload={(file, dryRun) => handleLookupImport('locations', file, dryRun)}
      />
    </div>
  )
}
