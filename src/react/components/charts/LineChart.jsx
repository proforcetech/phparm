import { useMemo } from 'react'
import { Line } from 'react-chartjs-2'
import './chartSetup'

const defaultOptions = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: {
    legend: {
      position: 'top',
    },
    tooltip: {
      mode: 'index',
      intersect: false,
    },
  },
  scales: {
    y: {
      beginAtZero: true,
      ticks: {
        callback(value) {
          return `$${value.toLocaleString()}`
        },
      },
    },
  },
  interaction: {
    mode: 'nearest',
    axis: 'x',
    intersect: false,
  },
}

export default function LineChart({ data, options, height, width }) {
  const mergedOptions = useMemo(() => ({
    ...defaultOptions,
    ...(options ?? {}),
  }), [options])

  return <Line data={data} options={mergedOptions} height={height} width={width} />
}
