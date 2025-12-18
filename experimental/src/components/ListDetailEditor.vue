<template>
  <div class="list-detail-editor">
    <!-- Header with title and Add button -->
    <div class="flex items-center gap-3 mb-3">
      <span v-if="title" class="text-sm font-semibold uppercase">{{ title }}</span>
      <Button
        size="small"
        :label="addLabel"
        @click="addItem"
      />
    </div>

    <!-- Empty state -->
    <div v-if="items.length === 0" class="text-surface-500 text-sm py-4">
      No items yet. Click "{{ addLabel }}" to add one.
    </div>

    <!-- Items displayed as rows -->
    <div v-else class="flex gap-3">
      <!-- Left side: Item list (tab buttons) -->
      <div class="item-list flex flex-col gap-1">
        <Button
          v-for="(item, idx) in items"
          :key="idx"
          :label="getItemLabel(item, idx)"
          :severity="selectedIndex === idx ? undefined : 'secondary'"
          :outlined="selectedIndex !== idx"
          size="small"
          @click="selectItem(idx)"
        />
      </div>

      <!-- Right side: Selected item's fields -->
      <div class="item-detail flex-1" v-if="selectedIndex !== null">
        <div class="flex items-start gap-3">
          <!-- Slot for custom fields -->
          <div class="flex-1">
            <slot
              name="detail"
              :item="items[selectedIndex]"
              :index="selectedIndex"
              :update="(field, value) => updateField(selectedIndex, field, value)"
            />
          </div>

          <!-- Action buttons -->
          <div class="flex gap-1 pt-6">
            <Button
              icon="pi pi-trash"
              severity="secondary"
              outlined
              size="small"
              @click="deleteItem(selectedIndex)"
              v-tooltip.top="'Delete'"
            />
            <Button
              icon="pi pi-arrow-up"
              severity="secondary"
              outlined
              size="small"
              :disabled="selectedIndex === 0"
              @click="moveItem(selectedIndex, -1)"
              v-tooltip.top="'Move Up'"
            />
            <Button
              icon="pi pi-arrow-down"
              severity="secondary"
              outlined
              size="small"
              :disabled="selectedIndex === items.length - 1"
              @click="moveItem(selectedIndex, 1)"
              v-tooltip.top="'Move Down'"
            />
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, watch } from 'vue'
import Button from 'primevue/button'

const props = defineProps({
  title: {
    type: String,
    default: 'Items'
  },
  addLabel: {
    type: String,
    default: 'Add Item'
  },
  items: {
    type: Array,
    required: true
  },
  labelField: {
    type: String,
    default: null
  },
  labelFunction: {
    type: Function,
    default: null
  },
  defaultItem: {
    type: [Object, Function],
    required: true
  },
  listWidth: {
    type: String,
    default: '140px'
  }
})

const emit = defineEmits(['update:items'])

const selectedIndex = ref(null)

// Select first item when items change and something exists
watch(() => props.items, (newItems) => {
  if (newItems.length > 0 && selectedIndex.value === null) {
    selectedIndex.value = 0
  } else if (newItems.length === 0) {
    selectedIndex.value = null
  } else if (selectedIndex.value >= newItems.length) {
    selectedIndex.value = newItems.length - 1
  }
}, { immediate: true })

function getItemLabel(item, idx) {
  if (props.labelFunction) {
    return props.labelFunction(item, idx)
  }
  if (props.labelField && item[props.labelField]) {
    return item[props.labelField]
  }
  return `Item ${idx + 1}`
}

function selectItem(idx) {
  selectedIndex.value = idx
}

function addItem() {
  const newItem = typeof props.defaultItem === 'function'
    ? props.defaultItem()
    : { ...props.defaultItem }

  const newItems = [...props.items, newItem]
  emit('update:items', newItems)

  // Select the new item
  selectedIndex.value = newItems.length - 1
}

function deleteItem(idx) {
  const newItems = props.items.filter((_, i) => i !== idx)
  emit('update:items', newItems)

  // Adjust selection
  if (newItems.length === 0) {
    selectedIndex.value = null
  } else if (selectedIndex.value >= newItems.length) {
    selectedIndex.value = newItems.length - 1
  }
}

function moveItem(idx, direction) {
  const newIdx = idx + direction
  if (newIdx < 0 || newIdx >= props.items.length) return

  const newItems = [...props.items]
  const [item] = newItems.splice(idx, 1)
  newItems.splice(newIdx, 0, item)
  emit('update:items', newItems)

  // Follow the moved item
  selectedIndex.value = newIdx
}

function updateField(idx, field, value) {
  const newItems = [...props.items]
  newItems[idx] = { ...newItems[idx], [field]: value }
  emit('update:items', newItems)
}
</script>

<style scoped>
.list-detail-editor {
  width: 100%;
}

.item-list {
  min-width: 100px;
}

.item-detail {
  flex: 1;
}
</style>
