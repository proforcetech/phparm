import { Doughnut } from 'react-chartjs-2'
import './chartSetup'

const defaultData = {
  labels: ['Completed', 'Pending', 'Overdue'],
  datasets: [
    {
      data: [60, 25, 15],
      backgroundColor: ['#10b981', '#f59e0b', '#ef4444'],
    },
  ],
}

export default function DoughnutChart({ data = defaultData, options, height, width }) {
  return <Doughnut data={data} options={options} height={height} width={width} />
}
