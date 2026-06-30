<script setup>
import { ref, onMounted, computed } from 'vue'

const isOpen = ref(false)
const isLoading = ref(false)
const stats = ref(null)
const selectedTable = ref(null)
const tableData = ref({ columns: [], rows: [] })
const customQuery = ref('SELECT * FROM emails LIMIT 10')
const queryResult = ref(null)
const activeTab = ref('overview') // overview, tables, query

// Format bytes to human readable
const formatBytes = (bytes) => {
  if (bytes === 0) return '0 B'
  const k = 1024
  const sizes = ['B', 'KB', 'MB', 'GB']
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i]
}

// Load database stats
const loadStats = async () => {
  isLoading.value = true
  try {
    stats.value = await window.api.debug.getDbStats()
  } catch (e) {
    console.error('Failed to load stats:', e)
  }
  isLoading.value = false
}

// Load table data
const loadTableData = async (tableName) => {
  selectedTable.value = tableName
  isLoading.value = true
  try {
    tableData.value = await window.api.debug.getTableData(tableName, 50)
  } catch (e) {
    console.error('Failed to load table:', e)
  }
  isLoading.value = false
}

// Run custom query
const runQuery = async () => {
  isLoading.value = true
  try {
    queryResult.value = await window.api.debug.runQuery(customQuery.value)
  } catch (e) {
    queryResult.value = { error: e.message }
  }
  isLoading.value = false
}

// Open database folder
const openDbFolder = async () => {
  await window.api.debug.openDbFolder()
}

// Toggle panel
const toggle = () => {
  isOpen.value = !isOpen.value
  if (isOpen.value && !stats.value) {
    loadStats()
  }
}

// Keyboard shortcut: Ctrl+Shift+D
onMounted(() => {
  window.addEventListener('keydown', (e) => {
    if (e.ctrlKey && e.shiftKey && e.key === 'D') {
      e.preventDefault()
      toggle()
    }
  })
})

// Sorted tables by count
const sortedTables = computed(() => {
  if (!stats.value?.tables) return []
  return [...stats.value.tables].sort((a, b) => b.count - a.count)
})
</script>

<template>
  <!-- Debug toggle button (bottom-left) -->
  <button 
    @click="toggle"
    class="fixed bottom-4 left-4 z-50 p-2 rounded-lg bg-amber-600 hover:bg-amber-500 text-white shadow-lg transition-all"
    title="Database Debug (Ctrl+Shift+D)"
  >
    <span class="material-symbols-rounded text-lg">database</span>
  </button>

  <!-- Debug panel -->
  <Teleport to="body">
    <div 
      v-if="isOpen"
      class="fixed inset-0 z-[9999] bg-black/50 flex items-center justify-center"
      @click.self="isOpen = false"
    >
      <div class="bg-surface-800 rounded-xl shadow-2xl w-[90vw] max-w-5xl h-[80vh] flex flex-col overflow-hidden border border-surface-600">
        <!-- Header -->
        <div class="flex items-center justify-between px-4 py-3 border-b border-surface-600 bg-surface-700">
          <div class="flex items-center gap-3">
            <span class="material-symbols-rounded text-amber-400">database</span>
            <h2 class="text-white font-semibold">Database Debug Panel</h2>
            <span v-if="stats" class="text-xs text-surface-400">
              {{ formatBytes(stats.dbSize) }}
            </span>
          </div>
          <div class="flex items-center gap-2">
            <button 
              @click="loadStats"
              class="p-2 rounded hover:bg-surface-600 text-surface-300"
              :class="{ 'animate-spin': isLoading }"
            >
              <span class="material-symbols-rounded text-sm">refresh</span>
            </button>
            <button 
              @click="openDbFolder"
              class="p-2 rounded hover:bg-surface-600 text-surface-300"
              title="Open database folder"
            >
              <span class="material-symbols-rounded text-sm">folder_open</span>
            </button>
            <button 
              @click="isOpen = false"
              class="p-2 rounded hover:bg-surface-600 text-surface-300"
            >
              <span class="material-symbols-rounded text-sm">close</span>
            </button>
          </div>
        </div>

        <!-- Tabs -->
        <div class="flex border-b border-surface-600 bg-surface-750">
          <button 
            @click="activeTab = 'overview'"
            :class="[
              'px-4 py-2 text-sm font-medium transition-colors',
              activeTab === 'overview' ? 'text-amber-400 border-b-2 border-amber-400' : 'text-surface-400 hover:text-white'
            ]"
          >
            Overview
          </button>
          <button 
            @click="activeTab = 'tables'"
            :class="[
              'px-4 py-2 text-sm font-medium transition-colors',
              activeTab === 'tables' ? 'text-amber-400 border-b-2 border-amber-400' : 'text-surface-400 hover:text-white'
            ]"
          >
            Tables
          </button>
          <button 
            @click="activeTab = 'query'"
            :class="[
              'px-4 py-2 text-sm font-medium transition-colors',
              activeTab === 'query' ? 'text-amber-400 border-b-2 border-amber-400' : 'text-surface-400 hover:text-white'
            ]"
          >
            Query
          </button>
        </div>

        <!-- Content -->
        <div class="flex-1 overflow-hidden">
          <!-- Overview Tab -->
          <div v-if="activeTab === 'overview'" class="h-full overflow-auto p-4">
            <div v-if="!stats" class="text-center text-surface-400 py-8">
              <span class="material-symbols-rounded text-4xl mb-2">hourglass_empty</span>
              <p>Loading...</p>
            </div>
            
            <div v-else class="space-y-6">
              <!-- Database Info -->
              <div>
                <h3 class="text-white font-medium mb-2 flex items-center gap-2">
                  <span class="material-symbols-rounded text-sm text-amber-400">info</span>
                  Database Info
                </h3>
                <div class="bg-surface-700 rounded-lg p-3 text-sm">
                  <div class="flex justify-between py-1">
                    <span class="text-surface-400">Path:</span>
                    <span class="text-white font-mono text-xs">{{ stats.dbPath }}</span>
                  </div>
                  <div class="flex justify-between py-1">
                    <span class="text-surface-400">Size:</span>
                    <span class="text-white">{{ formatBytes(stats.dbSize) }}</span>
                  </div>
                </div>
              </div>

              <!-- Table Counts -->
              <div>
                <h3 class="text-white font-medium mb-2 flex items-center gap-2">
                  <span class="material-symbols-rounded text-sm text-amber-400">table_chart</span>
                  Table Counts
                </h3>
                <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-2">
                  <button 
                    v-for="table in sortedTables" 
                    :key="table.name"
                    @click="activeTab = 'tables'; loadTableData(table.name)"
                    class="bg-surface-700 rounded-lg p-3 text-left hover:bg-surface-600 transition-colors group"
                  >
                    <div class="text-xs text-surface-400 group-hover:text-surface-300">{{ table.name }}</div>
                    <div class="text-lg font-semibold" :class="table.count > 0 ? 'text-primary-400' : 'text-surface-500'">
                      {{ table.count.toLocaleString() }}
                    </div>
                  </button>
                </div>
              </div>

              <!-- Sync State -->
              <div>
                <h3 class="text-white font-medium mb-2 flex items-center gap-2">
                  <span class="material-symbols-rounded text-sm text-amber-400">sync</span>
                  Sync State
                </h3>
                <div class="bg-surface-700 rounded-lg overflow-hidden">
                  <table class="w-full text-sm">
                    <thead class="bg-surface-600">
                      <tr>
                        <th class="text-left p-2 text-surface-300">Entity</th>
                        <th class="text-left p-2 text-surface-300">Cursor</th>
                        <th class="text-left p-2 text-surface-300">Last Sync</th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr v-for="state in stats.syncState" :key="state.entity_type" class="border-t border-surface-600">
                        <td class="p-2 text-white font-medium">{{ state.entity_type }}</td>
                        <td class="p-2 text-surface-300 font-mono text-xs">{{ state.sync_cursor || '-' }}</td>
                        <td class="p-2 text-surface-300 text-xs">{{ state.last_sync_at || 'Never' }}</td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </div>

              <!-- Pending Queue -->
              <div v-if="stats.pendingQueue?.length > 0">
                <h3 class="text-white font-medium mb-2 flex items-center gap-2">
                  <span class="material-symbols-rounded text-sm text-amber-400">pending_actions</span>
                  Pending Queue ({{ stats.pendingQueue.length }})
                </h3>
                <div class="bg-surface-700 rounded-lg overflow-hidden">
                  <table class="w-full text-sm">
                    <thead class="bg-surface-600">
                      <tr>
                        <th class="text-left p-2 text-surface-300">Type</th>
                        <th class="text-left p-2 text-surface-300">Action</th>
                        <th class="text-left p-2 text-surface-300">Created</th>
                        <th class="text-left p-2 text-surface-300">Attempts</th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr v-for="item in stats.pendingQueue" :key="item.id" class="border-t border-surface-600">
                        <td class="p-2 text-white">{{ item.entity_type }}</td>
                        <td class="p-2 text-surface-300">{{ item.action }}</td>
                        <td class="p-2 text-surface-300 text-xs">{{ item.created_at }}</td>
                        <td class="p-2 text-surface-300">{{ item.attempts }}</td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
          </div>

          <!-- Tables Tab -->
          <div v-if="activeTab === 'tables'" class="h-full flex">
            <!-- Table List -->
            <div class="w-48 border-r border-surface-600 overflow-y-auto bg-surface-750">
              <button 
                v-for="table in sortedTables" 
                :key="table.name"
                @click="loadTableData(table.name)"
                :class="[
                  'w-full text-left px-3 py-2 text-sm transition-colors flex justify-between items-center',
                  selectedTable === table.name ? 'bg-surface-600 text-white' : 'text-surface-300 hover:bg-surface-700'
                ]"
              >
                <span>{{ table.name }}</span>
                <span class="text-xs text-surface-500">{{ table.count }}</span>
              </button>
            </div>

            <!-- Table Data -->
            <div class="flex-1 overflow-auto">
              <div v-if="!selectedTable" class="text-center text-surface-400 py-8">
                Select a table to view data
              </div>
              <div v-else-if="isLoading" class="text-center text-surface-400 py-8">
                Loading...
              </div>
              <div v-else class="overflow-auto">
                <table class="w-full text-xs">
                  <thead class="bg-surface-700 sticky top-0">
                    <tr>
                      <th v-for="col in tableData.columns" :key="col" class="text-left p-2 text-surface-300 font-medium whitespace-nowrap">
                        {{ col }}
                      </th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="(row, i) in tableData.rows" :key="i" class="border-t border-surface-700 hover:bg-surface-700/50">
                      <td v-for="col in tableData.columns" :key="col" class="p-2 text-surface-200 max-w-xs truncate" :title="String(row[col])">
                        {{ row[col] }}
                      </td>
                    </tr>
                  </tbody>
                </table>
                <div v-if="tableData.rows.length === 0" class="text-center text-surface-400 py-8">
                  No data
                </div>
              </div>
            </div>
          </div>

          <!-- Query Tab -->
          <div v-if="activeTab === 'query'" class="h-full flex flex-col p-4">
            <div class="flex gap-2 mb-4">
              <textarea 
                v-model="customQuery"
                class="flex-1 bg-surface-700 text-white font-mono text-sm p-3 rounded-lg border border-surface-600 focus:border-amber-500 focus:outline-none resize-none"
                rows="3"
                placeholder="SELECT * FROM emails LIMIT 10"
              ></textarea>
              <button 
                @click="runQuery"
                :disabled="isLoading"
                class="px-4 bg-amber-600 hover:bg-amber-500 text-white rounded-lg font-medium disabled:opacity-50"
              >
                Run
              </button>
            </div>

            <div class="flex-1 overflow-auto">
              <div v-if="queryResult?.error" class="bg-red-900/30 border border-red-700 rounded-lg p-3 text-red-300 text-sm">
                {{ queryResult.error }}
              </div>
              <div v-else-if="queryResult" class="overflow-auto">
                <table class="w-full text-xs">
                  <thead class="bg-surface-700 sticky top-0">
                    <tr>
                      <th v-for="col in queryResult.columns" :key="col" class="text-left p-2 text-surface-300 font-medium whitespace-nowrap">
                        {{ col }}
                      </th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="(row, i) in queryResult.rows" :key="i" class="border-t border-surface-700 hover:bg-surface-700/50">
                      <td v-for="col in queryResult.columns" :key="col" class="p-2 text-surface-200 max-w-xs truncate" :title="String(row[col])">
                        {{ row[col] }}
                      </td>
                    </tr>
                  </tbody>
                </table>
                <div class="text-surface-400 text-xs mt-2">
                  {{ queryResult.rows.length }} rows
                </div>
              </div>
              <div v-else class="text-center text-surface-400 py-8">
                Run a query to see results
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </Teleport>
</template>

