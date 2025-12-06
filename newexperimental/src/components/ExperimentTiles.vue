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
          <img :src="images.sample" alt="Sample" class="tile-image" />
          <img
            v-if="hasData('sample')"
            :src="images.greencheck"
            alt="Complete"
            class="check-icon"
          />
        </div>
        <div
          class="section-tile"
          :class="{ 'has-data': hasData('facilityApparatus') }"
          @click="openSection('facilityApparatus')"
        >
          <img :src="images.facilityApparatus" alt="Facility & Apparatus" class="tile-image" />
          <img
            v-if="hasData('facilityApparatus')"
            :src="images.greencheck"
            alt="Complete"
            class="check-icon"
          />
        </div>
        <div
          class="section-tile"
          :class="{ 'has-data': hasData('experiment') }"
          @click="openSection('experiment')"
        >
          <img :src="images.experiment" alt="Experimental Setup" class="tile-image" />
          <img
            v-if="hasData('experiment')"
            :src="images.greencheck"
            alt="Complete"
            class="check-icon"
          />
        </div>
      </div>

      <!-- Row 2 -->
      <div class="tile-row">
        <div
          class="section-tile"
          :class="{ 'has-data': hasData('daq') }"
          @click="openSection('daq')"
        >
          <img :src="images.daq" alt="DAQ" class="tile-image" />
          <img
            v-if="hasData('daq')"
            :src="images.greencheck"
            alt="Complete"
            class="check-icon"
          />
        </div>
        <div
          class="section-tile"
          :class="{ 'has-data': hasData('protocol') }"
          @click="openSection('protocol')"
        >
          <img :src="images.protocol" alt="Protocol" class="tile-image" />
          <img
            v-if="hasData('protocol')"
            :src="images.greencheck"
            alt="Complete"
            class="check-icon"
          />
        </div>
        <div
          class="section-tile"
          :class="{ 'has-data': hasData('data') }"
          @click="openSection('data')"
        >
          <img :src="images.data" alt="Data" class="tile-image" />
          <img
            v-if="hasData('data')"
            :src="images.greencheck"
            alt="Complete"
            class="check-icon"
          />
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'

// Import images as assets so Vite can handle them
import sampleTile from '@/assets/images/sample_tile.png'
import facilityApparatusTile from '@/assets/images/f_and_a_tile.png'
import experimentTile from '@/assets/images/experimental_setup_tile.png'
import daqTile from '@/assets/images/daq_tile.png'
import protocolTile from '@/assets/images/protocol_controlled_variables_tile.png'
import dataTile from '@/assets/images/data_tile.png'
import greencheck from '@/assets/images/greencheck.png'

const images = {
  sample: sampleTile,
  facilityApparatus: facilityApparatusTile,
  experiment: experimentTile,
  daq: daqTile,
  protocol: protocolTile,
  data: dataTile,
  greencheck: greencheck
}

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
  border: 1px solid #444;
  border-radius: 8px;
  cursor: pointer;
  overflow: hidden;
  background-color: #2a2a2a;
  transition: transform 0.2s, box-shadow 0.2s, border-color 0.2s;
}

.section-tile:hover {
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
  border-color: #f4511e;
}

.section-tile.has-data {
  border-color: #4caf50;
}

.tile-image {
  width: 100%;
  height: 100%;
  object-fit: contain;
}

.check-icon {
  position: absolute;
  bottom: 5px;
  right: 5px;
  width: 20px;
  height: 20px;
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
}

@media (max-width: 600px) {
  .section-tile {
    width: 150px;
    height: 150px;
  }
}
</style>
