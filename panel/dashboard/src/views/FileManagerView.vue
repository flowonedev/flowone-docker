<template>
  <div class="file-manager flex flex-col h-[calc(100vh-8rem)] sm:h-[calc(100vh-120px)] card overflow-hidden">
    <!-- Toolbar -->
    <div class="flex flex-wrap justify-between items-center gap-2 px-3 sm:px-4 py-2 border-b border-surface-200 dark:border-[rgb(var(--color-border))] bg-surface-50 dark:bg-[rgb(var(--color-surface))]">
      <div class="flex flex-wrap items-center gap-1.5 sm:gap-2">
        <button 
          class="btn-secondary btn-sm"
          :class="{ '!bg-primary-500/20 !text-primary-600 dark:!text-primary-400 !border-primary-500/50': dualPanelMode }" 
          @click="dualPanelMode = !dualPanelMode"
          title="Toggle dual panel"
        >
          <span class="material-symbols-rounded text-lg">view_column</span>
          <span class="hidden sm:inline">{{ dualPanelMode ? 'Single' : 'Dual' }}</span>
        </button>
        <div class="hidden sm:block w-px h-6 bg-surface-200 dark:bg-[rgb(var(--color-border))] mx-1"></div>
        <button class="btn-secondary btn-sm" @click="showNewFileModal = true" title="New File">
          <span class="material-symbols-rounded text-lg">note_add</span>
          <span class="hidden md:inline">New File</span>
        </button>
        <button class="btn-secondary btn-sm" @click="showNewFolderModal = true" title="New Folder">
          <span class="material-symbols-rounded text-lg">create_new_folder</span>
          <span class="hidden md:inline">New Folder</span>
        </button>
        <button class="btn-secondary btn-sm" @click="triggerUpload" title="Upload">
          <span class="material-symbols-rounded text-lg">upload</span>
          <span class="hidden md:inline">Upload</span>
        </button>
        <input 
          type="file" 
          ref="fileInput" 
          @change="handleUpload" 
          class="hidden" 
          multiple
        />
        <div class="hidden sm:block w-px h-6 bg-surface-200 dark:bg-[rgb(var(--color-border))] mx-1"></div>
        <button class="btn-secondary btn-sm" @click="forceRefreshAll" title="Force refresh all panels">
          <span class="material-symbols-rounded text-lg">refresh</span>
          <span class="hidden md:inline">Refresh</span>
        </button>
      </div>
      <div class="flex flex-wrap items-center gap-1.5 sm:gap-2">
        <button 
          class="btn-secondary btn-sm" 
          :disabled="!canCopyOrMove"
          @click="copyToOtherPanel"
          title="Copy selected to other panel (F5)"
        >
          <span class="material-symbols-rounded text-lg">content_copy</span>
          <span class="hidden lg:inline">Copy</span>
        </button>
        <button 
          class="btn-secondary btn-sm" 
          :disabled="!canCopyOrMove"
          @click="moveToOtherPanel"
          title="Move selected to other panel (F6)"
        >
          <span class="material-symbols-rounded text-lg">drive_file_move</span>
          <span class="hidden lg:inline">Move</span>
        </button>
        <button 
          class="btn-secondary btn-sm hover:!text-red-500 hover:!border-red-500" 
          :disabled="!hasSelection"
          @click="deleteSelected"
          title="Delete selected (F8)"
        >
          <span class="material-symbols-rounded text-lg">delete</span>
          <span class="hidden lg:inline">Delete</span>
        </button>
        <div class="hidden sm:block w-px h-6 bg-surface-200 dark:bg-[rgb(var(--color-border))] mx-1"></div>
        <label class="hidden sm:flex items-center gap-2 text-sm text-surface-600 dark:text-surface-300 cursor-pointer">
          <span class="hidden lg:inline">Show Hidden</span>
          <button 
            type="button"
            @click="showHidden = !showHidden; refreshAll()"
            class="relative w-9 h-5 rounded-full transition-colors"
            :class="showHidden ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'"
            title="Show Hidden Files"
          >
            <span 
              class="absolute top-0.5 w-4 h-4 bg-white rounded-full shadow transition-transform"
              :class="showHidden ? 'translate-x-4' : 'translate-x-0.5'"
            ></span>
          </button>
        </label>
      </div>
    </div>

    <!-- Dual Panel Layout -->
    <div class="flex flex-1 overflow-hidden" :class="{ 'gap-0': dualPanelMode }">
      <!-- Left Panel -->
      <FilePanel
        ref="leftPanel"
        :path="leftPath"
        :items="leftItems"
        :loading="leftLoading"
        :selected="leftSelected"
        :active="activePanel === 'left'"
        :show-hidden="showHidden"
        @update:selected="leftSelected = $event"
        @navigate="navigatePanel('left', $event)"
        @activate="activatePanel('left')"
        @context="showContextMenu($event.event, $event.item, 'left')"
        @preview="previewFile"
        @switch-panel="switchPanel"
      />

      <!-- Right Panel (shown in dual mode) -->
      <FilePanel
        v-if="dualPanelMode"
        ref="rightPanel"
        :path="rightPath"
        :items="rightItems"
        :loading="rightLoading"
        :selected="rightSelected"
        :active="activePanel === 'right'"
        :show-hidden="showHidden"
        @update:selected="rightSelected = $event"
        @navigate="navigatePanel('right', $event)"
        @activate="activatePanel('right')"
        @context="showContextMenu($event.event, $event.item, 'right')"
        @preview="previewFile"
        @switch-panel="switchPanel"
      />

      <!-- Preview Panel (single panel mode only) -->
      <div 
        v-if="!dualPanelMode" 
        class="flex flex-col border-l border-surface-200 dark:border-[rgb(var(--color-border))] bg-surface-50 dark:bg-[rgb(var(--color-surface))] relative"
        :style="previewVisible ? { width: previewWidth + 'px' } : { width: '24px' }"
      >
        <!-- Resize Handle -->
        <div 
          v-if="previewVisible"
          class="absolute left-0 top-0 bottom-0 w-1 cursor-ew-resize hover:bg-primary-500/50 z-20"
          @mousedown="startResizePreview"
        ></div>
        
        <button 
          class="absolute -left-3 top-1/2 -translate-y-1/2 w-6 h-12 flex items-center justify-center bg-surface-100 dark:bg-[rgb(var(--color-surface-elevated))] border border-surface-200 dark:border-[rgb(var(--color-border))] rounded-l-lg cursor-pointer text-surface-500 hover:text-surface-700 dark:hover:text-surface-300 z-10"
          @click="previewVisible = !previewVisible"
        >
          <span class="material-symbols-rounded text-sm">
            {{ previewVisible ? 'chevron_right' : 'chevron_left' }}
          </span>
        </button>

        <template v-if="previewVisible">
          <div v-if="!previewItem" class="flex-1 flex flex-col items-center justify-center gap-3 p-6 text-surface-400">
            <span class="material-symbols-rounded text-5xl">preview</span>
            <p class="text-sm">Select a file to preview</p>
          </div>

          <div v-else-if="previewLoading" class="flex-1 flex flex-col items-center justify-center gap-3 p-6 text-surface-400">
            <span class="material-symbols-rounded text-5xl animate-spin">progress_activity</span>
            <p class="text-sm">Loading...</p>
          </div>

          <div v-else class="flex flex-col flex-1 overflow-hidden">
            <!-- Header with filename and actions -->
            <div class="flex items-center gap-2 px-4 py-2 border-b border-surface-200 dark:border-[rgb(var(--color-border))]">
              <span class="material-symbols-rounded text-lg text-surface-500">{{ getIcon(previewItem) }}</span>
              <span class="flex-1 font-medium text-sm truncate">{{ previewItem.name }}</span>
              <div class="flex gap-1">
                <!-- View/Edit Toggle -->
                <button 
                  v-if="isEditable && !previewData?.is_binary"
                  class="w-8 h-8 flex items-center justify-center rounded-lg transition-colors"
                  :class="previewMode === 'view' 
                    ? 'bg-primary-500/20 text-primary-600 dark:text-primary-400' 
                    : 'text-surface-500 hover:bg-surface-200 dark:hover:bg-[rgb(var(--color-surface-hover))]'"
                  @click="previewMode = 'view'"
                  title="View mode (F3)"
                >
                  <span class="material-symbols-rounded text-lg">visibility</span>
                </button>
                <button 
                  v-if="isEditable && !previewData?.is_binary"
                  class="w-8 h-8 flex items-center justify-center rounded-lg transition-colors"
                  :class="previewMode === 'edit' 
                    ? 'bg-primary-500/20 text-primary-600 dark:text-primary-400' 
                    : 'text-surface-500 hover:bg-surface-200 dark:hover:bg-[rgb(var(--color-surface-hover))]'"
                  @click="previewMode = 'edit'; updateHighlightedContent()"
                  title="Edit mode (F4)"
                >
                  <span class="material-symbols-rounded text-lg">edit</span>
                </button>
                <button class="w-8 h-8 flex items-center justify-center rounded-lg text-surface-500 hover:bg-surface-200 dark:hover:bg-[rgb(var(--color-surface-hover))]" @click="downloadFile(previewItem)" title="Download">
                  <span class="material-symbols-rounded text-lg">download</span>
                </button>
                <!-- Fullscreen button only in View mode - Edit mode uses ConfigEditor's Zen Mode -->
                <button 
                  v-if="previewMode === 'view' && isEditable && !previewData?.is_binary"
                  class="w-8 h-8 flex items-center justify-center rounded-lg text-surface-500 hover:bg-surface-200 dark:hover:bg-[rgb(var(--color-surface-hover))]" 
                  @click="openZenMode"
                  title="Fullscreen View (F11)"
                >
                  <span class="material-symbols-rounded text-lg">fullscreen</span>
                </button>
              </div>
            </div>

            <!-- Search Bar (View mode only) -->
            <div v-if="previewMode === 'view' && isEditable && !previewData?.is_binary" class="flex items-center gap-2 px-3 py-2 border-b border-surface-200 dark:border-[rgb(var(--color-border))] bg-surface-100 dark:bg-[rgb(var(--color-surface-elevated))]">
              <span class="material-symbols-rounded text-base text-surface-400">search</span>
              <input 
                type="text" 
                v-model="viewerSearch"
                @input="highlightSearch"
                @keydown.enter="findNext"
                @keydown.shift.enter="findPrev"
                placeholder="Search... (Enter=next, Shift+Enter=prev)"
                class="flex-1 bg-transparent text-sm outline-none text-surface-700 dark:text-surface-200 placeholder-surface-400"
              />
              <span v-if="viewerSearch && searchMatches > 0" class="text-xs text-surface-500">
                {{ currentSearchIndex + 1 }}/{{ searchMatches }}
              </span>
              <button 
                v-if="viewerSearch"
                @click="findPrev"
                class="w-6 h-6 flex items-center justify-center rounded hover:bg-surface-200 dark:hover:bg-[rgb(var(--color-surface-hover))] text-surface-500"
                title="Previous (Shift+Enter)"
              >
                <span class="material-symbols-rounded text-base">keyboard_arrow_up</span>
              </button>
              <button 
                v-if="viewerSearch"
                @click="findNext"
                class="w-6 h-6 flex items-center justify-center rounded hover:bg-surface-200 dark:hover:bg-[rgb(var(--color-surface-hover))] text-surface-500"
                title="Next (Enter)"
              >
                <span class="material-symbols-rounded text-base">keyboard_arrow_down</span>
              </button>
              <button 
                v-if="viewerSearch"
                @click="viewerSearch = ''; highlightSearch()"
                class="w-6 h-6 flex items-center justify-center rounded hover:bg-surface-200 dark:hover:bg-[rgb(var(--color-surface-hover))] text-surface-500"
              >
                <span class="material-symbols-rounded text-base">close</span>
              </button>
            </div>

            <!-- File Info -->
            <div class="px-4 py-2 border-b border-surface-200 dark:border-[rgb(var(--color-border))] text-xs flex gap-4 text-surface-500 dark:text-surface-400">
              <span>{{ previewData?.size_human }}</span>
              <span>{{ getLanguageName(previewItem) }}</span>
              <span>{{ previewData?.modified }}</span>
            </div>

            <!-- Save Bar (Edit mode only) -->
            <div v-if="previewMode === 'edit' && hasChanges" class="flex items-center justify-between px-4 py-2 border-b border-amber-500/30 bg-amber-500/10">
              <span class="text-xs text-amber-600 dark:text-amber-400">Unsaved changes</span>
              <button 
                class="btn-primary btn-sm"
                @click="saveFile"
              >
                <span class="material-symbols-rounded text-base">save</span>
                Save
              </button>
            </div>

            <!-- Code Viewer (View mode with syntax highlighting) -->
            <div 
              v-if="previewMode === 'view' && isEditable && !previewData?.is_binary" 
              class="flex-1 overflow-auto code-viewer"
              ref="codeViewer"
            >
              <pre class="m-0 p-4 text-xs leading-relaxed"><code 
                ref="codeBlock"
                :class="'language-' + getLanguageClass(previewItem)"
                v-html="highlightedContent"
              ></code></pre>
            </div>

            <!-- Text Editor (Edit mode with ConfigEditor) -->
            <div v-else-if="previewMode === 'edit' && isEditable && !previewData?.is_binary" class="flex-1 overflow-hidden file-config-editor">
              <ConfigEditor
                v-model="editorContent"
                :language="getEditorLanguage(previewItem)"
                :service="getEditorService(previewItem)"
                height="100%"
                :show-toolbar="true"
                :zen-title="previewItem?.name || 'File Editor'"
                @save="saveFile"
              />
            </div>

            <!-- Image Preview -->
            <div v-else-if="isImage" class="flex-1 flex items-center justify-center p-4 overflow-auto">
              <img :src="imageDataUrl" :alt="previewItem.name" class="max-w-full max-h-full object-contain rounded-lg" />
            </div>

            <!-- Binary/Large File -->
            <div v-else class="flex-1 flex flex-col items-center justify-center gap-4 p-6 text-surface-400">
              <span class="material-symbols-rounded text-5xl">description</span>
              <p class="text-sm text-center">Binary file or content too large to preview</p>
              <button class="btn-secondary" @click="downloadFile(previewItem)">
                <span class="material-symbols-rounded">download</span>
                Download
              </button>
            </div>
          </div>
        </template>
      </div>
    </div>

    <!-- Keyboard Shortcuts Help -->
    <div class="hidden sm:flex flex-wrap gap-4 px-4 py-2 bg-surface-50 dark:bg-[rgb(var(--color-surface))] border-t border-surface-200 dark:border-[rgb(var(--color-border))] text-xs text-surface-500 dark:text-surface-400">
      <span class="flex items-center gap-1"><kbd class="px-1.5 py-0.5 bg-surface-200 dark:bg-[rgb(var(--color-surface-elevated))] rounded text-[10px]">Enter</kbd> Open</span>
      <span class="flex items-center gap-1"><kbd class="px-1.5 py-0.5 bg-surface-200 dark:bg-[rgb(var(--color-surface-elevated))] rounded text-[10px]">Backspace</kbd> Go up</span>
      <span class="flex items-center gap-1"><kbd class="px-1.5 py-0.5 bg-surface-200 dark:bg-[rgb(var(--color-surface-elevated))] rounded text-[10px]">Tab</kbd> Switch panel</span>
      <span v-if="!dualPanelMode" class="flex items-center gap-1"><kbd class="px-1.5 py-0.5 bg-surface-200 dark:bg-[rgb(var(--color-surface-elevated))] rounded text-[10px]">F3</kbd> View</span>
      <span v-if="!dualPanelMode" class="flex items-center gap-1"><kbd class="px-1.5 py-0.5 bg-surface-200 dark:bg-[rgb(var(--color-surface-elevated))] rounded text-[10px]">F4</kbd> Edit</span>
      <span class="flex items-center gap-1"><kbd class="px-1.5 py-0.5 bg-surface-200 dark:bg-[rgb(var(--color-surface-elevated))] rounded text-[10px]">F5</kbd> Copy</span>
      <span class="flex items-center gap-1"><kbd class="px-1.5 py-0.5 bg-surface-200 dark:bg-[rgb(var(--color-surface-elevated))] rounded text-[10px]">F6</kbd> Move</span>
      <span class="flex items-center gap-1"><kbd class="px-1.5 py-0.5 bg-surface-200 dark:bg-[rgb(var(--color-surface-elevated))] rounded text-[10px]">F7</kbd> New folder</span>
      <span class="flex items-center gap-1"><kbd class="px-1.5 py-0.5 bg-surface-200 dark:bg-[rgb(var(--color-surface-elevated))] rounded text-[10px]">F8</kbd> Delete</span>
      <span class="flex items-center gap-1"><kbd class="px-1.5 py-0.5 bg-surface-200 dark:bg-[rgb(var(--color-surface-elevated))] rounded text-[10px]">Ctrl+A</kbd> Select all</span>
    </div>

    <!-- Context Menu -->
    <div
      v-if="contextMenu.visible"
      class="fixed z-50 bg-white dark:bg-[rgb(var(--color-surface-elevated))] border border-surface-200 dark:border-[rgb(var(--color-border-strong))] rounded-lg py-2 min-w-[180px] shadow-xl"
      :style="{ top: contextMenu.y + 'px', left: contextMenu.x + 'px' }"
      @click.stop
    >
      <template v-if="contextMenu.item">
        <div class="flex items-center gap-3 px-4 py-2 text-sm text-surface-700 dark:text-surface-200 hover:bg-surface-100 dark:hover:bg-[rgb(var(--color-surface-hover))] cursor-pointer" @click="openContextItem">
          <span class="material-symbols-rounded">{{ contextMenu.item.type === 'directory' ? 'folder_open' : 'edit' }}</span>
          {{ contextMenu.item.type === 'directory' ? 'Open' : 'Edit' }}
        </div>
        <div class="flex items-center gap-3 px-4 py-2 text-sm text-surface-700 dark:text-surface-200 hover:bg-surface-100 dark:hover:bg-[rgb(var(--color-surface-hover))] cursor-pointer" @click="renameItem(contextMenu.item)">
          <span class="material-symbols-rounded">edit</span>
          Rename
        </div>
        <div class="h-px bg-surface-200 dark:bg-[rgb(var(--color-border))] my-1"></div>
        <div class="flex items-center gap-3 px-4 py-2 text-sm text-surface-700 dark:text-surface-200 hover:bg-surface-100 dark:hover:bg-[rgb(var(--color-surface-hover))] cursor-pointer" @click="copyContextItem" v-if="dualPanelMode">
          <span class="material-symbols-rounded">content_copy</span>
          Copy to other panel
        </div>
        <div class="flex items-center gap-3 px-4 py-2 text-sm text-surface-700 dark:text-surface-200 hover:bg-surface-100 dark:hover:bg-[rgb(var(--color-surface-hover))] cursor-pointer" @click="moveContextItem" v-if="dualPanelMode">
          <span class="material-symbols-rounded">drive_file_move</span>
          Move to other panel
        </div>
        <div class="h-px bg-surface-200 dark:bg-[rgb(var(--color-border))] my-1" v-if="dualPanelMode"></div>
        <div class="flex items-center gap-3 px-4 py-2 text-sm text-surface-700 dark:text-surface-200 hover:bg-surface-100 dark:hover:bg-[rgb(var(--color-surface-hover))] cursor-pointer" @click="downloadFile(contextMenu.item)" v-if="contextMenu.item.type !== 'directory'">
          <span class="material-symbols-rounded">download</span>
          Download
        </div>
        <div class="flex items-center gap-3 px-4 py-2 text-sm text-surface-700 dark:text-surface-200 hover:bg-surface-100 dark:hover:bg-[rgb(var(--color-surface-hover))] cursor-pointer" @click="compressItems([contextMenu.item])">
          <span class="material-symbols-rounded">compress</span>
          Compress
        </div>
        <div class="flex items-center gap-3 px-4 py-2 text-sm text-surface-700 dark:text-surface-200 hover:bg-surface-100 dark:hover:bg-[rgb(var(--color-surface-hover))] cursor-pointer" @click="extractArchive(contextMenu.item)" v-if="isArchive(contextMenu.item)">
          <span class="material-symbols-rounded">unarchive</span>
          Extract
        </div>
        <div class="h-px bg-surface-200 dark:bg-[rgb(var(--color-border))] my-1"></div>
        <div class="flex items-center gap-3 px-4 py-2 text-sm text-surface-700 dark:text-surface-200 hover:bg-surface-100 dark:hover:bg-[rgb(var(--color-surface-hover))] cursor-pointer" @click="showPermissionsModal(contextMenu.item)">
          <span class="material-symbols-rounded">admin_panel_settings</span>
          Permissions
        </div>
        <div class="flex items-center gap-3 px-4 py-2 text-sm text-surface-700 dark:text-surface-200 hover:bg-surface-100 dark:hover:bg-[rgb(var(--color-surface-hover))] cursor-pointer" @click="showInfoModal(contextMenu.item)">
          <span class="material-symbols-rounded">info</span>
          Properties
        </div>
        <div class="h-px bg-surface-200 dark:bg-[rgb(var(--color-border))] my-1"></div>
        <div class="flex items-center gap-3 px-4 py-2 text-sm text-red-500 hover:bg-red-50 dark:hover:bg-red-500/10 cursor-pointer" @click="deleteContextItem">
          <span class="material-symbols-rounded">delete</span>
          Delete
        </div>
      </template>
      <template v-else>
        <div class="flex items-center gap-3 px-4 py-2 text-sm cursor-pointer" :class="clipboard.items.length ? 'text-surface-700 dark:text-surface-200 hover:bg-surface-100 dark:hover:bg-[rgb(var(--color-surface-hover))]' : 'text-surface-400 cursor-not-allowed'" @click="pasteItems">
          <span class="material-symbols-rounded">content_paste</span>
          Paste
        </div>
        <div class="h-px bg-surface-200 dark:bg-[rgb(var(--color-border))] my-1"></div>
        <div class="flex items-center gap-3 px-4 py-2 text-sm text-surface-700 dark:text-surface-200 hover:bg-surface-100 dark:hover:bg-[rgb(var(--color-surface-hover))] cursor-pointer" @click="showNewFileModal = true">
          <span class="material-symbols-rounded">note_add</span>
          New File
        </div>
        <div class="flex items-center gap-3 px-4 py-2 text-sm text-surface-700 dark:text-surface-200 hover:bg-surface-100 dark:hover:bg-[rgb(var(--color-surface-hover))] cursor-pointer" @click="showNewFolderModal = true">
          <span class="material-symbols-rounded">create_new_folder</span>
          New Folder
        </div>
        <div class="flex items-center gap-3 px-4 py-2 text-sm text-surface-700 dark:text-surface-200 hover:bg-surface-100 dark:hover:bg-[rgb(var(--color-surface-hover))] cursor-pointer" @click="triggerUpload">
          <span class="material-symbols-rounded">upload</span>
          Upload
        </div>
        <div class="h-px bg-surface-200 dark:bg-[rgb(var(--color-border))] my-1"></div>
        <div class="flex items-center gap-3 px-4 py-2 text-sm text-surface-700 dark:text-surface-200 hover:bg-surface-100 dark:hover:bg-[rgb(var(--color-surface-hover))] cursor-pointer" @click="refreshActivePanel">
          <span class="material-symbols-rounded">refresh</span>
          Refresh
        </div>
      </template>
    </div>

    <!-- New File Modal -->
    <div v-if="showNewFileModal" class="modal-overlay" @click.self="showNewFileModal = false">
      <div class="modal">
        <div class="modal-header flex items-center justify-between">
          <h3>New File</h3>
          <button class="btn-close" @click="showNewFileModal = false">
            <span class="material-symbols-rounded">close</span>
          </button>
        </div>
        <div class="modal-body">
          <div class="form-group">
            <label>Filename</label>
            <input 
              type="text" 
              v-model="newFileName" 
              @keyup.enter="createFile"
              placeholder="filename.txt"
              ref="newFileInput"
            />
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn" @click="showNewFileModal = false">Cancel</button>
          <button class="btn btn-primary" @click="createFile" :disabled="!newFileName">Create</button>
        </div>
      </div>
    </div>

    <!-- New Folder Modal -->
    <div v-if="showNewFolderModal" class="modal-overlay" @click.self="showNewFolderModal = false">
      <div class="modal">
        <div class="modal-header flex items-center justify-between">
          <h3>New Folder</h3>
          <button class="btn-close" @click="showNewFolderModal = false">
            <span class="material-symbols-rounded">close</span>
          </button>
        </div>
        <div class="modal-body">
          <div class="form-group">
            <label>Folder Name</label>
            <input 
              type="text" 
              v-model="newFolderName" 
              @keyup.enter="createFolder"
              placeholder="new-folder"
              ref="newFolderInput"
            />
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn" @click="showNewFolderModal = false">Cancel</button>
          <button class="btn btn-primary" @click="createFolder" :disabled="!newFolderName">Create</button>
        </div>
      </div>
    </div>

    <!-- Rename Modal -->
    <div v-if="renameModal.visible" class="modal-overlay" @click.self="renameModal.visible = false">
      <div class="modal">
        <div class="modal-header flex items-center justify-between">
          <h3>Rename</h3>
          <button class="btn-close" @click="renameModal.visible = false">
            <span class="material-symbols-rounded">close</span>
          </button>
        </div>
        <div class="modal-body">
          <div class="form-group">
            <label>New Name</label>
            <input 
              type="text" 
              v-model="renameModal.newName" 
              @keyup.enter="confirmRename"
              ref="renameInput"
            />
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn" @click="renameModal.visible = false">Cancel</button>
          <button class="btn btn-primary" @click="confirmRename" :disabled="!renameModal.newName">Rename</button>
        </div>
      </div>
    </div>

    <!-- Permissions Modal -->
    <div v-if="permissionsModal.visible" class="modal-overlay" @click.self="permissionsModal.visible = false">
      <div class="modal modal-lg">
        <div class="modal-header flex items-center justify-between">
          <h3>Permissions</h3>
          <button class="btn-close" @click="permissionsModal.visible = false">
            <span class="material-symbols-rounded">close</span>
          </button>
        </div>
        <div class="modal-body">
          <div class="form-group">
            <label>Path</label>
            <input type="text" :value="permissionsModal.item?.path" disabled />
          </div>
          <div class="form-group">
            <label>Mode (octal)</label>
            <input type="text" v-model="permissionsModal.mode" placeholder="0755" />
          </div>
          <div class="form-group">
            <label>Owner</label>
            <input type="text" v-model="permissionsModal.owner" placeholder="www-data" />
          </div>
          <div class="form-group">
            <label>Group</label>
            <input type="text" v-model="permissionsModal.group" placeholder="www-data" />
          </div>
          <div class="form-group">
            <label class="flex items-center justify-between cursor-pointer">
              <span class="text-sm text-surface-600 dark:text-surface-300">Apply recursively</span>
              <button 
                type="button"
                @click="permissionsModal.recursive = !permissionsModal.recursive"
                class="relative w-11 h-6 rounded-full transition-colors"
                :class="permissionsModal.recursive ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'"
              >
                <span 
                  class="absolute top-1 w-4 h-4 bg-white rounded-full shadow transition-transform"
                  :class="permissionsModal.recursive ? 'translate-x-6' : 'translate-x-1'"
                ></span>
              </button>
            </label>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn" @click="permissionsModal.visible = false">Cancel</button>
          <button class="btn btn-primary" @click="savePermissions">Apply</button>
        </div>
      </div>
    </div>

    <!-- Info Modal -->
    <div v-if="infoModal.visible" class="modal-overlay" @click.self="infoModal.visible = false">
      <div class="modal modal-lg">
        <div class="modal-header flex items-center justify-between">
          <h3>Properties</h3>
          <button class="btn-close" @click="infoModal.visible = false">
            <span class="material-symbols-rounded">close</span>
          </button>
        </div>
        <div class="modal-body">
          <div class="properties-grid" v-if="infoModal.data">
            <div class="prop-row">
              <span class="prop-label">Name:</span>
              <span class="prop-value">{{ infoModal.data.name }}</span>
            </div>
            <div class="prop-row">
              <span class="prop-label">Path:</span>
              <span class="prop-value mono">{{ infoModal.data.real_path }}</span>
            </div>
            <div class="prop-row">
              <span class="prop-label">Type:</span>
              <span class="prop-value">{{ infoModal.data.type }}</span>
            </div>
            <div class="prop-row">
              <span class="prop-label">Size:</span>
              <span class="prop-value">{{ infoModal.data.size_human }} ({{ infoModal.data.size }} bytes)</span>
            </div>
            <div class="prop-row" v-if="infoModal.data.total_size_human">
              <span class="prop-label">Total Size:</span>
              <span class="prop-value">{{ infoModal.data.total_size_human }}</span>
            </div>
            <div class="prop-row">
              <span class="prop-label">Permissions:</span>
              <span class="prop-value mono">{{ infoModal.data.permissions }} ({{ infoModal.data.permissions_octal }})</span>
            </div>
            <div class="prop-row">
              <span class="prop-label">Owner:</span>
              <span class="prop-value">{{ infoModal.data.owner }} ({{ infoModal.data.owner_uid }})</span>
            </div>
            <div class="prop-row">
              <span class="prop-label">Group:</span>
              <span class="prop-value">{{ infoModal.data.group }} ({{ infoModal.data.group_gid }})</span>
            </div>
            <div class="prop-row">
              <span class="prop-label">Modified:</span>
              <span class="prop-value">{{ infoModal.data.modified }}</span>
            </div>
            <div class="prop-row">
              <span class="prop-label">Created:</span>
              <span class="prop-value">{{ infoModal.data.created }}</span>
            </div>
            <div class="prop-row">
              <span class="prop-label">Accessed:</span>
              <span class="prop-value">{{ infoModal.data.accessed }}</span>
            </div>
            <div class="prop-row" v-if="infoModal.data.mime_type">
              <span class="prop-label">MIME Type:</span>
              <span class="prop-value">{{ infoModal.data.mime_type }}</span>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn" @click="infoModal.visible = false">Close</button>
        </div>
      </div>
    </div>

    <!-- Confirm Delete Modal -->
    <div v-if="confirmModal.visible" class="modal-overlay" @click.self="cancelConfirm">
      <div class="modal max-w-md">
        <div class="modal-body text-center py-8">
          <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-red-500/10 flex items-center justify-center">
            <span class="material-symbols-rounded text-4xl text-red-500">delete_forever</span>
          </div>
          <h3 class="text-lg font-semibold text-surface-900 dark:text-surface-100 mb-2">
            {{ confirmModal.title }}
          </h3>
          <p class="text-sm text-surface-600 dark:text-surface-400 mb-2">
            {{ confirmModal.message }}
          </p>
          <div v-if="confirmModal.items && confirmModal.items.length > 0" class="mt-4 max-h-32 overflow-auto bg-surface-100 dark:bg-[rgb(var(--color-surface))] rounded-lg p-3">
            <div 
              v-for="item in confirmModal.items.slice(0, 5)" 
              :key="item.path" 
              class="flex items-center gap-2 text-sm text-surface-700 dark:text-surface-300 py-1"
            >
              <span class="material-symbols-rounded text-base" :class="item.type === 'directory' ? 'text-amber-500' : 'text-surface-400'">
                {{ item.type === 'directory' ? 'folder' : 'description' }}
              </span>
              <span class="truncate">{{ item.name }}</span>
            </div>
            <div v-if="confirmModal.items.length > 5" class="text-xs text-surface-500 mt-2">
              ... and {{ confirmModal.items.length - 5 }} more
            </div>
          </div>
        </div>
        <div class="modal-footer justify-center gap-4 border-t-0 bg-transparent">
          <button class="btn-secondary px-6" @click="cancelConfirm">
            Cancel
          </button>
          <button class="btn-danger px-6" @click="executeConfirm">
            <span class="material-symbols-rounded text-lg">delete</span>
            Delete
          </button>
        </div>
      </div>
    </div>

    <!-- Zen Mode Overlay -->
    <Teleport to="body">
      <Transition name="zen-fade">
        <div v-if="zenMode" class="zen-overlay" @keydown.esc="closeZenMode">
          <!-- Header -->
          <div class="zen-header">
            <div class="flex items-center gap-3">
              <span class="material-symbols-rounded text-xl text-surface-400">{{ getIcon(previewItem) }}</span>
              <span class="text-surface-200 font-medium">{{ previewItem?.name }}</span>
              <span class="text-surface-500 text-sm">{{ getLanguageName(previewItem) }}</span>
            </div>
            <div class="flex items-center gap-2">
              <span v-if="zenHasChanges" class="text-amber-400 text-sm flex items-center gap-1">
                <span class="material-symbols-rounded text-base">edit</span>
                Unsaved changes
              </span>
              <button 
                class="zen-btn"
                @click="saveZenFile"
                :disabled="!zenHasChanges"
                :class="{ 'opacity-50 cursor-not-allowed': !zenHasChanges }"
              >
                <span class="material-symbols-rounded text-lg">save</span>
                Save
              </button>
              <button class="zen-btn" @click="closeZenMode">
                <span class="material-symbols-rounded text-lg">fullscreen_exit</span>
                Exit
              </button>
            </div>
          </div>

          <!-- Editor -->
          <div class="zen-editor-container">
            <div class="zen-editor-wrapper">
              <!-- Line numbers -->
              <div class="zen-line-numbers" ref="zenLineNumbers">
                <div v-for="n in zenLineCount" :key="n" class="zen-line-number">{{ n }}</div>
              </div>
              <!-- Highlighted code background -->
              <pre 
                class="zen-highlight"
                ref="zenHighlight"
                aria-hidden="true"
              ><code 
                :class="'language-' + getLanguageClass(previewItem)"
                v-html="zenHighlightedContent"
              ></code></pre>
              <!-- Textarea overlay -->
              <textarea
                ref="zenEditor"
                v-model="zenContent"
                class="zen-textarea"
                spellcheck="false"
                @input="onZenInput"
                @scroll="syncZenScroll"
                @keydown.ctrl.s.prevent="saveZenFile"
                @keydown.meta.s.prevent="saveZenFile"
              ></textarea>
            </div>
          </div>

          <!-- Footer -->
          <div class="zen-footer">
            <span class="text-surface-500 text-xs">Line {{ zenCursorLine }}, Col {{ zenCursorCol }}</span>
            <span class="text-surface-500 text-xs">{{ zenLineCount }} lines</span>
            <span class="text-surface-500 text-xs">Press <kbd class="zen-kbd">Esc</kbd> to exit | <kbd class="zen-kbd">Ctrl+S</kbd> to save</span>
          </div>
        </div>
      </Transition>
    </Teleport>
  </div>
</template>

<script>
import api from '@/services/api';
import { cache, TTL } from '@/services/cache';
import { useToastStore } from '@/stores/toast';
import FilePanel from '@/components/FilePanel.vue';
import ConfigEditor from '@/components/ConfigEditor.vue';
import hljs from 'highlight.js/lib/core';
import javascript from 'highlight.js/lib/languages/javascript';
import php from 'highlight.js/lib/languages/php';
import css from 'highlight.js/lib/languages/css';
import xml from 'highlight.js/lib/languages/xml';
import bash from 'highlight.js/lib/languages/bash';
import json from 'highlight.js/lib/languages/json';
import sql from 'highlight.js/lib/languages/sql';
import yaml from 'highlight.js/lib/languages/yaml';
import ini from 'highlight.js/lib/languages/ini';
import python from 'highlight.js/lib/languages/python';
import 'highlight.js/styles/github-dark.css';

// Register languages
hljs.registerLanguage('javascript', javascript);
hljs.registerLanguage('php', php);
hljs.registerLanguage('css', css);
hljs.registerLanguage('xml', xml);
hljs.registerLanguage('html', xml);
hljs.registerLanguage('bash', bash);
hljs.registerLanguage('shell', bash);
hljs.registerLanguage('sh', bash);
hljs.registerLanguage('json', json);
hljs.registerLanguage('sql', sql);
hljs.registerLanguage('yaml', yaml);
hljs.registerLanguage('ini', ini);
hljs.registerLanguage('python', python);

export default {
  name: 'FileManagerView',
  components: { FilePanel, ConfigEditor },
  
  setup() {
    const toastStore = useToastStore();
    return { toastStore };
  },

  data() {
    return {
      // Panel mode
      dualPanelMode: true,
      activePanel: 'left',
      
      // Left panel state
      leftPath: '/home',
      leftItems: [],
      leftLoading: false,
      leftSelected: [],
      
      // Right panel state
      rightPath: '/home',
      rightItems: [],
      rightLoading: false,
      rightSelected: [],
      
      // Common settings
      showHidden: false,
      
      // Preview panel (single mode)
      previewVisible: true,
      previewWidth: 450,
      isResizingPreview: false,
      previewItem: null,
      previewLoading: false,
      previewData: null,
      editorContent: '',
      originalContent: '',
      hasChanges: false,
      previewMode: 'view', // 'view' or 'edit'
      viewerSearch: '',
      searchMatches: 0,
      currentSearchIndex: 0,
      highlightedContent: '',
      editorHighlightedContent: '',
      
      // Clipboard
      clipboard: {
        items: [],
        operation: null,
      },
      
      // Context menu
      contextMenu: {
        visible: false,
        x: 0,
        y: 0,
        item: null,
        panel: null,
      },
      
      // Modals
      showNewFileModal: false,
      showNewFolderModal: false,
      newFileName: '',
      newFolderName: '',
      
      renameModal: {
        visible: false,
        item: null,
        newName: '',
        panel: null,
      },
      
      permissionsModal: {
        visible: false,
        item: null,
        mode: '',
        owner: '',
        group: '',
        recursive: false,
      },
      
      infoModal: {
        visible: false,
        data: null,
      },
      
      confirmModal: {
        visible: false,
        title: '',
        message: '',
        items: [],
        onConfirm: null,
      },

      // Zen mode
      zenMode: false,
      zenContent: '',
      zenOriginalContent: '',
      zenHasChanges: false,
      zenHighlightedContent: '',
      zenCursorLine: 1,
      zenCursorCol: 1,
    };
  },

  computed: {
    canCopyOrMove() {
      return this.dualPanelMode && this.hasSelection;
    },
    
    hasSelection() {
      return this.activePanel === 'left' 
        ? this.leftSelected.length > 0 
        : this.rightSelected.length > 0;
    },
    
    currentSelection() {
      return this.activePanel === 'left' ? this.leftSelected : this.rightSelected;
    },
    
    currentPath() {
      return this.activePanel === 'left' ? this.leftPath : this.rightPath;
    },
    
    otherPath() {
      return this.activePanel === 'left' ? this.rightPath : this.leftPath;
    },
    
    isEditable() {
      if (!this.previewItem || this.previewItem.type === 'directory') return false;
      const editableExtensions = ['txt', 'md', 'json', 'xml', 'html', 'htm', 'css', 'js', 'ts', 'jsx', 'tsx', 'php', 'py', 'rb', 'sh', 'bash', 'yml', 'yaml', 'conf', 'cfg', 'ini', 'env', 'htaccess', 'log', 'sql', 'vue', 'svelte'];
      const ext = this.previewItem.extension || '';
      return editableExtensions.includes(ext) || this.previewItem.name.startsWith('.');
    },
    
    isImage() {
      if (!this.previewData) return false;
      return this.previewData.mime_type?.startsWith('image/');
    },
    
    imageDataUrl() {
      if (!this.previewData || !this.isImage) return '';
      const mime = this.previewData.mime_type;
      const content = this.previewData.content;
      if (this.previewData.is_binary) {
        return `data:${mime};base64,${content}`;
      }
      return `data:${mime};base64,${btoa(content)}`;
    },

    zenLineCount() {
      if (!this.zenContent) return 1;
      return this.zenContent.split('\n').length;
    },
  },

  watch: {
    showNewFileModal(val) {
      if (val) this.$nextTick(() => this.$refs.newFileInput?.focus());
    },
    showNewFolderModal(val) {
      if (val) this.$nextTick(() => this.$refs.newFolderInput?.focus());
    },
    'renameModal.visible'(val) {
      if (val) this.$nextTick(() => this.$refs.renameInput?.focus());
    },
    // Track changes when ConfigEditor updates editorContent
    editorContent(newVal) {
      if (this.previewMode === 'edit' && this.originalContent !== undefined) {
        this.hasChanges = newVal !== this.originalContent;
      }
    },
  },

  mounted() {
    this.loadPanel('left');
    this.loadPanel('right');
    document.addEventListener('click', this.hideContextMenu);
    document.addEventListener('keydown', this.handleKeydown);
    document.addEventListener('mousemove', this.onResizePreview);
    document.addEventListener('mouseup', this.stopResizePreview);
  },

  beforeUnmount() {
    document.removeEventListener('click', this.hideContextMenu);
    document.removeEventListener('keydown', this.handleKeydown);
    document.removeEventListener('mousemove', this.onResizePreview);
    document.removeEventListener('mouseup', this.stopResizePreview);
  },

  methods: {
    async loadPanel(panel, path = null, forceRefresh = false) {
      const isLeft = panel === 'left';
      if (path) {
        if (isLeft) this.leftPath = path;
        else this.rightPath = path;
      }
      
      const currentPath = isLeft ? this.leftPath : this.rightPath;
      const cacheKey = `files:${currentPath}:${this.showHidden}`;
      
      // Check cache first (short TTL for files - 5 minutes)
      if (!forceRefresh) {
        const cached = cache.get(cacheKey);
        if (cached) {
          if (isLeft) {
            this.leftItems = cached;
            this.leftSelected = [];
            this.leftLoading = false;
          } else {
            this.rightItems = cached;
            this.rightSelected = [];
            this.rightLoading = false;
          }
          return;
        }
      }
      
      if (isLeft) {
        this.leftLoading = true;
        this.leftSelected = [];
      } else {
        this.rightLoading = true;
        this.rightSelected = [];
      }
      
      try {
        const response = await api.get('/files', {
          params: {
            path: currentPath,
            show_hidden: this.showHidden,
            sort_by: 'name',
            sort_dir: 'asc',
          },
        });
        
        const items = response.data.data.items;
        cache.set(cacheKey, items, TTL.SHORT); // 5 min TTL for files
        
        if (isLeft) {
          this.leftItems = items;
        } else {
          this.rightItems = items;
        }
      } catch (error) {
        this.toastStore.error(error.response?.data?.message || 'Failed to load directory');
      } finally {
        if (isLeft) this.leftLoading = false;
        else this.rightLoading = false;
      }
    },
    
    navigatePanel(panel, path) {
      this.loadPanel(panel, path);
    },
    
    
    refreshAll(forceRefresh = false) {
      this.loadPanel('left', null, forceRefresh);
      if (this.dualPanelMode) this.loadPanel('right', null, forceRefresh);
    },
    
    forceRefreshAll() {
      // Invalidate cache for current paths
      cache.invalidatePrefix('files:');
      this.refreshAll(true);
      this.toastStore.success('Refreshed');
    },
    
    refreshActivePanel(forceRefresh = true) {
      this.hideContextMenu();
      if (forceRefresh) {
        // Invalidate cache for current path
        const currentPath = this.activePanel === 'left' ? this.leftPath : this.rightPath;
        cache.invalidate(`files:${currentPath}:${this.showHidden}`);
      }
      this.loadPanel(this.activePanel, null, forceRefresh);
    },
    
    switchPanel() {
      if (!this.dualPanelMode) return;
      this.activePanel = this.activePanel === 'left' ? 'right' : 'left';
      this.$nextTick(() => {
        const panel = this.activePanel === 'left' ? this.$refs.leftPanel : this.$refs.rightPanel;
        panel?.focus();
      });
    },
    
    // Preview panel resize
    startResizePreview(e) {
      this.isResizingPreview = true;
      e.preventDefault();
    },
    
    onResizePreview(e) {
      if (!this.isResizingPreview) return;
      const containerWidth = window.innerWidth;
      const newWidth = containerWidth - e.clientX;
      this.previewWidth = Math.max(300, Math.min(800, newWidth));
    },
    
    stopResizePreview() {
      this.isResizingPreview = false;
    },
    
    activatePanel(panel) {
      this.activePanel = panel;
      this.$nextTick(() => {
        const ref = panel === 'left' ? this.$refs.leftPanel : this.$refs.rightPanel;
        ref?.focus();
      });
    },
    
    handleKeydown(event) {
      // Ignore if in modal or input
      if (this.showNewFileModal || this.showNewFolderModal || this.renameModal.visible) return;
      if (event.target.tagName === 'INPUT' || event.target.tagName === 'TEXTAREA') return;
      
      switch (event.key) {
        case 'Tab':
          event.preventDefault();
          if (this.dualPanelMode) this.switchPanel();
          break;
        case 'F3':
          event.preventDefault();
          if (this.previewItem && this.isEditable && !this.previewData?.is_binary) {
            this.previewMode = 'view';
            this.updateHighlightedContent();
          }
          break;
        case 'F4':
          event.preventDefault();
          if (this.previewItem && this.isEditable && !this.previewData?.is_binary) {
            this.previewMode = 'edit';
            this.updateHighlightedContent();
          }
          break;
        case 'F5':
          event.preventDefault();
          if (this.canCopyOrMove) this.copyToOtherPanel();
          break;
        case 'F6':
          event.preventDefault();
          if (this.canCopyOrMove) this.moveToOtherPanel();
          break;
        case 'F7':
          event.preventDefault();
          this.showNewFolderModal = true;
          break;
        case 'F8':
          event.preventDefault();
          if (this.hasSelection) this.deleteSelected();
          break;
        case 's':
          if ((event.ctrlKey || event.metaKey) && this.hasChanges) {
            event.preventDefault();
            this.saveFile();
          }
          break;
        case 'Escape':
          if (this.zenMode) {
            this.closeZenMode();
          } else {
            this.hideContextMenu();
          }
          break;
        case 'F11':
          event.preventDefault();
          if (this.zenMode) {
            this.closeZenMode();
          } else if (this.previewItem && this.isEditable && !this.previewData?.is_binary) {
            this.openZenMode();
          }
          break;
      }
    },
    
    
    async copyToOtherPanel() {
      if (!this.canCopyOrMove) return;
      
      const items = this.currentSelection;
      const destination = this.otherPath;
      
      try {
        for (const item of items) {
          await api.post('/files/copy', {
            source: item.path,
            destination: `${destination}/${item.name}`,
          });
        }
        
        this.toastStore.success(`Copied ${items.length} item(s)`);
        this.loadPanel(this.activePanel === 'left' ? 'right' : 'left');
      } catch (error) {
        this.toastStore.error(error.response?.data?.message || 'Failed to copy');
      }
    },
    
    async moveToOtherPanel() {
      if (!this.canCopyOrMove) return;
      
      const items = this.currentSelection;
      const destination = this.otherPath;
      
      try {
        for (const item of items) {
          await api.post('/files/move', {
            source: item.path,
            destination: `${destination}/${item.name}`,
          });
        }
        
        this.toastStore.success(`Moved ${items.length} item(s)`);
        this.refreshAll();
      } catch (error) {
        this.toastStore.error(error.response?.data?.message || 'Failed to move');
      }
    },
    
    async deleteSelected() {
      if (!this.hasSelection) return;
      
      const items = this.currentSelection;
      this.showConfirmDelete(items, async () => {
        try {
          for (const item of items) {
            await api.post('/files/delete', {
              path: item.path,
              recursive: item.type === 'directory',
            });
          }
          
          this.toastStore.success(`Deleted ${items.length} item(s)`);
          this.refreshActivePanel();
        } catch (error) {
          this.toastStore.error(error.response?.data?.message || 'Failed to delete');
        }
      });
    },
    
    // Confirm modal methods
    showConfirmDelete(items, onConfirm) {
      const itemList = Array.isArray(items) ? items : [items];
      const count = itemList.length;
      const hasDirectories = itemList.some(i => i.type === 'directory');
      
      this.confirmModal = {
        visible: true,
        title: count === 1 ? 'Delete this item?' : `Delete ${count} items?`,
        message: hasDirectories 
          ? 'This will permanently delete the selected items and all their contents.' 
          : 'This action cannot be undone.',
        items: itemList,
        onConfirm: onConfirm,
      };
    },
    
    cancelConfirm() {
      this.confirmModal.visible = false;
      this.confirmModal.onConfirm = null;
    },
    
    async executeConfirm() {
      if (this.confirmModal.onConfirm) {
        await this.confirmModal.onConfirm();
      }
      this.confirmModal.visible = false;
      this.confirmModal.onConfirm = null;
    },
    
    // Preview
    async previewFile(item) {
      if (item.type === 'directory' || this.dualPanelMode) return;
      
      this.previewItem = item;
      this.previewLoading = true;
      this.hasChanges = false;
      this.viewerSearch = '';
      this.searchMatches = 0;
      this.currentSearchIndex = 0;
      
      try {
        const response = await api.get('/files/read', {
          params: { path: item.path },
        });
        
        this.previewData = response.data.data;
        if (!this.previewData.is_binary) {
          this.editorContent = this.previewData.content;
          this.originalContent = this.previewData.content;
          this.updateHighlightedContent();
        }
      } catch (error) {
        this.toastStore.error(error.response?.data?.message || 'Failed to read file');
        this.previewData = null;
      } finally {
        this.previewLoading = false;
      }
    },
    
    async saveFile() {
      if (!this.hasChanges || !this.previewItem) return;
      
      try {
        await api.post('/files/write', {
          path: this.previewItem.path,
          content: this.editorContent,
        });
        
        this.originalContent = this.editorContent;
        this.hasChanges = false;
        this.toastStore.success('File saved');
      } catch (error) {
        this.toastStore.error(error.response?.data?.message || 'Failed to save file');
      }
    },
    
    // Context menu
    showContextMenu(event, item, panel) {
      this.contextMenu = {
        visible: true,
        x: event.clientX,
        y: event.clientY,
        item: item,
        panel: panel,
      };
    },
    
    hideContextMenu() {
      this.contextMenu.visible = false;
    },
    
    openContextItem() {
      this.hideContextMenu();
      const item = this.contextMenu.item;
      if (item.type === 'directory') {
        this.loadPanel(this.contextMenu.panel, item.path);
      } else {
        this.previewFile(item);
      }
    },
    
    copyContextItem() {
      this.hideContextMenu();
      const item = this.contextMenu.item;
      const panel = this.contextMenu.panel;
      const destination = panel === 'left' ? this.rightPath : this.leftPath;
      
      api.post('/files/copy', {
        source: item.path,
        destination: `${destination}/${item.name}`,
      }).then(() => {
        this.toastStore.success('Copied');
        this.loadPanel(panel === 'left' ? 'right' : 'left');
      }).catch(error => {
        this.toastStore.error(error.response?.data?.message || 'Failed to copy');
      });
    },
    
    moveContextItem() {
      this.hideContextMenu();
      const item = this.contextMenu.item;
      const panel = this.contextMenu.panel;
      const destination = panel === 'left' ? this.rightPath : this.leftPath;
      
      api.post('/files/move', {
        source: item.path,
        destination: `${destination}/${item.name}`,
      }).then(() => {
        this.toastStore.success('Moved');
        this.refreshAll();
      }).catch(error => {
        this.toastStore.error(error.response?.data?.message || 'Failed to move');
      });
    },
    
    deleteContextItem() {
      this.hideContextMenu();
      const item = this.contextMenu.item;
      const panel = this.contextMenu.panel;
      
      this.showConfirmDelete(item, async () => {
        try {
          await api.post('/files/delete', {
            path: item.path,
            recursive: item.type === 'directory',
          });
          this.toastStore.success('Deleted');
          this.loadPanel(panel);
        } catch (error) {
          this.toastStore.error(error.response?.data?.message || 'Failed to delete');
        }
      });
    },
    
    // File operations
    async createFile() {
      if (!this.newFileName) return;
      
      try {
        await api.post('/files/write', {
          path: `${this.currentPath}/${this.newFileName}`,
          content: '',
        });
        
        this.showNewFileModal = false;
        this.newFileName = '';
        this.toastStore.success('File created');
        this.refreshActivePanel();
      } catch (error) {
        this.toastStore.error(error.response?.data?.message || 'Failed to create file');
      }
    },
    
    async createFolder() {
      if (!this.newFolderName) return;
      
      try {
        await api.post('/files/mkdir', {
          path: `${this.currentPath}/${this.newFolderName}`,
        });
        
        this.showNewFolderModal = false;
        this.newFolderName = '';
        this.toastStore.success('Folder created');
        this.refreshActivePanel();
      } catch (error) {
        this.toastStore.error(error.response?.data?.message || 'Failed to create folder');
      }
    },
    
    renameItem(item) {
      this.hideContextMenu();
      this.renameModal = {
        visible: true,
        item: item,
        newName: item.name,
        panel: this.contextMenu.panel || this.activePanel,
      };
    },
    
    async confirmRename() {
      if (!this.renameModal.newName || !this.renameModal.item) return;
      
      try {
        await api.post('/files/rename', {
          path: this.renameModal.item.path,
          new_name: this.renameModal.newName,
        });
        
        this.renameModal.visible = false;
        this.toastStore.success('Renamed');
        this.loadPanel(this.renameModal.panel);
      } catch (error) {
        this.toastStore.error(error.response?.data?.message || 'Failed to rename');
      }
    },
    
    async pasteItems() {
      this.hideContextMenu();
      if (!this.clipboard.items.length) return;
      
      try {
        for (const item of this.clipboard.items) {
          const destination = `${this.currentPath}/${item.name}`;
          
          if (this.clipboard.operation === 'copy') {
            await api.post('/files/copy', {
              source: item.path,
              destination: destination,
            });
          } else {
            await api.post('/files/move', {
              source: item.path,
              destination: destination,
            });
          }
        }
        
        if (this.clipboard.operation === 'cut') {
          this.clipboard = { items: [], operation: null };
        }
        
        this.toastStore.success('Pasted');
        this.refreshActivePanel();
      } catch (error) {
        this.toastStore.error(error.response?.data?.message || 'Failed to paste');
      }
    },
    
    showPermissionsModal(item) {
      this.hideContextMenu();
      this.permissionsModal = {
        visible: true,
        item: item,
        mode: item.permissions_octal,
        owner: item.owner,
        group: item.group,
        recursive: false,
      };
    },
    
    async savePermissions() {
      try {
        await api.post('/files/permissions', {
          path: this.permissionsModal.item.path,
          mode: this.permissionsModal.mode,
          owner: this.permissionsModal.owner,
          group: this.permissionsModal.group,
          recursive: this.permissionsModal.recursive,
        });
        
        this.permissionsModal.visible = false;
        this.toastStore.success('Permissions updated');
        this.refreshAll();
      } catch (error) {
        this.toastStore.error(error.response?.data?.message || 'Failed to update permissions');
      }
    },
    
    async showInfoModal(item) {
      this.hideContextMenu();
      
      try {
        const response = await api.get('/files/info', {
          params: { path: item.path },
        });
        
        this.infoModal = {
          visible: true,
          data: response.data.data,
        };
      } catch (error) {
        this.toastStore.error(error.response?.data?.message || 'Failed to get info');
      }
    },
    
    async downloadFile(item) {
      this.hideContextMenu();
      
      try {
        const response = await api.get('/files/read', {
          params: { path: item.path },
        });
        
        const data = response.data.data;
        let content = data.content;
        let blob;
        
        if (data.is_binary) {
          const binary = atob(content);
          const bytes = new Uint8Array(binary.length);
          for (let i = 0; i < binary.length; i++) {
            bytes[i] = binary.charCodeAt(i);
          }
          blob = new Blob([bytes], { type: data.mime_type });
        } else {
          blob = new Blob([content], { type: 'text/plain' });
        }
        
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = item.name;
        a.click();
        URL.revokeObjectURL(url);
      } catch (error) {
        this.toastStore.error(error.response?.data?.message || 'Failed to download');
      }
    },
    
    triggerUpload() {
      this.$refs.fileInput.click();
    },
    
    async handleUpload(event) {
      const files = event.target.files;
      if (!files.length) return;
      
      for (const file of files) {
        try {
          const reader = new FileReader();
          reader.onload = async (e) => {
            const base64 = e.target.result.split(',')[1];
            
            await api.post('/files/upload', {
              destination: this.currentPath,
              filename: file.name,
              content: base64,
              encoding: 'base64',
            });
            
            this.toastStore.success(`Uploaded: ${file.name}`);
            this.refreshActivePanel();
          };
          reader.readAsDataURL(file);
        } catch (error) {
          this.toastStore.error(`Failed to upload: ${file.name}`);
        }
      }
      
      event.target.value = '';
    },
    
    async compressItems(items) {
      this.hideContextMenu();
      
      const name = items.length === 1 ? items[0].name : 'archive';
      const destination = `${this.currentPath}/${name}.zip`;
      
      try {
        await api.post('/files/compress', {
          paths: items.map(i => i.path),
          destination: destination,
          format: 'zip',
        });
        
        this.toastStore.success('Compressed');
        this.refreshActivePanel();
      } catch (error) {
        this.toastStore.error(error.response?.data?.message || 'Failed to compress');
      }
    },
    
    async extractArchive(item) {
      this.hideContextMenu();
      
      try {
        await api.post('/files/extract', {
          path: item.path,
          destination: this.currentPath,
        });
        
        this.toastStore.success('Extracted');
        this.refreshActivePanel();
      } catch (error) {
        this.toastStore.error(error.response?.data?.message || 'Failed to extract');
      }
    },
    
    isArchive(item) {
      const ext = item.extension || '';
      return ['zip', 'tar', 'gz', 'tgz', 'bz2'].includes(ext);
    },
    
    getIcon(item) {
      if (item.type === 'directory') return 'folder';
      
      const ext = item.extension || '';
      const icons = {
        pdf: 'picture_as_pdf',
        doc: 'description',
        docx: 'description',
        txt: 'article',
        md: 'article',
        php: 'code',
        js: 'javascript',
        ts: 'code',
        html: 'html',
        css: 'css',
        json: 'data_object',
        xml: 'code',
        vue: 'code',
        py: 'code',
        jpg: 'image',
        jpeg: 'image',
        png: 'image',
        gif: 'image',
        svg: 'image',
        webp: 'image',
        zip: 'folder_zip',
        tar: 'folder_zip',
        gz: 'folder_zip',
        conf: 'settings',
        cfg: 'settings',
        ini: 'settings',
        env: 'settings',
        yml: 'settings',
        yaml: 'settings',
        mp3: 'audio_file',
        mp4: 'video_file',
        wav: 'audio_file',
        sql: 'database',
        db: 'database',
        log: 'description',
      };
      
      return icons[ext] || 'insert_drive_file';
    },
    
    // Syntax highlighting methods
    getLanguageClass(item) {
      if (!item) return 'plaintext';
      const ext = item.extension || '';
      const name = item.name || '';
      
      const langMap = {
        'js': 'javascript',
        'jsx': 'javascript',
        'ts': 'javascript',
        'tsx': 'javascript',
        'vue': 'xml',
        'php': 'php',
        'html': 'html',
        'htm': 'html',
        'xml': 'xml',
        'svg': 'xml',
        'css': 'css',
        'scss': 'css',
        'less': 'css',
        'json': 'json',
        'sh': 'bash',
        'bash': 'bash',
        'zsh': 'bash',
        'sql': 'sql',
        'yml': 'yaml',
        'yaml': 'yaml',
        'ini': 'ini',
        'conf': 'ini',
        'cfg': 'ini',
        'env': 'ini',
        'py': 'python',
        'md': 'plaintext',
        'txt': 'plaintext',
        'log': 'plaintext',
      };
      
      // Check for dotfiles
      if (name === '.htaccess' || name === '.env') return 'ini';
      if (name === 'Dockerfile') return 'bash';
      
      return langMap[ext] || 'plaintext';
    },

    // Get editor language for ConfigEditor component
    getEditorLanguage(item) {
      if (!item) return 'conf';
      const ext = (item.extension || '').toLowerCase();
      const name = (item.name || '').toLowerCase();
      
      // Map file extensions to CodeMirror languages
      const langMap = {
        // Web languages
        'html': 'html',
        'htm': 'html',
        'vue': 'html',
        'svelte': 'html',
        'css': 'css',
        'scss': 'css',
        'sass': 'css',
        'less': 'css',
        'js': 'javascript',
        'jsx': 'jsx',
        'ts': 'typescript',
        'tsx': 'tsx',
        'mjs': 'javascript',
        'cjs': 'javascript',
        // Data formats
        'json': 'json',
        'xml': 'xml',
        'svg': 'xml',
        'yaml': 'conf',
        'yml': 'conf',
        // Programming
        'php': 'php',
        'phtml': 'php',
        'sql': 'sql',
        'py': 'conf',
        'sh': 'conf',
        'bash': 'conf',
        // Documentation
        'md': 'markdown',
        'markdown': 'markdown',
        // Config files
        'ini': 'ini',
        'conf': 'conf',
        'cfg': 'conf',
        'cnf': 'ini',
        'env': 'ini',
        'htaccess': 'conf',
        'txt': 'conf',
        'log': 'conf',
      };
      
      // Check for specific config files by name
      if (name === '.htaccess') return 'conf';
      if (name === '.env' || name.startsWith('.env.')) return 'ini';
      if (name.includes('nginx') || name.endsWith('.conf')) return 'nginx';
      if (name === 'php.ini') return 'ini';
      if (name === 'my.cnf' || name === 'mysqld.cnf') return 'ini';
      if (name === 'dockerfile') return 'conf';
      if (name === 'makefile') return 'conf';
      if (name === 'package.json' || name === 'composer.json' || name === 'tsconfig.json') return 'json';
      
      return langMap[ext] || 'conf';
    },

    // Get service type for syntax checking
    getEditorService(item) {
      if (!item) return '';
      const ext = item.extension || '';
      const name = item.name || '';
      const path = this.getPreviewPath() || '';
      
      // PHP files
      if (ext === 'php') return 'php';
      
      // Check path for specific config files
      if (path.includes('/lsws/') || name.includes('httpd_config') || name.includes('vhost.conf')) return 'ols';
      if (path.includes('/mysql/') || name === 'my.cnf' || name === 'mysqld.cnf') return 'mysql';
      if (path.includes('/postfix/') || name === 'main.cf' || name === 'master.cf') return 'postfix';
      if (path.includes('/dovecot/') || name.includes('dovecot')) return 'dovecot';
      if (path.includes('/ssh/') || name === 'sshd_config') return 'ssh';
      if (path.includes('/powerdns/') || name === 'pdns.conf') return 'pdns';
      
      // No syntax check available for generic files
      return '';
    },

    // Get the current preview file path
    getPreviewPath() {
      if (!this.previewItem) return '';
      const panel = this.activePanel === 'left' ? this.leftPath : this.rightPath;
      return panel + '/' + this.previewItem.name;
    },
    
    getLanguageName(item) {
      if (!item) return 'Plain Text';
      const ext = item.extension || '';
      const name = item.name || '';
      
      const nameMap = {
        'js': 'JavaScript',
        'jsx': 'JavaScript (JSX)',
        'ts': 'TypeScript',
        'tsx': 'TypeScript (TSX)',
        'vue': 'Vue',
        'php': 'PHP',
        'html': 'HTML',
        'htm': 'HTML',
        'xml': 'XML',
        'svg': 'SVG',
        'css': 'CSS',
        'scss': 'SCSS',
        'less': 'LESS',
        'json': 'JSON',
        'sh': 'Shell',
        'bash': 'Bash',
        'zsh': 'Zsh',
        'sql': 'SQL',
        'yml': 'YAML',
        'yaml': 'YAML',
        'ini': 'INI',
        'conf': 'Config',
        'cfg': 'Config',
        'env': 'Environment',
        'py': 'Python',
        'md': 'Markdown',
        'txt': 'Plain Text',
        'log': 'Log',
      };
      
      if (name === '.htaccess') return 'Apache Config';
      if (name === '.env') return 'Environment';
      if (name === 'Dockerfile') return 'Dockerfile';
      
      return nameMap[ext] || 'Plain Text';
    },
    
    updateHighlightedContent() {
      if (!this.previewData || this.previewData.is_binary) {
        this.highlightedContent = '';
        this.editorHighlightedContent = '';
        return;
      }
      
      const content = this.editorContent || '';
      const lang = this.getLanguageClass(this.previewItem);
      
      try {
        if (lang !== 'plaintext' && hljs.getLanguage(lang)) {
          const result = hljs.highlight(content, { language: lang });
          this.highlightedContent = result.value;
          // For editor, add extra newline to ensure scrolling works correctly
          this.editorHighlightedContent = result.value + '\n';
        } else {
          // Escape HTML for plain text
          this.highlightedContent = this.escapeHtml(content);
          this.editorHighlightedContent = this.escapeHtml(content) + '\n';
        }
      } catch (e) {
        this.highlightedContent = this.escapeHtml(content);
        this.editorHighlightedContent = this.escapeHtml(content) + '\n';
      }
    },
    
    onEditorInput() {
      this.hasChanges = true;
      this.updateHighlightedContent();
    },
    
    syncEditorScroll() {
      const textarea = this.$refs.editor;
      const highlight = this.$refs.editorHighlight;
      if (textarea && highlight) {
        highlight.scrollTop = textarea.scrollTop;
        highlight.scrollLeft = textarea.scrollLeft;
      }
    },
    
    escapeHtml(text) {
      const div = document.createElement('div');
      div.textContent = text;
      return div.innerHTML;
    },
    
    highlightSearch() {
      this.currentSearchIndex = 0;
      this.updateHighlightedContent();
      
      if (!this.viewerSearch) {
        this.searchMatches = 0;
        return;
      }
      
      const searchTerm = this.viewerSearch.toLowerCase();
      const content = this.editorContent || '';
      
      // Count matches
      let count = 0;
      let pos = 0;
      while ((pos = content.toLowerCase().indexOf(searchTerm, pos)) !== -1) {
        count++;
        pos += searchTerm.length;
      }
      this.searchMatches = count;
      
      if (count > 0) {
        // Highlight search terms in the highlighted content
        const regex = new RegExp(`(${this.escapeRegex(this.viewerSearch)})`, 'gi');
        this.highlightedContent = this.highlightedContent.replace(regex, '<mark class="search-highlight">$1</mark>');
        
        // Scroll to first match
        this.$nextTick(() => {
          this.scrollToMatch(0);
        });
      }
    },
    
    escapeRegex(string) {
      return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    },
    
    findNext() {
      if (this.searchMatches === 0) return;
      this.currentSearchIndex = (this.currentSearchIndex + 1) % this.searchMatches;
      this.scrollToMatch(this.currentSearchIndex);
    },
    
    findPrev() {
      if (this.searchMatches === 0) return;
      this.currentSearchIndex = (this.currentSearchIndex - 1 + this.searchMatches) % this.searchMatches;
      this.scrollToMatch(this.currentSearchIndex);
    },
    
    scrollToMatch(index) {
      const viewer = this.$refs.codeViewer;
      if (!viewer) return;
      
      const marks = viewer.querySelectorAll('mark.search-highlight');
      if (marks.length === 0) return;
      
      // Remove current highlight from all
      marks.forEach(m => m.classList.remove('current'));
      
      // Add current to target
      if (marks[index]) {
        marks[index].classList.add('current');
        marks[index].scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
    },

    // Zen mode methods
    openZenMode() {
      if (!this.previewItem || !this.isEditable || this.previewData?.is_binary) return;
      
      this.zenContent = this.editorContent;
      this.zenOriginalContent = this.editorContent;
      this.zenHasChanges = false;
      this.zenMode = true;
      this.updateZenHighlight();
      
      // Focus editor after transition
      this.$nextTick(() => {
        setTimeout(() => {
          this.$refs.zenEditor?.focus();
        }, 100);
      });
      
      // Prevent body scroll
      document.body.style.overflow = 'hidden';
    },

    closeZenMode() {
      if (this.zenHasChanges) {
        if (!confirm('You have unsaved changes. Discard them?')) {
          return;
        }
      }
      this.zenMode = false;
      document.body.style.overflow = '';
    },

    onZenInput() {
      this.zenHasChanges = this.zenContent !== this.zenOriginalContent;
      this.updateZenHighlight();
      this.updateZenCursor();
    },

    updateZenHighlight() {
      const content = this.zenContent || '';
      const lang = this.getLanguageClass(this.previewItem);
      
      try {
        if (lang !== 'plaintext' && hljs.getLanguage(lang)) {
          const result = hljs.highlight(content, { language: lang });
          this.zenHighlightedContent = result.value + '\n';
        } else {
          this.zenHighlightedContent = this.escapeHtml(content) + '\n';
        }
      } catch (e) {
        this.zenHighlightedContent = this.escapeHtml(content) + '\n';
      }
    },

    syncZenScroll() {
      const textarea = this.$refs.zenEditor;
      const highlight = this.$refs.zenHighlight;
      const lineNumbers = this.$refs.zenLineNumbers;
      
      if (textarea && highlight) {
        highlight.scrollTop = textarea.scrollTop;
        highlight.scrollLeft = textarea.scrollLeft;
      }
      if (textarea && lineNumbers) {
        lineNumbers.scrollTop = textarea.scrollTop;
      }
      
      this.updateZenCursor();
    },

    updateZenCursor() {
      const textarea = this.$refs.zenEditor;
      if (!textarea) return;
      
      const text = textarea.value.substring(0, textarea.selectionStart);
      const lines = text.split('\n');
      this.zenCursorLine = lines.length;
      this.zenCursorCol = lines[lines.length - 1].length + 1;
    },

    async saveZenFile() {
      if (!this.zenHasChanges || !this.previewItem) return;
      
      try {
        await api.post('/files/write', {
          path: this.previewItem.path,
          content: this.zenContent,
        });
        
        // Sync back to preview editor
        this.editorContent = this.zenContent;
        this.originalContent = this.zenContent;
        this.hasChanges = false;
        this.updateHighlightedContent();
        
        // Update zen state
        this.zenOriginalContent = this.zenContent;
        this.zenHasChanges = false;
        
        this.toastStore.success('File saved');
      } catch (error) {
        this.toastStore.error(error.response?.data?.message || 'Failed to save file');
      }
    },
  },
};
</script>

<style scoped>
/* Minimal custom styles - most styling done with Tailwind */
.file-panel {
  flex: 1;
}

.file-panel:first-child {
  border-right: 1px solid rgb(var(--color-border));
}

/* Modal classes from main.css work globally */
.form-group {
  @apply mb-4;
}

.form-group:last-child {
  @apply mb-0;
}

.form-group label {
  @apply block text-sm font-medium text-surface-600 dark:text-surface-300 mb-1.5;
}

.form-group input[type="text"] {
  @apply input;
}

.checkbox-label {
  @apply flex items-center gap-2 cursor-pointer text-sm;
}

.checkbox-label input[type="checkbox"] {
  @apply w-4 h-4 cursor-pointer;
}

/* Properties grid for info modal */
.properties-grid {
  @apply flex flex-col gap-2;
}

.prop-row {
  @apply flex text-sm;
}

.prop-label {
  @apply w-24 flex-shrink-0 text-surface-500 dark:text-surface-400;
}

.prop-value {
  @apply flex-1 text-surface-800 dark:text-surface-200 break-all;
}

.prop-value.mono {
  @apply font-mono text-xs;
}

/* Resize handle styles */
.cursor-ew-resize:hover,
.cursor-ew-resize:active {
  @apply bg-primary-500/30;
}

/* Code viewer styles - matching ConfigEditor background */
.code-viewer {
  @apply bg-[#0f172a] text-[#e2e8f0];
}

.code-viewer pre {
  @apply bg-transparent;
}

.code-viewer code {
  @apply font-mono text-xs leading-relaxed whitespace-pre-wrap break-words;
}

/* File ConfigEditor wrapper */
.file-config-editor {
  @apply bg-[#0f172a];
}

.file-config-editor :deep(.config-editor-wrapper) {
  height: 100%;
}

.file-config-editor :deep(.config-editor) {
  height: 100%;
  display: flex;
  flex-direction: column;
}

.file-config-editor :deep(.editor-container) {
  flex: 1;
  min-height: 0;
}

.file-config-editor :deep(.cm-editor) {
  height: 100%;
}

/* Search highlight styles */
.code-viewer :deep(mark.search-highlight) {
  @apply bg-amber-500/40 text-inherit rounded-sm px-0.5;
}

.code-viewer :deep(mark.search-highlight.current) {
  @apply bg-amber-500 text-black;
}

/* Override highlight.js theme to match CodeMirror */
.code-viewer :deep(.hljs) {
  @apply bg-transparent;
  color: #e2e8f0;
}

/* HTML/XML Tags */
.code-viewer :deep(.hljs-tag) {
  color: #6b7280;
}
.code-viewer :deep(.hljs-name),
.code-viewer :deep(.hljs-selector-tag) {
  color: #22d3ee;
}
.code-viewer :deep(.hljs-attr) {
  color: #a78bfa;
}

/* Strings and values */
.code-viewer :deep(.hljs-string),
.code-viewer :deep(.hljs-attribute) {
  color: #34d399;
}

/* Keywords */
.code-viewer :deep(.hljs-keyword),
.code-viewer :deep(.hljs-selector-class),
.code-viewer :deep(.hljs-selector-id) {
  color: #c084fc;
}

/* Numbers */
.code-viewer :deep(.hljs-number) {
  color: #f97316;
}

/* Comments */
.code-viewer :deep(.hljs-comment) {
  color: #6b7280;
  font-style: italic;
}

/* Functions and built-ins */
.code-viewer :deep(.hljs-function),
.code-viewer :deep(.hljs-title),
.code-viewer :deep(.hljs-title.function_) {
  color: #fbbf24;
}
.code-viewer :deep(.hljs-built_in) {
  color: #22d3ee;
}

/* Variables and properties */
.code-viewer :deep(.hljs-variable),
.code-viewer :deep(.hljs-property) {
  color: #60a5fa;
}

/* Types and classes */
.code-viewer :deep(.hljs-type),
.code-viewer :deep(.hljs-class),
.code-viewer :deep(.hljs-title.class_) {
  color: #22d3ee;
}

/* Booleans and special values */
.code-viewer :deep(.hljs-literal),
.code-viewer :deep(.hljs-boolean) {
  color: #fbbf24;
}

/* Meta and preprocessor */
.code-viewer :deep(.hljs-meta),
.code-viewer :deep(.hljs-doctag) {
  color: #f472b6;
}

/* Punctuation and symbols */
.code-viewer :deep(.hljs-punctuation),
.code-viewer :deep(.hljs-symbol) {
  color: #94a3b8;
}

/* Links */
.code-viewer :deep(.hljs-link) {
  color: #60a5fa;
  text-decoration: underline;
}

/* Regexp */
.code-viewer :deep(.hljs-regexp) {
  color: #fb923c;
}

/* CSS specific */
.code-viewer :deep(.hljs-selector-pseudo) {
  color: #c084fc;
}

/* Code/inline */
.code-viewer :deep(.hljs-code) {
  color: #34d399;
}

/* Zen Mode Styles - matching ConfigEditor */
.zen-overlay {
  position: fixed;
  inset: 0;
  z-index: 9999;
  display: flex;
  flex-direction: column;
  background: #0f172a;
}

.zen-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 12px 24px;
  background: #0f172a;
  border-bottom: 1px solid #1e293b;
}

.zen-btn {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 6px 12px;
  font-size: 13px;
  font-weight: 500;
  color: #e2e8f0;
  background: transparent;
  border: 1px solid #334155;
  border-radius: 6px;
  cursor: pointer;
  transition: all 0.15s;
}

.zen-btn:hover:not(:disabled) {
  background: rgba(148, 163, 184, 0.1);
  border-color: #475569;
}

.zen-editor-container {
  flex: 1;
  overflow: hidden;
  padding: 0;
}

.zen-editor-wrapper {
  position: relative;
  height: 100%;
  display: flex;
  background: #0f172a;
}

.zen-line-numbers {
  position: sticky;
  left: 0;
  width: 60px;
  padding: 16px 12px 16px 0;
  text-align: right;
  font-family: 'JetBrains Mono', 'Fira Code', 'Consolas', monospace;
  font-size: 13px;
  line-height: 1.6;
  color: #475569;
  background: #0f172a;
  border-right: 1px solid #1e293b;
  overflow: hidden;
  user-select: none;
}

.zen-line-number {
  height: 1.6em;
}

.zen-highlight {
  position: absolute;
  top: 0;
  left: 60px;
  right: 0;
  bottom: 0;
  margin: 0;
  padding: 16px 16px 16px 16px;
  font-family: 'JetBrains Mono', 'Fira Code', 'Consolas', monospace;
  font-size: 13px;
  line-height: 1.6;
  color: #e2e8f0;
  background: transparent;
  overflow: auto;
  pointer-events: none;
  white-space: pre-wrap;
  word-wrap: break-word;
}

.zen-highlight code {
  font-family: inherit;
  font-size: inherit;
  line-height: inherit;
}

/* Override highlight.js theme in Zen mode */
.zen-highlight :deep(.hljs) {
  background: transparent !important;
  color: #e2e8f0;
}

/* Zen mode highlight.js overrides - match CodeMirror */
.zen-highlight :deep(.hljs-tag) { color: #6b7280; }
.zen-highlight :deep(.hljs-name),
.zen-highlight :deep(.hljs-selector-tag) { color: #22d3ee; }
.zen-highlight :deep(.hljs-attr) { color: #a78bfa; }
.zen-highlight :deep(.hljs-string),
.zen-highlight :deep(.hljs-attribute) { color: #34d399; }
.zen-highlight :deep(.hljs-keyword),
.zen-highlight :deep(.hljs-selector-class),
.zen-highlight :deep(.hljs-selector-id) { color: #c084fc; }
.zen-highlight :deep(.hljs-number) { color: #f97316; }
.zen-highlight :deep(.hljs-comment) { color: #6b7280; font-style: italic; }
.zen-highlight :deep(.hljs-function),
.zen-highlight :deep(.hljs-title),
.zen-highlight :deep(.hljs-title.function_) { color: #fbbf24; }
.zen-highlight :deep(.hljs-built_in) { color: #22d3ee; }
.zen-highlight :deep(.hljs-variable),
.zen-highlight :deep(.hljs-property) { color: #60a5fa; }
.zen-highlight :deep(.hljs-type),
.zen-highlight :deep(.hljs-class),
.zen-highlight :deep(.hljs-title.class_) { color: #22d3ee; }
.zen-highlight :deep(.hljs-literal),
.zen-highlight :deep(.hljs-boolean) { color: #fbbf24; }
.zen-highlight :deep(.hljs-meta),
.zen-highlight :deep(.hljs-doctag) { color: #f472b6; }
.zen-highlight :deep(.hljs-punctuation),
.zen-highlight :deep(.hljs-symbol) { color: #94a3b8; }
.zen-highlight :deep(.hljs-link) { color: #60a5fa; text-decoration: underline; }
.zen-highlight :deep(.hljs-regexp) { color: #fb923c; }
.zen-highlight :deep(.hljs-selector-pseudo) { color: #c084fc; }
.zen-highlight :deep(.hljs-code) { color: #34d399; }

.zen-textarea {
  position: absolute;
  top: 0;
  left: 60px;
  right: 0;
  bottom: 0;
  width: calc(100% - 60px);
  height: 100%;
  margin: 0;
  padding: 16px 16px 16px 16px;
  font-family: 'JetBrains Mono', 'Fira Code', 'Consolas', monospace;
  font-size: 13px;
  line-height: 1.6;
  color: transparent;
  background: transparent;
  border: none;
  outline: none;
  resize: none;
  overflow: auto;
  caret-color: #60a5fa;
  -webkit-text-fill-color: transparent;
  white-space: pre-wrap;
  word-wrap: break-word;
}

.zen-textarea::selection {
  background: rgba(59, 130, 246, 0.3);
}

.zen-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 8px 24px;
  background: #0f172a;
  border-top: 1px solid #1e293b;
}

.zen-kbd {
  display: inline-block;
  padding: 2px 6px;
  font-family: inherit;
  font-size: 11px;
  color: #94a3b8;
  background: #1e293b;
  border: 1px solid #334155;
  border-radius: 4px;
}

/* Zen fade transition */
.zen-fade-enter-active,
.zen-fade-leave-active {
  transition: opacity 0.2s ease;
}

.zen-fade-enter-from,
.zen-fade-leave-to {
  opacity: 0;
}
</style>
