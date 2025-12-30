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
                    Customer #{{ workorder.customer_id }}
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
                    Vehicle #{{ workorder.vehicle_id }}
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
                    <h4 class="font-medium text-gray-900">{{ job.description }}</h4>
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
                        <td class="py-1 text-right">{{ formatCurrency(item.total) }}</td>
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
    <Modal v-if="showConvertModal" @close="showConvertModal = false">
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
      <template #actions>
        <Button variant="outline" @click="showConvertModal = false">Cancel</Button>
        <Button @click="confirmConvert" :disabled="converting">
          {{ converting ? 'Converting...' : 'Convert to Invoice' }}
        </Button>
      </template>
    </Modal>

    <!-- Assign Technician Modal -->
    <Modal v-if="showAssignModal" @close="showAssignModal = false">
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
      <template #actions>
        <Button variant="outline" @click="showAssignModal = false">Cancel</Button>
        <Button @click="confirmAssign" :disabled="assigning">
          {{ assigning ? 'Assigning...' : 'Assign' }}
        </Button>
      </template>
    </Modal>

    <!-- Assign Job Technician Modal -->
    <Modal v-if="showJobAssign" @close="showJobAssign = false">
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
      <template #actions>
        <Button variant="outline" @click="showJobAssign = false">Cancel</Button>
        <Button @click="confirmJobAssign" :disabled="assigning">
          {{ assigning ? 'Assigning...' : 'Assign' }}
        </Button>
      </template>
    </Modal>

    <!-- Change Priority Modal -->
    <Modal v-if="showPriorityModal" @close="showPriorityModal = false">
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
      <template #actions>
        <Button variant="outline" @click="showPriorityModal = false">Cancel</Button>
        <Button @click="confirmPriority" :disabled="updatingPriority">
          {{ updatingPriority ? 'Updating...' : 'Update Priority' }}
        </Button>
      </template>
    </Modal>

    <!-- Create Sub-Estimate Modal -->
    <Modal v-if="showSubEstimateModal" @close="showSubEstimateModal = false" size="lg">
      <template #title>Create Sub-Estimate for Additional Work</template>
      <template #content>
        <div class="space-y-4">
          <Alert variant="info">
            Create a sub-estimate for additional work discovered during repair.
            The customer will need to approve this before work can proceed.
          </Alert>

          <div v-for="(job, idx) in subEstimateForm.jobs" :key="idx" class="border border-gray-200 rounded-lg p-4">
            <div class="flex items-start justify-between mb-3">
              <h4 class="font-medium text-gray-900">Job {{ idx + 1 }}</h4>
              <Button
                v-if="subEstimateForm.jobs.length > 1"
                variant="ghost"
                size="sm"
                @click="removeSubEstimateJob(idx)"
              >
                <svg class="h-4 w-4 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
              </Button>
            </div>

            <div class="space-y-3">
              <div>
                <label class="block text-sm font-medium text-gray-700">Description *</label>
                <Input
                  v-model="job.description"
                  placeholder="Job description"
                  class="mt-1"
                  required
                />
              </div>

              <div class="grid grid-cols-2 gap-4">
                <div>
                  <label class="block text-sm font-medium text-gray-700">Labor Hours</label>
                  <Input
                    v-model.number="job.labor_hours"
                    type="number"
                    step="0.5"
                    min="0"
                    class="mt-1"
                  />
                </div>
                <div>
                  <label class="block text-sm font-medium text-gray-700">Labor Rate</label>
                  <Input
                    v-model.number="job.labor_rate"
                    type="number"
                    step="0.01"
                    min="0"
                    class="mt-1"
                  />
                </div>
              </div>

              <div>
                <label class="block text-sm font-medium text-gray-700">Parts Cost</label>
                <Input
                  v-model.number="job.parts_cost"
                  type="number"
                  step="0.01"
                  min="0"
                  class="mt-1"
                />
              </div>

              <div>
                <label class="block text-sm font-medium text-gray-700">Notes</label>
                <textarea
                  v-model="job.notes"
                  rows="2"
                  class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm"
                ></textarea>
              </div>
            </div>
          </div>

          <Button variant="outline" @click="addSubEstimateJob" class="w-full">
            <svg class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
            </svg>
            Add Another Job
          </Button>
        </div>
      </template>
      <template #actions>
        <Button variant="outline" @click="showSubEstimateModal = false">Cancel</Button>
        <Button @click="confirmSubEstimate" :disabled="creatingSubEstimate || !isSubEstimateValid">
          {{ creatingSubEstimate ? 'Creating...' : 'Create Sub-Estimate' }}
        </Button>
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
import workorderService from '@/services/workorder.service'
import userService from '@/services/user.service'
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

// Modal states
const showConvertModal = ref(false)
const showAssignModal = ref(false)
const showJobAssign = ref(false)
const showPriorityModal = ref(false)
const showSubEstimateModal = ref(false)
const selectedJob = ref(null)

// Loading states
const converting = ref(false)
const assigning = ref(false)
const updatingPriority = ref(false)
const creatingSubEstimate = ref(false)

// Form data
const convertForm = reactive({ due_date: '' })
const assignForm = reactive({ technician_id: '' })
const jobAssignForm = reactive({ technician_id: '' })
const priorityForm = reactive({ priority: '' })
const subEstimateForm = reactive({
  jobs: [{ description: '', labor_hours: 0, labor_rate: 85, parts_cost: 0, notes: '' }]
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
  return subEstimateForm.jobs.every(j => j.description && j.description.trim() !== '')
})

onMounted(() => {
  loadWorkorder()
  loadTechnicians()
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
    const response = await userService.getUsers({ role: 'technician' })
    const users = response.data || []
    technicians.value = users
    technicianOptions.value = [
      { value: '', label: 'Unassigned' },
      ...users.map(u => ({ value: u.id, label: u.name }))
    ]
  } catch (err) {
    console.error('Failed to load technicians:', err)
  }
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
  subEstimateForm.jobs.push({
    description: '',
    labor_hours: 0,
    labor_rate: 85,
    parts_cost: 0,
    notes: ''
  })
}

function removeSubEstimateJob(idx) {
  subEstimateForm.jobs.splice(idx, 1)
}

async function confirmSubEstimate() {
  try {
    creatingSubEstimate.value = true
    await workorderService.createSubEstimate(workorder.value.id, {
      jobs: subEstimateForm.jobs
    })

    toast.success('Sub-estimate created successfully')
    showSubEstimateModal.value = false

    // Reset form
    subEstimateForm.jobs = [{ description: '', labor_hours: 0, labor_rate: 85, parts_cost: 0, notes: '' }]

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
