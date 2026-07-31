<template>
  <Dialog
    :visible="visible"
    modal
    header="Unsaved Changes"
    :closable="false"
    :style="{ width: '420px', maxWidth: '95vw' }"
    :pt="{
      root: { class: 'dark-mode' },
      headerTitle: { class: 'text-lg' }
    }"
  >
    <p class="mb-5 text-strabo-text-secondary">
      You have unsaved changes that will be lost if you leave this page.
    </p>
    <div class="flex flex-col gap-2">
      <Button
        :label="saveLabel"
        :disabled="saving"
        @click="$emit('save')"
      />
      <Button
        label="Leave Without Saving"
        severity="danger"
        outlined
        :disabled="saving"
        @click="$emit('discard')"
      />
      <Button
        label="Keep Editing"
        severity="secondary"
        outlined
        :disabled="saving"
        @click="$emit('stay')"
      />
    </div>
  </Dialog>
</template>

<script setup>
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'

defineProps({
  visible: {
    type: Boolean,
    default: false
  },
  saving: {
    type: Boolean,
    default: false
  },
  saveLabel: {
    type: String,
    default: 'Save & Leave'
  }
})

defineEmits(['save', 'discard', 'stay'])
</script>
