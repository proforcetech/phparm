import { useMemo } from 'react'
import { Bar } from 'react-chartjs-2'
import './chartSetup'

const defaultOptions = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: {
    legend: {
      position: 'top',
    },
  },
  scales: {
    y: {
      beginAtZero: true,
    },
  },
}

export default function BarChart({ data, options, height, width }) {
  const mergedOptions = useMemo(() => ({
    ...defaultOptions,
    ...(options ?? {}),
  }), [options])

  return <Bar data={data} options={mergedOptions} height={height} width={width} />
}
