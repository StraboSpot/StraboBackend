<template>
  <div class="save-bar">
    <div class="save-bar-inner">
      <span class="save-status" :class="dirty ? 'save-status-dirty' : 'save-status-clean'">
        <span v-if="dirty" class="save-dot" aria-hidden="true"></span>
        {{ dirty ? 'Unsaved changes' : cleanText }}
      </span>
      <div class="save-bar-buttons">
        <button type="button" class="btn-secondary" @click="$emit('cancel')">
          Cancel
        </button>
        <button
          type="button"
          class="btn-primary"
          :class="{ 'save-attention': dirty }"
          :disabled="saving"
          @click="$emit('save')"
        >
          {{ saving ? 'Saving...' : saveLabel }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
defineProps({
  dirty: {
    type: Boolean,
    default: false
  },
  saving: {
    type: Boolean,
    default: false
  },
  saveLabel: {
    type: String,
    default: 'Save Changes'
  },
  cleanText: {
    type: String,
    default: 'All changes saved'
  }
})

defineEmits(['save', 'cancel'])
</script>

<style scoped>
.save-bar {
  position: fixed;
  left: 0;
  right: 0;
  bottom: 0;
  z-index: 40;
  background-color: var(--strabo-bg-secondary);
  border-top: 1px solid var(--strabo-border);
  box-shadow: 0 -2px 8px rgba(0, 0, 0, 0.35);
}

.save-bar-inner {
  max-width: 56rem;
  margin: 0 auto;
  padding: 0.75rem 1rem;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  flex-wrap: wrap;
}

.save-bar-buttons {
  display: flex;
  gap: 0.75rem;
  margin-left: auto;
}

.save-status {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 0.9rem;
}

.save-status-clean {
  color: var(--strabo-text-secondary);
}

.save-status-dirty {
  color: #fbbf24;
  font-weight: 600;
}

.save-dot {
  width: 0.6rem;
  height: 0.6rem;
  border-radius: 9999px;
  background-color: #fbbf24;
  animation: save-dot-pulse 1.6s ease-in-out infinite;
}

@keyframes save-dot-pulse {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.35; }
}
</style>
