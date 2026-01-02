import Button from '../ui/Button'
import Card from '../ui/Card'
import Input from '../ui/Input'
import Select from '../ui/Select'
import Textarea from '../ui/Textarea'

export default function EstimateRequestForm({
  services = ['Brakes', 'Oil Change', 'Diagnostics'],
  onSubmit,
}) {
  const handleSubmit = (event) => {
    event.preventDefault()
    const formData = new FormData(event.currentTarget)
    const payload = Object.fromEntries(formData.entries())
    if (onSubmit) {
      onSubmit(payload)
    }
  }

  return (
    <Card>
      <form className="space-y-4" onSubmit={handleSubmit}>
        <Input label="Full name" name="name" required />
        <Input label="Email" name="email" type="email" required />
        <Select
          label="Service type"
          name="service"
          options={services.map((service) => ({ label: service, value: service }))}
        />
        <Textarea label="Notes" name="notes" rows={4} />
        <Button type="submit">Request estimate</Button>
      </form>
    </Card>
  )
}
