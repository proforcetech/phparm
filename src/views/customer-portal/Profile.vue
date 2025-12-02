<template>
  <div>
    <div class="mb-6">
      <h1 class="text-2xl font-bold text-gray-900">My Profile</h1>
      <p class="mt-1 text-sm text-gray-500">Update your account information</p>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
      <div class="lg:col-span-2">
        <Card title="Personal Information">
          <div class="space-y-4">
            <div class="grid grid-cols-2 gap-4">
              <Input label="First Name" placeholder="John" />
              <Input label="Last Name" placeholder="Doe" />
            </div>
            <Input label="Email" type="email" placeholder="john@example.com" />
            <Input label="Phone" type="tel" placeholder="(555) 123-4567" />
            <div class="pt-4">
              <Button>Save Changes</Button>
            </div>
          </div>
        </Card>
      </div>

      <div>
        <Card title="Account">
          <div class="space-y-4">
            <Button variant="outline" class="w-full">
              Change Password
            </Button>
            <Button variant="outline" class="w-full">
              Email Preferences
            </Button>
          </div>
        </Card>
      </div>
    </div>
  </div>
</template>

<script setup>
import Card from '@/components/ui/Card.vue'
import Input from '@/components/ui/Input.vue'
import Button from '@/components/ui/Button.vue'
</script>
