<template>
  <div 
    class="file-panel flex flex-col overflow-hidden min-w-[300px] border border-surface-200 dark:border-[rgb(var(--color-border))]"
    :class="{ 'ring-1 ring-primary-500/50': active }"
    @click="$emit('activate')"
    tabindex="0"
    @keydown="handleKeydown"
    ref="panel"
  >
    <!-- Panel Header -->
    <div 
      class="flex items-center gap-2 px-3 py-2 text-sm"
      :class="active 
        ? 'bg-primary-500/20 dark:bg-primary-500/25 text-primary-700 dark:text-primary-400 border-b-2 border-primary-500' 
        : 'bg-surface-200 dark:bg-[rgb(var(--color-surface-elevated))] text-surface-700 dark:text-surface-300 border-b-2 border-transparent'"
    >
      <div class="flex gap-1">
        <button 
          class="w-7 h-7 flex items-center justify-center rounded hover:bg-white/20 disabled:opacity-30" 
          @click.stop="goUp" 
          :disabled="path === '/'"
        >
          <span class="material-symbols-rounded text-lg">arrow_upward</span>
        </button>
        <button 
          class="w-7 h-7 flex items-center justify-center rounded hover:bg-white/20" 
          @click.stop="refresh"
        >
          <span class="material-symbols-rounded text-lg">refresh</span>
        </button>
        <button 
          class="w-7 h-7 flex items-center justify-center rounded hover:bg-white/20" 
          @click.stop="goHome"
        >
          <span class="material-symbols-rounded text-lg">home</span>
        </button>
      </div>
      <div class="flex-1 flex items-center font-mono text-xs overflow-hidden">
        <span 
          v-for="(part, index) in breadcrumbs" 
          :key="index"
          class="cursor-pointer px-1 rounded hover:bg-white/20 whitespace-nowrap"
          @click.stop="navigateToBreadcrumb(index)"
        >
          {{ part || '/' }}<span v-if="index < breadcrumbs.length - 1" class="ml-1 opacity-50">/</span>
        </span>
      </div>
    </div>

    <!-- Column Headers -->
    <div class="flex px-3 py-2 bg-surface-100 dark:bg-[rgb(var(--color-surface))] border-b border-surface-200 dark:border-[rgb(var(--color-border))] text-[10px] font-semibold uppercase tracking-wide text-surface-500 dark:text-surface-400">
      <div class="flex-1 min-w-[140px] cursor-pointer flex items-center gap-1" @click="sortBy('name')">
        Name
        <span v-if="sortField === 'name'" class="material-symbols-rounded text-xs">
          {{ sortDir === 'asc' ? 'arrow_upward' : 'arrow_downward' }}
        </span>
      </div>
      <div class="w-20 text-right cursor-pointer" @click="sortBy('size')">Size</div>
      <div class="w-12 text-center">Perms</div>
      <div class="w-24 text-center cursor-pointer" @click="sortBy('owner')">Owner</div>
      <div class="w-28 text-right cursor-pointer" @click="sortBy('modified')">Modified</div>
    </div>

    <!-- File List -->
    <div 
      class="flex-1 overflow-y-auto bg-white dark:bg-[rgb(var(--color-bg))]" 
      ref="fileList"
      @contextmenu.prevent="$emit('context', { event: $event, item: null })"
    >
      <!-- Loading -->
      <div v-if="loading" class="flex flex-col items-center justify-center py-12 text-surface-400">
        <span class="material-symbols-rounded text-4xl animate-spin">progress_activity</span>
        <span class="mt-2 text-sm">Loading...</span>
      </div>

      <!-- Empty -->
      <div v-else-if="!allItems.length && path === '/'" class="flex flex-col items-center justify-center py-12 text-surface-400">
        <span class="material-symbols-rounded text-4xl">folder_off</span>
        <span class="mt-2 text-sm">Empty directory</span>
      </div>

      <!-- Table rows -->
      <template v-else>
        <!-- Go up row -->
        <div 
          v-if="path !== '/'"
          class="file-row flex items-center px-3 py-2 cursor-pointer font-mono text-xs border-b border-surface-100 dark:border-[rgb(var(--color-border))] text-surface-500 dark:text-surface-400 hover:bg-surface-100 dark:hover:bg-[rgb(var(--color-surface-hover))]"
          :class="{ 'bg-primary-500/10 dark:bg-primary-500/15': focusedIndex === -1 }"
          @click.stop="goUp"
          @dblclick.stop="goUp"
        >
          <div class="flex-1 min-w-[140px] flex items-center gap-2">
            <span class="material-symbols-rounded text-base text-amber-500">drive_folder_upload</span>
            <span>..</span>
          </div>
          <div class="w-20 text-right">-</div>
          <div class="w-12 text-center">-</div>
          <div class="w-24 text-center">-</div>
          <div class="w-28 text-right">-</div>
        </div>

        <!-- Items -->
        <div
          v-for="(item, index) in allItems"
          :key="item.path"
          class="file-row flex items-center px-3 py-2 cursor-pointer font-mono text-xs border-b border-surface-100 dark:border-[rgb(var(--color-border))] transition-colors"
          :class="getRowClasses(item, index)"
          @click.stop="onRowClick(item, index, $event)"
          @dblclick.stop="onRowDoubleClick(item)"
          @contextmenu.prevent.stop="$emit('context', { event: $event, item: item })"
        >
          <div class="flex-1 min-w-[140px] flex items-center gap-2 overflow-hidden">
            <span class="material-symbols-rounded text-base flex-shrink-0" :class="getIconColor(item)">
              {{ getIcon(item) }}
            </span>
            <span class="truncate" :class="{ 'opacity-50': item.name.startsWith('.') }">{{ item.name }}</span>
            <span v-if="item.is_link" class="material-symbols-rounded text-xs text-surface-400">link</span>
          </div>
          <div class="w-20 text-right text-surface-500 dark:text-surface-400">
            {{ item.type === 'directory' ? '&lt;DIR&gt;' : item.size_human }}
          </div>
          <div class="w-12 text-center text-surface-400">{{ item.permissions_octal }}</div>
          <div class="w-24 text-center text-surface-400 truncate" :title="item.owner">{{ item.owner }}</div>
          <div class="w-28 text-right text-surface-400">{{ formatDate(item.modified) }}</div>
        </div>
      </template>
    </div>

    <!-- Status Bar -->
    <div class="flex items-center gap-3 px-3 py-2 bg-surface-100 dark:bg-[rgb(var(--color-surface))] border-t border-surface-200 dark:border-[rgb(var(--color-border))] text-[11px] text-surface-500 dark:text-surface-400 font-mono">
      <span>{{ allItems.length }} items</span>
      <span v-if="selected.length" class="text-primary-500">| {{ selected.length }} selected</span>
      <span class="flex-1"></span>
      <span class="truncate max-w-[200px]">{{ path }}</span>
    </div>
  </div>
</template>

<script>
export default {
  name: 'FilePanel',
  
  props: {
    path: { type: String, required: true },
    items: { type: Array, default: () => [] },
    loading: { type: Boolean, default: false },
    selected: { type: Array, default: () => [] },
    active: { type: Boolean, default: false },
    showHidden: { type: Boolean, default: false },
  },
  
  emits: ['update:selected', 'navigate', 'activate', 'context', 'preview', 'switchPanel'],
  
  data() {
    return {
      sortField: 'name',
      sortDir: 'asc',
      focusedIndex: 0,
    };
  },
  
  computed: {
    breadcrumbs() {
      const parts = this.path.split('/').filter(Boolean);
      return ['', ...parts];
    },
    
    allItems() {
      const filtered = this.showHidden 
        ? this.items 
        : this.items.filter(item => !item.name.startsWith('.'));
      
      return [...filtered].sort((a, b) => {
        if (a.type === 'directory' && b.type !== 'directory') return -1;
        if (a.type !== 'directory' && b.type === 'directory') return 1;
        
        let cmp = 0;
        if (this.sortField === 'name') {
          cmp = a.name.localeCompare(b.name);
        } else if (this.sortField === 'size') {
          cmp = (a.size || 0) - (b.size || 0);
        } else if (this.sortField === 'modified') {
          cmp = new Date(a.modified) - new Date(b.modified);
        } else if (this.sortField === 'owner') {
          cmp = (a.owner || '').localeCompare(b.owner || '');
        }
        
        return this.sortDir === 'asc' ? cmp : -cmp;
      });
    },
  },
  
  watch: {
    active(val) {
      if (val) this.$nextTick(() => this.$refs.panel?.focus());
    },
    items() {
      this.focusedIndex = 0;
    },
  },
  
  mounted() {
    if (this.active) this.$refs.panel?.focus();
  },
  
  methods: {
    focus() {
      this.$refs.panel?.focus();
    },
    
    getRowClasses(item, index) {
      const classes = [];
      
      if (this.isSelected(item)) {
        classes.push('bg-primary-500/20 dark:bg-primary-500/25 text-primary-700 dark:text-primary-400');
      } else if (this.focusedIndex === index) {
        classes.push('bg-primary-500/10 dark:bg-primary-500/15');
      } else {
        classes.push('text-surface-800 dark:text-surface-200 hover:bg-surface-50 dark:hover:bg-[rgb(var(--color-surface-hover))]');
      }
      
      return classes;
    },
    
    // Single click - select, or navigate if directory
    onRowClick(item, index, event) {
      this.$emit('activate');
      this.focusedIndex = index;
      
      if (event.ctrlKey || event.metaKey) {
        this.toggleItemSelection(item);
      } else if (event.shiftKey && this.selected.length > 0) {
        this.rangeSelect(index);
      } else {
        this.$emit('update:selected', [item]);
        // Single click navigates into directories
        if (item.type === 'directory') {
          this.$emit('navigate', item.path);
        }
      }
    },
    
    // Double click - navigate or preview
    onRowDoubleClick(item) {
      if (item.type === 'directory') {
        this.$emit('navigate', item.path);
      } else {
        this.$emit('preview', item);
      }
    },
    
    toggleItemSelection(item) {
      let newSelected = [...this.selected];
      const idx = newSelected.findIndex(i => i.path === item.path);
      if (idx >= 0) {
        newSelected.splice(idx, 1);
      } else {
        newSelected.push(item);
      }
      this.$emit('update:selected', newSelected);
    },
    
    rangeSelect(toIndex) {
      const lastSelected = this.selected[this.selected.length - 1];
      const fromIndex = this.allItems.findIndex(i => i.path === lastSelected.path);
      const start = Math.min(fromIndex, toIndex);
      const end = Math.max(fromIndex, toIndex);
      this.$emit('update:selected', this.allItems.slice(start, end + 1));
    },
    
    handleKeydown(event) {
      if (!this.active) return;
      
      const maxIndex = this.allItems.length - 1;
      
      switch (event.key) {
        case 'ArrowUp':
          event.preventDefault();
          this.focusedIndex = Math.max(this.path !== '/' ? -1 : 0, this.focusedIndex - 1);
          this.scrollToFocused();
          if (!event.shiftKey) this.selectFocused();
          else this.extendSelection();
          break;
          
        case 'ArrowDown':
          event.preventDefault();
          this.focusedIndex = Math.min(maxIndex, this.focusedIndex + 1);
          this.scrollToFocused();
          if (!event.shiftKey) this.selectFocused();
          else this.extendSelection();
          break;
          
        case 'ArrowLeft':
        case 'ArrowRight':
          event.preventDefault();
          this.$emit('switchPanel');
          break;
          
        case 'Enter':
          event.preventDefault();
          if (this.focusedIndex === -1) {
            this.goUp();
          } else if (this.focusedIndex >= 0 && this.focusedIndex <= maxIndex) {
            const item = this.allItems[this.focusedIndex];
            if (item.type === 'directory') {
              this.$emit('navigate', item.path);
            } else {
              this.$emit('preview', item);
            }
          }
          break;
          
        case 'Backspace':
          event.preventDefault();
          this.goUp();
          break;
          
        case 'Home':
          event.preventDefault();
          this.focusedIndex = this.path !== '/' ? -1 : 0;
          this.scrollToFocused();
          this.selectFocused();
          break;
          
        case 'End':
          event.preventDefault();
          this.focusedIndex = maxIndex;
          this.scrollToFocused();
          this.selectFocused();
          break;
          
        case 'PageUp':
          event.preventDefault();
          this.focusedIndex = Math.max(this.path !== '/' ? -1 : 0, this.focusedIndex - 15);
          this.scrollToFocused();
          this.selectFocused();
          break;
          
        case 'PageDown':
          event.preventDefault();
          this.focusedIndex = Math.min(maxIndex, this.focusedIndex + 15);
          this.scrollToFocused();
          this.selectFocused();
          break;
          
        case ' ':
          event.preventDefault();
          this.toggleFocusedSelection();
          break;
          
        case 'a':
          if (event.ctrlKey || event.metaKey) {
            event.preventDefault();
            this.$emit('update:selected', [...this.allItems]);
          }
          break;
      }
    },
    
    scrollToFocused() {
      this.$nextTick(() => {
        const list = this.$refs.fileList;
        if (!list) return;
        const rowIndex = this.focusedIndex + (this.path !== '/' ? 1 : 0);
        const row = list.children[rowIndex];
        if (row) row.scrollIntoView({ block: 'nearest' });
      });
    },
    
    selectFocused() {
      if (this.focusedIndex >= 0 && this.focusedIndex < this.allItems.length) {
        this.$emit('update:selected', [this.allItems[this.focusedIndex]]);
      } else {
        this.$emit('update:selected', []);
      }
    },
    
    extendSelection() {
      if (this.focusedIndex < 0 || this.focusedIndex >= this.allItems.length) return;
      const item = this.allItems[this.focusedIndex];
      if (!this.isSelected(item)) {
        this.$emit('update:selected', [...this.selected, item]);
      }
    },
    
    toggleFocusedSelection() {
      if (this.focusedIndex < 0 || this.focusedIndex >= this.allItems.length) return;
      const item = this.allItems[this.focusedIndex];
      this.toggleItemSelection(item);
      if (this.focusedIndex < this.allItems.length - 1) {
        this.focusedIndex++;
        this.scrollToFocused();
      }
    },
    
    goUp() {
      if (this.path === '/') return;
      const parentPath = this.path.split('/').slice(0, -1).join('/') || '/';
      this.$emit('navigate', parentPath);
    },
    
    goHome() {
      this.$emit('navigate', '/home');
    },
    
    refresh() {
      this.$emit('navigate', this.path);
    },
    
    navigateToBreadcrumb(index) {
      const newPath = '/' + this.breadcrumbs.slice(1, index + 1).join('/');
      this.$emit('navigate', newPath || '/');
    },
    
    sortBy(field) {
      if (this.sortField === field) {
        this.sortDir = this.sortDir === 'asc' ? 'desc' : 'asc';
      } else {
        this.sortField = field;
        this.sortDir = 'asc';
      }
    },
    
    isSelected(item) {
      return this.selected.some(i => i.path === item.path);
    },
    
    getIcon(item) {
      if (item.type === 'directory') return 'folder';
      const ext = item.extension || '';
      const icons = {
        pdf: 'picture_as_pdf', doc: 'description', docx: 'description',
        txt: 'article', md: 'article', php: 'code', js: 'javascript',
        ts: 'code', html: 'html', css: 'css', json: 'data_object',
        xml: 'code', vue: 'code', py: 'code', jpg: 'image', jpeg: 'image',
        png: 'image', gif: 'image', svg: 'image', webp: 'image',
        zip: 'folder_zip', tar: 'folder_zip', gz: 'folder_zip',
        conf: 'settings', cfg: 'settings', ini: 'settings', env: 'settings',
        yml: 'settings', yaml: 'settings', mp3: 'audio_file', mp4: 'video_file',
        sql: 'database', db: 'database', log: 'description',
      };
      return icons[ext] || 'insert_drive_file';
    },
    
    getIconColor(item) {
      if (item.type === 'directory') return 'text-amber-500';
      const ext = item.extension || '';
      if (['php', 'js', 'ts', 'html', 'css', 'vue', 'py', 'json', 'xml'].includes(ext)) return 'text-blue-500';
      if (['jpg', 'jpeg', 'png', 'gif', 'svg', 'webp'].includes(ext)) return 'text-green-500';
      if (['zip', 'tar', 'gz', 'tgz'].includes(ext)) return 'text-purple-500';
      return 'text-surface-400';
    },
    
    formatDate(dateString) {
      const date = new Date(dateString);
      const now = new Date();
      const isThisYear = date.getFullYear() === now.getFullYear();
      
      if (isThisYear) {
        return date.toLocaleDateString('en-US', { 
          month: 'short', day: '2-digit', hour: '2-digit', minute: '2-digit', hour12: false
        });
      }
      return date.toLocaleDateString('en-US', { 
        year: 'numeric', month: 'short', day: '2-digit'
      });
    },
  },
};
</script>

<style scoped>
.file-panel:focus {
  outline: none;
}
</style>
