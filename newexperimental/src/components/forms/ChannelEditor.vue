<template>
  <div class="channel-editor">
    <!-- Channel Header Section -->
    <fieldset class="channel-section">
      <legend>Channel Header <span class="text-red-500">*</span></legend>
      <div class="grid grid-cols-1 md:grid-cols-5 gap-3">
        <div class="field md:col-span-2">
          <label class="text-xs">Header Type</label>
          <Select
            :modelValue="channel.header?.type"
            @update:modelValue="$emit('update', 'header.type', $event)"
            :options="HEADER_TYPES"
            placeholder="Select..."
            showClear
          />
        </div>
        <div class="field">
          <label class="text-xs">Specifier A <span class="text-red-500">*</span></label>
          <Select
            :modelValue="channel.header?.spec_a"
            @update:modelValue="$emit('update', 'header.spec_a', $event)"
            :options="SPECIFIER_A_OPTIONS"
            placeholder="Select..."
            showClear
          />
        </div>
        <div class="field">
          <label class="text-xs">Specifier B</label>
          <Select
            :modelValue="channel.header?.spec_b"
            @update:modelValue="$emit('update', 'header.spec_b', $event)"
            :options="SPECIFIER_B_OPTIONS"
            placeholder="Select..."
            showClear
          />
        </div>
        <div class="field">
          <label class="text-xs">Unit <span class="text-red-500">*</span></label>
          <Select
            :modelValue="channel.header?.unit"
            @update:modelValue="$emit('update', 'header.unit', $event)"
            :options="UNIT_TYPES"
            placeholder="Select..."
            showClear
          />
        </div>
      </div>
      <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mt-3">
        <div class="field">
          <label class="text-xs">Other Specifier</label>
          <InputText
            :modelValue="channel.header?.other_specifier"
            @update:modelValue="$emit('update', 'header.other_specifier', $event)"
            placeholder="Other specifier..."
          />
        </div>
      </div>
    </fieldset>

    <!-- Channel Information Section -->
    <fieldset class="channel-section">
      <legend>Channel Information</legend>
      <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
        <div class="field">
          <label class="text-xs">Channel #</label>
          <Select
            :modelValue="channel.number"
            @update:modelValue="$emit('update', 'number', $event)"
            :options="CHANNEL_NUMBERS"
            placeholder="#"
          />
        </div>
        <div class="field">
          <label class="text-xs">Type</label>
          <Select
            :modelValue="channel.type"
            @update:modelValue="$emit('update', 'type', $event)"
            :options="CHANNEL_TYPES"
            placeholder="Select..."
            showClear
          />
        </div>
        <div class="field">
          <label class="text-xs">Configuration</label>
          <Select
            :modelValue="channel.configuration"
            @update:modelValue="$emit('update', 'configuration', $event)"
            :options="CHANNEL_CONFIGURATIONS"
            placeholder="Select..."
            showClear
          />
        </div>
        <div class="field">
          <label class="text-xs">Note</label>
          <InputText
            :modelValue="channel.note"
            @update:modelValue="$emit('update', 'note', $event)"
            placeholder="Note..."
          />
        </div>
      </div>
      <div class="grid grid-cols-2 md:grid-cols-6 gap-3 mt-3">
        <div class="field">
          <label class="text-xs">Res [bit]</label>
          <InputText
            :modelValue="channel.resolution"
            @update:modelValue="$emit('update', 'resolution', $event)"
            placeholder="e.g., 16"
          />
        </div>
        <div class="field">
          <label class="text-xs">Min</label>
          <InputText
            :modelValue="channel.range_low"
            @update:modelValue="$emit('update', 'range_low', $event)"
            placeholder="0"
          />
        </div>
        <div class="field">
          <label class="text-xs">Max</label>
          <InputText
            :modelValue="channel.range_high"
            @update:modelValue="$emit('update', 'range_high', $event)"
            placeholder="10"
          />
        </div>
        <div class="field">
          <label class="text-xs">Rate</label>
          <InputText
            :modelValue="channel.rate"
            @update:modelValue="$emit('update', 'rate', $event)"
            placeholder="e.g., 1kHz"
          />
        </div>
        <div class="field">
          <label class="text-xs">Filter</label>
          <InputText
            :modelValue="channel.filter"
            @update:modelValue="$emit('update', 'filter', $event)"
            placeholder="none"
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
    </fieldset>

    <!-- Sensor/Actuator Information Section -->
    <fieldset class="channel-section">
      <legend>Sensor/Actuator Information</legend>
      <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
        <div class="field">
          <label class="text-xs">Sensor/Actuator</label>
          <Select
            :modelValue="channel.sensor?.name"
            @update:modelValue="$emit('update', 'sensor.name', $event)"
            :options="SENSOR_TYPES"
            placeholder="Select..."
            showClear
          />
        </div>
        <div class="field md:col-span-2">
          <label class="text-xs">IEEE Sensor Template</label>
          <Select
            :modelValue="channel.sensor?.ieee_template"
            @update:modelValue="$emit('update', 'sensor.ieee_template', $event)"
            :options="IEEE_TEMPLATES"
            placeholder="Select..."
            showClear
          />
        </div>
      </div>
      <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mt-3">
        <div class="field">
          <label class="text-xs">Type</label>
          <Select
            :modelValue="channel.sensor?.type"
            @update:modelValue="$emit('update', 'sensor.type', $event)"
            :options="SENSOR_ACTIVE_TYPES"
            placeholder="Select..."
            showClear
          />
        </div>
        <div class="field">
          <label class="text-xs">Manufacturer ID</label>
          <InputText
            :modelValue="channel.sensor?.manufacturer_id"
            @update:modelValue="$emit('update', 'sensor.manufacturer_id', $event)"
            placeholder="none"
          />
        </div>
        <div class="field md:col-span-2">
          <label class="text-xs">Model #</label>
          <InputText
            :modelValue="channel.sensor?.model"
            @update:modelValue="$emit('update', 'sensor.model', $event)"
            placeholder="custom"
          />
        </div>
      </div>
      <div class="grid grid-cols-3 gap-3 mt-3">
        <div class="field">
          <label class="text-xs">Version Letter</label>
          <InputText
            :modelValue="channel.sensor?.version_letter"
            @update:modelValue="$emit('update', 'sensor.version_letter', $event)"
            placeholder=""
          />
        </div>
        <div class="field">
          <label class="text-xs">Version #</label>
          <InputText
            :modelValue="channel.sensor?.version_number"
            @update:modelValue="$emit('update', 'sensor.version_number', $event)"
            placeholder=""
          />
        </div>
        <div class="field">
          <label class="text-xs">Serial #</label>
          <InputText
            :modelValue="channel.sensor?.serial_number"
            @update:modelValue="$emit('update', 'sensor.serial_number', $event)"
            placeholder=""
          />
        </div>
      </div>
    </fieldset>

    <!-- Calibration Information Section -->
    <fieldset class="channel-section">
      <legend>Calibration Information</legend>
      <p class="text-xs text-surface-400 mb-3">
        Data can be entered as Pairs: Calibration Table-Input:Unit; Linear Regression1 Input@0:Input/Unit; Linear Regression2 u=(x*a0+a1)*a2+a3; Polynomial-Base:Exponent1; Frequency Response Table-Frequency:Amplitude
      </p>
      <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
        <div class="field">
          <label class="text-xs">Template</label>
          <Select
            :modelValue="channel.calibration?.template"
            @update:modelValue="$emit('update', 'calibration.template', $event)"
            :options="CALIBRATION_TEMPLATES"
            placeholder="Select..."
            showClear
          />
        </div>
        <div class="field">
          <label class="text-xs">Input</label>
          <Select
            :modelValue="channel.calibration?.input"
            @update:modelValue="$emit('update', 'calibration.input', $event)"
            :options="CALIBRATION_INPUTS"
            placeholder="Select..."
            showClear
          />
        </div>
        <div class="field">
          <label class="text-xs">Unit</label>
          <Select
            :modelValue="channel.calibration?.unit"
            @update:modelValue="$emit('update', 'calibration.unit', $event)"
            :options="UNIT_TYPES"
            placeholder="Select..."
            showClear
          />
        </div>
        <div class="field">
          <label class="text-xs">Excitation</label>
          <InputText
            :modelValue="channel.calibration?.excitation"
            @update:modelValue="$emit('update', 'calibration.excitation', $event)"
            placeholder="e.g., 10"
          />
        </div>
      </div>
      <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mt-3">
        <div class="field">
          <label class="text-xs">Date</label>
          <DatePicker
            :modelValue="channel.calibration?.date ? new Date(channel.calibration.date) : null"
            @update:modelValue="$emit('update', 'calibration.date', $event ? $event.toISOString() : null)"
            dateFormat="mm/dd/yy"
            placeholder="mm / dd / yyyy"
            showIcon
            iconDisplay="input"
          />
        </div>
        <div class="field">
          <label class="text-xs">Note</label>
          <InputText
            :modelValue="channel.calibration?.note"
            @update:modelValue="$emit('update', 'calibration.note', $event)"
            placeholder="e.g., 100kN max"
          />
        </div>
      </div>

      <!-- Calibration Data Points -->
      <div class="calibration-data mt-3">
        <div class="flex items-center gap-2 mb-2">
          <span class="text-xs font-semibold uppercase">DATA</span>
          <Button
            size="small"
            label="Add Data"
            @click="addCalibrationData"
          />
        </div>
        <div v-if="channel.calibration?.data && channel.calibration.data.length > 0" class="data-rows">
          <div
            v-for="(dp, dpIdx) in channel.calibration.data"
            :key="dpIdx"
            class="data-row flex gap-2 items-end mb-2"
          >
            <div class="field w-24">
              <label class="text-xs" v-if="dpIdx === 0">A:</label>
              <InputText
                :modelValue="dp.a"
                @update:modelValue="updateCalibrationData(dpIdx, 'a', $event)"
                placeholder="0"
              />
            </div>
            <div class="field w-32">
              <label class="text-xs" v-if="dpIdx === 0">B:</label>
              <InputText
                :modelValue="dp.b"
                @update:modelValue="updateCalibrationData(dpIdx, 'b', $event)"
                placeholder="0.10473398"
              />
            </div>
            <Button
              icon="pi pi-trash"
              severity="secondary"
              text
              size="small"
              @click="deleteCalibrationData(dpIdx)"
            />
          </div>
        </div>
      </div>
    </fieldset>

    <!-- Channel Action Buttons -->
    <div class="flex justify-end mt-4 gap-2">
      <Button
        icon="pi pi-trash"
        severity="danger"
        outlined
        size="small"
        @click="$emit('delete')"
        v-tooltip.top="'Delete Channel'"
      />
      <Button
        icon="pi pi-arrow-down"
        severity="secondary"
        outlined
        size="small"
        @click="$emit('move-down')"
        v-tooltip.top="'Move Down'"
      />
      <Button
        icon="pi pi-arrow-up"
        severity="secondary"
        outlined
        size="small"
        @click="$emit('move-up')"
        v-tooltip.top="'Move Up'"
      />
    </div>
  </div>
</template>

<script setup>
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import Button from 'primevue/button'
import DatePicker from 'primevue/datepicker'
import {
  CHANNEL_NUMBERS,
  CHANNEL_TYPES,
  CHANNEL_CONFIGURATIONS,
  CHANNEL_GAINS,
  SENSOR_TYPES,
  UNIT_TYPES
} from '@/schemas/laps-enums'

const props = defineProps({
  channel: {
    type: Object,
    required: true
  }
})

const emit = defineEmits(['update', 'delete', 'move-up', 'move-down'])

// Header type options (from old app)
const HEADER_TYPES = [
  'Load',
  'Displacement',
  'Pressure',
  'Temperature',
  'Time',
  'Strain',
  'Stress',
  'Force',
  'Torque',
  'Velocity',
  'Acceleration',
  'Voltage',
  'Current',
  'Resistance',
  'Frequency',
  'Other'
]

// Specifier A options
const SPECIFIER_A_OPTIONS = [
  'Axial',
  'Radial',
  'Circumferential',
  'Confining',
  'Pore',
  'Differential',
  'Internal',
  'External',
  'Upper',
  'Lower',
  'Left',
  'Right',
  'Reference',
  'Sample'
]

// Specifier B options
const SPECIFIER_B_OPTIONS = [
  'Internal',
  'External',
  'Upper',
  'Lower',
  'Left',
  'Right',
  'Top',
  'Bottom',
  'Front',
  'Back',
  'In',
  'Out'
]

// Sensor active/passive types
const SENSOR_ACTIVE_TYPES = [
  'Active',
  'Passive'
]

// IEEE Sensor templates
const IEEE_TEMPLATES = [
  'IEEE 1451.4 TEDS',
  'IEEE 21451-4',
  'Custom'
]

// Calibration templates
const CALIBRATION_TEMPLATES = [
  'Input@0:Input/Unit',
  'Calibration Table',
  'Linear Regression1',
  'Linear Regression2',
  'Polynomial',
  'Frequency Response'
]

// Calibration input types
const CALIBRATION_INPUTS = [
  'Volt',
  'mV',
  'Amperage',
  'mA',
  'Ohm',
  'Hz',
  'Count'
]

// Calibration data management
function addCalibrationData() {
  const currentData = props.channel.calibration?.data || []
  emit('update', 'calibration.data', [...currentData, { a: '0', b: '' }])
}

function updateCalibrationData(idx, field, value) {
  const newData = [...(props.channel.calibration?.data || [])]
  newData[idx] = { ...newData[idx], [field]: value }
  emit('update', 'calibration.data', newData)
}

function deleteCalibrationData(idx) {
  const newData = (props.channel.calibration?.data || []).filter((_, i) => i !== idx)
  emit('update', 'calibration.data', newData)
}
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
  font-size: 0.8rem;
  font-weight: 600;
  padding: 0 0.5rem;
  color: var(--p-surface-300);
}

.field {
  display: flex;
  flex-direction: column;
}

.field label {
  margin-bottom: 2px;
}

.calibration-data {
  background: var(--p-surface-900);
  border-radius: 4px;
  padding: 0.75rem;
}

.data-row {
  padding-bottom: 0.5rem;
}

.data-row:last-child {
  padding-bottom: 0;
}
</style>
