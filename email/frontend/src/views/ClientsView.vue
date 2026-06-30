<template>
  <div class="h-[100dvh] flex flex-col bg-surface-50 dark:bg-surface-900 ambient-tint" :class="isMobile ? 'overflow-y-auto pb-20' : 'overflow-hidden'">
    <!-- Header -->
    <AppHeader
      current-view="clients"
      icon="groups"
      :title="$t('clientsView.clients')"
    />

    <!-- Main content -->
    <div class="flex-1 flex overflow-hidden">
      <!-- Client List Sidebar -->
      <!-- On mobile: show full width when no client selected or when showList is true -->
      <!-- On desktop: always show as sidebar -->
      <div 
        :class="[
          'flex-shrink-0 border-r border-surface-200 dark:border-surface-700 bg-white dark:bg-[rgb(var(--color-surface))] transition-all duration-300 flex flex-col',
          isMobile 
            ? (showList ? 'w-full' : 'w-0 overflow-hidden') 
            : (showList ? 'w-72 lg:w-80' : 'w-0 overflow-hidden')
        ]"
      >
        <ClientList 
          :selectedId="selectedClientId"
          @select="selectClient"
          @delete="handleClientDeleted"
        />
      </div>

      <!-- Desktop sidebar toggle button -->
      <button
        v-if="!isMobile"
        @click="showList = !showList"
        class="flex-shrink-0 w-5 flex items-center justify-center group hover:bg-surface-100 dark:hover:bg-surface-800 transition-colors border-r border-surface-200 dark:border-surface-700 cursor-pointer"
        :title="showList ? $t('clientsView.hideSidebar') : $t('clientsView.showSidebar')"
      >
        <span 
          class="material-symbols-rounded text-sm text-surface-300 group-hover:text-surface-600 dark:group-hover:text-surface-300 transition-all duration-200"
          :class="showList ? '' : 'rotate-180'"
        >chevron_left</span>
      </button>

      <!-- Client Snapshot -->
      <!-- On mobile: hide when showing list -->
      <div 
        v-if="!isMobile || !showList"
        class="flex-1 flex flex-col min-w-0 bg-surface-50 dark:bg-surface-900"
      >
        <!-- Mobile back button -->
        <div v-if="isMobile && selectedClientId" class="flex items-center gap-2 px-4 py-3 border-b border-surface-200 dark:border-surface-700 bg-white dark:bg-[rgb(var(--color-surface))]">
          <button
            @click="showList = true"
            class="p-2 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-800"
          >
            <span class="material-symbols-rounded">arrow_back</span>
          </button>
          <span class="text-sm font-medium text-surface-900 dark:text-surface-100">
            {{ $t('clientsView.clientDetails') }}
          </span>
        </div>

        <!-- Snapshot panel -->
        <ClientSnapshot 
          :clientId="selectedClientId"
          @openBoard="handleOpenBoard"
        />
      </div>
    </div>

    <!-- ComposeWindow is now rendered globally in App.vue -->
    
    <!-- Mobile Bottom Navigation -->
    <MobileBottomNav v-if="isMobile" />
  </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted, watch, computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useClientsStore } from '@/stores/clients';
import { useThemeStore } from '@/stores/theme';
import { useAccountsStore } from '@/stores/accounts';
import { useAuthStore } from '@/stores/auth';
import { useComposeStore } from '@/stores/compose';
import AppHeader from '@/components/shared/AppHeader.vue';
import ClientList from '@/components/clients/ClientList.vue';
import ClientSnapshot from '@/components/clients/ClientSnapshot.vue';
// ComposeModal moved to App.vue as ComposeWindow for cross-view persistence
import MobileBottomNav from '@/components/MobileBottomNav.vue';

const route = useRoute();
const router = useRouter();
const clientsStore = useClientsStore();
const theme = useThemeStore();
const accountsStore = useAccountsStore();
const authStore = useAuthStore();
const composeStore = useComposeStore();

// State - sidebar collapsed by default, persisted in localStorage
const showList = ref(localStorage.getItem('clientSidebarOpen') === 'true');
const showAccountDropdown = ref(false);
const isMobile = ref(false);

// Persist sidebar state
watch(showList, (val) => {
  localStorage.setItem('clientSidebarOpen', val ? 'true' : 'false');
});

// Accent color mapping
const accentColorMap = {
  green: '#22c55e',
  red: '#ef4444',
  purple: '#a855f7',
  blue: '#3b82f6',
  gold: '#eab308',
  mono: '#404040',
  teal: '#14b8a6',
  orange: '#f97316',
  gradient: '#a855f7',
};

// Computed
const selectedClientId = computed(() => {
  const id = route.params.id;
  return id ? parseInt(id) : null;
});

const allAccounts = computed(() => {
  const primaryAccount = {
    id: 'primary',
    account_email: authStore.userEmail,
    display_name: authStore.displayName,
    is_primary: true,
    is_default: accountsStore.accounts.length === 0,
  };
  return [primaryAccount, ...accountsStore.accounts];
});

const currentAccount = computed(() => {
  if (!accountsStore.activeAccountId || accountsStore.activeAccountId === 'primary') {
    return allAccounts.value[0];
  }
  return allAccounts.value.find(a => a.id === parseInt(accountsStore.activeAccountId)) || allAccounts.value[0];
});

// Methods
function getAccountInitials(account) {
  const name = account.display_name || account.account_email;
  return name.substring(0, 2).toUpperCase();
}

function getAccountAccentColor(account) {
  const accountId = account.id === 'primary' ? 'primary' : account.id;
  const accentId = localStorage.getItem(`webmail_accent_${accountId}`) 
    || localStorage.getItem('webmail_accent') 
    || 'green';
  return accentColorMap[accentId] || accentColorMap.green;
}

function getAccountAvatarStyle(account) {
  return { backgroundColor: getAccountAccentColor(account) };
}

function switchAccount(account) {
  accountsStore.setActiveAccount(account.id);
  showAccountDropdown.value = false;
}

function logout() {
  authStore.logout();
  router.push('/login');
}

function selectClient(client) {
  router.push(`/clients/${client.id}`);
  
  // On mobile, hide the list after selection
  if (isMobile.value) {
    showList.value = false;
  }
}

function handleClientDeleted(client) {
  // If the deleted client was selected, clear selection
  if (selectedClientId.value === client.id) {
    router.push('/clients');
  }
}

function handleOpenBoard(boardId) {
  router.push(`/boards/${boardId}`);
}

function checkMobile() {
  isMobile.value = window.innerWidth < 768;
  if (isMobile.value && !selectedClientId.value) {
    // On mobile with no selection, show list
    showList.value = true;
  }
}

// Lifecycle
onMounted(async () => {
  checkMobile();
  window.addEventListener('resize', checkMobile);
  
  await clientsStore.fetchClients();
  
  // Auto-sync on first visit if no clients
  if (clientsStore.counts.total === 0) {
    await clientsStore.syncClients();
  }
  
  // Handle responsive behavior
  if (isMobile.value && selectedClientId.value) {
    showList.value = false;
  }
});

onUnmounted(() => {
  window.removeEventListener('resize', checkMobile);
});

// Watch route changes
watch(() => route.params.id, (newId) => {
  if (newId && isMobile.value) {
    showList.value = false;
  }
});
</script>
