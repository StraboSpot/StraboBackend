<template>
  <div class="channel-editor">
    <!-- Channel Header Info -->
    <fieldset class="channel-section">
      <legend>HEADER</legend>
      <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
        <div class="field">
          <label class="text-xs">Spec A (Short Name)</label>
          <InputText
            :modelValue="channel.header?.spec_a"
            @update:modelValue="$emit('update', 'header.spec_a', $event)"
            placeholder="e.g., Pc"
          />
        </div>
        <div class="field">
          <label class="text-xs">Header Type</label>
          <InputText
            :modelValue="channel.header?.type"
            @update:modelValue="$emit('update', 'header.type', $event)"
            placeholder="e.g., Pressure"
          />
        </div>
      </div>
    </fieldset>

    <!-- Channel Info -->
    <fieldset class="channel-section">
      <legend>CHANNEL INFO</legend>
      <div class="grid grid-cols-2 md:grid-cols-6 gap-3">
        <div class="field">
          <label class="text-xs">Channel #</label>
          <Select
            :modelValue="channel.number"
            @update:modelValue="$emit('update', 'number', $event)"
            :options="CHANNEL_NUMBERS"
            placeholder="#"
          />
        </div>
        <div class="field md:col-span-2">
          <label class="text-xs">Type</label>
          <Select
            :modelValue="channel.type"
            @update:modelValue="$emit('update', 'type', $event)"
            :options="CHANNEL_TYPES"
            placeholder="Select type..."
            showClear
          />
        </div>
        <div class="field md:col-span-2">
          <label class="text-xs">Configuration</label>
          <Select
            :modelValue="channel.configuration"
            @update:modelValue="$emit('update', 'configuration', $event)"
            :options="CHANNEL_CONFIGURATIONS"
            placeholder="Select config..."
            showClear
          />
        </div>
        <div class="field">
          <label class="text-xs">Gain</label>
          <Select
            :modelValue="channel.gain"
            @update:modelValue="$emit('update', 'gain', $event)"
            :options="CHANNEL_GAINS"
            placeholder="Gain"
            showClear
          />
        </div>
      </div>
      <div class="grid grid-cols-2 md:grid-cols-6 gap-3 mt-3">
        <div class="field">
          <label class="text-xs">Resolution</label>
          <InputText
            :modelValue="channel.resolution"
            @update:modelValue="$emit('update', 'resolution', $event)"
            placeholder="e.g., 16-bit"
          />
        </div>
        <div class="field">
          <label class="text-xs">Range Low</label>
          <InputText
            :modelValue="channel.range_low"
            @update:modelValue="$emit('update', 'range_low', $event)"
            placeholder="e.g., -10V"
          />
        </div>
        <div class="field">
          <label class="text-xs">Range High</label>
          <InputText
            :modelValue="channel.range_high"
            @update:modelValue="$emit('update', 'range_high', $event)"
            placeholder="e.g., +10V"
          />
        </div>
        <div class="field">
          <label class="text-xs">Sample Rate</label>
          <InputText
            :modelValue="channel.rate"
            @update:modelValue="$emit('update', 'rate', $event)"
            placeholder="e.g., 1 kHz"
          />
        </div>
        <div class="field md:col-span-2">
          <label class="text-xs">Filter</label>
          <InputText
            :modelValue="channel.filter"
            @update:modelValue="$emit('update', 'filter', $event)"
            placeholder="e.g., Low-pass 100Hz"
          />
        </div>
      </div>
      <div class="grid grid-cols-1 gap-3 mt-3">
        <div class="field">
          <label class="text-xs">Notes</label>
          <InputText
            :modelValue="channel.note"
            @update:modelValue="$emit('update', 'note', $event)"
            placeholder="Channel notes..."
          />
        </div>
      </div>
    </fieldset>

    <!-- Sensor Section (collapsible) -->
    <CollapsibleSection title="SENSOR / ACTUATOR" :default-open="hasSensorData">
      <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
        <div class="field">
          <label class="text-xs">Manufacturer</label>
          <InputText
            :modelValue="channel.sensor?.manufacturer"
            @update:modelValue="$emit('update', 'sensor.manufacturer', $event)"
            placeholder="e.g., Omega"
          />
        </div>
        <div class="field">
          <label class="text-xs">Model</label>
          <InputText
            :modelValue="channel.sensor?.model"
            @update:modelValue="$emit('update', 'sensor.model', $event)"
            placeholder="Model number"
          />
        </div>
        <div class="field">
          <label class="text-xs">Serial Number</label>
          <InputText
            :modelValue="channel.sensor?.serial_number"
            @update:modelValue="$emit('update', 'sensor.serial_number', $event)"
            placeholder="Serial #"
          />
        </div>
        <div class="field">
          <label class="text-xs">Sensor Type</label>
          <Select
            :modelValue="channel.sensor?.type"
            @update:modelValue="$emit('update', 'sensor.type', $event)"
            :options="SENSOR_TYPES"
            placeholder="Select..."
            showClear
          />
        </div>
      </div>
      <div class="grid grid-cols-1 md:grid-cols-4 gap-3 mt-3">
        <div class="field">
          <label class="text-xs">Output Type</label>
          <Select
            :modelValue="channel.sensor?.output_type"
            @update:modelValue="$emit('update', 'sensor.output_type', $event)"
            :options="SENSOR_OUTPUT_TYPES"
            placeholder="Select..."
            showClear
          />
        </div>
        <div class="field">
          <label class="text-xs">Range</label>
          <InputText
            :modelValue="channel.sensor?.range"
            @update:modelValue="$emit('update', 'sensor.range', $event)"
            placeholder="e.g., 0-100 MPa"
          />
        </div>
        <div class="field">
          <label class="text-xs">Accuracy</label>
          <InputText
            :modelValue="channel.sensor?.accuracy"
            @update:modelValue="$emit('update', 'sensor.accuracy', $event)"
            placeholder="e.g., ±0.1%"
          />
        </div>
        <div class="field">
          <label class="text-xs">Sensor Note</label>
          <InputText
            :modelValue="channel.sensor?.note"
            @update:modelValue="$emit('update', 'sensor.note', $event)"
            placeholder="Notes..."
          />
        </div>
      </div>
    </CollapsibleSection>

    <!-- Calibration Section (collapsible) -->
    <CollapsibleSection title="CALIBRATION" :default-open="hasCalibrationData">
      <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
        <div class="field">
          <label class="text-xs">Calibration Date</label>
          <DatePicker
            :modelValue="channel.calibration?.date ? new Date(channel.calibration.date) : null"
            @update:modelValue="$emit('update', 'calibration.date', $event ? $event.toISOString() : null)"
            dateFormat="mm/dd/yy"
            placeholder="Select date..."
            showIcon
            iconDisplay="input"
          />
        </div>
        <div class="field">
          <label class="text-xs">Standard</label>
          <Select
            :modelValue="channel.calibration?.standard"
            @update:modelValue="$emit('update', 'calibration.standard', $event)"
            :options="CALIBRATION_STANDARDS"
            placeholder="Select..."
            showClear
          />
        </div>
        <div class="field">
          <label class="text-xs">Uncertainty</label>
          <InputText
            :modelValue="channel.calibration?.uncertainty"
            @update:modelValue="$emit('update', 'calibration.uncertainty', $event)"
            placeholder="e.g., ±0.5%"
          />
        </div>
        <div class="field">
          <label class="text-xs">Reference</label>
          <InputText
            :modelValue="channel.calibration?.reference"
            @update:modelValue="$emit('update', 'calibration.reference', $event)"
            placeholder="Reference document"
          />
        </div>
      </div>
      <div class="grid grid-cols-1 md:grid-cols-4 gap-3 mt-3">
        <div class="field">
          <label class="text-xs">Next Calibration</label>
          <DatePicker
            :modelValue="channel.calibration?.next_calibration ? new Date(channel.calibration.next_calibration) : null"
            @update:modelValue="$emit('update', 'calibration.next_calibration', $event ? $event.toISOString() : null)"
            dateFormat="mm/dd/yy"
            placeholder="Select date..."
            showIcon
            iconDisplay="input"
          />
        </div>
        <div class="field md:col-span-3">
          <label class="text-xs">Certificate</label>
          <InputText
            :modelValue="channel.calibration?.certificate"
            @update:modelValue="$emit('update', 'calibration.certificate', $event)"
            placeholder="Certificate ID or URL"
          />
        </div>
      </div>
    </CollapsibleSection>

    <!-- Delete Channel Button -->
    <div class="flex justify-end mt-4">
      <Button
        label="Delete Channel"
        severity="danger"
        outlined
        size="small"
        icon="pi pi-trash"
        @click="$emit('delete')"
      />
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import Button from 'primevue/button'
import DatePicker from 'primevue/datepicker'
import CollapsibleSection from '@/components/CollapsibleSection.vue'
import {
  CHANNEL_NUMBERS,
  CHANNEL_TYPES,
  CHANNEL_CONFIGURATIONS,
  CHANNEL_GAINS,
  SENSOR_TYPES,
  SENSOR_OUTPUT_TYPES,
  CALIBRATION_STANDARDS
} from '@/schemas/laps-enums'

const props = defineProps({
  channel: {
    type: Object,
    required: true
  }
})

defineEmits(['update', 'delete'])

const hasSensorData = computed(() => {
  const s = props.channel.sensor
  return s && (s.manufacturer || s.model || s.serial_number || s.type)
})

const hasCalibrationData = computed(() => {
  const c = props.channel.calibration
  return c && (c.date || c.standard || c.uncertainty)
})
</script>

<style scoped>
.channel-editor {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.channel-section {
  border: 1px solid var(--p-surface-700);
  border-radius: 4px;
  padding: 0.75rem;
}

.channel-section legend {
  font-size: 0.75rem;
  font-weight: 600;
  padding: 0 0.5rem;
  color: var(--p-surface-400);
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
</style>
