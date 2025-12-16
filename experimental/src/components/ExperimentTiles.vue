<template>
  <div class="experiment-tiles">
    <!-- Section tiles in 2 rows of 3 -->
    <div class="tiles-container">
      <!-- Row 1 -->
      <div class="tile-row">
        <div
          class="section-tile"
          :class="{ 'has-data': hasData('sample') }"
          @click="openSection('sample')"
        >
          <div class="tile-content">
            <div class="icon-wrapper">
              <!-- Sample: cube and cylinder representing rock specimens -->
              <Box class="tile-icon main-icon" :stroke-width="1.2" />
              <Cylinder class="tile-icon secondary-icon" :stroke-width="1.2" />
            </div>
            <span class="tile-label">Sample</span>
          </div>
          <CircleCheck v-if="hasData('sample')" class="check-icon" />
        </div>

        <div
          class="section-tile"
          :class="{ 'has-data': hasData('facilityApparatus') }"
          @click="openSection('facilityApparatus')"
        >
          <div class="tile-content">
            <div class="icon-wrapper">
              <!-- Facility & Apparatus: building + cog for lab equipment -->
              <Building2 class="tile-icon main-icon" :stroke-width="1.2" />
              <Wrench class="tile-icon secondary-icon" :stroke-width="1.2" />
            </div>
            <span class="tile-label">Facility and Apparatus</span>
          </div>
          <CircleCheck v-if="hasData('facilityApparatus')" class="check-icon" />
        </div>

        <div
          class="section-tile"
          :class="{ 'has-data': hasData('experiment') }"
          @click="openSection('experiment')"
        >
          <div class="tile-content">
            <div class="icon-wrapper">
              <!-- Experimental Setup: layout/assembly diagram -->
              <LayoutGrid class="tile-icon main-icon" :stroke-width="1.2" />
              <Settings class="tile-icon secondary-icon" :stroke-width="1.2" />
            </div>
            <span class="tile-label">Experimental Setup</span>
          </div>
          <CircleCheck v-if="hasData('experiment')" class="check-icon" />
        </div>
      </div>

      <!-- Row 2 -->
      <div class="tile-row">
        <div
          class="section-tile"
          :class="{ 'has-data': hasData('daq') }"
          @click="openSection('daq')"
        >
          <div class="tile-content">
            <div class="icon-wrapper">
              <!-- DAQ: circuit board + activity/signal -->
              <CircuitBoard class="tile-icon main-icon" :stroke-width="1.2" />
              <Activity class="tile-icon secondary-icon" :stroke-width="1.2" />
            </div>
            <span class="tile-label">DAQ</span>
          </div>
          <CircleCheck v-if="hasData('daq')" class="check-icon" />
        </div>

        <div
          class="section-tile"
          :class="{ 'has-data': hasData('protocol') }"
          @click="openSection('protocol')"
        >
          <div class="tile-content">
            <div class="icon-wrapper">
              <!-- Protocol: steps/list + chart showing controlled variables -->
              <ListOrdered class="tile-icon main-icon" :stroke-width="1.2" />
              <TrendingUp class="tile-icon secondary-icon" :stroke-width="1.2" />
            </div>
            <span class="tile-label">Protocol and<br>Controlled Variables</span>
          </div>
          <CircleCheck v-if="hasData('protocol')" class="check-icon" />
        </div>

        <div
          class="section-tile"
          :class="{ 'has-data': hasData('data') }"
          @click="openSection('data')"
        >
          <div class="tile-content">
            <div class="icon-wrapper">
              <!-- Data: table + chart for data/time series -->
              <Table class="tile-icon main-icon" :stroke-width="1.2" />
              <LineChart class="tile-icon secondary-icon" :stroke-width="1.2" />
            </div>
            <span class="tile-label">Data</span>
          </div>
          <CircleCheck v-if="hasData('data')" class="check-icon" />
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import {
  Box,
  Cylinder,
  Building2,
  Wrench,
  LayoutGrid,
  Settings,
  CircuitBoard,
  Activity,
  ListOrdered,
  TrendingUp,
  Table,
  LineChart,
  CircleCheck
} from 'lucide-vue-next'

const props = defineProps({
  experimentData: {
    type: Object,
    default: () => ({})
  }
})

const emit = defineEmits(['open-section'])

// Check if a section has data
const hasData = (section) => {
  if (!props.experimentData) return false

  switch (section) {
    case 'sample':
      return !!(props.experimentData.sample && Object.keys(props.experimentData.sample).length > 0)
    case 'facilityApparatus':
      return !!(
        (props.experimentData.facility && Object.keys(props.experimentData.facility).length > 0) ||
        (props.experimentData.apparatus && Object.keys(props.experimentData.apparatus).length > 0)
      )
    case 'experiment':
      return !!(props.experimentData.experiment && Object.keys(props.experimentData.experiment).length > 0)
    case 'daq':
      return !!(props.experimentData.daq && Object.keys(props.experimentData.daq).length > 0)
    case 'protocol':
      // Protocol is typically part of experiment section
      return !!(props.experimentData.experiment?.protocol && props.experimentData.experiment.protocol.length > 0)
    case 'data':
      return !!(props.experimentData.data && Object.keys(props.experimentData.data).length > 0)
    default:
      return false
  }
}

const openSection = (section) => {
  emit('open-section', section)
}
</script>

<style scoped>
.experiment-tiles {
  padding: 1rem;
}

.tiles-container {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  max-width: 750px;
  margin: 0 auto;
}

.tile-row {
  display: flex;
  justify-content: center;
  gap: 0.75rem;
}

.section-tile {
  position: relative;
  width: 230px;
  height: 230px;
  border: 2px solid #444;
  border-radius: 12px;
  cursor: pointer;
  overflow: hidden;
  background: linear-gradient(145deg, #2a2a2a 0%, #1f1f1f 100%);
  transition: all 0.25s ease;
}

.section-tile:hover {
  transform: translateY(-4px);
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.4);
  border-color: #f4511e;
  background: linear-gradient(145deg, #333 0%, #252525 100%);
}

.section-tile.has-data {
  border-color: #4caf50;
}

.section-tile.has-data:hover {
  border-color: #66bb6a;
}

.tile-content {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  height: 100%;
  padding: 1.5rem;
}

.icon-wrapper {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  margin-bottom: 1rem;
}

.tile-icon {
  color: #e0e0e0;
  transition: color 0.25s ease;
}

.main-icon {
  width: 64px;
  height: 64px;
}

.secondary-icon {
  width: 48px;
  height: 48px;
  opacity: 0.7;
}

.section-tile:hover .tile-icon {
  color: #ffffff;
}

.section-tile:hover .secondary-icon {
  opacity: 1;
}

.tile-label {
  font-family: 'Roboto', sans-serif;
  font-size: 1rem;
  font-weight: 500;
  color: #b0b0b0;
  text-align: center;
  line-height: 1.3;
  transition: color 0.25s ease;
}

.section-tile:hover .tile-label {
  color: #ffffff;
}

.check-icon {
  position: absolute;
  bottom: 10px;
  right: 10px;
  width: 24px;
  height: 24px;
  color: #4caf50;
}

/* Responsive adjustments */
@media (max-width: 768px) {
  .tile-row {
    flex-wrap: wrap;
  }

  .section-tile {
    width: 180px;
    height: 180px;
  }

  .main-icon {
    width: 48px;
    height: 48px;
  }

  .secondary-icon {
    width: 36px;
    height: 36px;
  }

  .tile-label {
    font-size: 0.875rem;
  }
}

@media (max-width: 600px) {
  .section-tile {
    width: 150px;
    height: 150px;
  }

  .main-icon {
    width: 40px;
    height: 40px;
  }

  .secondary-icon {
    width: 30px;
    height: 30px;
  }

  .tile-label {
    font-size: 0.75rem;
  }
}
</style>
