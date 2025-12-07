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
            <div class="channel-header">
              <span class="channel-num">Ch {{ channel.number || chIdx }}</span>
              <span v-if="channel.header?.spec_a" class="channel-spec">{{ channel.header.spec_a }}</span>
              <span v-if="channel.type" class="channel-type">{{ channel.type }}</span>
            </div>

            <div class="channel-info">
              <div class="grid grid-cols-3 md:grid-cols-6 gap-2">
                <InfoField label="Config" :value="channel.configuration" size="small" />
                <InfoField label="Gain" :value="channel.gain" size="small" />
                <InfoField label="Resolution" :value="channel.resolution" size="small" />
                <InfoField label="Range" :value="formatRange(channel)" size="small" />
                <InfoField label="Rate" :value="channel.rate" size="small" />
                <InfoField label="Filter" :value="channel.filter" size="small" />
              </div>
              <div v-if="channel.note" class="mt-2">
                <InfoField label="Note" :value="channel.note" size="small" />
              </div>
            </div>

            <!-- Sensor info (if present) -->
            <div v-if="hasSensorData(channel)" class="sensor-info mt-3">
              <div class="subsection-title">Sensor</div>
              <div class="grid grid-cols-2 md:grid-cols-4 gap-2">
                <InfoField label="Manufacturer" :value="channel.sensor?.manufacturer" size="small" />
                <InfoField label="Model" :value="channel.sensor?.model" size="small" />
                <InfoField label="Serial #" :value="channel.sensor?.serial_number" size="small" />
                <InfoField label="Type" :value="channel.sensor?.type" size="small" />
              </div>
              <div class="grid grid-cols-2 md:grid-cols-4 gap-2 mt-2">
                <InfoField label="Output" :value="channel.sensor?.output_type" size="small" />
                <InfoField label="Range" :value="channel.sensor?.range" size="small" />
                <InfoField label="Accuracy" :value="channel.sensor?.accuracy" size="small" />
              </div>
            </div>

            <!-- Calibration info (if present) -->
            <div v-if="hasCalibrationData(channel)" class="calibration-info mt-3">
              <div class="subsection-title">Calibration</div>
              <div class="grid grid-cols-2 md:grid-cols-4 gap-2">
                <InfoField label="Date" :value="formatDate(channel.calibration?.date)" size="small" />
                <InfoField label="Standard" :value="channel.calibration?.standard" size="small" />
                <InfoField label="Uncertainty" :value="channel.calibration?.uncertainty" size="small" />
                <InfoField label="Next Cal" :value="formatDate(channel.calibration?.next_calibration)" size="small" />
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
  return s && (s.manufacturer || s.model || s.serial_number || s.type)
}

function hasCalibrationData(channel) {
  const c = channel.calibration
  return c && (c.date || c.standard || c.uncertainty)
}
</script>

<style scoped>
.daq-view {
  max-width: 1100px;
  margin: 0 auto;
}

.view-section {
  margin-bottom: 1.5rem;
}

.section-title {
  font-size: 0.875rem;
  font-weight: 600;
  color: var(--p-surface-300);
  text-transform: uppercase;
  letter-spacing: 0.05em;
  margin-bottom: 0.75rem;
  border-bottom: 1px solid var(--p-surface-700);
  padding-bottom: 0.5rem;
}

.device-card {
  background: var(--p-surface-800);
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
  color: var(--p-surface-100);
  font-size: 1rem;
}

.channel-count {
  font-size: 0.75rem;
  color: var(--p-surface-400);
}

.channels-container {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.channel-card {
  background: var(--p-surface-900);
  border: 1px solid var(--p-surface-700);
  border-radius: 4px;
  padding: 0.75rem;
}

.channel-header {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  margin-bottom: 0.5rem;
}

.channel-num {
  font-weight: 600;
  color: var(--p-primary-400);
  font-size: 0.875rem;
}

.channel-spec {
  font-weight: 500;
  color: var(--p-surface-200);
}

.channel-type {
  font-size: 0.75rem;
  color: var(--p-surface-400);
  background: var(--p-surface-700);
  padding: 0.125rem 0.5rem;
  border-radius: 3px;
}

.channel-info {
  margin-top: 0.5rem;
}

.subsection-title {
  font-size: 0.75rem;
  font-weight: 600;
  color: var(--p-surface-400);
  text-transform: uppercase;
  letter-spacing: 0.05em;
  margin-bottom: 0.5rem;
  padding-bottom: 0.25rem;
  border-bottom: 1px dashed var(--p-surface-700);
}

.sensor-info,
.calibration-info {
  padding-top: 0.5rem;
  border-top: 1px solid var(--p-surface-700);
}
</style>
