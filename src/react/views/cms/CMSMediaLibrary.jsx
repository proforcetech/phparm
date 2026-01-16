import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import {
  FolderIcon,
  PhotoIcon,
  TagIcon,
} from '@heroicons/react/24/outline'

import Alert from '../../components/ui/Alert'
import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Modal from '../../components/ui/Modal'
import { useToast } from '../../stores/toast.jsx'
import { cmsService } from '../../../services/cms.service'

const emptyMetadata = { folders: [], tags: [] }

const parseTags = (value) => {
  if (!value) return []
  return value
    .split(',')
    .map((tag) => tag.trim())
    .filter(Boolean)
}

export default function CMSMediaLibrary() {
  const toast = useToast()
  const searchTimeout = useRef(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [media, setMedia] = useState([])
  const [metadata, setMetadata] = useState(emptyMetadata)
  const [filters, setFilters] = useState({
    search: '',
    status: '',
    folder: '',
    tag: '',
  })
  const [groupBy, setGroupBy] = useState('none')
  const [selectedIds, setSelectedIds] = useState([])
  const [showBulkModal, setShowBulkModal] = useState(false)
  const [showFolderModal, setShowFolderModal] = useState(false)
  const [bulkTags, setBulkTags] = useState('')
  const [bulkFolder, setBulkFolder] = useState('')
  const [clearTags, setClearTags] = useState(false)
  const [clearFolder, setClearFolder] = useState(false)
  const [bulkSaving, setBulkSaving] = useState(false)
  const [folderEdits, setFolderEdits] = useState({})

  const resetBulkForm = () => {
    setBulkTags('')
    setBulkFolder('')
    setClearTags(false)
    setClearFolder(false)
  }

  const loadMetadata = useCallback(async () => {
    try {
      const data = await cmsService.getMediaMetadata()
      setMetadata({
        folders: data.folders || [],
        tags: data.tags || [],
      })
    } catch (err) {
      console.error('Failed to load media metadata:', err)
    }
  }, [])

  const loadMedia = useCallback(async (nextFilters = filters) => {
    try {
      setLoading(true)
      setError(null)
      const data = await cmsService.getMedia(nextFilters)
      setMedia(data.data || data || [])
    } catch (err) {
      console.error('Failed to load media:', err)
      setError(err.response?.data?.message || 'Failed to load media')
    } finally {
      setLoading(false)
    }
  }, [filters])

  useEffect(() => {
    loadMedia()
    loadMetadata()

    return () => {
      if (searchTimeout.current) {
        clearTimeout(searchTimeout.current)
      }
    }
  }, [loadMedia, loadMetadata])

  useEffect(() => {
    setSelectedIds((prev) => prev.filter((id) => media.some((item) => item.id === id)))
  }, [media])

  useEffect(() => {
    if (!showFolderModal) {
      return
    }

    const nextEdits = {}
    metadata.folders.forEach((folder) => {
      nextEdits[folder.name] = folder.name
    })
    setFolderEdits(nextEdits)
  }, [metadata.folders, showFolderModal])

  useEffect(() => {
    if (!showBulkModal) {
      resetBulkForm()
    }
  }, [showBulkModal])

  const debouncedSearch = (nextFilters) => {
    if (searchTimeout.current) {
      clearTimeout(searchTimeout.current)
    }
    searchTimeout.current = setTimeout(() => {
      loadMedia(nextFilters)
    }, 300)
  }

  const toggleSelection = (id) => {
    setSelectedIds((prev) => (
      prev.includes(id) ? prev.filter((item) => item !== id) : [...prev, id]
    ))
  }

  const toggleSelectAll = () => {
    if (selectedIds.length === media.length) {
      setSelectedIds([])
      return
    }
    setSelectedIds(media.map((item) => item.id))
  }

  const handleBulkUpdate = async () => {
    if (selectedIds.length === 0) {
      toast.error('Select at least one media item')
      return
    }

    const payload = { ids: selectedIds }
    const tagList = parseTags(bulkTags)

    if (clearTags) {
      payload.tags = []
    } else if (tagList.length > 0) {
      payload.tags = tagList
    }

    if (clearFolder) {
      payload.folder = ''
    } else if (bulkFolder.trim()) {
      payload.folder = bulkFolder.trim()
    }

    const hasTagUpdate = Object.prototype.hasOwnProperty.call(payload, 'tags')
    const hasFolderUpdate = Object.prototype.hasOwnProperty.call(payload, 'folder')

    if (!hasTagUpdate && !hasFolderUpdate) {
      toast.error('Provide tags or a folder update')
      return
    }

    try {
      setBulkSaving(true)
      await cmsService.bulkUpdateMedia(payload)
      toast.success('Media updated')
      setShowBulkModal(false)
      resetBulkForm()
      await Promise.all([loadMedia(), loadMetadata()])
    } catch (err) {
      console.error('Failed to update media:', err)
      toast.error(err.response?.data?.message || 'Failed to update media')
    } finally {
      setBulkSaving(false)
    }
  }

  const handleFolderRename = async (from, to) => {
    if (!from) return

    if (to.trim() === from) {
      toast.info('Folder name unchanged')
      return
    }

    try {
      await cmsService.renameMediaFolder({ from, to: to.trim() })
      toast.success('Folder updated')
      await Promise.all([loadMedia(), loadMetadata()])
    } catch (err) {
      console.error('Failed to update folder:', err)
      toast.error(err.response?.data?.message || 'Failed to update folder')
    }
  }

  const handleFolderClear = async (from) => {
    if (!from) return
    try {
      await cmsService.renameMediaFolder({ from, to: '' })
      toast.success('Folder cleared')
      await Promise.all([loadMedia(), loadMetadata()])
    } catch (err) {
      console.error('Failed to clear folder:', err)
      toast.error(err.response?.data?.message || 'Failed to clear folder')
    }
  }

  const groupedMedia = useMemo(() => {
    if (groupBy === 'none') {
      return { 'All Media': media }
    }

    const groups = {}

    if (groupBy === 'folder') {
      media.forEach((item) => {
        const name = item.folder?.trim() ? item.folder : 'Unsorted'
        if (!groups[name]) {
          groups[name] = []
        }
        groups[name].push(item)
      })
    }

    if (groupBy === 'tag') {
      media.forEach((item) => {
        if (item.tags?.length) {
          item.tags.forEach((tag) => {
            if (!groups[tag]) {
              groups[tag] = []
            }
            groups[tag].push(item)
          })
        } else {
          if (!groups.Untagged) {
            groups.Untagged = []
          }
          groups.Untagged.push(item)
        }
      })
    }

    return Object.keys(groups)
      .sort((a, b) => a.localeCompare(b))
      .reduce((acc, key) => {
        acc[key] = groups[key]
        return acc
      }, {})
  }, [groupBy, media])

  const folderOptions = useMemo(() => (
    [{ name: 'Unassigned', value: '__unassigned' }].concat(
      metadata.folders.map((folder) => ({
        name: `${folder.name} (${folder.count})`,
        value: folder.name,
      }))
    )
  ), [metadata.folders])

  const tagOptions = useMemo(() => (
    [{ name: 'Untagged', value: '__untagged' }].concat(
      metadata.tags.map((tag) => ({
        name: `${tag.name} (${tag.count})`,
        value: tag.name,
      }))
    )
  ), [metadata.tags])

  const renderMediaCard = (item) => {
    const isSelected = selectedIds.includes(item.id)
    const isImage = item.mime_type?.startsWith('image/')

    return (
      <div
        key={item.id}
        className={`rounded-lg border p-3 shadow-sm transition hover:border-primary-300 ${isSelected ? 'border-primary-400 bg-primary-50/50' : 'border-gray-200'}`}
      >
        <div className="flex items-start justify-between">
          <label className="flex items-center gap-2 text-xs text-gray-500">
            <input
              type="checkbox"
              checked={isSelected}
              onChange={() => toggleSelection(item.id)}
              className="rounded border-gray-300 text-primary-600 focus:ring-primary-500"
            />
            Select
          </label>
          <Badge variant={item.status === 'published' ? 'success' : 'warning'}>
            {item.status}
          </Badge>
        </div>
        <div className="mt-3 flex h-32 items-center justify-center rounded-md bg-gray-100">
          {isImage ? (
            <img
              src={item.url}
              alt={item.alt_text || item.title || item.file_name}
              className="h-full w-full rounded-md object-cover"
            />
          ) : (
            <div className="text-center text-gray-400">
              <PhotoIcon className="mx-auto h-8 w-8" />
              <p className="mt-2 text-xs">{item.mime_type || 'File'}</p>
            </div>
          )}
        </div>
        <div className="mt-3">
          <p className="text-sm font-medium text-gray-900 truncate">{item.title || item.file_name}</p>
          <p className="text-xs text-gray-500 truncate">/{item.slug}</p>
        </div>
        <div className="mt-3 flex flex-wrap gap-2 text-xs text-gray-500">
          <span className="flex items-center gap-1">
            <FolderIcon className="h-4 w-4" />
            {item.folder?.trim() ? item.folder : 'Unsorted'}
          </span>
          <span className="flex items-center gap-1">
            <TagIcon className="h-4 w-4" />
            {item.tags?.length ? item.tags.join(', ') : 'No tags'}
          </span>
        </div>
      </div>
    )
  }

  return (
    <div>
      <div className="mb-8 flex flex-wrap items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Media Library</h1>
          <p className="mt-1 text-sm text-gray-500">Organize assets with folders and tags.</p>
        </div>
        <div className="flex flex-wrap gap-3">
          <Button variant="outline" onClick={() => setShowFolderModal(true)}>
            Manage Folders
          </Button>
          <Button onClick={() => setShowBulkModal(true)} disabled={selectedIds.length === 0}>
            Bulk Edit ({selectedIds.length})
          </Button>
        </div>
      </div>

      <Card className="mb-6">
        <div className="flex flex-wrap gap-4">
          <div className="flex-1 min-w-[200px]">
            <Input
              value={filters.search}
              placeholder="Search media..."
              onUpdateModelValue={(value) => {
                setFilters((prev) => {
                  const nextFilters = { ...prev, search: value }
                  debouncedSearch(nextFilters)
                  return nextFilters
                })
              }}
            />
          </div>
          <div>
            <select
              value={filters.status}
              className="px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
              onChange={(event) => {
                const nextFilters = { ...filters, status: event.target.value }
                setFilters(nextFilters)
                loadMedia(nextFilters)
              }}
            >
              <option value="">All Status</option>
              <option value="published">Published</option>
              <option value="draft">Draft</option>
              <option value="archived">Archived</option>
            </select>
          </div>
          <div>
            <select
              value={filters.folder}
              className="px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
              onChange={(event) => {
                const nextFilters = { ...filters, folder: event.target.value }
                setFilters(nextFilters)
                loadMedia(nextFilters)
              }}
            >
              <option value="">All Folders</option>
              {folderOptions.map((folder) => (
                <option key={folder.value} value={folder.value}>{folder.name}</option>
              ))}
            </select>
          </div>
          <div>
            <select
              value={filters.tag}
              className="px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
              onChange={(event) => {
                const nextFilters = { ...filters, tag: event.target.value }
                setFilters(nextFilters)
                loadMedia(nextFilters)
              }}
            >
              <option value="">All Tags</option>
              {tagOptions.map((tag) => (
                <option key={tag.value} value={tag.value}>{tag.name}</option>
              ))}
            </select>
          </div>
          <div>
            <select
              value={groupBy}
              className="px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
              onChange={(event) => setGroupBy(event.target.value)}
            >
              <option value="none">No Grouping</option>
              <option value="folder">Group by Folder</option>
              <option value="tag">Group by Tag</option>
            </select>
          </div>
        </div>
      </Card>

      <div className="mb-4 flex flex-wrap items-center justify-between gap-3 text-sm text-gray-500">
        <div className="flex items-center gap-2">
          <button
            type="button"
            className="text-primary-600 hover:text-primary-700"
            onClick={toggleSelectAll}
          >
            {selectedIds.length === media.length && media.length > 0 ? 'Clear selection' : 'Select all'}
          </button>
          <span>{selectedIds.length} selected</span>
        </div>
        <div className="flex items-center gap-2">
          <span>{media.length} assets</span>
        </div>
      </div>

      {loading ? (
        <div className="flex justify-center py-12">
          <Loading size="xl" text="Loading media..." />
        </div>
      ) : error ? (
        <Alert variant="danger" className="mb-6">
          {error}
        </Alert>
      ) : media.length === 0 ? (
        <Card>
          <div className="text-center py-12 text-gray-500">
            <PhotoIcon className="h-12 w-12 mx-auto mb-4 text-gray-400" />
            <p className="text-lg font-medium">No media found</p>
            <p className="text-sm mt-1">Try adjusting your filters.</p>
          </div>
        </Card>
      ) : (
        <div className="space-y-6">
          {Object.entries(groupedMedia).map(([group, items]) => (
            <Card key={group}>
              <div className="mb-4 flex items-center justify-between">
                <div>
                  <h2 className="text-lg font-semibold text-gray-900">{group}</h2>
                  <p className="text-xs text-gray-500">{items.length} assets</p>
                </div>
              </div>
              <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                {items.map(renderMediaCard)}
              </div>
            </Card>
          ))}
        </div>
      )}

      <Modal
        isOpen={showBulkModal}
        onClose={() => setShowBulkModal(false)}
        title="Bulk update media"
        maxWidth="lg"
      >
        <div className="space-y-6">
          <div>
            <label className="block text-sm font-medium text-gray-700">Tags (comma-separated)</label>
            <Input
              value={bulkTags}
              placeholder="e.g. homepage, hero, blog"
              onUpdateModelValue={setBulkTags}
            />
            <label className="mt-2 flex items-center gap-2 text-sm text-gray-600">
              <input
                type="checkbox"
                checked={clearTags}
                onChange={(event) => setClearTags(event.target.checked)}
                className="rounded border-gray-300 text-primary-600 focus:ring-primary-500"
              />
              Clear tags on selected assets
            </label>
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700">Folder</label>
            <Input
              value={bulkFolder}
              placeholder="e.g. Brand Assets"
              list="media-folder-list"
              onUpdateModelValue={setBulkFolder}
            />
            <datalist id="media-folder-list">
              {metadata.folders.map((folder) => (
                <option key={folder.name} value={folder.name} />
              ))}
            </datalist>
            <label className="mt-2 flex items-center gap-2 text-sm text-gray-600">
              <input
                type="checkbox"
                checked={clearFolder}
                onChange={(event) => setClearFolder(event.target.checked)}
                className="rounded border-gray-300 text-primary-600 focus:ring-primary-500"
              />
              Clear folder assignment
            </label>
          </div>
        </div>
        <div className="mt-6 flex justify-end gap-3">
          <Button variant="outline" onClick={() => setShowBulkModal(false)}>
            Cancel
          </Button>
          <Button onClick={handleBulkUpdate} disabled={bulkSaving}>
            {bulkSaving ? 'Saving...' : 'Apply changes'}
          </Button>
        </div>
      </Modal>

      <Modal
        isOpen={showFolderModal}
        onClose={() => setShowFolderModal(false)}
        title="Manage folders"
        maxWidth="xl"
      >
        <div className="space-y-4">
          {metadata.folders.length === 0 ? (
            <div className="text-sm text-gray-500">No folders yet. Assign a folder in bulk to create one.</div>
          ) : (
            metadata.folders.map((folder) => (
              <div
                key={folder.name}
                className="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 pb-3"
              >
                <div>
                  <p className="text-sm font-medium text-gray-900">{folder.name}</p>
                  <p className="text-xs text-gray-500">{folder.count} assets</p>
                </div>
                <div className="flex flex-1 flex-wrap items-center gap-3">
                  <div className="min-w-[200px] flex-1">
                    <Input
                      value={folderEdits[folder.name] || ''}
                      onUpdateModelValue={(value) => setFolderEdits((prev) => ({
                        ...prev,
                        [folder.name]: value,
                      }))}
                    />
                  </div>
                  <Button
                    variant="outline"
                    onClick={() => handleFolderRename(folder.name, folderEdits[folder.name] || '')}
                  >
                    Rename
                  </Button>
                  <Button
                    variant="outline"
                    onClick={() => handleFolderClear(folder.name)}
                  >
                    Clear
                  </Button>
                </div>
              </div>
            ))
          )}
        </div>
        <div className="mt-6 flex justify-end">
          <Button variant="outline" onClick={() => setShowFolderModal(false)}>
            Close
          </Button>
        </div>
      </Modal>
    </div>
  )
}
