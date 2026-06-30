<template>
  <div
    class="flex-shrink-0 border-l border-surface-200 dark:border-surface-700 bg-white dark:bg-surface-800 flex flex-col h-full transition-all duration-200 overflow-hidden"
    :class="collapsed ? 'w-11' : 'w-[350px]'"
  >
    <!-- Header row -->
    <div class="px-3 py-2 border-b border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-800 flex-shrink-0">
      <div class="flex items-center gap-1.5">
      <button
        @click="collapsed = !collapsed"
          class="p-1.5 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-400 transition-colors"
        :title="collapsed ? 'Expand properties' : 'Collapse properties'"
      >
        <span class="material-symbols-rounded text-lg">{{ collapsed ? 'chevron_left' : 'chevron_right' }}</span>
      </button>
      <template v-if="!collapsed">
          <div class="min-w-0 flex-1">
            <p class="text-[9px] font-semibold uppercase tracking-[0.16em] text-surface-400 dark:text-surface-500">{{ rightTab === 'inspect' ? 'Inspect' : 'Properties' }}</p>
            <div class="flex items-center gap-1.5 min-w-0">
              <span class="material-symbols-rounded text-lg text-surface-400">{{ headerIcon }}</span>
              <span class="text-sm font-semibold text-surface-800 dark:text-surface-100 truncate">{{ headerTitle }}</span>
            </div>
          </div>
          <!-- Collapse/Expand all sections -->
          <div class="flex items-center gap-0.5 flex-shrink-0">
            <button
              @click="collapseAllSections"
              class="p-1 rounded-md text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700 hover:text-surface-600 dark:hover:text-surface-300 transition-colors"
              title="Collapse all sections"
            >
              <span class="material-symbols-rounded text-sm">unfold_less</span>
            </button>
            <button
              @click="expandAllSections"
              class="p-1 rounded-md text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700 hover:text-surface-600 dark:hover:text-surface-300 transition-colors"
              title="Expand all sections"
            >
              <span class="material-symbols-rounded text-sm">unfold_more</span>
            </button>
          </div>
          <!-- Tab toggle -->
          <div class="flex items-center gap-0.5 flex-shrink-0 bg-surface-100 dark:bg-surface-700 rounded-lg p-0.5">
            <button
              @click="rightTab = 'properties'"
              class="px-2 py-1 text-[10px] font-medium rounded-md transition-colors"
              :class="rightTab === 'properties'
                ? 'bg-white dark:bg-surface-600 text-surface-700 dark:text-surface-200 shadow-sm'
                : 'text-surface-400 hover:text-surface-600 dark:hover:text-surface-300'"
            >
              <span class="material-symbols-rounded text-sm">tune</span>
            </button>
            <button
              @click="rightTab = 'inspect'"
              class="px-2 py-1 text-[10px] font-medium rounded-md transition-colors"
              :class="rightTab === 'inspect'
                ? 'bg-white dark:bg-surface-600 text-surface-700 dark:text-surface-200 shadow-sm'
                : 'text-surface-400 hover:text-surface-600 dark:hover:text-surface-300'"
            >
              <span class="material-symbols-rounded text-sm">code</span>
            </button>
          </div>
      </template>
      </div>
    </div>

    <!-- CSS Inspect tab -->
    <MoodCssInspect v-if="!collapsed && rightTab === 'inspect'" />

    <!-- Scrollable content (hidden when collapsed) -->
    <div v-if="!collapsed && rightTab === 'properties'" class="flex-1 overflow-y-auto overflow-x-hidden min-h-0 custom-scrollbar">

      <!-- ============================================ -->
      <!-- MULTI-SELECTION: Alignment & Bulk actions    -->
      <!-- ============================================ -->
      <template v-if="multipleSelected">
        <SidebarSection title="Alignment" icon="align_horizontal_left" :open="true">
          <div class="inline-flex w-full border border-surface-200 dark:border-surface-600 rounded-lg overflow-hidden bg-surface-50 dark:bg-surface-700">
            <button
              v-for="a in alignActions"
              :key="a.dir"
              @click="store.alignItems(a.dir)"
              :title="a.label"
              class="flex-1 flex items-center justify-center py-2 text-surface-500 hover:text-surface-900 dark:hover:text-white hover:bg-surface-100 dark:hover:bg-surface-600 transition-colors border-r border-surface-200 dark:border-surface-600 last:border-r-0"
            >
              <span class="material-symbols-rounded text-[18px]">{{ a.icon }}</span>
            </button>
          </div>
          <div class="inline-flex w-full border border-surface-200 dark:border-surface-600 rounded-lg overflow-hidden bg-surface-50 dark:bg-surface-700 mt-1.5">
            <button
              v-for="d in distributeActions"
              :key="d.dir"
              @click="store.alignItems(d.dir)"
              :title="d.label"
              :disabled="store.selectedItemIds.size < 3"
              class="flex-1 flex items-center justify-center py-2 text-surface-500 hover:text-surface-900 dark:hover:text-white hover:bg-surface-100 dark:hover:bg-surface-600 transition-colors border-r border-surface-200 dark:border-surface-600 last:border-r-0 disabled:opacity-30 disabled:cursor-not-allowed"
            >
              <span class="material-symbols-rounded text-[18px]">{{ d.icon }}</span>
            </button>
          </div>
        </SidebarSection>

        <SidebarSection title="Group" icon="group_work" :open="true">
          <div class="flex flex-col gap-1.5">
            <button
              v-if="!hasGroups"
              @click="store.groupSelectedItems()"
              class="flex items-center gap-2 w-full px-3 py-2 bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-700 rounded-lg text-emerald-700 dark:text-emerald-300 text-xs font-medium hover:border-emerald-300 dark:hover:border-emerald-500 transition-colors"
            >
              <span class="material-symbols-rounded text-base">group_work</span>
              <span class="flex-1 text-left">Group Items</span>
              <span class="text-[10px] font-mono text-emerald-500/60">Ctrl+G</span>
            </button>
            <button
              v-if="hasGroups"
              @click="store.ungroupSelectedItems()"
              class="flex items-center gap-2 w-full px-3 py-2 bg-surface-50 dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-lg text-surface-700 dark:text-surface-300 text-xs font-medium hover:border-surface-300 dark:hover:border-surface-500 transition-colors"
            >
              <span class="material-symbols-rounded text-base">workspaces</span>
              <span class="flex-1 text-left">Ungroup</span>
              <span class="text-[10px] font-mono text-surface-400">Ctrl+Shift+G</span>
            </button>
            <button
              @click="store.createRepeatGrid()"
              class="flex items-center gap-2 w-full px-3 py-2 bg-surface-50 dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-lg text-surface-700 dark:text-surface-300 text-xs font-medium hover:border-surface-300 dark:hover:border-surface-500 transition-colors"
            >
              <span class="material-symbols-rounded text-base">grid_view</span>
              <span class="flex-1 text-left">Repeat Grid</span>
            </button>
            <button
              v-if="canMask"
              @click="store.maskSelectedItems()"
              class="flex items-center gap-2 w-full px-3 py-2 bg-surface-50 dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-lg text-surface-700 dark:text-surface-300 text-xs font-medium hover:border-surface-300 dark:hover:border-surface-500 transition-colors"
            >
              <span class="material-symbols-rounded text-base">content_cut</span>
              <span class="flex-1 text-left">Create Clipping Mask</span>
            </button>
          </div>
        </SidebarSection>

        <!-- Boolean / Pathfinder Operations (only when 2+ shapes/vectors selected) -->
        <SidebarSection v-if="canBooleanOp" title="Pathfinders" icon="join_inner" :open="true">
          <div class="inline-flex w-full border border-surface-200 dark:border-surface-600 rounded-lg overflow-hidden bg-surface-50 dark:bg-surface-700">
            <button
              v-for="op in booleanOps.slice(0, 4)"
              :key="op.id"
              @click="store.booleanOp(op.id)"
              :title="op.label + ' (' + op.shortcut + ')'"
              class="flex-1 flex items-center justify-center py-2 text-surface-500 hover:text-surface-900 dark:hover:text-white hover:bg-surface-100 dark:hover:bg-surface-600 transition-colors border-r border-surface-200 dark:border-surface-600 last:border-r-0"
            >
              <span class="material-symbols-rounded text-[18px]">{{ op.icon }}</span>
            </button>
            <button
              @click="store.booleanOp('flatten')"
              :title="'Flatten (Alt+Shift+F)'"
              class="flex-1 flex items-center justify-center py-2 text-surface-500 hover:text-surface-900 dark:hover:text-white hover:bg-surface-100 dark:hover:bg-surface-600 transition-colors"
            >
              <span class="material-symbols-rounded text-[18px]">layers_clear</span>
            </button>
          </div>
        </SidebarSection>

        <SidebarSection title="Actions" icon="bolt" :open="true">
          <div class="flex flex-col gap-1.5">
            <button
              @click="store.duplicateSelectedItems(30, 30)"
              class="flex items-center gap-2 w-full px-3 py-2 bg-surface-50 dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-lg text-surface-700 dark:text-surface-300 text-xs font-medium hover:border-surface-300 dark:hover:border-surface-500 transition-colors"
            >
              <span class="material-symbols-rounded text-base">content_copy</span>
              <span class="flex-1 text-left">Duplicate</span>
              <span class="text-[10px] font-mono text-surface-400">Ctrl+D</span>
            </button>
            <button
              @click="store.deleteSelectedItems()"
              class="flex items-center gap-2 w-full px-3 py-2 border border-red-200 dark:border-red-900/40 rounded-lg text-red-500 text-xs font-medium hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors"
            >
              <span class="material-symbols-rounded text-base">delete</span>
              <span class="flex-1 text-left">Delete Selected</span>
              <span class="text-[10px] font-mono text-red-400/60">Del</span>
            </button>
          </div>
          <div class="mt-2 text-[9px] text-surface-400 text-center tabular-nums uppercase tracking-[0.12em]">
            {{ store.selectedItemIds.size }} items selected
          </div>
        </SidebarSection>

        <!-- Selection Colors -->
        <SidebarSection v-if="selectionColors.length" title="Selection colors" icon="palette" :open="true">
          <div class="space-y-1">
            <div
              v-for="(entry, idx) in selectionColors"
              :key="'sel-color-' + idx"
              class="flex items-center gap-2 px-2 py-1.5 rounded-lg bg-surface-50 dark:bg-surface-700/50 group"
            >
              <!-- Color swatch + picker -->
              <MoodColorPicker
                :model-value="entry.color"
                @update:model-value="(c) => updateSelectionColor(entry.sources, c)"
                :palette="store.getColorPalette()"
                :show-caret="false"
                dropdown-position="bottom-full left-0 mb-2"
              />
              <!-- Hex value -->
              <input
                type="text"
                :value="entry.color.replace('#', '').toUpperCase()"
                @change="updateSelectionColor(entry.color, '#' + $event.target.value.replace('#', ''))"
                class="flex-1 min-w-0 bg-transparent text-xs font-mono text-surface-700 dark:text-surface-300 uppercase tracking-wider focus:outline-none focus:text-primary-500"
                maxlength="7"
              />
              <!-- Opacity -->
              <input
                type="number"
                :value="entry.opacity"
                min="0" max="100" step="5"
                @change="updateSelectionColorOpacity(entry.color, parseInt($event.target.value))"
                class="w-10 bg-transparent text-xs font-mono text-surface-500 text-right focus:outline-none focus:text-primary-500 [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none"
              />
              <span class="text-[9px] text-surface-400">%</span>
            </div>
          </div>
          <p class="mt-2 text-[9px] text-surface-400 leading-tight">
            Colors from {{ store.selectedItemIds.size }} items. Change a color to update all items using it.
          </p>
        </SidebarSection>

        <!-- Multi-selection Typography (when text items are in the selection) -->
        <SidebarSection v-if="multiTextItems.length" title="Typography" icon="text_format" :open="true">
          <div class="space-y-2.5">
            <p class="text-[9px] text-surface-400 tabular-nums">
              {{ multiTextItems.length }} text item{{ multiTextItems.length > 1 ? 's' : '' }} selected
            </p>

            <!-- Font Family + Weight — single row -->
            <div class="flex items-center gap-1.5">
              <span class="text-[9px] text-surface-400 uppercase tracking-wider font-medium w-8 flex-shrink-0">Font</span>
              <div class="relative flex-[3] min-w-0" ref="multiTextFontDropdownRef">
                <button
                  @click.stop="showMultiTextFontDropdown = !showMultiTextFontDropdown"
                  class="flex items-center justify-between w-full px-2 py-1.5 bg-surface-50 dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-lg text-[11px] text-surface-700 dark:text-surface-300 hover:border-primary-400 transition-colors"
                  :style="{ fontFamily: multiTextFont }"
                >
                  <span class="truncate">{{ multiTextFont === '__mixed__' ? 'Mixed' : getFontLabel(multiTextFont) }}</span>
                  <span class="material-symbols-rounded text-[12px] text-surface-400 flex-shrink-0">expand_more</span>
                </button>
                <div
                  v-if="showMultiTextFontDropdown"
                  class="absolute top-full left-0 mt-1 w-52 max-h-64 overflow-y-auto bg-white dark:bg-surface-800 border border-surface-200 dark:border-surface-700 rounded-lg shadow-2xl z-50 py-1"
                >
                  <div class="px-2 py-1.5 sticky top-0 bg-white dark:bg-surface-800 border-b border-surface-200 dark:border-surface-700">
                    <input
                      v-model="multiTextFontSearch"
                      placeholder="Search fonts..."
                      class="w-full bg-surface-100 dark:bg-surface-700 rounded-lg px-2 py-1 text-xs text-surface-700 dark:text-surface-300 focus:outline-none"
                    />
                  </div>
                  <div v-for="group in multiTextFilteredFontGroups" :key="group.label" class="py-0.5">
                    <div class="px-3 py-1 text-[9px] uppercase tracking-wider text-surface-400 font-semibold">{{ group.label }}</div>
                    <button
                      v-for="f in group.fonts"
                      :key="f.value"
                      @click.stop="setMultiTextFont(f.value)"
                      class="w-full text-left px-3 py-1.5 text-xs hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-700 dark:text-surface-300"
                      :style="{ fontFamily: f.value }"
                    >
                      <span>{{ f.label }}</span>
                    </button>
                  </div>
                </div>
              </div>
              <div class="relative flex-[2] min-w-0" ref="multiTextWeightDropdownRef">
                <button
                  @click.stop="showMultiTextWeightDropdown = !showMultiTextWeightDropdown"
                  class="flex items-center justify-between w-full px-2 py-1.5 bg-surface-50 dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-lg text-[11px] text-surface-700 dark:text-surface-300 hover:border-primary-400 transition-colors"
                >
                  <span class="truncate">{{ multiTextWeightLabel }}</span>
                  <span class="material-symbols-rounded text-[12px] text-surface-400 flex-shrink-0">expand_more</span>
                </button>
                <div
                  v-if="showMultiTextWeightDropdown"
                  class="absolute top-full right-0 mt-1 w-40 max-h-56 overflow-y-auto bg-white dark:bg-surface-800 border border-surface-200 dark:border-surface-700 rounded-lg shadow-2xl z-50 py-1"
                >
                  <button
                    v-for="w in typographyWeights"
                    :key="w.value"
                    @click.stop="setMultiTextWeight(w.value)"
                    class="w-full text-left px-3 py-1.5 text-xs hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-700 dark:text-surface-300"
                    :style="{ fontWeight: w.value }"
                  >
                    <span>{{ w.label }}</span>
                  </button>
                </div>
              </div>
            </div>

            <!-- Font Size -->
            <div class="flex items-center gap-1.5">
              <span class="text-[9px] text-surface-400 uppercase tracking-wider font-medium w-8 flex-shrink-0">Size</span>
              <MoodScrubInput
                class="flex-1"
                icon="format_size"
                suffix="px"
                :model-value="multiTextFontSize"
                :min="1" :max="500"
                :default-value="16"
                @update:model-value="setMultiTextSize($event)"
              />
            </div>

            <!-- Line Height + Letter Spacing — single row -->
            <div class="flex items-center gap-1.5">
              <span class="text-[9px] text-surface-400 uppercase tracking-wider font-medium w-8 flex-shrink-0">LH</span>
              <MoodScrubInput
                class="flex-1"
                icon="format_line_spacing"
                :model-value="multiTextLineHeight"
                :min="0.5" :max="5" :step="0.01" :precision="2"
                :default-value="1"
                @update:model-value="applyToMultiText({ line_height: $event })"
              />
              <span class="text-[9px] text-surface-400 uppercase tracking-wider font-medium w-8 flex-shrink-0 text-right">LS</span>
              <MoodScrubInput
                class="flex-1"
                icon="format_letter_spacing"
                :model-value="multiTextLetterSpacing"
                :min="-10" :max="100" :step="0.5" :precision="1"
                :default-value="0"
                @update:model-value="applyToMultiText({ letter_spacing: $event })"
              />
            </div>

            <div class="border-t border-surface-200 dark:border-surface-700" />

            <!-- Text Color -->
            <div class="flex items-center gap-1.5">
              <span class="text-[9px] text-surface-400 uppercase tracking-wider font-medium w-8 flex-shrink-0">Fill</span>
              <div class="flex-1">
                <MoodColorPicker
                  :model-value="multiTextColor"
                  @update:model-value="setMultiTextColor($event)"
                  :palette="store.getColorPalette()"
                  :allow-transparent="true"
                  label="Text color"
                  :show-caret="false"
                />
              </div>
            </div>

            <div class="border-t border-surface-200 dark:border-surface-700" />

            <!-- Text Alignment -->
            <div class="flex items-center gap-1.5">
              <span class="text-[9px] text-surface-400 uppercase tracking-wider font-medium w-8 flex-shrink-0">Align</span>
              <div class="inline-flex border border-surface-200 dark:border-surface-600 rounded-lg overflow-hidden bg-surface-50 dark:bg-surface-700">
                <button
                  v-for="a in textAlignOptions"
                  :key="a.value"
                  @click="setMultiTextAlign(a.value)"
                  class="flex items-center justify-center w-7 h-7 transition-colors border-r border-surface-200 dark:border-surface-600 last:border-r-0"
                  :class="multiTextAlign === a.value
                    ? 'bg-surface-200 dark:bg-surface-600 text-surface-900 dark:text-white'
                    : 'text-surface-400 hover:text-surface-900 dark:hover:text-white hover:bg-surface-100 dark:hover:bg-surface-600'"
                  :title="a.label"
                >
                  <span class="material-symbols-rounded text-sm">{{ a.icon }}</span>
                </button>
              </div>
              <div class="inline-flex border border-surface-200 dark:border-surface-600 rounded-lg overflow-hidden bg-surface-50 dark:bg-surface-700">
                <button
                  v-for="tt in textTransformOptions"
                  :key="tt.value"
                  @click="setMultiTextTransform(multiTextTransform === tt.value ? 'none' : tt.value)"
                  class="flex items-center justify-center w-7 h-7 transition-colors border-r border-surface-200 dark:border-surface-600 last:border-r-0"
                  :class="multiTextTransform === tt.value
                    ? 'bg-surface-200 dark:bg-surface-600 text-surface-900 dark:text-white'
                    : 'text-surface-400 hover:text-surface-900 dark:hover:text-white hover:bg-surface-100 dark:hover:bg-surface-600'"
                  :title="tt.label"
                >
                  <span class="material-symbols-rounded text-sm">{{ tt.icon }}</span>
                </button>
              </div>
            </div>
          </div>
        </SidebarSection>

        <!-- Multi-selection Shape Appearance (when shape items are in the selection) -->
        <SidebarSection v-if="multiShapeItems.length" title="Shape Appearance" icon="format_paint" :open="true">
          <div class="space-y-2.5">
            <p class="text-[9px] text-surface-400 tabular-nums">
              {{ multiShapeItems.length }} shape{{ multiShapeItems.length > 1 ? 's' : '' }} selected
            </p>

            <!-- Fill Color -->
            <div class="flex items-center gap-1.5">
              <span class="text-[9px] text-surface-400 uppercase tracking-wider font-medium w-8 flex-shrink-0">Fill</span>
              <div class="flex-1">
                <MoodColorPicker
                  :model-value="multiShapeFill"
                  @update:model-value="applyToMultiShape({ shape_fill: $event })"
                  :palette="store.getColorPalette()"
                  :allow-transparent="true"
                  label="Fill color"
                  :show-caret="false"
                />
              </div>
            </div>

            <!-- Border Color -->
            <div class="flex items-center gap-1.5">
              <span class="text-[9px] text-surface-400 uppercase tracking-wider font-medium w-8 flex-shrink-0">Stroke</span>
              <div class="flex-1 flex items-center gap-1.5">
                <MoodColorPicker
                  :model-value="multiShapeBorderColor"
                  @update:model-value="applyToMultiShape({ shape_border_color: $event })"
                  :palette="store.getColorPalette()"
                  :allow-transparent="true"
                  label="Border color"
                  :show-caret="false"
                />
                <MoodScrubInput
                  class="flex-1"
                  icon="line_weight"
                  suffix="px"
                  :model-value="multiShapeBorderWidth"
                  :min="0" :max="20" :step="1"
                  :default-value="0"
                  @update:model-value="applyToMultiShape({ shape_border_width: $event })"
                />
              </div>
            </div>

            <div class="border-t border-surface-200 dark:border-surface-700" />

            <!-- Corner Radius -->
            <div class="flex items-center gap-1.5">
              <span class="text-[9px] text-surface-400 uppercase tracking-wider font-medium w-8 flex-shrink-0">Radius</span>
              <MoodScrubInput
                class="flex-1"
                icon="rounded_corner"
                suffix="px"
                :model-value="multiShapeRadius"
                :min="0" :max="200" :step="1"
                :default-value="0"
                @update:model-value="applyToMultiShapeRadius($event)"
              />
            </div>
          </div>
        </SidebarSection>
      </template>

      <!-- ============================================ -->
      <!-- SINGLE ITEM SELECTED                         -->
      <!-- ============================================ -->
      <template v-else-if="singleItem">
        <!-- ── FIGMA-STYLE: TYPE HEADER + NAME ── -->
        <div class="px-3 pt-3 pb-1">
          <div class="flex items-center gap-1.5 mb-1.5">
            <span class="material-symbols-rounded text-base text-surface-400">{{ headerIcon }}</span>
            <span class="text-[10px] font-semibold text-surface-500 dark:text-surface-400 uppercase tracking-[0.14em]">{{ singleItem.type.replace('_', ' ') }}</span>
          </div>
          <MoodClassBadgeInput
            :modelValue="singleItem.title || ''"
            @update:modelValue="updateSingleItem({ title: $event })"
            placeholder="Add class name..."
            :suggestions="cssClassSuggestions"
          />

          <!-- Component instance indicator -->
          <div v-if="singleItem.component_id" class="flex items-center gap-2 mt-1.5 px-0.5">
            <span class="material-symbols-rounded text-sm text-violet-500">widgets</span>
            <span class="text-[10px] text-violet-500 font-medium flex-1 truncate">Component Instance</span>
            <button
              @click="detachComponentInstance(singleItem)"
              class="text-[10px] text-surface-400 hover:text-red-400 transition-colors"
              title="Detach from component"
            >
              <span class="material-symbols-rounded text-sm">link_off</span>
            </button>
            <button
              @click="resetComponentOverrides(singleItem)"
              class="text-[10px] text-surface-400 hover:text-amber-400 transition-colors"
              title="Reset local overrides"
            >
              <span class="material-symbols-rounded text-sm">restart_alt</span>
            </button>
          </div>
        </div>

        <!-- REPEAT GRID shortcut — always visible at top for non-container items -->
        <div v-if="singleItem.type !== 'repeat_grid' && singleItem.type !== 'slide' && singleItem.type !== 'frame'" class="px-3 pb-2">
          <button
            @click="store.createRepeatGrid()"
            class="flex items-center gap-2 w-full px-3 py-1.5 bg-surface-50 dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-lg text-surface-600 dark:text-surface-300 text-xs font-medium hover:border-surface-300 dark:hover:border-surface-500 transition-colors"
          >
            <span class="material-symbols-rounded text-base text-surface-400">grid_view</span>
            <span class="flex-1 text-left">Make Repeat Grid</span>
          </button>
        </div>

        <!-- ── TRANSFORM: Position, Size, Rotation (collapsible) ── -->
        <SidebarSection v-if="singleItem.type !== 'audio'" title="Transform" icon="open_with" :open="true">
          <div class="space-y-2">

          <!-- FRAME: Device Viewport Preset Picker -->
          <div v-if="singleItem.type === 'frame'" class="relative" ref="framePresetDropdownRef">
            <div class="text-[9px] text-surface-400 uppercase tracking-wider font-medium mb-1">Device / Viewport</div>
            <button
              @click.stop="showFramePresetDropdown = !showFramePresetDropdown"
              class="flex items-center justify-between w-full px-2.5 py-2 bg-surface-50 dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-lg text-xs text-surface-700 dark:text-surface-300 hover:border-primary-400 transition-colors"
            >
              <div class="flex items-center gap-2">
                <span class="material-symbols-rounded text-base text-primary-500">{{ currentFramePreset?.icon || 'crop_free' }}</span>
                <div class="text-left">
                  <div class="font-medium">{{ currentFramePreset?.label || 'Custom' }}</div>
                  <div class="text-[10px] text-surface-400">{{ Math.round(singleItem.width || 0) }} x {{ Math.round(singleItem.height || 0) }}px</div>
                </div>
              </div>
              <span class="material-symbols-rounded text-[14px] text-surface-400 flex-shrink-0">expand_more</span>
            </button>
            <!-- Device preset dropdown -->
            <div
              v-if="showFramePresetDropdown"
              class="absolute top-full left-0 right-0 mt-1 max-h-80 overflow-y-auto bg-white dark:bg-surface-800 border border-surface-200 dark:border-surface-700 rounded-xl shadow-2xl z-50 py-1"
            >
              <div class="px-2 py-1.5 sticky top-0 bg-white dark:bg-surface-800 border-b border-surface-200 dark:border-surface-700 z-10">
                <input
                  v-model="framePresetSearch"
                  placeholder="Search devices..."
                  class="w-full bg-surface-100 dark:bg-surface-700 rounded-lg px-2 py-1 text-xs text-surface-700 dark:text-surface-300 focus:outline-none"
                />
              </div>
              <div v-for="group in groupedFramePresets" :key="group.label" class="py-0.5">
                <div class="px-3 py-1 text-[9px] uppercase tracking-wider text-surface-400 font-semibold">{{ group.label }}</div>
                <button
                  v-for="p in group.presets"
                  :key="p.label"
                  @click.stop="selectFramePreset(p)"
                  class="w-full text-left px-3 py-1.5 text-xs hover:bg-surface-100 dark:hover:bg-surface-700 flex items-center gap-2"
                  :class="currentFramePreset?.label === p.label && singleItem.width === p.width ? 'bg-primary-50 dark:bg-primary-900/20 text-primary-600 dark:text-primary-400' : 'text-surface-700 dark:text-surface-300'"
                >
                  <span class="material-symbols-rounded text-sm text-surface-400">{{ p.icon }}</span>
                  <span class="flex-1">{{ p.label }}</span>
                  <span class="text-[10px] text-surface-400 font-mono">{{ p.width }}x{{ p.height }}</span>
                  <span v-if="currentFramePreset?.label === p.label && singleItem.width === p.width" class="material-symbols-rounded text-sm text-primary-500">check</span>
                </button>
              </div>
            </div>
          </div>

          <!-- Position: X / Y — inline row -->
          <div class="flex items-center gap-1.5">
            <span class="text-[9px] text-surface-400 uppercase tracking-wider font-medium w-6 flex-shrink-0">Pos</span>
            <MoodScrubInput
              class="flex-1"
              label="X"
              :model-value="Math.round(singleItem.pos_x || 0)"
              @update:model-value="v => moveContainerAware({ pos_x: v })"
            />
            <MoodScrubInput
              class="flex-1"
              label="Y"
              :model-value="Math.round(singleItem.pos_y || 0)"
              @update:model-value="v => moveContainerAware({ pos_y: v })"
            />
          </div>

          <!-- Dimensions: W / H — inline row -->
          <div class="flex items-center gap-1.5">
            <span class="text-[9px] text-surface-400 uppercase tracking-wider font-medium w-6 flex-shrink-0">Size</span>
            <MoodScrubInput
              class="flex-1"
              label="W"
              :model-value="Math.round(singleItem.width || 0)"
              :min="1"
              @update:model-value="updateSingleItem({ width: $event })"
            />
            <MoodScrubInput
              class="flex-1"
              label="H"
              :model-value="Math.round(singleItem.height || 0)"
              :min="1"
              @update:model-value="updateSingleItem({ height: $event })"
            />
          </div>

          <!-- Rotation & Scale (not for slides) — inline row -->
          <div v-if="singleItem.type !== 'slide'" class="flex items-center gap-1.5">
            <span class="text-[9px] text-surface-400 uppercase tracking-wider font-medium w-6 flex-shrink-0">Rot</span>
            <MoodScrubInput
              class="flex-1 min-w-0"
              icon="rotate_right"
              suffix="deg"
              :model-value="Math.round(singleItem.rotation || 0)"
              :min="-360" :max="360"
              :default-value="0"
              @update:model-value="updateSingleItem({ rotation: $event })"
            />
            <MoodScrubInput
              class="flex-1 min-w-0"
              icon="zoom_out_map"
              suffix="%"
              :model-value="Math.round((singleItem.style_data?.item_scale ?? 1) * 100)"
              :min="1" :max="1000"
              :step="1"
              :default-value="100"
              @update:model-value="updateItemStyleData({ item_scale: $event / 100 })"
            />
          </div>
          <!-- Flip — fused segmented bar -->
          <div v-if="singleItem.type !== 'slide'" class="flex items-center gap-1.5">
            <span class="text-[9px] text-surface-400 uppercase tracking-wider font-medium w-6 flex-shrink-0">Flip</span>
            <div class="inline-flex border border-surface-200 dark:border-surface-600 rounded-lg overflow-hidden bg-surface-50 dark:bg-surface-700">
              <button
                @click="toggleFlipX"
                class="flex items-center justify-center w-8 h-7 transition-colors border-r border-surface-200 dark:border-surface-600"
                :class="singleItem.style_data?.flip_x
                  ? 'bg-primary-100 dark:bg-primary-900/30 text-primary-600 dark:text-primary-400'
                  : 'text-surface-500 hover:text-surface-900 dark:hover:text-white hover:bg-surface-100 dark:hover:bg-surface-600'"
                title="Flip horizontal"
              >
                <span class="material-symbols-rounded text-sm">flip</span>
              </button>
              <button
                @click="toggleFlipY"
                class="flex items-center justify-center w-8 h-7 transition-colors"
                :class="singleItem.style_data?.flip_y
                  ? 'bg-primary-100 dark:bg-primary-900/30 text-primary-600 dark:text-primary-400'
                  : 'text-surface-500 hover:text-surface-900 dark:hover:text-white hover:bg-surface-100 dark:hover:bg-surface-600'"
                title="Flip vertical"
              >
                <span class="material-symbols-rounded text-sm" style="transform: rotate(90deg)">flip</span>
              </button>
            </div>
          </div>

          <div class="border-t border-surface-200 dark:border-surface-700" />

          <!-- Padding -->
          <div class="space-y-1">
            <div class="flex items-center gap-1.5">
              <span class="text-[9px] text-surface-400 uppercase tracking-wider font-medium w-6 flex-shrink-0">Pad</span>
              <template v-if="paddingLinked">
                <MoodScrubInput class="flex-1" icon="padding" suffix="px" :model-value="paddingAll" :min="0" :max="999" :default-value="0" @update:model-value="setPaddingAll($event)" />
              </template>
              <template v-else>
                <MoodScrubInput class="flex-1" label="T" suffix="px" :model-value="singleItem.style_data?.padding_top || 0" :min="0" :max="999" :default-value="0" @update:model-value="updateItemStyleData({ padding_top: $event || 0 })" />
                <MoodScrubInput class="flex-1" label="R" suffix="px" :model-value="singleItem.style_data?.padding_right || 0" :min="0" :max="999" :default-value="0" @update:model-value="updateItemStyleData({ padding_right: $event || 0 })" />
                <MoodScrubInput class="flex-1" label="B" suffix="px" :model-value="singleItem.style_data?.padding_bottom || 0" :min="0" :max="999" :default-value="0" @update:model-value="updateItemStyleData({ padding_bottom: $event || 0 })" />
                <MoodScrubInput class="flex-1" label="L" suffix="px" :model-value="singleItem.style_data?.padding_left || 0" :min="0" :max="999" :default-value="0" @update:model-value="updateItemStyleData({ padding_left: $event || 0 })" />
              </template>
              <button
                @click="paddingLinked = !paddingLinked"
                class="flex-shrink-0 p-0.5 rounded transition-colors"
                :class="paddingLinked ? 'text-primary-500' : 'text-surface-400 hover:text-surface-600'"
                :title="paddingLinked ? 'Edit sides individually' : 'Edit all sides together'"
              >
                <span class="material-symbols-rounded text-[14px]">{{ paddingLinked ? 'link' : 'link_off' }}</span>
              </button>
            </div>
          </div>

          <!-- Margin -->
          <div class="space-y-1">
            <div class="flex items-center gap-1.5">
              <span class="text-[9px] text-surface-400 uppercase tracking-wider font-medium w-6 flex-shrink-0">Mar</span>
              <template v-if="marginLinked">
                <MoodScrubInput class="flex-1" icon="margin" suffix="px" :model-value="marginAll" :min="-999" :max="999" :default-value="0" @update:model-value="setMarginAll($event)" />
              </template>
              <template v-else>
                <div v-for="side in ['top','right','bottom','left']" :key="'m-'+side" class="flex-1 min-w-0">
                  <template v-if="singleItem.style_data?.['margin_' + side] === 'auto'">
                    <button
                      class="w-full h-full py-1.5 text-[10px] font-medium text-amber-500 bg-surface-50 dark:bg-surface-700 border border-amber-400/40 rounded-lg text-center"
                      @click="updateItemStyleData({ ['margin_' + side]: 0 })"
                      title="Click to set numeric value"
                    >auto</button>
                  </template>
                  <MoodScrubInput
                    v-else
                    :label="side[0].toUpperCase()"
                    suffix="px"
                    :model-value="singleItem.style_data?.['margin_' + side] || 0"
                    :min="-999" :max="999" :default-value="0"
                    @update:model-value="updateItemStyleData({ ['margin_' + side]: $event || 0 })"
                  />
                </div>
              </template>
              <button
                @click="marginLinked = !marginLinked"
                class="flex-shrink-0 p-0.5 rounded transition-colors"
                :class="marginLinked ? 'text-primary-500' : 'text-surface-400 hover:text-surface-600'"
                :title="marginLinked ? 'Edit sides individually' : 'Edit all sides together'"
              >
                <span class="material-symbols-rounded text-[14px]">{{ marginLinked ? 'link' : 'link_off' }}</span>
              </button>
            </div>
            <!-- Quick-set auto centering (only in unlinked mode) -->
            <div v-if="!marginLinked" class="flex items-center gap-1 pl-7">
              <button
                @click="updateItemStyleData({ margin_top: 0, margin_right: 'auto', margin_bottom: 0, margin_left: 'auto' })"
                class="text-[9px] px-2 py-0.5 rounded-md border transition-colors"
                :class="singleItem.style_data?.margin_right === 'auto' && singleItem.style_data?.margin_left === 'auto'
                  ? 'bg-primary-50 dark:bg-primary-900/30 border-primary-300 dark:border-primary-700 text-primary-600 dark:text-primary-400 font-semibold'
                  : 'bg-surface-50 dark:bg-surface-700 border-surface-200 dark:border-surface-600 text-surface-500 hover:bg-surface-100 dark:hover:bg-surface-600'"
                title="Center horizontally (0 auto 0 auto)"
              >Center H</button>
              <button
                @click="updateItemStyleData({ margin_top: 'auto', margin_right: 0, margin_bottom: 'auto', margin_left: 0 })"
                class="text-[9px] px-2 py-0.5 rounded-md border transition-colors"
                :class="singleItem.style_data?.margin_top === 'auto' && singleItem.style_data?.margin_bottom === 'auto'
                  ? 'bg-primary-50 dark:bg-primary-900/30 border-primary-300 dark:border-primary-700 text-primary-600 dark:text-primary-400 font-semibold'
                  : 'bg-surface-50 dark:bg-surface-700 border-surface-200 dark:border-surface-600 text-surface-500 hover:bg-surface-100 dark:hover:bg-surface-600'"
                title="Center vertically (auto 0 auto 0)"
              >Center V</button>
              <button
                @click="updateItemStyleData({ margin_top: 'auto', margin_right: 'auto', margin_bottom: 'auto', margin_left: 'auto' })"
                class="text-[9px] px-2 py-0.5 rounded-md border transition-colors"
                :class="singleItem.style_data?.margin_top === 'auto' && singleItem.style_data?.margin_right === 'auto' && singleItem.style_data?.margin_bottom === 'auto' && singleItem.style_data?.margin_left === 'auto'
                  ? 'bg-primary-50 dark:bg-primary-900/30 border-primary-300 dark:border-primary-700 text-primary-600 dark:text-primary-400 font-semibold'
                  : 'bg-surface-50 dark:bg-surface-700 border-surface-200 dark:border-surface-600 text-surface-500 hover:bg-surface-100 dark:hover:bg-surface-600'"
                title="Center both axes (auto auto auto auto)"
              >Center All</button>
            </div>
          </div>
          </div>
        </SidebarSection>

        <!-- ============================================ -->
        <!-- CHILD-IN-FRAME: Constraints / Layout Props   -->
        <!-- ============================================ -->

        <!-- Constraints (child inside a STATIC frame — no auto-layout) -->
        <SidebarSection v-if="isChildOfStaticFrame" title="Constraints" icon="anchor" :open="true">
          <div class="space-y-3">
            <!-- Horizontal constraint — fused segmented -->
            <div>
              <span class="text-[9px] text-surface-400 uppercase tracking-wider font-medium">Horizontal</span>
              <div class="inline-flex w-full border border-surface-200 dark:border-surface-600 rounded-lg overflow-hidden bg-surface-50 dark:bg-surface-700 mt-1">
                <button
                  v-for="ch in [
                    {v:'left',icon:'align_horizontal_left',label:'Left'},
                    {v:'right',icon:'align_horizontal_right',label:'Right'},
                    {v:'center',icon:'align_horizontal_center',label:'Center'},
                    {v:'left_right',icon:'width',label:'Left + Right'},
                    {v:'scale',icon:'open_in_full',label:'Scale'}
                  ]"
                  :key="ch.v"
                  @click="updateItemStyleData({ constraint_h: ch.v })"
                  class="flex-1 flex items-center justify-center py-1.5 transition-colors border-r border-surface-200 dark:border-surface-600 last:border-r-0"
                  :class="(singleItem.style_data?.constraint_h || 'left') === ch.v
                    ? 'bg-primary-500/15 text-primary-600 dark:text-primary-400'
                    : 'text-surface-500 hover:text-surface-900 dark:hover:text-white hover:bg-surface-100 dark:hover:bg-surface-600'"
                  :title="ch.label"
                >
                  <span class="material-symbols-rounded text-sm">{{ ch.icon }}</span>
                </button>
              </div>
            </div>
            <!-- Vertical constraint — fused segmented -->
            <div>
              <span class="text-[9px] text-surface-400 uppercase tracking-wider font-medium">Vertical</span>
              <div class="inline-flex w-full border border-surface-200 dark:border-surface-600 rounded-lg overflow-hidden bg-surface-50 dark:bg-surface-700 mt-1">
                <button
                  v-for="cv in [
                    {v:'top',icon:'align_vertical_top',label:'Top'},
                    {v:'bottom',icon:'align_vertical_bottom',label:'Bottom'},
                    {v:'center',icon:'align_vertical_center',label:'Center'},
                    {v:'top_bottom',icon:'height',label:'Top + Bottom'},
                    {v:'scale',icon:'open_in_full',label:'Scale'}
                  ]"
                  :key="cv.v"
                  @click="updateItemStyleData({ constraint_v: cv.v })"
                  class="flex-1 flex items-center justify-center py-1.5 transition-colors border-r border-surface-200 dark:border-surface-600 last:border-r-0"
                  :class="(singleItem.style_data?.constraint_v || 'top') === cv.v
                    ? 'bg-primary-500/15 text-primary-600 dark:text-primary-400'
                    : 'text-surface-500 hover:text-surface-900 dark:hover:text-white hover:bg-surface-100 dark:hover:bg-surface-600'"
                  :title="cv.label"
                >
                  <span class="material-symbols-rounded text-sm">{{ cv.icon }}</span>
                </button>
              </div>
            </div>
          </div>
        </SidebarSection>

        <!-- Layout Properties (child inside an AUTO-LAYOUT frame or layout group) -->
        <SidebarSection v-if="isChildOfAutoLayout || isChildOfLayoutParent" title="Child Layout" icon="dashboard_customize" :open="true">
          <div class="space-y-2">
            <!-- Background toggle: removes item from layout flow, makes it fill the container -->
            <div v-if="isChildOfLayoutParent" class="flex items-center justify-between px-1 py-1 rounded-lg" :class="singleItem.style_data?.is_background ? 'bg-amber-500/10 border border-amber-500/30' : ''">
              <div class="flex items-center gap-1.5">
                <span class="material-symbols-rounded text-[16px]" :class="singleItem.style_data?.is_background ? 'text-amber-500' : 'text-surface-400'">wallpaper</span>
                <span class="text-[10px] font-medium" :class="singleItem.style_data?.is_background ? 'text-amber-600 dark:text-amber-400' : 'text-surface-500'">Set as Background</span>
              </div>
              <button
                @click="updateItemStyleData({ is_background: !singleItem.style_data?.is_background })"
                class="relative w-9 h-5 rounded-full transition-colors duration-200 flex-shrink-0"
                :class="singleItem.style_data?.is_background ? 'bg-amber-500' : 'bg-surface-300 dark:bg-surface-600'"
              >
                <span
                  class="absolute top-0.5 left-0.5 w-4 h-4 rounded-full bg-white shadow transition-transform duration-200"
                  :class="singleItem.style_data?.is_background ? 'translate-x-4' : 'translate-x-0'"
                />
              </button>
            </div>

            <!-- Flex child controls (hidden when item is set as background) -->
            <template v-if="parentLayoutMode === 'flex' && !singleItem.style_data?.is_background">
              <!-- Sizing Mode — fused segmented, 2 rows -->
              <div class="flex items-center gap-1.5">
                <span class="text-[9px] text-surface-400 uppercase tracking-wider font-medium w-6 flex-shrink-0">W</span>
                <div class="inline-flex flex-1 border border-surface-200 dark:border-surface-600 rounded-lg overflow-hidden bg-surface-50 dark:bg-surface-700">
                  <button
                    v-for="sz in [{v:'fixed',icon:'width',label:'Fixed'},{v:'fill',icon:'expand',label:'Fill'},{v:'hug',icon:'compress',label:'Hug'}]"
                    :key="sz.v"
                    @click="updateItemStyleData({ sizing_h: sz.v })"
                    class="flex-1 flex items-center justify-center gap-0.5 py-1.5 text-[9px] transition-colors border-r border-surface-200 dark:border-surface-600 last:border-r-0"
                    :class="(singleItem.style_data?.sizing_h || 'fixed') === sz.v
                      ? 'bg-primary-500/15 text-primary-600 dark:text-primary-400 font-medium'
                      : 'text-surface-500 hover:text-surface-900 dark:hover:text-white hover:bg-surface-100 dark:hover:bg-surface-600'"
                    :title="sz.label"
                  >
                    <span class="material-symbols-rounded text-xs">{{ sz.icon }}</span>
                    {{ sz.label }}
                  </button>
                </div>
              </div>
              <div class="flex items-center gap-1.5">
                <span class="text-[9px] text-surface-400 uppercase tracking-wider font-medium w-6 flex-shrink-0">H</span>
                <div class="inline-flex flex-1 border border-surface-200 dark:border-surface-600 rounded-lg overflow-hidden bg-surface-50 dark:bg-surface-700">
                  <button
                    v-for="sz in [{v:'fixed',icon:'height',label:'Fixed'},{v:'fill',icon:'expand',label:'Fill'},{v:'hug',icon:'compress',label:'Hug'}]"
                    :key="sz.v"
                    @click="updateItemStyleData({ sizing_v: sz.v })"
                    class="flex-1 flex items-center justify-center gap-0.5 py-1.5 text-[9px] transition-colors border-r border-surface-200 dark:border-surface-600 last:border-r-0"
                    :class="(singleItem.style_data?.sizing_v || 'fixed') === sz.v
                      ? 'bg-primary-500/15 text-primary-600 dark:text-primary-400 font-medium'
                      : 'text-surface-500 hover:text-surface-900 dark:hover:text-white hover:bg-surface-100 dark:hover:bg-surface-600'"
                    :title="sz.label"
                  >
                    <span class="material-symbols-rounded text-xs">{{ sz.icon }}</span>
                    {{ sz.label }}
                  </button>
                </div>
              </div>

              <!-- Flex grow/shrink -->
              <div class="grid grid-cols-2 gap-2">
                <MoodScrubInput icon="expand" label="Grow" :model-value="singleItem.style_data?.flex_grow ?? 0" :min="0" :max="10" :step="1" @update:model-value="updateItemStyleData({ flex_grow: $event })" />
                <MoodScrubInput icon="compress" label="Shrink" :model-value="singleItem.style_data?.flex_shrink ?? 1" :min="0" :max="10" :step="1" @update:model-value="updateItemStyleData({ flex_shrink: $event })" />
              </div>

              <!-- Align Self — fused segmented -->
              <div class="flex items-center gap-1.5">
                <span class="text-[9px] text-surface-400 uppercase tracking-wider font-medium w-6 flex-shrink-0">Self</span>
                <div class="inline-flex flex-1 border border-surface-200 dark:border-surface-600 rounded-lg overflow-hidden bg-surface-50 dark:bg-surface-700">
                  <button
                    v-for="asOpt in [
                      {v:'auto',icon:'tune',label:'Auto'},
                      {v:'start',icon:'align_horizontal_left',label:'Start'},
                      {v:'center',icon:'align_horizontal_center',label:'Center'},
                      {v:'end',icon:'align_horizontal_right',label:'End'},
                      {v:'stretch',icon:'expand',label:'Stretch'}
                    ]"
                    :key="asOpt.v"
                    @click="updateItemStyleData({ align_self: asOpt.v })"
                    class="flex-1 flex items-center justify-center py-1.5 transition-colors border-r border-surface-200 dark:border-surface-600 last:border-r-0"
                    :class="(singleItem.style_data?.align_self || 'auto') === asOpt.v
                      ? 'bg-primary-500/15 text-primary-600 dark:text-primary-400'
                      : 'text-surface-500 hover:text-surface-900 dark:hover:text-white hover:bg-surface-100 dark:hover:bg-surface-600'"
                    :title="asOpt.label"
                  >
                    <span class="material-symbols-rounded text-sm">{{ asOpt.icon }}</span>
                  </button>
                </div>
              </div>
            </template>

            <!-- Grid child controls (hidden when item is set as background) -->
            <template v-if="parentLayoutMode === 'grid' && !singleItem.style_data?.is_background">
              <div class="grid grid-cols-2 gap-2">
                <div>
                  <label class="text-[9px] text-surface-400 uppercase tracking-wider font-medium">Column</label>
                  <input
                    type="text"
                    :value="singleItem.style_data?.grid_column || ''"
                    @change="updateItemStyleData({ grid_column: $event.target.value || null })"
                    placeholder="auto"
                    class="w-full mt-0.5 text-[11px] bg-surface-50 dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-lg px-2 py-1.5 text-surface-700 dark:text-surface-300"
                  />
                </div>
                <div>
                  <label class="text-[9px] text-surface-400 uppercase tracking-wider font-medium">Row</label>
                  <input
                    type="text"
                    :value="singleItem.style_data?.grid_row || ''"
                    @change="updateItemStyleData({ grid_row: $event.target.value || null })"
                    placeholder="auto"
                    class="w-full mt-0.5 text-[11px] bg-surface-50 dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-lg px-2 py-1.5 text-surface-700 dark:text-surface-300"
                  />
                </div>
              </div>
              <div class="flex items-center gap-1.5">
                <span class="text-[9px] text-surface-400 uppercase tracking-wider font-medium w-6 flex-shrink-0">Self</span>
                <div class="inline-flex flex-1 border border-surface-200 dark:border-surface-600 rounded-lg overflow-hidden bg-surface-50 dark:bg-surface-700">
                  <button
                    v-for="asOpt in [
                      {v:'',label:'Auto'},{v:'start',label:'Start'},{v:'center',label:'Center'},{v:'end',label:'End'},{v:'stretch',label:'Stretch'}
                    ]"
                    :key="asOpt.v"
                    @click="updateItemStyleData({ align_self: asOpt.v || null })"
                    class="flex-1 flex items-center justify-center py-1.5 text-[10px] transition-colors border-r border-surface-200 dark:border-surface-600 last:border-r-0"
                    :class="(singleItem.style_data?.align_self || '') === asOpt.v
                      ? 'bg-primary-500/15 text-primary-600 dark:text-primary-400'
                      : 'text-surface-500 hover:bg-surface-100 dark:hover:bg-surface-600'"
                  >
                    {{ asOpt.label }}
                  </button>
                </div>
              </div>
            </template>

            <!-- Min/Max Dimensions (hidden when item is set as background) -->
            <template v-if="!singleItem.style_data?.is_background">
              <div class="flex items-center gap-1.5">
                <span class="text-[9px] text-surface-400 uppercase tracking-wider font-medium w-6 flex-shrink-0">Min</span>
                <MoodScrubInput class="flex-1" label="W" suffix="px" :model-value="singleItem.style_data?.min_w || 0" :min="0" :max="9999" @update:model-value="updateItemStyleData({ min_w: $event || null })" />
                <MoodScrubInput class="flex-1" label="H" suffix="px" :model-value="singleItem.style_data?.min_h || 0" :min="0" :max="9999" @update:model-value="updateItemStyleData({ min_h: $event || null })" />
              </div>
              <div class="flex items-center gap-1.5">
                <span class="text-[9px] text-surface-400 uppercase tracking-wider font-medium w-6 flex-shrink-0">Max</span>
                <MoodScrubInput class="flex-1" label="W" suffix="px" :model-value="singleItem.style_data?.max_w || 0" :min="0" :max="9999" @update:model-value="updateItemStyleData({ max_w: $event || null })" />
                <MoodScrubInput class="flex-1" label="H" suffix="px" :model-value="singleItem.style_data?.max_h || 0" :min="0" :max="9999" @update:model-value="updateItemStyleData({ max_h: $event || null })" />
              </div>
            </template>
          </div>
        </SidebarSection>

        <!-- ============================================ -->
        <!-- TEXT: Typography FIRST (most important)       -->
        <!-- ============================================ -->
        <!-- TEXT ITEM: Figma-style Typography -->
        <template v-if="singleItem.type === 'text'">
          <SidebarSection title="Typography" icon="text_format" :open="true">
            <div class="space-y-2.5">

              <!-- Global text style selector + save -->
              <div class="flex items-center gap-1.5">
                <span class="text-[9px] text-surface-400 uppercase tracking-wider font-medium w-8 flex-shrink-0">Style</span>
                <div class="flex-1 flex items-center gap-1">
                  <select
                    :value="singleItem.style_data?._globals?.text_style?.id || ''"
                    @change="onSelectTextStyle($event.target.value, false)"
                    class="flex-1 text-[11px] bg-surface-50 dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-lg px-2 py-1.5 text-surface-700 dark:text-surface-300"
                  >
                    <option value="">None (local)</option>
                    <option v-for="ts in gsStore.globalTextStyles" :key="ts.id" :value="ts.id">{{ ts.name }}</option>
                  </select>
                  <MoodSaveStyleDialog
                    type="text"
                    :color="singleItem.style_data?.text_color || '#000000'"
                    title="Save to Character Styles"
                    name-placeholder="e.g. Heading, Body..."
                    @save="saveTextStyleToGlobals(false, $event)"
                  />
                  <MoodGlobalIndicator :item="singleItem" style-key="text_style" />
                </div>
              </div>

              <!-- Font Family + Weight — single row -->
              <div class="flex items-center gap-1.5">
                <span class="text-[9px] text-surface-400 uppercase tracking-wider font-medium w-8 flex-shrink-0">Font</span>
                <div class="relative flex-[3] min-w-0" ref="sidebarFontDropdownRef">
                  <button
                    @click.stop="showSidebarFontDropdown = !showSidebarFontDropdown"
                    class="flex items-center justify-between w-full px-2 py-1.5 bg-surface-50 dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-lg text-[11px] text-surface-700 dark:text-surface-300 hover:border-primary-400 transition-colors"
                    :style="{ fontFamily: textFontFamily }"
                  >
                    <span class="truncate">{{ textFontLabel }}</span>
                    <span class="material-symbols-rounded text-[12px] text-surface-400 flex-shrink-0">expand_more</span>
                  </button>
                  <div
                    v-if="showSidebarFontDropdown"
                    class="absolute top-full left-0 mt-1 w-52 max-h-64 overflow-y-auto bg-white dark:bg-surface-800 border border-surface-200 dark:border-surface-700 rounded-lg shadow-2xl z-50 py-1"
                  >
                    <div class="px-2 py-1.5 sticky top-0 bg-white dark:bg-surface-800 border-b border-surface-200 dark:border-surface-700">
                      <input
                        v-model="sidebarFontSearch"
                        placeholder="Search fonts..."
                        class="w-full bg-surface-100 dark:bg-surface-700 rounded-lg px-2 py-1 text-xs text-surface-700 dark:text-surface-300 focus:outline-none"
                        ref="sidebarFontSearchInput"
                      />
                    </div>
                    <div v-for="group in sidebarFilteredFontGroups" :key="group.label" class="py-0.5">
                      <div class="px-3 py-1 text-[9px] uppercase tracking-wider text-surface-400 font-semibold">{{ group.label }}</div>
                      <button
                        v-for="f in group.fonts"
                        :key="f.value"
                        @click.stop="setSidebarFont(f.value)"
                        class="w-full text-left px-3 py-1.5 text-xs hover:bg-surface-100 dark:hover:bg-surface-700 flex items-center justify-between"
                        :class="textFontFamily === f.value ? 'bg-primary-50 dark:bg-primary-900/20 text-primary-600 dark:text-primary-400' : 'text-surface-700 dark:text-surface-300'"
                        :style="{ fontFamily: f.value }"
                      >
                        <span>{{ f.label }}</span>
                        <span v-if="textFontFamily === f.value" class="material-symbols-rounded text-sm text-primary-500">check</span>
                      </button>
                    </div>
                  </div>
                </div>
                <div class="relative flex-[2] min-w-0" ref="sidebarWeightDropdownRef">
                  <button
                    @click.stop="showSidebarWeightDropdown = !showSidebarWeightDropdown"
                    class="flex items-center justify-between w-full px-2 py-1.5 bg-surface-50 dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-lg text-[11px] text-surface-700 dark:text-surface-300 hover:border-primary-400 transition-colors"
                    :style="{ fontWeight: textFontWeight }"
                  >
                    <span class="truncate">{{ textFontWeightLabel }}</span>
                    <span class="material-symbols-rounded text-[12px] text-surface-400 flex-shrink-0">expand_more</span>
                  </button>
                  <div
                    v-if="showSidebarWeightDropdown"
                    class="absolute top-full right-0 mt-1 w-40 max-h-56 overflow-y-auto bg-white dark:bg-surface-800 border border-surface-200 dark:border-surface-700 rounded-lg shadow-2xl z-50 py-1"
                  >
                    <button
                      v-for="w in typographyWeights"
                      :key="w.value"
                      @click.stop="setSidebarWeight(w.value)"
                      class="w-full text-left px-3 py-1.5 text-xs hover:bg-surface-100 dark:hover:bg-surface-700 flex items-center justify-between"
                      :class="textFontWeight === w.value ? 'bg-primary-50 dark:bg-primary-900/20 text-primary-600 dark:text-primary-400' : 'text-surface-700 dark:text-surface-300'"
                      :style="{ fontWeight: w.value }"
                    >
                      <span>{{ w.label }}</span>
                      <span v-if="textFontWeight === w.value" class="material-symbols-rounded text-sm text-primary-500">check</span>
                    </button>
                  </div>
                </div>
              </div>

              <!-- Size + Line Height — label | control row -->
              <div class="flex items-center gap-1.5">
                <span class="text-[9px] text-surface-400 uppercase tracking-wider font-medium w-8 flex-shrink-0">Size</span>
                <MoodScrubInput
                  class="flex-1"
                  icon="format_size"
                  suffix="px"
                  :model-value="itemFontSize"
                  :min="1" :max="500"
                  :default-value="16"
                  @update:model-value="setItemFontSize($event)"
                />
                <MoodScrubInput
                  class="flex-1"
                  icon="format_line_spacing"
                  :model-value="singleItem.style_data?.line_height ?? 1"
                  :min="0.5" :max="5" :step="0.01" :precision="2"
                  :default-value="1"
                  @update:model-value="updateItemStyleData({ line_height: $event })"
                />
              </div>

              <!-- Letter Spacing — label | control row -->
              <div class="flex items-center gap-1.5">
                <span class="text-[9px] text-surface-400 uppercase tracking-wider font-medium w-8 flex-shrink-0">Sp</span>
                <MoodScrubInput
                  class="flex-1"
                  icon="format_letter_spacing"
                  :model-value="singleItem.style_data?.letter_spacing ?? 0"
                  :min="-10" :max="100" :step="0.5" :precision="1"
                  :default-value="0"
                  @update:model-value="updateItemStyleData({ letter_spacing: $event })"
                />
              </div>

              <!-- Text Alignment — fused segmented bar -->
              <div class="flex items-center gap-1.5">
                <span class="text-[9px] text-surface-400 uppercase tracking-wider font-medium w-8 flex-shrink-0">Align</span>
                <div class="inline-flex border border-surface-200 dark:border-surface-600 rounded-lg overflow-hidden bg-surface-50 dark:bg-surface-700">
                  <button
                    v-for="a in textAlignOptions"
                    :key="a.value"
                    @click="updateItemStyleData({ text_align: a.value })"
                    class="flex items-center justify-center w-7 h-7 transition-colors border-r border-surface-200 dark:border-surface-600 last:border-r-0"
                    :class="(singleItem.style_data?.text_align || 'left') === a.value
                      ? 'bg-surface-200 dark:bg-surface-600 text-surface-900 dark:text-white'
                      : 'text-surface-400 hover:text-surface-900 dark:hover:text-white hover:bg-surface-100 dark:hover:bg-surface-600'"
                    :title="a.label"
                  >
                    <span class="material-symbols-rounded text-sm">{{ a.icon }}</span>
                  </button>
                </div>
                <div class="inline-flex border border-surface-200 dark:border-surface-600 rounded-lg overflow-hidden bg-surface-50 dark:bg-surface-700">
                  <button
                    v-for="tt in textTransformOptions"
                    :key="tt.value"
                    @click="updateItemStyleData({ text_transform: (singleItem.style_data?.text_transform || 'none') === tt.value ? 'none' : tt.value })"
                    class="flex items-center justify-center w-7 h-7 transition-colors border-r border-surface-200 dark:border-surface-600 last:border-r-0"
                    :class="(singleItem.style_data?.text_transform || 'none') === tt.value
                      ? 'bg-surface-200 dark:bg-surface-600 text-surface-900 dark:text-white'
                      : 'text-surface-400 hover:text-surface-900 dark:hover:text-white hover:bg-surface-100 dark:hover:bg-surface-600'"
                    :title="tt.label"
                  >
                    <span class="material-symbols-rounded text-sm">{{ tt.icon }}</span>
                  </button>
                </div>
                <div class="flex-1" />
                <div class="relative" ref="sidebarLoremRef">
                  <button
                    @click.stop="showSidebarLorem = !showSidebarLorem"
                    class="flex items-center justify-center w-7 h-7 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-400 transition-colors"
                    title="Insert Lorem Ipsum"
                  >
                    <span class="material-symbols-rounded text-sm">notes</span>
                  </button>
                  <div
                    v-if="showSidebarLorem"
                    class="absolute bottom-full right-0 mb-1 w-44 bg-white dark:bg-surface-800 border border-surface-200 dark:border-surface-700 rounded-lg shadow-2xl z-50 py-1"
                  >
                    <div class="px-3 py-1 text-[9px] uppercase tracking-wider text-surface-400 font-semibold">Insert Lorem Ipsum</div>
                    <button
                      v-for="opt in loremMenuOptions"
                      :key="opt.label"
                      @click.stop="insertLoremText(opt.type)"
                      class="w-full text-left px-3 py-1.5 text-xs text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700 flex items-center gap-2"
                    >
                      <span class="material-symbols-rounded text-sm text-surface-400">{{ opt.icon }}</span>
                      {{ opt.label }}
                    </button>
                  </div>
                </div>
              </div>

              <div class="border-t border-surface-200 dark:border-surface-700" />

              <!-- Fill — label | control row -->
              <div class="flex items-center gap-1.5">
                <span class="text-[9px] text-surface-400 uppercase tracking-wider font-medium w-8 flex-shrink-0">Fill</span>
                <div class="flex-1">
                  <MoodGradientPicker
                    :fill-type="singleItem.style_data?.text_fill_type || 'solid'"
                    :color="singleItem.style_data?.text_color || '#ffffff'"
                    :gradient="singleItem.style_data?.text_fill_gradient || { angle: 180, stops: [{ color: '#ffffff', position: 0 }, { color: '#6366f1', position: 100 }] }"
                    :palette="store.getColorPalette()"
                    :allow-transparent="true"
                    @update:fill-type="updateItemStyleData({ text_fill_type: $event })"
                    @update:color="updateItemStyleData({ text_color: $event })"
                    @update:gradient="updateItemStyleData({ text_fill_gradient: $event })"
                  />
                </div>
                <span
                  v-if="linkedTextColorGlobal"
                  class="text-[9px] font-medium text-primary-600 dark:text-primary-400 bg-primary-50 dark:bg-primary-900/20 px-1.5 py-0.5 rounded-full flex items-center gap-0.5 flex-shrink-0"
                  :title="`Linked to: ${linkedTextColorGlobal.name}`"
                >
                  <span class="material-symbols-rounded" style="font-size: 10px;">link</span>
                  {{ linkedTextColorGlobal.name }}
                </span>
                <MoodSaveStyleDialog
                  type="color"
                  :color="singleItem.style_data?.text_color || '#000000'"
                  title="Save text color to globals"
                  name-placeholder="e.g. Text Primary..."
                  @save="saveColorToGlobals($event)"
                />
              </div>

              <!-- Image Clip — drop image to clip text -->
              <div class="flex items-center gap-1.5">
                <span class="text-[9px] text-surface-400 uppercase tracking-wider font-medium w-8 flex-shrink-0">Clip</span>
                <div class="flex items-center gap-1.5 flex-1">
                  <template v-if="singleItem.style_data?.text_clip_image">
                    <div class="w-7 h-7 rounded border border-surface-300 dark:border-surface-600 overflow-hidden flex-shrink-0">
                      <img :src="singleItem.style_data.text_clip_image" class="w-full h-full object-cover" />
                    </div>
                    <select
                      :value="singleItem.style_data?.text_clip_image_size || 'cover'"
                      @change="updateItemStyleData({ text_clip_image_size: $event.target.value })"
                      class="flex-1 text-[10px] bg-surface-50 dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-lg px-1.5 py-1 text-surface-600 dark:text-surface-300"
                    >
                      <option value="cover">Cover</option>
                      <option value="contain">Contain</option>
                      <option value="100% 100%">Stretch</option>
                    </select>
                    <button
                      @click="updateItemStyleData({ text_clip_image: null, text_clip_image_size: null })"
                      class="p-1 rounded-md text-surface-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors"
                      title="Remove clip image"
                    >
                      <span class="material-symbols-rounded text-sm">close</span>
                    </button>
                  </template>
                  <template v-else>
                    <label class="flex items-center gap-1.5 flex-1 px-2 py-1 rounded-lg border border-dashed border-surface-300 dark:border-surface-600 text-[10px] text-surface-400 hover:border-primary-400 hover:text-primary-500 cursor-pointer transition-colors">
                      <span class="material-symbols-rounded text-sm">image</span>
                      <span>Drop or click to clip</span>
                      <input type="file" accept="image/*" class="hidden" @change="onTextClipImagePick($event)" />
                    </label>
                  </template>
                </div>
              </div>

              <!-- Stroke — label | control row -->
              <div class="flex items-center gap-1.5">
                <span class="text-[9px] text-surface-400 uppercase tracking-wider font-medium w-8 flex-shrink-0">Stk</span>
                <div class="flex items-center gap-1.5 flex-1">
                  <MoodColorPicker
                    :model-value="singleItem.style_data?.text_stroke_color || 'transparent'"
                    @update:model-value="updateItemStyleData({ text_stroke_color: $event })"
                    :palette="store.getColorPalette()"
                    :allow-transparent="true"
                    label="Text stroke color (double-click to remove)"
                    :show-caret="false"
                  />
                  <MoodScrubInput
                    class="flex-1"
                    icon="line_weight"
                    suffix="px"
                    :model-value="singleItem.style_data?.text_stroke_width || 0"
                    :min="0" :max="20" :step="0.5" :precision="1"
                    :default-value="0"
                    @update:model-value="updateItemStyleData({ text_stroke_width: $event })"
                  />
                </div>
              </div>

            </div>
          </SidebarSection>
        </template>

        <!-- ============================================ -->
        <!-- APPEARANCE (Figma-style: Opacity + Blend) — not for slides -->
        <!-- ============================================ -->
        <SidebarSection v-if="singleItem.type !== 'slide' && singleItem.type !== 'file' && singleItem.type !== 'audio'" title="Appearance" icon="tune" :open="true">
          <div class="space-y-2">
            <!-- Opacity — label | control row -->
            <div class="flex items-center gap-1.5">
              <span class="text-[9px] text-surface-400 uppercase tracking-wider font-medium w-8 flex-shrink-0">Opac</span>
              <div class="flex items-center flex-1 min-w-0 overflow-hidden bg-surface-50 dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-lg h-[28px]">
                <span class="material-symbols-rounded text-[13px] text-surface-400 pl-1.5 flex-shrink-0">opacity</span>
                <input
                  type="range"
                  :value="appearanceOpacity"
                  min="0" max="100" step="1"
                  @input="setAppearanceOpacity(parseInt($event.target.value))"
                  class="flex-1 min-w-0 h-1 mx-1 accent-primary-500 cursor-pointer"
                />
                <input
                  type="number"
                  :value="appearanceOpacity"
                  min="0" max="100"
                  inputmode="numeric"
                  @change="setAppearanceOpacity(parseInt($event.target.value) || 100)"
                  class="w-7 flex-shrink-0 text-[10px] bg-transparent focus:outline-none text-surface-700 dark:text-surface-300 text-right [appearance:textfield] [&::-webkit-outer-spin-button]:appearance-none [&::-webkit-inner-spin-button]:appearance-none"
                />
                <span class="text-[9px] text-surface-400 pr-1 flex-shrink-0">%</span>
              </div>
            </div>
            <!-- Blend mode — label | control row -->
            <div class="flex items-center gap-1.5">
              <span class="text-[9px] text-surface-400 uppercase tracking-wider font-medium w-8 flex-shrink-0">Blend</span>
              <select
                :value="singleItem.style_data?.blend_mode || 'normal'"
                @change="updateItemStyleData({ blend_mode: $event.target.value })"
                class="flex-1 text-xs bg-surface-50 dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-lg px-2 h-[28px] text-surface-700 dark:text-surface-300 focus:outline-none focus:ring-1 focus:ring-primary-400 capitalize"
              >
                <template v-for="group in blendModeGroups" :key="group.label || 'default'">
                  <optgroup v-if="group.label" :label="group.label">
                    <option v-for="bm in group.modes" :key="bm" :value="bm">{{ bm.replace(/-/g, ' ') }}</option>
                  </optgroup>
                  <template v-else>
                    <option v-for="bm in group.modes" :key="bm" :value="bm">{{ bm.replace(/-/g, ' ') }}</option>
                  </template>
                </template>
              </select>
            </div>
          </div>
        </SidebarSection>

        <!-- ============================================ -->
        <!-- UNIFIED APPEARANCE SECTIONS (Figma-style)    -->
        <!-- ============================================ -->

        <!-- Fill (shapes, pen shapes, frames) -->
        <SidebarSection
          v-if="singleItem.type === 'shape' || singleItem.type === 'pen_shape' || singleItem.type === 'frame'"
          title="Fill" icon="format_paint" :open="true"
        >
          <template #header-right>
            <span
              v-if="linkedFillGlobal"
              class="text-[9px] font-medium text-primary-600 dark:text-primary-400 bg-primary-50 dark:bg-primary-900/20 px-1.5 py-0.5 rounded-full flex items-center gap-0.5"
              :title="`Linked to global: ${linkedFillGlobal.name}`"
            >
              <span class="material-symbols-rounded" style="font-size: 10px;">link</span>
              {{ linkedFillGlobal.name }}
            </span>
            <span
              v-else-if="linkedGradientGlobal"
              class="text-[9px] font-medium text-primary-600 dark:text-primary-400 bg-primary-50 dark:bg-primary-900/20 px-1.5 py-0.5 rounded-full flex items-center gap-0.5"
              :title="`Linked to global: ${linkedGradientGlobal.name}`"
            >
              <span class="material-symbols-rounded" style="font-size: 10px;">link</span>
              {{ linkedGradientGlobal.name }}
            </span>
            <MoodSaveStyleDialog
              v-if="currentFillIsGradient && currentFillGradient?.stops?.length"
              type="gradient"
              :gradient="currentFillGradient"
              title="Save gradient to globals"
              name-placeholder="e.g. Brand gradient..."
              @save="saveGradientToGlobals($event)"
            />
            <MoodSaveStyleDialog
              v-else
              type="color"
              :color="singleItem.style_data?.shape_fill || singleItem.style_data?.fill_color || '#000000'"
              title="Save color to globals"
              name-placeholder="e.g. Primary, Accent..."
              @save="saveColorToGlobals($event)"
            />
          </template>
          <MoodFillSection :item="singleItem" hide-opacity @update-style-data="updateItemStyleData" />
        </SidebarSection>

        <!-- Stroke (shapes, pen shapes, frames) -->
        <SidebarSection
          v-if="singleItem.type === 'shape' || singleItem.type === 'pen_shape' || singleItem.type === 'frame'"
          title="Stroke" icon="border_color" :open="true"
        >
          <MoodStrokeSection :item="singleItem" @update-style-data="updateItemStyleData" />
        </SidebarSection>

        <!-- Corner Radius (shapes, images, frames) -->
        <SidebarSection
          v-if="singleItem.type === 'shape' || singleItem.type === 'image' || singleItem.type === 'frame'"
          title="Corner Radius" icon="rounded_corner" :open="false"
        >
          <!-- Shape corners (linked/unlinked, 4 independent values) -->
          <template v-if="singleItem.type === 'shape'">
            <div class="space-y-2">
              <div class="flex items-center gap-2">
                <button
                  @click="shapeCornersLinked = !shapeCornersLinked"
                  class="p-1 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700"
                  :class="shapeCornersLinked ? 'text-primary-500' : 'text-surface-400'"
                  :title="shapeCornersLinked ? 'Corners linked' : 'Corners unlinked'"
                >
                  <span class="material-symbols-rounded text-sm">{{ shapeCornersLinked ? 'link' : 'link_off' }}</span>
                </button>
                <span class="text-[10px] text-surface-500">{{ shapeCornersLinked ? 'All corners linked' : 'Individual corners' }}</span>
              </div>
              <template v-if="shapeCornersLinked">
                <div class="flex items-center gap-2">
                  <input
                    type="range"
                    :value="shapeRadiusAll"
                    min="0" max="100" step="1"
                    @input="setAllCornerRadius(parseInt($event.target.value))"
                    class="flex-1 h-1 accent-primary-500 cursor-pointer"
                  />
                  <span class="text-[10px] text-surface-400 min-w-[18px] text-right">{{ shapeRadiusAll }}</span>
                </div>
              </template>
              <template v-else>
                <div class="grid grid-cols-2 gap-2">
                  <div v-for="c in cornerDefs" :key="c.key">
                    <MoodScrubInput
                      :label="c.label"
                      :model-value="shapeCorners[c.key]"
                      :min="0" :max="200"
                      suffix="px"
                      @update:model-value="setCornerRadius(c.key, $event)"
                    />
                  </div>
                </div>
              </template>
            </div>
          </template>
          <!-- Frame corners (linked/unlinked like shapes) -->
          <template v-else-if="singleItem.type === 'frame'">
            <div class="space-y-2">
              <div class="flex items-center gap-2">
                <button
                  @click="shapeCornersLinked = !shapeCornersLinked"
                  class="p-1 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700"
                  :class="shapeCornersLinked ? 'text-primary-500' : 'text-surface-400'"
                  :title="shapeCornersLinked ? 'Corners linked' : 'Corners unlinked'"
                >
                  <span class="material-symbols-rounded text-sm">{{ shapeCornersLinked ? 'link' : 'link_off' }}</span>
                </button>
                <span class="text-[10px] text-surface-500">{{ shapeCornersLinked ? 'All corners linked' : 'Individual corners' }}</span>
              </div>
              <template v-if="shapeCornersLinked">
                <div class="flex items-center gap-2">
                  <input
                    type="range"
                    :value="shapeRadiusAll"
                    min="0" max="100" step="1"
                    @input="setAllCornerRadius(parseInt($event.target.value))"
                    class="flex-1 h-1 accent-primary-500 cursor-pointer"
                  />
                  <span class="text-[10px] text-surface-400 min-w-[18px] text-right">{{ shapeRadiusAll }}</span>
                </div>
              </template>
              <template v-else>
                <div class="grid grid-cols-2 gap-2">
                  <div v-for="c in cornerDefs" :key="c.key">
                    <MoodScrubInput
                      :label="c.label"
                      :model-value="shapeCorners[c.key]"
                      :min="0" :max="200"
                      suffix="px"
                      @update:model-value="setCornerRadius(c.key, $event)"
                    />
                  </div>
                </div>
              </template>
            </div>
          </template>
          <!-- Image corners (single slider with presets) -->
          <template v-else-if="singleItem.type === 'image'">
            <div class="space-y-2">
              <div class="flex items-center gap-2">
                <input
                  type="range"
                  :value="singleItem.style_data?.border_radius ?? 12"
                  min="0" max="100" step="1"
                  @input="updateItemStyleData({ border_radius: parseInt($event.target.value) })"
                  class="flex-1 h-1 accent-primary-500 cursor-pointer"
                />
                <MoodScrubInput
                  class="w-16"
                  suffix="px"
                  :model-value="singleItem.style_data?.border_radius ?? 12"
                  :min="0" :max="999"
                  @update:model-value="updateItemStyleData({ border_radius: $event })"
                />
              </div>
              <div class="flex gap-1">
                <button
                  v-for="preset in [0, 8, 16, 24, 50]"
                  :key="preset"
                  @click="updateItemStyleData({ border_radius: preset })"
                  class="flex-1 py-1 text-[10px] rounded-md border transition-colors"
                  :class="(singleItem.style_data?.border_radius ?? 12) === preset
                    ? 'bg-primary-50 dark:bg-primary-900/30 border-primary-300 dark:border-primary-700 text-primary-600 dark:text-primary-400 font-semibold'
                    : 'bg-surface-50 dark:bg-surface-700 border-surface-200 dark:border-surface-600 text-surface-500 hover:bg-surface-100 dark:hover:bg-surface-600'"
                >
                  {{ preset === 0 ? 'Sharp' : preset + 'px' }}
                </button>
              </div>
            </div>
          </template>
        </SidebarSection>

        <!-- Effects (drop shadow, blur) -->
        <SidebarSection
          v-if="singleItem.type !== 'slide' && singleItem.type !== 'file' && singleItem.type !== 'line' && singleItem.type !== 'audio'"
          title="Effects" icon="auto_awesome"
          :open="!!(singleItem.style_data?.shadow_enabled || singleItem.style_data?.blur_enabled || singleItem.style_data?.backdrop_blur_enabled || singleItem.style_data?.text_shadow_enabled)"
        >
          <MoodEffectsSection :item="singleItem" @update-style-data="updateItemStyleData" />
        </SidebarSection>

        <!-- ============================================ -->
        <!-- TYPE-SPECIFIC SECTIONS                       -->
        <!-- ============================================ -->

        <!-- NOTE ITEM: Note color -->
        <template v-if="singleItem.type === 'note'">
          <SidebarSection title="Note Color" icon="palette" :open="true">
            <div class="flex flex-wrap gap-1.5">
              <button
                v-for="c in noteColors"
                :key="c"
                @click="updateSingleItem({ color: c })"
                class="w-8 h-8 rounded-lg border-2 transition-all hover:scale-110"
                :class="singleItem.color === c ? 'border-primary-500 ring-2 ring-primary-200 dark:ring-primary-800' : 'border-surface-200 dark:border-surface-600'"
                :style="{ backgroundColor: c }"
              />
            </div>
          </SidebarSection>
          <SidebarSection title="Font Size" icon="format_size" :open="true">
            <div class="flex items-center gap-2">
              <input
                type="range"
                :value="itemFontSize"
                min="8"
                max="72"
                step="1"
                @input="setItemFontSize(parseInt($event.target.value))"
                class="flex-1 h-1 accent-primary-500 cursor-pointer"
              />
              <span class="text-xs text-surface-600 dark:text-surface-400 min-w-[30px] text-right tabular-nums">{{ itemFontSize }}px</span>
            </div>
          </SidebarSection>
        </template>

        <!-- SHAPE ITEM: Shape-specific features -->
        <template v-if="singleItem.type === 'shape' || singleItem.type === 'pen_shape'">
          <!-- Shape Text (full typography matching normal text) -->
          <SidebarSection title="Text in Shape" icon="text_fields" :open="false">
            <div class="space-y-2.5">
              <!-- Text content -->
              <div>
                <label class="text-[9px] text-surface-400 uppercase tracking-wider font-medium">Content</label>
                <textarea
                  :value="singleItem.content || ''"
                  @input="store.updateItem(singleItem.id, { content: $event.target.value })"
                  class="w-full mt-0.5 px-2 py-1.5 text-xs bg-surface-50 dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-lg focus:outline-none focus:ring-1 focus:ring-primary-400 text-surface-700 dark:text-surface-300 resize-none"
                  rows="2"
                  placeholder="Type text..."
                />
              </div>

              <!-- Color -->
              <div class="flex items-center gap-2">
                <MoodColorPicker
                  :model-value="singleItem.style_data?.shape_text_color || '#ffffff'"
                  @update:model-value="updateItemStyleData({ shape_text_color: $event })"
                  :palette="store.getColorPalette()"
                  label="Text color"
                  :show-caret="false"
                  dropdown-position="top-full left-0"
                  :contrast-bg="singleItem.style_data?.shape_fill || '#6366f1'"
                />
                <span class="text-[9px] text-surface-400 font-medium">Text Color</span>
              </div>

              <!-- Font Family + Weight (side by side) -->
              <div class="flex items-center gap-1.5">
                <div class="relative flex-[3] min-w-0" ref="shapeFontDropdownRef">
                  <button
                    @click.stop="showShapeFontDropdown = !showShapeFontDropdown"
                    class="flex items-center justify-between w-full px-2 py-1.5 bg-surface-50 dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-lg text-[11px] text-surface-700 dark:text-surface-300 hover:border-primary-400 transition-colors"
                    :style="{ fontFamily: shapeFontFamily }"
                  >
                    <span class="truncate">{{ shapeFontLabel }}</span>
                    <span class="material-symbols-rounded text-[12px] text-surface-400 flex-shrink-0">expand_more</span>
                  </button>
                  <div
                    v-if="showShapeFontDropdown"
                    class="absolute top-full left-0 mt-1 w-52 max-h-64 overflow-y-auto bg-white dark:bg-surface-800 border border-surface-200 dark:border-surface-700 rounded-xl shadow-2xl z-50 py-1"
                  >
                    <div class="px-2 py-1.5 sticky top-0 bg-white dark:bg-surface-800 border-b border-surface-200 dark:border-surface-700">
                      <input
                        v-model="shapeFontSearch"
                        placeholder="Search fonts..."
                        class="w-full bg-surface-100 dark:bg-surface-700 rounded-lg px-2 py-1 text-xs text-surface-700 dark:text-surface-300 focus:outline-none"
                        ref="shapeFontSearchInput"
                      />
                    </div>
                    <div v-for="group in shapeFilteredFontGroups" :key="group.label" class="py-0.5">
                      <div class="px-3 py-1 text-[9px] uppercase tracking-wider text-surface-400 font-semibold">{{ group.label }}</div>
                      <button
                        v-for="f in group.fonts"
                        :key="f.value"
                        @click.stop="setShapeFont(f.value)"
                        class="w-full text-left px-3 py-1.5 text-xs hover:bg-surface-100 dark:hover:bg-surface-700 flex items-center justify-between"
                        :class="shapeFontFamily === f.value ? 'bg-primary-50 dark:bg-primary-900/20 text-primary-600 dark:text-primary-400' : 'text-surface-700 dark:text-surface-300'"
                        :style="{ fontFamily: f.value }"
                      >
                        <span>{{ f.label }}</span>
                        <span v-if="shapeFontFamily === f.value" class="material-symbols-rounded text-sm text-primary-500">check</span>
                      </button>
                    </div>
                  </div>
                </div>
                <div class="relative flex-[2] min-w-0" ref="shapeWeightDropdownRef">
                  <button
                    @click.stop="showShapeWeightDropdown = !showShapeWeightDropdown"
                    class="flex items-center justify-between w-full px-2 py-1.5 bg-surface-50 dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-lg text-[11px] text-surface-700 dark:text-surface-300 hover:border-primary-400 transition-colors"
                    :style="{ fontWeight: shapeFontWeight }"
                  >
                    <span class="truncate">{{ shapeFontWeightLabel }}</span>
                    <span class="material-symbols-rounded text-[12px] text-surface-400 flex-shrink-0">expand_more</span>
                  </button>
                  <div
                    v-if="showShapeWeightDropdown"
                    class="absolute top-full right-0 mt-1 w-40 max-h-56 overflow-y-auto bg-white dark:bg-surface-800 border border-surface-200 dark:border-surface-700 rounded-xl shadow-2xl z-50 py-1"
                  >
                    <button
                      v-for="w in typographyWeights"
                      :key="w.value"
                      @click.stop="setShapeWeight(w.value)"
                      class="w-full text-left px-3 py-1.5 text-xs hover:bg-surface-100 dark:hover:bg-surface-700 flex items-center justify-between"
                      :class="shapeFontWeight === w.value ? 'bg-primary-50 dark:bg-primary-900/20 text-primary-600 dark:text-primary-400' : 'text-surface-700 dark:text-surface-300'"
                      :style="{ fontWeight: w.value }"
                    >
                      <span>{{ w.label }}</span>
                      <span v-if="shapeFontWeight === w.value" class="material-symbols-rounded text-sm text-primary-500">check</span>
                    </button>
                  </div>
                </div>
              </div>

              <!-- Font Size -->
              <div class="flex items-center gap-1.5">
                <MoodScrubInput
                  class="flex-1"
                  icon="format_size"
                  suffix="px"
                  :model-value="singleItem.style_data?.shape_font_size || 16"
                  :min="1" :max="500"
                  :default-value="16"
                  @update:model-value="updateItemStyleData({ shape_font_size: $event })"
                />
              </div>

              <!-- Line height + Letter spacing (side by side) -->
              <div class="grid grid-cols-2 gap-1.5">
                <div>
                  <div class="text-[9px] text-surface-400 font-medium mb-0.5">Line height</div>
                  <MoodScrubInput
                    icon="format_line_spacing"
                    :model-value="singleItem.style_data?.shape_line_height ?? 1"
                    :min="0.5" :max="5" :step="0.01" :precision="2"
                    :default-value="1"
                    @update:model-value="updateItemStyleData({ shape_line_height: $event })"
                  />
                </div>
                <div>
                  <div class="text-[9px] text-surface-400 font-medium mb-0.5">Letter spacing</div>
                  <MoodScrubInput
                    icon="format_letter_spacing"
                    :model-value="singleItem.style_data?.shape_letter_spacing ?? 0"
                    :min="-10" :max="100" :step="0.5" :precision="1"
                    :default-value="0"
                    @update:model-value="updateItemStyleData({ shape_letter_spacing: $event })"
                  />
                </div>
              </div>

              <!-- Alignment + Text Transform + Vertical Align + Lorem -->
              <div>
                <div class="text-[9px] text-surface-400 font-medium mb-1">Alignment</div>
                <div class="flex items-center gap-0.5">
                  <!-- Horizontal alignment -->
                  <button
                    v-for="a in textAlignOptions"
                    :key="'shape-' + a.value"
                    @click="updateItemStyleData({ shape_text_align: a.value })"
                    class="p-1.5 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
                    :class="(singleItem.style_data?.shape_text_align || 'center') === a.value ? 'bg-surface-200 dark:bg-surface-600 text-surface-900 dark:text-white' : 'text-surface-400'"
                    :title="a.label"
                  >
                    <span class="material-symbols-rounded text-sm">{{ a.icon }}</span>
                  </button>
                  <div class="w-px h-4 bg-surface-200 dark:bg-surface-600 mx-0.5" />
                  <!-- Text transform (individual buttons) -->
                  <button
                    v-for="tt in textTransformOptions"
                    :key="'shape-' + tt.value"
                    @click="updateItemStyleData({ shape_text_transform: (singleItem.style_data?.shape_text_transform || 'none') === tt.value ? 'none' : tt.value })"
                    class="p-1.5 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
                    :class="(singleItem.style_data?.shape_text_transform || 'none') === tt.value ? 'bg-surface-200 dark:bg-surface-600 text-surface-900 dark:text-white' : 'text-surface-400'"
                    :title="tt.label"
                  >
                    <span class="material-symbols-rounded text-sm">{{ tt.icon }}</span>
                  </button>
                  <div class="flex-1" />
                  <!-- Lorem ipsum -->
                  <div class="relative" ref="shapeLoremRef">
                    <button
                      @click.stop="showShapeLorem = !showShapeLorem"
                      class="p-1.5 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-400 transition-colors"
                      title="Insert Lorem Ipsum"
                    >
                      <span class="material-symbols-rounded text-sm">notes</span>
                    </button>
                    <div
                      v-if="showShapeLorem"
                      class="absolute bottom-full right-0 mb-1 w-44 bg-white dark:bg-surface-800 border border-surface-200 dark:border-surface-700 rounded-xl shadow-2xl z-50 py-1"
                    >
                      <div class="px-3 py-1 text-[9px] uppercase tracking-wider text-surface-400 font-semibold">Insert Lorem Ipsum</div>
                      <button
                        v-for="opt in loremMenuOptions"
                        :key="'shape-' + opt.label"
                        @click.stop="insertShapeLoremText(opt.type)"
                        class="w-full text-left px-3 py-1.5 text-xs text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700 flex items-center gap-2"
                      >
                        <span class="material-symbols-rounded text-sm text-surface-400">{{ opt.icon }}</span>
                        {{ opt.label }}
                      </button>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Vertical align -->
              <div>
                <div class="text-[9px] text-surface-400 font-medium mb-1">Vertical Align</div>
                <div class="flex items-center gap-0.5">
                  <button
                    v-for="va in [{ v: 'flex-start', i: 'vertical_align_top', l: 'Top' }, { v: 'center', i: 'vertical_align_center', l: 'Middle' }, { v: 'flex-end', i: 'vertical_align_bottom', l: 'Bottom' }]"
                    :key="va.v"
                    @click="updateItemStyleData({ shape_text_valign: va.v })"
                    class="p-1.5 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
                    :class="(singleItem.style_data?.shape_text_valign || 'center') === va.v ? 'bg-surface-200 dark:bg-surface-600 text-surface-900 dark:text-white' : 'text-surface-400'"
                    :title="va.l"
                  >
                    <span class="material-symbols-rounded text-sm">{{ va.i }}</span>
                  </button>
                </div>
              </div>
            </div>
          </SidebarSection>

          <SidebarSection title="Blend Mode" icon="layers" :open="false">
            <select
              :value="singleItem.style_data?.blend_mode || 'normal'"
              @change="updateItemStyleData({ blend_mode: $event.target.value })"
              class="w-full text-xs bg-surface-50 dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-lg px-2 py-1.5 text-surface-700 dark:text-surface-300 capitalize"
            >
              <template v-for="group in blendModeGroups" :key="group.label || 'default'">
                <optgroup v-if="group.label" :label="group.label">
                  <option v-for="bm in group.modes" :key="bm" :value="bm">{{ bm.replace(/-/g, ' ') }}</option>
                </optgroup>
                <template v-else>
                  <option v-for="bm in group.modes" :key="bm" :value="bm">{{ bm.replace(/-/g, ' ') }}</option>
                </template>
              </template>
            </select>
          </SidebarSection>

          <!-- Mask Image -->
          <SidebarSection title="Image Mask" icon="photo_frame" :open="false">
            <template v-if="singleItem.style_data?.mask_image_url">
              <div class="space-y-2">
                <img :src="singleItem.style_data.mask_image_url" class="w-full h-20 object-cover rounded-lg border border-surface-200 dark:border-surface-600" />
                <div class="flex gap-1">
                  <button
                    v-for="fit in maskFitOptions"
                    :key="fit.value"
                    @click="updateItemStyleData({ mask_image_fit: fit.value })"
                    class="flex-1 flex flex-col items-center gap-0.5 px-1.5 py-1.5 text-[10px] rounded-lg transition-colors"
                    :class="(singleItem.style_data?.mask_image_fit || 'cover') === fit.value
                      ? 'bg-primary-50 dark:bg-primary-900/30 text-primary-600 dark:text-primary-400 border border-primary-300 dark:border-primary-700'
                      : 'bg-surface-50 dark:bg-surface-700 text-surface-500 hover:bg-surface-100 dark:hover:bg-surface-600 border border-transparent'"
                    :title="fit.label"
                  >
                    <span class="material-symbols-rounded text-sm">{{ fit.icon }}</span>
                    {{ fit.label }}
                  </button>
                  <button
                    @click="removeMaskImage"
                    class="px-2 py-1.5 text-xs rounded-lg text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors flex items-center"
                    title="Remove mask"
                  >
                    <span class="material-symbols-rounded text-sm">delete</span>
                  </button>
                </div>
              </div>
            </template>
            <template v-else>
              <label class="flex items-center justify-center gap-2 w-full px-3 py-3 rounded-lg border-2 border-dashed border-surface-200 dark:border-surface-600 text-surface-500 hover:border-primary-400 hover:text-primary-500 cursor-pointer transition-colors text-xs">
                <span class="material-symbols-rounded text-lg">add_photo_alternate</span>
                Add Image Mask
                <input type="file" accept="image/*" class="hidden" @change="onAddMaskImage($event)" />
              </label>
            </template>
          </SidebarSection>
        </template>

        <!-- IMAGE ITEM: Preview, Corners, Blend, Replace/Remove -->
        <template v-if="singleItem.type === 'image'">
          <!-- Image Preview -->
          <SidebarSection title="Image" icon="image" :open="true">
            <div class="space-y-3">
              <!-- Current image thumbnail -->
              <div v-if="singleItem.image_url" class="relative group/img">
                <img
                  :src="singleItem.image_url"
                  :alt="singleItem.title || 'Image'"
                  class="w-full h-28 object-cover rounded-lg border border-surface-200 dark:border-surface-600"
                />
                <div class="absolute inset-0 bg-black/40 opacity-0 group-hover/img:opacity-100 transition-opacity rounded-lg flex items-center justify-center gap-2">
                  <button
                    @click="$emit('preview-file', singleItem)"
                    class="p-1.5 rounded-full bg-white/20 hover:bg-white/40 text-white transition-colors"
                    title="Preview"
                  >
                    <span class="material-symbols-rounded text-base">visibility</span>
                  </button>
                </div>
              </div>
              <div v-else class="flex flex-col items-center gap-2 py-4 rounded-lg border border-dashed border-surface-300 dark:border-surface-600 text-surface-400">
                <span class="material-symbols-rounded text-2xl">image</span>
                <span class="text-[10px]">No image</span>
              </div>
              <!-- File name -->
              <p v-if="singleItem.title" class="text-[10px] text-surface-500 dark:text-surface-400 truncate">
                {{ singleItem.title }}
              </p>
              <!-- Replace / Remove buttons -->
              <div class="flex gap-1.5">
                <label class="flex-1 flex items-center justify-center gap-1.5 px-2 py-1.5 text-xs rounded-lg bg-surface-50 dark:bg-surface-700 text-surface-600 dark:text-surface-400 hover:bg-primary-50 dark:hover:bg-primary-900/20 hover:text-primary-600 dark:hover:text-primary-400 border border-surface-200 dark:border-surface-600 cursor-pointer transition-colors">
                  <span class="material-symbols-rounded text-sm">swap_horiz</span>
                  Replace
                  <input type="file" accept="image/*" class="hidden" @change="onReplaceImage($event)" />
                </label>
                <button
                  v-if="singleItem.image_url"
                  @click="onRemoveImage"
                  class="flex items-center justify-center gap-1.5 px-2 py-1.5 text-xs rounded-lg bg-surface-50 dark:bg-surface-700 text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 border border-surface-200 dark:border-surface-600 transition-colors"
                >
                  <span class="material-symbols-rounded text-sm">delete</span>
                  Remove
                </button>
              </div>
            </div>
          </SidebarSection>

          <!-- Image Fit -->
          <SidebarSection title="Image Fit" icon="aspect_ratio" :open="true">
            <div class="flex gap-1">
              <button
                v-for="fit in imageFitOptions"
                :key="fit.value"
                @click="updateItemStyleData({ image_fit: fit.value })"
                class="flex-1 flex flex-col items-center gap-0.5 px-1 py-1.5 text-[10px] rounded-lg transition-colors"
                :class="(singleItem.style_data?.image_fit || 'cover') === fit.value
                  ? 'bg-primary-50 dark:bg-primary-900/30 text-primary-600 dark:text-primary-400 border border-primary-300 dark:border-primary-700'
                  : 'bg-surface-50 dark:bg-surface-700 text-surface-500 hover:bg-surface-100 dark:hover:bg-surface-600 border border-transparent'"
                :title="fit.label"
              >
                <span class="material-symbols-rounded text-sm">{{ fit.icon }}</span>
                {{ fit.label }}
              </button>
            </div>
          </SidebarSection>

          <!-- Blend Mode -->
          <SidebarSection title="Blend Mode" icon="layers" :open="true">
            <select
              :value="singleItem.style_data?.blend_mode || 'normal'"
              @change="updateItemStyleData({ blend_mode: $event.target.value })"
              class="w-full text-xs bg-surface-50 dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-lg px-2 py-1.5 text-surface-700 dark:text-surface-300 capitalize"
            >
              <template v-for="group in blendModeGroups" :key="group.label || 'default'">
                <optgroup v-if="group.label" :label="group.label">
                  <option v-for="bm in group.modes" :key="bm" :value="bm">{{ bm.replace(/-/g, ' ') }}</option>
                </optgroup>
                <template v-else>
                  <option v-for="bm in group.modes" :key="bm" :value="bm">{{ bm.replace(/-/g, ' ') }}</option>
                </template>
              </template>
            </select>
          </SidebarSection>
        </template>

        <!-- DRAWING ITEM: Color + Scale -->
        <template v-if="singleItem.type === 'drawing'">
          <SidebarSection title="Drawing Color" icon="palette" :open="true">
            <div class="space-y-2.5">
              <!-- Default preset colors -->
              <div>
                <div class="text-[9px] text-surface-400 uppercase tracking-wider font-medium mb-1">Presets</div>
                <div class="flex flex-wrap gap-1.5">
                  <button
                    v-for="c in drawingColors"
                    :key="c"
                    @click="recolorDrawing(c)"
                    class="w-6 h-6 rounded-full border-2 transition-all hover:scale-110 flex-shrink-0"
                    :class="singleItem.color === c ? 'border-primary-500 ring-2 ring-primary-300/50' : 'border-surface-200 dark:border-surface-600'"
                    :style="{ backgroundColor: c }"
                    :title="c"
                  />
                </div>
              </div>
              <!-- Saved palette colors -->
              <div v-if="store.getColorPalette().length > 0">
                <div class="text-[9px] text-surface-400 uppercase tracking-wider font-medium mb-1">Board Palette</div>
                <div class="flex flex-wrap gap-1.5">
                  <button
                    v-for="c in store.getColorPalette()"
                    :key="'pal-' + c"
                    @click="recolorDrawing(c)"
                    class="w-6 h-6 rounded-full border-2 transition-all hover:scale-110 flex-shrink-0"
                    :class="singleItem.color === c ? 'border-primary-500 ring-2 ring-primary-300/50' : 'border-surface-200 dark:border-surface-600'"
                    :style="{ backgroundColor: c }"
                    :title="c"
                  />
                </div>
              </div>
              <!-- Custom color picker -->
              <div class="flex items-center gap-2">
                <MoodColorPicker
                  :model-value="singleItem.color || '#1e293b'"
                  @update:model-value="recolorDrawing($event)"
                  :palette="store.getColorPalette()"
                  label="Custom color"
                  :show-caret="false"
                  dropdown-position="top-full left-0"
                />
                <span class="text-[9px] text-surface-400 font-medium">Custom</span>
              </div>
            </div>
          </SidebarSection>

          <!-- Scale -->
          <SidebarSection title="Scale" icon="aspect_ratio" :open="true">
            <div class="space-y-2">
              <div class="flex items-center gap-2">
                <MoodScrubInput
                  icon="zoom_in"
                  unit="%"
                  :model-value="scalePercent"
                  :min="10" :max="500" :step="5"
                  @update:model-value="applyScale($event)"
                  class="flex-1"
                />
                <button
                  @click="resetScale"
                  class="px-2 py-1.5 rounded-lg bg-surface-100 dark:bg-surface-700 hover:bg-surface-200 dark:hover:bg-surface-600 text-[10px] font-medium text-surface-500 dark:text-surface-400 transition-colors whitespace-nowrap"
                  title="Reset to original size"
                >
                  Reset
                </button>
              </div>
              <div class="text-[9px] text-surface-400">
                Original: {{ getOriginalWidth(singleItem) }} x {{ getOriginalHeight(singleItem) }}px
              </div>
            </div>
          </SidebarSection>

          <!-- Export Drawing -->
          <SidebarSection title="Export" icon="download" :open="true">
            <div class="grid grid-cols-3 gap-1.5">
              <button
                @click="exportDrawing('svg')"
                class="flex flex-col items-center gap-1 px-2 py-2.5 rounded-xl bg-surface-50 dark:bg-surface-700/50 hover:bg-primary-50 dark:hover:bg-primary-900/20 border border-surface-200 dark:border-surface-600 hover:border-primary-300 dark:hover:border-primary-700 text-surface-600 dark:text-surface-400 hover:text-primary-600 dark:hover:text-primary-400 transition-all group"
                title="Export as SVG (vector)"
              >
                <span class="material-symbols-rounded text-lg group-hover:scale-110 transition-transform">code</span>
                <span class="text-[10px] font-semibold">SVG</span>
              </button>
              <button
                @click="exportDrawing('png')"
                class="flex flex-col items-center gap-1 px-2 py-2.5 rounded-xl bg-surface-50 dark:bg-surface-700/50 hover:bg-primary-50 dark:hover:bg-primary-900/20 border border-surface-200 dark:border-surface-600 hover:border-primary-300 dark:hover:border-primary-700 text-surface-600 dark:text-surface-400 hover:text-primary-600 dark:hover:text-primary-400 transition-all group"
                title="Export as PNG (raster)"
              >
                <span class="material-symbols-rounded text-lg group-hover:scale-110 transition-transform">image</span>
                <span class="text-[10px] font-semibold">PNG</span>
              </button>
              <button
                @click="exportDrawing('eps')"
                class="flex flex-col items-center gap-1 px-2 py-2.5 rounded-xl bg-surface-50 dark:bg-surface-700/50 hover:bg-primary-50 dark:hover:bg-primary-900/20 border border-surface-200 dark:border-surface-600 hover:border-primary-300 dark:hover:border-primary-700 text-surface-600 dark:text-surface-400 hover:text-primary-600 dark:hover:text-primary-400 transition-all group"
                title="Export as EPS (print-ready vector)"
              >
                <span class="material-symbols-rounded text-lg group-hover:scale-110 transition-transform">print</span>
                <span class="text-[10px] font-semibold">EPS</span>
              </button>
            </div>
            <!-- PNG export scale -->
            <div class="mt-2 flex items-center gap-2">
              <label class="text-[9px] text-surface-400 uppercase tracking-wider font-medium whitespace-nowrap">PNG Scale</label>
              <select
                v-model="pngExportScale"
                class="flex-1 text-xs bg-surface-50 dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-lg px-2 py-1 text-surface-700 dark:text-surface-300"
              >
                <option :value="1">1× (standard)</option>
                <option :value="2">2× (retina)</option>
                <option :value="3">3× (high-res)</option>
                <option :value="4">4× (print)</option>
              </select>
            </div>
          </SidebarSection>
        </template>

        <!-- LINE ITEM: Line appearance -->
        <template v-if="singleItem.type === 'line'">
          <SidebarSection title="Line Style" icon="pen_size_1" :open="true">
            <div class="space-y-3">
              <!-- Color -->
              <div>
                <label class="text-[9px] text-surface-400 uppercase tracking-wider font-medium">Color</label>
                <div class="mt-1">
                  <MoodColorPicker
                    :model-value="singleItem.style_data?.line_color || '#1e293b'"
                    @update:model-value="updateItemStyleData({ line_color: $event })"
                    :palette="store.getColorPalette()"
                    label="Line color"
                    :show-caret="false"
                    dropdown-position="top-full left-0"
                  />
                </div>
              </div>
              <!-- Thickness -->
              <div>
                <label class="text-[9px] text-surface-400 uppercase tracking-wider font-medium">Thickness</label>
                <div class="flex items-center gap-2 mt-1">
                  <input
                    type="range"
                    :value="singleItem.style_data?.line_width || 2"
                    min="1" max="20" step="1"
                    @input="updateItemStyleData({ line_width: parseInt($event.target.value) })"
                    class="flex-1 h-1 accent-primary-500 cursor-pointer"
                  />
                  <span class="text-[10px] text-surface-400 min-w-[20px] text-right">{{ singleItem.style_data?.line_width || 2 }}px</span>
                </div>
              </div>
              <!-- Dash style -->
              <div>
                <label class="text-[9px] text-surface-400 uppercase tracking-wider font-medium">Dash Style</label>
                <div class="flex gap-1 mt-1">
                  <button
                    v-for="ds in ['solid', 'dashed', 'dotted']"
                    :key="ds"
                    @click="updateItemStyleData({ line_dash: ds })"
                    :class="[
                      'flex-1 py-1.5 rounded-lg text-[10px] font-medium border transition-all capitalize',
                      (singleItem.style_data?.line_dash || 'solid') === ds
                        ? 'bg-primary-50 dark:bg-primary-900/30 text-primary-600 dark:text-primary-400 border-primary-300 dark:border-primary-700'
                        : 'bg-surface-50 dark:bg-surface-700/50 text-surface-500 border-surface-200 dark:border-surface-600 hover:border-primary-300'
                    ]"
                  >{{ ds }}</button>
                </div>
              </div>
              <!-- Dash gap (only for dashed/dotted) -->
              <div v-if="singleItem.style_data?.line_dash && singleItem.style_data.line_dash !== 'solid'">
                <label class="text-[9px] text-surface-400 uppercase tracking-wider font-medium">Dash Spacing</label>
                <div class="flex items-center gap-2 mt-1">
                  <input
                    type="range"
                    :value="singleItem.style_data?.line_dash_gap || 0"
                    min="0" max="30" step="1"
                    @input="updateItemStyleData({ line_dash_gap: parseInt($event.target.value) })"
                    class="flex-1 h-1 accent-primary-500 cursor-pointer"
                  />
                  <span class="text-[10px] text-surface-400 min-w-[20px] text-right">{{ singleItem.style_data?.line_dash_gap || 0 }}</span>
                </div>
                <p class="text-[9px] text-surface-400 mt-0.5">0 = auto spacing</p>
              </div>
              <!-- Arrows -->
              <div>
                <label class="text-[9px] text-surface-400 uppercase tracking-wider font-medium">Arrows</label>
                <div class="flex gap-2 mt-1">
                  <button
                    @click="updateItemStyleData({ line_arrow_start: !singleItem.style_data?.line_arrow_start })"
                    :class="[
                      'flex items-center gap-1 px-3 py-1.5 rounded-lg text-[10px] font-medium border transition-all',
                      singleItem.style_data?.line_arrow_start
                        ? 'bg-primary-50 dark:bg-primary-900/30 text-primary-600 dark:text-primary-400 border-primary-300 dark:border-primary-700'
                        : 'bg-surface-50 dark:bg-surface-700/50 text-surface-500 border-surface-200 dark:border-surface-600 hover:border-primary-300'
                    ]"
                  >
                    <span class="material-symbols-rounded text-sm" style="transform: scaleX(-1);">arrow_right_alt</span>
                    Start
                  </button>
                  <button
                    @click="updateItemStyleData({ line_arrow_end: !singleItem.style_data?.line_arrow_end })"
                    :class="[
                      'flex items-center gap-1 px-3 py-1.5 rounded-lg text-[10px] font-medium border transition-all',
                      singleItem.style_data?.line_arrow_end
                        ? 'bg-primary-50 dark:bg-primary-900/30 text-primary-600 dark:text-primary-400 border-primary-300 dark:border-primary-700'
                        : 'bg-surface-50 dark:bg-surface-700/50 text-surface-500 border-surface-200 dark:border-surface-600 hover:border-primary-300'
                    ]"
                  >
                    End
                    <span class="material-symbols-rounded text-sm">arrow_right_alt</span>
                  </button>
                </div>
              </div>
            </div>
          </SidebarSection>

          <!-- Line Glow -->
          <SidebarSection title="Line Glow" icon="flare" :open="!!singleItem.style_data?.line_glow_enabled">
            <div class="space-y-3">
              <!-- Enable toggle -->
              <div class="flex items-center justify-between">
                <span class="text-xs text-surface-700 dark:text-surface-300">Enable</span>
                <span
                  @click="updateItemStyleData({ line_glow_enabled: !singleItem.style_data?.line_glow_enabled, ...(singleItem.style_data?.line_glow_enabled ? {} : { line_glow_color: singleItem.style_data?.line_glow_color || singleItem.style_data?.line_color || '#6366f1', line_glow_opacity: singleItem.style_data?.line_glow_opacity ?? 60, line_glow_blur: singleItem.style_data?.line_glow_blur ?? 6 }) })"
                  :class="[
                    'relative inline-flex h-4 w-8 shrink-0 rounded-full transition-colors duration-200 cursor-pointer',
                    singleItem.style_data?.line_glow_enabled ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'
                  ]"
                >
                  <span
                    :class="[
                      'inline-block h-3 w-3 rounded-full bg-white shadow-sm ring-0 transition-transform duration-200 mt-0.5',
                      singleItem.style_data?.line_glow_enabled ? 'translate-x-[18px]' : 'translate-x-[2px]'
                    ]"
                  />
                </span>
              </div>
              <template v-if="singleItem.style_data?.line_glow_enabled">
                <!-- Glow color -->
                <div>
                  <label class="text-[9px] text-surface-400 uppercase tracking-wider font-medium">Glow Color</label>
                  <div class="mt-1">
                    <MoodColorPicker
                      :model-value="singleItem.style_data?.line_glow_color || singleItem.style_data?.line_color || '#6366f1'"
                      @update:model-value="updateItemStyleData({ line_glow_color: $event })"
                      :palette="store.getColorPalette()"
                      label="Glow color"
                      :show-caret="false"
                      dropdown-position="top-full left-0"
                    />
                  </div>
                </div>
                <!-- Glow intensity (opacity) -->
                <div>
                  <label class="text-[9px] text-surface-400 uppercase tracking-wider font-medium">Intensity</label>
                  <div class="flex items-center gap-2 mt-1">
                    <input
                      type="range"
                      :value="singleItem.style_data?.line_glow_opacity ?? 60"
                      min="10" max="100" step="5"
                      @input="updateItemStyleData({ line_glow_opacity: parseInt($event.target.value) })"
                      class="flex-1 h-1 accent-primary-500 cursor-pointer"
                    />
                    <span class="text-[10px] text-surface-400 min-w-[22px] text-right">{{ singleItem.style_data?.line_glow_opacity ?? 60 }}%</span>
                  </div>
                </div>
                <!-- Glow blur (spread) -->
                <div>
                  <label class="text-[9px] text-surface-400 uppercase tracking-wider font-medium">Spread</label>
                  <div class="flex items-center gap-2 mt-1">
                    <input
                      type="range"
                      :value="singleItem.style_data?.line_glow_blur ?? 6"
                      min="1" max="200" step="1"
                      @input="updateItemStyleData({ line_glow_blur: parseInt($event.target.value) })"
                      class="flex-1 h-1 accent-primary-500 cursor-pointer"
                    />
                    <span class="text-[10px] text-surface-400 min-w-[14px] text-right">{{ singleItem.style_data?.line_glow_blur ?? 6 }}px</span>
                  </div>
                </div>
              </template>
            </div>
          </SidebarSection>
        </template>

        <!-- FRAME ITEM: Clip Content, Background Color, Auto Layout -->
        <template v-if="singleItem.type === 'frame'">
          <!-- Clip Content -->
          <SidebarSection title="Artboard" icon="crop_free" :open="true">
            <div class="flex items-center justify-between">
              <div class="flex items-center gap-2">
                <span class="material-symbols-rounded text-sm text-surface-400">content_cut</span>
                <span class="text-xs text-surface-700 dark:text-surface-300">Clip content</span>
              </div>
              <span
                @click="updateItemStyleData({ clip_content: !singleItem.style_data?.clip_content })"
                :class="[
                  'relative inline-flex h-5 w-10 shrink-0 rounded-full transition-colors duration-200 cursor-pointer',
                  singleItem.style_data?.clip_content ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'
                ]"
              >
                <span
                  :class="[
                    'inline-block h-4 w-4 rounded-full bg-white shadow-sm ring-0 transition-transform duration-200 mt-0.5',
                    singleItem.style_data?.clip_content ? 'translate-x-[22px]' : 'translate-x-[2px]'
                  ]"
                />
              </span>
            </div>
          </SidebarSection>

          <!-- Background Color -->
          <SidebarSection title="Background" icon="format_color_fill" :open="true">
            <div class="space-y-2">
              <MoodColorPicker
                :model-value="singleItem.style_data?.fill_color || '#ffffff'"
                @update:model-value="updateItemStyleData({ fill_color: $event })"
                :palette="store.getColorPalette()"
                label="Background color"
                :show-caret="false"
              />
              <div class="flex items-center gap-2 text-[10px] text-surface-400">
                <span>HEX</span>
                <span class="font-mono text-surface-600 dark:text-surface-300">{{ (singleItem.style_data?.fill_color || '#ffffff').toUpperCase() }}</span>
              </div>
            </div>
          </SidebarSection>

          <!-- Auto Layout -->
          <SidebarSection title="Auto Layout" icon="view_quilt" :open="!!singleItem.style_data?.auto_layout">
            <div class="space-y-3">
              <!-- Toggle auto layout on/off -->
              <div class="flex items-center justify-between">
                <span class="text-xs text-surface-700 dark:text-surface-300">Enable</span>
                <span
                  @click="updateItemStyleData({ auto_layout: !singleItem.style_data?.auto_layout })"
                  :class="[
                    'relative inline-flex h-5 w-10 shrink-0 rounded-full transition-colors duration-200 cursor-pointer',
                    singleItem.style_data?.auto_layout ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'
                  ]"
                >
                  <span
                    :class="[
                      'inline-block h-4 w-4 rounded-full bg-white shadow-sm ring-0 transition-transform duration-200 mt-0.5',
                      singleItem.style_data?.auto_layout ? 'translate-x-[22px]' : 'translate-x-[2px]'
                    ]"
                  />
                </span>
              </div>

              <!-- Auto layout controls (only shown when enabled) -->
              <template v-if="singleItem.style_data?.auto_layout">
                <!-- Direction -->
                <div class="flex items-center gap-1">
                  <span class="text-[9px] text-surface-400 uppercase tracking-wider font-medium flex-shrink-0 w-14">Direction</span>
                  <button
                    v-for="dir in [{v:'column',icon:'view_agenda',label:'Vertical'},{v:'row',icon:'view_week',label:'Horizontal'}]"
                    :key="dir.v"
                    @click="updateItemStyleData({ layout_direction: dir.v })"
                    :class="[
                      'flex-1 flex items-center justify-center gap-1 py-1 rounded-md text-[10px] transition-all',
                      (singleItem.style_data?.layout_direction || 'column') === dir.v
                        ? 'bg-primary-500/15 text-primary-600 dark:text-primary-400 font-medium'
                        : 'text-surface-500 hover:bg-surface-100 dark:hover:bg-surface-700'
                    ]"
                    :title="dir.label"
                  >
                    <span class="material-symbols-rounded text-sm">{{ dir.icon }}</span>
                    {{ dir.label }}
                  </button>
                </div>

                <!-- Gap -->
                <div class="flex items-center gap-2">
                  <span class="text-[9px] text-surface-400 uppercase tracking-wider font-medium flex-shrink-0 w-14">Gap</span>
                  <input
                    type="range"
                    :value="singleItem.style_data?.layout_gap || 0"
                    min="0" max="60" step="1"
                    @input="updateItemStyleData({ layout_gap: parseInt($event.target.value) })"
                    class="flex-1 h-1 accent-primary-500 cursor-pointer"
                  />
                  <MoodScrubInput
                    class="w-14"
                    suffix="px"
                    :model-value="singleItem.style_data?.layout_gap || 0"
                    :min="0" :max="200"
                    @update:model-value="updateItemStyleData({ layout_gap: $event })"
                  />
                </div>

                <!-- Padding -->
                <div class="space-y-1.5">
                  <div class="flex items-center gap-2">
                    <span class="text-[9px] text-surface-400 uppercase tracking-wider font-medium flex-shrink-0 w-14">Padding</span>
                    <input
                      type="range"
                      :value="singleItem.style_data?.padding || 0"
                      min="0" max="60" step="1"
                      @input="updateItemStyleData({ padding: parseInt($event.target.value), padding_top: undefined, padding_right: undefined, padding_bottom: undefined, padding_left: undefined })"
                      class="flex-1 h-1 accent-primary-500 cursor-pointer"
                    />
                    <MoodScrubInput
                      class="w-14"
                      suffix="px"
                      :model-value="singleItem.style_data?.padding || 0"
                      :min="0" :max="200"
                      @update:model-value="updateItemStyleData({ padding: $event, padding_top: undefined, padding_right: undefined, padding_bottom: undefined, padding_left: undefined })"
                    />
                    <button
                      @click="framePaddingExpanded = !framePaddingExpanded"
                      class="flex-shrink-0 p-0.5 rounded text-surface-400 hover:text-surface-600 dark:hover:text-surface-200 transition-colors"
                      :title="framePaddingExpanded ? 'Uniform padding' : 'Individual padding'"
                    >
                      <span class="material-symbols-rounded text-sm">{{ framePaddingExpanded ? 'unfold_less' : 'unfold_more' }}</span>
                    </button>
                  </div>
                  <div v-if="framePaddingExpanded" class="grid grid-cols-2 gap-1.5 pl-[62px]">
                    <div v-for="side in [{k:'padding_top',label:'Top',icon:'vertical_align_top'},{k:'padding_right',label:'Right',icon:'align_horizontal_right'},{k:'padding_bottom',label:'Bottom',icon:'vertical_align_bottom'},{k:'padding_left',label:'Left',icon:'align_horizontal_left'}]" :key="side.k" class="flex items-center gap-1">
                      <span class="material-symbols-rounded text-[10px] text-surface-400">{{ side.icon }}</span>
                      <MoodScrubInput
                        class="flex-1"
                        suffix="px"
                        :model-value="singleItem.style_data?.[side.k] ?? singleItem.style_data?.padding ?? 0"
                        :min="0" :max="200"
                        @update:model-value="updateItemStyleData({ [side.k]: $event })"
                      />
                    </div>
                  </div>
                </div>

                <!-- Alignment -->
                <div>
                  <span class="text-[9px] text-surface-400 uppercase tracking-wider font-medium">Alignment</span>
                  <div class="flex items-center gap-1 mt-1">
                    <button
                      v-for="al in [{v:'start',icon:'align_horizontal_left'},{v:'center',icon:'align_horizontal_center'},{v:'end',icon:'align_horizontal_right'},{v:'stretch',icon:'expand'}]"
                      :key="al.v"
                      @click="updateItemStyleData({ layout_align: al.v })"
                      :class="[
                        'flex-1 flex items-center justify-center py-1 rounded-md transition-all',
                        (singleItem.style_data?.layout_align || 'stretch') === al.v
                          ? 'bg-primary-500/15 text-primary-600 dark:text-primary-400'
                          : 'text-surface-500 hover:bg-surface-100 dark:hover:bg-surface-700'
                      ]"
                      :title="al.v"
                    >
                      <span class="material-symbols-rounded text-base">{{ al.icon }}</span>
                    </button>
                  </div>
                </div>

                <!-- Justify -->
                <div>
                  <span class="text-[9px] text-surface-400 uppercase tracking-wider font-medium">Justify</span>
                  <div class="flex items-center gap-1 mt-1">
                    <button
                      v-for="jt in [{v:'start',icon:'align_vertical_top',label:'Start'},{v:'center',icon:'align_vertical_center',label:'Center'},{v:'end',icon:'align_vertical_bottom',label:'End'},{v:'space-between',icon:'horizontal_distribute',label:'Space Between'}]"
                      :key="jt.v"
                      @click="updateItemStyleData({ layout_justify: jt.v })"
                      :class="[
                        'flex-1 flex items-center justify-center py-1 rounded-md transition-all',
                        (singleItem.style_data?.layout_justify || 'start') === jt.v
                          ? 'bg-primary-500/15 text-primary-600 dark:text-primary-400'
                          : 'text-surface-500 hover:bg-surface-100 dark:hover:bg-surface-700'
                      ]"
                      :title="jt.label"
                    >
                      <span class="material-symbols-rounded text-base">{{ jt.icon }}</span>
                    </button>
                  </div>
                </div>

                <!-- Wrap -->
                <div class="flex items-center justify-between">
                  <span class="text-xs text-surface-700 dark:text-surface-300">Wrap</span>
                  <span
                    @click="updateItemStyleData({ layout_wrap: !singleItem.style_data?.layout_wrap })"
                    :class="[
                      'relative inline-flex h-5 w-10 shrink-0 rounded-full transition-colors duration-200 cursor-pointer',
                      singleItem.style_data?.layout_wrap ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'
                    ]"
                  >
                    <span
                      :class="[
                        'inline-block h-4 w-4 rounded-full bg-white shadow-sm ring-0 transition-transform duration-200 mt-0.5',
                        singleItem.style_data?.layout_wrap ? 'translate-x-[22px]' : 'translate-x-[2px]'
                      ]"
                    />
                  </span>
                </div>

                <!-- Frame Sizing Mode (Hug / Fixed) -->
                <div>
                  <span class="text-[9px] text-surface-400 uppercase tracking-wider font-medium">Artboard Sizing</span>
                  <div class="grid grid-cols-2 gap-2 mt-1">
                    <!-- Horizontal sizing -->
                    <div>
                      <span class="text-[8px] text-surface-400 mb-0.5 block">Width</span>
                      <div class="flex gap-0.5">
                        <button
                          v-for="sz in [{v:'fixed',icon:'width',label:'Fixed'},{v:'hug',icon:'compress',label:'Hug'}]"
                          :key="sz.v"
                          @click="updateItemStyleData({ sizing_h: sz.v })"
                          :class="[
                            'flex-1 flex items-center justify-center gap-0.5 py-1 rounded-md text-[9px] transition-all',
                            (singleItem.style_data?.sizing_h || 'fixed') === sz.v
                              ? 'bg-primary-500/15 text-primary-600 dark:text-primary-400 font-medium'
                              : 'text-surface-500 hover:bg-surface-100 dark:hover:bg-surface-700'
                          ]"
                          :title="sz.label"
                        >
                          <span class="material-symbols-rounded text-xs">{{ sz.icon }}</span>
                          {{ sz.label }}
                        </button>
                      </div>
                    </div>
                    <!-- Vertical sizing -->
                    <div>
                      <span class="text-[8px] text-surface-400 mb-0.5 block">Height</span>
                      <div class="flex gap-0.5">
                        <button
                          v-for="sz in [{v:'fixed',icon:'height',label:'Fixed'},{v:'hug',icon:'compress',label:'Hug'}]"
                          :key="sz.v"
                          @click="updateItemStyleData({ sizing_v: sz.v })"
                          :class="[
                            'flex-1 flex items-center justify-center gap-0.5 py-1 rounded-md text-[9px] transition-all',
                            (singleItem.style_data?.sizing_v || 'fixed') === sz.v
                              ? 'bg-primary-500/15 text-primary-600 dark:text-primary-400 font-medium'
                              : 'text-surface-500 hover:bg-surface-100 dark:hover:bg-surface-700'
                          ]"
                          :title="sz.label"
                        >
                          <span class="material-symbols-rounded text-xs">{{ sz.icon }}</span>
                          {{ sz.label }}
                        </button>
                      </div>
                    </div>
                  </div>
                </div>

                <!-- Clip content -->
                <div class="flex items-center justify-between">
                  <span class="text-xs text-surface-700 dark:text-surface-300">Clip content</span>
                  <span
                    @click="updateItemStyleData({ clip_content: !singleItem.style_data?.clip_content })"
                    :class="[
                      'relative inline-flex h-5 w-10 shrink-0 rounded-full transition-colors duration-200 cursor-pointer',
                      singleItem.style_data?.clip_content ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'
                    ]"
                  >
                    <span
                      :class="[
                        'inline-block h-4 w-4 rounded-full bg-white shadow-sm ring-0 transition-transform duration-200 mt-0.5',
                        singleItem.style_data?.clip_content ? 'translate-x-[22px]' : 'translate-x-[2px]'
                      ]"
                    />
                  </span>
                </div>
              </template>
            </div>
          </SidebarSection>

        </template>

        <!-- GROUP ITEM: Layout Mode, Alignment, Distribute, Ungroup -->
        <template v-if="singleItem.type === 'group'">
          <SidebarSection title="Layout" icon="dashboard" :open="true">
            <div class="space-y-3">
              <!-- Layout mode toggle: None / Flex / Grid -->
              <div class="inline-flex w-full border border-surface-200 dark:border-surface-600 rounded-lg overflow-hidden bg-surface-50 dark:bg-surface-700">
                <button
                  v-for="m in layoutModes"
                  :key="m.id"
                  @click="setGroupLayoutMode(m.id)"
                  :class="[
                    'flex-1 flex items-center justify-center gap-1 py-1.5 text-xs font-medium transition-colors border-r border-surface-200 dark:border-surface-600 last:border-r-0',
                    groupLayoutModeVal === m.id
                      ? 'bg-primary-500 text-white'
                      : 'text-surface-500 hover:text-surface-900 dark:hover:text-white hover:bg-surface-100 dark:hover:bg-surface-600'
                  ]"
                >
                  <span class="material-symbols-rounded text-[16px]">{{ m.icon }}</span>
                  {{ m.label }}
                </button>
              </div>

              <!-- Flex controls -->
              <template v-if="groupLayoutModeVal === 'flex'">
                <div class="space-y-2">
                  <div>
                    <label class="text-[9px] text-surface-400 uppercase tracking-wider font-medium">Direction</label>
                    <div class="inline-flex w-full border border-surface-200 dark:border-surface-600 rounded-lg overflow-hidden bg-surface-50 dark:bg-surface-700 mt-0.5">
                      <button
                        v-for="d in flexDirections"
                        :key="d.value"
                        @click="updateGroupLayout('layout_direction', d.value)"
                        :class="[
                          'flex-1 flex items-center justify-center py-1.5 text-xs transition-colors border-r border-surface-200 dark:border-surface-600 last:border-r-0',
                          (singleItem.style_data?.layout_direction || 'column') === d.value
                            ? 'bg-primary-500/20 text-primary-600 dark:text-primary-400'
                            : 'text-surface-500 hover:bg-surface-100 dark:hover:bg-surface-600'
                        ]"
                      >
                        <span class="material-symbols-rounded text-[16px]">{{ d.icon }}</span>
                      </button>
                    </div>
                  </div>

                  <div class="grid grid-cols-2 gap-2">
                    <MoodScrubInput icon="space_bar" label="Gap" :model-value="singleItem.style_data?.layout_gap || 0" :min="0" :max="200" :step="1" @update:model-value="v => updateGroupLayout('layout_gap', v)" />
                    <MoodScrubInput icon="padding" label="Padding" :model-value="singleItem.style_data?.padding || 0" :min="0" :max="200" :step="1" @update:model-value="v => updateGroupLayout('padding', v)" />
                  </div>

                  <div>
                    <label class="text-[9px] text-surface-400 uppercase tracking-wider font-medium">Align Items</label>
                    <div class="inline-flex w-full border border-surface-200 dark:border-surface-600 rounded-lg overflow-hidden bg-surface-50 dark:bg-surface-700 mt-0.5">
                      <button
                        v-for="a in flexAlignOptions"
                        :key="a.value"
                        @click="updateGroupLayout('layout_align', a.value)"
                        :title="a.label"
                        :class="[
                          'flex-1 flex items-center justify-center py-1.5 transition-colors border-r border-surface-200 dark:border-surface-600 last:border-r-0',
                          (singleItem.style_data?.layout_align || 'stretch') === a.value
                            ? 'bg-primary-500/20 text-primary-600 dark:text-primary-400'
                            : 'text-surface-500 hover:bg-surface-100 dark:hover:bg-surface-600'
                        ]"
                      >
                        <span class="material-symbols-rounded text-[16px]">{{ a.icon }}</span>
                      </button>
                    </div>
                  </div>

                  <div>
                    <label class="text-[9px] text-surface-400 uppercase tracking-wider font-medium">Justify Content</label>
                    <div class="inline-flex w-full border border-surface-200 dark:border-surface-600 rounded-lg overflow-hidden bg-surface-50 dark:bg-surface-700 mt-0.5">
                      <button
                        v-for="j in flexJustifyOptions"
                        :key="j.value"
                        @click="updateGroupLayout('layout_justify', j.value)"
                        :title="j.label"
                        :class="[
                          'flex-1 flex items-center justify-center py-1.5 transition-colors border-r border-surface-200 dark:border-surface-600 last:border-r-0',
                          (singleItem.style_data?.layout_justify || 'start') === j.value
                            ? 'bg-primary-500/20 text-primary-600 dark:text-primary-400'
                            : 'text-surface-500 hover:bg-surface-100 dark:hover:bg-surface-600'
                        ]"
                      >
                        <span class="material-symbols-rounded text-[16px]">{{ j.icon }}</span>
                      </button>
                    </div>
                  </div>

                  <!-- Wrap toggle -->
                  <div class="flex items-center justify-between">
                    <span class="text-[10px] text-surface-500 font-medium">Wrap</span>
                    <button
                      @click="updateGroupLayout('layout_wrap', !singleItem.style_data?.layout_wrap)"
                      class="relative w-9 h-5 rounded-full transition-colors duration-200 flex-shrink-0"
                      :class="singleItem.style_data?.layout_wrap ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'"
                    >
                      <span
                        class="absolute top-0.5 left-0.5 w-4 h-4 rounded-full bg-white shadow transition-transform duration-200"
                        :class="singleItem.style_data?.layout_wrap ? 'translate-x-4' : 'translate-x-0'"
                      />
                    </button>
                  </div>
                </div>
              </template>

              <!-- Grid controls -->
              <template v-if="groupLayoutModeVal === 'grid'">
                <div class="space-y-2">
                  <div class="grid grid-cols-2 gap-2">
                    <MoodScrubInput icon="view_column" label="Columns" :model-value="singleItem.style_data?.grid_columns || 3" :min="1" :max="24" :step="1" @update:model-value="v => updateGroupLayout('grid_columns', v)" />
                    <MoodScrubInput icon="table_rows" label="Rows" :model-value="singleItem.style_data?.grid_rows || ''" :min="1" :max="24" :step="1" @update:model-value="v => updateGroupLayout('grid_rows', v)" />
                    <MoodScrubInput icon="swap_horiz" label="H Gap" :model-value="singleItem.style_data?.grid_h_gap ?? 20" :min="0" :max="200" :step="1" @update:model-value="v => updateGroupLayout('grid_h_gap', v)" />
                    <MoodScrubInput icon="swap_vert" label="V Gap" :model-value="singleItem.style_data?.grid_v_gap ?? 20" :min="0" :max="200" :step="1" @update:model-value="v => updateGroupLayout('grid_v_gap', v)" />
                    <MoodScrubInput icon="padding" label="Padding" :model-value="singleItem.style_data?.padding || 0" :min="0" :max="200" :step="1" @update:model-value="v => updateGroupLayout('padding', v)" />
                  </div>

                  <div>
                    <label class="text-[9px] text-surface-400 uppercase tracking-wider font-medium">Align Items</label>
                    <div class="inline-flex w-full border border-surface-200 dark:border-surface-600 rounded-lg overflow-hidden bg-surface-50 dark:bg-surface-700 mt-0.5">
                      <button
                        v-for="a in gridAlignOptions"
                        :key="a.value"
                        @click="updateGroupLayout('grid_align_items', a.value)"
                        :title="a.label"
                        :class="[
                          'flex-1 flex items-center justify-center py-1.5 text-[10px] transition-colors border-r border-surface-200 dark:border-surface-600 last:border-r-0',
                          (singleItem.style_data?.grid_align_items || '') === a.value
                            ? 'bg-primary-500/20 text-primary-600 dark:text-primary-400'
                            : 'text-surface-500 hover:bg-surface-100 dark:hover:bg-surface-600'
                        ]"
                      >
                        {{ a.label }}
                      </button>
                    </div>
                  </div>
                </div>
              </template>

              <!-- Clip / Overflow toggle (all groups) -->
              <div class="flex items-center justify-between">
                <div class="flex items-center gap-1.5">
                  <span class="material-symbols-rounded text-[16px]" :class="groupClipContent ? 'text-surface-400' : 'text-amber-500'">{{ groupClipContent ? 'visibility_off' : 'visibility' }}</span>
                  <span class="text-[10px] font-medium" :class="groupClipContent ? 'text-surface-500' : 'text-amber-500'">Clip Content</span>
                </div>
                <button
                  @click="updateItemStyleData({ clip_content: !groupClipContent })"
                  class="relative w-9 h-5 rounded-full transition-colors duration-200 flex-shrink-0"
                  :class="groupClipContent ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'"
                >
                  <span
                    class="absolute top-0.5 left-0.5 w-4 h-4 rounded-full bg-white shadow transition-transform duration-200"
                    :class="groupClipContent ? 'translate-x-4' : 'translate-x-0'"
                  />
                </button>
              </div>
            </div>
          </SidebarSection>

          <SidebarSection title="Children" icon="align_horizontal_left" :open="true" v-if="groupLayoutModeVal === 'none'">
            <div class="inline-flex w-full border border-surface-200 dark:border-surface-600 rounded-lg overflow-hidden bg-surface-50 dark:bg-surface-700">
              <button
                v-for="a in alignActions"
                :key="a.dir"
                @click="alignGroupChildren(a.dir); lastGroupAlign = a.dir"
                :title="a.label"
                :class="[
                  'flex-1 flex items-center justify-center py-2 transition-colors border-r border-surface-200 dark:border-surface-600 last:border-r-0',
                  lastGroupAlign === a.dir
                    ? 'bg-primary-500/15 text-primary-600 dark:text-primary-400'
                    : 'text-surface-500 hover:text-surface-900 dark:hover:text-white hover:bg-surface-100 dark:hover:bg-surface-600'
                ]"
              >
                <span class="material-symbols-rounded text-[18px]">{{ a.icon }}</span>
              </button>
            </div>
            <div class="inline-flex w-full border border-surface-200 dark:border-surface-600 rounded-lg overflow-hidden bg-surface-50 dark:bg-surface-700 mt-1.5">
              <button
                v-for="d in distributeActions"
                :key="d.dir"
                @click="alignGroupChildren(d.dir); lastGroupAlign = d.dir"
                :title="d.label"
                :class="[
                  'flex-1 flex items-center justify-center py-2 transition-colors border-r border-surface-200 dark:border-surface-600 last:border-r-0',
                  lastGroupAlign === d.dir
                    ? 'bg-primary-500/15 text-primary-600 dark:text-primary-400'
                    : 'text-surface-500 hover:text-surface-900 dark:hover:text-white hover:bg-surface-100 dark:hover:bg-surface-600'
                ]"
              >
                <span class="material-symbols-rounded text-[18px]">{{ d.icon }}</span>
              </button>
            </div>
          </SidebarSection>
          <SidebarSection title="Group" icon="group_work" :open="true">
            <button
              @click="store.ungroupSelectedItems()"
              class="flex items-center gap-2 w-full px-3 py-2 bg-surface-50 dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-lg text-surface-700 dark:text-surface-300 text-xs font-medium hover:border-surface-300 dark:hover:border-surface-500 transition-colors"
            >
              <span class="material-symbols-rounded text-base">workspaces</span>
              <span class="flex-1 text-left">Ungroup</span>
              <span class="text-[10px] font-mono text-surface-400">Ctrl+Shift+G</span>
            </button>
          </SidebarSection>
        </template>

        <!-- REPEAT GRID ITEM: columns, rows, gaps, dissolve -->
        <template v-if="singleItem.type === 'repeat_grid'">
          <SidebarSection title="Grid" icon="grid_view" :open="true">
            <div class="grid grid-cols-2 gap-2">
              <MoodScrubInput icon="view_column" label="Columns" :model-value="singleItem.style_data?.grid_columns || 3" :min="1" :max="50" :step="1" @update:model-value="v => updateRepeatGridParam('grid_columns', v)" />
              <MoodScrubInput icon="table_rows" label="Rows" :model-value="singleItem.style_data?.grid_rows || 3" :min="1" :max="50" :step="1" @update:model-value="v => updateRepeatGridParam('grid_rows', v)" />
              <MoodScrubInput icon="swap_horiz" label="H Gap" :model-value="singleItem.style_data?.grid_h_gap ?? 20" :min="0" :max="200" :step="1" @update:model-value="v => updateRepeatGridParam('grid_h_gap', v)" />
              <MoodScrubInput icon="swap_vert" label="V Gap" :model-value="singleItem.style_data?.grid_v_gap ?? 20" :min="0" :max="200" :step="1" @update:model-value="v => updateRepeatGridParam('grid_v_gap', v)" />
            </div>
          </SidebarSection>
          <SidebarSection title="Repeat Grid" icon="apps" :open="true">
            <button
              @click="store.dissolveRepeatGrid()"
              class="flex items-center gap-2 w-full px-3 py-2 bg-surface-50 dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-lg text-surface-700 dark:text-surface-300 text-xs font-medium hover:border-surface-300 dark:hover:border-surface-500 transition-colors"
            >
              <span class="material-symbols-rounded text-base">grid_off</span>
              <span class="flex-1 text-left">Dissolve Grid</span>
            </button>
          </SidebarSection>
        </template>

        <!-- SLIDE ITEM: Presentation camera view settings -->
        <template v-if="singleItem.type === 'slide'">
          <SidebarSection title="Presentation" icon="slideshow" :open="true">
            <div class="space-y-3">
              <div>
                <label class="text-[9px] text-surface-400 uppercase tracking-wider font-medium">Slide Order</label>
                <MoodScrubInput
                  class="mt-0.5"
                  icon="tag"
                  :model-value="singleItem.slide_order ?? 0"
                  :min="0"
                  @update:model-value="updateSingleItem({ slide_order: $event })"
                />
              </div>
              <div>
                <label class="text-[9px] text-surface-400 uppercase tracking-wider font-medium">Transition</label>
                <select
                  :value="singleItem.transition_type || 'fly'"
                  @change="updateSingleItem({ transition_type: $event.target.value })"
                  class="w-full mt-0.5 text-xs bg-surface-50 dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-lg px-2 py-1.5 focus:outline-none text-surface-700 dark:text-surface-300"
                >
                  <option value="fly">Fly</option>
                  <option value="fade">Fade</option>
                  <option value="instant">Instant</option>
                </select>
              </div>
              <!-- Duration (hidden for instant transitions) -->
              <div v-if="(singleItem.transition_type || 'fly') !== 'instant'">
                <label class="text-[9px] text-surface-400 uppercase tracking-wider font-medium">Duration</label>
                <div class="flex items-center gap-1.5 mt-1">
                  <input
                    type="range"
                    min="0.1"
                    max="10"
                    step="0.1"
                    :value="singleItem.transition_duration ?? (singleItem.transition_type === 'fade' ? 0.4 : 0.6)"
                    @input="updateSingleItem({ transition_duration: Math.round(parseFloat($event.target.value) * 10) / 10 })"
                    class="flex-1 h-1 accent-primary-500 cursor-pointer"
                  />
                  <input
                    type="number"
                    :value="singleItem.transition_duration ?? (singleItem.transition_type === 'fade' ? 0.4 : 0.6)"
                    @change="updateSingleItem({ transition_duration: Math.round(Math.max(0.1, Math.min(30, parseFloat($event.target.value) || 0.6)) * 10) / 10 })"
                    min="0.1"
                    max="30"
                    step="0.1"
                    class="w-14 text-[11px] text-center px-1.5 py-1 bg-surface-50 dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-lg focus:outline-none focus:ring-1 focus:ring-primary-400 text-surface-700 dark:text-surface-300
                           [appearance:textfield] [&::-webkit-outer-spin-button]:appearance-none [&::-webkit-inner-spin-button]:appearance-none"
                  />
                  <span class="text-[10px] text-surface-400">s</span>
                </div>
              </div>
              <div>
                <label class="text-[9px] text-surface-400 uppercase tracking-wider font-medium">Presenter Notes</label>
                <textarea
                  :value="singleItem.presenter_notes || ''"
                  @blur="updateSingleItem({ presenter_notes: $event.target.value || null })"
                  rows="2"
                  class="w-full mt-0.5 px-2.5 py-1.5 text-xs bg-surface-50 dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-lg focus:outline-none focus:ring-1 focus:ring-primary-400 text-surface-700 dark:text-surface-300 resize-none"
                  placeholder="Notes visible only during presentation..."
                />
              </div>
            </div>
          </SidebarSection>
        </template>

        <!-- VIDEO ITEM: Video properties -->
        <template v-if="singleItem.type === 'video'">
          <SidebarSection title="Video" icon="videocam" :open="true">
            <div class="space-y-3">
              <div>
                <label class="text-[9px] text-surface-400 uppercase tracking-wider font-medium">Title</label>
                <input
                  type="text"
                  :value="singleItem.title || ''"
                  @change="updateSingleItem({ title: $event.target.value })"
                  class="w-full mt-0.5 px-2 py-1.5 text-xs bg-surface-50 dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-lg focus:outline-none focus:ring-1 focus:ring-primary-400 text-surface-700 dark:text-surface-300"
                  placeholder="Video title..."
                />
              </div>
              <div>
                <label class="text-[9px] text-surface-400 uppercase tracking-wider font-medium">Video URL</label>
                <input
                  type="text"
                  :value="singleItem.url || ''"
                  @change="updateSingleItem({ url: $event.target.value })"
                  class="w-full mt-0.5 px-2 py-1.5 text-xs bg-surface-50 dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-lg focus:outline-none focus:ring-1 focus:ring-primary-400 text-surface-700 dark:text-surface-300"
                  placeholder="Video URL (mp4, webm, ogg)..."
                />
              </div>
              <a
                v-if="singleItem.url"
                :href="singleItem.url"
                target="_blank"
                class="flex items-center gap-2 px-3 py-2 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 text-primary-600 dark:text-primary-400 text-xs font-medium transition-colors"
              >
                <span class="material-symbols-rounded text-lg">open_in_new</span>
                Open Video
              </a>
            </div>
          </SidebarSection>
        </template>

        <!-- YOUTUBE ITEM: Video properties -->
        <template v-if="singleItem.type === 'youtube'">
          <SidebarSection title="YouTube" icon="smart_display" :open="true">
            <div class="space-y-3">
              <!-- Thumbnail preview -->
              <div v-if="youtubeEmbedId && !ytThumbError" class="rounded-lg overflow-hidden border border-surface-200 dark:border-surface-600">
                <img
                  :src="`https://img.youtube.com/vi/${youtubeEmbedId}/mqdefault.jpg`"
                  class="w-full h-auto"
                  alt="YouTube thumbnail"
                  @error="ytThumbError = true"
                />
              </div>
              <div>
                <label class="text-[9px] text-surface-400 uppercase tracking-wider font-medium">Title</label>
                <input
                  type="text"
                  :value="singleItem.title || ''"
                  @change="updateSingleItem({ title: $event.target.value })"
                  class="w-full mt-0.5 px-2 py-1.5 text-xs bg-surface-50 dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-lg focus:outline-none focus:ring-1 focus:ring-primary-400 text-surface-700 dark:text-surface-300"
                  placeholder="Video title..."
                />
              </div>
              <div>
                <label class="text-[9px] text-surface-400 uppercase tracking-wider font-medium">YouTube URL</label>
                <input
                  type="text"
                  :value="singleItem.url || ''"
                  @change="updateSingleItem({ url: $event.target.value })"
                  class="w-full mt-0.5 px-2 py-1.5 text-xs bg-surface-50 dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-lg focus:outline-none focus:ring-1 focus:ring-primary-400 text-surface-700 dark:text-surface-300"
                  placeholder="YouTube URL..."
                />
              </div>
              <!-- Interactive mode toggle -->
              <div class="flex items-center justify-between">
                <span class="text-[10px] text-surface-500 font-medium">Interactive Mode</span>
                <button
                  @click="updateSingleItem({ style_data: { ...(singleItem.style_data || {}), _youtubeInteractive: !singleItem.style_data?._youtubeInteractive } })"
                  class="relative w-9 h-5 rounded-full transition-colors"
                  :class="singleItem.style_data?._youtubeInteractive ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'"
                >
                  <span
                    class="absolute left-0 top-0.5 w-4 h-4 rounded-full bg-white shadow transition-transform"
                    :class="singleItem.style_data?._youtubeInteractive ? 'translate-x-[18px]' : 'translate-x-0.5'"
                  />
                </button>
              </div>
              <p class="text-[9px] text-surface-400 leading-tight">
                Enable interactive mode to play the video directly on canvas. Disable to move/resize freely.
              </p>
              <!-- External link -->
              <a
                v-if="singleItem.url"
                :href="singleItem.url"
                target="_blank"
                class="flex items-center gap-2 px-3 py-2 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 text-red-500 text-xs font-medium transition-colors"
              >
                <span class="material-symbols-rounded text-lg">smart_display</span>
                Open on YouTube
              </a>
            </div>
          </SidebarSection>
        </template>

        <!-- AUDIO ITEM: Player settings -->
        <template v-if="singleItem.type === 'audio'">
          <SidebarSection title="Audio" icon="graphic_eq" :open="true">
            <div class="space-y-3">
              <div>
                <label class="text-[9px] text-surface-400 uppercase tracking-wider font-medium">Title</label>
                <input
                  type="text"
                  :value="singleItem.title || ''"
                  @change="updateSingleItem({ title: $event.target.value })"
                  class="w-full mt-0.5 px-2 py-1.5 text-xs bg-surface-50 dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-lg focus:outline-none focus:ring-1 focus:ring-primary-400 text-surface-700 dark:text-surface-300"
                  placeholder="Audio title..."
                />
              </div>
              <div>
                <label class="text-[9px] text-surface-400 uppercase tracking-wider font-medium">Audio URL</label>
                <input
                  type="text"
                  :value="singleItem.url || ''"
                  @change="updateSingleItem({ url: $event.target.value })"
                  class="w-full mt-0.5 px-2 py-1.5 text-xs bg-surface-50 dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-lg focus:outline-none focus:ring-1 focus:ring-primary-400 text-surface-700 dark:text-surface-300"
                  placeholder="Audio URL (mp3, wav, ogg, m4a)..."
                />
              </div>
              <!-- Volume -->
              <div>
                <label class="text-[9px] text-surface-400 uppercase tracking-wider font-medium">Volume</label>
                <div class="flex items-center gap-2 mt-1">
                  <span class="material-symbols-rounded text-sm text-surface-400" style="font-size:14px">
                    {{ (singleItem.style_data?.audio_volume ?? 80) === 0 ? 'volume_off' : 'volume_up' }}
                  </span>
                  <input
                    type="range"
                    min="0"
                    max="100"
                    step="1"
                    :value="singleItem.style_data?.audio_volume ?? 80"
                    @input="updateSingleItem({ style_data: { ...singleItem.style_data, audio_volume: parseInt($event.target.value) } })"
                    class="flex-1 h-1 accent-primary-500 cursor-pointer"
                  />
                  <span class="text-[10px] text-surface-400 w-7 text-right tabular-nums">{{ singleItem.style_data?.audio_volume ?? 80 }}%</span>
                </div>
              </div>
              <!-- Auto-play when in view -->
              <div class="flex items-center justify-between">
                <div class="flex items-center gap-1.5">
                  <span class="material-symbols-rounded text-sm text-surface-400" style="font-size:14px">play_circle</span>
                  <span class="text-[10px] text-surface-500 font-medium">Auto-play when in view</span>
                </div>
                <button
                  @click="updateSingleItem({ style_data: { ...singleItem.style_data, audio_autoplay: !singleItem.style_data?.audio_autoplay } })"
                  class="relative w-9 h-5 rounded-full transition-colors"
                  :class="singleItem.style_data?.audio_autoplay ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'"
                >
                  <span
                    class="absolute left-0 top-0.5 w-4 h-4 rounded-full bg-white shadow transition-transform"
                    :class="singleItem.style_data?.audio_autoplay ? 'translate-x-[18px]' : 'translate-x-0.5'"
                  />
                </button>
              </div>
              <p class="text-[9px] text-surface-400 leading-tight -mt-1">
                Automatically play audio when the item scrolls into view. Pauses when out of view.
              </p>
              <!-- Loop -->
              <div class="flex items-center justify-between">
                <div class="flex items-center gap-1.5">
                  <span class="material-symbols-rounded text-sm text-surface-400" style="font-size:14px">repeat</span>
                  <span class="text-[10px] text-surface-500 font-medium">Loop</span>
                </div>
                <button
                  @click="updateSingleItem({ style_data: { ...singleItem.style_data, audio_loop: !singleItem.style_data?.audio_loop } })"
                  class="relative w-9 h-5 rounded-full transition-colors"
                  :class="singleItem.style_data?.audio_loop ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'"
                >
                  <span
                    class="absolute left-0 top-0.5 w-4 h-4 rounded-full bg-white shadow transition-transform"
                    :class="singleItem.style_data?.audio_loop ? 'translate-x-[18px]' : 'translate-x-0.5'"
                  />
                </button>
              </div>
              <!-- Hide UI in presentation -->
              <div class="flex items-center justify-between">
                <div class="flex items-center gap-1.5">
                  <span class="material-symbols-rounded text-sm text-surface-400" style="font-size:14px">visibility_off</span>
                  <span class="text-[10px] text-surface-500 font-medium">Hide in presentation</span>
                </div>
                <button
                  @click="updateSingleItem({ style_data: { ...singleItem.style_data, audio_hidden_in_pres: !singleItem.style_data?.audio_hidden_in_pres } })"
                  class="relative w-9 h-5 rounded-full transition-colors"
                  :class="singleItem.style_data?.audio_hidden_in_pres ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'"
                >
                  <span
                    class="absolute left-0 top-0.5 w-4 h-4 rounded-full bg-white shadow transition-transform"
                    :class="singleItem.style_data?.audio_hidden_in_pres ? 'translate-x-[18px]' : 'translate-x-0.5'"
                  />
                </button>
              </div>
              <p class="text-[9px] text-surface-400 leading-tight -mt-1">
                Player is invisible during presentation but still plays audio. Great for ambient sound on a slide.
              </p>
            </div>
          </SidebarSection>
          <!-- Audio Appearance -->
          <SidebarSection title="Appearance" icon="palette" :open="false">
            <div class="space-y-3">
              <div>
                <label class="text-[9px] text-surface-400 uppercase tracking-wider font-medium">Accent Color</label>
                <div class="flex items-center gap-2 mt-1">
                  <input
                    type="color"
                    :value="singleItem.style_data?.audio_accent || '#6366f1'"
                    @input="updateSingleItem({ style_data: { ...singleItem.style_data, audio_accent: $event.target.value } })"
                    class="w-7 h-7 rounded-lg border border-surface-200 dark:border-surface-600 cursor-pointer"
                  />
                  <span class="text-[10px] text-surface-400 uppercase">{{ singleItem.style_data?.audio_accent || '#6366f1' }}</span>
                </div>
              </div>
              <div>
                <label class="text-[9px] text-surface-400 uppercase tracking-wider font-medium">Background Color</label>
                <div class="flex items-center gap-2 mt-1">
                  <input
                    type="color"
                    :value="singleItem.style_data?.audio_bg || '#1e1b2e'"
                    @input="updateSingleItem({ style_data: { ...singleItem.style_data, audio_bg: $event.target.value } })"
                    class="w-7 h-7 rounded-lg border border-surface-200 dark:border-surface-600 cursor-pointer"
                  />
                  <span class="text-[10px] text-surface-400 uppercase">{{ singleItem.style_data?.audio_bg || '#1e1b2e' }}</span>
                </div>
              </div>
              <div>
                <label class="text-[9px] text-surface-400 uppercase tracking-wider font-medium">Text Color</label>
                <div class="flex items-center gap-2 mt-1">
                  <input
                    type="color"
                    :value="singleItem.style_data?.audio_text || '#e2e8f0'"
                    @input="updateSingleItem({ style_data: { ...singleItem.style_data, audio_text: $event.target.value } })"
                    class="w-7 h-7 rounded-lg border border-surface-200 dark:border-surface-600 cursor-pointer"
                  />
                  <span class="text-[10px] text-surface-400 uppercase">{{ singleItem.style_data?.audio_text || '#e2e8f0' }}</span>
                </div>
              </div>
            </div>
          </SidebarSection>
        </template>

        <!-- FILE ITEM: Details & Actions -->
        <template v-if="singleItem.type === 'file'">
          <SidebarSection title="File" icon="insert_drive_file" :open="true">
            <div class="space-y-3">
              <!-- File info card -->
              <div class="flex items-start gap-3 p-2.5 bg-surface-50 dark:bg-surface-700/50 rounded-xl border border-surface-200/60 dark:border-surface-600/40">
                <!-- File icon -->
                <div
                  class="flex-shrink-0 w-10 h-10 rounded-lg flex items-center justify-center"
                  :style="{ backgroundColor: getFileIconColor(singleItem) + '18' }"
                >
                  <span class="material-symbols-rounded text-xl" :style="{ color: getFileIconColor(singleItem) }">{{ getFileIcon(singleItem) }}</span>
                </div>
                <!-- Name + type -->
                <div class="flex-1 min-w-0">
                  <p class="text-xs font-semibold text-surface-800 dark:text-surface-200 truncate" :title="singleItem.title || 'Untitled'">
                    {{ singleItem.title || 'Untitled' }}
                  </p>
                  <span
                    class="inline-block mt-1 px-1.5 py-0.5 rounded text-[9px] font-bold uppercase tracking-wider"
                    :style="{ backgroundColor: getFileIconColor(singleItem) + '20', color: getFileIconColor(singleItem) }"
                  >
                    {{ getFileExtension(singleItem) }}
                  </span>
                </div>
              </div>

              <!-- Path (collapsible) -->
              <div v-if="singleItem.url || singleItem.image_url">
                <label class="text-[9px] text-surface-400 uppercase tracking-wider font-medium">Path</label>
                <p
                  class="mt-0.5 text-[10px] text-surface-500 dark:text-surface-400 break-all font-mono leading-relaxed bg-surface-50 dark:bg-surface-700/50 rounded-lg px-2 py-1.5 border border-surface-200/50 dark:border-surface-600/30 select-all cursor-text"
                >{{ singleItem.url || singleItem.image_url }}</p>
              </div>

              <!-- Action buttons -->
              <div class="grid grid-cols-2 gap-1.5">
                <button
                  @click="$emit('preview-file', singleItem)"
                  class="flex items-center justify-center gap-1.5 px-3 py-2 rounded-lg bg-surface-100 dark:bg-surface-700 hover:bg-surface-200 dark:hover:bg-surface-600 text-surface-700 dark:text-surface-300 text-[11px] font-medium transition-colors"
                >
                  <span class="material-symbols-rounded text-base">visibility</span>
                  Preview
                </button>
                <a
                  v-if="singleItem.url || singleItem.image_url"
                  :href="singleItem.url || singleItem.image_url"
                  target="_blank"
                  class="flex items-center justify-center gap-1.5 px-3 py-2 rounded-lg bg-primary-500/10 hover:bg-primary-500/20 text-primary-600 dark:text-primary-400 text-[11px] font-medium transition-colors"
                >
                  <span class="material-symbols-rounded text-base">open_in_new</span>
                  Open File
                </a>
                <button
                  v-if="isCollabEditable"
                  @click="$emit('edit-file-collab', singleItem)"
                  class="flex items-center justify-center gap-1.5 col-span-2 px-3 py-2 rounded-lg bg-primary-500/10 hover:bg-primary-500/20 text-primary-600 dark:text-primary-400 text-[11px] font-medium transition-colors"
                >
                  <span class="material-symbols-rounded text-base">edit_document</span>
                  Edit in Editor
                </button>
              </div>
            </div>
          </SidebarSection>
        </template>

        <!-- COLOR SWATCH ITEM: Color editor -->
        <template v-if="singleItem.type === 'color_swatch'">
          <SidebarSection title="color swatch" icon="palette" :open="true">
            <div class="space-y-3">
              <!-- Color preview + info -->
              <div class="flex items-center gap-3">
                <div
                  class="w-14 h-14 rounded-xl border border-surface-200 dark:border-surface-600 flex-shrink-0 shadow-sm"
                  :style="{ backgroundColor: singleItem.color || '#6366f1' }"
                />
                <div class="flex-1 min-w-0">
                  <p class="text-sm font-mono font-semibold text-surface-700 dark:text-surface-300 uppercase">{{ singleItem.color || '#6366f1' }}</p>
                  <p class="text-[10px] font-mono text-surface-400 mt-0.5">
                    R{{ swatchRgb.r }} G{{ swatchRgb.g }} B{{ swatchRgb.b }}
                  </p>
                  <p class="text-[10px] font-mono text-surface-400">
                    C{{ swatchCmyk.c }} M{{ swatchCmyk.m }} Y{{ swatchCmyk.y }} K{{ swatchCmyk.k }}
                  </p>
                </div>
              </div>

              <!-- Color picker (full HSV + hex/rgb + save/eyedropper) -->
              <MoodColorPicker
                :model-value="singleItem.color || '#6366f1'"
                @update:model-value="applySwatchColor($event)"
                :palette="store.getColorPalette()"
                label="Swatch color"
                :show-caret="false"
                dropdown-position="top-full left-0"
              />
            </div>
          </SidebarSection>
        </template>

      </template>

      <!-- ============================================ -->
      <!-- NOTHING SELECTED: Board settings             -->
      <!-- ============================================ -->
      <template v-if="nothingSelected">
        <SidebarSection title="Board Background" icon="image" :open="true">
          <div class="space-y-3">
            <!-- Background Color -->
            <div>
              <label class="text-[9px] text-surface-400 uppercase tracking-wider font-medium">Background Color</label>
              <div class="mt-1">
                <MoodColorPicker
                  :model-value="board?.background_color || '#f5f5f5'"
                  @update:model-value="$emit('update-board-field', 'background_color', $event)"
                  :palette="store.getColorPalette()"
                  label="Board BG"
                  :show-caret="false"
                  dropdown-position="top-full left-0"
                />
              </div>
            </div>

            <!-- Background Image -->
            <div>
              <label class="text-[9px] text-surface-400 uppercase tracking-wider font-medium">Background Image</label>
              <div class="mt-1.5">
                <!-- Preview -->
                <div
                  v-if="board?.background_image"
                  class="relative group rounded-lg overflow-hidden border border-surface-200 dark:border-surface-700 mb-2"
                >
                  <img
                    :src="board.background_image"
                    alt="Background"
                    class="w-full h-24 object-cover"
                  />
                  <div class="absolute inset-0 bg-black/0 group-hover:bg-black/40 transition-colors flex items-center justify-center gap-2 opacity-0 group-hover:opacity-100">
                    <button
                      @click="$emit('update-board-field', 'background_image', null)"
                      class="p-1.5 rounded-lg bg-red-500/90 text-white hover:bg-red-600 transition-colors"
                      title="Remove image"
                    >
                      <span class="material-symbols-rounded text-sm">close</span>
                    </button>
                  </div>
                </div>

                <!-- Size options (when image set) -->
                <div v-if="board?.background_image" class="mb-2">
                  <label class="text-[9px] text-surface-400 uppercase tracking-wider font-medium mb-1 block">Size</label>
                  <div class="flex gap-1">
                    <button
                      v-for="opt in bgImageSizeOptions"
                      :key="opt.value"
                      @click="$emit('update-board-field', 'background_image_size', opt.value)"
                      class="flex-1 px-2 py-1 text-[10px] font-medium rounded-lg border transition-colors"
                      :class="(board?.background_image_size || 'cover') === opt.value
                        ? 'bg-primary-50 dark:bg-primary-900/30 border-primary-300 dark:border-primary-700 text-primary-700 dark:text-primary-300'
                        : 'border-surface-200 dark:border-surface-700 text-surface-500 hover:bg-surface-100 dark:hover:bg-surface-700'"
                    >{{ opt.label }}</button>
                  </div>
                </div>

                <!-- Upload / Actions -->
                <div class="flex gap-1.5">
                  <input
                    ref="bgImageInput"
                    type="file"
                    accept="image/*"
                    class="hidden"
                    @change="handleBgImageUpload"
                  />
                  <button
                    @click="bgImageInput?.click()"
                    :disabled="bgImageUploading"
                    class="flex-1 flex items-center justify-center gap-1 px-2 py-1.5 rounded-lg border border-surface-200 dark:border-surface-700 text-[10px] font-medium text-surface-600 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
                  >
                    <span v-if="bgImageUploading" class="material-symbols-rounded text-xs animate-spin">progress_activity</span>
                    <span v-else class="material-symbols-rounded text-xs">upload</span>
                    Upload
                  </button>
                  <button
                    @click="bgImageUrlPrompt"
                    class="flex-1 flex items-center justify-center gap-1 px-2 py-1.5 rounded-lg border border-surface-200 dark:border-surface-700 text-[10px] font-medium text-surface-600 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
                  >
                    <span class="material-symbols-rounded text-xs">link</span>
                    URL
                  </button>
                </div>
              </div>
            </div>
          </div>
        </SidebarSection>

        <SidebarSection title="Background Effects" icon="auto_awesome" :open="false">
          <MoodBackgroundEffects :embedded="true" />
        </SidebarSection>

        <SidebarSection title="Background Audio" icon="music_note" :open="false">
          <!-- Background audio preview player (invisible) -->
          <MoodBgAudio
            v-if="bgAudioPreviewing && bgAudioCurrentUrl"
            :config="bgAudioParsed()"
            :playing="bgAudioPreviewing"
          />

          <p class="text-[10px] text-surface-400 dark:text-surface-500 mb-2.5">
            Ambient audio for presentations. Only audio from YouTube videos.
          </p>

          <!-- Source type toggle -->
          <div class="flex gap-1 mb-2.5">
            <button
              @click="bgAudioType = 'youtube'"
              class="flex-1 px-2 py-1.5 rounded-lg text-[10px] font-medium transition-all flex items-center justify-center gap-1"
              :class="bgAudioType === 'youtube'
                ? 'bg-red-500/10 text-red-600 dark:text-red-400 border border-red-300 dark:border-red-700'
                : 'bg-surface-50 dark:bg-surface-700 text-surface-500 border border-surface-200 dark:border-surface-600 hover:border-surface-300'"
            >
              <span class="material-symbols-rounded" style="font-size: 14px;">smart_display</span>
              YouTube
            </button>
            <button
              @click="bgAudioType = 'file'"
              class="flex-1 px-2 py-1.5 rounded-lg text-[10px] font-medium transition-all flex items-center justify-center gap-1"
              :class="bgAudioType === 'file'
                ? 'bg-primary-500/10 text-primary-600 dark:text-primary-400 border border-primary-300 dark:border-primary-700'
                : 'bg-surface-50 dark:bg-surface-700 text-surface-500 border border-surface-200 dark:border-surface-600 hover:border-surface-300'"
            >
              <span class="material-symbols-rounded" style="font-size: 14px;">audio_file</span>
              Audio File
            </button>
          </div>

          <!-- YouTube URL input -->
          <div v-if="bgAudioType === 'youtube'" class="mb-2.5">
            <div class="flex gap-1">
              <input
                v-model="bgAudioUrl"
                type="url"
                placeholder="https://www.youtube.com/watch?v=..."
                class="flex-1 px-2 py-1.5 text-[10px] rounded-lg border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-surface-900 dark:text-surface-100 focus:ring-1 focus:ring-primary-500 focus:border-transparent outline-none"
                @keydown.enter="bgAudioSave"
              />
              <button
                @click="bgAudioSave"
                class="px-2 py-1.5 rounded-lg text-[10px] font-medium bg-primary-500 text-white hover:bg-primary-600 transition-colors flex items-center"
                :disabled="!bgAudioUrl"
              >
                <span class="material-symbols-rounded" style="font-size: 14px;">check</span>
              </button>
            </div>
            <p class="text-[9px] text-surface-400 dark:text-surface-500 mt-0.5">
              youtube.com, youtu.be, music.youtube.com
            </p>
          </div>

          <!-- Audio file URL input -->
          <div v-if="bgAudioType === 'file'" class="mb-2.5">
            <div class="flex gap-1">
              <input
                v-model="bgAudioUrl"
                type="url"
                placeholder="https://example.com/ambient.mp3"
                class="flex-1 px-2 py-1.5 text-[10px] rounded-lg border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-surface-900 dark:text-surface-100 focus:ring-1 focus:ring-primary-500 focus:border-transparent outline-none"
                @keydown.enter="bgAudioSave"
              />
              <button
                @click="bgAudioSave"
                class="px-2 py-1.5 rounded-lg text-[10px] font-medium bg-primary-500 text-white hover:bg-primary-600 transition-colors flex items-center"
                :disabled="!bgAudioUrl"
              >
                <span class="material-symbols-rounded" style="font-size: 14px;">check</span>
              </button>
            </div>
            <p class="text-[9px] text-surface-400 dark:text-surface-500 mt-0.5">
              Direct link to .mp3, .ogg, .wav, or .m4a
            </p>
          </div>

          <!-- Current audio controls -->
          <div v-if="bgAudioCurrentUrl" class="p-2.5 rounded-lg bg-surface-50 dark:bg-surface-700/50 border border-surface-200 dark:border-surface-600">
            <div class="flex items-center gap-1.5 mb-2">
              <span class="material-symbols-rounded text-primary-500" style="font-size: 16px;">
                {{ bgAudioCurrentType === 'youtube' ? 'smart_display' : 'audio_file' }}
              </span>
              <span class="text-[10px] text-surface-700 dark:text-surface-300 truncate flex-1" :title="bgAudioCurrentUrl">
                {{ bgAudioCurrentUrl }}
              </span>
              <button
                @click="bgAudioRemove"
                class="p-0.5 rounded hover:bg-red-50 dark:hover:bg-red-900/20 text-surface-400 hover:text-red-500 transition-colors flex-shrink-0"
                title="Remove"
              >
                <span class="material-symbols-rounded" style="font-size: 14px;">close</span>
              </button>
            </div>

            <!-- Volume slider -->
            <div class="flex items-center gap-1.5">
              <span class="material-symbols-rounded text-surface-400" style="font-size: 14px;">
                {{ bgAudioVolume === 0 ? 'volume_off' : bgAudioVolume < 40 ? 'volume_down' : 'volume_up' }}
              </span>
              <input
                type="range"
                min="0"
                max="100"
                :value="bgAudioVolume"
                @input="bgAudioVolume = parseInt($event.target.value)"
                @change="bgAudioSave"
                class="flex-1 h-1 rounded-full appearance-none bg-surface-200 dark:bg-surface-600 accent-primary-500 cursor-pointer
                       [&::-webkit-slider-thumb]:appearance-none [&::-webkit-slider-thumb]:w-3 [&::-webkit-slider-thumb]:h-3 [&::-webkit-slider-thumb]:rounded-full [&::-webkit-slider-thumb]:bg-primary-500 [&::-webkit-slider-thumb]:cursor-pointer
                       [&::-moz-range-thumb]:w-3 [&::-moz-range-thumb]:h-3 [&::-moz-range-thumb]:rounded-full [&::-moz-range-thumb]:bg-primary-500 [&::-moz-range-thumb]:border-0 [&::-moz-range-thumb]:cursor-pointer"
              />
              <span class="text-[10px] text-surface-500 dark:text-surface-400 w-7 text-right tabular-nums">{{ bgAudioVolume }}%</span>
            </div>

            <!-- Loop toggle -->
            <div class="flex items-center justify-between mt-2 pt-2 border-t border-surface-200 dark:border-surface-600">
              <span class="text-[10px] text-surface-600 dark:text-surface-400 flex items-center gap-1">
                <span class="material-symbols-rounded" style="font-size: 14px;">repeat</span>
                Loop
              </span>
              <button
                @click="bgAudioLoop = !bgAudioLoop; bgAudioSave()"
                class="relative w-8 h-[18px] rounded-full transition-colors duration-200 focus:outline-none"
                :class="bgAudioLoop ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'"
              >
                <span
                  class="absolute top-[2px] left-[2px] w-[14px] h-[14px] bg-white rounded-full shadow transition-transform duration-200"
                  :class="bgAudioLoop ? 'translate-x-[14px]' : 'translate-x-0'"
                />
              </button>
            </div>

            <!-- Loop range (start / end) — only shown when loop is on -->
            <div v-if="bgAudioLoop" class="mt-2 pt-2 border-t border-surface-200 dark:border-surface-600">
              <div class="flex items-center gap-1 mb-1.5">
                <span class="material-symbols-rounded text-surface-400" style="font-size: 14px;">timer</span>
                <span class="text-[10px] text-surface-600 dark:text-surface-400">Loop Range</span>
                <span class="text-[9px] text-surface-400 dark:text-surface-500 ml-auto">optional</span>
              </div>
              <div class="flex items-center gap-1.5">
                <div class="flex-1 relative">
                  <input
                    v-model="bgAudioLoopStart"
                    type="text"
                    placeholder="0:00"
                    class="w-full px-2 py-1 text-[10px] text-center rounded-lg border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-surface-900 dark:text-surface-100 focus:ring-1 focus:ring-primary-500 focus:border-transparent outline-none tabular-nums"
                    @change="bgAudioSave"
                  />
                  <span class="absolute -top-1.5 left-1.5 text-[8px] text-surface-400 dark:text-surface-500 bg-white dark:bg-surface-700 px-0.5">from</span>
                </div>
                <span class="text-[10px] text-surface-400">-</span>
                <div class="flex-1 relative">
                  <input
                    v-model="bgAudioLoopEnd"
                    type="text"
                    placeholder="0:00"
                    class="w-full px-2 py-1 text-[10px] text-center rounded-lg border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-surface-900 dark:text-surface-100 focus:ring-1 focus:ring-primary-500 focus:border-transparent outline-none tabular-nums"
                    @change="bgAudioSave"
                  />
                  <span class="absolute -top-1.5 left-1.5 text-[8px] text-surface-400 dark:text-surface-500 bg-white dark:bg-surface-700 px-0.5">to</span>
                </div>
              </div>
              <p class="text-[8px] text-surface-400 dark:text-surface-500 mt-1">
                Format: m:ss (e.g. 1:00 to 1:34). Leave empty to loop full track.
              </p>
            </div>

            <!-- Preview button -->
            <div class="mt-2 pt-2 border-t border-surface-200 dark:border-surface-600">
              <button
                @click="bgAudioPreviewing = !bgAudioPreviewing"
                class="w-full px-2 py-1 rounded-lg text-[10px] font-medium transition-colors flex items-center justify-center gap-1"
                :class="bgAudioPreviewing
                  ? 'bg-red-500/10 text-red-600 dark:text-red-400 border border-red-300 dark:border-red-700 hover:bg-red-500/20'
                  : 'bg-primary-500/10 text-primary-600 dark:text-primary-400 border border-primary-300 dark:border-primary-700 hover:bg-primary-500/20'"
              >
                <span class="material-symbols-rounded" style="font-size: 14px;">{{ bgAudioPreviewing ? 'stop' : 'play_arrow' }}</span>
                {{ bgAudioPreviewing ? 'Stop Preview' : 'Preview Audio' }}
              </button>
            </div>
          </div>

          <p v-else class="text-[10px] text-surface-400 dark:text-surface-500 italic">
            No audio set. Plays automatically in presentation mode.
          </p>
        </SidebarSection>

        <SidebarSection title="Board Info" icon="info" :open="false">
          <div class="space-y-2 text-xs text-surface-500 dark:text-surface-400">
            <div class="flex items-center justify-between">
              <span>Items</span>
              <span class="font-medium text-surface-700 dark:text-surface-300">{{ store.currentItems.length }}</span>
            </div>
            <div class="flex items-center justify-between">
              <span>Connections</span>
              <span class="font-medium text-surface-700 dark:text-surface-300">{{ store.currentConnections.length }}</span>
            </div>
            <div class="flex items-center justify-between">
              <span>Slides</span>
              <span class="font-medium text-surface-700 dark:text-surface-300">{{ store.presentationSlides.length }}</span>
            </div>
          </div>
        </SidebarSection>
      </template>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import { useMoodBoardsStore } from '@/addons/moodboards/stores/moodBoards'
import api from '@/services/api'

import MoodColorPicker from './MoodColorPicker.vue'
import MoodScrubInput from './MoodScrubInput.vue'
import MoodGradientPicker from './MoodGradientPicker.vue'
import MoodBackgroundEffects from './MoodBackgroundEffects.vue'
import MoodBgAudio from './MoodBgAudio.vue'
import SidebarSection from './MoodSidebarSection.vue'
import MoodFillSection from './MoodFillSection.vue'
import MoodStrokeSection from './MoodStrokeSection.vue'
import MoodEffectsSection from './MoodEffectsSection.vue'
import MoodCssInspect from './MoodCssInspect.vue'
import MoodGlobalIndicator from './MoodGlobalIndicator.vue'
import MoodSaveStyleDialog from './MoodSaveStyleDialog.vue'
import MoodClassBadgeInput from './MoodClassBadgeInput.vue'
import { useMoodBoardGlobalStylesStore } from '@/addons/moodboards/stores/moodBoardGlobalStyles'
import { FONT_GROUPS as SHARED_FONT_GROUPS, getFontLabel, filterFontGroups, loadGoogleFont, preloadFontList, ALL_FONT_VALUES } from '../utils/fontLoader'

const props = defineProps({
  board: { type: Object, default: null },
})

const emit = defineEmits([
  'update-board-field',
  'preview-file',
  'edit-file-collab',
  'connect-item',
  'toggle-youtube-interactive',
])

const store = useMoodBoardsStore()
const gsStore = useMoodBoardGlobalStylesStore()

const cssClassSuggestions = computed(() =>
  (gsStore.globalCssClasses || []).map(c => ({
    name: c.name,
    summary: Object.entries(c.properties || {}).map(([k, v]) => `${k}: ${v}`).join(', '),
  }))
)

const savedRightCollapsed = localStorage.getItem('mood_right_sidebar_collapsed')
const collapsed = ref(savedRightCollapsed !== null ? savedRightCollapsed === 'true' : false)
watch(collapsed, (v) => localStorage.setItem('mood_right_sidebar_collapsed', String(v)))

const rightTab = ref(localStorage.getItem('mood_right_sidebar_tab') || 'properties')
watch(rightTab, (v) => localStorage.setItem('mood_right_sidebar_tab', v))

function collapseAllSections() { window.dispatchEvent(new Event('mood-sidebar-collapse-all')) }
function expandAllSections() { window.dispatchEvent(new Event('mood-sidebar-expand-all')) }

// ── Selection state ──

const multipleSelected = computed(() => store.selectedItemIds.size >= 2)

// Frozen during drag — sidebar doesn't need to recompute item lookups
// while the user is just repositioning. Prevents O(n) find() per frame.
let _frozenSingleItem = null
const singleItem = computed(() => {
  if (store.isDragging && _frozenSingleItem !== undefined) return _frozenSingleItem
  if (store.selectedItemIds.size !== 1) { _frozenSingleItem = null; return null }
  const id = [...store.selectedItemIds][0]
  const item = store.currentItems.find(i => i.id === id) || null
  _frozenSingleItem = item
  return item
})
const nothingSelected = computed(() => store.selectedItemIds.size === 0)

const linkedFillGlobal = computed(() => {
  const item = singleItem.value
  if (!item) return null
  const key = item.type === 'frame' ? 'fill_color' : 'shape_fill'
  const ref = item.style_data?._globals?.[key]
  if (!ref?.id) return null
  return gsStore.globalColors.find(c => c.id === ref.id) || null
})

const linkedGradientGlobal = computed(() => {
  const item = singleItem.value
  if (!item) return null
  const ref = item.style_data?._globals?.gradient
  if (!ref?.id) return null
  return gsStore.globalGradients.find(g => g.id === ref.id) || null
})

const currentFillIsGradient = computed(() => {
  const sd = singleItem.value?.style_data
  if (!sd) return false
  const t = singleItem.value.type
  if (t === 'shape' || t === 'pen_shape') return sd.shape_fill_type === 'linear' || sd.shape_fill_type === 'radial'
  if (t === 'frame') return sd.fill_type === 'linear' || sd.fill_type === 'radial'
  if (t === 'text') return sd.text_fill_type === 'linear' || sd.text_fill_type === 'radial'
  return false
})

const currentFillGradient = computed(() => {
  const sd = singleItem.value?.style_data
  if (!sd) return null
  const t = singleItem.value.type
  if (t === 'shape' || t === 'pen_shape') return sd.shape_fill_gradient || null
  if (t === 'frame') return sd.fill_gradient || null
  if (t === 'text') return sd.text_fill_gradient || null
  return null
})

const currentFillType = computed(() => {
  const sd = singleItem.value?.style_data
  if (!sd) return 'solid'
  const t = singleItem.value.type
  if (t === 'shape' || t === 'pen_shape') return sd.shape_fill_type || 'solid'
  if (t === 'frame') return sd.fill_type || 'solid'
  if (t === 'text') return sd.text_fill_type || 'solid'
  return 'solid'
})

const linkedTextStyleGlobal = computed(() => {
  const item = singleItem.value
  if (!item) return null
  const ref = item.style_data?._globals?.text_style
  if (!ref?.id) return null
  return gsStore.globalTextStyles.find(s => s.id === ref.id) || null
})

const linkedTextColorGlobal = computed(() => {
  const item = singleItem.value
  if (!item) return null
  const ref = item.style_data?._globals?.text_color
  if (!ref?.id) return null
  return gsStore.globalColors.find(c => c.id === ref.id) || null
})

// Detect parent frame context for child-in-frame controls
const parentFrame = computed(() => {
  if (!singleItem.value?.parent_id) return null
  return store.getItemById(singleItem.value.parent_id)
})
const isChildOfFrame = computed(() => parentFrame.value?.type === 'frame')
const isChildOfAutoLayout = computed(() => isChildOfFrame.value && !!parentFrame.value?.style_data?.auto_layout)
const isChildOfStaticFrame = computed(() => isChildOfFrame.value && !parentFrame.value?.style_data?.auto_layout)
const parentLayoutMode = computed(() => {
  const p = parentFrame.value
  if (!p) return 'none'
  if (p.type === 'group') return p.style_data?.layout_mode || 'none'
  if (p.type === 'frame' && p.style_data?.auto_layout) return 'flex'
  return 'none'
})
const isChildOfLayoutParent = computed(() => parentLayoutMode.value !== 'none')

const hasGroups = computed(() => store.selectionHasGroups())
const canMask = computed(() => store.canMaskSelection())
const canBooleanOp = computed(() => store.canBooleanOp())

const booleanOps = [
  { id: 'union',     icon: 'join_full',  label: 'Union',     shortcut: 'Alt+Shift+U' },
  { id: 'subtract',  icon: 'join_left',  label: 'Subtract',  shortcut: 'Alt+Shift+S' },
  { id: 'intersect', icon: 'join_inner', label: 'Intersect', shortcut: 'Alt+Shift+I' },
  { id: 'exclude',   icon: 'join_right', label: 'Exclude',   shortcut: 'Alt+Shift+E' },
  { id: 'flatten',   icon: 'layers_clear', label: 'Flatten', shortcut: 'Alt+Shift+F' },
]

// ── Selection Colors ──
// Collect all unique colors from selected items, tracking which items & property keys use each color
const selectionColors = computed(() => {
  if (!multipleSelected.value) return []
  const items = store.selectedItems
  const colorMap = new Map() // hex -> { color, opacity, sources: [{ itemId, key, opacityKey }] }

  function addColor(item, colorVal, sdKey, opacityKey) {
    if (!colorVal || colorVal === 'transparent' || colorVal === 'none') return
    const hex = colorVal.toLowerCase()
    const sd = item.style_data || {}
    const opacity = opacityKey ? (sd[opacityKey] ?? 100) : 100
    if (!colorMap.has(hex)) {
      colorMap.set(hex, { color: hex, opacity, sources: [] })
    }
    colorMap.get(hex).sources.push({ itemId: item.id, key: sdKey, opacityKey })
  }

  for (const item of items) {
    const sd = item.style_data || {}
    const t = item.type

    // item.color (notes, todos, links, board_links)
    if (item.color && !['shape', 'pen_shape', 'text', 'frame', 'slide', 'line', 'drawing'].includes(t)) {
      addColor(item, item.color, '_item_color', null)
    }

    // Text
    if (t === 'text') {
      addColor(item, sd.text_color, 'text_color', 'text_opacity')
    }

    // Shape / Pen Shape fills & borders
    if (t === 'shape' || t === 'pen_shape') {
      addColor(item, sd.shape_fill, 'shape_fill', 'shape_opacity')
      if (sd.shape_border_color && sd.shape_border_width > 0) {
        addColor(item, sd.shape_border_color, 'shape_border_color', null)
      }
      if (sd.shape_text_color) {
        addColor(item, sd.shape_text_color, 'shape_text_color', null)
      }
    }

    // Stroke (shapes, frames)
    if (sd.stroke_color && sd.stroke_width > 0) {
      addColor(item, sd.stroke_color, 'stroke_color', null)
    }

    // Frame / Slide fill
    if (t === 'frame' || t === 'slide') {
      addColor(item, sd.fill_color, 'fill_color', 'frame_opacity')
    }

    // Line
    if (t === 'line') {
      addColor(item, sd.line_color, 'line_color', null)
    }

    // Drawing strokes color (from content)
    if (t === 'drawing' && sd.draw_color) {
      addColor(item, sd.draw_color, 'draw_color', null)
    }

    // Color swatch
    if (t === 'color_swatch' && item.color) {
      addColor(item, item.color, '_item_color', null)
    }
  }

  return Array.from(colorMap.values())
})

function updateSelectionColor(oldColorOrSources, newColor) {
  // Accept either a color string (legacy/hex-input) or a sources array (drag-safe)
  let sources
  if (Array.isArray(oldColorOrSources)) {
    sources = oldColorOrSources
  } else {
    const entry = selectionColors.value.find(e => e.color === oldColorOrSources)
    if (!entry) return
    sources = entry.sources
  }
  for (const src of sources) {
    if (src.key === '_item_color') {
      store.updateItem(src.itemId, { color: newColor })
    } else {
      const item = store.currentItems.find(i => i.id === src.itemId)
      if (!item) continue
      const current = item.style_data || {}
      store.updateItem(src.itemId, { style_data: { ...current, [src.key]: newColor } })
    }
  }
}

function updateSelectionColorOpacity(oldColor, newOpacity) {
  const entry = selectionColors.value.find(e => e.color === oldColor)
  if (!entry) return
  for (const src of entry.sources) {
    if (!src.opacityKey) continue
    const item = store.currentItems.find(i => i.id === src.itemId)
    if (!item) continue
    const current = item.style_data || {}
    store.updateItem(src.itemId, { style_data: { ...current, [src.opacityKey]: newOpacity } })
  }
  // Update the local display
  entry.opacity = newOpacity
}

// ── Header ──

const headerIcon = computed(() => {
  if (multipleSelected.value) return 'select_all'
  if (singleItem.value) {
    const TYPE_ICONS = {
      note: 'sticky_note_2', image: 'image', text: 'title', shape: 'shapes',
      drawing: 'draw', frame: 'crop_free', slide: 'slideshow', file: 'insert_drive_file',
      color_swatch: 'color_lens', link: 'link', todo_list: 'checklist',
      pen_shape: 'ink_pen', video: 'videocam', youtube: 'smart_display',
      board_link: 'dashboard', image_set: 'photo_library',
      calendar_event: 'event', folder: 'folder', table: 'table_chart',
      column: 'view_column',
    }
    return TYPE_ICONS[singleItem.value.type] || 'article'
  }
  return 'tune'
})

const headerTitle = computed(() => {
  if (multipleSelected.value) return `${store.selectedItemIds.size} Items Selected`
  if (singleItem.value) {
    const t = singleItem.value.type.replace('_', ' ')
    return (singleItem.value.title || t).substring(0, 30)
  }
  return 'Properties'
})

// ── Alignment / Distribute constants ──

const alignActions = [
  { dir: 'left',     icon: 'align_horizontal_left',   label: 'Align Left',   shortLabel: 'Left' },
  { dir: 'center-h', icon: 'align_horizontal_center', label: 'Align Center', shortLabel: 'Center' },
  { dir: 'right',    icon: 'align_horizontal_right',  label: 'Align Right',  shortLabel: 'Right' },
  { dir: 'top',      icon: 'align_vertical_top',      label: 'Align Top',    shortLabel: 'Top' },
  { dir: 'center-v', icon: 'align_vertical_center',   label: 'Align Middle', shortLabel: 'Middle' },
  { dir: 'bottom',   icon: 'align_vertical_bottom',   label: 'Align Bottom', shortLabel: 'Bottom' },
]

const distributeActions = [
  { dir: 'distribute-h', icon: 'horizontal_distribute', label: 'Horizontal' },
  { dir: 'distribute-v', icon: 'vertical_distribute',   label: 'Vertical' },
]

const lastGroupAlign = ref(null)

function alignGroupChildren(dir) {
  const group = singleItem.value
  if (!group || group.type !== 'group') return
  const children = store.currentItems.filter(i => i.parent_id === group.id)
  if (children.length < 2) return
  const savedSel = new Set(store.selectedItemIds)
  store.selectedItemIds = new Set(children.map(c => c.id))
  store.alignItems(dir)
  store.selectedItemIds = savedSel
}

// ── Group Layout Mode ──

const layoutModes = [
  { id: 'none', label: 'None', icon: 'close' },
  { id: 'flex', label: 'Flex', icon: 'view_stream' },
  { id: 'grid', label: 'Grid', icon: 'grid_view' },
]

const flexDirections = [
  { value: 'row', icon: 'arrow_forward' },
  { value: 'column', icon: 'arrow_downward' },
  { value: 'row-reverse', icon: 'arrow_back' },
  { value: 'column-reverse', icon: 'arrow_upward' },
]

const flexAlignOptions = [
  { value: 'start', icon: 'align_vertical_top', label: 'Start' },
  { value: 'center', icon: 'align_vertical_center', label: 'Center' },
  { value: 'end', icon: 'align_vertical_bottom', label: 'End' },
  { value: 'stretch', icon: 'expand', label: 'Stretch' },
]

const flexJustifyOptions = [
  { value: 'start', icon: 'align_horizontal_left', label: 'Start' },
  { value: 'center', icon: 'align_horizontal_center', label: 'Center' },
  { value: 'end', icon: 'align_horizontal_right', label: 'End' },
  { value: 'space-between', icon: 'horizontal_distribute', label: 'Between' },
  { value: 'space-around', icon: 'space_bar', label: 'Around' },
]

const gridAlignOptions = [
  { value: 'start', label: 'Start' },
  { value: 'center', label: 'Center' },
  { value: 'end', label: 'End' },
  { value: 'stretch', label: 'Stretch' },
]

const groupLayoutModeVal = computed(() => {
  const item = singleItem.value
  if (!item || item.type !== 'group') return 'none'
  return item.style_data?.layout_mode || 'none'
})

const groupClipContent = computed(() => {
  const item = singleItem.value
  if (!item || item.type !== 'group') return false
  const sd = item.style_data || {}
  if (sd.clip_content !== undefined) return sd.clip_content
  return sd.layout_mode && sd.layout_mode !== 'none'
})

function setGroupLayoutMode(mode) {
  const item = singleItem.value
  if (!item) return
  const sd = { ...(item.style_data || {}), layout_mode: mode }
  store.updateItem(item.id, { style_data: sd })
}

function updateGroupLayout(key, value) {
  const item = singleItem.value
  if (!item) return
  const sd = { ...(item.style_data || {}), [key]: value }
  store.updateItem(item.id, { style_data: sd })
}

// ── Constants ──

const noteColors = [
  '#fef3c7', '#fecaca', '#bbf7d0', '#bfdbfe', '#ddd6fe',
  '#fbcfe8', '#fed7aa', '#e2e8f0', '#ffffff', '#1e293b'
]

// ── Device viewport presets for frames ──
const FRAME_PRESETS = [
  { label: 'Desktop', category: 'Desktop', icon: 'desktop_windows', width: 1920, height: 1080 },
  { label: 'Desktop HD', category: 'Desktop', icon: 'desktop_windows', width: 1440, height: 900 },
  { label: 'MacBook Pro 16"', category: 'Desktop', icon: 'laptop_mac', width: 1728, height: 1117 },
  { label: 'MacBook Pro 14"', category: 'Desktop', icon: 'laptop_mac', width: 1512, height: 982 },
  { label: 'MacBook Air', category: 'Desktop', icon: 'laptop_mac', width: 1280, height: 832 },
  { label: 'Surface Pro 8', category: 'Desktop', icon: 'laptop', width: 1440, height: 960 },
  { label: 'iMac 24"', category: 'Desktop', icon: 'desktop_mac', width: 2048, height: 1152 },
  { label: 'iPad Pro 12.9"', category: 'Tablet', icon: 'tablet_mac', width: 1024, height: 1366 },
  { label: 'iPad Pro 11"', category: 'Tablet', icon: 'tablet_mac', width: 834, height: 1194 },
  { label: 'iPad Mini', category: 'Tablet', icon: 'tablet', width: 744, height: 1133 },
  { label: 'Surface Pro', category: 'Tablet', icon: 'tablet', width: 912, height: 1368 },
  { label: 'iPad Air', category: 'Tablet', icon: 'tablet_mac', width: 820, height: 1180 },
  { label: 'iPhone 16 Pro Max', category: 'Phone', icon: 'smartphone', width: 440, height: 956 },
  { label: 'iPhone 16 Pro', category: 'Phone', icon: 'smartphone', width: 402, height: 874 },
  { label: 'iPhone 16', category: 'Phone', icon: 'smartphone', width: 393, height: 852 },
  { label: 'iPhone SE', category: 'Phone', icon: 'smartphone', width: 375, height: 667 },
  { label: 'Android Large', category: 'Phone', icon: 'phone_android', width: 412, height: 915 },
  { label: 'Android Small', category: 'Phone', icon: 'phone_android', width: 360, height: 800 },
  { label: 'Samsung Galaxy S24', category: 'Phone', icon: 'phone_android', width: 412, height: 915 },
  { label: 'Pixel 8', category: 'Phone', icon: 'phone_android', width: 412, height: 892 },
  { label: 'Instagram Post', category: 'Social', icon: 'photo_camera', width: 1080, height: 1080 },
  { label: 'Instagram Story', category: 'Social', icon: 'photo_camera', width: 1080, height: 1920 },
  { label: 'Facebook Post', category: 'Social', icon: 'public', width: 1200, height: 630 },
  { label: 'Facebook Cover', category: 'Social', icon: 'public', width: 820, height: 312 },
  { label: 'Twitter/X Post', category: 'Social', icon: 'tag', width: 1200, height: 675 },
  { label: 'Twitter/X Header', category: 'Social', icon: 'tag', width: 1500, height: 500 },
  { label: 'LinkedIn Banner', category: 'Social', icon: 'work', width: 1584, height: 396 },
  { label: 'YouTube Thumbnail', category: 'Social', icon: 'smart_display', width: 1280, height: 720 },
  { label: 'Pinterest Pin', category: 'Social', icon: 'push_pin', width: 1000, height: 1500 },
  { label: 'Apple Watch 49mm', category: 'Watch', icon: 'watch', width: 205, height: 251 },
  { label: 'Apple Watch 45mm', category: 'Watch', icon: 'watch', width: 198, height: 242 },
  { label: 'Apple Watch 41mm', category: 'Watch', icon: 'watch', width: 176, height: 215 },
  { label: 'A4 (Portrait)', category: 'Print', icon: 'description', width: 595, height: 842 },
  { label: 'A4 (Landscape)', category: 'Print', icon: 'description', width: 842, height: 595 },
  { label: 'Letter', category: 'Print', icon: 'description', width: 612, height: 792 },
  { label: 'Business Card', category: 'Print', icon: 'badge', width: 252, height: 144 },
  { label: 'Custom', category: 'Custom', icon: 'crop_free', width: 400, height: 300 },
]

const FRAME_PRESET_CATEGORIES = ['Desktop', 'Tablet', 'Phone', 'Social', 'Watch', 'Print', 'Custom']

// State for frame controls
const framePaddingExpanded = ref(false)
const showFramePresetDropdown = ref(false)
const framePresetSearch = ref('')
const framePresetDropdownRef = ref(null)

const filteredFramePresets = computed(() => {
  const q = framePresetSearch.value.toLowerCase().trim()
  if (!q) return FRAME_PRESETS
  return FRAME_PRESETS.filter(p => p.label.toLowerCase().includes(q) || p.category.toLowerCase().includes(q))
})

const groupedFramePresets = computed(() => {
  const groups = []
  for (const cat of FRAME_PRESET_CATEGORIES) {
    const items = filteredFramePresets.value.filter(p => p.category === cat)
    if (items.length > 0) groups.push({ label: cat, presets: items })
  }
  return groups
})

const currentFramePreset = computed(() => {
  if (!singleItem.value || singleItem.value.type !== 'frame') return null
  const w = singleItem.value.width
  const sd = singleItem.value.style_data || {}
  return FRAME_PRESETS.find(p => p.width === w && p.label === sd.frame_device) || 
         FRAME_PRESETS.find(p => p.width === w) ||
         { label: 'Custom', category: 'Custom', icon: 'crop_free', width: w, height: singleItem.value.height }
})

function selectFramePreset(preset) {
  if (!singleItem.value || singleItem.value.type !== 'frame') return
  updateSingleItem({ width: preset.width, height: preset.height })
  updateItemStyleData({ frame_device: preset.label })
  showFramePresetDropdown.value = false
  framePresetSearch.value = ''
}

// Close frame preset dropdown on outside click
watch(showFramePresetDropdown, (v) => {
  if (v) {
    setTimeout(() => document.addEventListener('click', onFramePresetClickOutside), 0)
  } else {
    document.removeEventListener('click', onFramePresetClickOutside)
  }
})
function onFramePresetClickOutside(e) {
  if (showFramePresetDropdown.value && framePresetDropdownRef.value && !framePresetDropdownRef.value.contains(e.target)) {
    showFramePresetDropdown.value = false
    framePresetSearch.value = ''
  }
}

const drawingColors = [
  '#1e293b', '#ef4444', '#f97316', '#eab308',
  '#22c55e', '#3b82f6', '#8b5cf6', '#ec4899', '#ffffff'
]

const blendModeGroups = [
  { label: null, modes: ['pass-through', 'normal'] },
  { label: 'Darken', modes: ['darken', 'multiply', 'plus-darker', 'color-burn'] },
  { label: 'Lighten', modes: ['lighten', 'screen', 'plus-lighter', 'color-dodge'] },
  { label: 'Contrast', modes: ['overlay', 'soft-light', 'hard-light'] },
  { label: 'Inversion', modes: ['difference', 'exclusion'] },
  { label: 'Component', modes: ['hue', 'saturation', 'color', 'luminosity'] },
]

const cornerDefs = [
  { key: 'tl', label: 'Top Left', rotate: 'none' },
  { key: 'tr', label: 'Top Right', rotate: 'rotate(90deg)' },
  { key: 'bl', label: 'Bottom Left', rotate: 'rotate(-90deg)' },
  { key: 'br', label: 'Bottom Right', rotate: 'rotate(180deg)' },
]

const maskFitOptions = [
  { value: 'cover',      label: 'Cover',      icon: 'crop' },
  { value: 'contain',    label: 'Contain',     icon: 'fit_screen' },
  { value: 'fill',       label: 'Fill',        icon: 'aspect_ratio' },
  { value: 'none',       label: 'None',        icon: 'photo_size_select_actual' },
  { value: 'scale-down', label: 'Scale Down',  icon: 'photo_size_select_large' },
]

const imageFitOptions = [
  { value: 'cover',      label: 'Cover',      icon: 'crop' },
  { value: 'contain',    label: 'Contain',     icon: 'fit_screen' },
  { value: 'fill',       label: 'Fill',        icon: 'aspect_ratio' },
  { value: 'none',       label: 'None',        icon: 'photo_size_select_actual' },
  { value: 'scale-down', label: 'Scale Down',  icon: 'photo_size_select_large' },
]

// ── Item updates ──

function updateSingleItem(data) {
  if (!singleItem.value) return
  store.updateItem(singleItem.value.id, data)
}

function detachComponentInstance(item) {
  store.updateItem(item.id, {
    component_id: null,
    component_instance_id: null,
    component_item_index: null,
  })
}

function resetComponentOverrides(item) {
  const sd = { ...(item.style_data || {}) }
  delete sd._overrides
  store.updateItem(item.id, { style_data: sd })
}

function moveContainerAware(data) {
  const item = singleItem.value
  if (!item) return
  const isContainer = item.type === 'group' || item.type === 'repeat_grid'
  if (!isContainer) { store.updateItem(item.id, data); return }
  const dx = data.pos_x != null ? data.pos_x - (item.pos_x || 0) : 0
  const dy = data.pos_y != null ? data.pos_y - (item.pos_y || 0) : 0
  const updates = [{ id: item.id, ...data }]
  if (dx !== 0 || dy !== 0) _cascadeChildDeltas(item.id, dx, dy, updates)
  store.batchUpdateItems(updates)
}

function _cascadeChildDeltas(parentId, dx, dy, out) {
  const children = store.currentItems.filter(i => i.parent_id === parentId)
  for (const c of children) {
    out.push({ id: c.id, pos_x: (c.pos_x || 0) + dx, pos_y: (c.pos_y || 0) + dy })
    if (c.type === 'group' || c.type === 'repeat_grid') {
      _cascadeChildDeltas(c.id, dx, dy, out)
    }
  }
}

function updateItemStyleData(updates) {
  if (!singleItem.value) return
  const current = singleItem.value.style_data || {}
  store.updateItem(singleItem.value.id, { style_data: { ...current, ...updates } })
}

// ── Padding / Margin linked mode ──
const paddingLinked = ref(true)
const marginLinked = ref(true)

const paddingAll = computed(() => {
  const sd = singleItem.value?.style_data || {}
  return sd.padding_top || sd.padding_right || sd.padding_bottom || sd.padding_left || 0
})

const marginAll = computed(() => {
  const sd = singleItem.value?.style_data || {}
  const mt = sd.margin_top, mr = sd.margin_right, mb = sd.margin_bottom, ml = sd.margin_left
  if (mt === 'auto' || mr === 'auto' || mb === 'auto' || ml === 'auto') return 0
  return mt || mr || mb || ml || 0
})

function setPaddingAll(v) {
  updateItemStyleData({ padding_top: v || 0, padding_right: v || 0, padding_bottom: v || 0, padding_left: v || 0 })
}

function setMarginAll(v) {
  updateItemStyleData({ margin_top: v || 0, margin_right: v || 0, margin_bottom: v || 0, margin_left: v || 0 })
}

async function onTextClipImagePick(e) {
  const file = e.target.files?.[0]
  if (!file || !singleItem.value) return
  try {
    const uploaded = await store.uploadFiles([file])
    if (uploaded[0]) {
      updateItemStyleData({ text_clip_image: uploaded[0].url, text_clip_image_size: 'cover' })
    }
  } catch (err) {
    console.error('Text clip image upload failed:', err)
  }
}

function updateRepeatGridParam(key, value) {
  if (!singleItem.value || singleItem.value.type !== 'repeat_grid') return
  const sd = { ...(singleItem.value.style_data || {}), [key]: value }
  const cols = sd.grid_columns || 3
  const rows = sd.grid_rows || 3
  const hGap = sd.grid_h_gap ?? 20
  const vGap = sd.grid_v_gap ?? 20
  const children = store.currentItems.filter(i => i.parent_id === singleItem.value.id)
  const gx = singleItem.value.pos_x || 0
  const gy = singleItem.value.pos_y || 0
  let cw = 100, ch = 100
  for (const c of children) {
    cw = Math.max(cw, (c.pos_x || 0) - gx + (c.width || 100))
    ch = Math.max(ch, (c.pos_y || 0) - gy + (c.height || 100))
  }
  store.updateItem(singleItem.value.id, {
    style_data: sd,
    width: cols * cw + Math.max(0, cols - 1) * hGap,
    height: rows * ch + Math.max(0, rows - 1) * vGap,
  })
}

function toggleFlipX() {
  if (!singleItem.value) return
  const sd = singleItem.value.style_data || {}
  updateItemStyleData({ flip_x: !sd.flip_x })
}

function toggleFlipY() {
  if (!singleItem.value) return
  const sd = singleItem.value.style_data || {}
  updateItemStyleData({ flip_y: !sd.flip_y })
}

// ── Appearance: type-aware opacity ──
// Each item type stores opacity under a different style_data key
const appearanceOpacity = computed(() => {
  const item = singleItem.value
  if (!item) return 100
  const sd = item.style_data || {}
  const t = item.type
  if (t === 'shape' || t === 'pen_shape') return sd.shape_opacity ?? 100
  if (t === 'frame') return sd.frame_opacity ?? 100
  if (t === 'text') return sd.text_opacity ?? 100
  if (t === 'image') return sd.image_opacity ?? 100
  // Generic fallback for notes, links, etc.
  return sd.opacity ?? 100
})

function setAppearanceOpacity(val) {
  const item = singleItem.value
  if (!item) return
  const t = item.type
  if (t === 'shape' || t === 'pen_shape') updateItemStyleData({ shape_opacity: val })
  else if (t === 'frame') updateItemStyleData({ frame_opacity: val })
  else if (t === 'text') updateItemStyleData({ text_opacity: val })
  else if (t === 'image') updateItemStyleData({ image_opacity: val })
  else updateItemStyleData({ opacity: val })
}

// ── Scale (proportional, persistent) ──
// Original dimensions are stored in style_data.original_width / original_height
// so the scale % survives deselect/reselect.

function getOriginalWidth(item) {
  if (!item) return 100
  // 1. Explicit snapshot saved on first scale
  if (item.style_data?.original_width) return item.style_data.original_width
  // 2. For drawings: read from content JSON (original canvas size)
  if (item.type === 'drawing' && item.content) {
    try {
      const d = typeof item.content === 'string' ? JSON.parse(item.content) : item.content
      const cw = parseInt(d.width)
      if (cw) return cw
    } catch {}
  }
  return item.width || 100
}
function getOriginalHeight(item) {
  if (!item) return 100
  if (item.style_data?.original_height) return item.style_data.original_height
  if (item.type === 'drawing' && item.content) {
    try {
      const d = typeof item.content === 'string' ? JSON.parse(item.content) : item.content
      const ch = parseInt(d.height)
      if (ch) return ch
    } catch {}
  }
  return item.height || 100
}

const scalePercent = computed(() => {
  if (!singleItem.value) return 100
  const origW = getOriginalWidth(singleItem.value)
  const currentW = singleItem.value.width || origW
  return Math.round((currentW / origW) * 100)
})

function applyScale(pct) {
  if (!singleItem.value) return
  const clamped = Math.max(10, Math.min(500, pct))
  const origW = getOriginalWidth(singleItem.value)
  const origH = getOriginalHeight(singleItem.value)

  const factor = clamped / 100
  const newW = Math.round(origW * factor)
  const newH = Math.round(origH * factor)

  // Persist original dimensions on first scale so they survive reselect
  const sd = singleItem.value.style_data || {}
  if (!sd.original_width || !sd.original_height) {
    store.updateItem(singleItem.value.id, {
      width: newW,
      height: newH,
      style_data: { ...sd, original_width: origW, original_height: origH }
    })
  } else {
    updateSingleItem({ width: newW, height: newH })
  }
}

function resetScale() {
  if (!singleItem.value) return
  const origW = getOriginalWidth(singleItem.value)
  const origH = getOriginalHeight(singleItem.value)
  updateSingleItem({ width: origW, height: origH })
}

// ── Font size ──

const itemFontSize = computed(() => {
  if (!singleItem.value) return 14
  return singleItem.value.style_data?.font_size || 14
})

function adjustFontSize(delta) {
  const newSize = Math.max(1, Math.min(500, itemFontSize.value + delta))
  updateItemStyleData({ font_size: newSize })
}

function setItemFontSize(size) {
  const clamped = Math.max(1, Math.min(500, size))
  updateItemStyleData({ font_size: clamped })
}

function onInsertLorem(text) {
  if (!singleItem.value) return
  store.updateItem(singleItem.value.id, { content: text })
}

// ── Figma-style Typography (sidebar) ──

const SIDEBAR_FONT_GROUPS = SHARED_FONT_GROUPS

const typographyWeights = [
  { value: '100', label: 'Thin' },
  { value: '200', label: 'Extra Light' },
  { value: '300', label: 'Light' },
  { value: '400', label: 'Regular' },
  { value: '500', label: 'Medium' },
  { value: '600', label: 'Semi Bold' },
  { value: '700', label: 'Bold' },
  { value: '800', label: 'Extra Bold' },
  { value: '900', label: 'Black' },
]

const textAlignOptions = [
  { value: 'left', icon: 'format_align_left', label: 'Align Left' },
  { value: 'center', icon: 'format_align_center', label: 'Align Center' },
  { value: 'right', icon: 'format_align_right', label: 'Align Right' },
]

const loremMenuOptions = [
  { label: '1 Sentence', type: 'sentence', icon: 'short_text' },
  { label: '1 Paragraph', type: 'paragraph', icon: 'notes' },
  { label: '3 Paragraphs', type: 'paragraphs', icon: 'article' },
  { label: 'Heading Text', type: 'heading', icon: 'title' },
]

// Font dropdown
const showSidebarFontDropdown = ref(false)
const sidebarFontSearch = ref('')
const sidebarFontDropdownRef = ref(null)
const sidebarFontSearchInput = ref(null)

const textFontFamily = computed(() => singleItem.value?.style_data?.font_family || 'Inter')
const textFontLabel = computed(() => getFontLabel(textFontFamily.value))

const sidebarFilteredFontGroups = computed(() => filterFontGroups(SIDEBAR_FONT_GROUPS, sidebarFontSearch.value))

function onSelectTextStyle(styleId, isShapeText = false) {
  if (!singleItem.value) return
  if (!styleId) {
    const key = isShapeText ? 'shape_text_style' : 'text_style'
    gsStore.unlinkGlobal(singleItem.value.id, key)
    return
  }
  gsStore.applyTextStyleToItems(styleId, [singleItem.value.id])
}

function saveTextStyleToGlobals(isShape = false, name = '') {
  const item = singleItem.value
  if (!item) return
  const sd = item.style_data || {}
  const style = {
    id: `ts-${Date.now()}-${Math.random().toString(36).slice(2, 6)}`,
    name: name || `Style from ${item.type}`,
    font_family: isShape ? (sd.shape_font_family ?? 'Inter') : (sd.font_family ?? 'Inter'),
    font_weight: isShape ? (sd.shape_font_weight ?? '400') : (sd.font_weight ?? '400'),
    font_size: isShape ? (sd.shape_font_size ?? 16) : (sd.font_size ?? 16),
    line_height: isShape ? (sd.shape_line_height ?? 1) : (sd.line_height ?? 1),
    letter_spacing: isShape ? (sd.shape_letter_spacing ?? 0) : (sd.letter_spacing ?? 0),
    text_transform: isShape ? (sd.shape_text_transform ?? 'none') : (sd.text_transform ?? 'none'),
    text_color: isShape ? (sd.shape_text_color ?? '#000000') : (sd.text_color ?? '#000000'),
  }
  gsStore.addGlobalTextStyle(style)
}

function saveColorToGlobals(name = '') {
  const item = singleItem.value
  if (!item) return
  const sd = item.style_data || {}
  const hex = sd.shape_fill || sd.text_color || sd.fill_color || sd.line_color || item.color
  if (!hex) return
  gsStore.addGlobalColor({
    id: `gc-${Date.now()}-${Math.random().toString(36).slice(2, 6)}`,
    name: name || hex.toUpperCase(),
    value: hex,
  })
}

function saveGradientToGlobals(name = '') {
  const grad = currentFillGradient.value
  if (!grad?.stops?.length) return
  gsStore.addGlobalGradient({
    id: `gg-${Date.now()}-${Math.random().toString(36).slice(2, 6)}`,
    name: name || `Gradient ${gsStore.globalGradients.length + 1}`,
    type: currentFillType.value === 'radial' ? 'radial' : 'linear',
    angle: grad.angle ?? 135,
    stops: JSON.parse(JSON.stringify(grad.stops)),
  })
}

function setSidebarFont(fontValue) {
  loadGoogleFont(fontValue)
  updateItemStyleData({ font_family: fontValue })
  showSidebarFontDropdown.value = false
  sidebarFontSearch.value = ''
}

let sidebarFontsPreloaded = false
watch(showSidebarFontDropdown, (open) => {
  if (open) {
    setTimeout(() => sidebarFontSearchInput.value?.focus(), 50)
    if (!sidebarFontsPreloaded) {
      sidebarFontsPreloaded = true
      preloadFontList(ALL_FONT_VALUES)
    }
  }
})

// Weight dropdown
const showSidebarWeightDropdown = ref(false)
const sidebarWeightDropdownRef = ref(null)

const textFontWeight = computed(() => singleItem.value?.style_data?.font_weight || '400')
const textFontWeightLabel = computed(() => {
  const w = typographyWeights.find(fw => fw.value === textFontWeight.value)
  return w ? w.label : 'Regular'
})

function setSidebarWeight(value) {
  updateItemStyleData({ font_weight: value })
  showSidebarWeightDropdown.value = false
}

// Text transform options (individual buttons instead of cycling toggle)
const textTransformOptions = [
  { value: 'uppercase',  icon: 'title',       label: 'Uppercase' },
  { value: 'lowercase',  icon: 'text_fields',  label: 'Lowercase' },
  { value: 'capitalize', icon: 'match_case',   label: 'Capitalize' },
]

// ── Multi-selection text editing ──────────────────────────────────────

const multiTextItems = computed(() => {
  if (!multipleSelected.value) return []
  return store.selectedItems.filter(i => i.type === 'text')
})

const showMultiTextFontDropdown = ref(false)
const multiTextFontDropdownRef = ref(null)
const multiTextFontSearch = ref('')
const showMultiTextWeightDropdown = ref(false)
const multiTextWeightDropdownRef = ref(null)

const multiTextFilteredFontGroups = computed(() => filterFontGroups(SIDEBAR_FONT_GROUPS, multiTextFontSearch.value))

function sharedTextProp(key, fallback) {
  const items = multiTextItems.value
  if (!items.length) return fallback
  const first = items[0].style_data?.[key] ?? fallback
  for (let i = 1; i < items.length; i++) {
    if ((items[i].style_data?.[key] ?? fallback) !== first) return '__mixed__'
  }
  return first
}

const multiTextFont = computed(() => sharedTextProp('font_family', 'Inter'))
const multiTextWeight = computed(() => sharedTextProp('font_weight', '400'))
const multiTextFontSize = computed(() => {
  const items = multiTextItems.value
  if (!items.length) return 14
  const first = items[0].style_data?.font_size || 14
  for (let i = 1; i < items.length; i++) {
    if ((items[i].style_data?.font_size || 14) !== first) return first
  }
  return first
})
const multiTextColor = computed(() => sharedTextProp('text_color', '#ffffff'))
const multiTextAlign = computed(() => sharedTextProp('text_align', 'left'))
const multiTextTransform = computed(() => sharedTextProp('text_transform', 'none'))
const multiTextLineHeight = computed(() => {
  const items = multiTextItems.value
  if (!items.length) return 1
  const first = items[0].style_data?.line_height ?? 1
  for (let i = 1; i < items.length; i++) {
    if ((items[i].style_data?.line_height ?? 1) !== first) return first
  }
  return first
})
const multiTextLetterSpacing = computed(() => {
  const items = multiTextItems.value
  if (!items.length) return 0
  const first = items[0].style_data?.letter_spacing ?? 0
  for (let i = 1; i < items.length; i++) {
    if ((items[i].style_data?.letter_spacing ?? 0) !== first) return first
  }
  return first
})
const multiTextWeightLabel = computed(() => {
  const w = multiTextWeight.value
  if (w === '__mixed__') return 'Mixed'
  const found = typographyWeights.find(fw => fw.value === w)
  return found ? found.label : 'Regular'
})

function applyToMultiText(styleUpdates) {
  const updates = multiTextItems.value.map(item => ({
    id: item.id,
    style_data: { ...(item.style_data || {}), ...styleUpdates },
  }))
  store.batchUpdateItems(updates)
}

function setMultiTextFont(fontValue) {
  loadGoogleFont(fontValue)
  applyToMultiText({ font_family: fontValue })
  showMultiTextFontDropdown.value = false
  multiTextFontSearch.value = ''
}

function setMultiTextWeight(value) {
  applyToMultiText({ font_weight: value })
  showMultiTextWeightDropdown.value = false
}

function setMultiTextSize(size) {
  applyToMultiText({ font_size: size })
}

function setMultiTextColor(color) {
  applyToMultiText({ text_color: color })
}

function setMultiTextAlign(value) {
  applyToMultiText({ text_align: value })
}

function setMultiTextTransform(value) {
  applyToMultiText({ text_transform: value })
}

// ── Multi-selection shape editing ──────────────────────────────────────

const multiShapeItems = computed(() => {
  if (!multipleSelected.value) return []
  return store.selectedItems.filter(i => i.type === 'shape' || i.type === 'pen_shape')
})

function sharedShapeProp(key, fallback) {
  const items = multiShapeItems.value
  if (!items.length) return fallback
  const first = items[0].style_data?.[key] ?? fallback
  for (let i = 1; i < items.length; i++) {
    if ((items[i].style_data?.[key] ?? fallback) !== first) return '__mixed__'
  }
  return first
}

const multiShapeFill = computed(() => sharedShapeProp('shape_fill', '#6366f1'))
const multiShapeBorderColor = computed(() => sharedShapeProp('shape_border_color', '#000000'))
const multiShapeBorderWidth = computed(() => {
  const items = multiShapeItems.value
  if (!items.length) return 0
  const first = items[0].style_data?.shape_border_width ?? 0
  for (let i = 1; i < items.length; i++) {
    if ((items[i].style_data?.shape_border_width ?? 0) !== first) return first
  }
  return first
})
const multiShapeRadius = computed(() => {
  const items = multiShapeItems.value
  if (!items.length) return 0
  const first = items[0].style_data?.shape_border_radius ?? 0
  for (let i = 1; i < items.length; i++) {
    if ((items[i].style_data?.shape_border_radius ?? 0) !== first) return first
  }
  return first
})

function applyToMultiShape(styleUpdates) {
  const updates = multiShapeItems.value.map(item => ({
    id: item.id,
    style_data: { ...(item.style_data || {}), ...styleUpdates },
  }))
  store.batchUpdateItems(updates)
}

function applyToMultiShapeRadius(val) {
  const updates = multiShapeItems.value.map(item => ({
    id: item.id,
    style_data: {
      ...(item.style_data || {}),
      shape_border_radius: val,
      shape_border_radius_tl: val,
      shape_border_radius_tr: val,
      shape_border_radius_bl: val,
      shape_border_radius_br: val,
    },
  }))
  store.batchUpdateItems(updates)
}

// Lorem ipsum
const showSidebarLorem = ref(false)
const sidebarLoremRef = ref(null)

const LOREM_SENTENCES = [
  'Lorem ipsum dolor sit amet, consectetur adipiscing elit.',
  'Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.',
  'Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris.',
  'Duis aute irure dolor in reprehenderit in voluptate velit esse cillum.',
  'Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia.',
]
const LOREM_HEADINGS = [
  'The Art of Visual Design',
  'Crafting Meaningful Experiences',
  'Beyond the Ordinary',
  'Where Ideas Take Shape',
]

function insertLoremText(type) {
  if (!singleItem.value) return
  let text
  switch (type) {
    case 'sentence':
      text = LOREM_SENTENCES[Math.floor(Math.random() * LOREM_SENTENCES.length)]
      break
    case 'paragraph':
      text = [...LOREM_SENTENCES].sort(() => Math.random() - 0.5).slice(0, 4).join(' ')
      break
    case 'paragraphs':
      text = [
        [...LOREM_SENTENCES].sort(() => Math.random() - 0.5).slice(0, 4).join(' '),
        [...LOREM_SENTENCES].sort(() => Math.random() - 0.5).slice(0, 3).join(' '),
        [...LOREM_SENTENCES].sort(() => Math.random() - 0.5).slice(0, 4).join(' '),
      ].join('\n\n')
      break
    case 'heading':
      text = LOREM_HEADINGS[Math.floor(Math.random() * LOREM_HEADINGS.length)]
      break
    default:
      text = LOREM_SENTENCES[0]
  }
  store.updateItem(singleItem.value.id, { content: text })
  showSidebarLorem.value = false
}

// ── Shape Text Typography ──
const showShapeFontDropdown = ref(false)
const shapeFontSearch = ref('')
const shapeFontDropdownRef = ref(null)
const shapeFontSearchInput = ref(null)

const shapeFontFamily = computed(() => singleItem.value?.style_data?.shape_font_family || 'Inter')
const shapeFontLabel = computed(() => getFontLabel(shapeFontFamily.value))

const shapeFilteredFontGroups = computed(() => filterFontGroups(SIDEBAR_FONT_GROUPS, shapeFontSearch.value))

function setShapeFont(fontValue) {
  loadGoogleFont(fontValue)
  updateItemStyleData({ shape_font_family: fontValue })
  showShapeFontDropdown.value = false
  shapeFontSearch.value = ''
}

watch(showShapeFontDropdown, (open) => {
  if (open) setTimeout(() => shapeFontSearchInput.value?.focus(), 50)
})

const showShapeWeightDropdown = ref(false)
const shapeWeightDropdownRef = ref(null)

const shapeFontWeight = computed(() => singleItem.value?.style_data?.shape_font_weight || '600')
const shapeFontWeightLabel = computed(() => {
  const w = typographyWeights.find(fw => fw.value === shapeFontWeight.value)
  return w ? w.label : 'Semi Bold'
})

function setShapeWeight(value) {
  updateItemStyleData({ shape_font_weight: value })
  showShapeWeightDropdown.value = false
}

// Shape lorem insertion
const showShapeLorem = ref(false)
const shapeLoremRef = ref(null)

function insertShapeLoremText(type) {
  if (!singleItem.value) return
  let text
  switch (type) {
    case 'sentence': text = LOREM_SENTENCES[Math.floor(Math.random() * LOREM_SENTENCES.length)]; break
    case 'paragraph': text = [...LOREM_SENTENCES].sort(() => Math.random() - 0.5).slice(0, 4).join(' '); break
    case 'paragraphs': text = [
      [...LOREM_SENTENCES].sort(() => Math.random() - 0.5).slice(0, 4).join(' '),
      [...LOREM_SENTENCES].sort(() => Math.random() - 0.5).slice(0, 3).join(' '),
      [...LOREM_SENTENCES].sort(() => Math.random() - 0.5).slice(0, 4).join(' '),
    ].join('\n\n'); break
    case 'heading': text = LOREM_HEADINGS[Math.floor(Math.random() * LOREM_HEADINGS.length)]; break
    default: text = LOREM_SENTENCES[0]
  }
  store.updateItem(singleItem.value.id, { content: text })
  showShapeLorem.value = false
}

// Close sidebar dropdowns on outside click
function onSidebarDocClick(e) {
  if (sidebarFontDropdownRef.value && !sidebarFontDropdownRef.value.contains(e.target)) {
    showSidebarFontDropdown.value = false
  }
  if (sidebarWeightDropdownRef.value && !sidebarWeightDropdownRef.value.contains(e.target)) {
    showSidebarWeightDropdown.value = false
  }
  if (sidebarLoremRef.value && !sidebarLoremRef.value.contains(e.target)) {
    showSidebarLorem.value = false
  }
  if (shapeFontDropdownRef.value && !shapeFontDropdownRef.value.contains(e.target)) {
    showShapeFontDropdown.value = false
  }
  if (shapeWeightDropdownRef.value && !shapeWeightDropdownRef.value.contains(e.target)) {
    showShapeWeightDropdown.value = false
  }
  if (shapeLoremRef.value && !shapeLoremRef.value.contains(e.target)) {
    showShapeLorem.value = false
  }
  if (multiTextFontDropdownRef.value && !multiTextFontDropdownRef.value.contains(e.target)) {
    showMultiTextFontDropdown.value = false
  }
  if (multiTextWeightDropdownRef.value && !multiTextWeightDropdownRef.value.contains(e.target)) {
    showMultiTextWeightDropdown.value = false
  }
}

onMounted(() => document.addEventListener('mousedown', onSidebarDocClick))
onBeforeUnmount(() => document.removeEventListener('mousedown', onSidebarDocClick))

// ── Shape corners ──

const shapeCornersLinked = ref(true)

const shapeCorners = computed(() => {
  const sd = singleItem.value?.style_data || {}
  return {
    tl: sd.shape_border_radius_tl ?? sd.shape_border_radius ?? 0,
    tr: sd.shape_border_radius_tr ?? sd.shape_border_radius ?? 0,
    bl: sd.shape_border_radius_bl ?? sd.shape_border_radius ?? 0,
    br: sd.shape_border_radius_br ?? sd.shape_border_radius ?? 0,
  }
})

const shapeRadiusAll = computed(() => {
  return singleItem.value?.style_data?.shape_border_radius ?? 0
})

function setAllCornerRadius(val) {
  updateItemStyleData({
    shape_border_radius: val,
    shape_border_radius_tl: val,
    shape_border_radius_tr: val,
    shape_border_radius_bl: val,
    shape_border_radius_br: val,
  })
}

function setCornerRadius(corner, val) {
  updateItemStyleData({ [`shape_border_radius_${corner}`]: val })
}

// ── Shape mask ──

function cycleShapeMaskFit() {
  const fits = ['cover', 'contain', 'fill']
  const current = singleItem.value?.style_data?.mask_image_fit || 'cover'
  const idx = fits.indexOf(current)
  updateItemStyleData({ mask_image_fit: fits[(idx + 1) % fits.length] })
}

function removeMaskImage() {
  if (!singleItem.value) return
  const sd = { ...(singleItem.value.style_data || {}) }
  delete sd.mask_image_url
  delete sd.mask_image_fit
  store.updateItem(singleItem.value.id, { style_data: sd })
}

async function onAddMaskImage(e) {
  const file = e.target?.files?.[0]
  if (!file || !file.type.startsWith('image/')) return
  try {
    const uploaded = await store.uploadFiles([file])
    if (uploaded[0]) {
      updateItemStyleData({
        mask_image_url: uploaded[0].url,
        mask_image_fit: 'cover'
      })
    }
  } catch (err) {
    console.error('Shape mask upload failed:', err)
  }
}

// ── Image replace / remove ──

async function onReplaceImage(e) {
  const file = e.target?.files?.[0]
  if (!file || !file.type.startsWith('image/') || !singleItem.value) return
  try {
    const uploaded = await store.uploadFiles([file])
    if (uploaded[0]) {
      updateSingleItem({
        image_url: uploaded[0].url,
        title: file.name
      })
    }
  } catch (err) {
    console.error('Image replace failed:', err)
  }
}

function onRemoveImage() {
  if (!singleItem.value) return
  updateSingleItem({ image_url: null })
}

// ── Drawing recolor ──

function recolorDrawing(newColor) {
  if (!singleItem.value || singleItem.value.type !== 'drawing') return
  try {
    const data = typeof singleItem.value.content === 'string'
      ? JSON.parse(singleItem.value.content)
      : singleItem.value.content
    if (data?.strokes) {
      for (const stroke of data.strokes) {
        stroke.color = newColor
      }
      store.updateItem(singleItem.value.id, {
        content: JSON.stringify(data),
        color: newColor
      })
    }
  } catch (e) {
    console.error('Failed to recolor drawing:', e)
  }
}

// ── Drawing export ──

const pngExportScale = ref(2)

function getDrawingSvgString(item) {
  if (!item || item.type !== 'drawing') return null
  let data = item.content
  if (typeof data === 'string') {
    try { data = JSON.parse(data) } catch { return null }
  }
  if (!data?.strokes?.length) return null

  const w = parseInt(data.width) || item.width || 200
  const h = parseInt(data.height) || item.height || 150

  const paths = data.strokes.map(stroke => {
    return `<path d="${stroke.svgPath}" fill="${stroke.color || '#000000'}" stroke-linecap="round" stroke-linejoin="round"/>`
  }).join('\n    ')

  return `<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${w} ${h}" width="${w}" height="${h}">
    ${paths}
</svg>`
}

function downloadBlob(blob, filename) {
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = filename
  document.body.appendChild(a)
  a.click()
  document.body.removeChild(a)
  setTimeout(() => URL.revokeObjectURL(url), 5000)
}

function exportDrawing(format) {
  if (!singleItem.value || singleItem.value.type !== 'drawing') return

  const name = (singleItem.value.title || 'drawing').replace(/[^a-zA-Z0-9_-]/g, '_')

  if (format === 'svg') {
    const svgStr = getDrawingSvgString(singleItem.value)
    if (!svgStr) return
    const blob = new Blob([svgStr], { type: 'image/svg+xml;charset=utf-8' })
    downloadBlob(blob, `${name}.svg`)
  }

  else if (format === 'png') {
    const svgStr = getDrawingSvgString(singleItem.value)
    if (!svgStr) return

    const data = typeof singleItem.value.content === 'string'
      ? JSON.parse(singleItem.value.content) : singleItem.value.content
    const w = parseInt(data.width) || singleItem.value.width || 200
    const h = parseInt(data.height) || singleItem.value.height || 150
    const scale = pngExportScale.value || 2

    const img = new Image()
    const svgBlob = new Blob([svgStr], { type: 'image/svg+xml;charset=utf-8' })
    const url = URL.createObjectURL(svgBlob)

    img.onload = () => {
      const canvas = document.createElement('canvas')
      canvas.width = w * scale
      canvas.height = h * scale
      const ctx = canvas.getContext('2d')
      ctx.scale(scale, scale)
      ctx.drawImage(img, 0, 0, w, h)
      canvas.toBlob(blob => {
        if (blob) downloadBlob(blob, `${name}@${scale}x.png`)
        URL.revokeObjectURL(url)
      }, 'image/png')
    }
    img.onerror = () => URL.revokeObjectURL(url)
    img.src = url
  }

  else if (format === 'eps') {
    // Generate a simplified EPS from the SVG paths
    const data = typeof singleItem.value.content === 'string'
      ? JSON.parse(singleItem.value.content) : singleItem.value.content
    if (!data?.strokes?.length) return

    const w = parseInt(data.width) || singleItem.value.width || 200
    const h = parseInt(data.height) || singleItem.value.height || 150

    // Convert hex color to PS RGB (0-1 range)
    function hexToPs(hex) {
      hex = (hex || '#000000').replace('#', '')
      const r = parseInt(hex.substr(0, 2), 16) / 255
      const g = parseInt(hex.substr(2, 2), 16) / 255
      const b = parseInt(hex.substr(4, 2), 16) / 255
      return `${r.toFixed(4)} ${g.toFixed(4)} ${b.toFixed(4)}`
    }

    // Parse SVG path d-string to PostScript moveto/lineto/curveto
    function svgPathToPs(d, pageH) {
      const ops = []
      const cmds = d.match(/[MLCQZHVSAZ][^MLCQZHVSAZ]*/gi) || []
      let cx = 0, cy = 0
      for (const seg of cmds) {
        const type = seg[0]
        const nums = seg.slice(1).trim().split(/[\s,]+/).map(Number).filter(n => !isNaN(n))
        switch (type) {
          case 'M':
            cx = nums[0]; cy = nums[1]
            ops.push(`${cx.toFixed(2)} ${(pageH - cy).toFixed(2)} moveto`)
            break
          case 'L':
            cx = nums[0]; cy = nums[1]
            ops.push(`${cx.toFixed(2)} ${(pageH - cy).toFixed(2)} lineto`)
            break
          case 'C':
            for (let i = 0; i + 5 < nums.length; i += 6) {
              ops.push(`${nums[i].toFixed(2)} ${(pageH - nums[i+1]).toFixed(2)} ${nums[i+2].toFixed(2)} ${(pageH - nums[i+3]).toFixed(2)} ${nums[i+4].toFixed(2)} ${(pageH - nums[i+5]).toFixed(2)} curveto`)
              cx = nums[i+4]; cy = nums[i+5]
            }
            break
          case 'Q':
            // Convert quadratic to cubic
            for (let i = 0; i + 3 < nums.length; i += 4) {
              const qx = nums[i], qy = nums[i+1], ex = nums[i+2], ey = nums[i+3]
              const c1x = cx + 2/3 * (qx - cx), c1y = cy + 2/3 * (qy - cy)
              const c2x = ex + 2/3 * (qx - ex), c2y = ey + 2/3 * (qy - ey)
              ops.push(`${c1x.toFixed(2)} ${(pageH - c1y).toFixed(2)} ${c2x.toFixed(2)} ${(pageH - c2y).toFixed(2)} ${ex.toFixed(2)} ${(pageH - ey).toFixed(2)} curveto`)
              cx = ex; cy = ey
            }
            break
          case 'Z': case 'z':
            ops.push('closepath')
            break
        }
      }
      return ops.join('\n')
    }

    const strokesPs = data.strokes.map(stroke => {
      const psPath = svgPathToPs(stroke.svgPath || '', h)
      const rgb = hexToPs(stroke.color)
      return `${rgb} setrgbcolor\nnewpath\n${psPath}\nfill`
    }).join('\n')

    const eps = `%!PS-Adobe-3.0 EPSF-3.0
%%BoundingBox: 0 0 ${w} ${h}
%%Title: ${name}
%%Creator: MoodBoard Export
%%Pages: 1
%%EndComments

${strokesPs}

showpage
%%EOF`

    const blob = new Blob([eps], { type: 'application/postscript' })
    downloadBlob(blob, `${name}.eps`)
  }
}

// ── File helpers ──

const isCollabEditable = computed(() => {
  if (!singleItem.value?.drive_file_id) return false
  const name = (singleItem.value.title || '').toLowerCase()
  return name.endsWith('.docx') || name.endsWith('.pptx')
})

function getFileIcon(item) {
  const name = (item.title || '').toLowerCase()
  if (name.endsWith('.pdf')) return 'picture_as_pdf'
  if (/\.(doc|docx|odt|txt)$/i.test(name)) return 'description'
  if (/\.(xls|xlsx|csv|ods)$/i.test(name)) return 'table_chart'
  if (/\.(ppt|pptx|odp)$/i.test(name)) return 'slideshow'
  if (/\.(jpg|jpeg|png|gif|webp|svg|bmp|avif)$/i.test(name)) return 'image'
  if (/\.(mp4|webm|ogg|mov|avi)$/i.test(name)) return 'videocam'
  if (/\.(zip|rar|7z|tar|gz)$/i.test(name)) return 'folder_zip'
  return 'insert_drive_file'
}

function getFileIconColor(item) {
  const name = (item.title || '').toLowerCase()
  if (name.endsWith('.pdf')) return '#ef4444'
  if (/\.(doc|docx)$/i.test(name)) return '#3b82f6'
  if (/\.(xls|xlsx|csv)$/i.test(name)) return '#22c55e'
  if (/\.(ppt|pptx)$/i.test(name)) return '#f97316'
  if (/\.(jpg|jpeg|png|gif|webp|svg)$/i.test(name)) return '#8b5cf6'
  return '#6b7280'
}

function getFileExtension(item) {
  const name = (item.title || '').toLowerCase()
  const parts = name.split('.')
  return parts.length > 1 ? parts.pop() : 'file'
}

// ── YouTube helpers ──

const ytThumbError = ref(false)

const youtubeEmbedId = computed(() => {
  if (!singleItem.value || singleItem.value.type !== 'youtube') return null
  const url = singleItem.value.url || ''
  if (!url) return null
  let m = url.match(/[?&]v=([a-zA-Z0-9_-]{11})/)
  if (m) return m[1]
  m = url.match(/youtu\.be\/([a-zA-Z0-9_-]{11})/)
  if (m) return m[1]
  m = url.match(/youtube\.com\/embed\/([a-zA-Z0-9_-]{11})/)
  if (m) return m[1]
  m = url.match(/youtube\.com\/shorts\/([a-zA-Z0-9_-]{11})/)
  if (m) return m[1]
  return null
})

watch(youtubeEmbedId, () => { ytThumbError.value = false })

// ── Color swatch helpers ──


function hexToRgb(hex) {
  hex = (hex || '#000000').replace('#', '')
  if (hex.length === 3) hex = hex[0] + hex[0] + hex[1] + hex[1] + hex[2] + hex[2]
  return {
    r: parseInt(hex.substring(0, 2), 16) || 0,
    g: parseInt(hex.substring(2, 4), 16) || 0,
    b: parseInt(hex.substring(4, 6), 16) || 0,
  }
}

function rgbToHex(r, g, b) {
  return '#' + [r, g, b].map(c => Math.max(0, Math.min(255, c)).toString(16).padStart(2, '0')).join('')
}

function rgbToCmyk(r, g, b) {
  const rr = r / 255, gg = g / 255, bb = b / 255
  const k = 1 - Math.max(rr, gg, bb)
  if (k >= 1) return { c: 0, m: 0, y: 0, k: 100 }
  return {
    c: Math.round(((1 - rr - k) / (1 - k)) * 100),
    m: Math.round(((1 - gg - k) / (1 - k)) * 100),
    y: Math.round(((1 - bb - k) / (1 - k)) * 100),
    k: Math.round(k * 100),
  }
}

const swatchRgb = computed(() => {
  if (!singleItem.value || singleItem.value.type !== 'color_swatch') return { r: 0, g: 0, b: 0 }
  if (singleItem.value.color_data?.rgb) return singleItem.value.color_data.rgb
  return hexToRgb(singleItem.value.color)
})

const swatchCmyk = computed(() => {
  if (!singleItem.value || singleItem.value.type !== 'color_swatch') return { c: 0, m: 0, y: 0, k: 0 }
  if (singleItem.value.color_data?.cmyk) return singleItem.value.color_data.cmyk
  const { r, g, b } = swatchRgb.value
  return rgbToCmyk(r, g, b)
})

function buildColorData(hex) {
  const rgb = hexToRgb(hex)
  const cmyk = rgbToCmyk(rgb.r, rgb.g, rgb.b)
  return { hex, rgb, cmyk }
}

function applySwatchColor(hex) {
  if (!singleItem.value) return
  let val = (hex || '').trim()
  if (!val.startsWith('#')) val = '#' + val
  if (!/^#[0-9a-fA-F]{3,6}$/.test(val)) return
  // Expand shorthand
  if (val.length === 4) val = '#' + val[1] + val[1] + val[2] + val[2] + val[3] + val[3]
  store.updateItem(singleItem.value.id, {
    color: val,
    color_data: buildColorData(val),
  })
}

function onSwatchRgbChange(channel, value) {
  if (!singleItem.value) return
  value = Math.max(0, Math.min(255, value))
  const current = { ...swatchRgb.value }
  current[channel] = value
  const hex = rgbToHex(current.r, current.g, current.b)
  store.updateItem(singleItem.value.id, {
    color: hex,
    color_data: buildColorData(hex),
  })
}

// ── Board Background Image ──────────────────────────────────────
const bgImageInput = ref(null)
const bgImageUploading = ref(false)

const bgImageSizeOptions = [
  { label: 'Cover', value: 'cover' },
  { label: 'Contain', value: 'contain' },
  { label: 'Tile', value: 'repeat' },
]

async function handleBgImageUpload(e) {
  const file = e.target?.files?.[0]
  if (!file) return
  if (file.size > 10 * 1024 * 1024) {
    alert('Image must be less than 10 MB')
    return
  }
  bgImageUploading.value = true
  try {
    const formData = new FormData()
    formData.append('file', file)
    formData.append('folder_name', 'Board Backgrounds')
    const response = await api.post('/drive/upload', formData, {
      headers: { 'Content-Type': 'multipart/form-data' }
    })
    if (response.data.success) {
      const fileId = response.data.data.file.id
      const shareResponse = await api.post(`/drive/files/${fileId}/share`)
      if (shareResponse.data.success) {
        emit('update-board-field', 'background_image', shareResponse.data.data.url)
      }
    }
  } catch (err) {
    console.error('Background image upload failed:', err)
  } finally {
    bgImageUploading.value = false
    if (e.target) e.target.value = ''
  }
}

function bgImageUrlPrompt() {
  const url = prompt('Enter image URL:')
  if (url && url.trim()) {
    emit('update-board-field', 'background_image', url.trim())
  }
}

// ── Board Background Audio ──────────────────────────────────────
function bgAudioParsed() {
  const raw = props.board?.bg_audio
  if (!raw) return null
  try { return typeof raw === 'string' ? JSON.parse(raw) : raw } catch { return null }
}

const bgAudioType = ref(bgAudioParsed()?.type || 'youtube')
const bgAudioUrl = ref(bgAudioParsed()?.url || '')
const bgAudioVolume = ref(bgAudioParsed()?.volume ?? 30)
const bgAudioLoop = ref(bgAudioParsed()?.loop !== false)
const bgAudioLoopStart = ref(bgAudioParsed()?.loop_start || '')  // mm:ss format
const bgAudioLoopEnd = ref(bgAudioParsed()?.loop_end || '')      // mm:ss format
const bgAudioPreviewing = ref(false)

const bgAudioCurrentUrl = computed(() => bgAudioParsed()?.url || '')
const bgAudioCurrentType = computed(() => bgAudioParsed()?.type || 'youtube')

function bgAudioSave() {
  if (!bgAudioUrl.value) return
  const payload = {
    type: bgAudioType.value,
    url: bgAudioUrl.value.trim(),
    volume: bgAudioVolume.value,
    loop: bgAudioLoop.value,
    loop_start: bgAudioLoopStart.value || null,
    loop_end: bgAudioLoopEnd.value || null,
  }
  emit('update-board-field', 'bg_audio', JSON.stringify(payload))
}

function bgAudioRemove() {
  bgAudioUrl.value = ''
  bgAudioVolume.value = 30
  bgAudioLoop.value = true
  bgAudioLoopStart.value = ''
  bgAudioLoopEnd.value = ''
  bgAudioPreviewing.value = false
  emit('update-board-field', 'bg_audio', null)
}

// Sync when board bg_audio changes externally
watch(() => props.board?.bg_audio, () => {
  const parsed = bgAudioParsed()
  if (parsed) {
    bgAudioType.value = parsed.type || 'youtube'
    bgAudioUrl.value = parsed.url || ''
    bgAudioVolume.value = parsed.volume ?? 30
    bgAudioLoop.value = parsed.loop !== false
    bgAudioLoopStart.value = parsed.loop_start || ''
    bgAudioLoopEnd.value = parsed.loop_end || ''
  }
}, { deep: true })
</script>

