<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Roadside Dashboard</h1>
        <p class="mt-1 text-sm text-gray-500">
          Monitor incoming roadside assistance activity and upcoming workflows.
        </p>
      </div>
    </div>

    <div class="grid gap-4 md:grid-cols-3">
      <div class="rounded-lg border border-gray-200 bg-white p-4">
        <p class="text-xs uppercase tracking-wide text-gray-500">Open Requests</p>
        <p class="mt-2 text-2xl font-semibold text-gray-900">0</p>
        <p class="mt-1 text-sm text-gray-500">Awaiting new requests.</p>
      </div>
      <div class="rounded-lg border border-gray-200 bg-white p-4">
        <p class="text-xs uppercase tracking-wide text-gray-500">Active Dispatches</p>
        <p class="mt-2 text-2xl font-semibold text-gray-900">0</p>
        <p class="mt-1 text-sm text-gray-500">No vehicles assigned yet.</p>
      </div>
      <div class="rounded-lg border border-gray-200 bg-white p-4">
        <p class="text-xs uppercase tracking-wide text-gray-500">Resolved Today</p>
        <p class="mt-2 text-2xl font-semibold text-gray-900">0</p>
        <p class="mt-1 text-sm text-gray-500">Placeholder metrics.</p>
      </div>
    </div>

    <div class="rounded-lg border border-dashed border-gray-300 bg-white p-6">
      <h2 class="text-lg font-semibold text-gray-900">Next Steps</h2>
      <p class="mt-2 text-sm text-gray-600">
        This dashboard will surface real-time roadside requests, dispatch status, and
        operator actions once the feature set is wired in.
      </p>
    </div>
  </div>
</template>
