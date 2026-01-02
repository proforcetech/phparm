import { Bar } from 'react-chartjs-2'
import './chartSetup'

const defaultData = {
  labels: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'],
  datasets: [
    {
      label: 'Sales',
      data: [12, 19, 3, 5, 2],
      backgroundColor: '#4f46e5',
    },
  ],
}

export default function BarChart({ data = defaultData, options, height, width }) {
  return <Bar data={data} options={options} height={height} width={width} />
}
