<template>
  <div class="document-uploader">
    <!-- File not yet uploaded - show file input -->
    <div v-if="!isUploaded && !isUploading" class="file-input-container">
      <label class="text-sm text-surface-300">Choose File <span class="text-red-500">*</span></label>
      <input
        type="file"
        ref="fileInput"
        class="file-input"
        @change="handleFileSelect"
      />
    </div>

    <!-- Uploading - show progress bar -->
    <div v-if="isUploading" class="upload-progress">
      <div class="text-sm text-surface-300 mb-1">Uploading File...</div>
      <div class="progress-bar-container">
        <div class="progress-bar" :style="{ width: uploadProgress + '%' }"></div>
      </div>
      <div class="text-xs text-surface-400 mt-1">{{ uploadProgress }}%</div>
    </div>

    <!-- File uploaded - show link and delete option -->
    <div v-if="isUploaded && !isUploading" class="uploaded-file">
      <label class="text-sm text-surface-300">File Uploaded</label>
      <div class="file-info">
        <a :href="fileUrl" target="_blank" class="file-link" :title="fileUrl">
          {{ displayFilename }}
        </a>
        <a href="javascript:void(0);" @click="handleDelete" class="delete-link">
          (Delete)
        </a>
      </div>
    </div>

    <!-- Error message -->
    <div v-if="errorMessage" class="error-message text-red-400 text-sm mt-1">
      {{ errorMessage }}
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue'

const props = defineProps({
  // The UUID for this document (pre-generated)
  uuid: {
    type: String,
    required: true
  },
  // The current file path/URL (for editing existing documents)
  modelValue: {
    type: String,
    default: ''
  }
})

const emit = defineEmits(['update:modelValue', 'uploaded', 'deleted'])

const fileInput = ref(null)
const isUploading = ref(false)
const uploadProgress = ref(0)
const errorMessage = ref('')

// Check if file is already uploaded based on modelValue
const isUploaded = computed(() => {
  return props.modelValue && props.modelValue.length > 0
})

// Construct the file URL from the modelValue
const fileUrl = computed(() => {
  return props.modelValue || ''
})

// Extract just the filename from the URL for display
const displayFilename = computed(() => {
  if (!props.modelValue) return ''
  // Try to get filename from URL parameter
  try {
    const url = new URL(props.modelValue, window.location.origin)
    const filenameParam = url.searchParams.get('filename')
    if (filenameParam) return decodeURIComponent(filenameParam)
  } catch (e) {
    // Fallback: extract from path
  }
  // Fallback: get last segment of path
  const parts = props.modelValue.split('/')
  return parts[parts.length - 1] || props.modelValue
})

// Generate the file URL based on UUID and filename
function generateFileUrl(uuid, filename) {
  // Sanitize filename - replace spaces with underscores (matching old app)
  const sanitizedFilename = filename.replace(/\s+/g, '_')
  // Use the new view endpoint
  return `/newexperimental/api/view_document.php?uuid=${uuid}&filename=${encodeURIComponent(sanitizedFilename)}`
}

async function handleFileSelect(event) {
  const file = event.target.files[0]
  if (!file) return

  errorMessage.value = ''
  isUploading.value = true
  uploadProgress.value = 0

  const formData = new FormData()
  formData.append('uuid', props.uuid)
  formData.append('infile', file)

  try {
    const xhr = new XMLHttpRequest()

    // Track upload progress
    xhr.upload.onprogress = (e) => {
      if (e.lengthComputable) {
        uploadProgress.value = Math.round((e.loaded / e.total) * 100)
      }
    }

    xhr.onload = () => {
      if (xhr.status === 200) {
        // Small delay to show 100% completion (matching old app behavior)
        setTimeout(() => {
          isUploading.value = false
          const newUrl = generateFileUrl(props.uuid, file.name)
          emit('update:modelValue', newUrl)
          emit('uploaded', { uuid: props.uuid, filename: file.name, url: newUrl })
        }, 500)
      } else {
        isUploading.value = false
        errorMessage.value = 'Upload failed. Please try again.'
      }
    }

    xhr.onerror = () => {
      isUploading.value = false
      errorMessage.value = 'Upload failed. Please check your connection.'
    }

    // POST to the upload endpoint
    xhr.open('POST', '/newexperimental/api/upload_document.php')
    xhr.send(formData)

  } catch (error) {
    isUploading.value = false
    errorMessage.value = 'Upload failed: ' + error.message
  }
}

function handleDelete() {
  // Clear the file path and reset the input
  emit('update:modelValue', '')
  emit('deleted', { uuid: props.uuid })

  // Reset file input
  if (fileInput.value) {
    fileInput.value.value = ''
  }
}
</script>

<style scoped>
.document-uploader {
  display: flex;
  flex-direction: column;
}

.file-input-container {
  display: flex;
  flex-direction: column;
}

.file-input {
  color: var(--p-surface-300);
  font-size: 0.875rem;
  margin-top: 2px;
}

.file-input::file-selector-button {
  background-color: var(--p-surface-700);
  color: var(--p-surface-200);
  border: 1px solid var(--p-surface-600);
  border-radius: 4px;
  padding: 0.375rem 0.75rem;
  margin-right: 0.5rem;
  cursor: pointer;
  transition: background-color 0.2s;
}

.file-input::file-selector-button:hover {
  background-color: var(--p-surface-600);
}

.upload-progress {
  display: flex;
  flex-direction: column;
}

.progress-bar-container {
  width: 100%;
  height: 8px;
  background-color: var(--p-surface-700);
  border-radius: 4px;
  overflow: hidden;
}

.progress-bar {
  height: 100%;
  background-color: #28a745;
  transition: width 0.3s ease;
}

.uploaded-file {
  display: flex;
  flex-direction: column;
}

.file-info {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  margin-top: 2px;
}

.file-link {
  color: #60a5fa;
  font-size: 0.875rem;
  text-decoration: none;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  max-width: 200px;
}

.file-link:hover {
  color: #93c5fd;
  text-decoration: underline;
}

.delete-link {
  color: #f87171;
  font-size: 0.75rem;
  text-decoration: none;
  flex-shrink: 0;
}

.delete-link:hover {
  color: #fca5a5;
  text-decoration: underline;
}
</style>
