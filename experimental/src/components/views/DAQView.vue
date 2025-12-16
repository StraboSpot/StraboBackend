<template>
  <div class="daq-view">
    <!-- DAQ Info -->
    <div class="view-section">
      <h3 class="section-title">DAQ INFO</h3>
      <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <InfoField label="DAQ Group Name" :value="data.name" class="md:col-span-2" />
        <InfoField label="DAQ Type" :value="data.type" />
        <InfoField label="Location" :value="data.location" />
      </div>
      <div class="mt-4" v-if="data.description">
        <InfoField label="Description" :value="data.description" />
      </div>
    </div>

    <!-- Devices -->
    <div class="view-section" v-if="data.devices && data.devices.length > 0">
      <h3 class="section-title">DEVICES</h3>
      <div
        v-for="(device, dIdx) in data.devices"
        :key="dIdx"
        class="device-card"
      >
        <div class="device-header">
          <span class="device-name">{{ device.name || `Device ${dIdx + 1}` }}</span>
          <span class="channel-count">{{ device.channels?.length || 0 }} channel(s)</span>
        </div>

        <!-- Channels -->
        <div v-if="device.channels && device.channels.length > 0" class="channels-container">
          <div
            v-for="(channel, chIdx) in device.channels"
            :key="chIdx"
            class="channel-card"
          >
            <!-- Channel Header -->
            <div class="channel-header-info">
              <span class="channel-num">Ch {{ channel.number ?? chIdx }}</span>
              <span v-if="channel.header?.type" class="channel-type-badge">{{ channel.header.type }}</span>
              <span v-if="channel.header?.spec_a" class="channel-spec">{{ channel.header.spec_a }}</span>
              <span v-if="channel.header?.spec_b" class="channel-spec">{{ channel.header.spec_b }}</span>
              <span v-if="channel.header?.unit" class="channel-unit">[{{ channel.header.unit }}]</span>
            </div>

            <!-- Channel Info Grid -->
            <div class="channel-info mt-3">
              <div class="grid grid-cols-3 md:grid-cols-6 gap-2">
                <InfoField label="Type" :value="channel.type" size="small" />
                <InfoField label="Config" :value="channel.configuration" size="small" />
                <InfoField label="Gain" :value="channel.gain" size="small" />
                <InfoField label="Resolution" :value="channel.resolution" size="small" />
                <InfoField label="Range" :value="formatRange(channel)" size="small" />
                <InfoField label="Rate" :value="channel.rate" size="small" />
              </div>
              <div v-if="channel.note" class="mt-2">
                <InfoField label="Note" :value="channel.note" size="small" />
              </div>
            </div>

            <!-- Sensor info (if present) -->
            <div v-if="hasSensorData(channel)" class="sensor-info mt-3">
              <div class="subsection-title">Sensor/Actuator</div>
              <div class="grid grid-cols-2 md:grid-cols-4 gap-2">
                <InfoField label="Name" :value="channel.sensor?.name" size="small" />
                <InfoField label="Type" :value="channel.sensor?.type" size="small" />
                <InfoField label="Manufacturer" :value="channel.sensor?.manufacturer_id" size="small" />
                <InfoField label="Model" :value="channel.sensor?.model" size="small" />
              </div>
              <div class="grid grid-cols-3 gap-2 mt-2" v-if="channel.sensor?.serial_number || channel.sensor?.version_letter">
                <InfoField label="Version Letter" :value="channel.sensor?.version_letter" size="small" />
                <InfoField label="Version #" :value="channel.sensor?.version_number" size="small" />
                <InfoField label="Serial #" :value="channel.sensor?.serial_number" size="small" />
              </div>
            </div>

            <!-- Calibration info (if present) -->
            <div v-if="hasCalibrationData(channel)" class="calibration-info mt-3">
              <div class="subsection-title">Calibration</div>
              <div class="grid grid-cols-2 md:grid-cols-4 gap-2">
                <InfoField label="Template" :value="channel.calibration?.template" size="small" />
                <InfoField label="Input" :value="channel.calibration?.input" size="small" />
                <InfoField label="Unit" :value="channel.calibration?.unit" size="small" />
                <InfoField label="Excitation" :value="channel.calibration?.excitation" size="small" />
              </div>
              <div class="grid grid-cols-2 gap-2 mt-2">
                <InfoField label="Date" :value="formatDate(channel.calibration?.date)" size="small" />
                <InfoField label="Note" :value="channel.calibration?.note" size="small" />
              </div>
              <!-- Calibration data points -->
              <div v-if="channel.calibration?.data && channel.calibration.data.length > 0" class="cal-data mt-2">
                <span class="text-xs text-surface-400">Data Points:</span>
                <div class="flex flex-wrap gap-2 mt-1">
                  <span
                    v-for="(dp, dpIdx) in channel.calibration.data"
                    :key="dpIdx"
                    class="data-point"
                  >
                    A: {{ dp.a }}, B: {{ dp.b }}
                  </span>
                </div>
              </div>
            </div>
          </div>
        </div>

      </div>
    </div>

    <!-- No devices message -->
    <div v-else class="text-surface-500 text-center py-4">
      No DAQ devices configured.
    </div>
  </div>
</template>

<script setup>
import InfoField from '@/components/InfoField.vue'

defineProps({
  data: {
    type: Object,
    required: true
  }
})

function formatRange(channel) {
  if (channel.range_low && channel.range_high) {
    return `${channel.range_low} to ${channel.range_high}`
  }
  return channel.range_low || channel.range_high || ''
}

function formatDate(dateStr) {
  if (!dateStr) return ''
  try {
    return new Date(dateStr).toLocaleDateString()
  } catch {
    return dateStr
  }
}

function hasSensorData(channel) {
  const s = channel.sensor
  return s && (s.name || s.manufacturer_id || s.model || s.type)
}

function hasCalibrationData(channel) {
  const c = channel.calibration
  return c && (c.date || c.template || c.input || c.data?.length > 0)
}
</script>

<style scoped>
.daq-view {
  max-width: 1100px;
  margin: 0 auto;
}

.view-section {
  margin-bottom: 1.5rem;
  padding-bottom: 1.5rem;
  border-bottom: 1px solid var(--p-surface-600);
}

.view-section:last-child {
  border-bottom: none;
  margin-bottom: 0;
}

.section-title {
  font-size: 1.125rem;
  font-weight: 600;
  color: #ffffff;
  margin-bottom: 1rem;
  text-transform: uppercase;
}

.device-card {
  background: var(--p-surface-800);
  border: 1px solid var(--p-surface-600);
  border-radius: 6px;
  padding: 1rem;
  margin-bottom: 1rem;
}

.device-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 0.75rem;
}

.device-name {
  font-weight: 600;
  color: #ffffff;
  font-size: 1rem;
}

.channel-count {
  font-size: 0.75rem;
  font-weight: 500;
  color: #ffffff;
  background: var(--p-surface-700);
  border: 1px solid var(--p-surface-600);
  padding: 0.25rem 0.625rem;
  border-radius: 4px;
}

.channels-container {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.channel-card {
  background: var(--p-surface-900);
  border: 1px solid var(--p-surface-600);
  border-radius: 6px;
  padding: 0.75rem;
}

.channel-header-info {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  flex-wrap: wrap;
}

.channel-num {
  font-weight: 600;
  color: var(--p-primary-400);
  font-size: 0.875rem;
}

.channel-type-badge {
  font-size: 0.75rem;
  color: #ffffff;
  background: var(--p-surface-700);
  border: 1px solid var(--p-surface-600);
  padding: 0.25rem 0.5rem;
  border-radius: 4px;
  font-weight: 500;
}

.channel-spec {
  font-size: 0.875rem;
  color: #d1d5db;
}

.channel-unit {
  font-size: 0.75rem;
  color: var(--p-surface-400);
}

.channel-info {
  margin-top: 0.5rem;
}

.subsection-title {
  font-size: 1rem;
  font-weight: 600;
  color: #d1d5db;
  margin-bottom: 0.75rem;
}

.sensor-info,
.calibration-info,
.device-documents {
  padding-top: 0.5rem;
  border-top: 1px solid var(--p-surface-600);
}

.data-point {
  font-size: 0.75rem;
  background: var(--p-surface-700);
  border: 1px solid var(--p-surface-600);
  padding: 0.25rem 0.5rem;
  border-radius: 4px;
  color: #ffffff;
}
</style>
