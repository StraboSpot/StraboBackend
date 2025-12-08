<template>
  <div class="feature-selector">
    <!-- Summary of selections -->
    <div v-if="modelValue.length > 0" class="selected-summary">
      <span class="summary-label">Selected ({{ modelValue.length }}):</span>
      <div class="selected-tags">
        <span
          v-for="feature in modelValue"
          :key="feature"
          class="selected-tag"
          @click="removeFeature(feature)"
        >
          {{ feature }}
          <span class="tag-remove">&times;</span>
        </span>
      </div>
    </div>

    <!-- Grouped categories -->
    <div class="categories">
      <div
        v-for="group in groups"
        :key="group.name"
        class="category"
      >
        <!-- Category header (clickable to expand/collapse) -->
        <button
          type="button"
          class="category-header"
          @click="toggleGroup(group.name)"
        >
          <span class="category-arrow">
            {{ expandedGroup === group.name ? '▼' : '►' }}
          </span>
          <span class="category-name">{{ group.name }}</span>
          <span
            v-if="getSelectedCount(group) > 0"
            class="category-count"
          >
            {{ getSelectedCount(group) }} selected
          </span>
        </button>

        <!-- Category content (checkboxes) -->
        <div
          v-show="expandedGroup === group.name"
          class="category-content"
        >
          <label
            v-for="feature in group.features"
            :key="feature"
            class="feature-checkbox"
          >
            <input
              type="checkbox"
              :checked="modelValue.includes(feature)"
              @change="toggleFeature(feature)"
            />
            <span class="checkbox-label">{{ feature }}</span>
          </label>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, watch, onMounted } from 'vue'
import { GROUPED_FEATURES } from '@/schemas/laps-enums'

const props = defineProps({
  modelValue: {
    type: Array,
    default: () => []
  }
})

const emit = defineEmits(['update:modelValue'])

const groups = GROUPED_FEATURES
const expandedGroup = ref(null)  // Only one group can be open at a time
const hasInitialized = ref(false)

// Auto-expand the first group that has selections when data first loads
const autoExpandSelected = () => {
  const firstGroupWithSelection = groups.find(g =>
    g.features.some(f => props.modelValue.includes(f))
  )
  if (firstGroupWithSelection) {
    expandedGroup.value = firstGroupWithSelection.name
  }
}

onMounted(() => {
  // Only auto-expand if we have initial data (edit mode)
  if (props.modelValue.length > 0) {
    autoExpandSelected()
  }
  hasInitialized.value = true
})

// Watch for external changes to modelValue (e.g., when loading existing data from parent)
// But only before the component has been interacted with
watch(() => props.modelValue, (newVal, oldVal) => {
  // Only auto-expand if this is new data being loaded externally
  // (detected by going from empty to having values, after mount)
  if (hasInitialized.value && oldVal?.length === 0 && newVal?.length > 0) {
    autoExpandSelected()
  }
}, { deep: true })

function toggleGroup(groupName) {
  // If clicking the already-open group, close it; otherwise open the new one
  expandedGroup.value = expandedGroup.value === groupName ? null : groupName
}

function getSelectedCount(group) {
  return group.features.filter(f => props.modelValue.includes(f)).length
}

function toggleFeature(feature) {
  const current = [...props.modelValue]
  const idx = current.indexOf(feature)
  if (idx === -1) {
    current.push(feature)
  } else {
    current.splice(idx, 1)
  }
  emit('update:modelValue', current)
}

function removeFeature(feature) {
  const current = props.modelValue.filter(f => f !== feature)
  emit('update:modelValue', current)
}
</script>

<style scoped>
.feature-selector {
  width: 100%;
}

/* Selected summary at top */
.selected-summary {
  margin-bottom: 1rem;
  padding: 0.75rem 1rem;
  background: var(--p-surface-800);
  border-radius: 4px;
  border: 1px solid var(--p-surface-600);
}

.summary-label {
  font-size: 0.875rem;
  color: var(--p-surface-400);
  text-transform: uppercase;
  letter-spacing: 0.05em;
  display: block;
  margin-bottom: 0.5rem;
}

.selected-tags {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}

.selected-tag {
  display: inline-flex;
  align-items: center;
  gap: 0.375rem;
  padding: 0.375rem 0.75rem;
  background: #dc3545;
  color: white;
  border-radius: 4px;
  font-size: 0.875rem;
  cursor: pointer;
  transition: background 0.15s;
}

.selected-tag:hover {
  background: #c82333;
}

.tag-remove {
  font-size: 1rem;
  line-height: 1;
  opacity: 0.8;
}

/* Categories */
.categories {
  display: flex;
  flex-direction: column;
  gap: 0.375rem;
}

.category {
  border: 1px solid var(--p-surface-600);
  border-radius: 4px;
  overflow: hidden;
}

.category-header {
  width: 100%;
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.625rem 1rem;
  background: var(--p-surface-800);
  border: none;
  color: var(--p-surface-200);
  cursor: pointer;
  text-align: left;
  font-size: 0.9375rem;
  transition: background 0.15s;
}

.category-header:hover {
  background: var(--p-surface-700);
}

.category-arrow {
  font-size: 0.75rem;
  width: 1rem;
  color: var(--p-surface-400);
}

.category-name {
  flex: 1;
  font-weight: 500;
}

.category-count {
  font-size: 0.8125rem;
  color: white;
  background: #dc3545;
  padding: 0.25rem 0.625rem;
  border-radius: 4px;
  font-weight: 500;
}

.category-content {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
  gap: 0.375rem 1.5rem;
  padding: 0.75rem 1rem 1rem;
  background: var(--p-surface-900);
  border-top: 1px solid var(--p-surface-700);
}

/* Checkboxes */
.feature-checkbox {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.375rem 0;
  cursor: pointer;
  font-size: 0.9375rem;
  color: var(--p-surface-300);
  transition: color 0.15s;
}

.feature-checkbox:hover {
  color: var(--p-surface-100);
}

.feature-checkbox input[type="checkbox"] {
  width: 1.125rem;
  height: 1.125rem;
  accent-color: #dc3545;
  cursor: pointer;
}

.checkbox-label {
  user-select: none;
}
</style>
