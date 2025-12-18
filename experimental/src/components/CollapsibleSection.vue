<template>
  <div class="collapsible-section">
    <div
      class="collapsible-header"
      :class="{ 'collapsed': !isOpen }"
      @click="isOpen = !isOpen"
    >
      <h3 class="font-semibold text-strabo-text-primary">{{ title }}</h3>
      <span class="text-strabo-accent">{{ isOpen ? '▼' : '▶' }}</span>
    </div>
    <div v-show="isOpen" class="collapsible-content">
      <slot></slot>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'

const props = defineProps({
  title: {
    type: String,
    required: true
  },
  defaultOpen: {
    type: Boolean,
    default: false
  }
})

const isOpen = ref(props.defaultOpen)
</script>
