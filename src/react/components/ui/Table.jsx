import { useEffect, useMemo, useRef, useState } from 'react';
import Loading from './Loading';

export default function Table({
  columns = [],
  data = [],
  loading = false,
  selectable = false,
  hoverable = true,
  rowKey = 'id',
  pagination = false,
  perPage = 10,
  total = 0,
  currentPage = 1,
  onRowClick,
  onSort,
  onSelectionChange,
  onPageChange,
  renderEmpty,
  renderActions,
  cellRenderers = {},
}) {
  const [sortKey, setSortKey] = useState('');
  const [sortOrder, setSortOrder] = useState('asc');
  const [selectedRows, setSelectedRows] = useState([]);
  const selectAllRef = useRef(null);

  const sort = (key) => {
    const newOrder = sortKey === key ? (sortOrder === 'asc' ? 'desc' : 'asc') : 'asc';
    setSortKey(key);
    setSortOrder(newOrder);
    onSort?.({ key, order: newOrder });
  };

  const getRowKey = (row, index) => row?.[rowKey] ?? index;
  const getCellValue = (row, key) => key.split('.').reduce((obj, part) => obj?.[part], row);

  const isSelected = (row) => {
    return selectedRows.some((selected) => getRowKey(selected) === getRowKey(row));
  };

  const allSelected = useMemo(() => {
    return data.length > 0 && selectedRows.length === data.length;
  }, [data.length, selectedRows]);

  const someSelected = useMemo(() => {
    return selectedRows.length > 0 && selectedRows.length < data.length;
  }, [data.length, selectedRows.length]);

  const toggleRow = (row) => {
    const key = getRowKey(row);
    setSelectedRows((prev) => {
      const index = prev.findIndex((selected) => getRowKey(selected) === key);
      let next;
      if (index > -1) {
        next = prev.filter((selected) => getRowKey(selected) !== key);
      } else {
        next = [...prev, row];
      }
      onSelectionChange?.(next);
      return next;
    });
  };

  const toggleAll = () => {
    const next = allSelected ? [] : [...data];
    setSelectedRows(next);
    onSelectionChange?.(next);
  };

  const totalPages = useMemo(() => Math.ceil(total / perPage), [total, perPage]);
  const startIndex = useMemo(() => (currentPage - 1) * perPage + 1, [currentPage, perPage]);
  const endIndex = useMemo(() => Math.min(currentPage * perPage, total), [currentPage, perPage, total]);

  const displayedPages = useMemo(() => {
    const pages = [];
    const maxPages = 7;

    if (totalPages <= maxPages) {
      for (let i = 1; i <= totalPages; i++) pages.push(i);
    } else if (currentPage <= 4) {
      for (let i = 1; i <= 5; i++) pages.push(i);
      pages.push('...');
      pages.push(totalPages);
    } else if (currentPage >= totalPages - 3) {
      pages.push(1);
      pages.push('...');
      for (let i = totalPages - 4; i <= totalPages; i++) pages.push(i);
    } else {
      pages.push(1);
      pages.push('...');
      for (let i = currentPage - 1; i <= currentPage + 1; i++) pages.push(i);
      pages.push('...');
      pages.push(totalPages);
    }
    return pages; // Removed the .filter that was deleting your ellipses
  }, [currentPage, totalPages]);

  const goToPage = (page) => {
    if (page !== '...' && page !== currentPage) {
      onPageChange?.(page);
    }
  };

  const columnCount = useMemo(() => {
    let count = columns?.length ?? 0;
    if (selectable) count += 1;
    if (typeof renderActions === 'function') count += 1;
    return count;
  }, [columns, renderActions, selectable]);

  useEffect(() => {
    setSelectedRows([]);
  }, [data]);

  useEffect(() => {
    if (selectAllRef.current) {
      selectAllRef.current.indeterminate = someSelected;
    }
  }, [someSelected]);

  return (
    <div className="flex flex-col">
      <div className="overflow-x-auto">
        <div className="inline-block min-w-full align-middle">
          <div className="overflow-hidden shadow ring-1 ring-black ring-opacity-5 rounded-lg">
            <table className="min-w-full divide-y divide-gray-300">
              <thead className="bg-gray-50">
                <tr>
                  {selectable && (
                    <th scope="col" className="relative px-4 py-3.5 sm:w-12 sm:px-6">
                      <input
                        ref={selectAllRef}
                        type="checkbox"
                        checked={allSelected}
                        onChange={toggleAll}
                        className="absolute left-4 top-1/2 -mt-2 h-4 w-4 rounded border-gray-300 text-primary-600 focus:ring-primary-600"
                      />
                    </th>
                  )}

                  {columns.map((column) => (
                    <th
                      key={column.key}
                      scope="col"
                      className={`px-3 py-3.5 text-left text-sm font-semibold text-gray-900 ${column.sortable ? 'cursor-pointer select-none hover:bg-gray-100' : ''}`}
                      onClick={() => column.sortable && sort(column.key)}
                    >
                      <div className="flex items-center gap-2">
                        <span>{column.label}</span>
                        {column.sortable && sortKey === column.key && (
                          <span className="flex-none">
                            <svg 
                              className={`h-4 w-4 text-gray-400 ${sortOrder === 'desc' ? 'rotate-180' : ''}`} 
                              fill="currentColor" 
                              viewBox="0 0 20 20"
                            >
                              <path fillRule="evenodd" d="M14.707 12.707a1 1 0 01-1.414 0L10 9.414l-3.293 3.293a1 1 0 01-1.414-1.414l4-4a1 1 0 011.414 0l4 4a1 1 0 010 1.414z" clipRule="evenodd" />
                            </svg>
                          </span>
                        )}
                      </div>
                    </th>
                  ))}

                  {typeof renderActions === 'function' && (
                    <th scope="col" className="relative py-3.5 pl-3 pr-4 sm:pr-6">
                      <span className="sr-only">Actions</span>
                    </th>
                  )}
                </tr>
              </thead>

              <tbody className="divide-y divide-gray-200 bg-white">
                {loading ? (
                  <tr>
                    <td colSpan={columnCount} className="px-3 py-12 text-center">
                      <div className="flex justify-center"><Loading size="lg" /></div>
                    </td>
                  </tr>
                ) : data.length === 0 ? (
                  <tr>
                    <td colSpan={columnCount} className="px-3 py-12 text-center">
                      <div className="text-gray-500">
                        {typeof renderEmpty === 'function' ? renderEmpty() : (renderEmpty || <p className="text-sm">No data available</p>)}
                      </div>
                    </td>
                  </tr>
                ) : (
                  data.map((row, rowIndex) => (
                    <tr
                      key={getRowKey(row, rowIndex)}
                      className={`${rowIndex % 2 === 0 ? 'bg-white' : 'bg-gray-50'} ${hoverable ? 'hover:bg-gray-100 cursor-pointer' : ''}`}
                      onClick={() => hoverable && onRowClick?.(row)}
                    >
                      {selectable && (
                        <td className="relative px-4 sm:w-12 sm:px-6">
                          <input
                            type="checkbox"
                            checked={isSelected(row)}
                            onChange={() => toggleRow(row)}
                            onClick={(e) => e.stopPropagation()}
                            className="absolute left-4 top-1/2 -mt-2 h-4 w-4 rounded border-gray-300 text-primary-600 focus:ring-primary-600"
                          />
                        </td>
                      )}
                      {columns.map((column) => {
                        const value = getCellValue(row, column.key);
                        const renderer = cellRenderers?.[column.key];
                        return (
                          <td key={column.key} className="whitespace-nowrap px-3 py-4 text-sm text-gray-900">
                            {renderer ? renderer({ row, value }) : value}
                          </td>
                        );
                      })}
                      {typeof renderActions === 'function' && (
                        <td className="relative whitespace-nowrap py-4 pl-3 pr-4 text-right text-sm font-medium sm:pr-6">
                          {renderActions(row)}
                        </td>
                      )}
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
        </div>
      </div>

      {pagination && !loading && (
        <div className="flex items-center justify-between border-t border-gray-200 bg-white px-4 py-3 sm:px-6 mt-4">
          <div className="hidden sm:flex sm:flex-1 sm:items-center sm:justify-between">
            <div>
              <p className="text-sm text-gray-700">
                Showing <span className="font-medium">{startIndex}</span> to <span className="font-medium">{endIndex}</span> of <span className="font-medium">{total}</span> results
              </p>
            </div>
            <nav className="isolate inline-flex -space-x-px rounded-md shadow-sm">
              <button
                onClick={() => onPageChange?.(currentPage - 1)}
                disabled={currentPage === 1}
                className="relative inline-flex items-center rounded-l-md px-2 py-2 text-gray-400 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 disabled:opacity-50"
              >
                Prev
              </button>
              {displayedPages.map((page, idx) => (
                <button
                  key={idx}
                  onClick={() => goToPage(page)}
                  className={`relative inline-flex items-center px-4 py-2 text-sm font-semibold ${page === currentPage ? 'z-10 bg-primary-600 text-white' : 'text-gray-900 ring-1 ring-inset ring-gray-300 hover:bg-gray-50'}`}
                >
                  {page}
                </button>
              ))}
              <button
                onClick={() => onPageChange?.(currentPage + 1)}
                disabled={currentPage === totalPages}
                className="relative inline-flex items-center rounded-r-md px-2 py-2 text-gray-400 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 disabled:opacity-50"
              >
                Next
              </button>
            </nav>
          </div>
        </div>
      )}
    </div>
  );
}
