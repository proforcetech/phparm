import { Line } from 'react-chartjs-2'
import './chartSetup'

const defaultData = {
  labels: ['Week 1', 'Week 2', 'Week 3', 'Week 4'],
  datasets: [
    {
      label: 'Revenue',
      data: [1200, 1900, 3000, 5000],
      borderColor: '#3b82f6',
      backgroundColor: 'rgba(59,130,246,0.3)',
      tension: 0.4,
    },
  ],
}

export default function LineChart({ data = defaultData, options, height, width }) {
  return <Line data={data} options={options} height={height} width={width} />
}
