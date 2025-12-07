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

    <!-- DAQ Devices Section -->
    <fieldset class="form-section">
      <legend>DEVICES</legend>
      <p class="text-sm text-surface-400 mb-3">Add DAQ devices and their channels:</p>

      <ListDetailEditor
        title=""
        add-label="Add Device"
        :items="form.devices"
        :default-item="defaultDevice"
        :label-function="(item, idx) => item.name || `Device ${idx + 1}`"
        @update:items="form.devices = $event"
      >
        <template #detail="{ item, update }">
          <div class="flex flex-col gap-4">
            <!-- Device Name -->
            <div class="field">
              <label class="text-sm">Device Name</label>
              <InputText
                :modelValue="item.name"
                @update:modelValue="update('name', $event)"
                placeholder="e.g., NI DAQ-6289"
              />
            </div>

            <!-- Channels nested inside device -->
            <div class="channels-section">
              <div class="flex items-center gap-2 mb-3">
                <span class="text-sm font-semibold uppercase">Channels</span>
                <Button
                  size="small"
                  label="Add Channel"
                  @click="addChannel(item, update)"
                />
              </div>

              <div v-if="item.channels && item.channels.length > 0" class="channels-list">
                <!-- Channel tabs -->
                <div class="flex gap-1 mb-3 flex-wrap">
                  <Button
                    v-for="(ch, chIdx) in item.channels"
                    :key="chIdx"
                    :label="getChannelLabel(ch, chIdx)"
                    :severity="item._selectedChannel === chIdx ? undefined : 'secondary'"
                    :outlined="item._selectedChannel !== chIdx"
                    size="small"
                    @click="selectChannel(item, chIdx, update)"
                  />
                </div>

                <!-- Selected channel detail -->
                <div v-if="item._selectedChannel !== undefined && item.channels[item._selectedChannel]" class="channel-detail">
                  <ChannelEditor
                    :channel="item.channels[item._selectedChannel]"
                    @update="(field, value) => updateChannel(item, item._selectedChannel, field, value, update)"
                    @delete="deleteChannel(item, item._selectedChannel, update)"
                  />
                </div>
              </div>
              <p v-else class="text-sm text-surface-500">No channels added yet.</p>
            </div>
          </div>
        </template>
      </ListDetailEditor>
    </fieldset>

    <!-- Documents Section (placeholder) -->
    <fieldset class="form-section">
      <legend>DOCUMENTS</legend>
      <p class="text-sm text-surface-400 mb-4">
        Document upload coming soon.
      </p>
    </fieldset>

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
import ListDetailEditor from '@/components/ListDetailEditor.vue'
import ChannelEditor from '@/components/forms/ChannelEditor.vue'
import {
  DAQ_TYPES
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
  devices: [],
  documents: []
})

const form = ref(createEmptyForm())

// Default factory for device items
const defaultDevice = () => ({
  name: 'System Default',
  channels: [],
  _selectedChannel: undefined
})

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
        _selectedChannel: d.channels?.length > 0 ? 0 : undefined
      })) || [],
      documents: data.documents?.map(doc => ({ ...doc })) || []
    }
  }
}, { immediate: true, deep: true })

// Channel helpers
function getChannelLabel(channel, idx) {
  const num = channel.number || idx
  const spec = channel.header?.spec_a || ''
  const type = channel.header?.type || channel.type || ''
  return `Ch ${num}${spec ? ` - ${spec}` : ''}${type ? ` (${type})` : ''}`
}

function addChannel(device, update) {
  const newChannels = [...(device.channels || []), createDefaultChannel(device.channels?.length || 0)]
  update('channels', newChannels)
  update('_selectedChannel', newChannels.length - 1)
}

function selectChannel(device, chIdx, update) {
  update('_selectedChannel', chIdx)
}

function updateChannel(device, chIdx, field, value, update) {
  const newChannels = [...device.channels]
  // Handle nested fields like 'header.spec_a' or 'sensor.manufacturer'
  if (field.includes('.')) {
    const [parent, child] = field.split('.')
    newChannels[chIdx] = {
      ...newChannels[chIdx],
      [parent]: {
        ...newChannels[chIdx][parent],
        [child]: value
      }
    }
  } else {
    newChannels[chIdx] = { ...newChannels[chIdx], [field]: value }
  }
  update('channels', newChannels)
}

function deleteChannel(device, chIdx, update) {
  const newChannels = device.channels.filter((_, i) => i !== chIdx)
  update('channels', newChannels)
  // Adjust selection
  if (newChannels.length === 0) {
    update('_selectedChannel', undefined)
  } else if (device._selectedChannel >= newChannels.length) {
    update('_selectedChannel', newChannels.length - 1)
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
      spec_a: '',
      type: ''
    },
    sensor: {
      manufacturer: '',
      model: '',
      serial_number: '',
      type: '',
      output_type: '',
      range: '',
      accuracy: '',
      note: ''
    },
    calibration: {
      date: null,
      standard: '',
      uncertainty: '',
      reference: '',
      next_calibration: null,
      certificate: ''
    }
  }
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

.channels-section {
  background: var(--p-surface-800);
  border-radius: 4px;
  padding: 0.75rem;
}

.channel-detail {
  background: var(--p-surface-900);
  border-radius: 4px;
  padding: 1rem;
}
</style>
