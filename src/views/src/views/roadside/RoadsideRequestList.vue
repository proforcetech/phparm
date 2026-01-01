<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Roadside Requests</h1>
        <p class="mt-1 text-sm text-gray-500">Review incoming assistance requests.</p>
      </div>
    </div>

    <div class="overflow-hidden rounded-lg border border-gray-200 bg-white">
      <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
          <tr>
            <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Request</th>
            <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Customer</th>
            <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Status</th>
            <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Priority</th>
            <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Updated</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-200">
          <tr>
            <td class="px-4 py-3 text-sm font-medium text-gray-900">#1001</td>
            <td class="px-4 py-3 text-sm text-gray-600">Sample Customer</td>
            <td class="px-4 py-3 text-sm text-gray-600">New</td>
            <td class="px-4 py-3 text-sm text-gray-600">Normal</td>
            <td class="px-4 py-3 text-sm text-gray-600">Just now</td>
          </tr>
          <tr>
            <td class="px-4 py-3 text-sm font-medium text-gray-900">#1000</td>
            <td class="px-4 py-3 text-sm text-gray-600">Placeholder Customer</td>
            <td class="px-4 py-3 text-sm text-gray-600">Pending</td>
            <td class="px-4 py-3 text-sm text-gray-600">High</td>
            <td class="px-4 py-3 text-sm text-gray-600">5 minutes ago</td>
          </tr>
        </tbody>
      </table>
    </div>

    <div class="rounded-lg border border-dashed border-gray-300 bg-white p-6">
      <p class="text-sm text-gray-600">
        Request filtering, assignment, and intake actions will appear here as the roadmap progresses.
      </p>
    </div>
  </div>
</template>
