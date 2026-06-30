<script setup lang="ts">
// List view with Name / Modified / Type / Size columns (web Drive parity).
// Inline badges next to the name carry client + sharing info; editing state
// keeps the red (someone else) / green (you) treatment from before.
import {
  FOLDER_TYPE_LABEL,
  fileTypeLabel,
  formatSize,
  formatRelativeDate,
} from '../utils/format'
import {
  getFileIcon,
  getFileIconBg,
  getFileIconColor,
  getFolderColor,
  getSharingStatus,
} from '../utils/fileVisuals'

interface EditingStatus {
  filename: string
  folder_id: number | null
  editor_email: string
}

defineProps<{
  folders: any[]
  files: any[]
  getFileEditor: (filename: string, folderId: number | null) => EditingStatus | null
  isSelfEditing: (filename: string, folderId: number | null) => boolean
}>()

const emit = defineEmits<{
  'navigate': [folderId: number, folderName: string]
  'context-menu': [event: MouseEvent, item: any, type: 'file' | 'folder']
  'download': [file: any]
}>()
</script>

<template>
  <div>
    <div class="list-header">
      <div class="col-name hcell">Name</div>
      <div class="col-mod hcell hsep">Modified</div>
      <div class="col-type hcell hsep">Type</div>
      <div class="col-size hcell hsep">Size</div>
      <div class="col-actions"></div>
    </div>

    <!-- Folders -->
    <div
      v-for="folder in folders"
      :key="'folder-' + folder.remoteId"
      @click="emit('navigate', folder.remoteId, folder.name)"
      @contextmenu="emit('context-menu', $event, folder, 'folder')"
      class="list-row group"
    >
      <div class="col-name name-cell">
        <span class="material-symbols-rounded folder-glyph" :style="'color: ' + getFolderColor(folder)">folder</span>
        <span class="item-name">{{ folder.name }}</span>
        <span v-if="folder.clientName" class="client-chip" :title="'Client: ' + folder.clientName">
          <span class="material-symbols-rounded">person</span>
          {{ folder.clientName }}
        </span>
        <span v-if="getSharingStatus(folder).hasLink" class="share-dot" title="Public link">
          <span class="material-symbols-rounded">link</span>
        </span>
        <span v-else-if="getSharingStatus(folder).isPublic" class="share-dot" title="Public">
          <span class="material-symbols-rounded">public</span>
        </span>
      </div>
      <div class="col-mod cell-dim">{{ formatRelativeDate(folder.lastSyncAt || '') }}</div>
      <div class="col-type cell-dim">{{ FOLDER_TYPE_LABEL }}</div>
      <div class="col-size cell-dim">—</div>
      <div class="col-actions">
        <button @click.stop="emit('context-menu', $event, folder, 'folder')" class="row-action opacity-0 group-hover:opacity-100" title="More">
          <span class="material-symbols-rounded">more_vert</span>
        </button>
      </div>
    </div>

    <!-- Files -->
    <div
      v-for="file in files"
      :key="'file-' + file.remoteId"
      @contextmenu="emit('context-menu', $event, file, 'file')"
      class="list-row group"
      :class="getFileEditor(file.filename, file.remoteFolderId)
        ? 'row-editing-other editing-pulse'
        : isSelfEditing(file.filename, file.remoteFolderId) ? 'row-editing-self self-editing-pulse' : ''"
    >
      <div class="col-name name-cell">
        <div style="position: relative; flex-shrink: 0;">
          <div
            class="file-icon-box"
            :style="getFileEditor(file.filename, file.remoteFolderId)
              ? 'background: rgba(239, 68, 68, 0.25)'
              : isSelfEditing(file.filename, file.remoteFolderId)
                ? 'background: rgba(34, 197, 94, 0.25)'
                : getFileIconBg(file.mimeType)"
          >
            <span
              class="material-symbols-rounded"
              :style="getFileEditor(file.filename, file.remoteFolderId)
                ? 'color: #ef4444'
                : isSelfEditing(file.filename, file.remoteFolderId) ? 'color: #22c55e' : getFileIconColor(file.mimeType)"
            >
              {{ getFileEditor(file.filename, file.remoteFolderId) || isSelfEditing(file.filename, file.remoteFolderId) ? 'edit_document' : getFileIcon(file.mimeType) }}
            </span>
          </div>
          <!-- Pulsating editing indicator -->
          <div
            v-if="getFileEditor(file.filename, file.remoteFolderId)"
            class="editing-dot-pulse edit-dot"
            style="background: #ef4444;"
          ></div>
          <div
            v-else-if="isSelfEditing(file.filename, file.remoteFolderId)"
            class="self-editing-dot-pulse edit-dot"
            style="background: #22c55e;"
          ></div>
        </div>
        <div class="name-stack">
          <span
            class="item-name"
            :style="getFileEditor(file.filename, file.remoteFolderId)
              ? 'color: #ef4444; font-weight: 500;'
              : isSelfEditing(file.filename, file.remoteFolderId) ? 'color: #22c55e; font-weight: 500;' : ''"
          >{{ file.filename }}</span>
          <span v-if="getFileEditor(file.filename, file.remoteFolderId)" class="editing-note" style="color: #ef4444;">
            <span class="material-symbols-rounded editing-icon-pulse">person</span>
            {{ getFileEditor(file.filename, file.remoteFolderId)!.editor_email }} is editing
          </span>
          <span v-else-if="isSelfEditing(file.filename, file.remoteFolderId)" class="editing-note" style="color: #22c55e;">
            <span class="material-symbols-rounded self-editing-icon-pulse">edit</span>
            You are editing this file
          </span>
        </div>
        <span v-if="file.clientName" class="client-chip" :title="'Client: ' + file.clientName">
          <span class="material-symbols-rounded">person</span>
          {{ file.clientName }}
        </span>
        <span v-if="getSharingStatus(file).hasLink" class="share-dot" title="Public link">
          <span class="material-symbols-rounded">link</span>
        </span>
        <span v-else-if="getSharingStatus(file).isPublic" class="share-dot" title="Public">
          <span class="material-symbols-rounded">public</span>
        </span>
      </div>
      <div class="col-mod cell-dim">{{ formatRelativeDate(file.remoteUpdatedAt) }}</div>
      <div class="col-type cell-dim">{{ fileTypeLabel(file.filename, file.mimeType) }}</div>
      <div class="col-size cell-dim">{{ formatSize(file.size) }}</div>
      <div class="col-actions">
        <button @click.stop="emit('download', file)" class="row-action opacity-0 group-hover:opacity-100" title="Download">
          <span class="material-symbols-rounded">download</span>
        </button>
        <button @click.stop="emit('context-menu', $event, file, 'file')" class="row-action opacity-0 group-hover:opacity-100" title="More">
          <span class="material-symbols-rounded">more_vert</span>
        </button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.list-header {
  display: flex;
  align-items: center;
  padding: 0 12px;
  position: sticky;
  top: 0;
  z-index: 10;
  background: transparent;
  border-bottom: 1px solid var(--border);
  font-size: 12px;
  font-weight: 500;
  color: var(--text-dim);
}

.hcell {
  padding: 7px 8px;
}

.hsep {
  border-left: 1px solid var(--border);
}

.col-name {
  flex: 1;
  min-width: 0;
}

.col-mod {
  width: 130px;
  flex-shrink: 0;
}

.col-type {
  width: 190px;
  flex-shrink: 0;
}

.col-size {
  width: 100px;
  flex-shrink: 0;
}

.col-actions {
  width: 64px;
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 1px;
}

.list-row {
  display: flex;
  align-items: center;
  padding: 5px 12px;
  border-bottom: 1px solid var(--border-subtle);
  cursor: pointer;
  transition: background 0.1s ease;
}

.list-row:hover {
  background: var(--bg-hover);
}

.list-row.row-editing-other {
  background: rgba(239, 68, 68, 0.12);
}

.list-row.row-editing-self {
  background: rgba(34, 197, 94, 0.12);
}

.list-row .col-mod,
.list-row .col-type,
.list-row .col-size {
  padding: 0 8px;
}

.cell-dim {
  color: var(--text-dim);
  font-size: 12px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.name-cell {
  display: flex;
  align-items: center;
  gap: 8px;
  padding-right: 8px;
}

.folder-glyph {
  font-size: 20px;
  flex-shrink: 0;
}

.file-icon-box {
  width: 24px;
  height: 24px;
  border-radius: 6px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.file-icon-box .material-symbols-rounded {
  font-size: 15px;
}

.edit-dot {
  position: absolute;
  top: -4px;
  right: -4px;
  width: 11px;
  height: 11px;
  border-radius: 50%;
  border: 2px solid var(--bg-main);
}

.name-stack {
  display: flex;
  flex-direction: column;
  overflow: hidden;
  min-width: 0;
}

.item-name {
  color: var(--text-primary);
  font-size: 13px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.editing-note {
  display: flex;
  align-items: center;
  gap: 4px;
  font-size: 11px;
  font-weight: 500;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.editing-note .material-symbols-rounded {
  font-size: 13px;
}

.client-chip {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  padding: 2px 6px;
  border-radius: 6px;
  font-size: 10px;
  font-weight: 500;
  background: rgba(34, 197, 94, 0.15);
  color: #22c55e;
  white-space: nowrap;
  flex-shrink: 0;
}

.client-chip .material-symbols-rounded {
  font-size: 11px;
}

.share-dot {
  width: 20px;
  height: 20px;
  border-radius: 6px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: rgba(34, 197, 94, 0.12);
  color: #22c55e;
  flex-shrink: 0;
}

.share-dot .material-symbols-rounded {
  font-size: 13px;
}

.row-action {
  padding: 4px;
  border-radius: 4px;
  color: var(--text-dim);
  transition: background 0.15s ease, color 0.15s ease, opacity 0.15s ease;
}

.row-action:hover {
  background: var(--bg-elevated);
  color: var(--text-primary);
}

.row-action .material-symbols-rounded {
  font-size: 15px;
}
</style>
