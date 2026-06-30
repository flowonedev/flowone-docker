<template>
  <div class="h-full flex flex-col overflow-hidden">
    <!-- Header with filters -->
    <div class="px-3 py-3 border-b border-surface-200 dark:border-surface-700 flex-shrink-0">
      <!-- Status filter tabs - wrap to multiple lines if needed -->
      <div class="flex flex-wrap items-center gap-1.5 mb-3">
        <button
          v-for="filter in statusFilters"
          :key="filter.value"
          @click="setStatusFilter(filter.value)"
          :class="[
            'px-2.5 py-1 rounded-full text-xs font-medium transition-colors whitespace-nowrap',
            statusFilter === filter.value
              ? 'bg-primary-500 text-white'
              : 'bg-surface-100 text-surface-700 hover:bg-surface-200 dark:bg-surface-700 dark:text-surface-300 dark:hover:bg-surface-600'
          ]"
        >
          {{ filter.label }}
          <span 
            v-if="filter.count > 0"
            :class="[
              'ml-1 px-1.5 py-0.5 rounded-full text-xs',
              statusFilter === filter.value
                ? 'bg-primary-400 text-white'
                : 'bg-surface-200 text-surface-600 dark:bg-surface-600 dark:text-surface-300'
            ]"
          >
            {{ filter.count }}
          </span>
        </button>
      </div>

      <!-- Row 1: Sort & View mode -->
      <div class="flex items-center gap-2 mb-2">
        <select
          v-model="localSortBy"
          @change="handleSortChange"
          class="flex-1 min-w-0 h-8 text-xs border border-surface-300 dark:border-surface-600 rounded-lg px-2 bg-white dark:bg-surface-800 text-surface-900 dark:text-surface-100 focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
        >
          <option value="status">Sort by Status</option>
          <option value="activity">Sort by Activity</option>
          <option value="name">Sort by Name</option>
        </select>

        <!-- View Toggle -->
        <div class="flex bg-surface-100 dark:bg-surface-700 rounded-lg h-8 items-center p-0.5 flex-shrink-0">
          <button
            @click="viewMode = 'cards'"
            :class="[
              'h-7 w-7 flex items-center justify-center rounded transition-colors',
              viewMode === 'cards'
                ? 'bg-white dark:bg-surface-600 text-primary-600 shadow-sm'
                : 'text-surface-500 hover:text-surface-700'
            ]"
            title="Card View"
          >
            <span class="material-symbols-rounded text-sm">view_agenda</span>
          </button>
          <button
            @click="viewMode = 'compact'"
            :class="[
              'h-7 w-7 flex items-center justify-center rounded transition-colors',
              viewMode === 'compact'
                ? 'bg-white dark:bg-surface-600 text-primary-600 shadow-sm'
                : 'text-surface-500 hover:text-surface-700'
            ]"
            title="Compact View"
          >
            <span class="material-symbols-rounded text-sm">table_rows</span>
          </button>
        </div>
      </div>

      <!-- Row 2: Actions -->
      <div class="flex items-center gap-2">
        <router-link
          to="/clients/overview"
          class="flex items-center justify-center gap-1.5 h-8 px-2.5 text-xs font-medium bg-surface-100 text-surface-700 hover:bg-surface-200 dark:bg-surface-700 dark:text-surface-300 dark:hover:bg-surface-600 rounded-lg transition-colors flex-shrink-0"
          title="Clients Overview Table"
        >
          <span class="material-symbols-rounded text-sm">monitoring</span>
          <span>Overview</span>
        </router-link>

        <button
          @click="handleSync"
          :disabled="syncing"
          class="flex items-center justify-center gap-1.5 h-8 px-2.5 text-xs font-medium bg-primary-500 text-white hover:bg-primary-600 rounded-lg transition-colors disabled:opacity-50 whitespace-nowrap flex-shrink-0 ml-auto"
          title="Sync clients from boards"
        >
          <span 
            class="material-symbols-rounded text-sm"
            :class="{ 'animate-spin': syncing }"
          >sync</span>
          Sync
        </button>
      </div>
    </div>

    <!-- Client list -->
    <div class="flex-1 overflow-y-auto min-h-0">
      <!-- Compact View -->
      <ClientCompactList
        v-if="viewMode === 'compact'"
        :selected-id="selectedId"
        @select="$emit('select', $event)"
      />
      
      <!-- Cards View -->
      <template v-else>
        <!-- Loading state -->
        <div v-if="loading" class="p-8 text-center">
          <span class="material-symbols-rounded text-4xl text-surface-400 animate-spin">sync</span>
          <p class="mt-2 text-surface-500 dark:text-surface-400">Loading clients...</p>
        </div>

        <!-- Empty state -->
        <div v-else-if="clients.length === 0" class="p-8 text-center">
          <span class="material-symbols-rounded text-5xl text-surface-300 dark:text-surface-600">groups</span>
        <p class="mt-2 text-surface-500 dark:text-surface-400">No clients yet</p>
        <p class="text-sm text-surface-400 dark:text-surface-500">
          Clients are created from emails linked to boards
        </p>
        <button
          @click="handleSync"
          :disabled="syncing"
          class="mt-4 px-4 py-2 bg-primary-500 text-white rounded-full hover:bg-primary-600 transition-colors disabled:opacity-50"
        >
          Sync from Boards
        </button>
      </div>

      <!-- Client items -->
      <div v-else class="divide-y divide-surface-100 dark:divide-surface-700">
        <div
          v-for="client in clients"
          :key="client.id"
          @click="$emit('select', client)"
          :class="[
            'group px-4 py-3 cursor-pointer transition-colors',
            selectedId === client.id
              ? 'bg-primary-50 dark:bg-primary-500/10'
              : 'hover:bg-surface-50 dark:hover:bg-surface-800'
          ]"
        >
          <div class="flex items-start gap-3">
            <!-- Avatar / Domain icon -->
            <div 
              :class="[
                'w-10 h-10 rounded-lg flex items-center justify-center flex-shrink-0',
                getAvatarColor(client.domain)
              ]"
            >
              <span class="material-symbols-rounded text-xl">
                {{ isPersonalEmail(client.domain) ? 'person' : 'domain' }}
              </span>
            </div>

            <!-- Client info -->
            <div class="flex-1 min-w-0">
              <div class="flex items-center justify-between gap-2">
                <h3 class="font-medium text-surface-900 dark:text-surface-100 truncate">
                  {{ client.display_name || client.domain }}
                </h3>
                <button
                  @click.stop="handleDelete(client)"
                  class="p-1 opacity-0 group-hover:opacity-100 text-surface-400 hover:text-red-500 transition-all rounded"
                  title="Remove client"
                >
                  <span class="material-symbols-rounded text-lg">close</span>
                </button>
              </div>

              <p class="text-sm text-surface-500 dark:text-surface-400 truncate">
                {{ client.domain }}
              </p>

              <div class="flex items-center flex-wrap gap-x-3 gap-y-1 mt-1.5 text-xs">
                <!-- Status badge -->
                <span 
                  :class="[
                    'inline-flex items-center gap-1 px-1.5 py-0.5 rounded-full font-medium',
                    client.status === 'active' ? 'bg-green-100 text-green-700 dark:bg-green-500/20 dark:text-green-400' : '',
                    client.status === 'waiting' ? 'bg-yellow-100 text-yellow-700 dark:bg-yellow-500/20 dark:text-yellow-400' : '',
                    client.status === 'attention' ? 'bg-red-100 text-red-700 dark:bg-red-500/20 dark:text-red-400' : ''
                  ]"
                  :title="getStatusTooltip(client.status, client.last_activity_at)"
                >
                  <span class="w-1.5 h-1.5 rounded-full" :class="[
                    client.status === 'active' ? 'bg-green-500' : '',
                    client.status === 'waiting' ? 'bg-yellow-500' : '',
                    client.status === 'attention' ? 'bg-red-500' : ''
                  ]"></span>
                  {{ getStatusBadge(client.status) }}
                </span>

                <!-- We sent (outbound) -->
                <span 
                  v-if="client.last_outbound_at" 
                  class="flex items-center gap-1 text-surface-500 dark:text-surface-400"
                  title="When we last emailed them"
                >
                  <span class="material-symbols-rounded text-sm">outgoing_mail</span>
                  {{ getTimeElapsed(client.last_outbound_at) }}
                </span>

                <!-- They replied (inbound) -->
                <span 
                  v-if="client.last_inbound_at" 
                  :class="[
                    'flex items-center gap-1',
                    client.status === 'attention' ? 'text-red-500' : 'text-surface-500 dark:text-surface-400'
                  ]"
                  title="When they last replied"
                >
                  <span class="material-symbols-rounded text-sm">mark_email_read</span>
                  {{ getTimeElapsed(client.last_inbound_at) }}
                </span>

                <!-- Task count -->
                <span v-if="client.open_task_count > 0" class="flex items-center gap-1 text-surface-500 dark:text-surface-400">
                  <span class="material-symbols-rounded text-sm">task_alt</span>
                  {{ client.open_task_count }}
                  <span v-if="client.overdue_task_count > 0" class="text-red-500">
                    ({{ client.overdue_task_count }} overdue)
                  </span>
                </span>

                <!-- Contact count -->
                <span v-if="client.contact_count" class="flex items-center gap-1 text-surface-500 dark:text-surface-400">
                  <span class="material-symbols-rounded text-sm">person</span>
                  {{ client.contact_count }}
                </span>
              </div>
            </div>
          </div>
        </div>
      </div>
      </template>
    </div>
    
    <!-- Delete Confirmation Modal -->
    <div 
      v-if="showDeleteModal" 
      class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4"
      @click.self="cancelDelete"
    >
      <div class="bg-white dark:bg-surface-800 rounded-xl shadow-xl w-full max-w-md p-6">
        <div class="flex items-center gap-3 mb-4">
          <div class="w-10 h-10 rounded-full bg-red-100 dark:bg-red-500/20 flex items-center justify-center">
            <span class="material-symbols-rounded text-red-600 dark:text-red-400">delete</span>
          </div>
          <h3 class="text-lg font-semibold text-surface-900 dark:text-surface-100">Remove Client</h3>
        </div>
        <p class="text-surface-600 dark:text-surface-400 mb-4">
          Are you sure you want to remove <strong class="text-surface-900 dark:text-surface-100">{{ clientToDelete?.display_name || clientToDelete?.domain }}</strong> from your clients? This won't delete any emails or board data.
        </p>
        
        <!-- Confirmation input -->
        <div class="mb-6">
          <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
            Type <span class="font-mono bg-red-100 dark:bg-red-500/20 text-red-600 dark:text-red-400 px-1.5 py-0.5 rounded">DELETE</span> to confirm
          </label>
          <input 
            v-model="deleteConfirmText"
            type="text"
            placeholder="Type DELETE"
            class="w-full px-4 py-2 border border-surface-300 dark:border-surface-600 rounded-lg bg-white dark:bg-surface-900 text-surface-900 dark:text-surface-100 focus:ring-2 focus:ring-red-500 focus:border-red-500 placeholder:text-surface-400"
            @keydown.enter="deleteConfirmText === 'DELETE' && confirmDelete()"
          />
        </div>
        
        <div class="flex justify-end gap-2">
          <button 
            @click="cancelDelete"
            class="px-4 py-2 text-surface-600 dark:text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700 rounded-lg transition-colors"
          >
            Cancel
          </button>
          <button 
            @click="confirmDelete"
            :disabled="deleting || deleteConfirmText !== 'DELETE'"
            class="px-4 py-2 bg-red-500 text-white rounded-lg hover:bg-red-600 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
          >
            {{ deleting ? 'Removing...' : 'Remove' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import { useClientsStore } from '@/stores/clients';
import ClientCompactList from './ClientCompactList.vue';

const props = defineProps({
  selectedId: {
    type: Number,
    default: null
  }
});

const emit = defineEmits(['select', 'delete']);

const clientsStore = useClientsStore();
const localSortBy = ref(clientsStore.sortBy);
const viewMode = ref(localStorage.getItem('clientViewMode') || 'cards'); // 'cards' or 'compact'
const showDeleteModal = ref(false);
const clientToDelete = ref(null);
const deleting = ref(false);
const deleteConfirmText = ref('');

// Persist view mode
watch(viewMode, (mode) => {
  localStorage.setItem('clientViewMode', mode);
});

// Computed
const loading = computed(() => clientsStore.loading);
const syncing = computed(() => clientsStore.syncing);
const clients = computed(() => clientsStore.filteredClients);
const statusFilter = computed(() => clientsStore.statusFilter);

const statusFilters = computed(() => [
  { value: null, label: 'All', count: clientsStore.counts.total },
  { value: 'attention', label: 'Attention', count: clientsStore.counts.attention },
  { value: 'waiting', label: 'Waiting', count: clientsStore.counts.waiting },
  { value: 'active', label: 'Active', count: clientsStore.counts.active }
]);

// Methods
function setStatusFilter(status) {
  clientsStore.setStatusFilter(status);
}

function handleSortChange() {
  clientsStore.setSortBy(localSortBy.value);
}

function handleSync() {
  clientsStore.syncClients();
}


function handleDelete(client) {
  clientToDelete.value = client;
  deleteConfirmText.value = '';
  showDeleteModal.value = true;
}

function cancelDelete() {
  showDeleteModal.value = false;
  clientToDelete.value = null;
  deleteConfirmText.value = '';
}

async function confirmDelete() {
  if (!clientToDelete.value || deleteConfirmText.value !== 'DELETE') return;
  
  deleting.value = true;
  await clientsStore.deleteClient(clientToDelete.value.id);
  emit('delete', clientToDelete.value);
  deleting.value = false;
  showDeleteModal.value = false;
  clientToDelete.value = null;
  deleteConfirmText.value = '';
}

function formatLastActivity(dateStr) {
  return clientsStore.formatLastActivity(dateStr);
}

function getInitials(name) {
  if (!name) return '?';
  const parts = name.split(/[@.\s]+/);
  if (parts.length >= 2) {
    return (parts[0][0] + parts[1][0]).toUpperCase();
  }
  return name.substring(0, 2).toUpperCase();
}

function getAvatarColor(domain) {
  // Consistent accent color with soft border style
  return 'bg-primary-500/10 border border-primary-500/30 text-primary-500 dark:bg-primary-500/20 dark:border-primary-500/40 dark:text-primary-400';
}

// Check if domain is a personal/generic email provider
const genericDomains = ['gmail.com', 'googlemail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'live.com', 'msn.com', 'icloud.com', 'me.com', 'aol.com', 'mail.com', 'protonmail.com', 'proton.me'];
function isPersonalEmail(domain) {
  if (!domain) return false;
  // If domain contains @, it's a full email - check the domain part
  const domainPart = domain.includes('@') ? domain.split('@')[1] : domain;
  return genericDomains.includes(domainPart.toLowerCase());
}

function getTimeElapsed(dateStr) {
  if (!dateStr) return null;
  
  const date = new Date(dateStr);
  const now = new Date();
  const diffMs = now - date;
  const diffMins = Math.floor(diffMs / 60000);
  const diffHours = Math.floor(diffMs / 3600000);
  const diffDays = Math.floor(diffMs / 86400000);
  
  if (diffMins < 1) return 'just now';
  if (diffMins < 60) return `${diffMins}m`;
  if (diffHours < 24) return `${diffHours}h`;
  if (diffDays === 1) return '1 day';
  if (diffDays < 7) return `${diffDays} days`;
  if (diffDays < 14) return '1 week';
  if (diffDays < 30) return `${Math.floor(diffDays / 7)} weeks`;
  return `${Math.floor(diffDays / 30)} months`;
}

function getStatusBadge(status) {
  switch (status) {
    case 'active':
      return 'Active';
    case 'waiting':
      return 'Waiting';
    case 'attention':
      return 'Attention';
    default:
      return status;
  }
}

function getStatusLabel(status, lastActivityAt) {
  const timeElapsed = getTimeElapsed(lastActivityAt);
  
  switch (status) {
    case 'active':
      return timeElapsed ? `Active ${timeElapsed} ago` : 'Active';
    case 'waiting':
      return timeElapsed ? `Waiting ${timeElapsed}` : 'Waiting';
    case 'attention':
      return timeElapsed ? `${timeElapsed} no reply` : 'Needs attention';
    default:
      return status;
  }
}

function getStatusTooltip(status, lastActivityAt) {
  const timeElapsed = getTimeElapsed(lastActivityAt);
  const timeInfo = timeElapsed ? ` - ${timeElapsed}` : '';
  
  switch (status) {
    case 'active':
      return `Active - Client responded${timeInfo}`;
    case 'waiting':
      return `Waiting - You emailed them${timeInfo}`;
    case 'attention':
      return `Attention - No reply in 14+ days${timeInfo}`;
    default:
      return 'Unknown status';
  }
}
</script>
