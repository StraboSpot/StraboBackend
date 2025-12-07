<template>
  <div class="info-field">
    <span class="info-label">{{ label }}</span>
    <p v-if="value" class="info-value">
      <a v-if="isLink" :href="value" target="_blank" class="info-link">
        {{ value }}
      </a>
      <a v-else-if="isEmail" :href="`mailto:${value}`" class="info-link">
        {{ value }}
      </a>
      <span v-else>{{ value }}</span>
    </p>
    <p v-else class="info-empty">—</p>
  </div>
</template>

<script setup>
defineProps({
  label: {
    type: String,
    required: true
  },
  value: {
    type: [String, Number],
    default: ''
  },
  isLink: {
    type: Boolean,
    default: false
  },
  isEmail: {
    type: Boolean,
    default: false
  }
})
</script>

<style scoped>
.info-field {
  margin-bottom: 0.25rem;
}

.info-label {
  display: block;
  font-size: 0.8125rem; /* 13px - slightly larger than text-xs */
  font-weight: 500;
  color: #9ca3af; /* gray-400 */
  text-transform: uppercase;
  letter-spacing: 0.025em;
  margin-bottom: 0.125rem;
}

.info-value {
  font-size: 1rem; /* 16px - larger, readable text */
  color: #ffffff; /* Pure white for better contrast */
  margin: 0;
  line-height: 1.4;
}

.info-empty {
  font-size: 1rem;
  color: #6b7280; /* gray-500 for empty values */
  margin: 0;
}

.info-link {
  color: #dc3545; /* StraboSpot red accent */
  text-decoration: none;
}

.info-link:hover {
  text-decoration: underline;
}
</style>
