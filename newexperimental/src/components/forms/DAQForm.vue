<template>
  <form @submit.prevent="handleSubmit" class="daq-form">
    <!-- DAQ Info Section -->
    <fieldset class="form-section">
      <legend>DAQ INFO</legend>
      <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div class="field md:col-span-2">
          <label class="text-sm">DAQ Group Name</label>
          <InputText v-model="form.name" placeholder="e.g., Main Data Acquisition System" />
        </div>
        <div class="field">
          <label class="text-sm">DAQ Type</label>
          <Select
            v-model="form.type"
            :options="DAQ_TYPES"
            placeholder="Select type..."
            showClear
          />
        </div>
        <div class="field">
          <label class="text-sm">Location</label>
          <InputText v-model="form.location" placeholder="e.g., Room 101" />
        </div>
      </div>
      <div class="grid grid-cols-1 gap-4 mt-3">
        <div class="field">
          <label class="text-sm">Description</label>
          <Textarea
            v-model="form.description"
            rows="2"
            autoResize
            placeholder="Describe the DAQ system..."
          />
        </div>
      </div>
    </fieldset>

    <!-- Devices - stacked vertically like old app -->
    <div class="devices-container">
      <div class="flex items-center gap-3 mb-4">
        <Button label="Add Device" @click="addDevice" />
      </div>

      <!-- Each device as a separate card -->
      <div
        v-for="(device, dIdx) in form.devices"
        :key="dIdx"
        class="device-card"
      >
        <!-- Device header with name and action buttons -->
        <div class="device-header">
          <div class="field flex-1">
            <label class="text-sm">Device Name <span class="text-red-500">*</span></label>
            <InputText
              v-model="device.name"
              placeholder="e.g., National Instrument PCI-MIO-16XE-10"
            />
          </div>
          <div class="device-actions">
            <Button
              icon="pi pi-trash"
              severity="secondary"
              outlined
              size="small"
              @click="deleteDevice(dIdx)"
              v-tooltip.top="'Delete Device'"
            />
            <Button
              icon="pi pi-arrow-down"
              severity="secondary"
              outlined
              size="small"
              :disabled="dIdx === form.devices.length - 1"
              @click="moveDevice(dIdx, 1)"
              v-tooltip.top="'Move Down'"
            />
            <Button
              icon="pi pi-arrow-up"
              severity="secondary"
              outlined
              size="small"
              :disabled="dIdx === 0"
              @click="moveDevice(dIdx, -1)"
              v-tooltip.top="'Move Up'"
            />
          </div>
        </div>

        <!-- Device (DAQ) Channels Section -->
        <fieldset class="channels-fieldset">
          <legend>DEVICE (DAQ) CHANNELS</legend>
          <div class="flex items-center gap-2 mb-3">
            <Button
              size="small"
              label="Add Channel"
              @click="addChannel(dIdx)"
            />
          </div>

          <div v-if="device.channels && device.channels.length > 0" class="channels-layout">
            <!-- Channel tabs on the left -->
            <div class="channel-tabs">
              <Button
                v-for="(ch, chIdx) in device.channels"
                :key="chIdx"
                :label="getChannelLabel(ch, chIdx)"
                :severity="device._selectedChannel === chIdx ? undefined : 'secondary'"
                :outlined="device._selectedChannel !== chIdx"
                size="small"
                class="channel-tab-btn"
                @click="device._selectedChannel = chIdx"
              />
            </div>

            <!-- Selected channel detail on the right -->
            <div v-if="device._selectedChannel !== undefined && device.channels[device._selectedChannel]" class="channel-detail">
              <ChannelEditor
                :channel="device.channels[device._selectedChannel]"
                @update="(field, value) => updateChannel(dIdx, device._selectedChannel, field, value)"
                @delete="deleteChannel(dIdx, device._selectedChannel)"
              />
            </div>
          </div>
          <p v-else class="text-sm text-surface-500">No channels added yet.</p>
        </fieldset>

        <!-- Device Documents Section -->
        <fieldset class="documents-fieldset">
          <legend>DEVICE DOCUMENTS</legend>
          <div class="flex items-center gap-2 mb-3">
            <Button
              size="small"
              label="Add Document"
              @click="addDeviceDocument(dIdx)"
            />
          </div>

          <div v-if="device.documents && device.documents.length > 0" class="documents-list">
            <div
              v-for="(doc, docIdx) in device.documents"
              :key="docIdx"
              class="document-row"
            >
              <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                <div class="field">
                  <label class="text-xs">Document Type <span class="text-red-500">*</span></label>
                  <Select
                    v-model="doc.type"
                    :options="DOCUMENT_TYPES"
                    placeholder="Select..."
                  />
                </div>
                <div class="field">
                  <label class="text-xs">Document Format <span class="text-red-500">*</span></label>
                  <Select
                    v-model="doc.format"
                    :options="DOCUMENT_FORMATS"
                    placeholder="Select..."
                  />
                </div>
                <div class="field">
                  <label class="text-xs">File Uploaded</label>
                  <InputText
                    v-model="doc.path"
                    placeholder="File path or URL"
                  />
                </div>
                <div class="field">
                  <label class="text-xs">Document ID</label>
                  <div class="flex gap-2">
                    <InputText
                      v-model="doc.id"
                      placeholder="ID"
                      class="flex-1"
                    />
                    <Button
                      icon="pi pi-trash"
                      severity="secondary"
                      outlined
                      size="small"
                      @click="deleteDeviceDocument(dIdx, docIdx)"
                    />
                  </div>
                </div>
              </div>
              <div class="field mt-2">
                <label class="text-xs">Description</label>
                <Textarea
                  v-model="doc.description"
                  rows="2"
                  autoResize
                  placeholder="Document description..."
                />
              </div>
            </div>
          </div>
          <p v-else class="text-sm text-surface-500">No documents added yet.</p>
        </fieldset>
      </div>

      <p v-if="form.devices.length === 0" class="text-surface-500 text-center py-4">
        No devices added. Click "Add Device" to add a DAQ device.
      </p>
    </div>

    <!-- Actions -->
    <div class="flex justify-center gap-3 mt-6">
      <Button
        type="button"
        severity="secondary"
        outlined
        label="Cancel"
        @click="$emit('cancel')"
      />
      <Button
        type="submit"
        label="Save DAQ"
      />
    </div>
  </form>
</template>

<script setup>
import { ref, watch } from 'vue'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import Button from 'primevue/button'
import Textarea from 'primevue/textarea'
import ChannelEditor from '@/components/forms/ChannelEditor.vue'
import {
  DAQ_TYPES,
  DOCUMENT_TYPES,
  DOCUMENT_FORMATS
} from '@/schemas/laps-enums'

const props = defineProps({
  initialData: {
    type: Object,
    default: () => ({})
  }
})

const emit = defineEmits(['submit', 'cancel'])

const createEmptyForm = () => ({
  name: '',
  type: '',
  location: '',
  description: '',
  devices: []
})

const form = ref(createEmptyForm())

// Populate form with initial data
watch(() => props.initialData, (data) => {
  if (data && Object.keys(data).length > 0) {
    form.value = {
      name: data.name || '',
      type: data.type || '',
      location: data.location || '',
      description: data.description || '',
      devices: data.devices?.map(d => ({
        name: d.name || 'System Default',
        channels: d.channels?.map(ch => ({ ...ch })) || [],
        documents: d.documents?.map(doc => ({ ...doc })) || [],
        _selectedChannel: d.channels?.length > 0 ? 0 : undefined
      })) || []
    }
  }
}, { immediate: true, deep: true })

// Device management
function addDevice() {
  form.value.devices.push({
    name: '',
    channels: [],
    documents: [],
    _selectedChannel: undefined
  })
}

function deleteDevice(idx) {
  form.value.devices.splice(idx, 1)
}

function moveDevice(idx, direction) {
  const newIdx = idx + direction
  if (newIdx < 0 || newIdx >= form.value.devices.length) return
  const device = form.value.devices.splice(idx, 1)[0]
  form.value.devices.splice(newIdx, 0, device)
}

// Channel helpers
function getChannelLabel(channel, idx) {
  const num = channel.number ?? idx
  const header = channel.header?.type || ''
  return `${num} - ${header || 'undefined'}`
}

function addChannel(deviceIdx) {
  const device = form.value.devices[deviceIdx]
  const newChannel = createDefaultChannel(device.channels?.length || 0)
  device.channels.push(newChannel)
  device._selectedChannel = device.channels.length - 1
}

function updateChannel(deviceIdx, chIdx, field, value) {
  const channel = form.value.devices[deviceIdx].channels[chIdx]
  // Handle nested fields like 'header.spec_a' or 'sensor.manufacturer'
  if (field.includes('.')) {
    const [parent, child] = field.split('.')
    if (!channel[parent]) channel[parent] = {}
    channel[parent][child] = value
  } else {
    channel[field] = value
  }
}

function deleteChannel(deviceIdx, chIdx) {
  const device = form.value.devices[deviceIdx]
  device.channels.splice(chIdx, 1)
  // Adjust selection
  if (device.channels.length === 0) {
    device._selectedChannel = undefined
  } else if (device._selectedChannel >= device.channels.length) {
    device._selectedChannel = device.channels.length - 1
  }
}

function createDefaultChannel(index) {
  return {
    number: String(index),
    type: '',
    configuration: '',
    note: '',
    resolution: '',
    range_low: '',
    range_high: '',
    rate: '',
    filter: '',
    gain: '',
    header: {
      type: '',
      spec_a: '',
      spec_b: '',
      other_specifier: '',
      unit: ''
    },
    sensor: {
      name: '',
      type: '',
      manufacturer_id: '',
      model: '',
      version_letter: '',
      version_number: '',
      serial_number: ''
    },
    calibration: {
      template: '',
      input: '',
      unit: '',
      excitation: '',
      date: null,
      note: '',
      data: []
    }
  }
}

// Device document management
function addDeviceDocument(deviceIdx) {
  const device = form.value.devices[deviceIdx]
  if (!device.documents) device.documents = []
  device.documents.push({
    type: '',
    format: '',
    path: '',
    id: '',
    description: ''
  })
}

function deleteDeviceDocument(deviceIdx, docIdx) {
  form.value.devices[deviceIdx].documents.splice(docIdx, 1)
}

function handleSubmit() {
  // Clean up internal state before emitting
  const formData = {
    ...form.value,
    devices: form.value.devices.map(d => {
      const { _selectedChannel, ...device } = d
      return device
    })
  }
  emit('submit', formData)
}
</script>

<style scoped>
.daq-form {
  max-width: 1100px;
  margin: 0 auto;
}

.form-section {
  border: 1px solid var(--p-surface-600);
  border-radius: 4px;
  padding: 1rem 1.25rem;
  margin-bottom: 1rem;
}

.form-section legend {
  font-size: 0.875rem;
  font-weight: 600;
  padding: 0 0.5rem;
  color: var(--p-surface-300);
  text-transform: uppercase;
  letter-spacing: 0.05em;
}

.field {
  display: flex;
  flex-direction: column;
}

.field label {
  margin-bottom: 2px;
}

.devices-container {
  margin-bottom: 1rem;
}

.device-card {
  border: 1px solid var(--p-surface-600);
  border-radius: 4px;
  padding: 1rem;
  margin-bottom: 1rem;
  background: var(--p-surface-900);
}

.device-header {
  display: flex;
  gap: 1rem;
  align-items: flex-end;
  margin-bottom: 1rem;
}

.device-actions {
  display: flex;
  gap: 0.25rem;
  padding-bottom: 2px;
}

.channels-fieldset,
.documents-fieldset {
  border: 1px solid var(--p-surface-700);
  border-radius: 4px;
  padding: 0.75rem;
  margin-top: 1rem;
}

.channels-fieldset legend,
.documents-fieldset legend {
  font-size: 0.75rem;
  font-weight: 600;
  padding: 0 0.5rem;
  color: var(--p-surface-400);
  text-transform: uppercase;
  letter-spacing: 0.05em;
}

.channels-layout {
  display: flex;
  gap: 1rem;
}

.channel-tabs {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  min-width: 140px;
  max-width: 160px;
}

.channel-tab-btn {
  text-align: left;
  justify-content: flex-start;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.channel-detail {
  flex: 1;
  background: var(--p-surface-800);
  border-radius: 4px;
  padding: 1rem;
  min-width: 0;
}

.document-row {
  background: var(--p-surface-800);
  border-radius: 4px;
  padding: 0.75rem;
  margin-bottom: 0.5rem;
}

.document-row:last-child {
  margin-bottom: 0;
}
</style>
