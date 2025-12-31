<template>
  <div>
    <!-- Loading State -->
    <div v-if="loading" class="flex justify-center py-12">
      <Loading size="xl" text="Loading workorder..." />
    </div>

    <!-- Error State -->
    <Alert v-else-if="error" variant="danger" class="mb-6">
      {{ error }}
    </Alert>

    <!-- Workorder Details -->
    <div v-else-if="workorder">
      <!-- Header -->
      <div class="mb-6">
        <div class="flex items-center justify-between mb-2">
          <div class="flex items-center gap-4">
            <Button variant="ghost" @click="$router.back()">
              <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
              </svg>
            </Button>
            <div>
              <h1 class="text-2xl font-bold text-gray-900">Workorder {{ workorder.number }}</h1>
              <p class="text-sm text-gray-500">Created {{ formatDate(workorder.created_at) }}</p>
            </div>
          </div>
          <div class="flex items-center gap-2">
            <Badge :variant="getPriorityVariant(workorder.priority)" size="lg">
              {{ formatStatus(workorder.priority) }}
            </Badge>
            <Badge :variant="getStatusVariant(workorder.status)" size="lg">
              {{ formatStatus(workorder.status) }}
            </Badge>
          </div>
        </div>

        <!-- Actions -->
        <div class="flex gap-2 mt-4">
          <Button
            v-if="workorder.status === 'pending'"
            variant="primary"
            @click="updateStatus('in_progress', 'Work started')"
          >
            <svg class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z" />
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            Start Work
          </Button>
          <Button
            v-if="workorder.status === 'in_progress'"
            variant="warning"
            @click="updateStatus('on_hold', 'Work paused')"
          >
            <svg class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            Put On Hold
          </Button>
          <Button
            v-if="workorder.status === 'on_hold'"
            variant="primary"
            @click="updateStatus('in_progress', 'Work resumed')"
          >
            Resume Work
          </Button>
          <Button
            v-if="workorder.status === 'in_progress'"
            variant="success"
            @click="updateStatus('completed', 'All work completed')"
          >
            <svg class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            Mark Complete
          </Button>
          <Button
            v-if="workorder.status === 'completed'"
            @click="showConvertModal = true"
          >
            <svg class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
            </svg>
            Convert to Invoice
          </Button>
          <Button
            v-if="['pending', 'in_progress', 'on_hold'].includes(workorder.status)"
            variant="outline"
            @click="showSubEstimateModal = true"
          >
            <svg class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
            </svg>
            Create Sub-Estimate
          </Button>
          <Button
            v-if="['pending', 'in_progress', 'on_hold'].includes(workorder.status)"
            variant="outline"
            @click="showAssignModal = true"
          >
            <svg class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
            </svg>
            {{ workorder.assigned_technician_id ? 'Reassign' : 'Assign Technician' }}
          </Button>
          <Button
            v-if="['pending', 'in_progress', 'on_hold'].includes(workorder.status)"
            variant="outline"
            @click="showPriorityModal = true"
          >
            Change Priority
          </Button>
        </div>
      </div>

      <!-- Main Content Grid -->
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Left Column - Details & Jobs -->
        <div class="lg:col-span-2 space-y-6">
          <!-- Customer & Vehicle Info -->
          <Card>
            <template #header>
              <h3 class="text-lg font-medium text-gray-900">Customer & Vehicle</h3>
            </template>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
              <div>
                <label class="text-sm font-medium text-gray-500">Customer</label>
                <p class="mt-1 text-sm text-gray-900">
                  <router-link
                    :to="`/cp/customers/${workorder.customer_id}`"
                    class="text-primary-600 hover:text-primary-800"
                  >
                    {{ workorder.customer?.name || `Customer #${workorder.customer_id}` }}
                  </router-link>
                </p>
              </div>
              <div>
                <label class="text-sm font-medium text-gray-500">Vehicle</label>
                <p class="mt-1 text-sm text-gray-900">
                  <router-link
                    :to="`/cp/vehicles/${workorder.vehicle_id}`"
                    class="text-primary-600 hover:text-primary-800"
                  >
                    {{ workorder.vehicle?.display_name || `Vehicle #${workorder.vehicle_id}` }}
                  </router-link>
                </p>
              </div>
              <div>
                <label class="text-sm font-medium text-gray-500">Assigned Technician</label>
                <p class="mt-1 text-sm text-gray-900">
                  <span v-if="workorder.assigned_technician_id">
                    {{ getTechnicianName(workorder.assigned_technician_id) }}
                  </span>
                  <span v-else class="text-gray-400 italic">Unassigned</span>
                </p>
              </div>
              <div>
                <label class="text-sm font-medium text-gray-500">Source Estimate</label>
                <p class="mt-1 text-sm text-gray-900">
                  <router-link
                    :to="`/cp/estimates/${workorder.estimate_id}`"
                    class="text-primary-600 hover:text-primary-800"
                  >
                    View Estimate
                  </router-link>
                </p>
              </div>
            </div>
            <div v-if="workorder.started_at || workorder.completed_at" class="grid grid-cols-2 gap-4 mt-4 pt-4 border-t border-gray-200">
              <div v-if="workorder.started_at">
                <label class="text-sm font-medium text-gray-500">Started</label>
                <p class="mt-1 text-sm text-gray-900">{{ formatDateTime(workorder.started_at) }}</p>
              </div>
              <div v-if="workorder.completed_at">
                <label class="text-sm font-medium text-gray-500">Completed</label>
                <p class="mt-1 text-sm text-gray-900">{{ formatDateTime(workorder.completed_at) }}</p>
              </div>
            </div>
          </Card>

          <!-- Jobs List -->
          <Card>
            <template #header>
              <div class="flex items-center justify-between">
                <h3 class="text-lg font-medium text-gray-900">Jobs</h3>
                <div class="text-sm text-gray-500">
                  {{ completedJobsCount }} / {{ jobs.length }} completed
                </div>
              </div>
            </template>

            <div v-if="jobs.length === 0" class="text-center py-8 text-gray-500">
              No jobs found
            </div>

            <div v-else class="space-y-4">
              <div
                v-for="job in jobs"
                :key="job.id"
                class="border border-gray-200 rounded-lg p-4"
                :class="{
                  'bg-green-50 border-green-200': job.status === 'completed',
                  'bg-blue-50 border-blue-200': job.status === 'in_progress'
                }"
              >
                <div class="flex items-start justify-between mb-2">
                  <div>
                    <h4 class="font-medium text-gray-900">{{ job.title || job.description }}</h4>
                    <p v-if="job.notes" class="text-sm text-gray-500 mt-1">{{ job.notes }}</p>
                  </div>
                  <div class="flex items-center gap-2">
                    <Badge :variant="getJobStatusVariant(job.status)">
                      {{ formatStatus(job.status) }}
                    </Badge>
                    <span class="font-medium text-gray-900">{{ formatCurrency(job.total) }}</span>
                  </div>
                </div>

                <!-- Job Items -->
                <div v-if="job.items && job.items.length > 0" class="mt-3 border-t border-gray-200 pt-3">
                  <table class="min-w-full text-sm">
                    <thead>
                      <tr class="text-gray-500 text-left">
                        <th class="font-medium pb-1">Item</th>
                        <th class="font-medium pb-1 text-right">Qty</th>
                        <th class="font-medium pb-1 text-right">Unit Price</th>
                        <th class="font-medium pb-1 text-right">Total</th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr v-for="item in job.items" :key="item.id">
                        <td class="py-1">
                          <span class="capitalize">{{ item.type }}</span>: {{ item.description }}
                        </td>
                        <td class="py-1 text-right">{{ item.quantity }}</td>
                        <td class="py-1 text-right">{{ formatCurrency(item.unit_price) }}</td>
                        <td class="py-1 text-right">{{ formatCurrency(getItemLineTotal(item)) }}</td>
                      </tr>
                    </tbody>
                  </table>
                </div>

                <!-- Job Actions -->
                <div class="mt-3 pt-3 border-t border-gray-200 flex items-center justify-between">
                  <div class="flex items-center gap-2 text-sm text-gray-500">
                    <span v-if="job.technician_id">
                      Assigned to: {{ getTechnicianName(job.technician_id) }}
                    </span>
                    <span v-else class="italic">Unassigned</span>
                    <Button
                      variant="ghost"
                      size="sm"
                      @click="showJobAssignModal(job)"
                      title="Assign technician"
                    >
                      <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                      </svg>
                    </Button>
                  </div>
                  <div class="flex gap-2">
                    <Button
                      v-if="job.status === 'pending'"
                      variant="outline"
                      size="sm"
                      @click="updateJobStatus(job.id, 'in_progress')"
                    >
                      Start
                    </Button>
                    <Button
                      v-if="job.status === 'in_progress'"
                      variant="success"
                      size="sm"
                      @click="updateJobStatus(job.id, 'completed')"
                    >
                      Complete
                    </Button>
                    <Button
                      v-if="job.status === 'completed'"
                      variant="ghost"
                      size="sm"
                      @click="updateJobStatus(job.id, 'in_progress')"
                    >
                      Reopen
                    </Button>
                  </div>
                </div>
              </div>
            </div>
          </Card>

          <!-- Pull Requests (Parts/Supplies) -->
          <Card>
            <template #header>
              <div class="flex items-center justify-between">
                <h3 class="text-lg font-medium text-gray-900">Parts & Supplies</h3>
                <Button variant="outline" size="sm" @click="showPullRequestModal = true">
                  <svg class="h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                  </svg>
                  Request Part
                </Button>
              </div>
            </template>

            <div v-if="pullRequests.length === 0" class="text-center py-4 text-gray-500">
              No parts requested yet
            </div>

            <div v-else class="space-y-3">
              <div
                v-for="pr in pullRequests"
                :key="pr.id"
                class="flex items-center justify-between p-3 border border-gray-200 rounded-lg"
                :class="{
                  'bg-green-50 border-green-200': pr.status === 'pulled' || pr.status === 'received',
                  'bg-yellow-50 border-yellow-200': pr.status === 'ordered',
                  'bg-red-50 border-red-200': pr.status === 'cancelled'
                }"
              >
                <div class="flex-1">
                  <div class="flex items-center gap-2">
                    <span class="font-medium text-gray-900">{{ pr.description }}</span>
                    <Badge :variant="pr.request_type === 'pull' ? 'success' : 'warning'" size="sm">
                      {{ pr.request_type === 'pull' ? 'In Stock' : 'Order' }}
                    </Badge>
                    <Badge :variant="getPullRequestStatusVariant(pr.status)" size="sm">
                      {{ formatStatus(pr.status) }}
                    </Badge>
                  </div>
                  <p class="text-sm text-gray-500 mt-1">
                    Qty: {{ pr.quantity_fulfilled }}/{{ pr.quantity_requested }}
                    <span v-if="pr.sku" class="ml-2">SKU: {{ pr.sku }}</span>
                    <span class="ml-2">{{ formatCurrency(pr.unit_price) }} each</span>
                  </p>
                </div>
                <div class="flex gap-2">
                  <Button
                    v-if="pr.status === 'pending' && pr.request_type === 'pull'"
                    size="sm"
                    variant="success"
                    @click="pullFromStock(pr)"
                  >
                    Pull
                  </Button>
                  <Button
                    v-if="pr.status === 'pending' && pr.request_type === 'order'"
                    size="sm"
                    variant="primary"
                    @click="markAsOrdered(pr)"
                  >
                    Order
                  </Button>
                  <Button
                    v-if="pr.status === 'ordered'"
                    size="sm"
                    variant="success"
                    @click="markAsReceived(pr)"
                  >
                    Received
                  </Button>
                  <Button
                    v-if="['pending', 'ordered'].includes(pr.status)"
                    size="sm"
                    variant="ghost"
                    @click="cancelPullRequest(pr)"
                  >
                    <svg class="h-4 w-4 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                  </Button>
                </div>
              </div>
            </div>
          </Card>

          <!-- Sub-Estimates -->
          <Card v-if="subEstimates.length > 0">
            <template #header>
              <h3 class="text-lg font-medium text-gray-900">Sub-Estimates (Additional Work)</h3>
            </template>

            <div class="space-y-3">
              <div
                v-for="subEst in subEstimates"
                :key="subEst.id"
                class="flex items-center justify-between p-3 border border-gray-200 rounded-lg"
              >
                <div>
                  <div class="flex items-center gap-2">
                    <router-link
                      :to="`/cp/estimates/${subEst.id}`"
                      class="font-medium text-primary-600 hover:text-primary-800"
                    >
                      {{ subEst.number }}
                    </router-link>
                    <Badge :variant="getEstimateStatusVariant(subEst.status)">
                      {{ formatStatus(subEst.status) }}
                    </Badge>
                  </div>
                  <p class="text-sm text-gray-500 mt-1">
                    {{ formatCurrency(subEst.grand_total) }}
                  </p>
                </div>
                <Button
                  v-if="subEst.status === 'approved'"
                  variant="primary"
                  size="sm"
                  @click="addSubEstimateJobs(subEst.id)"
                >
                  Add Jobs to Workorder
                </Button>
              </div>
            </div>
          </Card>

          <!-- Notes -->
          <Card v-if="workorder.notes">
            <template #header>
              <h3 class="text-lg font-medium text-gray-900">Notes</h3>
            </template>
            <p class="text-sm text-gray-900 whitespace-pre-wrap">{{ workorder.notes }}</p>
          </Card>
        </div>

        <!-- Right Column - Summary & Timeline -->
        <div class="space-y-6">
          <!-- Financial Summary -->
          <Card>
            <template #header>
              <h3 class="text-lg font-medium text-gray-900">Summary</h3>
            </template>
            <div class="space-y-3">
              <div class="flex justify-between">
                <span class="text-sm text-gray-600">Subtotal</span>
                <span class="text-sm font-medium text-gray-900">{{ formatCurrency(workorder.subtotal) }}</span>
              </div>
              <div v-if="workorder.shop_fee > 0" class="flex justify-between">
                <span class="text-sm text-gray-600">Shop Fee</span>
                <span class="text-sm font-medium text-gray-900">{{ formatCurrency(workorder.shop_fee) }}</span>
              </div>
              <div v-if="workorder.hazmat_disposal_fee > 0" class="flex justify-between">
                <span class="text-sm text-gray-600">Hazmat Disposal</span>
                <span class="text-sm font-medium text-gray-900">{{ formatCurrency(workorder.hazmat_disposal_fee) }}</span>
              </div>
              <div v-if="workorder.discounts > 0" class="flex justify-between text-green-600">
                <span class="text-sm">Discounts</span>
                <span class="text-sm font-medium">-{{ formatCurrency(workorder.discounts) }}</span>
              </div>
              <div class="flex justify-between">
                <span class="text-sm text-gray-600">Tax</span>
                <span class="text-sm font-medium text-gray-900">{{ formatCurrency(workorder.tax) }}</span>
              </div>
              <div class="border-t border-gray-200 pt-3 flex justify-between">
                <span class="text-base font-medium text-gray-900">Grand Total</span>
                <span class="text-base font-bold text-gray-900">{{ formatCurrency(workorder.grand_total) }}</span>
              </div>
            </div>
          </Card>

          <!-- Status Timeline -->
          <Card>
            <template #header>
              <h3 class="text-lg font-medium text-gray-900">Status History</h3>
            </template>

            <div v-if="statusHistory.length === 0" class="text-center py-4 text-gray-500">
              No status changes yet
            </div>

            <div v-else class="flow-root">
              <ul role="list" class="-mb-8">
                <li v-for="(event, idx) in statusHistory" :key="event.id">
                  <div class="relative pb-8">
                    <span
                      v-if="idx !== statusHistory.length - 1"
                      class="absolute left-4 top-4 -ml-px h-full w-0.5 bg-gray-200"
                      aria-hidden="true"
                    ></span>
                    <div class="relative flex space-x-3">
                      <div>
                        <span
                          class="h-8 w-8 rounded-full flex items-center justify-center ring-8 ring-white"
                          :class="getTimelineIconBg(event.new_status)"
                        >
                          <svg class="h-4 w-4 text-white" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                          </svg>
                        </span>
                      </div>
                      <div class="flex min-w-0 flex-1 justify-between space-x-4 pt-1.5">
                        <div>
                          <p class="text-sm text-gray-500">
                            Changed to <span class="font-medium text-gray-900">{{ formatStatus(event.new_status) }}</span>
                          </p>
                          <p v-if="event.notes" class="text-xs text-gray-400 mt-0.5">{{ event.notes }}</p>
                        </div>
                        <div class="whitespace-nowrap text-right text-sm text-gray-500">
                          {{ formatDateTime(event.created_at) }}
                        </div>
                      </div>
                    </div>
                  </div>
                </li>
              </ul>
            </div>
          </Card>
        </div>
      </div>
    </div>

    <!-- Convert to Invoice Modal -->
    <Modal v-model="showConvertModal" @close="showConvertModal = false">
      <template #title>Convert to Invoice</template>
      <template #content>
        <div class="space-y-4">
          <p class="text-sm text-gray-600">
            Convert workorder #{{ workorder?.number }} to an invoice?
          </p>
          <Alert variant="info">
            This will create an invoice with all completed work from this workorder.
          </Alert>
          <div>
            <label class="block text-sm font-medium text-gray-700">Due Date (Optional)</label>
            <Input
              v-model="convertForm.due_date"
              type="date"
              class="mt-1"
            />
          </div>
        </div>
      </template>
      <template #footer>
        <div class="flex justify-end gap-3">
          <Button variant="outline" @click="showConvertModal = false">Cancel</Button>
          <Button @click="confirmConvert" :disabled="converting">
            {{ converting ? 'Converting...' : 'Convert to Invoice' }}
          </Button>
        </div>
      </template>
    </Modal>

    <!-- Assign Technician Modal -->
    <Modal v-model="showAssignModal" @close="showAssignModal = false">
      <template #title>Assign Technician</template>
      <template #content>
        <div>
          <label class="block text-sm font-medium text-gray-700">Technician</label>
          <Select
            v-model="assignForm.technician_id"
            :options="technicianOptions"
            placeholder="Select technician"
            class="mt-1"
          />
        </div>
      </template>
      <template #footer>
        <div class="flex justify-end gap-3">
          <Button variant="outline" @click="showAssignModal = false">Cancel</Button>
          <Button @click="confirmAssign" :disabled="assigning">
            {{ assigning ? 'Assigning...' : 'Assign' }}
          </Button>
        </div>
      </template>
    </Modal>

    <!-- Assign Job Technician Modal -->
    <Modal v-model="showJobAssign" @close="showJobAssign = false">
      <template #title>Assign Technician to Job</template>
      <template #content>
        <div>
          <p class="text-sm text-gray-600 mb-4">
            Assign a technician to: <strong>{{ selectedJob?.description }}</strong>
          </p>
          <label class="block text-sm font-medium text-gray-700">Technician</label>
          <Select
            v-model="jobAssignForm.technician_id"
            :options="technicianOptions"
            placeholder="Select technician"
            class="mt-1"
          />
        </div>
      </template>
      <template #footer>
        <div class="flex justify-end gap-3">
          <Button variant="outline" @click="showJobAssign = false">Cancel</Button>
          <Button @click="confirmJobAssign" :disabled="assigning">
            {{ assigning ? 'Assigning...' : 'Assign' }}
          </Button>
        </div>
      </template>
    </Modal>

    <!-- Change Priority Modal -->
    <Modal v-model="showPriorityModal" @close="showPriorityModal = false">
      <template #title>Change Priority</template>
      <template #content>
        <div>
          <label class="block text-sm font-medium text-gray-700">Priority</label>
          <Select
            v-model="priorityForm.priority"
            :options="priorityOptions"
            class="mt-1"
          />
        </div>
      </template>
      <template #footer>
        <div class="flex justify-end gap-3">
          <Button variant="outline" @click="showPriorityModal = false">Cancel</Button>
          <Button @click="confirmPriority" :disabled="updatingPriority">
            {{ updatingPriority ? 'Updating...' : 'Update Priority' }}
          </Button>
        </div>
      </template>
    </Modal>

    <!-- Create Sub-Estimate Modal -->
    <Modal v-model="showSubEstimateModal" @close="showSubEstimateModal = false" size="lg">
      <template #title>Create Sub-Estimate for Additional Work</template>
      <template #content>
        <div class="space-y-6">
          <Alert variant="info">
            Create a sub-estimate for additional work discovered during repair.
            The customer will need to approve this before work can proceed.
          </Alert>

          <div class="flex items-center justify-between">
            <h4 class="text-sm font-medium text-gray-700">Jobs</h4>
            <Button variant="outline" size="sm" @click="addSubEstimateJob" type="button">
              <svg class="h-4 w-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
              </svg>
              Add Job
            </Button>
          </div>

          <div v-for="(job, idx) in subEstimateForm.jobs" :key="idx" class="border border-gray-200 rounded-lg p-4">
            <div class="flex items-start justify-between mb-3">
              <h4 class="font-medium text-gray-900">Job {{ idx + 1 }}</h4>
              <Button
                v-if="subEstimateForm.jobs.length > 1"
                variant="ghost"
                size="sm"
                @click="removeSubEstimateJob(idx)"
                type="button"
              >
                <svg class="h-4 w-4 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
              </Button>
            </div>

            <div class="space-y-3">
              <div>
                <Input
                  v-model="job.title"
                  label="Job Title *"
                  placeholder="e.g., Replace brake pads"
                  required
                />
              </div>

              <div>
                <Textarea
                  v-model="job.notes"
                  label="Job Notes"
                  placeholder="Additional notes for this job (optional)"
                  :rows="2"
                />
              </div>

              <div class="space-y-3">
                <div class="flex items-center justify-between">
                  <h5 class="text-sm font-medium text-gray-700">Line Items</h5>
                  <Button
                    variant="outline"
                    size="sm"
                    @click="addSubEstimateLineItem(idx)"
                    type="button"
                  >
                    <svg class="h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                    </svg>
                    Add Item
                  </Button>
                </div>

                <div
                  v-for="(item, itemIndex) in job.items"
                  :key="itemIndex"
                  class="bg-white border border-gray-200 rounded p-3"
                >
                  <div class="grid grid-cols-12 gap-3">
                    <div class="col-span-12 md:col-span-2">
                      <Select
                        v-model="item.type"
                        label="Type"
                        :options="[
                          { value: 'LABOR', label: 'Labor' },
                          { value: 'PART', label: 'Part' }
                        ]"
                        required
                        @change="onSubEstimateItemTypeChange(item)"
                      />
                    </div>

                    <div v-if="item.type === 'PART'" class="col-span-12 md:col-span-2">
                      <Input
                        v-model="item.sku"
                        label="SKU"
                        placeholder="SKU / Part #"
                        @blur="lookupSubEstimateSku(item)"
                      />
                    </div>

                    <div :class="item.type === 'PART' ? 'col-span-12 md:col-span-4' : 'col-span-12 md:col-span-6'">
                      <Autocomplete
                        v-if="item.type === 'PART'"
                        v-model="item.description"
                        label="Description"
                        placeholder="Search or enter part description..."
                        :search-fn="(query) => searchSubEstimateInventoryParts(query)"
                        :item-value="(inv) => inv.name"
                        :item-label="(inv) => inv.name"
                        :item-subtext="(inv) => inv.sku ? `SKU: ${inv.sku}` : ''"
                        @select="(inv) => selectSubEstimateInventoryItem(item, inv)"
                        required
                        free-text
                      />
                      <Input
                        v-else
                        v-model="item.description"
                        label="Description"
                        placeholder="Describe the work"
                        required
                      />
                    </div>

                    <div class="col-span-6 md:col-span-2">
                      <Input
                        v-model.number="item.quantity"
                        type="number"
                        label="Qty"
                        min="0"
                        step="0.01"
                        required
                      />
                    </div>

                    <div class="col-span-6 md:col-span-2">
                      <Input
                        v-model.number="item.unit_price"
                        type="number"
                        label="Unit Price"
                        min="0"
                        step="0.01"
                        required
                      />
                    </div>

                    <div v-if="item.type === 'PART'" class="col-span-6 md:col-span-2">
                      <Input
                        v-model.number="item.list_price"
                        type="number"
                        label="List"
                        min="0"
                        step="0.01"
                      />
                    </div>

                    <div class="col-span-6 md:col-span-2 flex items-end">
                      <label class="flex items-center gap-2 text-xs">
                        <input v-model="item.taxable" type="checkbox" class="h-4 w-4 text-indigo-600 rounded" />
                        <span>Tax</span>
                      </label>
                    </div>

                    <div class="col-span-6 md:col-span-2 flex items-end justify-end">
                      <Button
                        variant="ghost"
                        size="sm"
                        @click="removeSubEstimateLineItem(idx, itemIndex)"
                        type="button"
                        :disabled="job.items.length === 1"
                      >
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                          <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"
                          />
                        </svg>
                      </Button>
                    </div>

                    <div class="col-span-12 flex items-center justify-between text-sm">
                      <span class="text-gray-600">Line Total:</span>
                      <span class="font-semibold">{{ formatCurrency(calculateSubEstimateLineTotal(item)) }}</span>
                    </div>
                  </div>
                </div>
              </div>

              <div class="mt-4 pt-3 border-t border-gray-300 flex justify-between text-sm font-medium">
                <span>Job Subtotal:</span>
                <span>{{ formatCurrency(calculateSubEstimateJobSubtotal(job)) }}</span>
              </div>
            </div>
          </div>

          <div class="border border-gray-200 rounded-lg p-4">
            <label class="block text-sm font-medium text-gray-700">Tax Rate (%)</label>
            <Input v-model.number="subEstimateForm.tax_rate" type="number" min="0" step="0.01" class="mt-1" />
          </div>

          <Button variant="outline" @click="addSubEstimateJob" class="w-full" type="button">
            <svg class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
            </svg>
            Add Another Job
          </Button>
        </div>
      </template>
      <template #footer>
        <div class="flex justify-end gap-3">
          <Button variant="outline" @click="showSubEstimateModal = false">Cancel</Button>
          <Button @click="confirmSubEstimate" :disabled="creatingSubEstimate || !isSubEstimateValid">
            {{ creatingSubEstimate ? 'Creating...' : 'Create Sub-Estimate' }}
          </Button>
        </div>
      </template>
    </Modal>

    <!-- Request Part Modal -->
    <Modal v-model="showPullRequestModal" @close="showPullRequestModal = false" size="lg">
      <template #title>Request Part/Supply</template>
      <template #content>
        <div class="space-y-4">
          <Alert variant="info">
            Search for an existing inventory item or enter details for a new part.
          </Alert>

          <!-- Search existing inventory -->
          <div>
            <label class="block text-sm font-medium text-gray-700">Search Inventory</label>
            <Autocomplete
              v-model="pullRequestForm.selectedItem"
              placeholder="Search by name or SKU..."
              :search-fn="searchInventory"
              :item-value="(item) => item.id"
              :item-label="(item) => item.name"
              :item-subtext="(item) => `SKU: ${item.sku || 'N/A'} | ${item.is_tracked ? `Stock: ${item.stock_quantity}` : 'Catalog Item'} | $${item.sale_price}`"
              @select="selectInventoryItem"
              class="mt-1"
            />
          </div>

          <div class="border-t border-gray-200 pt-4">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
              <div class="md:col-span-2">
                <label class="block text-sm font-medium text-gray-700">Description *</label>
                <Input
                  v-model="pullRequestForm.description"
                  placeholder="Part description"
                  class="mt-1"
                  required
                />
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700">SKU</label>
                <Input
                  v-model="pullRequestForm.sku"
                  placeholder="SKU-1234"
                  class="mt-1"
                />
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700">Quantity *</label>
                <Input
                  v-model.number="pullRequestForm.quantity_requested"
                  type="number"
                  min="1"
                  class="mt-1"
                />
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700">Unit Cost</label>
                <Input
                  v-model.number="pullRequestForm.unit_cost"
                  type="number"
                  step="0.01"
                  min="0"
                  class="mt-1"
                />
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700">Unit Price</label>
                <Input
                  v-model.number="pullRequestForm.unit_price"
                  type="number"
                  step="0.01"
                  min="0"
                  class="mt-1"
                />
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700">Vendor</label>
                <Input
                  v-model="pullRequestForm.vendor"
                  placeholder="Vendor name"
                  class="mt-1"
                />
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700">Job (Optional)</label>
                <Select
                  v-model="pullRequestForm.workorder_job_id"
                  :options="jobOptions"
                  placeholder="Select a job"
                  class="mt-1"
                />
              </div>
              <div class="md:col-span-2">
                <label class="block text-sm font-medium text-gray-700">Notes</label>
                <textarea
                  v-model="pullRequestForm.notes"
                  rows="2"
                  class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm"
                  placeholder="Additional notes..."
                ></textarea>
              </div>
            </div>
          </div>
        </div>
      </template>
      <template #footer>
        <div class="flex justify-end gap-3">
          <Button variant="outline" @click="showPullRequestModal = false">Cancel</Button>
          <Button @click="createPullRequest" :disabled="creatingPullRequest || !pullRequestForm.description">
            {{ creatingPullRequest ? 'Creating...' : 'Create Request' }}
          </Button>
        </div>
      </template>
    </Modal>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import Card from '@/components/ui/Card.vue'
import Button from '@/components/ui/Button.vue'
import Badge from '@/components/ui/Badge.vue'
import Input from '@/components/ui/Input.vue'
import Select from '@/components/ui/Select.vue'
import Alert from '@/components/ui/Alert.vue'
import Loading from '@/components/ui/Loading.vue'
import Modal from '@/components/ui/Modal.vue'
import Autocomplete from '@/components/ui/Autocomplete.vue'
import Textarea from '@/components/ui/Textarea.vue'
import workorderService from '@/services/workorder.service'
import userService from '@/services/user.service'
import pullRequestService from '@/services/pull-request.service'
import inventoryService from '@/services/inventory.service'
import { useToast } from '@/stores/toast'

const router = useRouter()
const route = useRoute()
const toast = useToast()

const loading = ref(true)
const error = ref(null)
const workorder = ref(null)
const jobs = ref([])
const subEstimates = ref([])
const statusHistory = ref([])
const technicians = ref([])
const pullRequests = ref([])

function createSubEstimateItem() {
  return {
    type: 'LABOR',
    sku: '',
    inventory_item_id: null,
    description: '',
    quantity: 1,
    unit_price: 0,
    list_price: null,
    taxable: true,
  }
}

function createSubEstimateJob() {
  return {
    title: '',
    notes: '',
    items: [createSubEstimateItem()],
  }
}

// Modal states
const showConvertModal = ref(false)
const showAssignModal = ref(false)
const showJobAssign = ref(false)
const showPriorityModal = ref(false)
const showSubEstimateModal = ref(false)
const showPullRequestModal = ref(false)
const selectedJob = ref(null)

// Loading states
const converting = ref(false)
const assigning = ref(false)
const updatingPriority = ref(false)
const creatingSubEstimate = ref(false)
const creatingPullRequest = ref(false)

// Form data
const convertForm = reactive({ due_date: '' })
const assignForm = reactive({ technician_id: '' })
const pullRequestForm = reactive({
  selectedItem: null,
  inventory_item_id: null,
  description: '',
  sku: '',
  quantity_requested: 1,
  unit_cost: 0,
  unit_price: 0,
  vendor: '',
  workorder_job_id: '',
  notes: '',
})
const jobAssignForm = reactive({ technician_id: '' })
const priorityForm = reactive({ priority: '' })
const subEstimateForm = reactive({
  tax_rate: 0,
  jobs: [createSubEstimateJob()]
})

const technicianOptions = ref([{ value: '', label: 'Unassigned' }])

const priorityOptions = [
  { value: 'urgent', label: 'Urgent' },
  { value: 'high', label: 'High' },
  { value: 'normal', label: 'Normal' },
  { value: 'low', label: 'Low' }
]

const completedJobsCount = computed(() => {
  return jobs.value.filter(j => j.status === 'completed').length
})

const isSubEstimateValid = computed(() => {
  return subEstimateForm.jobs.every((job) => {
    if (!job.title || !job.title.trim()) return false
    if (!job.items.length) return false
    return job.items.every((item) => item.description && item.description.trim() !== '')
  })
})

const jobOptions = computed(() => {
  return [
    { value: '', label: 'No specific job' },
    ...jobs.value.map(j => ({ value: j.id, label: j.title || j.description }))
  ]
})

onMounted(() => {
  loadWorkorder()
  loadTechnicians()
  loadPullRequests()
})

async function loadWorkorder() {
  try {
    loading.value = true
    error.value = null
    const response = await workorderService.getWorkorder(route.params.id)
    const data = response.data

    workorder.value = data
    jobs.value = data.jobs || []
    subEstimates.value = data.sub_estimates || []
    statusHistory.value = data.status_history || []

    // Set priority form default
    priorityForm.priority = data.priority || 'normal'
    assignForm.technician_id = data.assigned_technician_id || ''
  } catch (err) {
    console.error('Failed to load workorder:', err)
    error.value = err.response?.data?.message || 'Failed to load workorder'
  } finally {
    loading.value = false
  }
}

async function loadTechnicians() {
  try {
    const users = await userService.listUsers({ role: 'technician' }) || []
    technicians.value = users
    technicianOptions.value = [
      { value: '', label: 'Unassigned' },
      ...users.map(u => ({ value: u.id, label: u.name }))
    ]
  } catch (err) {
    console.error('Failed to load technicians:', err)
  }
}

async function loadPullRequests() {
  try {
    const response = await pullRequestService.getByWorkorder(route.params.id)
    pullRequests.value = response.data?.items || []
  } catch (err) {
    console.error('Failed to load pull requests:', err)
  }
}

async function searchInventory(query) {
  if (!query || query.length < 2) return []
  try {
    const response = await inventoryService.searchParts(query, null, 10)
    return response.data || []
  } catch (err) {
    console.error('Failed to search inventory:', err)
    return []
  }
}

function selectInventoryItem(item) {
  if (!item) return
  pullRequestForm.inventory_item_id = item.id
  pullRequestForm.description = item.name
  pullRequestForm.sku = item.sku || ''
  pullRequestForm.unit_cost = item.cost || 0
  pullRequestForm.unit_price = item.sale_price || 0
  pullRequestForm.vendor = item.vendor || ''
}

function resetPullRequestForm() {
  pullRequestForm.selectedItem = null
  pullRequestForm.inventory_item_id = null
  pullRequestForm.description = ''
  pullRequestForm.sku = ''
  pullRequestForm.quantity_requested = 1
  pullRequestForm.unit_cost = 0
  pullRequestForm.unit_price = 0
  pullRequestForm.vendor = ''
  pullRequestForm.workorder_job_id = ''
  pullRequestForm.notes = ''
}

async function createPullRequest() {
  creatingPullRequest.value = true
  try {
    await pullRequestService.create({
      workorder_id: workorder.value.id,
      workorder_job_id: pullRequestForm.workorder_job_id || null,
      inventory_item_id: pullRequestForm.inventory_item_id || null,
      description: pullRequestForm.description,
      sku: pullRequestForm.sku || null,
      quantity_requested: pullRequestForm.quantity_requested,
      unit_cost: pullRequestForm.unit_cost,
      unit_price: pullRequestForm.unit_price,
      vendor: pullRequestForm.vendor || null,
      notes: pullRequestForm.notes || null,
    })
    toast.success('Part request created')
    showPullRequestModal.value = false
    resetPullRequestForm()
    loadPullRequests()
  } catch (err) {
    console.error('Failed to create pull request:', err)
    toast.error(err.response?.data?.error || 'Failed to create request')
  } finally {
    creatingPullRequest.value = false
  }
}

async function pullFromStock(pr) {
  try {
    await pullRequestService.markAsPulled(pr.id, pr.quantity_requested - pr.quantity_fulfilled)
    toast.success('Item pulled from inventory')
    loadPullRequests()
  } catch (err) {
    console.error('Failed to pull from stock:', err)
    toast.error('Failed to pull from inventory')
  }
}

async function markAsOrdered(pr) {
  try {
    await pullRequestService.markAsOrdered(pr.id)
    toast.success('Item marked as ordered')
    loadPullRequests()
  } catch (err) {
    console.error('Failed to mark as ordered:', err)
    toast.error('Failed to mark as ordered')
  }
}

async function markAsReceived(pr) {
  try {
    await pullRequestService.markAsReceived(pr.id, pr.quantity_requested - pr.quantity_fulfilled)
    toast.success('Item marked as received')
    loadPullRequests()
  } catch (err) {
    console.error('Failed to mark as received:', err)
    toast.error('Failed to mark as received')
  }
}

async function cancelPullRequest(pr) {
  if (!confirm('Cancel this part request?')) return
  try {
    await pullRequestService.cancel(pr.id)
    toast.success('Request cancelled')
    loadPullRequests()
  } catch (err) {
    console.error('Failed to cancel request:', err)
    toast.error('Failed to cancel request')
  }
}

function getPullRequestStatusVariant(status) {
  const variants = {
    pending: 'default',
    pulled: 'success',
    ordered: 'info',
    received: 'success',
    cancelled: 'danger',
  }
  return variants[status] || 'default'
}

async function updateStatus(status, notes = null) {
  try {
    await workorderService.updateStatus(workorder.value.id, status, notes)
    toast.success(`Workorder status updated to ${formatStatus(status)}`)
    loadWorkorder()
  } catch (err) {
    console.error('Failed to update status:', err)
    toast.error(err.response?.data?.error || 'Failed to update status')
  }
}

async function updateJobStatus(jobId, status) {
  try {
    await workorderService.updateJobStatus(workorder.value.id, jobId, status)
    toast.success(`Job status updated to ${formatStatus(status)}`)
    loadWorkorder()
  } catch (err) {
    console.error('Failed to update job status:', err)
    toast.error(err.response?.data?.error || 'Failed to update job status')
  }
}

function showJobAssignModal(job) {
  selectedJob.value = job
  jobAssignForm.technician_id = job.technician_id || ''
  showJobAssign.value = true
}

async function confirmJobAssign() {
  try {
    assigning.value = true
    await workorderService.assignJobTechnician(
      workorder.value.id,
      selectedJob.value.id,
      jobAssignForm.technician_id || null
    )
    toast.success('Technician assigned to job')
    showJobAssign.value = false
    loadWorkorder()
  } catch (err) {
    console.error('Failed to assign technician:', err)
    toast.error(err.response?.data?.error || 'Failed to assign technician')
  } finally {
    assigning.value = false
  }
}

async function confirmAssign() {
  try {
    assigning.value = true
    await workorderService.assignTechnician(
      workorder.value.id,
      assignForm.technician_id || null
    )
    toast.success('Technician assigned')
    showAssignModal.value = false
    loadWorkorder()
  } catch (err) {
    console.error('Failed to assign technician:', err)
    toast.error(err.response?.data?.error || 'Failed to assign technician')
  } finally {
    assigning.value = false
  }
}

async function confirmPriority() {
  try {
    updatingPriority.value = true
    await workorderService.updatePriority(workorder.value.id, priorityForm.priority)
    toast.success('Priority updated')
    showPriorityModal.value = false
    loadWorkorder()
  } catch (err) {
    console.error('Failed to update priority:', err)
    toast.error(err.response?.data?.error || 'Failed to update priority')
  } finally {
    updatingPriority.value = false
  }
}

async function confirmConvert() {
  try {
    converting.value = true
    const response = await workorderService.convertToInvoice(
      workorder.value.id,
      convertForm.due_date || null
    )

    toast.success('Workorder converted to invoice successfully')
    showConvertModal.value = false

    if (response.data?.data?.id) {
      router.push(`/cp/invoices/${response.data.data.id}`)
    }
  } catch (err) {
    console.error('Failed to convert workorder:', err)
    toast.error(err.response?.data?.error || 'Failed to convert workorder')
  } finally {
    converting.value = false
  }
}

function addSubEstimateJob() {
  subEstimateForm.jobs.push(createSubEstimateJob())
}

function removeSubEstimateJob(idx) {
  subEstimateForm.jobs.splice(idx, 1)
}

function addSubEstimateLineItem(jobIndex) {
  subEstimateForm.jobs[jobIndex].items.push(createSubEstimateItem())
}

function removeSubEstimateLineItem(jobIndex, itemIndex) {
  subEstimateForm.jobs[jobIndex].items.splice(itemIndex, 1)
}

function onSubEstimateItemTypeChange(item) {
  if (item.type === 'LABOR') {
    item.sku = ''
    item.inventory_item_id = null
    item.list_price = null
  }
}

async function lookupSubEstimateSku(item) {
  if (!item.sku || item.sku.trim() === '') {
    return
  }

  try {
    const inventoryItem = await inventoryService.findBySku(item.sku.trim())
    if (inventoryItem) {
      populateSubEstimateFromInventory(item, inventoryItem)
    }
  } catch (err) {
    console.log('SKU not found in inventory')
  }
}

async function searchSubEstimateInventoryParts(query) {
  if (!query || query.length < 2) {
    return []
  }

  try {
    const results = await inventoryService.searchParts(query, null, 10)
    if (!results) return []
    return Array.isArray(results) ? results : (results.data || [])
  } catch (err) {
    console.error('Failed to search inventory:', err)
    return []
  }
}

function selectSubEstimateInventoryItem(item, inventoryItem) {
  if (inventoryItem) {
    populateSubEstimateFromInventory(item, inventoryItem)
  }
}

function populateSubEstimateFromInventory(item, inventoryItem) {
  item.sku = inventoryItem.sku || ''
  item.inventory_item_id = inventoryItem.id
  item.description = inventoryItem.name
  item.unit_price = inventoryItem.sale_price || 0
  item.list_price = inventoryItem.list_price || 0
}

function calculateSubEstimateLineTotal(item) {
  const quantity = Number(item.quantity) || 0
  const unitPrice = Number(item.unit_price) || 0
  return quantity * unitPrice
}

function calculateSubEstimateJobSubtotal(job) {
  return job.items.reduce((sum, item) => sum + calculateSubEstimateLineTotal(item), 0)
}

async function confirmSubEstimate() {
  try {
    creatingSubEstimate.value = true
    await workorderService.createSubEstimate(workorder.value.id, {
      jobs: subEstimateForm.jobs.map((job) => ({
        title: job.title,
        notes: job.notes,
        items: job.items.map((item) => ({
          type: item.type,
          sku: item.sku || null,
          inventory_item_id: item.inventory_item_id || null,
          description: item.description,
          quantity: Number(item.quantity) || 0,
          unit_price: Number(item.unit_price) || 0,
          list_price: item.list_price !== null && item.list_price !== '' ? Number(item.list_price) : null,
          taxable: item.taxable !== false,
        })),
      })),
      tax_rate: (Number(subEstimateForm.tax_rate) || 0) / 100,
    })

    toast.success('Sub-estimate created successfully')
    showSubEstimateModal.value = false

    // Reset form
    subEstimateForm.tax_rate = 0
    subEstimateForm.jobs = [createSubEstimateJob()]

    loadWorkorder()
  } catch (err) {
    console.error('Failed to create sub-estimate:', err)
    toast.error(err.response?.data?.error || 'Failed to create sub-estimate')
  } finally {
    creatingSubEstimate.value = false
  }
}

async function addSubEstimateJobs(subEstimateId) {
  try {
    await workorderService.addSubEstimateJobs(workorder.value.id, subEstimateId)
    toast.success('Sub-estimate jobs added to workorder')
    loadWorkorder()
  } catch (err) {
    console.error('Failed to add sub-estimate jobs:', err)
    toast.error(err.response?.data?.error || 'Failed to add sub-estimate jobs')
  }
}

function getTechnicianName(id) {
  const tech = technicians.value.find(t => t.id === id)
  return tech?.name || `Tech #${id}`
}

function getStatusVariant(status) {
  const variants = {
    pending: 'default',
    in_progress: 'info',
    on_hold: 'warning',
    completed: 'success',
    cancelled: 'danger'
  }
  return variants[status?.toLowerCase()] || 'default'
}

function getJobStatusVariant(status) {
  const variants = {
    pending: 'default',
    in_progress: 'info',
    completed: 'success'
  }
  return variants[status?.toLowerCase()] || 'default'
}

function getPriorityVariant(priority) {
  const variants = {
    urgent: 'danger',
    high: 'warning',
    normal: 'default',
    low: 'secondary'
  }
  return variants[priority?.toLowerCase()] || 'default'
}

function getEstimateStatusVariant(status) {
  const variants = {
    pending: 'default',
    sent: 'info',
    approved: 'success',
    rejected: 'danger',
    expired: 'warning',
    converted: 'success'
  }
  return variants[status?.toLowerCase()] || 'default'
}

function getTimelineIconBg(status) {
  const colors = {
    pending: 'bg-gray-400',
    in_progress: 'bg-blue-500',
    on_hold: 'bg-yellow-500',
    completed: 'bg-green-500',
    cancelled: 'bg-red-500'
  }
  return colors[status] || 'bg-gray-400'
}

function formatStatus(status) {
  if (!status) return ''
  return status
    .split('_')
    .map(word => word.charAt(0).toUpperCase() + word.slice(1))
    .join(' ')
}

function formatCurrency(amount) {
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD'
  }).format(amount || 0)
}

function getItemLineTotal(item) {
  if (item?.line_total !== undefined && item?.line_total !== null) {
    return item.line_total
  }
  const quantity = Number(item?.quantity) || 0
  const unitPrice = Number(item?.unit_price) || 0
  return quantity * unitPrice
}

function formatDate(date) {
  if (!date) return ''
  return new Intl.DateTimeFormat('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric'
  }).format(new Date(date))
}

function formatDateTime(date) {
  if (!date) return ''
  return new Intl.DateTimeFormat('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
    hour: 'numeric',
    minute: '2-digit'
  }).format(new Date(date))
}
</script>
