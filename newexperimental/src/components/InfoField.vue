<template>
  <div>
    <span class="text-xs text-strabo-text-secondary uppercase">{{ label }}</span>
    <p v-if="value" class="text-strabo-text-primary">
      <a v-if="isLink" :href="value" target="_blank" class="text-strabo-accent hover:underline">
        {{ value }}
      </a>
      <a v-else-if="isEmail" :href="`mailto:${value}`" class="text-strabo-accent hover:underline">
        {{ value }}
      </a>
      <span v-else>{{ value }}</span>
    </p>
    <p v-else class="text-strabo-text-secondary">—</p>
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
