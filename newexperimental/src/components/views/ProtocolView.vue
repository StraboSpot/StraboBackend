<template>
  <div class="protocol-view">
    <!-- Protocol Steps -->
    <div v-if="data && data.length > 0">
      <div
        v-for="(step, idx) in data"
        :key="idx"
        class="step-card"
      >
        <!-- Step header -->
        <div class="step-header">
          <span class="step-number">Step {{ idx + 1 }}</span>
          <span v-if="step.test" class="step-type">{{ step.test }}</span>
        </div>

        <!-- Step info -->
        <div class="step-info">
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <InfoField label="Objective" :value="step.objective" />
            <InfoField label="Description" :value="step.description" />
          </div>
        </div>

        <!-- Parameters table -->
        <div v-if="step.parameters && step.parameters.length > 0" class="parameters-section">
          <div class="subsection-title">Parameters</div>
          <div class="params-table">
            <div class="params-header">
              <div class="param-col param-col-variable">Variable</div>
              <div class="param-col param-col-value">Value</div>
              <div class="param-col param-col-unit">Unit</div>
              <div class="param-col param-col-note">Note</div>
            </div>
            <div
              v-for="(param, pIdx) in step.parameters"
              :key="pIdx"
              class="params-row"
            >
              <div class="param-col param-col-variable">
                <span class="param-value">{{ param.control || '-' }}</span>
              </div>
              <div class="param-col param-col-value">
                <span class="param-value">{{ param.value ?? '-' }}</span>
              </div>
              <div class="param-col param-col-unit">
                <span class="param-value">{{ param.unit || '-' }}</span>
              </div>
              <div class="param-col param-col-note">
                <span class="param-value">{{ param.note || '-' }}</span>
              </div>
            </div>
          </div>
        </div>

        <!-- No parameters message -->
        <div v-else class="no-params">
          No parameters defined for this step.
        </div>
      </div>
    </div>

    <!-- No steps message -->
    <div v-else class="text-surface-500 text-center py-4">
      No protocol steps defined.
    </div>
  </div>
</template>

<script setup>
import InfoField from '@/components/InfoField.vue'

defineProps({
  data: {
    type: Array,
    required: true
  }
})
</script>

<style scoped>
.protocol-view {
  max-width: 1100px;
  margin: 0 auto;
}

.step-card {
  background: var(--p-surface-800);
  border-radius: 6px;
  padding: 1rem;
  margin-bottom: 1rem;
}

.step-header {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  margin-bottom: 0.75rem;
  padding-bottom: 0.5rem;
  border-bottom: 1px solid var(--p-surface-700);
}

.step-number {
  font-size: 0.875rem;
  font-weight: 600;
  color: var(--p-primary-400);
}

.step-type {
  font-size: 1rem;
  font-weight: 600;
  color: var(--p-surface-100);
}

.step-info {
  margin-bottom: 0.75rem;
}

.parameters-section {
  padding-top: 0.75rem;
  border-top: 1px solid var(--p-surface-700);
}

.subsection-title {
  font-size: 0.75rem;
  font-weight: 600;
  color: var(--p-surface-400);
  text-transform: uppercase;
  letter-spacing: 0.05em;
  margin-bottom: 0.5rem;
}

.params-table {
  background: var(--p-surface-900);
  border: 1px solid var(--p-surface-700);
  border-radius: 4px;
  overflow: hidden;
}

.params-header {
  display: flex;
  gap: 0.5rem;
  padding: 0.5rem 0.75rem;
  font-weight: 600;
  font-size: 0.75rem;
  color: var(--p-surface-400);
  background: var(--p-surface-800);
  text-transform: uppercase;
  letter-spacing: 0.03em;
}

.params-row {
  display: flex;
  gap: 0.5rem;
  padding: 0.5rem 0.75rem;
  border-top: 1px solid var(--p-surface-800);
}

.params-row:first-of-type {
  border-top: none;
}

.param-col {
  flex-shrink: 0;
}

.param-col-variable {
  width: 180px;
}

.param-col-value {
  width: 100px;
}

.param-col-unit {
  width: 100px;
}

.param-col-note {
  flex: 1;
  min-width: 100px;
}

.param-value {
  font-size: 1rem;
  color: var(--p-surface-100);
}

.no-params {
  font-size: 0.875rem;
  color: var(--p-surface-500);
  padding: 0.5rem 0;
}
</style>
