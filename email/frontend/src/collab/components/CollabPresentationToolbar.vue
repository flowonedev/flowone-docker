<template>
  <div class="toolbar-container">
    <div class="toolbar-content">
      <!-- File Actions -->
      <div class="toolbar-section">
        <div class="section-label">File</div>
        <div class="section-buttons">
          <button @click="$emit('export-pptx')" title="Download as PPTX" class="toolbar-btn accent" :disabled="isExporting">
            <span v-if="isExporting" class="material-symbols-rounded spin">progress_activity</span>
            <span v-else class="material-symbols-rounded">download</span>
          </button>
          <button @click="handlePrint" title="Print" class="toolbar-btn">
            <span class="material-symbols-rounded">print</span>
          </button>
        </div>
      </div>

      <div class="toolbar-divider"></div>

      <!-- History -->
      <div class="toolbar-section">
        <div class="section-label">History</div>
        <div class="section-buttons">
          <button @click="$emit('undo')" :disabled="!canUndo" title="Undo (Ctrl+Z)" class="toolbar-btn" :class="{ 'disabled': !canUndo }">
            <span class="material-symbols-rounded">undo</span>
          </button>
          <button @click="$emit('redo')" :disabled="!canRedo" title="Redo (Ctrl+Y)" class="toolbar-btn" :class="{ 'disabled': !canRedo }">
            <span class="material-symbols-rounded">redo</span>
          </button>
          <button @click="$emit('global-undo')" title="Global Undo (Ctrl+Shift+Z)" class="toolbar-btn">
            <span class="material-symbols-rounded">history</span>
          </button>
        </div>
      </div>

      <div class="toolbar-divider"></div>

      <!-- Insert -->
      <div class="toolbar-section">
        <div class="section-label">Insert</div>
        <div class="section-buttons">
          <button @click="$emit('add-text')" title="Add Text Box" class="toolbar-btn">
            <span class="material-symbols-rounded">text_fields</span>
          </button>
          <button @click="showShapeDropdown = !showShapeDropdown" ref="shapeBtnRef" title="Add Shape" class="toolbar-btn" :class="{ 'active': showShapeDropdown }">
            <span class="material-symbols-rounded">shapes</span>
          </button>
          <button @click="$emit('add-image')" title="Add Image" class="toolbar-btn">
            <span class="material-symbols-rounded">image</span>
          </button>
          <button @click="$emit('open-templates')" title="Insert Template" class="toolbar-btn">
            <span class="material-symbols-rounded">dashboard</span>
          </button>
        </div>
      </div>

      <div class="toolbar-divider"></div>

      <!-- Slide -->
      <div class="toolbar-section">
        <div class="section-label">Slide</div>
        <div class="section-buttons">
          <button @click="$emit('new-slide')" title="New Slide" class="toolbar-btn">
            <span class="material-symbols-rounded">add</span>
          </button>
          <button @click="$emit('open-background')" title="Background Settings" class="toolbar-btn">
            <span class="material-symbols-rounded">format_color_fill</span>
          </button>
          <button @click="$emit('save-template')" :disabled="!hasObjects" title="Save as Template" class="toolbar-btn" :class="{ 'disabled': !hasObjects }">
            <span class="material-symbols-rounded">bookmark_add</span>
          </button>
        </div>
      </div>

      <div class="toolbar-divider"></div>

      <!-- Format (when text/object is selected) -->
      <div class="toolbar-section" v-if="selectedObject">
        <div class="section-label">Format</div>
        <div class="section-buttons" v-if="selectedObject.type === 'text'">
          <!-- Font Family -->
          <button @click="openDropdown('fontFamily', $event)" class="dropdown-btn font-family-btn" title="Font Family">
            <span class="btn-text" :style="{ fontFamily: selectedObject.fontFamily || 'Inter' }">{{ currentFontFamily }}</span>
            <span class="material-symbols-rounded dropdown-arrow">expand_more</span>
          </button>
          <!-- Font Weight -->
          <button @click="openDropdown('fontWeight', $event)" class="dropdown-btn font-weight-btn" title="Font Weight">
            <span class="btn-text">{{ currentFontWeight }}</span>
            <span class="material-symbols-rounded dropdown-arrow">expand_more</span>
          </button>
          <!-- Font Size -->
          <button @click="openDropdown('fontSize', $event)" class="dropdown-btn font-size-btn" title="Font Size">
            <span class="btn-text">{{ selectedObject.fontSize || 24 }}</span>
            <span class="material-symbols-rounded dropdown-arrow">expand_more</span>
          </button>
          <button @click="decreaseFontSize" title="Decrease Size (A-)" class="toolbar-btn">
            <span class="material-symbols-rounded">text_decrease</span>
          </button>
          <button @click="increaseFontSize" title="Increase Size (A+)" class="toolbar-btn">
            <span class="material-symbols-rounded">text_increase</span>
          </button>
        </div>
        <div class="section-buttons" v-else-if="selectedObject.type === 'shape'">
          <button @click="openDropdown('fillColor', $event)" title="Fill Color" class="toolbar-btn color-btn">
            <span class="material-symbols-rounded">format_color_fill</span>
            <span class="color-indicator" :style="{ backgroundColor: selectedObject.fill || '#2196F3' }"></span>
          </button>
          <button @click="openDropdown('strokeColor', $event)" title="Border Color" class="toolbar-btn color-btn">
            <span class="material-symbols-rounded">border_color</span>
            <span class="color-indicator" :style="{ backgroundColor: selectedObject.stroke || '#1976D2' }"></span>
          </button>
          <button @click="openDropdown('strokeWidth', $event)" class="dropdown-btn" title="Border Width">
            <span class="material-symbols-rounded" style="font-size: 18px;">line_weight</span>
            <span class="btn-text">{{ selectedObject.strokeWidth || 2 }}px</span>
          </button>
        </div>
        <div class="section-buttons" v-else-if="selectedObject.type === 'image'">
          <button @click="openDropdown('borderRadius', $event)" class="dropdown-btn" title="Corner Radius">
            <span class="material-symbols-rounded" style="font-size: 18px;">rounded_corner</span>
            <span class="btn-text">{{ selectedObject.borderRadius || 0 }}px</span>
          </button>
          <button @click="openDropdown('objectFit', $event)" class="dropdown-btn" title="Fit Mode">
            <span class="material-symbols-rounded" style="font-size: 18px;">aspect_ratio</span>
            <span class="btn-text">{{ selectedObject.objectFit || 'contain' }}</span>
          </button>
        </div>
      </div>

      <div class="toolbar-divider" v-if="selectedObject && selectedObject.type === 'text'"></div>

      <!-- Style (for text) -->
      <div class="toolbar-section" v-if="selectedObject && selectedObject.type === 'text'">
        <div class="section-label">Style</div>
        <div class="section-buttons">
          <button @click="toggleItalic" title="Italic" class="toolbar-btn" :class="{ 'active': isItalic }">
            <span class="material-symbols-rounded">format_italic</span>
          </button>
          <button @click="toggleUnderline" title="Underline" class="toolbar-btn" :class="{ 'active': isUnderline }">
            <span class="material-symbols-rounded">format_underlined</span>
          </button>
          <button @click="toggleStrikethrough" title="Strikethrough" class="toolbar-btn" :class="{ 'active': isStrikethrough }">
            <span class="material-symbols-rounded">strikethrough_s</span>
          </button>
        </div>
      </div>

      <div class="toolbar-divider" v-if="selectedObject && selectedObject.type === 'text'"></div>

      <!-- Case Transform -->
      <div class="toolbar-section" v-if="selectedObject && selectedObject.type === 'text'">
        <div class="section-label">Case</div>
        <div class="section-buttons">
          <button @click="setTextTransform('uppercase')" title="UPPERCASE" class="toolbar-btn text-btn" :class="{ 'active': selectedObject.textTransform === 'uppercase' }">
            <span>AA</span>
          </button>
          <button @click="setTextTransform('lowercase')" title="lowercase" class="toolbar-btn text-btn" :class="{ 'active': selectedObject.textTransform === 'lowercase' }">
            <span>aa</span>
          </button>
          <button @click="setTextTransform('capitalize')" title="Capitalize" class="toolbar-btn text-btn" :class="{ 'active': selectedObject.textTransform === 'capitalize' }">
            <span>Aa</span>
          </button>
        </div>
      </div>

      <div class="toolbar-divider" v-if="selectedObject && selectedObject.type === 'text'"></div>

      <!-- Spacing -->
      <div class="toolbar-section" v-if="selectedObject && selectedObject.type === 'text'">
        <div class="section-label">Spacing</div>
        <div class="section-buttons">
          <button @click="openDropdown('letterSpacing', $event)" class="dropdown-btn spacing-btn" title="Letter Spacing">
            <span class="material-symbols-rounded" style="font-size: 18px;">format_letter_spacing</span>
            <span class="btn-text">{{ selectedObject.letterSpacing || 0 }}px</span>
          </button>
          <button @click="openDropdown('lineHeight', $event)" class="dropdown-btn spacing-btn" title="Line Height">
            <span class="material-symbols-rounded" style="font-size: 18px;">format_line_spacing</span>
            <span class="btn-text">{{ selectedObject.lineHeight || 1.4 }}</span>
          </button>
        </div>
      </div>

      <div class="toolbar-divider" v-if="selectedObject && selectedObject.type === 'text'"></div>

      <!-- Colors (for text) -->
      <div class="toolbar-section" v-if="selectedObject && selectedObject.type === 'text'">
        <div class="section-label">Color</div>
        <div class="section-buttons">
          <button @click="openDropdown('textColor', $event)" title="Text Color" class="toolbar-btn color-btn">
            <span class="text-color-icon">A</span>
            <span class="color-indicator" :style="{ backgroundColor: selectedObject.color || '#000000' }"></span>
          </button>
        </div>
      </div>

      <div class="toolbar-divider" v-if="selectedObject && selectedObject.type === 'text'"></div>

      <!-- Align (for text) -->
      <div class="toolbar-section" v-if="selectedObject && selectedObject.type === 'text'">
        <div class="section-label">Align</div>
        <div class="section-buttons">
          <button @click="setAlign('left')" title="Align Left" class="toolbar-btn" :class="{ 'active': (selectedObject.textAlign || 'left') === 'left' }">
            <span class="material-symbols-rounded">format_align_left</span>
          </button>
          <button @click="setAlign('center')" title="Align Center" class="toolbar-btn" :class="{ 'active': selectedObject.textAlign === 'center' }">
            <span class="material-symbols-rounded">format_align_center</span>
          </button>
          <button @click="setAlign('right')" title="Align Right" class="toolbar-btn" :class="{ 'active': selectedObject.textAlign === 'right' }">
            <span class="material-symbols-rounded">format_align_right</span>
          </button>
        </div>
      </div>

      <div class="toolbar-divider" v-if="selectedObject && selectedObject.type === 'text'"></div>

      <!-- List -->
      <div class="toolbar-section" v-if="selectedObject && selectedObject.type === 'text'">
        <div class="section-label">List</div>
        <div class="section-buttons">
          <button @click="setListType('bullet')" title="Bullet List" class="toolbar-btn" :class="{ 'active': selectedObject.listType === 'bullet' }">
            <span class="material-symbols-rounded">format_list_bulleted</span>
          </button>
          <button @click="setListType('numbered')" title="Numbered List" class="toolbar-btn" :class="{ 'active': selectedObject.listType === 'numbered' }">
            <span class="material-symbols-rounded">format_list_numbered</span>
          </button>
        </div>
      </div>

      <div class="toolbar-divider"></div>

      <!-- Align to Artboard -->
      <div class="toolbar-section" v-if="selectedObject">
        <div class="section-label">Position</div>
        <div class="section-buttons">
          <button @click="openDropdown('alignArtboard', $event)" class="toolbar-btn" title="Align to Artboard">
            <span class="material-symbols-rounded">grid_view</span>
          </button>
        </div>
      </div>

      <div class="toolbar-divider" v-if="selectedObject"></div>

      <!-- Actions -->
      <div class="toolbar-section" v-if="selectedObject">
        <div class="section-label">Object</div>
        <div class="section-buttons">
          <button @click="$emit('bring-forward')" title="Bring to Front" class="toolbar-btn">
            <span class="material-symbols-rounded">flip_to_front</span>
          </button>
          <button @click="$emit('send-backward')" title="Send to Back" class="toolbar-btn">
            <span class="material-symbols-rounded">flip_to_back</span>
          </button>
          <button @click="$emit('delete-selected')" title="Delete" class="toolbar-btn delete-btn">
            <span class="material-symbols-rounded">delete</span>
          </button>
        </div>
      </div>

      <!-- Spacer -->
      <div class="flex-1"></div>

      <!-- Tools (right side) -->
      <div class="toolbar-section">
        <div class="section-label">Tools</div>
        <div class="section-buttons">
          <button @click="$emit('toggle-comments')" title="Comments" class="toolbar-btn" :class="{ 'active': showComments }">
            <span class="material-symbols-rounded">comment</span>
            <span v-if="commentCount > 0" class="badge">{{ commentCount > 9 ? '9+' : commentCount }}</span>
          </button>
          <button @click="$emit('open-history')" title="Version History" class="toolbar-btn">
            <span class="material-symbols-rounded">history</span>
          </button>
        </div>
      </div>

      <div class="toolbar-divider"></div>

      <!-- Theme -->
      <div class="toolbar-section">
        <div class="section-label">Theme</div>
        <div class="section-buttons">
          <button @click="openDropdown('theme', $event)" class="dropdown-btn theme-btn" title="Change Theme">
            <span class="theme-preview" :style="{ background: themePreview?.background }"></span>
            <span class="btn-text">{{ currentThemeName }}</span>
            <span class="material-symbols-rounded dropdown-arrow">expand_more</span>
          </button>
        </div>
      </div>
    </div>

    <!-- Teleported Dropdowns -->
    <Teleport to="body">
      <!-- Shape Dropdown -->
      <div 
        v-if="showShapeDropdown"
        class="dropdown-panel shape-dropdown"
        :style="shapeDropdownPosition"
        @click.stop
      >
        <div class="dropdown-content">
          <div class="shape-grid">
            <button 
              v-for="shape in shapes"
              :key="shape.type"
              @click="selectShape(shape.type)"
              class="shape-item"
              :title="shape.label"
            >
              <span class="material-symbols-rounded">{{ shape.icon }}</span>
            </button>
          </div>
          <button @click="$emit('open-shape-library')" class="more-shapes-btn">
            <span>More shapes...</span>
            <span class="material-symbols-rounded">arrow_forward</span>
          </button>
        </div>
      </div>

      <!-- Font Family Dropdown -->
      <div 
        v-if="activeDropdown === 'fontFamily'"
        class="dropdown-panel"
        :style="dropdownPosition"
        @click.stop
      >
        <div class="dropdown-content font-list" style="width: 240px; max-height: 400px;">
          <div class="font-category">
            <div class="category-label">Sans Serif</div>
            <button 
              v-for="font in fontsByCategory.sansSerif"
              :key="font.value"
              @click="setFontFamily(font.value)"
              class="dropdown-item font-item"
              :style="{ fontFamily: font.value }"
              :class="{ 'selected': currentFontFamily === font.label }"
            >
              {{ font.label }}
            </button>
          </div>
          <div class="font-category">
            <div class="category-label">Serif</div>
            <button 
              v-for="font in fontsByCategory.serif"
              :key="font.value"
              @click="setFontFamily(font.value)"
              class="dropdown-item font-item"
              :style="{ fontFamily: font.value }"
              :class="{ 'selected': currentFontFamily === font.label }"
            >
              {{ font.label }}
            </button>
          </div>
          <div class="font-category">
            <div class="category-label">Display</div>
            <button 
              v-for="font in fontsByCategory.display"
              :key="font.value"
              @click="setFontFamily(font.value)"
              class="dropdown-item font-item"
              :style="{ fontFamily: font.value }"
              :class="{ 'selected': currentFontFamily === font.label }"
            >
              {{ font.label }}
            </button>
          </div>
          <div class="font-category">
            <div class="category-label">Monospace</div>
            <button 
              v-for="font in fontsByCategory.monospace"
              :key="font.value"
              @click="setFontFamily(font.value)"
              class="dropdown-item font-item"
              :style="{ fontFamily: font.value }"
              :class="{ 'selected': currentFontFamily === font.label }"
            >
              {{ font.label }}
            </button>
          </div>
        </div>
      </div>

      <!-- Font Weight Dropdown -->
      <div 
        v-if="activeDropdown === 'fontWeight'"
        class="dropdown-panel"
        :style="dropdownPosition"
        @click.stop
      >
        <div class="dropdown-content" style="width: 140px;">
          <button 
            v-for="weight in fontWeights"
            :key="weight.value"
            @click="setFontWeight(weight.value)"
            class="dropdown-item"
            :style="{ fontWeight: weight.value }"
            :class="{ 'selected': currentFontWeightValue === weight.value }"
          >
            {{ weight.label }}
          </button>
        </div>
      </div>
      
      <!-- Font Size Dropdown (Slider) -->
      <div 
        v-if="activeDropdown === 'fontSize'"
        class="dropdown-panel"
        :style="dropdownPosition"
        @click.stop
      >
        <div class="slider-picker-content" style="width: 220px;">
          <div class="slider-header">
            <span class="slider-label">Font Size</span>
            <span class="slider-value">{{ tempFontSize }}px</span>
          </div>
          <input 
            type="range" 
            min="8" 
            max="350" 
            step="1" 
            v-model.number="tempFontSize"
            @input="setFontSize(tempFontSize)"
            class="slider-input"
          />
          <div class="slider-marks">
            <span>8</span>
            <span>100</span>
            <span>200</span>
            <span>350</span>
          </div>
        </div>
      </div>

      <!-- Letter Spacing Dropdown -->
      <div 
        v-if="activeDropdown === 'letterSpacing'"
        class="dropdown-panel"
        :style="dropdownPosition"
        @click.stop
      >
        <div class="slider-picker-content">
          <div class="slider-header">
            <span class="slider-label">Letter Spacing</span>
            <span class="slider-value">{{ tempLetterSpacing }}px</span>
          </div>
          <input 
            type="range" 
            min="-15" 
            max="50" 
            step="0.5" 
            v-model.number="tempLetterSpacing"
            @input="setLetterSpacing(tempLetterSpacing)"
            class="slider-input"
          />
          <div class="slider-marks">
            <span>-15</span>
            <span>0</span>
            <span>25</span>
            <span>50</span>
          </div>
        </div>
      </div>

      <!-- Line Height Dropdown -->
      <div 
        v-if="activeDropdown === 'lineHeight'"
        class="dropdown-panel"
        :style="dropdownPosition"
        @click.stop
      >
        <div class="slider-picker-content">
          <div class="slider-header">
            <span class="slider-label">Line Height</span>
            <span class="slider-value">{{ tempLineHeight }}</span>
          </div>
          <input 
            type="range" 
            min="0.8" 
            max="3" 
            step="0.1" 
            v-model.number="tempLineHeight"
            @input="setLineHeight(tempLineHeight)"
            class="slider-input"
          />
          <div class="slider-marks">
            <span>0.8</span>
            <span>1.4</span>
            <span>2</span>
            <span>3</span>
          </div>
        </div>
      </div>

      <!-- Align to Artboard Dropdown -->
      <div 
        v-if="activeDropdown === 'alignArtboard'"
        class="dropdown-panel"
        :style="dropdownPosition"
        @click.stop
      >
        <div class="align-artboard-content">
          <div class="align-artboard-label">Align to Slide</div>
          <div class="align-artboard-grid">
            <button @click="alignToArtboard('top-left')" class="align-btn" title="Top Left">
              <span class="material-symbols-rounded">north_west</span>
            </button>
            <button @click="alignToArtboard('top-center')" class="align-btn" title="Top Center">
              <span class="material-symbols-rounded">north</span>
            </button>
            <button @click="alignToArtboard('top-right')" class="align-btn" title="Top Right">
              <span class="material-symbols-rounded">north_east</span>
            </button>
            <button @click="alignToArtboard('middle-left')" class="align-btn" title="Middle Left">
              <span class="material-symbols-rounded">west</span>
            </button>
            <button @click="alignToArtboard('center')" class="align-btn center-btn" title="Center">
              <span class="material-symbols-rounded">filter_center_focus</span>
            </button>
            <button @click="alignToArtboard('middle-right')" class="align-btn" title="Middle Right">
              <span class="material-symbols-rounded">east</span>
            </button>
            <button @click="alignToArtboard('bottom-left')" class="align-btn" title="Bottom Left">
              <span class="material-symbols-rounded">south_west</span>
            </button>
            <button @click="alignToArtboard('bottom-center')" class="align-btn" title="Bottom Center">
              <span class="material-symbols-rounded">south</span>
            </button>
            <button @click="alignToArtboard('bottom-right')" class="align-btn" title="Bottom Right">
              <span class="material-symbols-rounded">south_east</span>
            </button>
          </div>
        </div>
      </div>

      <!-- Text Color Picker -->
      <div 
        v-if="activeDropdown === 'textColor'"
        class="dropdown-panel"
        :style="dropdownPosition"
        @click.stop
      >
        <div class="color-picker-content">
          <div class="color-picker-label">Text Color</div>
          <div class="color-grid">
            <button
              v-for="color in textColors"
              :key="color"
              @click="setTextColor(color)"
              class="color-swatch"
              :style="{ backgroundColor: color }"
              :class="{ 'selected': selectedObject?.color === color }"
            ></button>
          </div>
          
          <!-- Custom Colors Section -->
          <div class="custom-colors-section" v-if="customColors.length > 0">
            <div class="color-picker-label">Custom Colors</div>
            <div class="color-grid">
              <button
                v-for="(color, idx) in customColors"
                :key="'custom-' + idx"
                @click="setTextColor(color)"
                class="color-swatch custom-swatch"
                :style="{ backgroundColor: color }"
                :class="{ 'selected': selectedObject?.color === color }"
              >
                <span class="remove-color" @click.stop="removeCustomColor(idx)">x</span>
              </button>
            </div>
          </div>
          
          <!-- Color Input -->
          <div class="color-input-row">
            <input
              type="color"
              v-model="customColorInput"
              class="color-input-picker"
            />
            <input
              type="text"
              v-model="customColorInput"
              class="color-input-text"
              placeholder="#000000"
              maxlength="7"
            />
            <button @click="addAndApplyCustomColor" class="color-apply-btn" title="Add & Apply">
              <span class="material-symbols-rounded">add</span>
            </button>
          </div>
        </div>
      </div>

      <!-- Fill Color Picker -->
      <div 
        v-if="activeDropdown === 'fillColor'"
        class="dropdown-panel"
        :style="dropdownPosition"
        @click.stop
      >
        <div class="color-picker-content">
          <div class="color-picker-label">Fill Color</div>
          <div class="color-grid">
            <button
              v-for="color in fillColors"
              :key="color"
              @click="setFillColor(color)"
              class="color-swatch"
              :style="{ backgroundColor: color }"
              :class="{ 'selected': selectedObject?.fill === color }"
            ></button>
          </div>
          
          <!-- Custom Colors Section -->
          <div class="custom-colors-section" v-if="customColors.length > 0">
            <div class="color-picker-label">Custom Colors</div>
            <div class="color-grid">
              <button
                v-for="(color, idx) in customColors"
                :key="'custom-' + idx"
                @click="setFillColor(color)"
                class="color-swatch custom-swatch"
                :style="{ backgroundColor: color }"
                :class="{ 'selected': selectedObject?.fill === color }"
              >
                <span class="remove-color" @click.stop="removeCustomColor(idx)">x</span>
              </button>
            </div>
          </div>
          
          <!-- Color Input -->
          <div class="color-input-row">
            <input
              type="color"
              v-model="customColorInput"
              class="color-input-picker"
            />
            <input
              type="text"
              v-model="customColorInput"
              class="color-input-text"
              placeholder="#000000"
              maxlength="7"
            />
            <button @click="addAndApplyCustomFillColor" class="color-apply-btn" title="Add & Apply">
              <span class="material-symbols-rounded">add</span>
            </button>
          </div>
          
          <button @click="setFillColor('transparent')" class="color-action">No fill</button>
        </div>
      </div>

      <!-- Stroke Color Picker -->
      <div 
        v-if="activeDropdown === 'strokeColor'"
        class="dropdown-panel"
        :style="dropdownPosition"
        @click.stop
      >
        <div class="color-picker-content">
          <div class="color-picker-label">Border Color</div>
          <div class="color-grid">
            <button
              v-for="color in fillColors"
              :key="color"
              @click="setStrokeColor(color)"
              class="color-swatch"
              :style="{ backgroundColor: color }"
              :class="{ 'selected': selectedObject?.stroke === color }"
            ></button>
          </div>
          
          <!-- Color Input -->
          <div class="color-input-row">
            <input
              type="color"
              v-model="customColorInput"
              class="color-input-picker"
            />
            <input
              type="text"
              v-model="customColorInput"
              class="color-input-text"
              placeholder="#000000"
              maxlength="7"
            />
            <button @click="applyCustomStrokeColor" class="color-apply-btn" title="Apply">
              <span class="material-symbols-rounded">check</span>
            </button>
          </div>
          
          <button @click="setStrokeColor('transparent')" class="color-action">No border</button>
        </div>
      </div>

      <!-- Stroke Width Dropdown -->
      <div 
        v-if="activeDropdown === 'strokeWidth'"
        class="dropdown-panel"
        :style="dropdownPosition"
        @click.stop
      >
        <div class="dropdown-content" style="width: 100px;">
          <button 
            v-for="width in strokeWidths"
            :key="width"
            @click="setStrokeWidth(width)"
            class="dropdown-item text-center"
            :class="{ 'selected': (selectedObject?.strokeWidth || 2) === width }"
          >
            {{ width }}px
          </button>
        </div>
      </div>

      <!-- Border Radius Dropdown (Slider) -->
      <div 
        v-if="activeDropdown === 'borderRadius'"
        class="dropdown-panel"
        :style="dropdownPosition"
        @click.stop
      >
        <div class="slider-picker-content">
          <div class="slider-header">
            <span class="slider-label">Border Radius</span>
            <span class="slider-value">{{ tempBorderRadius }}px</span>
          </div>
          <input 
            type="range" 
            min="0" 
            max="100" 
            step="1" 
            v-model.number="tempBorderRadius"
            @input="setBorderRadius(tempBorderRadius)"
            class="slider-input"
          />
          <div class="slider-marks">
            <span>0</span>
            <span>25</span>
            <span>50</span>
            <span>100</span>
          </div>
        </div>
      </div>

      <!-- Object Fit Dropdown -->
      <div 
        v-if="activeDropdown === 'objectFit'"
        class="dropdown-panel"
        :style="dropdownPosition"
        @click.stop
      >
        <div class="dropdown-content" style="width: 120px;">
          <button 
            v-for="fit in objectFitOptions"
            :key="fit.value"
            @click="setObjectFit(fit.value)"
            class="dropdown-item"
            :class="{ 'selected': (selectedObject?.objectFit || 'contain') === fit.value }"
          >
            {{ fit.label }}
          </button>
        </div>
      </div>

      <!-- Theme Picker -->
      <div 
        v-if="activeDropdown === 'theme'"
        class="dropdown-panel"
        :style="dropdownPosition"
        @click.stop
      >
        <div class="dropdown-content theme-list" style="width: 240px; max-height: 360px;">
          <button 
            v-for="theme in themes"
            :key="theme.id"
            @click="selectTheme(theme.id)"
            class="theme-item"
            :class="{ 'selected': currentThemeId === theme.id }"
          >
            <div class="theme-item-preview" :style="{ background: theme.preview?.background }">
              <span :style="{ color: theme.preview?.accent }">Aa</span>
            </div>
            <div class="theme-item-info">
              <div class="theme-item-name">{{ theme.name }}</div>
              <div class="theme-item-desc">{{ theme.description }}</div>
            </div>
            <span v-if="currentThemeId === theme.id" class="material-symbols-rounded check-icon">check</span>
          </button>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'

const props = defineProps({
  canUndo: { type: Boolean, default: false },
  canRedo: { type: Boolean, default: false },
  isExporting: { type: Boolean, default: false },
  selectedObject: { type: Object, default: null },
  hasObjects: { type: Boolean, default: false },
  showComments: { type: Boolean, default: false },
  commentCount: { type: Number, default: 0 },
  themes: { type: Array, default: () => [] },
  currentThemeId: { type: String, default: 'modern' },
  themePreview: { type: Object, default: () => ({ background: '#ffffff' }) },
  currentThemeName: { type: String, default: 'Modern' },
})

const emit = defineEmits([
  'export-pptx', 'undo', 'redo', 'global-undo',
  'add-text', 'add-shape', 'add-image', 'open-templates',
  'new-slide', 'open-background', 'save-template',
  'update-object', 'delete-selected', 'bring-forward', 'send-backward',
  'toggle-comments', 'open-history', 'change-theme', 'open-shape-library',
  'align-to-artboard', 'print'
])

// State
const showShapeDropdown = ref(false)
const shapeDropdownPosition = ref({})
const shapeBtnRef = ref(null)
const activeDropdown = ref(null)
const dropdownPosition = ref({})
const customColorInput = ref('#000000')
const customColors = ref([])
const tempLetterSpacing = ref(0)
const tempBorderRadius = ref(0)
const tempFontSize = ref(24)
const tempLineHeight = ref(1.4)

// Load custom colors from localStorage
onMounted(() => {
  try {
    const saved = localStorage.getItem('ppt-custom-colors')
    if (saved) {
      customColors.value = JSON.parse(saved)
    }
  } catch (e) {
    console.warn('Failed to load custom colors')
  }
})

// Watch for letter spacing changes
watch(() => props.selectedObject?.letterSpacing, (val) => {
  tempLetterSpacing.value = val || 0
}, { immediate: true })

// Watch for line height changes
watch(() => props.selectedObject?.lineHeight, (val) => {
  tempLineHeight.value = val || 1.4
}, { immediate: true })

// Watch for border radius changes
watch(() => props.selectedObject?.borderRadius, (val) => {
  tempBorderRadius.value = val || 0
}, { immediate: true })

// Watch for font size changes
watch(() => props.selectedObject?.fontSize, (val) => {
  tempFontSize.value = val || 24
}, { immediate: true })

// Configuration
const shapes = [
  { type: 'rectangle', icon: 'square', label: 'Rectangle' },
  { type: 'ellipse', icon: 'circle', label: 'Ellipse' },
  { type: 'triangle', icon: 'change_history', label: 'Triangle' },
  { type: 'line', icon: 'horizontal_rule', label: 'Line' },
  { type: 'arrow', icon: 'arrow_forward', label: 'Arrow' },
]

const fontsByCategory = {
  sansSerif: [
    { label: 'Inter', value: 'Inter, sans-serif' },
    { label: 'Outfit', value: 'Outfit, sans-serif' },
    { label: 'Roboto', value: 'Roboto, sans-serif' },
    { label: 'Open Sans', value: 'Open Sans, sans-serif' },
    { label: 'Lato', value: 'Lato, sans-serif' },
    { label: 'Montserrat', value: 'Montserrat, sans-serif' },
    { label: 'Poppins', value: 'Poppins, sans-serif' },
    { label: 'Nunito', value: 'Nunito, sans-serif' },
    { label: 'Source Sans Pro', value: 'Source Sans Pro, sans-serif' },
    { label: 'Arial', value: 'Arial, sans-serif' },
  ],
  serif: [
    { label: 'Playfair Display', value: 'Playfair Display, serif' },
    { label: 'Merriweather', value: 'Merriweather, serif' },
    { label: 'Lora', value: 'Lora, serif' },
    { label: 'PT Serif', value: 'PT Serif, serif' },
    { label: 'Georgia', value: 'Georgia, serif' },
    { label: 'Times New Roman', value: 'Times New Roman, serif' },
  ],
  display: [
    { label: 'Oswald', value: 'Oswald, sans-serif' },
    { label: 'Bebas Neue', value: 'Bebas Neue, sans-serif' },
    { label: 'Raleway', value: 'Raleway, sans-serif' },
    { label: 'Archivo Black', value: 'Archivo Black, sans-serif' },
  ],
  monospace: [
    { label: 'JetBrains Mono', value: 'JetBrains Mono, monospace' },
    { label: 'Fira Code', value: 'Fira Code, monospace' },
    { label: 'Roboto Mono', value: 'Roboto Mono, monospace' },
    { label: 'Source Code Pro', value: 'Source Code Pro, monospace' },
    { label: 'Consolas', value: 'Consolas, monospace' },
  ],
}

const fontWeights = [
  { label: 'Light', value: '300' },
  { label: 'Regular', value: 'normal' },
  { label: 'Medium', value: '500' },
  { label: 'Semi Bold', value: '600' },
  { label: 'Bold', value: 'bold' },
  { label: 'Extra Bold', value: '800' },
]

const textColors = [
  '#000000', '#1f2937', '#374151', '#4b5563', '#6b7280', '#9ca3af', '#d1d5db', '#f3f4f6', '#ffffff',
  '#991b1b', '#dc2626', '#f87171', '#fca5a5',
  '#92400e', '#f59e0b', '#fbbf24', '#fde047',
  '#166534', '#22c55e', '#4ade80', '#86efac',
  '#1e40af', '#3b82f6', '#60a5fa', '#93c5fd',
  '#6b21a8', '#9333ea', '#a855f7', '#c084fc',
  '#9d174d', '#ec4899', '#f472b6', '#f9a8d4',
]

const fillColors = [
  '#2196F3', '#1976D2', '#0D47A1', '#03A9F4', '#00BCD4',
  '#4CAF50', '#8BC34A', '#CDDC39', '#FFEB3B', '#FFC107',
  '#FF9800', '#FF5722', '#F44336', '#E91E63', '#9C27B0',
  '#673AB7', '#3F51B5', '#607D8B', '#9E9E9E', '#ffffff',
  '#000000', '#212121', '#424242', '#616161', '#9e9e9e', '#bdbdbd', '#e0e0e0', '#eeeeee',
]

const strokeWidths = [0, 1, 2, 3, 4, 5, 6, 8, 10, 12, 16, 20]

const objectFitOptions = [
  { label: 'Contain', value: 'contain' },
  { label: 'Cover', value: 'cover' },
  { label: 'Fill', value: 'fill' },
]

// Computed
const currentFontFamily = computed(() => {
  const fontFamily = props.selectedObject?.fontFamily || 'Inter'
  // Find matching font label
  for (const category of Object.values(fontsByCategory)) {
    const found = category.find(f => fontFamily.includes(f.label))
    if (found) return found.label
  }
  return 'Inter'
})

const currentFontWeight = computed(() => {
  const weight = props.selectedObject?.fontWeight || 'normal'
  const found = fontWeights.find(w => w.value === weight || w.value === String(weight))
  return found?.label || 'Regular'
})

const currentFontWeightValue = computed(() => {
  return props.selectedObject?.fontWeight || 'normal'
})

const isItalic = computed(() => props.selectedObject?.fontStyle === 'italic')
const isUnderline = computed(() => props.selectedObject?.textDecoration?.includes('underline'))
const isStrikethrough = computed(() => props.selectedObject?.textDecoration?.includes('line-through'))

// Methods
function handlePrint() {
  emit('print')
}

function selectShape(type) {
  emit('add-shape', type)
  showShapeDropdown.value = false
}

function openDropdown(name, event) {
  if (activeDropdown.value === name) {
    activeDropdown.value = null
    return
  }
  
  const rect = event.target.getBoundingClientRect()
  dropdownPosition.value = {
    position: 'fixed',
    top: `${rect.bottom + 6}px`,
    left: `${rect.left}px`,
    zIndex: 9999,
  }
  activeDropdown.value = name
  
  // Reset temp values
  if (name === 'letterSpacing') {
    tempLetterSpacing.value = props.selectedObject?.letterSpacing || 0
  }
  if (name === 'lineHeight') {
    tempLineHeight.value = props.selectedObject?.lineHeight || 1.4
  }
  if (name === 'borderRadius') {
    tempBorderRadius.value = props.selectedObject?.borderRadius || 0
  }
  if (name === 'fontSize') {
    tempFontSize.value = props.selectedObject?.fontSize || 24
  }
}

function closeDropdown() {
  activeDropdown.value = null
  showShapeDropdown.value = false
}

function setFontFamily(value) {
  emit('update-object', { fontFamily: value })
  closeDropdown()
}

function setFontWeight(value) {
  emit('update-object', { fontWeight: value })
  closeDropdown()
}

function setFontSize(size) {
  emit('update-object', { fontSize: size })
}

function increaseFontSize() {
  const currentSize = props.selectedObject?.fontSize || 24
  // Increment by different amounts based on current size
  let increment = 2
  if (currentSize >= 48) increment = 4
  if (currentSize >= 100) increment = 8
  if (currentSize >= 200) increment = 16
  const newSize = Math.min(350, currentSize + increment)
  emit('update-object', { fontSize: newSize })
}

function decreaseFontSize() {
  const currentSize = props.selectedObject?.fontSize || 24
  // Decrement by different amounts based on current size
  let decrement = 2
  if (currentSize >= 48) decrement = 4
  if (currentSize >= 100) decrement = 8
  if (currentSize >= 200) decrement = 16
  const newSize = Math.max(8, currentSize - decrement)
  emit('update-object', { fontSize: newSize })
}

function toggleItalic() {
  const newStyle = isItalic.value ? 'normal' : 'italic'
  emit('update-object', { fontStyle: newStyle })
}

function toggleUnderline() {
  let current = props.selectedObject?.textDecoration || ''
  let newDeco = current.includes('underline') 
    ? current.replace('underline', '').trim() 
    : (current + ' underline').trim()
  emit('update-object', { textDecoration: newDeco || 'none' })
}

function toggleStrikethrough() {
  let current = props.selectedObject?.textDecoration || ''
  let newDeco = current.includes('line-through') 
    ? current.replace('line-through', '').trim() 
    : (current + ' line-through').trim()
  emit('update-object', { textDecoration: newDeco || 'none' })
}

function setTextTransform(transform) {
  const current = props.selectedObject?.textTransform
  emit('update-object', { textTransform: current === transform ? 'none' : transform })
}

function setLetterSpacing(value) {
  emit('update-object', { letterSpacing: value })
}

function setLineHeight(value) {
  emit('update-object', { lineHeight: value })
}

function alignToArtboard(position) {
  emit('align-to-artboard', position)
  closeDropdown()
}

function setTextColor(color) {
  emit('update-object', { color })
  closeDropdown()
}

function setFillColor(color) {
  emit('update-object', { fill: color })
  closeDropdown()
}

function setStrokeColor(color) {
  emit('update-object', { stroke: color })
  closeDropdown()
}

function setStrokeWidth(width) {
  emit('update-object', { strokeWidth: width })
  closeDropdown()
}

function setBorderRadius(radius) {
  emit('update-object', { borderRadius: radius })
}

function setObjectFit(fit) {
  emit('update-object', { objectFit: fit })
  closeDropdown()
}

function setAlign(align) {
  emit('update-object', { textAlign: align })
}

function setListType(type) {
  const current = props.selectedObject?.listType
  emit('update-object', { listType: current === type ? 'none' : type })
}

function selectTheme(id) {
  emit('change-theme', id)
  closeDropdown()
}

// Custom colors
function saveCustomColors() {
  try {
    localStorage.setItem('ppt-custom-colors', JSON.stringify(customColors.value))
  } catch (e) {
    console.warn('Failed to save custom colors')
  }
}

function addAndApplyCustomColor() {
  const color = customColorInput.value
  if (color && /^#[0-9A-Fa-f]{6}$/.test(color)) {
    if (!customColors.value.includes(color)) {
      customColors.value = [color, ...customColors.value.slice(0, 9)] // Keep max 10 colors
      saveCustomColors()
    }
    emit('update-object', { color })
    closeDropdown()
  }
}

function addAndApplyCustomFillColor() {
  const color = customColorInput.value
  if (color && /^#[0-9A-Fa-f]{6}$/.test(color)) {
    if (!customColors.value.includes(color)) {
      customColors.value = [color, ...customColors.value.slice(0, 9)]
      saveCustomColors()
    }
    emit('update-object', { fill: color })
    closeDropdown()
  }
}

function applyCustomStrokeColor() {
  const color = customColorInput.value
  if (color && /^#[0-9A-Fa-f]{6}$/.test(color)) {
    emit('update-object', { stroke: color })
    closeDropdown()
  }
}

function removeCustomColor(idx) {
  customColors.value.splice(idx, 1)
  saveCustomColors()
}

// Update shape dropdown position
function updateShapeDropdownPosition() {
  if (shapeBtnRef.value) {
    const rect = shapeBtnRef.value.getBoundingClientRect()
    shapeDropdownPosition.value = {
      position: 'fixed',
      top: `${rect.bottom + 6}px`,
      left: `${rect.left}px`,
      zIndex: 9999,
    }
  }
}

// Click outside handler
function handleClickOutside(e) {
  if (!e.target.closest('.dropdown-panel') && !e.target.closest('.dropdown-btn') && !e.target.closest('.toolbar-btn')) {
    closeDropdown()
  }
}

onMounted(() => {
  document.addEventListener('click', handleClickOutside)
})

onUnmounted(() => {
  document.removeEventListener('click', handleClickOutside)
})

// Watch for shape dropdown
watch(showShapeDropdown, (show) => {
  if (show) updateShapeDropdownPosition()
})
</script>

<style scoped>
.toolbar-container {
  background: linear-gradient(to bottom, #fafafa 0%, #f5f5f5 100%);
  border-bottom: 1px solid #e0e0e0;
  padding: 8px 12px;
  overflow-x: auto;
}

.toolbar-content {
  display: flex;
  align-items: flex-end;
  gap: 8px;
  flex-wrap: nowrap;
  min-width: max-content;
}

.toolbar-section {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.section-label {
  font-size: 9px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  color: #9ca3af;
  padding-left: 2px;
}

.section-buttons {
  display: flex;
  align-items: center;
  gap: 2px;
  background: #ffffff;
  border: 1px solid #e5e7eb;
  border-radius: 8px;
  padding: 3px;
}

.toolbar-divider {
  width: 1px;
  height: 52px;
  background: linear-gradient(to bottom, transparent 0%, #d1d5db 20%, #d1d5db 80%, transparent 100%);
  margin: 0 4px;
  align-self: stretch;
}

.toolbar-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  border: none;
  border-radius: 6px;
  background: transparent;
  color: #4b5563;
  cursor: pointer;
  transition: all 0.15s ease;
  position: relative;
}

.toolbar-btn .material-symbols-rounded {
  font-size: 22px;
}

.toolbar-btn:hover {
  background: #f3f4f6;
  color: #1f2937;
}

.toolbar-btn.active {
  background: rgb(var(--color-primary-100));
  color: rgb(var(--color-primary-600));
}

.toolbar-btn.disabled {
  opacity: 0.35;
  cursor: not-allowed;
}

.toolbar-btn.disabled:hover {
  background: transparent;
  color: #4b5563;
}

.toolbar-btn.accent {
  background: rgb(var(--color-primary-500));
  color: white;
}

.toolbar-btn.accent:hover {
  background: rgb(var(--color-primary-600));
}

.toolbar-btn.delete-btn:hover {
  background: #fee2e2;
  color: #dc2626;
}

.toolbar-btn.text-btn {
  font-size: 13px;
  font-weight: 700;
  font-family: 'Outfit', Arial, sans-serif;
}

.toolbar-btn .badge {
  position: absolute;
  top: -2px;
  right: -2px;
  min-width: 16px;
  height: 16px;
  padding: 0 4px;
  background: rgb(var(--color-primary-500));
  color: white;
  font-size: 10px;
  font-weight: 600;
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.dropdown-btn {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 4px;
  height: 32px;
  padding: 0 8px;
  border: none;
  border-radius: 6px;
  background: transparent;
  color: #374151;
  font-size: 13px;
  font-weight: 500;
  cursor: pointer;
  transition: all 0.15s ease;
}

.dropdown-btn:hover {
  background: #f3f4f6;
}

.dropdown-btn .btn-text {
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.dropdown-btn .dropdown-arrow {
  font-size: 18px;
  color: #9ca3af;
}

.font-family-btn {
  min-width: 100px;
  max-width: 130px;
}

.font-weight-btn {
  min-width: 70px;
  max-width: 90px;
}

.font-size-btn {
  min-width: 50px;
}

.spacing-btn {
  min-width: 60px;
}

.theme-btn {
  min-width: 120px;
}

.theme-btn .theme-preview {
  width: 20px;
  height: 20px;
  border-radius: 4px;
  border: 1px solid #e5e7eb;
}

.color-btn {
  position: relative;
  padding-bottom: 5px;
}

.text-color-icon {
  font-size: 18px;
  font-weight: 700;
  color: #374151;
  font-family: 'Outfit', Arial, sans-serif;
}

.color-indicator {
  position: absolute;
  bottom: 3px;
  left: 50%;
  transform: translateX(-50%);
  width: 16px;
  height: 3px;
  border-radius: 2px;
}

.spin {
  animation: spin 1s linear infinite;
}

@keyframes spin {
  from { transform: rotate(0deg); }
  to { transform: rotate(360deg); }
}

.flex-1 {
  flex: 1;
}

/* Print styles */
@media print {
  .toolbar-container {
    display: none !important;
  }
}

/* Responsive - Stage 1: Hide labels */
@media (max-width: 1800px) {
  .section-label {
    display: none;
  }
  
  .toolbar-section {
    gap: 0;
  }
}

/* Responsive - Stage 2: Shrink icons */
@media (max-width: 1650px) {
  .toolbar-container {
    padding: 4px 8px;
  }
  
  .toolbar-content {
    gap: 4px;
  }
  
  .section-buttons {
    padding: 2px;
  }
  
  .toolbar-btn {
    width: 28px;
    height: 28px;
  }
  
  .toolbar-btn .material-symbols-rounded {
    font-size: 20px;
  }
  
  .toolbar-divider {
    height: 32px;
    margin: 0 2px;
  }
}

/* Responsive - Stage 3: 2 rows, centered */
@media (max-width: 1500px) {
  .toolbar-container {
    padding: 6px 12px;
    overflow-x: hidden;
  }
  
  .toolbar-content {
    flex-wrap: wrap;
    justify-content: center;
    min-width: 0;
    gap: 6px 8px;
  }
  
  .toolbar-divider {
    display: none;
  }
  
  .flex-1 {
    display: none;
  }
  
  .toolbar-btn {
    width: 30px;
    height: 30px;
  }
  
  .toolbar-btn .material-symbols-rounded {
    font-size: 20px;
  }
  
  .dropdown-btn {
    height: 30px;
    padding: 0 6px;
    font-size: 12px;
  }
  
  .font-family-btn {
    min-width: 70px;
    max-width: 90px;
  }
  
  .font-weight-btn {
    min-width: 60px;
    max-width: 70px;
  }
  
  .theme-btn {
    min-width: 90px;
  }
}

/* Responsive - Stage 4: Extra small screens */
@media (max-width: 1100px) {
  .toolbar-container {
    padding: 4px 8px;
  }
  
  .toolbar-content {
    gap: 4px 6px;
  }
  
  .section-buttons {
    padding: 1px;
    gap: 1px;
  }
  
  .toolbar-btn {
    width: 26px;
    height: 26px;
  }
  
  .toolbar-btn .material-symbols-rounded {
    font-size: 18px;
  }
  
  .dropdown-btn {
    height: 26px;
    padding: 0 4px;
    font-size: 11px;
  }
  
  .font-family-btn,
  .font-weight-btn,
  .font-size-btn,
  .spacing-btn,
  .theme-btn {
    min-width: auto;
    max-width: 70px;
  }
  
  .dropdown-btn .btn-text {
    max-width: 50px;
  }
  
  .dropdown-btn .dropdown-arrow {
    font-size: 16px;
  }
  
  .dropdown-btn {
    height: 28px;
    padding: 0 6px;
    font-size: 12px;
  }
  
  .font-family-btn {
    min-width: 80px;
    max-width: 100px;
  }
}
</style>

<style>
/* Global styles for teleported dropdowns */
.dropdown-panel {
  background: #ffffff;
  border: 1px solid #e5e7eb;
  border-radius: 10px;
  box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15), 0 2px 10px rgba(0, 0, 0, 0.05);
  overflow: hidden;
}

.dropdown-content {
  overflow-y: auto;
}

.dropdown-item {
  display: block;
  width: 100%;
  padding: 10px 14px;
  text-align: left;
  font-size: 14px;
  color: #374151;
  border: none;
  background: #ffffff;
  cursor: pointer;
  transition: background 0.1s;
}

.dropdown-item:hover {
  background: #f3f4f6;
}

.dropdown-item.selected {
  background: rgb(var(--color-primary-50));
  color: rgb(var(--color-primary-600));
  font-weight: 500;
}

/* Font List Styles */
.font-list {
  padding: 8px;
}

.font-category {
  margin-bottom: 12px;
}

.font-category:last-child {
  margin-bottom: 0;
}

.category-label {
  font-size: 10px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  color: #9ca3af;
  padding: 4px 8px 8px;
}

.font-item {
  padding: 8px 12px;
  border-radius: 6px;
  font-size: 15px;
}

/* Slider picker */
.slider-picker-content {
  padding: 16px;
  width: 200px;
}

.slider-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 12px;
}

.slider-label {
  font-size: 12px;
  font-weight: 600;
  color: #374151;
}

.slider-value {
  font-size: 13px;
  font-weight: 600;
  color: rgb(var(--color-primary-500));
}

.slider-input {
  width: 100%;
  height: 6px;
  border-radius: 3px;
  appearance: none;
  background: #e5e7eb;
  outline: none;
}

.slider-input::-webkit-slider-thumb {
  appearance: none;
  width: 18px;
  height: 18px;
  border-radius: 50%;
  background: rgb(var(--color-primary-500));
  cursor: pointer;
  box-shadow: 0 2px 6px rgba(0, 0, 0, 0.2);
}

.slider-marks {
  display: flex;
  justify-content: space-between;
  margin-top: 6px;
  font-size: 10px;
  color: #9ca3af;
}

/* Align to Artboard */
.align-artboard-content {
  padding: 12px;
  width: 140px;
}

.align-artboard-label {
  font-size: 11px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  color: #9ca3af;
  margin-bottom: 10px;
}

.align-artboard-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 4px;
}

.align-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 36px;
  height: 36px;
  border: 1px solid #e5e7eb;
  background: #ffffff;
  border-radius: 6px;
  cursor: pointer;
  transition: all 0.15s;
  color: #6b7280;
}

.align-btn:hover {
  background: #f3f4f6;
  border-color: #d1d5db;
  color: #374151;
}

.align-btn.center-btn {
  background: #f0f9ff;
  border-color: #bae6fd;
  color: #0284c7;
}

.align-btn .material-symbols-rounded {
  font-size: 18px;
}

/* Shape dropdown */
.shape-dropdown .dropdown-content {
  padding: 12px;
}

.shape-grid {
  display: grid;
  grid-template-columns: repeat(5, 1fr);
  gap: 4px;
}

.shape-item {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 40px;
  height: 40px;
  border: none;
  border-radius: 8px;
  background: #ffffff;
  color: #4b5563;
  cursor: pointer;
  transition: all 0.15s;
}

.shape-item:hover {
  background: #f3f4f6;
  color: rgb(var(--color-primary-600));
}

.shape-item .material-symbols-rounded {
  font-size: 24px;
}

.more-shapes-btn {
  display: flex;
  align-items: center;
  justify-content: space-between;
  width: 100%;
  padding: 10px;
  margin-top: 8px;
  border: none;
  border-radius: 8px;
  background: #f3f4f6;
  color: #4b5563;
  font-size: 13px;
  cursor: pointer;
  transition: all 0.15s;
}

.more-shapes-btn:hover {
  background: #e5e7eb;
}

.more-shapes-btn .material-symbols-rounded {
  font-size: 18px;
}

/* Color picker */
.color-picker-content {
  padding: 14px;
  background: #ffffff;
  width: 240px;
}

.color-picker-label {
  font-size: 11px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  color: #9ca3af;
  margin-bottom: 10px;
}

.color-grid {
  display: grid;
  grid-template-columns: repeat(9, 1fr);
  gap: 3px;
}

.color-swatch {
  width: 20px;
  height: 20px;
  border: 2px solid #ffffff;
  border-radius: 4px;
  cursor: pointer;
  transition: all 0.15s;
  box-shadow: inset 0 0 0 1px rgba(0,0,0,0.1);
  position: relative;
}

.color-swatch:hover {
  transform: scale(1.2);
  z-index: 1;
  box-shadow: 0 2px 8px rgba(0,0,0,0.2);
}

.color-swatch.selected {
  border: 2px solid rgb(var(--color-primary-500));
  box-shadow: 0 0 0 2px rgb(var(--color-primary-200));
}

.custom-colors-section {
  margin-top: 12px;
  padding-top: 12px;
  border-top: 1px solid #e5e7eb;
}

.custom-swatch .remove-color {
  position: absolute;
  top: -4px;
  right: -4px;
  width: 12px;
  height: 12px;
  background: #ef4444;
  color: white;
  font-size: 10px;
  font-weight: bold;
  line-height: 12px;
  text-align: center;
  border-radius: 50%;
  opacity: 0;
  transition: opacity 0.15s;
}

.custom-swatch:hover .remove-color {
  opacity: 1;
}

.color-input-row {
  display: flex;
  gap: 6px;
  margin-top: 12px;
  padding-top: 12px;
  border-top: 1px solid #e5e7eb;
  align-items: center;
}

.color-input-row .color-input-text {
  flex: 1;
  min-width: 70px;
}

.color-input-picker {
  width: 36px;
  height: 32px;
  padding: 2px;
  border: 1px solid #e5e7eb;
  border-radius: 6px;
  cursor: pointer;
}

.color-input-text {
  flex: 1;
  padding: 0 10px;
  height: 32px;
  border: 1px solid #e5e7eb;
  border-radius: 6px;
  font-size: 12px;
  font-family: 'JetBrains Mono', monospace;
  background: #ffffff;
  color: #374151;
}

.color-apply-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  border: none;
  border-radius: 6px;
  background: rgb(var(--color-primary-500));
  color: white;
  cursor: pointer;
  transition: all 0.15s;
}

.color-apply-btn:hover {
  background: rgb(var(--color-primary-600));
}

.color-apply-btn .material-symbols-rounded {
  font-size: 18px;
}

.color-action {
  display: block;
  width: 100%;
  padding: 10px;
  margin-top: 10px;
  text-align: center;
  font-size: 12px;
  font-weight: 500;
  color: #6b7280;
  background: #f3f4f6;
  border: none;
  border-radius: 6px;
  cursor: pointer;
  transition: all 0.15s;
}

.color-action:hover {
  background: #e5e7eb;
  color: #374151;
}

/* Theme list */
.theme-list {
  padding: 8px;
}

.theme-item {
  display: flex;
  align-items: center;
  gap: 12px;
  width: 100%;
  padding: 10px;
  border: none;
  border-radius: 8px;
  background: #ffffff;
  cursor: pointer;
  transition: all 0.15s;
  text-align: left;
}

.theme-item:hover {
  background: #f3f4f6;
}

.theme-item.selected {
  background: rgb(var(--color-primary-50));
}

.theme-item-preview {
  width: 40px;
  height: 28px;
  border-radius: 4px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 10px;
  font-weight: 700;
  border: 1px solid #e5e7eb;
}

.theme-item-info {
  flex: 1;
  min-width: 0;
}

.theme-item-name {
  font-size: 13px;
  font-weight: 600;
  color: #1f2937;
}

.theme-item-desc {
  font-size: 11px;
  color: #9ca3af;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.check-icon {
  font-size: 18px;
  color: rgb(var(--color-primary-500));
}
</style>
