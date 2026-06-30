<template>
  <div class="collab-object-properties w-64 bg-white  border-l border-surface-200  overflow-y-auto flex-shrink-0">
    <div class="px-3 py-2 border-b border-surface-200 ">
      <h3 class="text-sm font-medium text-surface-700 ">
        {{ panelTitle }}
      </h3>
    </div>
    
    <!-- No selection state -->
    <div v-if="!object" class="p-3 text-center text-surface-500 ">
      <span class="material-symbols-rounded text-3xl mb-1 block opacity-50">touch_app</span>
      <p class="text-xs">Select an object to edit</p>
    </div>
    
    <!-- Object properties -->
    <div v-else class="p-3 space-y-2">
      <!-- Scale content toggle (for text) -->
      <div v-if="object.type === 'text'" class="flex items-center justify-between">
        <label class="text-xs text-surface-600 ">Scale on resize</label>
        <button
          @click="toggleScaleContent"
          class="relative inline-flex h-4 w-7 items-center rounded-full transition-colors"
          :class="scaleContent ? 'bg-primary-500' : 'bg-surface-300 '"
        >
          <span
            class="inline-block h-3 w-3 transform rounded-full bg-white shadow transition-transform"
            :class="scaleContent ? 'translate-x-3.5' : 'translate-x-0.5'"
          ></span>
        </button>
      </div>
      
      <!-- Text properties -->
      <template v-if="object.type === 'text'">
        <hr class="border-surface-200 " />
        
        <div class="space-y-2">
          <!-- Font size and family row -->
          <div class="flex gap-2">
            <div class="w-16">
              <label class="text-xs text-surface-500">Size</label>
              <input
                type="number"
                min="8"
                max="400"
                :value="object.fontSize || 24"
                @change="updateProperty('fontSize', Number($event.target.value))"
                class="w-full px-2 py-1 text-sm border border-surface-300  rounded-lg bg-white "
              />
            </div>
            <div class="flex-1">
              <label class="text-xs text-surface-500">Weight</label>
              <select
                :value="object.fontWeight || 'normal'"
                @change="updateProperty('fontWeight', $event.target.value)"
                class="w-full px-1 py-1 text-sm border border-surface-300  rounded-lg bg-white "
              >
                <option value="300">Light</option>
                <option value="normal">Regular</option>
                <option value="500">Medium</option>
                <option value="600">Semi Bold</option>
                <option value="bold">Bold</option>
                <option value="800">Extra Bold</option>
              </select>
            </div>
          </div>
          
          <!-- Font family -->
          <div>
            <label class="text-xs text-surface-500">Font</label>
            <select
              :value="object.fontFamily || 'Inter'"
              @change="updateProperty('fontFamily', $event.target.value)"
              class="w-full px-1 py-1 text-sm border border-surface-300  rounded-lg bg-white "
            >
              <optgroup label="Sans Serif">
                <option value="Inter">Inter</option>
                <option value="Roboto">Roboto</option>
                <option value="Open Sans">Open Sans</option>
                <option value="Poppins">Poppins</option>
                <option value="Montserrat">Montserrat</option>
                <option value="Lato">Lato</option>
                <option value="Arial">Arial</option>
              </optgroup>
              <optgroup label="Serif">
                <option value="Playfair Display">Playfair Display</option>
                <option value="Merriweather">Merriweather</option>
                <option value="Georgia">Georgia</option>
                <option value="Times New Roman">Times New Roman</option>
              </optgroup>
              <optgroup label="Display">
                <option value="Oswald">Oswald</option>
                <option value="Bebas Neue">Bebas Neue</option>
              </optgroup>
              <optgroup label="Monospace">
                <option value="Roboto Mono">Roboto Mono</option>
                <option value="JetBrains Mono">JetBrains Mono</option>
              </optgroup>
            </select>
          </div>
          
          <!-- Letter spacing -->
          <div>
            <label class="text-xs text-surface-500">Letter spacing</label>
            <div class="flex items-center gap-2">
              <input
                type="range"
                min="-5"
                max="20"
                step="0.5"
                :value="object.letterSpacing || 0"
                @input="updateProperty('letterSpacing', Number($event.target.value))"
                class="flex-1 accent-primary-500"
              />
              <span class="text-sm text-surface-600  w-10 text-right">{{ object.letterSpacing || 0 }}px</span>
            </div>
          </div>
          
          <!-- Text style & case (combined row) -->
          <div>
            <label class="text-xs text-surface-500">Style</label>
            <div class="flex gap-1 mt-1">
              <button
                @click="toggleProperty('fontStyle', 'italic', 'normal')"
                class="p-1.5 rounded-full border transition-colors"
                :class="object.fontStyle === 'italic' ? 'bg-primary-500/15 border-primary-500 text-primary-600' : 'border-surface-300  hover:bg-surface-100 '"
                title="Italic"
              >
                <span class="material-symbols-rounded" style="font-size: 16px;">format_italic</span>
              </button>
              <button
                @click="toggleProperty('textDecoration', 'underline', 'none')"
                class="p-1.5 rounded-full border transition-colors"
                :class="object.textDecoration === 'underline' ? 'bg-primary-500/15 border-primary-500 text-primary-600' : 'border-surface-300  hover:bg-surface-100 '"
                title="Underline"
              >
                <span class="material-symbols-rounded" style="font-size: 16px;">format_underlined</span>
              </button>
              <button
                @click="toggleProperty('textDecoration', 'line-through', 'none')"
                class="p-1.5 rounded-full border transition-colors"
                :class="object.textDecoration === 'line-through' ? 'bg-primary-500/15 border-primary-500 text-primary-600' : 'border-surface-300  hover:bg-surface-100 '"
                title="Strikethrough"
              >
                <span class="material-symbols-rounded" style="font-size: 16px;">strikethrough_s</span>
              </button>
              <span class="w-px bg-surface-300  mx-0.5"></span>
              <button
                @click="updateProperty('textTransform', 'uppercase')"
                class="p-1.5 rounded-full border text-xs font-bold transition-colors"
                :class="object.textTransform === 'uppercase' ? 'bg-primary-500/15 border-primary-500 text-primary-600' : 'border-surface-300  hover:bg-surface-100 '"
                title="Uppercase"
              >
                AA
              </button>
              <button
                @click="updateProperty('textTransform', 'lowercase')"
                class="p-1.5 rounded-full border text-xs font-bold transition-colors"
                :class="object.textTransform === 'lowercase' ? 'bg-primary-500/15 border-primary-500 text-primary-600' : 'border-surface-300  hover:bg-surface-100 '"
                title="Lowercase"
              >
                aa
              </button>
              <button
                @click="updateProperty('textTransform', 'capitalize')"
                class="p-1.5 rounded-full border text-xs font-bold transition-colors"
                :class="object.textTransform === 'capitalize' ? 'bg-primary-500/15 border-primary-500 text-primary-600' : 'border-surface-300  hover:bg-surface-100 '"
                title="Capitalize"
              >
                Aa
              </button>
            </div>
          </div>
          
          <!-- Color and alignment row -->
          <div class="flex items-end gap-2">
            <div class="w-20">
              <label class="text-xs text-surface-500">Color</label>
              <div class="flex items-center gap-1">
                <input
                  type="color"
                  :value="object.color || '#000000'"
                  @input="updateProperty('color', $event.target.value)"
                  class="w-8 h-8 rounded-full border border-surface-300  cursor-pointer"
                />
                <input
                  type="text"
                  :value="object.color || '#000000'"
                  @change="updateProperty('color', $event.target.value)"
                  class="w-full px-1 py-1 text-xs border border-surface-300  rounded-lg bg-white  font-mono"
                />
              </div>
            </div>
            <div class="flex-1">
              <label class="text-xs text-surface-500">Align</label>
              <div class="flex gap-0.5">
                <button
                  @click="updateProperty('textAlign', 'left')"
                  class="flex-1 p-1.5 rounded-full border transition-colors"
                  :class="(object.textAlign || 'left') === 'left' ? 'bg-primary-500/15 border-primary-500 text-primary-600' : 'border-surface-300  hover:bg-surface-100 '"
                >
                  <span class="material-symbols-rounded" style="font-size: 16px;">format_align_left</span>
                </button>
                <button
                  @click="updateProperty('textAlign', 'center')"
                  class="flex-1 p-1.5 rounded-full border transition-colors"
                  :class="object.textAlign === 'center' ? 'bg-primary-500/15 border-primary-500 text-primary-600' : 'border-surface-300  hover:bg-surface-100 '"
                >
                  <span class="material-symbols-rounded" style="font-size: 16px;">format_align_center</span>
                </button>
                <button
                  @click="updateProperty('textAlign', 'right')"
                  class="flex-1 p-1.5 rounded-full border transition-colors"
                  :class="object.textAlign === 'right' ? 'bg-primary-500/15 border-primary-500 text-primary-600' : 'border-surface-300  hover:bg-surface-100 '"
                >
                  <span class="material-symbols-rounded" style="font-size: 16px;">format_align_right</span>
                </button>
              </div>
            </div>
          </div>
          
          <!-- List type -->
          <div>
            <label class="text-xs text-surface-500">List</label>
            <div class="flex gap-0.5">
              <button
                @click="updateProperty('listType', 'none')"
                class="flex-1 p-1.5 rounded-full border transition-colors"
                :class="(!object.listType || object.listType === 'none') ? 'bg-primary-500/15 border-primary-500 text-primary-600' : 'border-surface-300  hover:bg-surface-100 '"
                title="No list"
              >
                <span class="material-symbols-rounded" style="font-size: 16px;">format_clear</span>
              </button>
              <button
                @click="updateProperty('listType', 'bullet')"
                class="flex-1 p-1.5 rounded-full border transition-colors"
                :class="object.listType === 'bullet' ? 'bg-primary-500/15 border-primary-500 text-primary-600' : 'border-surface-300  hover:bg-surface-100 '"
                title="Bullet list"
              >
                <span class="material-symbols-rounded" style="font-size: 16px;">format_list_bulleted</span>
              </button>
              <button
                @click="updateProperty('listType', 'numbered')"
                class="flex-1 p-1.5 rounded-full border transition-colors"
                :class="object.listType === 'numbered' ? 'bg-primary-500/15 border-primary-500 text-primary-600' : 'border-surface-300  hover:bg-surface-100 '"
                title="Numbered list"
              >
                <span class="material-symbols-rounded" style="font-size: 16px;">format_list_numbered</span>
              </button>
            </div>
          </div>
        </div>
      </template>
      
      <!-- Shape properties -->
      <template v-if="object.type === 'shape'">
        <hr class="border-surface-200 " />
        
        <div class="space-y-2">
          <!-- Fill and Stroke colors -->
          <div class="flex gap-2">
            <div class="flex-1">
              <label class="text-xs text-surface-500">Fill</label>
              <div class="flex items-center gap-1">
                <input
                  type="color"
                  :value="object.fill || '#2196F3'"
                  @input="updateProperty('fill', $event.target.value)"
                  class="w-7 h-7 rounded-full border border-surface-300  cursor-pointer"
                />
                <input
                  type="text"
                  :value="object.fill || '#2196F3'"
                  @change="updateProperty('fill', $event.target.value)"
                  class="w-full px-1 py-1 text-xs border border-surface-300  rounded-lg bg-white  font-mono"
                />
              </div>
            </div>
            <div class="flex-1">
              <label class="text-xs text-surface-500">Border</label>
              <div class="flex items-center gap-1">
                <input
                  type="color"
                  :value="object.stroke || '#1976D2'"
                  @input="updateProperty('stroke', $event.target.value)"
                  class="w-7 h-7 rounded-full border border-surface-300  cursor-pointer"
                />
                <input
                  type="text"
                  :value="object.stroke || '#1976D2'"
                  @change="updateProperty('stroke', $event.target.value)"
                  class="w-full px-1 py-1 text-xs border border-surface-300  rounded-lg bg-white  font-mono"
                />
              </div>
            </div>
          </div>
          
          <!-- Stroke width -->
          <div>
            <label class="text-xs text-surface-500">Border width</label>
            <div class="flex items-center gap-1">
              <input
                type="range"
                min="0"
                max="20"
                :value="object.strokeWidth || 2"
                @input="updateProperty('strokeWidth', Number($event.target.value))"
                class="flex-1 accent-primary-500"
              />
              <span class="text-xs text-surface-600  w-8 text-right">{{ object.strokeWidth || 2 }}px</span>
            </div>
          </div>
        </div>
      </template>
      
      <!-- Image properties -->
      <template v-if="object.type === 'image'">
        <hr class="border-surface-200 " />
        
        <div class="space-y-2">
          <!-- Object fit -->
          <div>
            <label class="text-xs text-surface-500">Fit mode</label>
            <select
              :value="object.objectFit || 'contain'"
              @change="updateProperty('objectFit', $event.target.value)"
              class="w-full px-1 py-1 text-sm border border-surface-300  rounded-lg bg-white "
            >
              <option value="contain">Contain</option>
              <option value="cover">Cover</option>
              <option value="fill">Stretch</option>
            </select>
          </div>
          
          <!-- Border radius -->
          <div>
            <label class="text-xs text-surface-500">Corner radius</label>
            <div class="flex items-center gap-1">
              <input
                type="range"
                min="0"
                max="100"
                :value="object.borderRadius || 0"
                @input="updateProperty('borderRadius', Number($event.target.value))"
                class="flex-1 accent-primary-500"
              />
              <span class="text-xs text-surface-600  w-8 text-right">{{ object.borderRadius || 0 }}px</span>
            </div>
          </div>
        </div>
      </template>
      
      <!-- Delete button -->
      <hr class="border-surface-200  mt-2" />
      <button
        @click="$emit('delete')"
        class="w-full px-3 py-1.5 text-xs text-red-600 hover:bg-red-50 rounded-full border border-red-200 flex items-center justify-center gap-1 transition-colors"
      >
        <span class="material-symbols-rounded" style="font-size: 16px;">delete</span>
        Delete
      </button>
    </div>
  </div>
</template>

<script setup>
import { ref, watch, computed } from 'vue'

const props = defineProps({
  object: {
    type: Object,
    default: null,
  },
})

const emit = defineEmits(['update', 'delete'])

// Panel title based on object type
const panelTitle = computed(() => {
  if (!props.object) return 'Properties'
  switch (props.object.type) {
    case 'text': return 'Text Properties'
    case 'shape': return 'Shape Properties'
    case 'image': return 'Image Properties'
    default: return 'Properties'
  }
})

// Scale content toggle - stored per object
const scaleContent = ref(props.object?.scaleContent ?? false)

watch(() => props.object?.scaleContent, (val) => {
  scaleContent.value = val ?? false
})

function updateProperty(key, value) {
  emit('update', { [key]: value })
}

function toggleProperty(key, onValue, offValue) {
  const currentValue = props.object?.[key]
  const newValue = currentValue === onValue ? offValue : onValue
  emit('update', { [key]: newValue })
}

function toggleScaleContent() {
  scaleContent.value = !scaleContent.value
  emit('update', { scaleContent: scaleContent.value })
}
</script>
