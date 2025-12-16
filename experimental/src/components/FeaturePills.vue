<template>
  <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-2">
    <button
      v-for="feature in features"
      :key="feature"
      type="button"
      @click="toggleFeature(feature)"
      :class="[
        'feature-pill',
        modelValue.includes(feature) ? 'feature-pill-selected' : ''
      ]"
    >
      {{ feature }}
    </button>
  </div>
</template>

<script setup>
const props = defineProps({
  features: {
    type: Array,
    required: true
  },
  modelValue: {
    type: Array,
    default: () => []
  }
})

const emit = defineEmits(['update:modelValue'])

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
</script>

<style scoped>
.feature-pill {
  padding: 8px 12px;
  border: 1px solid #ffffff;
  border-radius: 9999px;
  background-color: transparent;
  color: #ffffff;
  cursor: pointer;
  transition: all 0.15s ease;
  text-align: center;
}

.feature-pill:hover {
  background-color: rgba(220, 53, 69, 0.3);
  border-color: #dc3545;
}

.feature-pill-selected {
  background-color: #dc3545;
  border-color: #dc3545;
  color: #ffffff;
}

.feature-pill-selected:hover {
  background-color: #c82333;
  border-color: #c82333;
}
</style>
