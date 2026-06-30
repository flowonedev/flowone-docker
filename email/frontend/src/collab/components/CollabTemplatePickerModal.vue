<template>
  <Teleport to="body">
    <div 
      class="template-modal-overlay"
      @click.self="$emit('close')"
    >
      <div class="template-modal">
        <!-- Header -->
        <div class="modal-header">
          <h2 class="modal-title">Choose a slide layout</h2>
          <button @click="$emit('close')" class="close-btn">
            <span class="material-symbols-rounded">close</span>
          </button>
        </div>

        <div class="template-content">
          <!-- Custom templates section -->
          <div v-if="customTemplates.length > 0" class="template-section">
            <h3 class="section-title">
              <span class="material-symbols-rounded" style="font-size: 18px;">bookmark</span>
              My Templates
            </h3>
            <div class="template-grid-small">
              <div
                v-for="template in customTemplates"
                :key="template.id"
                class="template-card-wrapper"
              >
                <button
                  @click="selectTemplate(template.id, true)"
                  class="template-card"
                  :class="{ 'selected': selectedId === template.id }"
                >
                  <div class="template-preview">
                    <div class="preview-slide">
                      <div 
                        v-for="(obj, i) in getCustomPreviewObjects(template.id)" 
                        :key="i"
                        class="preview-object"
                        :style="getPreviewObjectStyle(obj)"
                      ></div>
                    </div>
                  </div>
                  <div class="template-info">
                    <span class="material-symbols-rounded template-icon">{{ template.icon }}</span>
                    <span class="template-name">{{ template.name }}</span>
                  </div>
                </button>
                <!-- Delete button -->
                <button
                  @click.stop="confirmDeleteTemplate(template)"
                  class="delete-template-btn"
                  title="Delete template"
                >
                  <span class="material-symbols-rounded" style="font-size: 16px;">delete</span>
                </button>
              </div>
            </div>
          </div>

          <!-- Built-in templates section -->
          <div class="template-section">
            <h3 v-if="customTemplates.length > 0" class="section-title">
              <span class="material-symbols-rounded" style="font-size: 18px;">dashboard</span>
              Built-in Layouts
            </h3>
            <div class="template-grid">
              <button
                v-for="template in builtInTemplates"
                :key="template.id"
                @click="selectTemplate(template.id, false)"
                class="template-card"
                :class="{ 'selected': selectedId === template.id }"
              >
                <div class="template-preview">
                  <div class="preview-slide">
                    <div 
                      v-for="(obj, i) in getPreviewObjects(template.id)" 
                      :key="i"
                      class="preview-object"
                      :style="getPreviewObjectStyle(obj)"
                    ></div>
                  </div>
                </div>
                <div class="template-info">
                  <span class="material-symbols-rounded template-icon">{{ template.icon }}</span>
                  <span class="template-name">{{ template.name }}</span>
                </div>
              </button>
            </div>
          </div>
        </div>

        <!-- Footer -->
        <div class="modal-footer">
          <button @click="$emit('close')" class="cancel-btn">
            Cancel
          </button>
          <button 
            @click="confirm"
            class="confirm-btn"
            :disabled="!selectedId"
          >
            <span class="material-symbols-rounded" style="font-size: 18px;">add</span>
            Add Slide
          </button>
        </div>
      </div>
    </div>

    <!-- Delete confirmation dialog -->
    <div 
      v-if="templateToDelete"
      class="template-modal-overlay"
      style="z-index: 10001;"
      @click.self="templateToDelete = null"
    >
      <div class="delete-confirm-modal">
        <div class="p-5 text-center">
          <span class="material-symbols-rounded text-red-500 mb-3" style="font-size: 48px;">delete_forever</span>
          <h3 class="text-lg font-semibold text-gray-900 mb-2">Delete Template?</h3>
          <p class="text-sm text-gray-600 mb-4">
            Are you sure you want to delete "{{ templateToDelete?.name }}"? This action cannot be undone.
          </p>
          <div class="flex items-center justify-center gap-3">
            <button
              @click="templateToDelete = null"
              class="px-4 py-2 text-sm text-gray-600 hover:bg-gray-100 rounded-full"
            >
              Cancel
            </button>
            <button
              @click="deleteTemplate"
              class="px-4 py-2 text-sm font-medium bg-red-500 hover:bg-red-600 text-white rounded-full"
            >
              Delete
            </button>
          </div>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { getTemplateList, slideTemplates, getCustomTemplates, deleteCustomTemplate } from '../data/slideTemplates.js'

const emit = defineEmits(['close', 'select'])

const builtInTemplates = getTemplateList()
const customTemplates = ref([])
const selectedId = ref('blank')
const isCustomSelected = ref(false)
const templateToDelete = ref(null)

// Load custom templates on mount
onMounted(() => {
  loadCustomTemplates()
})

function loadCustomTemplates() {
  customTemplates.value = getCustomTemplates()
}

function getPreviewObjects(templateId) {
  const template = slideTemplates[templateId]
  if (!template) return []
  return template.objects.slice(0, 6)
}

function getCustomPreviewObjects(templateId) {
  const template = customTemplates.value.find(t => t.id === templateId)
  if (!template) return []
  return template.objects.slice(0, 6)
}

function getPreviewObjectStyle(obj) {
  const scale = 0.1
  
  const style = {
    position: 'absolute',
    left: `${obj.x * scale}px`,
    top: `${obj.y * scale}px`,
    width: `${obj.width * scale}px`,
    height: `${obj.height * scale}px`,
  }
  
  if (obj.type === 'shape') {
    style.backgroundColor = obj.fill || '#e5e7eb'
    if (obj.borderRadius) {
      style.borderRadius = `${obj.borderRadius * scale}px`
    }
    if (obj.shapeType === 'ellipse') {
      style.borderRadius = '50%'
    }
  } else if (obj.type === 'text') {
    style.backgroundColor = '#e5e7eb'
  }
  
  return style
}

function selectTemplate(id, isCustom = false) {
  selectedId.value = id
  isCustomSelected.value = isCustom
}

function confirm() {
  if (selectedId.value) {
    emit('select', selectedId.value, isCustomSelected.value)
    emit('close')
  }
}

function confirmDeleteTemplate(template) {
  templateToDelete.value = template
}

function deleteTemplate() {
  if (templateToDelete.value) {
    deleteCustomTemplate(templateToDelete.value.id)
    loadCustomTemplates()
    
    // Clear selection if deleted template was selected
    if (selectedId.value === templateToDelete.value.id) {
      selectedId.value = 'blank'
      isCustomSelected.value = false
    }
    
    templateToDelete.value = null
  }
}
</script>

<style scoped>
.template-modal-overlay {
  position: fixed;
  inset: 0;
  z-index: 10000;
  background: rgba(0, 0, 0, 0.5);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 24px;
}

.template-modal {
  background: white;
  border-radius: 16px;
  width: 100%;
  max-width: 720px;
  max-height: 90vh;
  overflow: hidden;
  display: flex;
  flex-direction: column;
  box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
}

.dark .template-modal {
  background: #1f2937;
}

.modal-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 20px 24px;
  border-bottom: 1px solid #e5e7eb;
}

.dark .modal-header {
  border-bottom-color: #374151;
}

.modal-title {
  font-size: 20px;
  font-weight: 600;
  color: #111827;
}

.dark .modal-title {
  color: #f9fafb;
}

.close-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 36px;
  height: 36px;
  border: none;
  background: transparent;
  border-radius: 8px;
  cursor: pointer;
  color: #6b7280;
  transition: all 0.15s;
}

.close-btn:hover {
  background: #f3f4f6;
  color: #111827;
}

.dark .close-btn:hover {
  background: #374151;
  color: #f9fafb;
}

.template-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
  gap: 16px;
  padding: 24px;
  overflow-y: auto;
}

.template-card {
  display: flex;
  flex-direction: column;
  background: #f9fafb;
  border: 2px solid transparent;
  border-radius: 12px;
  padding: 12px;
  cursor: pointer;
  transition: all 0.15s;
  text-align: left;
}

.template-card:hover {
  background: #f3f4f6;
  border-color: #d1d5db;
}

.template-card.selected {
  background: #eff6ff;
  border-color: #2563eb;
}

.dark .template-card {
  background: #374151;
}

.dark .template-card:hover {
  background: #4b5563;
  border-color: #6b7280;
}

.dark .template-card.selected {
  background: #1e3a5f;
  border-color: #3b82f6;
}

.template-preview {
  aspect-ratio: 16 / 9;
  background: white;
  border-radius: 8px;
  overflow: hidden;
  margin-bottom: 12px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

.dark .template-preview {
  background: #1f2937;
}

.preview-slide {
  position: relative;
  width: 100%;
  height: 100%;
}

.preview-object {
  position: absolute;
  background: #e5e7eb;
}

.dark .preview-object {
  background: #4b5563;
}

.template-info {
  display: flex;
  align-items: center;
  gap: 8px;
}

.template-icon {
  font-size: 20px;
  color: #6b7280;
}

.template-card.selected .template-icon {
  color: #2563eb;
}

.template-name {
  font-size: 14px;
  font-weight: 500;
  color: #374151;
}

.dark .template-name {
  color: #e5e7eb;
}

.template-card.selected .template-name {
  color: #2563eb;
}

.modal-footer {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 12px;
  padding: 16px 24px;
  border-top: 1px solid #e5e7eb;
}

.dark .modal-footer {
  border-top-color: #374151;
}

.cancel-btn {
  padding: 10px 20px;
  border: none;
  background: transparent;
  border-radius: 9999px;
  font-size: 14px;
  font-weight: 500;
  color: #6b7280;
  cursor: pointer;
  transition: all 0.15s;
}

.cancel-btn:hover {
  background: #f3f4f6;
  color: #111827;
}

.dark .cancel-btn:hover {
  background: #374151;
  color: #f9fafb;
}

.confirm-btn {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 10px 24px;
  border: none;
  background: #2563eb;
  border-radius: 9999px;
  font-size: 14px;
  font-weight: 500;
  color: white;
  cursor: pointer;
  transition: all 0.15s;
}

.confirm-btn:hover:not(:disabled) {
  background: #1d4ed8;
}

.confirm-btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

/* Template content area */
.template-content {
  overflow-y: auto;
  max-height: calc(90vh - 200px);
}

.template-section {
  padding: 20px 24px;
}

.template-section:not(:last-child) {
  border-bottom: 1px solid #e5e7eb;
}

.dark .template-section:not(:last-child) {
  border-bottom-color: #374151;
}

.section-title {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 14px;
  font-weight: 600;
  color: #6b7280;
  margin-bottom: 16px;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.dark .section-title {
  color: #9ca3af;
}

.template-grid-small {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
  gap: 12px;
}

.template-card-wrapper {
  position: relative;
}

.delete-template-btn {
  position: absolute;
  top: 8px;
  right: 8px;
  width: 28px;
  height: 28px;
  display: flex;
  align-items: center;
  justify-content: center;
  background: white;
  border: 1px solid #e5e7eb;
  border-radius: 6px;
  color: #9ca3af;
  cursor: pointer;
  opacity: 0;
  transition: all 0.15s;
  z-index: 10;
}

.template-card-wrapper:hover .delete-template-btn {
  opacity: 1;
}

.delete-template-btn:hover {
  background: #fef2f2;
  border-color: #fecaca;
  color: #ef4444;
}

.dark .delete-template-btn {
  background: #374151;
  border-color: #4b5563;
}

.dark .delete-template-btn:hover {
  background: #450a0a;
  border-color: #7f1d1d;
}

/* Delete confirmation modal */
.delete-confirm-modal {
  background: white;
  border-radius: 16px;
  width: 320px;
  box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
}

.dark .delete-confirm-modal {
  background: #1f2937;
}
</style>

