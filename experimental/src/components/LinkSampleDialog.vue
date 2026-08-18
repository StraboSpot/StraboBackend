<template>
  <Dialog
    :visible="true"
    modal
    header="Link Sample From StraboSamples"
    :style="{ width: '640px', maxWidth: '95vw' }"
    :closable="true"
    @update:visible="$emit('close')"
    :pt="{
      root: { class: 'dark-mode' },
      header: { class: 'border-b border-surface-700' },
      content: { class: 'p-4' }
    }"
  >
    <p class="text-sm text-surface-400 mb-3">
      Your samples across StraboField, StraboMicro, and StraboExperimental.
      Select one to link it to this experiment.
    </p>

    <InputText
      v-model="searchText"
      placeholder="Search by name, IGSN, or description..."
      class="w-full mb-2"
      autofocus
    />

    <div v-if="error" class="text-red-400 text-sm my-3">{{ error }}</div>

    <div v-if="loading" class="flex justify-center py-6">
      <i class="pi pi-spin pi-spinner text-2xl" />
    </div>

    <div v-else-if="samples.length === 0" class="text-center py-6 text-surface-400">
      <p class="mb-2">No samples found in your StraboSamples account.</p>
      <p class="text-sm">
        Samples appear here after they are created in StraboField, StraboMicro,
        or StraboExperimental and uploaded to the server.
      </p>
    </div>

    <div v-else-if="filteredSamples.length === 0" class="text-center py-6 text-surface-400">
      No samples match your search.
    </div>

    <div v-else class="sample-list">
      <button
        v-for="sample in filteredSamples"
        :key="sample.id + '-' + sample.userpkey"
        type="button"
        class="sample-row"
        :class="{ 'sample-row-disabled': isDisabled(sample) }"
        :disabled="isDisabled(sample) || selecting !== null"
        @click="handleSelect(sample)"
      >
        <div class="flex-1 text-left">
          <div class="font-medium">
            {{ sample.name || `Sample ${sample.id}` }}
            <span v-if="isCurrent(sample)" class="text-xs text-surface-400">(currently linked)</span>
          </div>
          <div class="text-xs italic text-surface-400" v-if="isCollaborated(sample)">
            Owned by another user. Samples you collaborate on can't be linked from StraboExperimental yet.
          </div>
          <div class="text-xs text-surface-400" v-else>
            {{ secondaryText(sample) }}
          </div>
        </div>
        <i v-if="selecting === sample.id" class="pi pi-spin pi-spinner ml-2" />
      </button>
    </div>

    <div class="flex justify-end mt-4">
      <Button label="Cancel" severity="secondary" outlined @click="$emit('close')" />
    </div>
  </Dialog>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import { sampleLinkService } from '@/services/api'

const props = defineProps({
  // strabo_id the form is currently linked to (greyed as "currently linked")
  currentId: {
    type: String,
    default: null
  }
})

const emit = defineEmits(['close', 'select'])

const loading = ref(true)
const selecting = ref(null)
const error = ref(null)
const samples = ref([])
const viewerPkey = ref(null)
const searchText = ref('')

// Field vocabulary labels for display_sample_type (spine values); Exp's own
// samples carry LAPS labels verbatim, which pass through untranslated.
const TYPE_LABELS = {
  intact_rock: 'Intact Rock',
  fragmented_roc: 'Fragmented Rock',
  sediment: 'Sediment',
  tephra: 'Tephra',
  carbon_or_animal: 'Carbon or Animal',
  other: 'Other'
}

onMounted(async () => {
  try {
    const data = await sampleLinkService.getMySamples()
    samples.value = data.samples || []
    viewerPkey.value = data.viewer_pkey ?? null
  } catch (err) {
    error.value = err.message || 'Failed to load samples'
  } finally {
    loading.value = false
  }
})

const filteredSamples = computed(() => {
  const query = searchText.value.trim().toLowerCase()
  if (!query) return samples.value
  return samples.value.filter((s) => {
    return [s.name, s.igsn, s.description, s.id]
      .filter(v => typeof v === 'string' && v.length > 0)
      .join(' ')
      .toLowerCase()
      .includes(query)
  })
})

const isCollaborated = (sample) =>
  viewerPkey.value !== null && Number(sample.userpkey) !== Number(viewerPkey.value)

const isCurrent = (sample) =>
  props.currentId !== null && sample.id === props.currentId

const isDisabled = (sample) => isCollaborated(sample) || isCurrent(sample)

const secondaryText = (sample) => {
  const parts = []
  if (sample.display_sample_type) {
    parts.push(TYPE_LABELS[sample.display_sample_type] || sample.display_sample_type)
  }
  if (sample.description) parts.push(sample.description)
  if (Number(sample.experimental_link_count) > 0) {
    const n = Number(sample.experimental_link_count)
    parts.push(`linked to ${n} experiment${n === 1 ? '' : 's'}`)
  }
  return parts.join(' | ')
}

const handleSelect = async (sample) => {
  if (isDisabled(sample) || selecting.value) return
  selecting.value = sample.id
  error.value = null
  try {
    const record = await sampleLinkService.getSample(sample.id, sample.userpkey)
    if (!record) {
      error.value = 'That sample could not be loaded. It may have been deleted.'
      return
    }
    emit('select', record)
  } catch (err) {
    error.value = err.message || 'Failed to load sample'
  } finally {
    selecting.value = null
  }
}
</script>

<style scoped>
.sample-list {
  max-height: 400px;
  overflow-y: auto;
  border: 1px solid var(--p-surface-700);
  border-radius: 4px;
}

.sample-row {
  display: flex;
  align-items: center;
  width: 100%;
  padding: 0.6rem 0.9rem;
  background: transparent;
  border: none;
  border-bottom: 1px solid var(--p-surface-700);
  color: inherit;
  cursor: pointer;
}

.sample-row:last-child {
  border-bottom: none;
}

.sample-row:hover:not(:disabled) {
  background: var(--p-surface-800, #27272a);
}

.sample-row-disabled,
.sample-row:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}
</style>
