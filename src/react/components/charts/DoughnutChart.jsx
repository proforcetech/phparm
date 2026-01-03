import { useMemo } from 'react'
import { Doughnut } from 'react-chartjs-2'
import './chartSetup'

const defaultOptions = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: {
    legend: {
      position: 'right',
    },
  },
}

export default function DoughnutChart({ data, options, height, width }) {
  const mergedOptions = useMemo(() => ({
    ...defaultOptions,
    ...(options ?? {}),
  }), [options])

  return <Doughnut data={data} options={mergedOptions} height={height} width={width} />
}
