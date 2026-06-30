<template>
  <div class="h-[100dvh] flex flex-col bg-surface-50 dark:bg-surface-900" :class="isMobile ? 'overflow-y-auto pb-20' : 'overflow-hidden'">
    <!-- Header (hidden during presentation) -->
    <AppHeader
      v-if="!store.presentationMode"
      current-view="mood"
      :icon="currentBoard ? 'arrow_back' : 'dashboard_customize'"
      :title="currentBoard ? currentBoard.name : 'Mood Boards'"
      @icon-click="onHeaderIconClick"
    >
      <template #title-badge>
        <HowItWorksButton @click="showStepGuide = true" />
        <HowItWorksButton variant="features" :active="showFeatureGuide" @click="showFeatureGuide = !showFeatureGuide" />
      </template>
    </AppHeader>
    
    <!-- Mobile: full-width board list (tap to open in play mode) -->
    <div v-if="isMobile && !store.presentationMode" class="flex-1 overflow-y-auto">
      <!-- Show board list when no board is selected -->
      <template v-if="!currentBoard || !route.params.id">
        <MoodBoardList
          :selected-id="currentBoard?.id"
          @select="openBoard"
        />
      </template>

      <!-- Board selected but no slides / can't present: show fallback with back button -->
      <template v-else>
        <div class="flex-1 flex flex-col items-center justify-center h-full text-surface-500 gap-4 px-6 py-12">
          <span class="material-symbols-rounded text-6xl text-surface-300 dark:text-surface-600">slideshow</span>
          <div class="text-center">
            <p class="text-lg font-medium text-surface-700 dark:text-surface-300">{{ currentBoard.name }}</p>
            <p class="text-sm text-surface-500 mt-1">This board has no slides to present yet. Add slides on desktop to view here.</p>
          </div>
          <button
            @click="router.push('/mood')"
            class="mt-4 px-6 py-2.5 bg-primary-500 hover:bg-primary-600 text-white rounded-full text-sm font-medium transition-colors flex items-center gap-2"
          >
            <span class="material-symbols-rounded text-lg">arrow_back</span>
            Back to Boards
          </button>
        </div>
      </template>
    </div>

    <!-- Desktop layout (also rendered on mobile during presentation so canvasRef is available) -->
    <div v-if="!isMobile || store.presentationMode" class="flex-1 flex overflow-hidden">
      <!-- Sidebar (board list) — hidden during presentation -->
      <transition name="slide">
        <div
          v-if="showSidebar && !store.presentationMode"
          class="w-72 flex-shrink-0 border-r border-surface-200 dark:border-surface-700"
        >
          <MoodBoardList
            :selected-id="currentBoard?.id"
            @select="openBoard"
          />
        </div>
      </transition>
      
      <!-- Main content area (left sidebar + canvas + right sidebar) -->
      <div class="flex-1 flex overflow-hidden">
        <!-- No board selected -->
        <div v-if="!currentBoard && !store.boardLoading" class="flex-1 flex flex-col items-center justify-center h-full text-surface-500 gap-4">
          <span class="material-symbols-rounded text-7xl text-surface-300 dark:text-surface-600">dashboard_customize</span>
          <div class="text-center">
            <p class="text-lg font-medium text-surface-700 dark:text-surface-300">Select a mood board</p>
            <p class="text-sm text-surface-500 mt-1">Or create a new one to start collecting your ideas</p>
          </div>
        </div>
        
        <!-- Loading board data -->
        <div v-else-if="store.boardLoading" class="flex-1 flex items-center justify-center h-full">
          <span class="material-symbols-rounded text-3xl text-surface-400 animate-spin">sync</span>
        </div>

        <!-- Preloading assets -->
        <div v-else-if="currentBoard && boardPreloading" class="flex-1 flex items-center justify-center h-full">
          <div class="flex flex-col items-center gap-5 text-center px-6 max-w-sm">
            <div class="w-14 h-14 rounded-2xl flex items-center justify-center shadow-lg"
              :style="{ backgroundColor: currentBoard.background_color || '#e5e7eb' }">
              <span class="material-symbols-rounded text-3xl text-white drop-shadow">dashboard_customize</span>
            </div>
            <p class="text-sm font-medium text-surface-600 dark:text-surface-300">Loading assets...</p>
            <div class="w-64">
              <div class="h-1.5 rounded-full bg-surface-200 dark:bg-surface-700 overflow-hidden">
                <div
                  class="h-full rounded-full bg-primary-500 transition-all duration-300 ease-out"
                  :style="{ width: boardPreloadProgress + '%' }"
                />
              </div>
              <p class="text-[11px] text-surface-400 mt-2 tabular-nums">
                {{ boardPreloadLoaded }} / {{ boardPreloadTotal }} images
              </p>
            </div>
          </div>
        </div>

        <!-- Canvas with sidebars -->
        <template v-else-if="currentBoard && !boardPreloading">
          <!-- Left Sidebar (Layers, Components, Palette) — hidden during presentation -->
          <MoodLeftSidebar
            ref="leftSidebarRef"
            v-if="!store.presentationMode"
            v-model:collapsed="leftSidebarCollapsed"
            :board-name="currentBoard.name"
            :board-color="currentBoard.background_color || '#f5f5f5'"
            :is-ready="!!currentBoard.is_ready"
            :comment-count="comments.openThreads.value.length"
            :is-comment-active="isCommentMode || comments.showCommentsPanel.value"
            @fly-to-item="onFlyToItem"
            @place-component="onPlaceComponent"
            @edit-component-items="onEditComponentItems"
            @pick-color="onPalettePickColor"
            @toggle-ready="handleToggleReady"
            @open-comments="toggleCommentsPanel()"
            @open-share="showBoardSettings = true; settingsInitialTab = 'sharing'"
            @open-settings="showBoardSettings = !showBoardSettings; settingsInitialTab = 'general'"
          />
          <!-- Canvas area — goes fullscreen during presentation -->
          <div
            class="overflow-hidden"
            :class="store.presentationMode ? 'fixed inset-0 z-[9998]' : 'relative flex-1 isolate'"
            :style="store.presentationMode ? {
              backgroundColor: currentBoard?.background_color || '#1e1e2e',
              ...(currentBoard?.background_image ? {
                backgroundImage: `url(${currentBoard.background_image})`,
                backgroundSize: currentBoard.background_image_size === 'repeat' ? 'auto' : (currentBoard.background_image_size || 'cover'),
                backgroundRepeat: currentBoard.background_image_size === 'repeat' ? 'repeat' : 'no-repeat',
                backgroundPosition: 'center',
              } : {})
            } : {}"
          >
          <!-- Renderer toggle -->
          <div v-if="!store.presentationMode" class="absolute top-2 right-2 z-[10001] flex items-center gap-2 bg-white/90 dark:bg-surface-800/90 backdrop-blur-sm rounded-xl px-3 py-1.5 shadow-sm border border-surface-200 dark:border-surface-700">
            <span class="text-[10px] font-medium text-surface-500 uppercase tracking-wider">{{ usePixiRenderer ? 'WebGL' : 'DOM' }}</span>
            <button
              class="w-11 h-6 rounded-full transition-colors relative shrink-0"
              :class="usePixiRenderer ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'"
              @click="toggleRenderer"
            >
              <span
                :class="['absolute top-1 left-1 w-4 h-4 rounded-full bg-white shadow transition-transform duration-200', usePixiRenderer ? 'translate-x-5' : 'translate-x-0']"
              />
            </button>
          </div>
          
          <!-- Canvas: PixiJS WebGL or legacy DOM renderer -->
          <component
            :is="usePixiRenderer ? PixiCanvas : MoodCanvas"
            ref="canvasRef"
            :board="currentBoard"
            @renderer-fallback="onRendererFallback"
            :before-upload="ensureClientOrSkip"
            :comment-counts="comments.itemCounts.value"
            :comment-threads="comments.threads.value"
            :active-comment-thread-id="comments.activeThreadId.value"
            :show-comment-pins="showCommentPins && !store.presentationMode"
            v-model:drawMode="isDrawMode"
            v-model:penMode="isPenMode"
            v-model:lineMode="isLineMode"
            v-model:measureMode="isMeasureMode"
            v-model:measureColor="measureColor"
            v-model:measureWidth="measureWidth"
            v-model:measureVisible="measureVisible"
            v-model:measureCount="measureCount"
            v-model:snapGrid="isSnapToGrid"
            v-model:snapCenter="isSnapToCenter"
            v-model:commentMode="isCommentMode"
            :class="store.presentationMode ? '' : 'pt-[48px]'"
            @item-context="onItemContext"
            @select-connection="onSelectConnection"
            @connection-context="onConnectionContext"
            @open-color-picker="openColorPickerForItem"
            @pick-color="pickColorForItem"
            @edit-drawing="openDrawingEditor"
            @preview-file="openFilePreview"
            @edit-file-collab="openFileInCollab"
            @browse-folder="onBrowseFolder"
            @comment-item="onCommentItem"
            @comment-canvas="onCommentCanvas"
            @select-comment-thread="onSelectCommentThread"
            @delete-comment-thread="onDeleteCommentThread"
          />
          
          <!-- Bottom toolbar — hidden during presentation -->
          <div v-if="!store.presentationMode" class="absolute bottom-4 left-1/2 -translate-x-1/2 z-[10000] overflow-visible">
            <MoodToolbar
              :zoom="store.zoom"
              :draw-mode="isDrawMode"
              :pen-mode="isPenMode"
              :line-mode="isLineMode"
              :measure-mode="isMeasureMode"
              :measure-visible="measureVisible"
              :measure-count="measureCount"
              :measure-line-color="measureColor"
              :measure-line-width="measureWidth"
              :snap-to-grid="isSnapToGrid"
              :snap-to-center="isSnapToCenter"
              :show-rulers="store.showRulers"
              :has-slides="store.presentationSlides.length > 0"
              :filmstrip-open="store.showFilmstrip"
              :motion-enabled="store.motionEnabled"
              :motion-cards="store.motionCards"
              :motion-elements="store.motionElements"
              :motion-lines="store.motionLines"
              :motion-intensity="store.motionIntensity"
              :motion-card-intensity="store.motionCardIntensity"
              :motion-speed="store.motionSpeed"
              :motion-line-wave="store.motionLineWave"
              :motion-line-speed="store.motionLineSpeed"
              :motion-line-density="store.motionLineDensity"
              :motion-draw-on="store.motionDrawOn"
              :motion-draw-on-trigger="store.motionDrawOnTrigger"
              :motion-draw-on-speed="store.motionDrawOnSpeed"
              :slides-visible="store.slidesVisible"
              :undo-count="store.undoStack.length"
              :redo-count="store.redoStack.length"
              :offline-cache-status="offlineCacheStatus"
              :offline-cached-count="offlineCachedCount"
              :offline-saving="offlineSaving"
              @add-item="onAddItem"
              @toggle-draw="canvasRef?.toggleDrawMode()"
              @toggle-pen="canvasRef?.togglePenMode()"
              @toggle-line="canvasRef?.toggleLineMode()"
              @toggle-measure="canvasRef?.toggleMeasureMode()"
              @toggle-measure-visibility="measureVisible = !measureVisible"
              @clear-measurements="canvasRef?.clearMeasurements()"
              @set-measure-color="(c) => measureColor = c"
              @set-measure-width="(w) => measureWidth = w"
              @toggle-snap-grid="isSnapToGrid = !isSnapToGrid"
              @toggle-snap-center="isSnapToCenter = !isSnapToCenter"
              @toggle-rulers="store.showRulers = !store.showRulers"
              @zoom-in="canvasRef?.zoomIn()"
              @zoom-out="canvasRef?.zoomOut()"
              @zoom-reset="canvasRef?.zoomReset()"
              @fit-screen="canvasRef?.fitScreen()"
              @open-drive-picker="openDrivePickerWithClientCheck"
              @open-calendar-picker="showCalendarPicker = true"
              @open-board-picker="showBoardPicker = true"
              @trigger-file-upload="triggerFileUpload"
              @toggle-filmstrip="store.showFilmstrip = !store.showFilmstrip"
              @start-presentation="onStartPresentation"
              @start-scroll-story="startScrollStory"
              @export-presentation="onExportPresentation"
              @export-pptx="onExportPptx"
              @export-pdf="onExportPdf"
              @toggle-motion="store.motionEnabled = !store.motionEnabled"
              @toggle-motion-cards="store.motionCards = !store.motionCards"
              @toggle-motion-elements="store.motionElements = !store.motionElements"
              @toggle-motion-lines="store.motionLines = !store.motionLines"
              @set-motion-intensity="v => store.motionIntensity = v"
              @set-motion-card-intensity="v => store.motionCardIntensity = v"
              @set-motion-speed="v => store.motionSpeed = v"
              @set-motion-line-wave="v => store.motionLineWave = v"
              @set-motion-line-speed="v => store.motionLineSpeed = v"
              @set-motion-line-density="v => store.motionLineDensity = v"
              @toggle-motion-draw-on="store.motionDrawOn = !store.motionDrawOn"
              @set-motion-draw-on-trigger="v => store.motionDrawOnTrigger = v"
              @set-motion-draw-on-speed="v => store.motionDrawOnSpeed = v"
              @toggle-slides-visible="store.slidesVisible = !store.slidesVisible"
              @undo="store.undo()"
              @redo="store.redo()"
              @save-offline="onSaveOffline"
              @clear-offline-cache="onClearOfflineCache"
            />
          </div>

          <!-- AI Panel toggle button -->
          <button
            v-if="!store.presentationMode"
            @click="showAIPanel = !showAIPanel"
            class="absolute bottom-5 right-4 z-[10000] w-10 h-10 flex items-center justify-center rounded-xl shadow-lg transition-all duration-150"
            :class="showAIPanel
              ? 'bg-primary-500 text-white ring-2 ring-primary-300'
              : 'bg-surface-100 dark:bg-surface-800 text-surface-500 dark:text-surface-400 hover:bg-surface-200 dark:hover:bg-surface-700 border border-surface-200 dark:border-surface-600'"
            title="AI Layout Generator"
          >
            <span class="material-symbols-rounded text-xl">auto_awesome</span>
          </button>

          <!-- AI Panel -->
          <MoodAIPanel
            :open="showAIPanel && !store.presentationMode"
            @close="showAIPanel = false"
          />
          
          <!-- Filmstrip (slide ordering panel) — hidden during presentation -->
          <MoodFilmstrip
            v-if="!store.presentationMode"
            @fly-to-slide="onFlyToSlide"
            @start-presentation="onStartPresentation"
            @insert-slide-at="onInsertSlideAt"
          />
          
          <!-- Follow user indicator — hidden during presentation -->
          <transition name="follow-indicator">
            <div
              v-if="!store.presentationMode && store.followingUser && followedCollab"
              class="absolute top-14 left-1/2 -translate-x-1/2 z-40 flex items-center gap-2 px-4 py-2 rounded-full shadow-lg border-2"
              :style="{
                backgroundColor: (followedCollab.color || '#6366f1') + '15',
                borderColor: followedCollab.color || '#6366f1'
              }"
            >
              <div
                class="w-5 h-5 rounded-full flex items-center justify-center text-white text-[9px] font-bold"
                :style="{ backgroundColor: followedCollab.color || '#6366f1' }"
              >
                {{ (followedCollab.name || followedCollab.email).charAt(0).toUpperCase() }}
              </div>
              <span class="text-xs font-medium" :style="{ color: followedCollab.color || '#6366f1' }">
                Following {{ followedCollab.name || followedCollab.email }}
              </span>
              <!-- Mode toggle: cursor vs viewport lock -->
              <button
                @click="toggleFollowMode"
                class="flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold transition-colors"
                :style="{
                  backgroundColor: (followedCollab.color || '#6366f1') + '25',
                  color: followedCollab.color || '#6366f1'
                }"
                :title="store.followMode === 'cursor' ? 'Switch to viewport lock (see exactly what they see)' : 'Switch to cursor follow'"
              >
                <span class="material-symbols-rounded" style="font-size: 14px;">{{ store.followMode === 'viewport' ? 'visibility' : 'near_me' }}</span>
                {{ store.followMode === 'viewport' ? 'Their View' : 'Cursor' }}
              </button>
              <button
                @click="store.stopFollowing()"
                class="p-0.5 rounded-full hover:bg-surface-200 dark:hover:bg-surface-600 transition-colors"
                :style="{ color: followedCollab.color || '#6366f1' }"
              >
                <span class="material-symbols-rounded text-sm">close</span>
              </button>
            </div>
          </transition>

          <!-- Focus mode indicator — hidden during presentation -->
          <transition name="follow-indicator">
            <div
              v-if="!store.presentationMode && store.focusedItemId"
              class="absolute top-14 right-4 z-40 flex items-center gap-2 px-3 py-1.5 rounded-full shadow-lg bg-amber-50 dark:bg-amber-900/30 border border-amber-300 dark:border-amber-700"
            >
              <span class="material-symbols-rounded text-sm text-amber-600 dark:text-amber-400">center_focus_strong</span>
              <span class="text-xs font-medium text-amber-700 dark:text-amber-300">Focus Mode</span>
              <button
                @click="store.clearFocusItem()"
                class="p-0.5 rounded-full hover:bg-amber-100 dark:hover:bg-amber-800/50 text-amber-500 transition-colors"
                title="Exit focus (Esc)"
              >
                <span class="material-symbols-rounded text-sm">close</span>
              </button>
            </div>
          </transition>
          
          <!-- Hidden file input for uploads -->
          <input
            ref="fileInputRef"
            type="file"
            multiple
            class="hidden"
            @change="onFileInputChange"
          />
          </div><!-- /canvas area -->

          <!-- Comments panel (between canvas and right sidebar) -->
          <MoodCommentsPanel
            v-if="comments.showCommentsPanel.value && !store.presentationMode"
            :threads="comments.threads.value"
            :loading="comments.loading.value"
            :is-public="false"
            :current-user-email="store.isPublicView ? '' : (authStore?.userEmail || '')"
            :is-board-owner="!!(currentBoard?.owner_email && authStore?.userEmail && currentBoard.owner_email.toLowerCase() === authStore.userEmail.toLowerCase())"
            :selected-thread-id="comments.activeThreadId.value"
            @close="comments.showCommentsPanel.value = false"
            @add-comment="onAddComment"
            @delete-comment="onDeleteComment"
            @delete-thread="onDeleteCommentThread"
            @resolve-thread="onResolveThread"
            @unresolve-thread="onUnresolveThread"
            @select-thread="onSelectThread"
            @focus-thread="onFocusThread"
          />

          <!-- Right Sidebar (context-aware properties) — hidden during presentation -->
          <MoodRightSidebar
            v-if="!store.presentationMode"
            :board="currentBoard"
            @update-board-field="updateBoardField"
            @preview-file="openFilePreview"
            @edit-file-collab="openFileInCollab"
            @connect-item="toggleConnect"
            @toggle-youtube-interactive="toggleYoutubeInteractive"
          />
        </template>
      </div>
    </div>
    
    <!-- Feature Guide -->
    <div v-if="showFeatureGuide" class="px-4 py-2">
      <FeatureGuide v-model="showFeatureGuide" :tiers="guideData.tiers" :integrations="guideData.integrations" :title-key="guideData.titleKey" :footer-key="guideData.footerKey" :layer-key="guideData.layerKey" :layer-icon="guideData.layerIcon" />
    </div>
    
    <!-- Board Settings Panel (slide-in from right) -->
    <transition name="slide-right">
      <MoodBoardSettings
        v-if="showBoardSettings && currentBoard"
        :board="currentBoard"
        :initial-tab="settingsInitialTab"
        @close="showBoardSettings = false; settingsInitialTab = 'general'"
        @update-field="updateBoardField"
      />
    </transition>
    
    <!-- Item context menu -->
    <div
      v-if="itemContextMenu.show"
      ref="itemContextEl"
      class="fixed z-50 bg-white dark:bg-surface-800 rounded-lg shadow-lg border border-surface-200 dark:border-surface-700 py-0.5 min-w-[180px] overflow-visible"
      :style="{ left: itemContextMenu.x + 'px', top: itemContextMenu.y + 'px' }"
      @click.stop
    >
      <button @click="handleFocusItem" class="w-full px-2.5 py-1.5 text-xs text-left hover:bg-surface-50 dark:hover:bg-surface-700 flex items-center gap-2 text-surface-700 dark:text-surface-300">
        <span class="material-symbols-rounded text-[16px]">{{ store.focusedItemId === itemContextMenu.item?.id ? 'visibility_off' : 'center_focus_strong' }}</span>
        {{ store.focusedItemId === itemContextMenu.item?.id ? 'Exit Focus' : 'Focus' }}
      </button>
      <div class="my-0.5 border-t border-surface-200 dark:border-surface-700"></div>
      <button @click="handleBringToFront" class="w-full px-2.5 py-1.5 text-xs text-left hover:bg-surface-50 dark:hover:bg-surface-700 flex items-center gap-2 text-surface-700 dark:text-surface-300">
        <span class="material-symbols-rounded text-[16px]">flip_to_front</span>
        <span class="flex-1">Bring to Front</span>
        <span class="text-[10px] text-surface-400 font-mono">Ctrl+Shift+]</span>
      </button>
      <button @click="handleMoveForward" class="w-full px-2.5 py-1.5 text-xs text-left hover:bg-surface-50 dark:hover:bg-surface-700 flex items-center gap-2 text-surface-700 dark:text-surface-300">
        <span class="material-symbols-rounded text-[16px]">stat_1</span>
        <span class="flex-1">Move Forward</span>
        <span class="text-[10px] text-surface-400 font-mono">Ctrl+]</span>
      </button>
      <button @click="handleMoveBackward" class="w-full px-2.5 py-1.5 text-xs text-left hover:bg-surface-50 dark:hover:bg-surface-700 flex items-center gap-2 text-surface-700 dark:text-surface-300">
        <span class="material-symbols-rounded text-[16px]">stat_minus_1</span>
        <span class="flex-1">Move Backward</span>
        <span class="text-[10px] text-surface-400 font-mono">Ctrl+[</span>
      </button>
      <button @click="handleSendToBack" class="w-full px-2.5 py-1.5 text-xs text-left hover:bg-surface-50 dark:hover:bg-surface-700 flex items-center gap-2 text-surface-700 dark:text-surface-300">
        <span class="material-symbols-rounded text-[16px]">flip_to_back</span>
        <span class="flex-1">Send to Back</span>
        <span class="text-[10px] text-surface-400 font-mono">Ctrl+Shift+[</span>
      </button>
      <button @click="handleToggleLock" class="w-full px-2.5 py-1.5 text-xs text-left hover:bg-surface-50 dark:hover:bg-surface-700 flex items-center gap-2 text-surface-700 dark:text-surface-300">
        <span class="material-symbols-rounded text-[16px]">{{ itemContextMenu.item?.locked ? 'lock_open' : 'lock' }}</span>
        {{ itemContextMenu.item?.locked ? 'Unlock' : 'Lock' }}
      </button>
      <!-- Rotate options -->
      <div class="my-0.5 border-t border-surface-200 dark:border-surface-700"></div>
      <div class="px-1.5 py-0.5 flex items-center gap-0.5">
        <button @click="handleRotate(-90)" class="flex-1 px-1.5 py-1 text-xs rounded hover:bg-surface-50 dark:hover:bg-surface-700 flex items-center justify-center text-surface-700 dark:text-surface-300" title="Rotate 90 left">
          <span class="material-symbols-rounded text-[15px]">rotate_left</span>
        </button>
        <button @click="handleRotate(90)" class="flex-1 px-1.5 py-1 text-xs rounded hover:bg-surface-50 dark:hover:bg-surface-700 flex items-center justify-center text-surface-700 dark:text-surface-300" title="Rotate 90 right">
          <span class="material-symbols-rounded text-[15px]">rotate_right</span>
        </button>
        <button @click="handleRotate(180)" class="flex-1 px-1.5 py-1 text-xs rounded hover:bg-surface-50 dark:hover:bg-surface-700 flex items-center justify-center text-surface-700 dark:text-surface-300" title="Rotate 180">
          <span class="material-symbols-rounded text-[15px]">sync</span>
        </button>
        <button
          v-if="itemContextMenu.item?.rotation"
          @click="handleResetRotation"
          class="flex-1 px-1.5 py-1 text-xs rounded hover:bg-surface-50 dark:hover:bg-surface-700 flex items-center justify-center text-primary-600 dark:text-primary-400"
          title="Reset rotation"
        >
          <span class="material-symbols-rounded text-[15px]">restart_alt</span>
        </button>
      </div>
      <div class="my-0.5 border-t border-surface-200 dark:border-surface-700"></div>
      <button @click="handleCopyItem" class="w-full px-2.5 py-1.5 text-xs text-left hover:bg-surface-50 dark:hover:bg-surface-700 flex items-center gap-2 text-surface-700 dark:text-surface-300">
        <span class="material-symbols-rounded text-[16px]">content_copy</span> Copy
      </button>
      <button @click="handleDuplicateItem" class="w-full px-2.5 py-1.5 text-xs text-left hover:bg-surface-50 dark:hover:bg-surface-700 flex items-center gap-2 text-surface-700 dark:text-surface-300">
        <span class="material-symbols-rounded text-[16px]">content_paste</span> Duplicate
      </button>
      <div class="my-0.5 border-t border-surface-200 dark:border-surface-700"></div>
      <button @click="handleCopyStyle" class="w-full px-2.5 py-1.5 text-xs text-left hover:bg-surface-50 dark:hover:bg-surface-700 flex items-center gap-2 text-surface-700 dark:text-surface-300">
        <span class="material-symbols-rounded text-[16px]">format_paint</span> Copy Style
      </button>
      <button
        v-if="copiedStyle"
        @click="handlePasteStyle"
        class="w-full px-2.5 py-1.5 text-xs text-left hover:bg-surface-50 dark:hover:bg-surface-700 flex items-center gap-2 text-primary-600 dark:text-primary-400"
      >
        <span class="material-symbols-rounded text-[16px]">format_paint</span>
        Paste Style{{ store.selectedItemIds.size > 1 ? ` (${store.selectedItemIds.size} items)` : '' }}
      </button>
      <div class="my-0.5 border-t border-surface-200 dark:border-surface-700"></div>
      <button @click="handleCommentOnItem" class="w-full px-2.5 py-1.5 text-xs text-left hover:bg-surface-50 dark:hover:bg-surface-700 flex items-center gap-2 text-surface-700 dark:text-surface-300">
        <span class="material-symbols-rounded text-[16px]">add_comment</span> Comment
      </button>
      <!-- File preview/edit options -->
      <template v-if="itemContextMenu.item?.type === 'file'">
        <div class="my-0.5 border-t border-surface-200 dark:border-surface-700"></div>
        <button @click="openFilePreview(itemContextMenu.item); closeItemContext()" class="w-full px-2.5 py-1.5 text-xs text-left hover:bg-surface-50 dark:hover:bg-surface-700 flex items-center gap-2 text-surface-700 dark:text-surface-300">
          <span class="material-symbols-rounded text-[16px]">visibility</span> Preview
        </button>
        <button
          v-if="isCollabEditable(itemContextMenu.item)"
          @click="openFileInCollab(itemContextMenu.item); closeItemContext()"
          class="w-full px-2.5 py-1.5 text-xs text-left hover:bg-surface-50 dark:hover:bg-surface-700 flex items-center gap-2 text-primary-600 dark:text-primary-400"
        >
          <span class="material-symbols-rounded text-[16px]">edit_document</span> Edit in Editor
        </button>
      </template>
      <!-- Blend mode (images & shapes) -->
      <template v-if="itemContextMenu.item?.type === 'image' || itemContextMenu.item?.type === 'shape'">
        <div class="my-0.5 border-t border-surface-200 dark:border-surface-700"></div>
        <div class="px-2.5 py-1.5">
          <p class="text-[9px] font-medium text-surface-500 uppercase tracking-wider mb-1">Blend Mode</p>
          <select
            :value="itemContextMenu.item?.style_data?.blend_mode || 'normal'"
            @change="applyBlendMode($event.target.value)"
            class="w-full text-[11px] bg-surface-50 dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded px-1.5 py-1 text-surface-700 dark:text-surface-300"
          >
            <option v-for="bm in blendModesList" :key="bm" :value="bm">{{ bm }}</option>
          </select>
        </div>
      </template>
      <!-- Shape mask image (shapes only) -->
      <template v-if="itemContextMenu.item?.type === 'shape' || itemContextMenu.item?.type === 'pen_shape'">
        <div class="my-0.5 border-t border-surface-200 dark:border-surface-700"></div>
        <template v-if="itemContextMenu.item?.style_data?.mask_image_url">
          <button @click="removeShapeMaskFromContext" class="w-full px-2.5 py-1.5 text-xs text-left hover:bg-surface-50 dark:hover:bg-surface-700 flex items-center gap-2 text-surface-700 dark:text-surface-300">
            <span class="material-symbols-rounded text-[16px]">hide_image</span> Remove Mask Image
          </button>
        </template>
        <template v-else>
          <label class="w-full px-2.5 py-1.5 text-xs text-left hover:bg-surface-50 dark:hover:bg-surface-700 flex items-center gap-2 text-surface-700 dark:text-surface-300 cursor-pointer">
            <span class="material-symbols-rounded text-[16px]">add_photo_alternate</span> Add Image Mask
            <input type="file" accept="image/*" class="hidden" @change="addShapeMaskFromContext($event)" />
          </label>
        </template>
      </template>
      <!-- Clipping mask (multi-selection with shape) -->
      <template v-if="store.canMaskSelection()">
        <div class="my-0.5 border-t border-surface-200 dark:border-surface-700"></div>
        <button @click="handleCreateMask" class="w-full px-2.5 py-1.5 text-xs text-left hover:bg-surface-50 dark:hover:bg-surface-700 flex items-center gap-2 text-surface-700 dark:text-surface-300">
          <span class="material-symbols-rounded text-[16px]">content_cut</span> Create Clipping Mask
        </button>
      </template>
      <!-- Release clipping mask (if this shape is a mask container) -->
      <template v-if="store.isMaskContainer(itemContextMenu.item?.id)">
        <div class="my-0.5 border-t border-surface-200 dark:border-surface-700"></div>
        <button @click="handleReleaseMask" class="w-full px-2.5 py-1.5 text-xs text-left hover:bg-surface-50 dark:hover:bg-surface-700 flex items-center gap-2 text-surface-700 dark:text-surface-300">
          <span class="material-symbols-rounded text-[16px]">content_paste_off</span> Release Clipping Mask
        </button>
      </template>
      <!-- Recolor drawing -->
      <template v-if="itemContextMenu.item?.type === 'drawing'">
        <div class="my-0.5 border-t border-surface-200 dark:border-surface-700"></div>
        <div class="px-2.5 py-1.5">
          <p class="text-[9px] font-medium text-surface-500 uppercase tracking-wider mb-1">Drawing Color</p>
          <div class="flex items-center gap-1 flex-wrap">
            <button
              v-for="c in drawingRecolorPresets"
              :key="c"
              @click="recolorDrawing(c)"
              class="w-5 h-5 rounded-full border-2 transition-all hover:scale-110"
              :class="itemContextMenu.item?.color === c ? 'border-primary-500 ring-2 ring-primary-200' : 'border-surface-200 dark:border-surface-600'"
              :style="{ backgroundColor: c }"
            />
            <label class="relative w-5 h-5 rounded-full border-2 border-dashed border-surface-300 dark:border-surface-600 cursor-pointer flex items-center justify-center hover:border-primary-400 transition-colors" title="Custom color">
              <span class="material-symbols-rounded text-[10px] text-surface-400">palette</span>
              <input
                type="color"
                :value="itemContextMenu.item?.color || '#1e293b'"
                @input="recolorDrawing($event.target.value)"
                class="absolute inset-0 w-full h-full opacity-0 cursor-pointer"
              />
            </label>
          </div>
        </div>
      </template>
      <!-- Frame / Presentation options (frames are always slides) -->
      <template v-if="itemContextMenu.item?.type === 'frame'">
        <div class="my-0.5 border-t border-surface-200 dark:border-surface-700"></div>
        <button
          @click="presenterNotesItemId = itemContextMenu.item.id; presenterNotesText = itemContextMenu.item.presenter_notes || ''; showPresenterNotesEditor = true; closeItemContext()"
          class="w-full px-2.5 py-1.5 text-xs text-left hover:bg-surface-50 dark:hover:bg-surface-700 flex items-center gap-2 text-surface-700 dark:text-surface-300"
        >
          <span class="material-symbols-rounded text-[16px]">sticky_note_2</span> Presenter Notes
        </button>
      </template>
      <!-- Convert to Frame (non-frame items, or multi-selection) -->
      <template v-if="itemContextMenu.item?.type !== 'frame' || store.selectedItemIds.size > 1">
        <div class="my-0.5 border-t border-surface-200 dark:border-surface-700"></div>
        <button @click="handleConvertToFrame" class="w-full px-2.5 py-1.5 text-xs text-left hover:bg-surface-50 dark:hover:bg-surface-700 flex items-center gap-2 text-surface-700 dark:text-surface-300">
          <span class="material-symbols-rounded text-[16px]">frame_inspect</span> Convert to Artboard
        </button>
      </template>
      <!-- Component instance options -->
      <template v-if="itemContextMenu.item?.component_id">
        <div class="my-0.5 border-t border-surface-200 dark:border-surface-700"></div>
        <div class="px-2.5 py-1">
          <p class="text-[9px] font-medium text-cyan-600 dark:text-cyan-400 uppercase tracking-wider flex items-center gap-1">
            <span class="material-symbols-rounded" style="font-size: 11px;">link</span>
            Linked Component
          </p>
        </div>
        <button @click="handleDetachComponent" class="w-full px-2.5 py-1.5 text-xs text-left hover:bg-surface-50 dark:hover:bg-surface-700 flex items-center gap-2 text-surface-700 dark:text-surface-300">
          <span class="material-symbols-rounded text-[16px]">link_off</span> Detach from Component
        </button>
        <button @click="handleSelectAllInstances" class="w-full px-2.5 py-1.5 text-xs text-left hover:bg-surface-50 dark:hover:bg-surface-700 flex items-center gap-2 text-surface-700 dark:text-surface-300">
          <span class="material-symbols-rounded text-[16px]">select_all</span> Select Instance Items
        </button>
      </template>
      <!-- Select Similar (right flyout) -->
      <div class="my-0.5 border-t border-surface-200 dark:border-surface-700"></div>
      <div class="relative">
        <button
          ref="selectSimilarTriggerEl"
          @click.stop="toggleSelectSimilar"
          class="w-full px-2.5 py-1.5 text-xs text-left flex items-center gap-2 text-surface-700 dark:text-surface-300"
          :class="selectSimilarOpen ? 'bg-surface-100 dark:bg-surface-700' : 'hover:bg-surface-50 dark:hover:bg-surface-700'"
        >
          <span class="material-symbols-rounded text-[16px]">filter_alt</span>
          Select Similar
          <span class="material-symbols-rounded text-[14px] ml-auto text-surface-400">chevron_right</span>
        </button>
        <div
          v-if="selectSimilarOpen"
          ref="selectSimilarFlyoutEl"
          class="fixed bg-white dark:bg-surface-800 rounded-lg shadow-lg border border-surface-200 dark:border-surface-700 py-0.5 min-w-[160px] z-[60]"
          :style="{ left: selectSimilarPos.x + 'px', top: selectSimilarPos.y + 'px' }"
          @click.stop
        >
          <button
            v-for="opt in selectSimilarOptions"
            :key="opt.criteria"
            v-show="opt.show === undefined || opt.show(itemContextMenu.item)"
            @click="store.selectSimilar(opt.criteria); closeItemContext()"
            class="w-full px-2.5 py-1.5 text-xs text-left hover:bg-surface-50 dark:hover:bg-surface-700 flex items-center gap-2 text-surface-700 dark:text-surface-300"
          >
            <span class="material-symbols-rounded text-[14px] text-surface-400">{{ opt.icon }}</span>
            {{ opt.label }}
          </button>
        </div>
      </div>

      <div class="my-0.5 border-t border-surface-200 dark:border-surface-700"></div>
      <button @click="handleDeleteItem" class="w-full px-2.5 py-1.5 text-xs text-left hover:bg-red-50 dark:hover:bg-red-900/20 flex items-center gap-2 text-red-600 dark:text-red-400">
        <span class="material-symbols-rounded text-[16px]">delete</span> Delete
      </button>
    </div>
    
    <!-- Comment popover (inline comment creation -- item or canvas) -->
    <MoodCommentPopover
      :visible="!!comments.commentingOnItem.value || !!comments.commentingAtCanvas.value"
      :x="commentPopoverPos.x"
      :y="commentPopoverPos.y"
      :item-id="comments.commentingOnItem.value"
      :pin-x="comments.commentingAtCanvas.value?.canvasX ?? null"
      :pin-y="comments.commentingAtCanvas.value?.canvasY ?? null"
      :is-public="false"
      @submit="onPopoverSubmit"
      @cancel="comments.cancelCommentOnItem()"
    />

    <!-- Drive picker modal -->
    <MoodDrivePicker
      v-if="showDrivePicker"
      @select="onDriveFileSelected"
      @select-folder="onDriveFolderSelected"
      @close="showDrivePicker = false"
    />
    
    <!-- Folder browser modal -->
    <MoodFolderBrowser
      v-if="showFolderBrowser"
      :folder-id="folderBrowserId"
      :folder-name="folderBrowserName"
      @close="showFolderBrowser = false"
    />
    
    <!-- Calendar picker modal -->
    <MoodCalendarPicker
      v-if="showCalendarPicker"
      @select="onCalendarEventSelected"
      @close="showCalendarPicker = false"
    />
    
    <!-- Board picker modal -->
    <MoodBoardPicker
      v-if="showBoardPicker"
      @select-board="onBoardSelected"
      @select-card="onCardSelected"
      @close="showBoardPicker = false"
    />
    
    <!-- Presenter Notes Editor -->
    <teleport to="body">
      <transition name="fade">
        <div v-if="showPresenterNotesEditor" class="fixed inset-0 z-[100] flex items-center justify-center bg-black/50 backdrop-blur-sm" @click.self="showPresenterNotesEditor = false">
          <div class="bg-white dark:bg-surface-800 rounded-2xl shadow-2xl border border-surface-200 dark:border-surface-700 w-[480px] max-w-[90vw]">
            <div class="flex items-center justify-between px-5 py-4 border-b border-surface-200 dark:border-surface-700">
              <h3 class="text-sm font-semibold text-surface-900 dark:text-surface-100 flex items-center gap-2">
                <span class="material-symbols-rounded text-lg">sticky_note_2</span>
                Presenter Notes
              </h3>
              <button @click="showPresenterNotesEditor = false" class="p-1 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-400">
                <span class="material-symbols-rounded text-lg">close</span>
              </button>
            </div>
            <div class="p-5">
              <textarea
                v-model="presenterNotesText"
                rows="6"
                class="w-full rounded-xl border border-surface-200 dark:border-surface-600 bg-surface-50 dark:bg-surface-700 px-4 py-3 text-sm focus:ring-2 focus:ring-primary-500 focus:border-primary-500 resize-none"
                placeholder="Add notes for this slide (visible only to you during presentation)..."
              ></textarea>
            </div>
            <div class="flex justify-end gap-2 px-5 py-4 border-t border-surface-200 dark:border-surface-700">
              <button
                @click="showPresenterNotesEditor = false"
                class="px-4 py-2 rounded-full text-sm font-medium text-surface-600 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
              >
                Cancel
              </button>
              <button
                @click="savePresenterNotes"
                class="px-4 py-2 rounded-full text-sm font-medium bg-primary-500 text-white hover:bg-primary-600 transition-colors"
              >
                Save Notes
              </button>
            </div>
          </div>
        </div>
      </transition>
    </teleport>
    
    <!-- Connection context menu -->
    <div
      ref="connPanelEl"
      v-if="connContextMenu.show && connContextMenu.conn"
      class="fixed z-50 bg-white dark:bg-surface-800 rounded-xl shadow-2xl border border-surface-200 dark:border-surface-700 py-1.5 w-[340px] max-h-[70vh] overflow-y-auto"
      :style="{ left: connContextMenu.x + 'px', top: connContextMenu.y + 'px' }"
      @click.stop
      @contextmenu.prevent.stop
    >
      <!-- Header (drag handle) -->
      <div
        class="px-3 pb-2 mb-1 border-b border-surface-100 dark:border-surface-700 cursor-grab active:cursor-grabbing select-none flex items-center gap-2"
        @mousedown="onConnPanelDragStart"
      >
        <span class="material-symbols-rounded text-xs text-surface-400" style="font-size: 14px;">drag_indicator</span>
        <p class="text-xs font-semibold text-surface-700 dark:text-surface-300 flex-1">Connection Settings</p>
      </div>

      <!-- Line color -->
      <div class="px-3 py-1.5">
        <p class="text-[10px] font-medium text-surface-500 uppercase tracking-wider mb-1.5">Color</p>
        <div class="flex items-center gap-1.5 flex-wrap">
          <button
            v-for="c in connColorPresets"
            :key="c.value"
            @click="updateConnColor(c.value)"
            class="w-6 h-6 rounded-full border-2 transition-all hover:scale-110"
            :class="connContextMenu.conn.line_color === c.value ? 'border-primary-500 ring-2 ring-primary-200 dark:ring-primary-800' : 'border-surface-200 dark:border-surface-600'"
            :style="{ backgroundColor: connPresetBg(c.value) }"
            :title="c.label"
          />
          <label class="relative w-6 h-6 rounded-full border-2 border-dashed border-surface-300 dark:border-surface-600 cursor-pointer flex items-center justify-center hover:border-primary-400 transition-colors" title="Custom color">
            <span class="material-symbols-rounded text-xs text-surface-400">palette</span>
            <input
              type="color"
              :value="connContextMenu.conn.line_color || '#666666'"
              @input="updateConnColor($event.target.value)"
              class="absolute inset-0 w-full h-full opacity-0 cursor-pointer"
            />
          </label>
          <button
            v-if="hasEyeDropper"
            @click="pickColorFromScreen"
            class="w-6 h-6 rounded-full border-2 border-dashed border-surface-300 dark:border-surface-600 flex items-center justify-center hover:border-primary-400 hover:bg-primary-50 dark:hover:bg-primary-900/20 transition-colors"
            title="Pick color from screen"
          >
            <span class="material-symbols-rounded text-xs text-surface-400">colorize</span>
          </button>
        </div>
        <!-- Saved palette colors -->
        <template v-if="connBoardPaletteColors.length">
          <p class="text-[9px] font-medium text-surface-400 uppercase tracking-wider mt-2 mb-1">Saved</p>
          <div class="flex items-center gap-1.5 flex-wrap">
            <button
              v-for="(pc, idx) in connBoardPaletteColors"
              :key="'bp-' + idx"
              @click="updateConnColor(pc)"
              class="w-5 h-5 rounded-full border-2 transition-all hover:scale-110"
              :class="connContextMenu.conn.line_color === pc ? 'border-primary-500 ring-2 ring-primary-200 dark:ring-primary-800' : 'border-surface-200 dark:border-surface-600'"
              :style="{ backgroundColor: pc }"
              :title="pc"
            />
          </div>
        </template>
        <!-- Saved gradient swatches (applied as gradient_color_start/end) -->
        <template v-if="connBoardPaletteGradients.length">
          <p class="text-[9px] font-medium text-surface-400 uppercase tracking-wider mt-2 mb-1">Saved Gradients</p>
          <div class="flex items-center gap-1.5 flex-wrap">
            <button
              v-for="(sg, idx) in connBoardPaletteGradients"
              :key="'bg-' + idx"
              @click="applyGradientToConn(sg)"
              class="w-7 h-5 rounded-md border transition-all hover:scale-110"
              :class="'border-surface-200 dark:border-surface-600'"
              :style="{ background: connGradientCSS(sg) }"
              title="Apply gradient to connection"
            />
          </div>
        </template>
      </div>

      <!-- Style + Arrows row -->
      <div class="px-3 py-1.5 grid grid-cols-2 gap-3">
        <div>
          <p class="text-[10px] font-medium text-surface-500 uppercase tracking-wider mb-1.5">Style</p>
          <div class="flex items-center gap-1">
            <button
              v-for="s in [{ value: 'solid', label: 'Solid' }, { value: 'dashed', label: 'Dash' }, { value: 'dotted', label: 'Dot' }]"
              :key="s.value"
              @click="updateConnStyle(s.value)"
              :class="[
                'px-1.5 py-1 rounded-md text-[11px] font-medium transition-colors',
                connContextMenu.conn.line_style === s.value
                  ? 'bg-primary-100 dark:bg-primary-900/30 text-primary-700 dark:text-primary-300'
                  : 'text-surface-600 dark:text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700'
              ]"
            >{{ s.label }}</button>
          </div>
        </div>
        <div>
          <p class="text-[10px] font-medium text-surface-500 uppercase tracking-wider mb-1.5">Arrows</p>
          <div class="flex items-center gap-1.5">
            <button
              @click="toggleConnArrowStart"
              :class="[
                'flex items-center gap-0.5 px-1.5 py-1 rounded-md text-[11px] transition-colors',
                connContextMenu.conn.arrow_start
                  ? 'bg-primary-100 dark:bg-primary-900/30 text-primary-700 dark:text-primary-300'
                  : 'text-surface-500 hover:bg-surface-100 dark:hover:bg-surface-700'
              ]"
            >
              <span class="material-symbols-rounded text-sm">arrow_back</span> Start
            </button>
            <button
              @click="toggleConnArrowEnd"
              :class="[
                'flex items-center gap-0.5 px-1.5 py-1 rounded-md text-[11px] transition-colors',
                connContextMenu.conn.arrow_end
                  ? 'bg-primary-100 dark:bg-primary-900/30 text-primary-700 dark:text-primary-300'
                  : 'text-surface-500 hover:bg-surface-100 dark:hover:bg-surface-700'
              ]"
            >
              End <span class="material-symbols-rounded text-sm">arrow_forward</span>
            </button>
          </div>
        </div>
      </div>

      <!-- Thickness -->
      <div class="px-3 py-1.5">
        <p class="text-[10px] font-medium text-surface-500 uppercase tracking-wider mb-1.5">Thickness</p>
        <div class="flex items-center gap-2">
          <input
            type="range"
            :value="connContextMenu.conn.line_width || 2"
            min="1"
            max="12"
            step="1"
            @input="updateConnWidth(parseInt($event.target.value))"
            class="flex-1 h-1 accent-primary-500 cursor-pointer"
          />
          <span class="text-[11px] font-mono text-surface-500 w-6 text-right">{{ connContextMenu.conn.line_width || 2 }}</span>
          <span class="text-[9px] text-surface-400">px</span>
        </div>
        <div class="flex gap-1 mt-1.5">
          <button
            v-for="pw in [1, 2, 3, 5, 8]"
            :key="pw"
            @click="updateConnWidth(pw)"
            class="flex-1 py-0.5 text-[10px] rounded-md border transition-colors"
            :class="(connContextMenu.conn.line_width || 2) === pw
              ? 'bg-primary-50 dark:bg-primary-900/30 border-primary-300 dark:border-primary-700 text-primary-600 dark:text-primary-400 font-semibold'
              : 'bg-surface-50 dark:bg-surface-700 border-surface-200 dark:border-surface-600 text-surface-500 hover:bg-surface-100 dark:hover:bg-surface-600'"
          >{{ pw }}px</button>
        </div>
      </div>

      <div class="my-1 border-t border-surface-100 dark:border-surface-700"></div>

      <!-- Glow -->
      <div class="px-3 py-1.5">
        <div class="flex items-center justify-between mb-1.5">
          <p class="text-[10px] font-medium text-surface-500 uppercase tracking-wider">Glow</p>
          <span
            @click="toggleConnGlow"
            :class="[
              'relative inline-flex h-4 w-8 shrink-0 rounded-full transition-colors duration-200 cursor-pointer',
              connContextMenu.conn.glow_enabled ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'
            ]"
          >
            <span
              :class="[
                'inline-block h-3 w-3 rounded-full bg-white shadow-sm ring-0 transition-transform duration-200 mt-0.5',
                connContextMenu.conn.glow_enabled ? 'translate-x-[18px]' : 'translate-x-[2px]'
              ]"
            />
          </span>
        </div>
        <template v-if="connContextMenu.conn.glow_enabled">
          <div class="grid grid-cols-[auto_1fr_1fr] gap-x-3 gap-y-1.5 items-center">
            <div class="col-span-3 flex items-center gap-1.5">
              <label class="text-[9px] text-surface-400 uppercase tracking-wider font-medium">Color</label>
              <label class="relative w-5 h-5 rounded-full border-2 transition-all hover:scale-110 cursor-pointer"
                :style="{ backgroundColor: connContextMenu.conn.glow_color || connContextMenu.conn.line_color || '#6366f1', borderColor: connContextMenu.conn.glow_color || connContextMenu.conn.line_color || '#6366f1' }"
              >
                <input
                  type="color"
                  :value="connContextMenu.conn.glow_color || connContextMenu.conn.line_color || '#6366f1'"
                  @input="updateConnGlowProp('glow_color', $event.target.value)"
                  class="absolute inset-0 w-full h-full opacity-0 cursor-pointer"
                />
              </label>
              <button
                @click="updateConnGlowProp('glow_color', connContextMenu.conn.line_color || '#6366f1')"
                class="text-[10px] text-surface-400 hover:text-primary-500 transition-colors"
                title="Match line color"
              >
                <span class="material-symbols-rounded text-sm">sync</span>
              </button>
              <span class="text-[10px] text-surface-500 font-mono">{{ (connContextMenu.conn.glow_color || connContextMenu.conn.line_color || '#6366f1').toUpperCase() }}</span>
            </div>
            <label class="text-[9px] text-surface-400 uppercase tracking-wider font-medium">Intensity</label>
            <input
              type="range"
              :value="connContextMenu.conn.glow_opacity ?? 60"
              min="10" max="100" step="5"
              @input="updateConnGlowProp('glow_opacity', parseInt($event.target.value))"
              class="h-1 accent-primary-500 cursor-pointer"
            />
            <span class="text-[10px] text-surface-400 text-right">{{ connContextMenu.conn.glow_opacity ?? 60 }}%</span>
            <label class="text-[9px] text-surface-400 uppercase tracking-wider font-medium">Spread</label>
            <input
              type="range"
              :value="connContextMenu.conn.glow_blur ?? 6"
              min="1" max="200" step="1"
              @input="updateConnGlowProp('glow_blur', parseInt($event.target.value))"
              class="h-1 accent-primary-500 cursor-pointer"
            />
            <span class="text-[10px] text-surface-400 text-right">{{ connContextMenu.conn.glow_blur ?? 6 }}px</span>
          </div>
        </template>
      </div>

      <div class="my-1 border-t border-surface-100 dark:border-surface-700"></div>

      <!-- Gradient -->
      <div class="px-3 py-1.5">
        <div class="flex items-center justify-between mb-1.5">
          <p class="text-[10px] font-medium text-surface-500 uppercase tracking-wider">Gradient</p>
          <span
            @click="toggleConnGradient"
            :class="[
              'relative inline-flex h-4 w-8 shrink-0 rounded-full transition-colors duration-200 cursor-pointer',
              connContextMenu.conn.gradient_enabled ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'
            ]"
          >
            <span
              :class="[
                'inline-block h-3 w-3 rounded-full bg-white shadow-sm ring-0 transition-transform duration-200 mt-0.5',
                connContextMenu.conn.gradient_enabled ? 'translate-x-[18px]' : 'translate-x-[2px]'
              ]"
            />
          </span>
        </div>
        <template v-if="connContextMenu.conn.gradient_enabled">
          <div class="grid grid-cols-2 gap-2">
            <!-- Start color -->
            <div>
              <label class="text-[9px] text-surface-400 uppercase tracking-wider font-medium">Start</label>
              <div class="flex items-center gap-1.5 mt-1">
                <label class="relative w-6 h-6 rounded-full border-2 transition-all hover:scale-110 cursor-pointer flex-shrink-0"
                  :style="{ backgroundColor: connContextMenu.conn.gradient_color_start || connContextMenu.conn.line_color || '#6366f1', borderColor: connContextMenu.conn.gradient_color_start || connContextMenu.conn.line_color || '#6366f1' }"
                >
                  <input
                    type="color"
                    :value="connContextMenu.conn.gradient_color_start || connContextMenu.conn.line_color || '#6366f1'"
                    @input="updateConnGradientProp('gradient_color_start', $event.target.value)"
                    class="absolute inset-0 w-full h-full opacity-0 cursor-pointer"
                  />
                </label>
                <span class="text-[9px] text-surface-500 font-mono truncate">{{ (connContextMenu.conn.gradient_color_start || connContextMenu.conn.line_color || '#6366f1').toUpperCase() }}</span>
              </div>
            </div>
            <!-- End color -->
            <div>
              <label class="text-[9px] text-surface-400 uppercase tracking-wider font-medium">End</label>
              <div class="flex items-center gap-1.5 mt-1">
                <label class="relative w-6 h-6 rounded-full border-2 transition-all hover:scale-110 cursor-pointer flex-shrink-0"
                  :style="{ backgroundColor: connContextMenu.conn.gradient_color_end || '#8b5cf6', borderColor: connContextMenu.conn.gradient_color_end || '#8b5cf6' }"
                >
                  <input
                    type="color"
                    :value="connContextMenu.conn.gradient_color_end || '#8b5cf6'"
                    @input="updateConnGradientProp('gradient_color_end', $event.target.value)"
                    class="absolute inset-0 w-full h-full opacity-0 cursor-pointer"
                  />
                </label>
                <span class="text-[9px] text-surface-500 font-mono truncate">{{ (connContextMenu.conn.gradient_color_end || '#8b5cf6').toUpperCase() }}</span>
              </div>
            </div>
          </div>
          <!-- Swap direction -->
          <button
            @click="swapConnGradientColors"
            class="flex items-center gap-1 text-[10px] text-surface-400 hover:text-primary-500 transition-colors mt-2"
            title="Swap start/end colors"
          >
            <span class="material-symbols-rounded text-sm">swap_horiz</span>
            Swap colors
          </button>
        </template>
      </div>

      <div class="my-1 border-t border-surface-100 dark:border-surface-700"></div>

      <!-- Label -->
      <div class="px-3 py-1.5">
        <p class="text-[10px] font-medium text-surface-500 uppercase tracking-wider mb-1.5">Label</p>
        <div class="flex items-center gap-1.5">
          <input
            ref="connLabelInput"
            type="text"
            :value="connContextMenu.conn.label || ''"
            @keydown.enter="commitConnLabel($event.target.value)"
            @blur="commitConnLabel($event.target.value)"
            placeholder="Add label..."
            class="flex-1 text-xs px-2.5 py-1.5 rounded-lg bg-surface-100 dark:bg-surface-700 border border-surface-200 dark:border-surface-600 text-surface-800 dark:text-surface-200 placeholder-surface-400 dark:placeholder-surface-500 outline-none focus:border-primary-400 focus:ring-1 focus:ring-primary-400/30 transition-colors"
          />
          <button
            v-if="connContextMenu.conn.label"
            @click="commitConnLabel('')"
            class="p-1 rounded-md text-surface-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors"
            title="Remove label"
          >
            <span class="material-symbols-rounded text-sm">close</span>
          </button>
        </div>
      </div>

      <div class="my-1 border-t border-surface-100 dark:border-surface-700"></div>

      <!-- Render above items toggle (per-connection) -->
      <div class="px-3 py-1.5">
        <div class="flex items-center justify-between">
          <div class="flex items-center gap-1.5">
            <span class="material-symbols-rounded text-sm text-surface-400">layers</span>
            <p class="text-[10px] font-medium text-surface-500 uppercase tracking-wider">Above Items</p>
          </div>
          <span
            @click="toggleConnRenderAbove"
            :class="[
              'relative inline-flex h-4 w-8 shrink-0 rounded-full transition-colors duration-200 cursor-pointer',
              connContextMenu.conn.render_above ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'
            ]"
            title="Render this line above or below canvas items"
          >
            <span
              :class="[
                'inline-block h-3 w-3 rounded-full bg-white shadow-sm ring-0 transition-transform duration-200 mt-0.5',
                connContextMenu.conn.render_above ? 'translate-x-[18px]' : 'translate-x-[2px]'
              ]"
            />
          </span>
        </div>
      </div>

      <div class="my-1 border-t border-surface-100 dark:border-surface-700"></div>

      <!-- Reset anchors to auto -->
      <button
        v-if="connContextMenu.conn.from_anchor_x != null || connContextMenu.conn.to_anchor_x != null"
        @click="resetConnAnchors"
        class="w-full px-3 py-1.5 text-left text-xs hover:bg-surface-100 dark:hover:bg-surface-700 flex items-center gap-2 text-surface-600 dark:text-surface-400"
      >
        <span class="material-symbols-rounded text-sm">restart_alt</span> Reset Anchor Points
      </button>

      <!-- Delete -->
      <button @click="deleteConnFromContext" class="w-full px-3 py-1.5 text-left text-xs hover:bg-red-50 dark:hover:bg-red-900/20 flex items-center gap-2 text-red-600 dark:text-red-400">
        <span class="material-symbols-rounded text-sm">delete</span> Delete Connection
      </button>
    </div>

    <!-- PPTX export slide picker modal -->
    <MoodExportPptxModal
      :show="showExportPptxModal"
      :slides="store.presentationSlides"
      @export="onExportPptxConfirm"
      @close="showExportPptxModal = false"
    />

    <!-- Color picker modal -->
    <MoodColorPickerModal
      v-if="showColorPicker"
      :initial-color="colorPickerInitial"
      :title="colorPickerItemId ? (colorPickerField === 'color' ? 'Edit Color Swatch' : 'Edit Color') : 'New Color Swatch'"
      :confirm-label="colorPickerItemId ? 'Update' : 'Add Swatch'"
      @select="onColorPickerSave"
      @close="showColorPicker = false"
    />
    
    <!-- File preview modal -->
    <MoodFilePreview
      v-if="showFilePreview && filePreviewItem"
      :item="filePreviewItem"
      @close="showFilePreview = false; filePreviewItem = null"
      @edit-collab="openFileInCollab"
    />

    <!-- Drawing canvas overlay -->
    <MoodDrawingCanvas
      v-if="showDrawingCanvas"
      :initial-data="editingDrawingData"
      @save="onDrawingSave"
      @discard="onDrawingDiscard"
    />

    <!-- Client assignment prompt (shown on first upload if no client set) -->
    <teleport to="body">
      <transition name="fade">
        <div
          v-if="showClientPrompt"
          class="fixed inset-0 z-[9999] flex items-center justify-center bg-black/40 backdrop-blur-sm"
          @click.self="onClientPromptSkip"
        >
          <div class="bg-white dark:bg-surface-800 rounded-2xl shadow-2xl w-full max-w-md mx-4 overflow-hidden border border-surface-200 dark:border-surface-700">
            <!-- Header -->
            <div class="px-5 pt-5 pb-3">
              <div class="flex items-center gap-3 mb-1">
                <div class="w-10 h-10 rounded-xl bg-amber-100 dark:bg-amber-900/30 flex items-center justify-center">
                  <span class="material-symbols-rounded text-xl text-amber-600 dark:text-amber-400">folder_shared</span>
                </div>
                <div>
                  <h3 class="text-base font-semibold text-surface-900 dark:text-surface-100">Assign a Client</h3>
                  <p class="text-xs text-surface-500">Files will be organized in Drive under the client folder</p>
                </div>
              </div>
            </div>

            <!-- Info -->
            <div class="px-5 pb-3">
              <div class="flex items-start gap-2 p-3 rounded-xl bg-surface-50 dark:bg-surface-700/50 text-xs text-surface-600 dark:text-surface-400">
                <span class="material-symbols-rounded text-sm text-surface-400 mt-0.5 flex-shrink-0">info</span>
                <span>
                  Uploaded files go to <strong>Drive</strong>. With a client assigned, they are stored under:
                  <span class="font-mono text-primary-600 dark:text-primary-400">ClientName / Moodboards / {{ currentBoard?.name }}</span>.
                  Without a client, they go to <span class="font-mono text-primary-600 dark:text-primary-400">Moodboards / {{ currentBoard?.name }}</span>.
                </span>
              </div>
            </div>

            <!-- Search -->
            <div class="px-5 pb-2">
              <div class="relative">
                <span class="material-symbols-rounded text-lg absolute left-3 top-1/2 -translate-y-1/2 text-surface-400">search</span>
                <input
                  v-model="clientPromptSearch"
                  type="text"
                  placeholder="Search clients..."
                  class="w-full pl-9 pr-3 py-2 rounded-xl bg-surface-100 dark:bg-surface-700 border border-surface-200 dark:border-surface-600 text-sm focus:ring-2 focus:ring-primary-500 focus:border-primary-500 transition-colors"
                  @keydown.esc.prevent="onClientPromptSkip"
                />
              </div>
            </div>

            <!-- Client list -->
            <div class="px-5 pb-2 max-h-[240px] overflow-y-auto">
              <div v-if="!filteredPromptClients.length" class="py-6 text-center text-sm text-surface-400">
                No clients found
              </div>
              <button
                v-for="client in filteredPromptClients"
                :key="client.id"
                @click="onClientPromptSelect(client)"
                class="w-full flex items-center gap-3 px-3 py-2.5 rounded-xl hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors text-left group"
              >
                <div
                  class="w-8 h-8 rounded-lg flex items-center justify-center text-white text-sm font-semibold flex-shrink-0"
                  :style="{ backgroundColor: client.color || '#6366f1' }"
                >
                  {{ (client.display_name || client.domain || '?').charAt(0).toUpperCase() }}
                </div>
                <div class="min-w-0 flex-1">
                  <p class="text-sm font-medium text-surface-900 dark:text-surface-100 truncate">
                    {{ client.display_name || client.domain }}
                  </p>
                  <p v-if="client.display_name && client.domain" class="text-[11px] text-surface-400 truncate">
                    {{ client.domain }}
                  </p>
                </div>
                <span class="material-symbols-rounded text-lg text-surface-300 group-hover:text-primary-500 transition-colors">arrow_forward</span>
              </button>
            </div>

            <!-- Footer -->
            <div class="px-5 py-4 border-t border-surface-100 dark:border-surface-700 flex items-center justify-between">
              <p class="text-[11px] text-surface-400">You can change this later in board settings</p>
              <button
                @click="onClientPromptSkip"
                class="px-4 py-2 rounded-full text-sm font-medium text-surface-600 dark:text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
              >
                Skip for now
              </button>
            </div>
          </div>
        </div>
      </transition>
    </teleport>
    
    <!-- Scroll-driven story mode overlay -->
    <MoodScrollStory
      :active="store.scrollStoryMode"
      :bg-color="currentBoard?.background_color || '#111111'"
      @exit="exitScrollStory"
      @pan-to-slide="onScrollStoryPan"
    />

    <!-- Mobile: Rotate phone prompt before presentation starts -->
    <Teleport to="body">
      <Transition name="rotate-prompt">
        <div
          v-if="mobileWaitingForLandscape"
          class="fixed inset-0 z-[9999] bg-surface-900 flex flex-col items-center justify-center gap-6 px-8"
        >
          <!-- Animated rotate icon -->
          <div class="rotate-phone-icon">
            <span class="material-symbols-rounded text-6xl text-white/80">screen_rotation</span>
          </div>

          <div class="text-center">
            <p class="text-lg font-semibold text-white">Rotate Your Phone</p>
            <p class="text-sm text-white/60 mt-2">Moodboard presentations are designed for landscape view. Please rotate your device to start.</p>
          </div>

          <button
            @click="cancelMobileWait"
            class="mt-4 px-6 py-2.5 bg-white/10 hover:bg-white/20 text-white rounded-full text-sm font-medium transition-colors flex items-center gap-2"
          >
            <span class="material-symbols-rounded text-lg">arrow_back</span>
            Back to Boards
          </button>
        </div>
      </Transition>
    </Teleport>

    <!-- Mobile: Rotate back to portrait (shown whenever mobile + landscape + not presenting) -->
    <Teleport to="body">
      <Transition name="rotate-prompt">
        <div
          v-if="isMobile && isLandscape && !store.presentationMode && !mobileWaitingForLandscape"
          class="fixed inset-0 z-[9999] bg-surface-900 flex flex-col items-center justify-center gap-6 px-8"
        >
          <div class="rotate-phone-icon-back">
            <span class="material-symbols-rounded text-6xl text-white/80">screen_rotation</span>
          </div>

          <div class="text-center">
            <p class="text-lg font-semibold text-white">Rotate Back</p>
            <p class="text-sm text-white/60 mt-2">Please rotate your device back to portrait to continue browsing.</p>
          </div>
        </div>
      </Transition>
    </Teleport>

    <!-- Presentation image preloading overlay -->
    <Teleport to="body">
      <transition name="fade">
        <div
          v-if="presPreloading"
          class="fixed inset-0 z-[99999] flex items-center justify-center bg-black/60 backdrop-blur-sm"
        >
          <div class="flex flex-col items-center gap-5 text-center px-8 py-8 max-w-sm rounded-3xl bg-[#1e1e2e]/90 shadow-2xl border border-white/10">
            <div class="w-14 h-14 rounded-2xl flex items-center justify-center bg-white/10 shadow-lg">
              <span class="material-symbols-rounded text-3xl text-white">slideshow</span>
            </div>
            <p class="text-white/80 text-sm font-medium">Preparing presentation...</p>
            <div class="w-56">
              <div class="h-1.5 rounded-full bg-white/10 overflow-hidden">
                <div
                  class="h-full rounded-full bg-white/80 transition-all duration-300 ease-out"
                  :style="{ width: presPreloadProgress + '%' }"
                />
              </div>
              <p class="text-[11px] text-white/40 mt-2 tabular-nums">
                {{ presPreloadLoaded }} / {{ presPreloadTotal }} images
              </p>
            </div>
          </div>
        </div>
      </transition>
    </Teleport>

    <!-- Presentation mode overlay -->
    <MoodPresenter
      v-if="store.presentationMode && currentBoard"
      :board="currentBoard"
      :canvas-ref="canvasRef"
      @exit="onPresentationExit"
    />

    <MobileBottomNav v-if="isMobile && !store.presentationMode" />

    <StepGuide
      v-if="showStepGuide"
      :title-key="moodBoardGuide.titleKey"
      :subtitle-key="moodBoardGuide.subtitleKey"
      :header-icon="moodBoardGuide.headerIcon"
      :header-color="moodBoardGuide.headerColor"
      :storage-key="moodBoardGuide.storageKey"
      :steps="moodBoardGuide.steps"
      @close="showStepGuide = false"
    />
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onUnmounted, nextTick } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useMoodBoardsStore } from '@/addons/moodboards/stores/moodBoards'
import { useMoodBoardGlobalStylesStore } from '@/addons/moodboards/stores/moodBoardGlobalStyles'
import { templateGlobalColors } from '@/addons/moodboards/components/templatePalette'
import { nextZIndexInScope } from '@/addons/moodboards/utils/layerOrderUtils'
import { useAuthStore } from '@/stores/auth'
import { useClientsStore } from '@/stores/clients'
import { useToastStore } from '@/stores/toast'
import { useAddons } from '@/composables/useAddons'
import clientTimeTracker from '@/addons/time-tracker/services/clientTimeTracker'
import AppHeader from '@/components/shared/AppHeader.vue'
import FeatureGuide from '@/components/shared/FeatureGuide.vue'
import HowItWorksButton from '@/components/shared/HowItWorksButton.vue'
import MobileBottomNav from '@/components/MobileBottomNav.vue'
import MoodBoardList from '../components/MoodBoardList.vue'
import MoodCanvas from '../components/MoodCanvas.vue'
import PixiCanvas from '../canvas/PixiCanvas.vue'
import MoodToolbar from '../components/MoodToolbar.vue'
import MoodDrivePicker from '../components/MoodDrivePicker.vue'
import MoodFolderBrowser from '../components/MoodFolderBrowser.vue'
import MoodCalendarPicker from '../components/MoodCalendarPicker.vue'
import MoodColorPickerModal from '../components/MoodColorPickerModal.vue'
import MoodExportPptxModal from '../components/MoodExportPptxModal.vue'
import MoodDrawingCanvas from '../components/MoodDrawingCanvas.vue'
import MoodBoardSettings from '../components/MoodBoardSettings.vue'
import MoodBoardPicker from '../components/MoodBoardPicker.vue'
import MoodFilePreview from '../components/MoodFilePreview.vue'
import MoodPresenter from '../components/MoodPresenter.vue'
import MoodFilmstrip from '../components/MoodFilmstrip.vue'
import MoodLeftSidebar from '../components/MoodLeftSidebar.vue'
import MoodRightSidebar from '../components/MoodRightSidebar.vue'
import MoodScrollStory from '../components/MoodScrollStory.vue'
import MoodCommentsPanel from '../components/MoodCommentsPanel.vue'
import MoodAIPanel from '../components/MoodAIPanel.vue'
import MoodCommentPopover from '../components/MoodCommentPopover.vue'
import { useMoodComments } from '@/addons/moodboards/composables/useMoodComments'
import { useImagePreloader } from '@/addons/moodboards/composables/useImagePreloader'
import { preloadBoardFonts } from '@/addons/moodboards/utils/fontLoader'
import { extractStyle, applyStyle } from '@/addons/moodboards/utils/styleTransfer'
import { useMailSyncSocket } from '@/services/mailSyncSocket'
import { featureGuides } from '@/data/featureGuides'
import StepGuide from '@/components/shared/StepGuide.vue'
import { moodBoardGuide } from '@/data/stepGuides'

const route = useRoute()
const router = useRouter()
const store = useMoodBoardsStore()
const authStore = useAuthStore()
const clientsStore = useClientsStore()
const toast = useToastStore()
const { kanbanBoardsEnabled } = useAddons()
const { preloading: presPreloading, preloadTotal: presPreloadTotal, preloadLoaded: presPreloadLoaded, preloadProgress: presPreloadProgress, preloadImages: presPreloadImages } = useImagePreloader()
const { preloading: boardPreloading, preloadTotal: boardPreloadTotal, preloadLoaded: boardPreloadLoaded, preloadProgress: boardPreloadProgress, preloadImages: boardPreloadImages } = useImagePreloader()

const {
  preloading: offlineSaving,
  cacheStatus: offlineCacheStatusRef,
  cachedImageCount: offlineCachedCountRef,
  saveBoardForOffline,
  checkBoardCacheStatus,
  clearBoardCache,
  cleanupLegacyMoodCacheServiceWorker,
} = useImagePreloader()
const offlineCacheStatus = computed(() => offlineCacheStatusRef.value)
const offlineCachedCount = computed(() => offlineCachedCountRef.value)

const showFeatureGuide = ref(false)
const showStepGuide = ref(false)
const guideData = featureGuides.moodboards

const canvasRef = ref(null)
// WebGL (Pixi) is the default renderer; DOM renderer is kept as an explicit fallback
const usePixiRenderer = ref(localStorage.getItem('canvasRenderer') !== 'dom')
function toggleRenderer() {
  usePixiRenderer.value = !usePixiRenderer.value
  localStorage.setItem('canvasRenderer', usePixiRenderer.value ? 'pixi' : 'dom')
}
/** WebGL canvas reported an unrecoverable error — fall back to the DOM renderer. */
function onRendererFallback() {
  usePixiRenderer.value = false
  localStorage.setItem('canvasRenderer', 'dom')
  toast.info('Switched to the DOM renderer due to a canvas error')
}
const fileInputRef = ref(null)
const showSidebar = ref(!route.params.id)
const leftSidebarCollapsed = ref(false)
const leftSidebarRef = ref(null)
const showBoardSettings = ref(false)
const settingsInitialTab = ref('general')
const showDrivePicker = ref(false)
const showCalendarPicker = ref(false)
const showBoardPicker = ref(false)
const showAIPanel = ref(false)
const showExportPptxModal = ref(false)
const showColorPicker = ref(false)
const colorPickerInitial = ref('#6366f1')
const colorPickerItemId = ref(null)
const colorPickerField = ref(null)
const isDrawMode = ref(false)
const isPenMode = ref(false)
const isLineMode = ref(false)
const isMeasureMode = ref(false)
const measureColor = ref('#0ea5e9')
const measureWidth = ref(1.5)
const measureVisible = ref(true)
const measureCount = ref(0)
const isSnapToGrid = ref(false)
const isSnapToCenter = ref(true)
const showDrawingCanvas = ref(false)
const editingDrawingItem = ref(null)
const editingDrawingData = ref(null)
const showFilePreview = ref(false)
const filePreviewItem = ref(null)
const showFolderBrowser = ref(false)
const folderBrowserId = ref(null)
const folderBrowserName = ref('')

// Presentation / follow-user state
const showPresenterNotesEditor = ref(false)
const presenterNotesText = ref('')
const presenterNotesItemId = ref(null)

const currentBoard = computed(() => store.currentBoard)

// Comments
const isPublicRef = ref(false)
const boardIdRef = computed(() => currentBoard.value?.id || null)
const comments = useMoodComments({ boardId: boardIdRef, isPublic: isPublicRef })
const commentPopoverPos = ref({ x: 0, y: 0 })
const isCommentMode = ref(false)
const showCommentPins = ref(true)
const showCommentMenu = ref(false)
const commentMenuRef = ref(null)
// Followed collaborator info
const followedCollab = computed(() => {
  if (!store.followingUser) return null
  return store.collaborators.find(c => c.email === store.followingUser) || null
})

// Preset colors for connection lines ('accent' is a special token resolved by MoodCanvas)
const connColorPresets = [
  { value: 'accent', label: 'Accent Color' },
  { value: '#666666', label: 'Grey (Default)' },
  { value: '#ef4444', label: 'Red' },
  { value: '#f97316', label: 'Orange' },
  { value: '#eab308', label: 'Yellow' },
  { value: '#22c55e', label: 'Green' },
  { value: '#3b82f6', label: 'Blue' },
  { value: '#8b5cf6', label: 'Purple' },
  { value: '#ec4899', label: 'Pink' },
  { value: '#ffffff', label: 'White' },
]

// Resolve the accent color for the color swatch display
const resolvedAccentColor = ref('#22c55e')
function updateResolvedAccent() {
  const style = getComputedStyle(document.documentElement)
  const rgb = style.getPropertyValue('--color-primary-500').trim()
  if (rgb) {
    const parts = rgb.split(/\s+/)
    if (parts.length === 3) {
      resolvedAccentColor.value = `rgb(${parts[0]}, ${parts[1]}, ${parts[2]})`
    }
  }
}

function connPresetBg(value) {
  return value === 'accent' ? resolvedAccentColor.value : value
}

// Board palette colors & gradients available in connection settings
const connBoardPaletteColors = computed(() => store.getColorPalette())
const connBoardPaletteGradients = computed(() => store.getGradientPalette())

function connGradientCSS(sg) {
  const stops = (sg.stops || []).map(s => `${s.color} ${s.position}%`).join(', ')
  if ((sg.type || 'linear') === 'radial') return `radial-gradient(circle, ${stops})`
  return `linear-gradient(${sg.angle || 135}deg, ${stops})`
}

function applyGradientToConn(sg) {
  if (!connContextMenu.value.conn) return
  const stops = sg.stops || []
  const startColor = stops[0]?.color || '#6366f1'
  const endColor = stops[stops.length - 1]?.color || '#8b5cf6'
  store.updateConnection(connContextMenu.value.conn.id, {
    gradient_enabled: 1,
    gradient_color_start: startColor,
    gradient_color_end: endColor,
  })
  connContextMenu.value.conn.gradient_enabled = 1
  connContextMenu.value.conn.gradient_color_start = startColor
  connContextMenu.value.conn.gradient_color_end = endColor
}

// EyeDropper API support
const hasEyeDropper = typeof window !== 'undefined' && 'EyeDropper' in window

async function pickColorFromScreen() {
  if (!hasEyeDropper) return
  try {
    const eyeDropper = new window.EyeDropper()
    const result = await eyeDropper.open()
    if (result?.sRGBHex) {
      updateConnColor(result.sRGBHex)
    }
  } catch (e) {
    // User cancelled the picker - that's fine
  }
}

// ========================================
// CONTEXT MENUS
// ========================================

const itemContextMenu = ref({ show: false, x: 0, y: 0, item: null })
const itemContextEl = ref(null)
const copiedStyle = ref(null)
const selectSimilarOpen = ref(false)
const selectSimilarTriggerEl = ref(null)
const selectSimilarFlyoutEl = ref(null)
const selectSimilarPos = ref({ x: 0, y: 0 })
const ITEM_CONTEXT_WIDTH = 200

function onItemContext(e, item) {
  const vw = window.innerWidth
  const vh = window.innerHeight
  const menuH = itemContextEl.value?.offsetHeight || 400
  const x = Math.max(8, Math.min(vw - ITEM_CONTEXT_WIDTH - 8, e.clientX))
  const y = Math.max(8, Math.min(vh - menuH - 8, e.clientY))
  itemContextMenu.value = { show: true, x, y, item }
  nextTick(() => {
    if (!itemContextEl.value) return
    const h = itemContextEl.value.offsetHeight
    const w = itemContextEl.value.offsetWidth
    itemContextMenu.value.x = Math.max(8, Math.min(vw - w - 8, itemContextMenu.value.x))
    itemContextMenu.value.y = Math.max(8, Math.min(vh - h - 8, itemContextMenu.value.y))
  })
}

function closeItemContext() {
  itemContextMenu.value.show = false
  connContextMenu.value.show = false
  selectSimilarOpen.value = false
}

function toggleSelectSimilar() {
  selectSimilarOpen.value = !selectSimilarOpen.value
  if (!selectSimilarOpen.value) return
  nextTick(() => {
    const trigger = selectSimilarTriggerEl.value
    const flyout = selectSimilarFlyoutEl.value
    if (!trigger || !flyout) return
    const triggerRect = trigger.getBoundingClientRect()
    const flyoutH = flyout.offsetHeight
    const flyoutW = flyout.offsetWidth
    const vh = window.innerHeight
    const vw = window.innerWidth
    let x = triggerRect.right + 4
    let y = triggerRect.top
    if (y + flyoutH > vh - 8) y = vh - flyoutH - 8
    if (y < 8) y = 8
    if (x + flyoutW > vw - 8) x = triggerRect.left - flyoutW - 4
    selectSimilarPos.value = { x, y }
  })
}

const selectSimilarOptions = [
  { criteria: 'type',          icon: 'category',          label: 'Same Type' },
  { criteria: 'size',          icon: 'aspect_ratio',      label: 'Same Size' },
  { criteria: 'fill-color',    icon: 'format_color_fill', label: 'Fill Color',    show: i => i?.type === 'shape' },
  { criteria: 'border-color',  icon: 'border_color',      label: 'Border Color',  show: i => i?.style_data?.shape_border_color },
  { criteria: 'border-width',  icon: 'line_weight',       label: 'Border Width',  show: i => i?.style_data?.shape_border_width },
  { criteria: 'border-radius', icon: 'rounded_corner',    label: 'Border Radius', show: i => i?.type === 'shape' },
  { criteria: 'opacity',       icon: 'opacity',           label: 'Opacity',       show: i => i?.type === 'shape' },
  { criteria: 'font-size',     icon: 'format_size',       label: 'Font Size',     show: i => i?.type === 'text' },
  { criteria: 'font-family',   icon: 'font_download',     label: 'Font Family',   show: i => i?.type === 'text' },
  { criteria: 'font-weight',   icon: 'format_bold',       label: 'Font Weight',   show: i => i?.type === 'text' },
  { criteria: 'text-color',    icon: 'format_color_text', label: 'Text Color',    show: i => i?.type === 'text' },
]

function handleBringToFront() {
  if (itemContextMenu.value.item) store.bringToFront(itemContextMenu.value.item.id)
  closeItemContext()
}

function handleSendToBack() {
  if (itemContextMenu.value.item) store.sendToBack(itemContextMenu.value.item.id)
  closeItemContext()
}

function handleMoveForward() {
  if (itemContextMenu.value.item) store.moveForward(itemContextMenu.value.item.id)
  closeItemContext()
}

function handleMoveBackward() {
  if (itemContextMenu.value.item) store.moveBackward(itemContextMenu.value.item.id)
  closeItemContext()
}

function handleToggleLock() {
  if (itemContextMenu.value.item) {
    store.updateItem(itemContextMenu.value.item.id, { locked: itemContextMenu.value.item.locked ? 0 : 1 })
  }
  closeItemContext()
}

async function handleConvertToFrame() {
  trackMoodBoardEdit()
  // If only one item is right-clicked and not selected, select it first
  if (itemContextMenu.value.item && !store.selectedItemIds.has(itemContextMenu.value.item.id)) {
    store.selectItem(itemContextMenu.value.item.id)
  }
  await store.convertToFrame()
  closeItemContext()
}

function handleDeleteItem() {
  trackMoodBoardEdit()
  if (itemContextMenu.value.item) store.deleteItem(itemContextMenu.value.item.id)
  closeItemContext()
}

async function handleDetachComponent() {
  const item = itemContextMenu.value.item
  if (item?.component_instance_id) {
    await store.detachComponentInstance(item.component_instance_id)
  }
  closeItemContext()
}

function handleSelectAllInstances() {
  const item = itemContextMenu.value.item
  if (item?.component_instance_id) {
    const instanceItems = store.getInstanceItems(item.component_instance_id)
    store.selectedItemIds = new Set(instanceItems.map(i => i.id))
  }
  closeItemContext()
}

// ========================================
// PRESENTATION HANDLERS
// ========================================

function savePresenterNotes() {
  if (presenterNotesItemId.value) {
    store.updateItem(presenterNotesItemId.value, { presenter_notes: presenterNotesText.value || null })
  }
  showPresenterNotesEditor.value = false
  presenterNotesText.value = ''
  presenterNotesItemId.value = null
}

async function onStartPresentation(startIndex = 0) {
  if (store.presentationSlides.length === 0) {
    toast.show('No slides yet. Add slides to the board first.', 'info')
    return
  }

  // Request fullscreen SYNCHRONOUSLY in the click handler before any await.
  if (document.documentElement.requestFullscreen) {
    document.documentElement.requestFullscreen().catch(() => {})
  }

  // Preload all images with a visible loading screen before starting
  await presPreloadImages(currentBoard.value)

  store.startPresentation(startIndex)
}

async function onSaveOffline() {
  if (!currentBoard.value) return
  toast.show('Saving board for offline use...', 'info')
  await saveBoardForOffline(currentBoard.value)
  toast.show(`Board saved for offline! ${offlineCachedCountRef.value} images cached.`, 'success')
}

async function onClearOfflineCache() {
  if (!currentBoard.value) return
  await clearBoardCache(currentBoard.value.id)
  toast.show('Offline cache cleared.', 'info')
}

function onPresentationExit() {
  if (isMobile.value) {
    router.push('/mood')
  }
}

async function onExportPresentation() {
  if (!currentBoard.value?.id) return
  if (store.presentationSlides.length === 0) {
    toast.show('No slides yet. Add slides to the board first.', 'info')
    return
  }
  toast.show('Generating presentation file...', 'info')
  const ok = await store.exportPresentation(currentBoard.value.id)
  if (ok) {
    toast.show('Presentation exported', 'success')
  } else {
    toast.show('Failed to export presentation', 'error')
  }
}

function onExportPptx() {
  if (!currentBoard.value?.id) return
  showExportPptxModal.value = true
}

async function onExportPptxConfirm(selectedSlideIds) {
  showExportPptxModal.value = false
  if (!currentBoard.value?.id) return
  toast.show('Generating PowerPoint file...', 'info')
  const ok = await store.exportPptx(currentBoard.value.id, selectedSlideIds)
  if (ok) {
    toast.show('PowerPoint exported', 'success')
  } else {
    toast.show('Failed to export PowerPoint', 'error')
  }
}

async function onExportPdf() {
  if (!currentBoard.value?.id) return
  toast.show('Preparing PDF print preview...', 'info')
  const ok = await store.exportPdf(currentBoard.value.id)
  if (ok) {
    toast.show('PDF print preview opened', 'success')
  } else {
    toast.show('Failed to export PDF', 'error')
  }
}

// ========================================
// SCROLL STORY MODE
// ========================================

function startScrollStory() {
  if (store.presentationSlides.length === 0) {
    toast.show('No slides yet. Add slides to the board first.', 'info')
    return
  }
  store.scrollStoryMode = true
}

function exitScrollStory() {
  store.scrollStoryMode = false
}

function onScrollStoryPan({ frame, nextFrame, fraction }) {
  if (!canvasRef.value) return

  // Interpolate between current frame center and next frame center
  const fx = frame.pos_x + (frame.width || 480) / 2
  const fy = frame.pos_y + (frame.height || 270) / 2

  let targetX = fx
  let targetY = fy

  if (nextFrame && fraction > 0) {
    const nx = nextFrame.pos_x + (nextFrame.width || 480) / 2
    const ny = nextFrame.pos_y + (nextFrame.height || 270) / 2
    // Ease the fraction
    const t = fraction < 0.5 ? 2 * fraction * fraction : 1 - Math.pow(-2 * fraction + 2, 2) / 2
    targetX = fx + (nx - fx) * t
    targetY = fy + (ny - fy) * t
  }

  // Calculate zoom to fit frame in viewport
  const container = canvasRef.value?.$el || canvasRef.value?.canvasContainer
  const rect = container?.getBoundingClientRect?.()
  const viewW = rect?.width || window.innerWidth
  const viewH = rect?.height || window.innerHeight
  const frameW = frame.width || 480
  const frameH = frame.height || 270
  const fitZoom = Math.min(viewW / (frameW + 80), viewH / (frameH + 80))

  // Set pan + zoom directly (no animation — scroll is driving the motion)
  store.zoom = fitZoom
  store.panX = (viewW / 2) - targetX * fitZoom
  store.panY = (viewH / 2) - targetY * fitZoom
}

function onFlyToSlide(slide) {
  if (canvasRef.value && slide) {
    canvasRef.value.animateToFrame(slide, 500, 'fly')
    store.focusedSlideId = slide.id
  }
}

function onFlyToItem(item) {
  if (canvasRef.value && item) {
    // Use animateToFrame which works for any item with pos/size
    canvasRef.value.animateToFrame(item, 400, 'fly')
    store.selectItem(item.id, false)
  }
}

// Insert a new slide at a specific position in the filmstrip
async function onInsertSlideAt(insertIndex) {
  // Place the new slide at the center of the current viewport
  const container = canvasRef.value?.$el || canvasRef.value?.canvasContainer
  const rect = container?.getBoundingClientRect?.()
  const viewW = rect?.width || window.innerWidth
  const viewH = rect?.height || window.innerHeight
  const centerX = (-store.panX + viewW / 2) / store.zoom - 240  // half of 480 slide width
  const centerY = (-store.panY + viewH / 2) / store.zoom - 135  // half of 270 slide height

  // Create the slide with slide_order = insertIndex
  const newItem = await store.addItem({
    type: 'slide',
    pos_x: Math.round(centerX),
    pos_y: Math.round(centerY),
    width: 480,
    height: 270,
    title: 'Slide',
    slide_order: insertIndex,
  })

  if (!newItem) return

  // Bump slide_order for all slides that were at or after the insert position
  const slides = store.presentationSlides
  for (const s of slides) {
    if (s.id !== newItem.id && (s.slide_order ?? 0) >= insertIndex) {
      store.updateItem(s.id, { slide_order: (s.slide_order ?? 0) + 1 })
    }
  }

  // Focus the new slide
  store.focusedSlideId = newItem.id
}

// ========================================
// COMPONENT PLACEMENT
// ========================================

async function onPlaceComponent(comp) {
  if (!comp.items_data?.length || !store.currentBoard) return

  const container = canvasRef.value?.$el || canvasRef.value?.canvasContainer
  const rect = container?.getBoundingClientRect?.()
  const viewW = rect?.width || window.innerWidth
  const viewH = rect?.height || window.innerHeight
  const baseX = Math.round((-store.panX + viewW / 2) / store.zoom - 120)
  const baseY = Math.round((-store.panY + viewH / 2) / store.zoom - 60)

  const baseZ = nextZIndexInScope(store.currentBoard.items || [], { parentId: null, lane: 'content' })

  const instanceId = comp.id
    ? `${comp.id}-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`
    : null

  const numericCompId = typeof comp.id === 'number' ? comp.id : null

  const itemsData = comp.items_data.map((source, i) => {
    const payload = {
      ...source,
      pos_x: baseX + (source.pos_x || 0),
      pos_y: baseY + (source.pos_y || 0),
      z_index: baseZ + i,
      component_id: numericCompId,
      component_instance_id: numericCompId ? instanceId : null,
      component_item_index: numericCompId ? i : null,
    }
    delete payload.todos
    delete payload.images
    return payload
  })

  const newIds = await store.batchAddItems(itemsData)

  if (newIds.length) {
    store.selectedItemIds = new Set(newIds)
  }

  if (typeof comp.id === 'string') {
    const gsStore = useMoodBoardGlobalStylesStore()
    gsStore.ensureTemplateGlobals(templateGlobalColors)
  }
}

async function onEditComponentItems(comp) {
  if (!comp.items_data?.length || !store.currentBoard) return

  const container = canvasRef.value?.$el || canvasRef.value?.canvasContainer
  const rect = container?.getBoundingClientRect?.()
  const viewW = rect?.width || window.innerWidth
  const viewH = rect?.height || window.innerHeight
  const baseX = Math.round((-store.panX + viewW / 2) / store.zoom - 120)
  const baseY = Math.round((-store.panY + viewH / 2) / store.zoom - 60)

  const baseZ = nextZIndexInScope(store.currentBoard.items || [], { parentId: null, lane: 'content' })

  const itemsData = comp.items_data.map((source, i) => {
    const payload = { ...source, pos_x: baseX + (source.pos_x || 0), pos_y: baseY + (source.pos_y || 0), z_index: baseZ + i }
    delete payload.todos
    delete payload.images
    return payload
  })

  const newIds = await store.batchAddItems(itemsData)
  if (newIds.length) {
    store.selectedItemIds = new Set(newIds)
  }
}

// ========================================
// FOLLOW USER
// ========================================

function toggleFollowUser(email) {
  if (store.followingUser === email) {
    store.stopFollowing()
  } else {
    store.startFollowing(email)
  }
}

function toggleFollowMode() {
  store.followMode = store.followMode === 'cursor' ? 'viewport' : 'cursor'
}

function toggleFollowViewport(email) {
  if (store.followingUser === email && store.followMode === 'viewport') {
    store.stopFollowing()
  } else {
    store.startFollowing(email, 'viewport')
  }
}

function handleFocusItem() {
  if (itemContextMenu.value.item) {
    store.toggleFocusItem(itemContextMenu.value.item.id)
  }
  closeItemContext()
}

function handleCopyItem() {
  if (itemContextMenu.value.item) {
    // Ensure the item is selected so copySelectedItems picks it up
    store.selectItem(itemContextMenu.value.item.id)
    const count = store.copySelectedItems()
    if (count) toast.show(`Copied ${count} item${count > 1 ? 's' : ''}`, 'success')
  }
  closeItemContext()
}

async function handleDuplicateItem() {
  if (itemContextMenu.value.item) {
    store.selectItem(itemContextMenu.value.item.id)
    await store.duplicateSelectedItems(30, 30)
  }
  closeItemContext()
}

function handleCopyStyle() {
  const item = itemContextMenu.value.item
  if (!item) return
  copiedStyle.value = extractStyle(item)
  if (copiedStyle.value) {
    toast.show('Style copied', 'success')
  }
  closeItemContext()
}

function handlePasteStyle() {
  if (!copiedStyle.value) return
  const item = itemContextMenu.value.item
  if (item) {
    applyStyle(item, copiedStyle.value, (id, data) => store.updateItem(id, data))
  }
  if (store.selectedItemIds.size > 1) {
    for (const selId of store.selectedItemIds) {
      if (selId === item?.id) continue
      const target = store.currentItems.find(i => i.id === selId)
      if (target) {
        applyStyle(target, copiedStyle.value, (id, data) => store.updateItem(id, data))
      }
    }
  }
  toast.show('Style applied', 'success')
  closeItemContext()
}

function handleCreateMask() {
  store.maskSelectedItems()
  closeItemContext()
}

function handleReleaseMask() {
  const item = itemContextMenu.value.item
  if (item) store.unmaskItems(item.id)
  closeItemContext()
}

function handleRotate(degrees) {
  const item = itemContextMenu.value.item
  if (!item) return
  const current = item.rotation || 0
  const newRotation = ((current + degrees) % 360 + 360) % 360
  store.updateItem(item.id, { rotation: newRotation })
  closeItemContext()
}

function handleResetRotation() {
  const item = itemContextMenu.value.item
  if (!item) return
  store.updateItem(item.id, { rotation: 0 })
  closeItemContext()
}

const drawingRecolorPresets = [
  '#1e293b', '#ef4444', '#f97316', '#eab308',
  '#22c55e', '#3b82f6', '#8b5cf6', '#ec4899', '#ffffff'
]

const blendModesList = [
  'normal', 'multiply', 'screen', 'overlay', 'darken', 'lighten',
  'color-dodge', 'color-burn', 'hard-light', 'soft-light',
  'difference', 'exclusion', 'hue', 'saturation', 'color', 'luminosity'
]

function applyBlendMode(mode) {
  const item = itemContextMenu.value.item
  if (!item) return
  const sd = { ...(item.style_data || {}), blend_mode: mode }
  store.updateItem(item.id, { style_data: sd })
  closeItemContext()
}

/**
 * Recolor all strokes in a drawing item to a new color.
 * Updates the content JSON and the item's color field.
 */
function removeShapeMaskFromContext() {
  const item = itemContextMenu.value.item
  if (!item) return
  const sd = { ...(item.style_data || {}) }
  delete sd.mask_image_url
  delete sd.mask_image_fit
  store.updateItem(item.id, { style_data: sd })
  closeItemContext()
}

async function addShapeMaskFromContext(e) {
  const item = itemContextMenu.value.item
  if (!item) return
  const file = e.target?.files?.[0]
  if (!file || !file.type.startsWith('image/')) return
  try {
    const uploaded = await store.uploadFiles([file])
    if (uploaded[0]) {
      store.updateItem(item.id, {
        style_data: {
          ...(item.style_data || {}),
          mask_image_url: uploaded[0].url,
          mask_image_fit: 'cover'
        }
      })
    }
  } catch (err) {
    console.error('Shape mask upload failed:', err)
  }
  closeItemContext()
}

function recolorDrawing(newColor) {
  const item = itemContextMenu.value.item
  if (!item || item.type !== 'drawing') return
  
  try {
    const data = typeof item.content === 'string' ? JSON.parse(item.content) : item.content
    if (data?.strokes) {
      for (const stroke of data.strokes) {
        stroke.color = newColor
      }
      store.updateItem(item.id, {
        content: JSON.stringify(data),
        color: newColor
      })
    }
  } catch (e) {
    console.error('Failed to recolor drawing:', e)
  }
}

function onSelectConnection(conn) {
  // Left click only selects the connection and reveals its handles.
  // The settings panel remains a right-click context menu.
  if (connContextMenu.value.show) closeConnContext()
}

const connContextMenu = ref({ show: false, x: 0, y: 0, conn: null })
const connLabelInput = ref(null)
const connPanelEl = ref(null)
const CONN_PANEL_WIDTH = 360

function clampConnPanelPosition(x, y) {
  const vw = window.innerWidth
  const vh = window.innerHeight
  // Estimate panel height; after mount we can use actual height
  const panelH = connPanelEl.value?.offsetHeight || 600
  return {
    x: Math.max(8, Math.min(vw - CONN_PANEL_WIDTH - 8, x)),
    y: Math.max(8, Math.min(vh - panelH - 8, y)),
  }
}

function getConnPanelPreferredX() {
  const pct = (currentBoard.value?.conn_panel_position ?? 70) / 100
  return window.innerWidth * pct - 120
}

function onConnectionContext(e, conn) {
  const clamped = clampConnPanelPosition(getConnPanelPreferredX(), e.clientY)
  connContextMenu.value = { show: true, x: clamped.x, y: clamped.y, conn }
  // Re-clamp after render when actual panel height is known
  nextTick(() => {
    const c = clampConnPanelPosition(connContextMenu.value.x, connContextMenu.value.y)
    connContextMenu.value.x = c.x
    connContextMenu.value.y = c.y
  })
}

function closeConnContext() {
  flushConnPersist()
  connContextMenu.value.show = false
}

// --- Draggable connection settings panel ---
const connPanelDrag = ref(null) // { offsetX, offsetY } while dragging

function onConnPanelDragStart(e) {
  // Only left button
  if (e.button !== 0) return
  e.preventDefault()
  connPanelDrag.value = {
    offsetX: e.clientX - connContextMenu.value.x,
    offsetY: e.clientY - connContextMenu.value.y,
  }
  window.addEventListener('mousemove', onConnPanelDragMove)
  window.addEventListener('mouseup', onConnPanelDragEnd)
}

function onConnPanelDragMove(e) {
  if (!connPanelDrag.value) return
  const raw = clampConnPanelPosition(
    e.clientX - connPanelDrag.value.offsetX,
    e.clientY - connPanelDrag.value.offsetY
  )
  connContextMenu.value.x = raw.x
  connContextMenu.value.y = raw.y
}

function onConnPanelDragEnd() {
  connPanelDrag.value = null
  window.removeEventListener('mousemove', onConnPanelDragMove)
  window.removeEventListener('mouseup', onConnPanelDragEnd)
}

// Debounced connection persist — batches rapid slider changes into a single API call
let _connPersistTimer = null
let _connPersistQueue = {}
let _connPersistId = null
function debouncedConnUpdate(connId, data) {
  if (_connPersistId !== connId) {
    // Different connection — flush previous
    if (_connPersistTimer) { clearTimeout(_connPersistTimer); flushConnPersist() }
    _connPersistId = connId
    _connPersistQueue = {}
  }
  Object.assign(_connPersistQueue, data)
  if (_connPersistTimer) clearTimeout(_connPersistTimer)
  _connPersistTimer = setTimeout(flushConnPersist, 300)
}
function flushConnPersist() {
  _connPersistTimer = null
  if (_connPersistId && Object.keys(_connPersistQueue).length) {
    store.updateConnection(_connPersistId, { ..._connPersistQueue })
  }
  _connPersistQueue = {}
  _connPersistId = null
}

function updateConnColor(color) {
  if (!connContextMenu.value.conn) return
  connContextMenu.value.conn.line_color = color
  debouncedConnUpdate(connContextMenu.value.conn.id, { line_color: color })
}

function updateConnStyle(style) {
  if (!connContextMenu.value.conn) return
  store.updateConnection(connContextMenu.value.conn.id, { line_style: style })
  connContextMenu.value.conn.line_style = style
}

function updateConnWidth(width) {
  if (!connContextMenu.value.conn) return
  connContextMenu.value.conn.line_width = width
  debouncedConnUpdate(connContextMenu.value.conn.id, { line_width: width })
}

function toggleConnArrowEnd() {
  if (!connContextMenu.value.conn) return
  const newVal = connContextMenu.value.conn.arrow_end ? 0 : 1
  store.updateConnection(connContextMenu.value.conn.id, { arrow_end: newVal })
  connContextMenu.value.conn.arrow_end = newVal
}

function toggleConnArrowStart() {
  if (!connContextMenu.value.conn) return
  const newVal = connContextMenu.value.conn.arrow_start ? 0 : 1
  store.updateConnection(connContextMenu.value.conn.id, { arrow_start: newVal })
  connContextMenu.value.conn.arrow_start = newVal
}

function toggleConnGlow() {
  if (!connContextMenu.value.conn) return
  const enabled = connContextMenu.value.conn.glow_enabled ? 0 : 1
  const updates = { glow_enabled: enabled }
  if (enabled) {
    // Set defaults when enabling
    updates.glow_color = connContextMenu.value.conn.glow_color || connContextMenu.value.conn.line_color || '#6366f1'
    updates.glow_opacity = connContextMenu.value.conn.glow_opacity ?? 60
    updates.glow_blur = connContextMenu.value.conn.glow_blur ?? 6
  }
  store.updateConnection(connContextMenu.value.conn.id, updates)
  Object.assign(connContextMenu.value.conn, updates)
}

function updateConnGlowProp(prop, value) {
  if (!connContextMenu.value.conn) return
  connContextMenu.value.conn[prop] = value
  debouncedConnUpdate(connContextMenu.value.conn.id, { [prop]: value })
}

function toggleConnGradient() {
  if (!connContextMenu.value.conn) return
  const enabled = connContextMenu.value.conn.gradient_enabled ? 0 : 1
  const updates = { gradient_enabled: enabled }
  if (enabled) {
    // Set defaults when enabling — start = current line color, end = purple
    updates.gradient_color_start = connContextMenu.value.conn.gradient_color_start || connContextMenu.value.conn.line_color || '#6366f1'
    updates.gradient_color_end = connContextMenu.value.conn.gradient_color_end || '#8b5cf6'
  }
  store.updateConnection(connContextMenu.value.conn.id, updates)
  Object.assign(connContextMenu.value.conn, updates)
}

function updateConnGradientProp(prop, value) {
  if (!connContextMenu.value.conn) return
  connContextMenu.value.conn[prop] = value
  debouncedConnUpdate(connContextMenu.value.conn.id, { [prop]: value })
}

function swapConnGradientColors() {
  if (!connContextMenu.value.conn) return
  const startColor = connContextMenu.value.conn.gradient_color_start || connContextMenu.value.conn.line_color || '#6366f1'
  const endColor = connContextMenu.value.conn.gradient_color_end || '#8b5cf6'
  const updates = { gradient_color_start: endColor, gradient_color_end: startColor }
  store.updateConnection(connContextMenu.value.conn.id, updates)
  Object.assign(connContextMenu.value.conn, updates)
}

function commitConnLabel(label) {
  if (!connContextMenu.value.conn) return
  const trimmed = label.trim()
  if (trimmed === (connContextMenu.value.conn.label || '')) return
  store.updateConnection(connContextMenu.value.conn.id, { label: trimmed })
  connContextMenu.value.conn.label = trimmed
}

function deleteConnFromContext() {
  if (!connContextMenu.value.conn) return
  store.deleteConnection(connContextMenu.value.conn.id)
  closeConnContext()
}

function toggleConnRenderAbove() {
  if (!connContextMenu.value.conn) return
  const newVal = connContextMenu.value.conn.render_above ? 0 : 1
  store.updateConnection(connContextMenu.value.conn.id, { render_above: newVal })
  connContextMenu.value.conn.render_above = newVal
}

function resetConnAnchors() {
  if (!connContextMenu.value.conn) return
  canvasRef.value?.resetConnectionAnchors(connContextMenu.value.conn)
  closeConnContext()
}

// ========================================
// NAVIGATION
// ========================================

function onHeaderIconClick() {
  if (currentBoard.value) {
    store.currentBoard = null
    router.push('/mood')
  }
}

async function handleToggleReady() {
  if (!currentBoard.value) return
  const result = await store.toggleReady(currentBoard.value.id)
  if (result) {
    toast.success(result.is_ready ? 'Board marked as ready' : 'Board unmarked as ready')
  } else {
    toast.error('Failed to toggle ready state')
  }
}

async function openBoard(id) {
  await router.push(`/mood/${id}`)
  showSidebar.value = false
}

function toggleConnect() {
  if (store.connectingFrom) {
    store.connectingFrom = null
  } else {
    // User needs to click an item to start connecting
    toast.show('Click an item to start a connection', 'info')
    store.connectingFrom = -1 // sentinel: "ready to connect" mode
  }
}

const _boardFieldTimers = {}

function updateBoardField(field, value) {
  if (!currentBoard.value) return

  currentBoard.value[field] = value

  clearTimeout(_boardFieldTimers[field])
  _boardFieldTimers[field] = setTimeout(() => {
    if (!currentBoard.value) return
    store.updateBoard(currentBoard.value.id, { [field]: value })
  }, 300)
}

// ========================================
// TOOLBAR ADD ITEM
// ========================================

function onAddItem(type, extraStyleData) {
  trackMoodBoardEdit()
  if (type === 'color_swatch') {
    // Open color picker to choose color first
    colorPickerInitial.value = '#6366f1'
    colorPickerItemId.value = null
    showColorPicker.value = true
    return
  }
  canvasRef.value?.addItemFromToolbar(type, extraStyleData)
}

// ========================================
// CLIENT PROMPT (shown on first upload if no client set)
// ========================================

const showClientPrompt = ref(false)
const clientPromptSearch = ref('')
const clientPromptCallback = ref(null)
const clientPromptDismissed = ref(false) // Skip for current session per board

const filteredPromptClients = computed(() => {
  const q = clientPromptSearch.value.toLowerCase().trim()
  const list = clientsStore.clients || []
  if (!q) return list.slice(0, 20)
  return list.filter(c =>
    (c.display_name || '').toLowerCase().includes(q) ||
    (c.domain || '').toLowerCase().includes(q)
  ).slice(0, 20)
})

/**
 * Check if client is set — if not, prompt the user.
 * Returns a promise that resolves when ready to proceed.
 */
function ensureClientOrSkip() {
  return new Promise((resolve) => {
    const board = currentBoard.value
    if (!board || board.client_id || clientPromptDismissed.value) {
      resolve()
      return
    }
    // Load clients if not loaded
    if (!clientsStore.clients?.length) {
      clientsStore.fetchClients()
    }
    clientPromptCallback.value = resolve
    showClientPrompt.value = true
  })
}

async function onClientPromptSelect(client) {
  if (currentBoard.value) {
    await store.linkToClient(client.id, currentBoard.value.id)
    // Refresh board data
    currentBoard.value.client_id = client.id
    currentBoard.value.client = client
    toast.success(`Linked to ${client.display_name || client.domain}`)
    // Refresh mood board mappings and start tracking for this client
    clientTimeTracker.refreshMappings()
    trackMoodBoardEdit()
  }
  showClientPrompt.value = false
  clientPromptCallback.value?.()
  clientPromptCallback.value = null
}

function onClientPromptSkip() {
  clientPromptDismissed.value = true
  showClientPrompt.value = false
  clientPromptCallback.value?.()
  clientPromptCallback.value = null
}

// Reset dismissed flag when switching boards
watch(currentBoard, () => {
  clientPromptDismissed.value = false
})

// Preload Google Fonts used by items on the current board
watch(currentBoard, (board) => {
  if (board?.items?.length) {
    preloadBoardFonts(board.items)
  }
  if (board?.id) {
    store.fetchDesignTokens()
    checkBoardCacheStatus(board)
  }
}, { immediate: true })

let _cacheCheckDebounce = null
watch(() => store.currentBoard?.items?.length, () => {
  if (!currentBoard.value?.id) return
  clearTimeout(_cacheCheckDebounce)
  _cacheCheckDebounce = setTimeout(() => {
    checkBoardCacheStatus(currentBoard.value)
  }, 2000)
})

// ========================================
// COMMENTS
// ========================================

async function onAddComment(data) {
  const result = await comments.addComment(data)
  if (result) {
    broadcastCommentEvent('MOOD_BOARD_COMMENT_BROADCAST', { boardId: currentBoard.value?.id, comment: result })
  } else {
    toast.show('Failed to add comment', 'error')
  }
}

async function onDeleteComment(commentId) {
  const ok = await comments.deleteComment(commentId)
  if (ok) {
    broadcastCommentEvent('MOOD_BOARD_COMMENT_DELETE_BROADCAST', { boardId: currentBoard.value?.id, commentId })
  } else {
    toast.show('Failed to delete comment', 'error')
  }
}

async function onResolveThread(threadId) {
  const ok = await comments.resolveThread(threadId)
  if (ok) {
    broadcastCommentEvent('MOOD_BOARD_THREAD_RESOLVE_BROADCAST', { boardId: currentBoard.value?.id, threadId, resolved: true })
  } else {
    toast.show('Failed to resolve thread', 'error')
  }
}

async function onUnresolveThread(threadId) {
  const ok = await comments.unresolveThread(threadId)
  if (ok) {
    broadcastCommentEvent('MOOD_BOARD_THREAD_RESOLVE_BROADCAST', { boardId: currentBoard.value?.id, threadId, resolved: false })
  } else {
    toast.show('Failed to re-open thread', 'error')
  }
}

function onSelectThread(thread) {
  comments.activeThreadId.value = thread.thread_id
}

function onCommentItem({ itemId, screenX, screenY }) {
  commentPopoverPos.value = { x: screenX, y: screenY }
  comments.startCommentOnItem(itemId)
}

function handleCommentOnItem() {
  const item = itemContextMenu.value.item
  if (!item) { closeItemContext(); return }
  const x = itemContextMenu.value.x
  const y = itemContextMenu.value.y
  closeItemContext()
  commentPopoverPos.value = { x, y }
  comments.startCommentOnItem(item.id)
}

function toggleCommentMode() {
  isCommentMode.value = !isCommentMode.value
  if (isCommentMode.value) {
    comments.cancelCommentOnItem()
  }
}

function onCommentCanvas({ canvasX, canvasY, screenX, screenY }) {
  commentPopoverPos.value = { x: screenX, y: screenY }
  comments.startCommentOnCanvas({ canvasX, canvasY, screenX, screenY })
}

function toggleCommentsPanel() {
  comments.showCommentsPanel.value = !comments.showCommentsPanel.value
  if (comments.showCommentsPanel.value) {
    comments.activeThreadId.value = null
  }
}

async function toggleCommentNotify() {
  if (!currentBoard.value) return
  const newVal = currentBoard.value.notify_on_comment === false ? 1 : 0
  await store.updateBoard(currentBoard.value.id, { notify_on_comment: newVal })
  currentBoard.value.notify_on_comment = !!newVal
}

function onSelectCommentThread(thread) {
  comments.activeThreadId.value = thread.thread_id
  comments.showCommentsPanel.value = true
}

async function onDeleteCommentThread(threadOrId) {
  const threadId = typeof threadOrId === 'string' ? threadOrId : threadOrId?.thread_id
  if (!threadId) return
  const ok = await comments.deleteThread(threadId)
  if (ok) {
    broadcastCommentEvent('MOOD_BOARD_THREAD_DELETED', { boardId: currentBoard.value?.id, threadId })
    if (comments.activeThreadId.value === threadId) {
      comments.activeThreadId.value = null
    }
  } else {
    toast.show('Failed to delete thread', 'error')
  }
}

function onFocusThread(thread) {
  if (thread.pin_x != null && thread.pin_y != null) {
    const container = canvasRef.value?.$el || canvasRef.value
    if (!container) return
    const rect = container.getBoundingClientRect()
    const targetX = -(thread.pin_x * store.zoom) + rect.width / 2
    const targetY = -(thread.pin_y * store.zoom) + rect.height / 2
    store.panX = targetX
    store.panY = targetY
  }
  comments.activeThreadId.value = thread.thread_id
}

function broadcastCommentEvent(type, payload) {
  const socket = useMailSyncSocket()
  socket.send({ type, ...payload })
}

async function onPopoverSubmit(data) {
  const result = await comments.addComment(data)
  if (result) {
    broadcastCommentEvent('MOOD_BOARD_COMMENT_BROADCAST', { boardId: currentBoard.value?.id, comment: result })
    comments.cancelCommentOnItem()
    if (isCommentMode.value && !comments.showCommentsPanel.value) {
      comments.showCommentsPanel.value = true
    }
    comments.activeThreadId.value = result.thread_id
  } else {
    toast.show('Failed to add comment', 'error')
  }
}

// Load comments when board is fetched
watch(currentBoard, (board) => {
  if (board?.id) {
    comments.fetchComments().catch(e => {
      console.warn('[MoodBoard] Failed to load comments:', e)
    })
  } else {
    comments.$reset()
  }
})

// Wire up real-time comment events from WebSocket
let _unsubCommentEvents = null
watch(currentBoard, (board) => {
  if (_unsubCommentEvents) { _unsubCommentEvents(); _unsubCommentEvents = null }
  if (board?.id) {
    _unsubCommentEvents = store.onCommentEvent((type, payload) => {
      if (type === 'comment_added' && payload.comment) {
        comments.handleRealtimeComment(payload.comment)
      } else if (type === 'comment_deleted' && payload.comment_id) {
        comments.deleteComment(payload.comment_id)
      } else if (type === 'thread_deleted' && payload.thread_id) {
        comments.handleRealtimeThreadDelete(payload.thread_id)
      } else if (type === 'thread_resolved') {
        comments.handleRealtimeResolve(payload)
      }
    })
  }
})

// ========================================
// FILE UPLOAD
// ========================================

async function triggerFileUpload() {
  await ensureClientOrSkip()
  fileInputRef.value?.click()
}

function onFileInputChange(e) {
  const files = e.target.files
  if (files?.length) {
    trackMoodBoardEdit()
    canvasRef.value?.handleFileUpload(files)
  }
  // Reset input so same file can be picked again
  e.target.value = ''
}

async function openDrivePickerWithClientCheck() {
  await ensureClientOrSkip()
  showDrivePicker.value = true
}

// ========================================
// DRIVE PICKER
// ========================================

function onDriveFileSelected(files) {
  trackMoodBoardEdit()
  if (!Array.isArray(files)) files = [files]
  for (const file of files) {
    canvasRef.value?.addDriveItem(file)
  }
  showDrivePicker.value = false
}

function onDriveFolderSelected(folder) {
  canvasRef.value?.addDriveFolder(folder)
  showDrivePicker.value = false
}

function onBrowseFolder(item) {
  const data = item.style_data || {}
  let sd = data
  if (typeof sd === 'string') {
    try { sd = JSON.parse(sd) } catch { sd = {} }
  }
  if (sd?.drive_folder_id) {
    folderBrowserId.value = sd.drive_folder_id
    folderBrowserName.value = item.title || 'Folder'
    showFolderBrowser.value = true
  }
}

// ========================================
// CALENDAR PICKER
// ========================================

function onCalendarEventSelected(events) {
  if (!Array.isArray(events)) events = [events]
  for (const event of events) {
    canvasRef.value?.addCalendarEvent(event)
  }
  showCalendarPicker.value = false
}

// ========================================
// BOARD PICKER
// ========================================

function onBoardSelected(boardData) {
  canvasRef.value?.addBoardItem(boardData)
  showBoardPicker.value = false
}

function onCardSelected(cardData) {
  canvasRef.value?.addBoardItem(cardData)
  showBoardPicker.value = false
}

// ========================================
// COLOR PICKER
// ========================================

async function onColorPickerSave(colorData) {
  if (colorPickerItemId.value) {
    const field = colorPickerField.value
    const itemId = colorPickerItemId.value
    const item = store.currentItems.find(i => i.id === itemId)

    if (field === 'shape_fill') {
      store.updateItem(itemId, { style_data: { ...(item?.style_data || {}), shape_fill: colorData.hex } })
    } else if (field === 'text_color') {
      store.updateItem(itemId, { style_data: { ...(item?.style_data || {}), text_color: colorData.hex } })
    } else if (field === 'fill_color') {
      store.updateItem(itemId, { style_data: { ...(item?.style_data || {}), fill_color: colorData.hex } })
    } else if (field === 'stroke_color') {
      store.updateItem(itemId, { style_data: { ...(item?.style_data || {}), stroke_color: colorData.hex, line_color: colorData.hex } })
    } else {
      store.updateItem(itemId, { color: colorData.hex, color_data: colorData })
    }
  } else {
    // Creating new color swatch from toolbar — include the chosen color
    // in the initial addItem payload so the server item already has the
    // correct color. Previously we created with defaults then updated,
    // but updateItem skips API calls for temp (negative) IDs, so the
    // server response would overwrite the color back to the default.
    const rect = canvasRef.value?.$el?.getBoundingClientRect()
    const centerX = rect ? Math.round((rect.width / 2 - store.panX) / store.zoom) : 200
    const centerY = rect ? Math.round((rect.height / 2 - store.panY) / store.zoom) : 200
    await store.addItem({
      type: 'color_swatch',
      pos_x: centerX,
      pos_y: centerY,
      width: 100,
      height: 100,
      color: colorData.hex,
      color_data: colorData,
    })
  }
  showColorPicker.value = false
  colorPickerItemId.value = null
  colorPickerField.value = null
}

// Open color picker for an existing item (called from canvas item double-click, sidebar, or "I" shortcut)
function openColorPickerForItem(item) {
  colorPickerItemId.value = item.id

  const t = item.type
  const sd = item.style_data || {}
  if (t === 'shape' || t === 'pen_shape') {
    colorPickerField.value = 'shape_fill'
    colorPickerInitial.value = sd.shape_fill || '#6366f1'
  } else if (t === 'text') {
    colorPickerField.value = 'text_color'
    colorPickerInitial.value = sd.text_color || '#000000'
  } else if (t === 'frame' || t === 'slide') {
    colorPickerField.value = 'fill_color'
    colorPickerInitial.value = sd.fill_color || '#ffffff'
  } else if (t === 'line' || t === 'drawing') {
    colorPickerField.value = 'stroke_color'
    colorPickerInitial.value = sd.stroke_color || sd.line_color || '#000000'
  } else {
    colorPickerField.value = 'color'
    colorPickerInitial.value = item.color || '#6366f1'
  }

  showColorPicker.value = true
}

async function pickColorForItem(item, target = 'fill') {
  if (!hasEyeDropper) {
    openColorPickerForItem(item)
    return
  }
  try {
    const eyeDropper = new window.EyeDropper()
    const result = await eyeDropper.open()
    if (!result?.sRGBHex) return

    const hex = result.sRGBHex
    const t = item.type
    const sd = item.style_data || {}

    if (target === 'stroke') {
      if (t === 'shape' || t === 'pen_shape') {
        store.updateItem(item.id, { style_data: { ...sd, shape_border_color: hex, shape_border_width: sd.shape_border_width || 2 } })
      } else if (t === 'text') {
        store.updateItem(item.id, { style_data: { ...sd, text_stroke_color: hex, text_stroke_width: sd.text_stroke_width || 1 } })
      } else if (t === 'frame') {
        store.updateItem(item.id, { style_data: { ...sd, border_color: hex, border_width: sd.border_width || 2 } })
      } else if (t === 'line' || t === 'drawing') {
        store.updateItem(item.id, { style_data: { ...sd, stroke_color: hex, line_color: hex } })
      } else {
        store.updateItem(item.id, { style_data: { ...sd, border_color: hex, border_width: sd.border_width || 2 } })
      }
      toast.show('Stroke color applied', 'success')
    } else {
      if (t === 'shape' || t === 'pen_shape') {
        store.updateItem(item.id, { style_data: { ...sd, shape_fill: hex } })
      } else if (t === 'text') {
        store.updateItem(item.id, { style_data: { ...sd, text_color: hex } })
      } else if (t === 'frame' || t === 'slide') {
        store.updateItem(item.id, { style_data: { ...sd, fill_color: hex } })
      } else if (t === 'line' || t === 'drawing') {
        store.updateItem(item.id, { style_data: { ...sd, stroke_color: hex, line_color: hex } })
      } else {
        store.updateItem(item.id, { color: hex, color_data: buildColorData(hex) })
      }
      toast.show('Fill color applied', 'success')
    }
  } catch (e) {
    // User cancelled the eyedropper
  }
}

// Toggle YouTube interactive mode — when on, the iframe receives pointer events directly
function toggleYoutubeInteractive() {
  const selected = [...store.selectedItemIds]
  if (selected.length !== 1) return
  const item = store.currentItems.find(i => i.id === selected[0])
  if (!item || item.type !== 'youtube') return
  const current = item.style_data?._youtubeInteractive || false
  store.updateItem(item.id, { style_data: { ...(item.style_data || {}), _youtubeInteractive: !current } })
}

// Add a color_swatch to the board from the Board Palette panel
async function onPalettePickColor(hex) {
  trackMoodBoardEdit()
  const colorData = buildColorData(hex)
  // Include the chosen color in the initial addItem payload so the
  // server item already has the correct color (avoids temp-ID race).
  const rect = canvasRef.value?.$el?.getBoundingClientRect()
  const centerX = rect ? Math.round((rect.width / 2 - store.panX) / store.zoom) : 200
  const centerY = rect ? Math.round((rect.height / 2 - store.panY) / store.zoom) : 200
  await store.addItem({
    type: 'color_swatch',
    pos_x: centerX,
    pos_y: centerY,
    width: 100,
    height: 100,
    color: hex,
    color_data: colorData,
  })
}

/**
 * Build a full color_data object (hex, RGB, CMYK) from a hex string.
 */
function buildColorData(hex) {
  const clean = hex.replace('#', '')
  const r = parseInt(clean.substring(0, 2), 16) || 0
  const g = parseInt(clean.substring(2, 4), 16) || 0
  const b = parseInt(clean.substring(4, 6), 16) || 0

  // RGB -> CMYK conversion
  const rr = r / 255, gg = g / 255, bb = b / 255
  const k = 1 - Math.max(rr, gg, bb)
  let c = 0, m = 0, y = 0
  if (k < 1) {
    c = Math.round(((1 - rr - k) / (1 - k)) * 100)
    m = Math.round(((1 - gg - k) / (1 - k)) * 100)
    y = Math.round(((1 - bb - k) / (1 - k)) * 100)
  }

  return {
    hex,
    rgb: { r, g, b },
    cmyk: { c, m, y, k: Math.round(k * 100) }
  }
}

// ========================================
// DRAWING
// ========================================

function openNewDrawing() {
  editingDrawingItem.value = null
  editingDrawingData.value = null
  showDrawingCanvas.value = true
}

function openDrawingEditor(item) {
  editingDrawingItem.value = item
  editingDrawingData.value = item.content || null
  showDrawingCanvas.value = true
}

async function onDrawingSave(payload) {
  if (!currentBoard.value) return
  
  // Calculate position at center of current viewport
  const rect = canvasRef.value?.$el?.getBoundingClientRect()
  const centerX = rect ? Math.round((rect.width / 2 - store.panX) / store.zoom) : 200
  const centerY = rect ? Math.round((rect.height / 2 - store.panY) / store.zoom) : 200
  
  const drawingPayload = {
    ...payload,
    pos_x: editingDrawingItem.value ? undefined : centerX - (payload.width || 400) / 2,
    pos_y: editingDrawingItem.value ? undefined : centerY - (payload.height || 300) / 2,
  }
  
  await store.saveDrawing(drawingPayload, editingDrawingItem.value?.id || null)
  
  // Refresh the board to get updated data
  await store.fetchBoard(currentBoard.value.id)
  
  showDrawingCanvas.value = false
  editingDrawingItem.value = null
  editingDrawingData.value = null
  
  toast.show('Drawing saved', 'success')
}

function onDrawingDiscard() {
  showDrawingCanvas.value = false
  editingDrawingItem.value = null
  editingDrawingData.value = null
}

// ========================================
// FILE PREVIEW
// ========================================

function isCollabEditable(item) {
  if (!item?.drive_file_id) return false
  const name = (item.title || '').toLowerCase()
  return name.endsWith('.docx') || name.endsWith('.pptx')
}

function openFilePreview(item) {
  filePreviewItem.value = item
  showFilePreview.value = true
}

function openFileInCollab(item) {
  showFilePreview.value = false
  filePreviewItem.value = null

  // Navigate to the Drive view with the document open in collab editor
  // The item needs a drive_file_id to open in collab
  if (item.drive_file_id) {
    router.push({ name: 'drive', query: { openCollab: item.drive_file_id, fileName: item.title } })
  } else {
    toast.show('This file cannot be edited collaboratively', 'info')
  }
}

// ========================================
// LIFECYCLE
// ========================================

watch(() => route.params.id, async (id) => {
  if (id) {
    await store.fetchBoard(parseInt(id))

    if (store.currentBoard) {
      await boardPreloadImages(store.currentBoard)
    }

    // On mobile, auto-enter presentation mode (with landscape check)
    if (isMobile.value && store.presentationSlides.length > 0 && !store.presentationMode) {
      startMobilePresentation()
      return
    }

    // Handle deep link from search: ?item=ID
    const itemId = route.query.item ? parseInt(route.query.item) : null
    if (itemId && store.currentBoard) {
      nextTick(() => {
        const item = store.currentBoard.items?.find(i => i.id === itemId)
        if (item) {
          onFlyToItem(item)
        }
        router.replace({ path: `/mood/${id}`, query: {} })
      })
    }
  } else {
    store.currentBoard = null
    clientTimeTracker.stopTracking()
  }
}, { immediate: true })

// Track mood board view/edit time for client time tracking
watch(currentBoard, (board) => {
  if (board) {
    const clientId = board.client_id || board.client?.id || null
    clientTimeTracker.trackMoodBoardActivity(board.id, board.name, false, clientId)
  } else {
    clientTimeTracker.stopTracking()
  }
})

/**
 * Track mood board edit activity (called when user modifies items)
 * This upgrades the current tracking from view to edit
 */
function trackMoodBoardEdit() {
  if (!currentBoard.value) return
  const clientId = currentBoard.value.client_id || currentBoard.value.client?.id || null
  clientTimeTracker.trackMoodBoardActivity(
    currentBoard.value.id,
    currentBoard.value.name,
    true,
    clientId
  )
}

const isMobile = ref(Math.min(window.innerWidth, window.innerHeight) < 768)
const mobileWaitingForLandscape = ref(false)
const isLandscape = ref(window.innerWidth > window.innerHeight)

function checkMobile() {
  isMobile.value = Math.min(window.innerWidth, window.innerHeight) < 768
  isLandscape.value = window.innerWidth > window.innerHeight

  if (mobileWaitingForLandscape.value && isLandscape.value) {
    mobileWaitingForLandscape.value = false
    if (store.presentationSlides.length > 0 && !store.presentationMode) {
      store.startPresentation(0)
    }
  }
}

function startMobilePresentation() {
  if (isLandscape.value) {
    if (store.presentationSlides.length > 0) {
      store.startPresentation(0)
    }
  } else {
    mobileWaitingForLandscape.value = true
  }
}

function cancelMobileWait() {
  mobileWaitingForLandscape.value = false
  router.push('/mood')
}

function onDocumentClick(e) {
  closeItemContext()
  if (showCommentMenu.value && commentMenuRef.value && !commentMenuRef.value.contains(e.target)) {
    showCommentMenu.value = false
  }
}

onMounted(() => {
  store.fetchBoards()
  document.addEventListener('click', onDocumentClick)
  updateResolvedAccent()
  checkMobile()
  window.addEventListener('resize', checkMobile)
  cleanupLegacyMoodCacheServiceWorker()
})

onUnmounted(() => {
  document.removeEventListener('click', onDocumentClick)
  window.removeEventListener('mousemove', onConnPanelDragMove)
  window.removeEventListener('mouseup', onConnPanelDragEnd)
  window.removeEventListener('resize', checkMobile)
  store.unsubscribeFromBoardEvents()
  clientTimeTracker.stopTracking()
})
</script>

<style scoped>
.slide-enter-active,
.slide-leave-active {
  transition: all 0.2s ease;
}
.slide-enter-from,
.slide-leave-to {
  transform: translateX(-100%);
  opacity: 0;
}

.slide-right-enter-active,
.slide-right-leave-active {
  transition: all 0.2s ease;
}
.slide-right-enter-from,
.slide-right-leave-to {
  transform: translateX(100%);
  opacity: 0;
}

.slide-right-sm-enter-active,
.slide-right-sm-leave-active {
  transition: all 0.2s ease;
}
.slide-right-sm-enter-from,
.slide-right-sm-leave-to {
  transform: translateX(20px);
  opacity: 0;
}

.slide-left-sm-enter-active,
.slide-left-sm-leave-active {
  transition: all 0.2s ease;
}
.slide-left-sm-enter-from,
.slide-left-sm-leave-to {
  transform: translateX(-20px);
  opacity: 0;
}

.follow-indicator-enter-active,
.follow-indicator-leave-active {
  transition: all 0.25s ease;
}
.follow-indicator-enter-from,
.follow-indicator-leave-to {
  opacity: 0;
  transform: translate(-50%, -10px);
}
</style>

<style>
/* Non-scoped for teleported content */
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s ease;
}
.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}

/* Rotate phone prompt */
.rotate-prompt-enter-active { transition: opacity 0.3s ease; }
.rotate-prompt-leave-active { transition: opacity 0.2s ease; }
.rotate-prompt-enter-from,
.rotate-prompt-leave-to { opacity: 0; }

.rotate-phone-icon {
  animation: rotateHint 2s ease-in-out infinite;
}

@keyframes rotateHint {
  0%, 100% { transform: rotate(0deg); }
  25% { transform: rotate(-90deg); }
  50% { transform: rotate(-90deg); }
  75% { transform: rotate(0deg); }
}

.rotate-phone-icon-back {
  animation: rotateHintBack 2s ease-in-out infinite;
}

@keyframes rotateHintBack {
  0%, 100% { transform: rotate(-90deg); }
  25% { transform: rotate(0deg); }
  50% { transform: rotate(0deg); }
  75% { transform: rotate(-90deg); }
}
</style>

