<template>
  <div class="h-full overflow-y-auto">
    <!-- Loading -->
    <div v-if="loading" class="flex items-center justify-center h-full">
      <span class="material-symbols-rounded text-4xl text-surface-400 animate-spin">sync</span>
    </div>
    
    <!-- Empty state -->
    <div v-else-if="!clientId" class="flex flex-col items-center justify-center h-full text-center px-8">
      <span class="material-symbols-rounded text-6xl text-surface-300 dark:text-surface-600 mb-4">groups</span>
      <h3 class="text-lg font-medium text-surface-700 dark:text-surface-300">Select a client to view details</h3>
      <p class="text-sm text-surface-500 dark:text-surface-400 mt-1">
        Choose a client from the list to see their overview
      </p>
    </div>
    
    <!-- Client Snapshot -->
    <div v-else-if="clientData" class="p-3 md:p-6 space-y-4 md:space-y-6">
      <!-- Header + Client Info (combined) -->
      <div class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border border-surface-200 dark:border-surface-700 p-4 md:p-6">
        <div class="flex items-start justify-between gap-3 md:gap-4">
          <div class="flex items-center gap-3 md:gap-4">
            <div 
              :class="[
                'w-12 md:w-16 h-12 md:h-16 rounded-xl flex items-center justify-center flex-shrink-0',
                getAvatarColor(clientData.domain)
              ]"
            >
              <span class="material-symbols-rounded text-2xl md:text-3xl">
                {{ isPersonalEmail(clientData.domain) ? 'person' : 'domain' }}
              </span>
            </div>
            <div class="min-w-0">
              <h2 class="text-lg md:text-2xl font-bold text-surface-900 dark:text-surface-100 truncate">
                {{ clientData.display_name || clientData.domain }}
              </h2>
              <p class="text-xs md:text-sm text-surface-500 dark:text-surface-400 truncate">
                {{ clientData.domain }}
              </p>
              <!-- Merged domain aliases -->
              <div v-if="clientData.domain_aliases?.length > 0" class="flex items-center gap-1 mt-0.5 flex-wrap">
                <span class="material-symbols-rounded text-[10px] text-surface-400">merge</span>
                <span 
                  v-for="alias in clientData.domain_aliases" 
                  :key="alias.id"
                  class="group inline-flex items-center gap-1 px-1.5 py-0.5 rounded-full bg-surface-100 dark:bg-surface-700 text-[10px] text-surface-500 dark:text-surface-400"
                >
                  {{ alias.alias_domain }}
                  <button 
                    @click.stop="removeAlias(alias.id, alias.alias_domain)"
                    class="hidden group-hover:inline-flex w-3 h-3 items-center justify-center rounded-full hover:bg-red-100 dark:hover:bg-red-900/30 hover:text-red-500 transition-colors"
                    title="Unlink this domain (will create its own client on next sync)"
                  >
                    <span class="material-symbols-rounded text-[10px]">close</span>
                  </button>
                </span>
              </div>
              <div class="flex items-center gap-2 md:gap-3 mt-1.5 md:mt-2 flex-wrap">
                <ClientStatusBadge 
                  :status="clientData.status || 'active'" 
                  :lastActivityAt="clientData.last_activity_at"
                  :showTime="true"
                />
                <span class="text-[10px] md:text-xs text-surface-400 dark:text-surface-500 flex items-center gap-1 hidden md:flex">
                  <span class="material-symbols-rounded text-xs md:text-sm">schedule</span>
                  Last active {{ formatLastActivity(clientData.last_activity_at) }}
                </span>
              </div>
            </div>
          </div>
          
          <!-- Edit buttons -->
          <div class="flex items-center gap-0.5 md:gap-1 flex-shrink-0">
            <button 
              @click="showEditModal = true"
              class="p-1.5 md:p-2 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-800 text-surface-500 dark:text-surface-400"
              title="Edit client info"
            >
              <span class="material-symbols-rounded text-lg md:text-xl">edit</span>
            </button>
            <button 
              @click="showMergeModal = true"
              class="p-1.5 md:p-2 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-800 text-surface-500 dark:text-surface-400"
              title="Merge with another client"
            >
              <span class="material-symbols-rounded text-lg md:text-xl">merge</span>
            </button>
          </div>
        </div>

        <!-- Client Info details (inline within header card) -->
        <div v-if="clientData?.phone || clientData?.address || clientData?.hourly_rate || clientData?.payment_terms_days || clientData?.billing_name || clientData?.billing_tax_id || clientData?.notes" class="mt-4 pt-4 border-t border-surface-200 dark:border-surface-700">
          <div class="flex flex-wrap gap-x-6 gap-y-3">
            <div v-if="clientData?.phone" class="flex items-center gap-2">
              <span class="material-symbols-rounded text-sm text-surface-400">phone</span>
              <span class="text-sm text-surface-700 dark:text-surface-300">{{ clientData.phone }}</span>
            </div>
            <div v-if="clientData?.address" class="flex items-center gap-2">
              <span class="material-symbols-rounded text-sm text-surface-400">location_on</span>
              <span class="text-sm text-surface-700 dark:text-surface-300">{{ clientData.address }}</span>
            </div>
            <div v-if="clientData?.hourly_rate" class="flex items-center gap-2">
              <span class="material-symbols-rounded text-sm text-surface-400">timer</span>
              <span class="text-sm text-surface-700 dark:text-surface-300">{{ formatHourlyRate(clientData.hourly_rate, clientData.currency) }}/hr</span>
            </div>
            <div v-if="clientData?.payment_terms_days && clientData.payment_terms_days !== 30" class="flex items-center gap-2">
              <span class="material-symbols-rounded text-sm text-surface-400">payments</span>
              <span class="text-sm text-surface-700 dark:text-surface-300">{{ clientData.payment_terms_days }} day terms</span>
            </div>
            <div v-if="clientData?.billing_name" class="flex items-center gap-2">
              <span class="material-symbols-rounded text-sm text-surface-400">domain</span>
              <span class="text-sm text-surface-700 dark:text-surface-300">{{ clientData.billing_name }}</span>
            </div>
            <div v-if="clientData?.billing_tax_id" class="flex items-center gap-2">
              <span class="material-symbols-rounded text-sm text-surface-400">tag</span>
              <span class="text-sm text-surface-700 dark:text-surface-300 font-mono">{{ clientData.billing_tax_id }}</span>
            </div>
            <div v-if="clientData?.billing_address || clientData?.billing_city" class="flex items-center gap-2">
              <span class="material-symbols-rounded text-sm text-surface-400">location_city</span>
              <span class="text-sm text-surface-700 dark:text-surface-300">
                {{ [clientData.billing_zip, clientData.billing_city, clientData.billing_address].filter(Boolean).join(', ') }}
                <span v-if="clientData.billing_country && clientData.billing_country !== 'HU'" class="text-surface-400"> ({{ clientData.billing_country }})</span>
              </span>
            </div>
            <div v-if="clientData?.billing_eu_tax_id" class="flex items-center gap-2">
              <span class="material-symbols-rounded text-sm text-surface-400">euro</span>
              <span class="text-sm text-surface-700 dark:text-surface-300 font-mono">{{ clientData.billing_eu_tax_id }}</span>
            </div>
            <div v-if="clientData?.billing_bank_account" class="flex items-center gap-2">
              <span class="material-symbols-rounded text-sm text-surface-400">account_balance</span>
              <span class="text-sm text-surface-700 dark:text-surface-300 font-mono">{{ clientData.billing_bank_account }}</span>
            </div>
          </div>
          <div v-if="clientData?.notes" class="mt-3 pt-3 border-t border-surface-100 dark:border-surface-700/50">
            <p class="text-xs text-surface-400 mb-0.5">Notes</p>
            <p class="text-sm text-surface-700 dark:text-surface-300 whitespace-pre-line">{{ clientData.notes }}</p>
          </div>
        </div>
      </div>
      
      <!-- Tab Navigation -->
      <div class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border border-surface-200 dark:border-surface-700 p-1.5 flex gap-1 overflow-x-auto">
        <button
          v-for="tab in availableTabs"
          :key="tab.id"
          @click="activeTab = tab.id"
          :class="[
            'flex items-center gap-1.5 px-4 py-2 rounded-full text-xs font-medium transition-all',
            activeTab === tab.id
              ? 'bg-primary-500 text-white shadow-sm'
              : 'text-surface-600 dark:text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-800'
          ]"
        >
          <span class="material-symbols-rounded text-sm">{{ tab.icon }}</span>
          {{ tab.label }}
        </button>
      </div>
      
      <!-- ========== OVERVIEW TAB ========== -->
      <template v-if="activeTab === 'overview'">

      <!-- Status & Quick Actions Row -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-3 md:gap-4">
        <!-- Status -->
        <div 
          class="rounded-xl border p-3 md:p-4 transition-colors flex items-center gap-3 md:gap-4"
          :class="getStatusBoxClasses()"
        >
          <div class="flex-shrink-0 w-10 md:w-12 h-10 md:h-12 rounded-full flex items-center justify-center" :class="getStatusBadgeClasses()">
            <span class="material-symbols-rounded text-xl md:text-2xl" :class="getStatusIconClasses()">{{ getStatusIcon() }}</span>
          </div>
          <div class="flex-1 min-w-0">
            <p class="font-semibold text-sm md:text-lg truncate" :class="getStatusTextClasses()">{{ responsibility }}</p>
            <p v-if="clientData?.last_email_direction" class="text-xs md:text-sm opacity-70 truncate">
              {{ clientData?.last_email_direction === 'outbound' ? 'Last contact was from us' : 'Last contact was from them' }}
            </p>
          </div>
        </div>
        
        <!-- Quick Actions -->
        <div class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border border-surface-200 dark:border-surface-700 p-3 md:p-4 flex items-center gap-2 md:gap-3">
          <button 
            @click="viewEmails"
            class="flex-1 flex flex-col items-center gap-0.5 md:gap-1 p-2 md:p-3 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-800 transition-colors"
            title="View all emails with this client"
          >
            <span class="material-symbols-rounded text-xl md:text-2xl text-surface-600 dark:text-surface-400">mail</span>
            <span class="text-[10px] md:text-xs text-surface-500">Emails</span>
          </button>
          <button 
            v-if="addons.kanbanBoardsEnabled.value && linkedBoards.length > 0"
            @click="$emit('openBoard', linkedBoards[0].board_id)"
            class="flex-1 flex flex-col items-center gap-0.5 md:gap-1 p-2 md:p-3 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-800 transition-colors"
            :title="'Open ' + (linkedBoards[0]?.board_name || 'Board')"
          >
            <span class="material-symbols-rounded text-xl md:text-2xl text-surface-600 dark:text-surface-400">dashboard</span>
            <span class="text-[10px] md:text-xs text-surface-500">Board</span>
          </button>
          <button 
            v-else
            disabled
            class="flex-1 flex flex-col items-center gap-0.5 md:gap-1 p-2 md:p-3 rounded-lg opacity-40 cursor-not-allowed"
            title="No board linked"
          >
            <span class="material-symbols-rounded text-xl md:text-2xl text-surface-400">dashboard</span>
            <span class="text-[10px] md:text-xs text-surface-500">Board</span>
          </button>
          <button 
            @click="openDriveFolder"
            class="flex-1 flex flex-col items-center gap-0.5 md:gap-1 p-2 md:p-3 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-800 transition-colors"
            :title="clientData?.drive_folder_id ? 'Open linked Drive folder' : 'Open Drive'"
          >
            <span class="material-symbols-rounded text-xl md:text-2xl text-surface-600 dark:text-surface-400">folder</span>
            <span class="text-[10px] md:text-xs text-surface-500">Drive</span>
          </button>
          <button 
            @click="composeToClient"
            class="flex-1 flex flex-col items-center gap-0.5 md:gap-1 p-2 md:p-3 rounded-lg border border-primary-500 hover:bg-primary-50 dark:hover:bg-primary-500/10 transition-colors"
            title="Compose new email to client"
          >
            <span class="material-symbols-rounded text-xl md:text-2xl text-primary-500">edit_square</span>
            <span class="text-[10px] md:text-xs text-primary-500">Compose</span>
          </button>
        </div>
      </div>
      
      <!-- Compact Stats Bar -->
      <div class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border border-surface-200 dark:border-surface-700 p-3 md:p-4">
        <div class="grid grid-cols-3 md:grid-cols-3 lg:grid-cols-6 gap-3 md:gap-4 lg:gap-6">
          <!-- Tasks -->
          <div class="flex items-center gap-2 md:gap-3">
            <div class="w-8 md:w-10 h-8 md:h-10 rounded-lg bg-surface-100 dark:bg-surface-800 flex items-center justify-center flex-shrink-0">
              <span class="material-symbols-rounded text-lg md:text-xl text-surface-500">task_alt</span>
            </div>
            <div>
              <p class="text-lg md:text-2xl font-bold text-surface-900 dark:text-surface-100">{{ boardStats.openTasks }}</p>
              <p class="text-[10px] md:text-xs text-surface-500">Tasks</p>
            </div>
          </div>
          
          <!-- Overdue -->
          <div class="flex items-center gap-2 md:gap-3">
            <div :class="['w-8 md:w-10 h-8 md:h-10 rounded-lg flex items-center justify-center flex-shrink-0', boardStats.overdueTasks > 0 ? 'bg-red-500/10' : 'bg-surface-100 dark:bg-surface-800']">
              <span :class="['material-symbols-rounded text-lg md:text-xl', boardStats.overdueTasks > 0 ? 'text-red-500' : 'text-surface-400']">warning</span>
            </div>
            <div>
              <p :class="['text-lg md:text-2xl font-bold', boardStats.overdueTasks > 0 ? 'text-red-500' : 'text-surface-400']">{{ boardStats.overdueTasks }}</p>
              <p class="text-[10px] md:text-xs text-surface-500">Overdue</p>
            </div>
          </div>
          
          <!-- Emails -->
          <div class="flex items-center gap-2 md:gap-3">
            <div class="w-8 md:w-10 h-8 md:h-10 rounded-lg bg-surface-100 dark:bg-surface-800 flex items-center justify-center flex-shrink-0">
              <span class="material-symbols-rounded text-lg md:text-xl text-surface-500">mail</span>
            </div>
            <div>
              <p class="text-lg md:text-2xl font-bold text-surface-900 dark:text-surface-100">{{ emailStats.totalEmails }}</p>
              <p class="text-[10px] md:text-xs text-surface-500">Emails</p>
            </div>
          </div>
          
          <!-- Contacts -->
          <div class="flex items-center gap-2 md:gap-3">
            <div class="w-8 md:w-10 h-8 md:h-10 rounded-lg bg-surface-100 dark:bg-surface-800 flex items-center justify-center flex-shrink-0">
              <span class="material-symbols-rounded text-lg md:text-xl text-surface-500">person</span>
            </div>
            <div>
              <p class="text-lg md:text-2xl font-bold text-surface-900 dark:text-surface-100">{{ contacts.length }}</p>
              <p class="text-[10px] md:text-xs text-surface-500">Contacts</p>
            </div>
          </div>
          
          <!-- Boards (gated by kanban_boards addon) -->
          <div v-if="addons.kanbanBoardsEnabled.value" class="flex items-center gap-2 md:gap-3">
            <div class="w-8 md:w-10 h-8 md:h-10 rounded-lg bg-surface-100 dark:bg-surface-800 flex items-center justify-center flex-shrink-0">
              <span class="material-symbols-rounded text-lg md:text-xl text-surface-500">dashboard</span>
            </div>
            <div>
              <p class="text-lg md:text-2xl font-bold text-surface-900 dark:text-surface-100">{{ linkedBoards.length }}</p>
              <p class="text-[10px] md:text-xs text-surface-500">Boards</p>
            </div>
          </div>
          
          <!-- Next Deadline -->
          <div class="flex items-center gap-2 md:gap-3">
            <div :class="['w-8 md:w-10 h-8 md:h-10 rounded-lg flex items-center justify-center flex-shrink-0', boardStats.overdueTasks > 0 ? 'bg-red-500/10' : 'bg-surface-100 dark:bg-surface-800']">
              <span :class="['material-symbols-rounded text-lg md:text-xl', boardStats.overdueTasks > 0 ? 'text-red-500' : 'text-surface-500']">event</span>
            </div>
            <div>
              <p :class="['text-sm md:text-lg font-bold truncate', boardStats.overdueTasks > 0 ? 'text-red-500' : 'text-surface-900 dark:text-surface-100']">{{ getNextDeadlineText() }}</p>
              <p class="text-[10px] md:text-xs text-surface-500">Deadline</p>
            </div>
          </div>
        </div>
      </div>
      
      <!-- Communication Timeline -->
      <div class="grid grid-cols-1 md:grid-cols-2 gap-3 md:gap-4">
        <!-- We Sent -->
        <div 
          class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border p-3 md:p-4 flex items-center gap-3 md:gap-4"
          :class="clientData?.status === 'waiting' && clientData?.last_email_direction === 'outbound' ? 'border-primary-300 dark:border-primary-500/50' : 'border-surface-200 dark:border-surface-700'"
        >
          <div class="w-10 md:w-12 h-10 md:h-12 rounded-full bg-surface-100 dark:bg-surface-800 flex items-center justify-center flex-shrink-0">
            <span class="material-symbols-rounded text-surface-500 text-xl md:text-2xl">outgoing_mail</span>
          </div>
          <div class="flex-1 min-w-0">
            <p class="text-[10px] md:text-xs text-surface-500 uppercase tracking-wide">We Sent</p>
            <p class="text-lg md:text-2xl font-bold text-surface-900 dark:text-surface-100">{{ getWaitingTime(effectiveLastOutbound) }}</p>
            <p class="text-[10px] md:text-xs text-surface-400 truncate">{{ effectiveLastOutbound ? 'Last email to client' : 'No emails sent yet' }}</p>
          </div>
        </div>
        
        <!-- They Replied -->
        <div 
          class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border p-3 md:p-4 flex items-center gap-3 md:gap-4"
          :class="{
            'border-red-300 dark:border-red-500/50': clientData?.status === 'attention',
            'border-surface-200 dark:border-surface-700': clientData?.status !== 'attention'
          }"
        >
          <div :class="['w-10 md:w-12 h-10 md:h-12 rounded-full flex items-center justify-center flex-shrink-0', clientData?.status === 'attention' ? 'bg-red-500/10' : 'bg-surface-100 dark:bg-surface-800']">
            <span :class="['material-symbols-rounded text-xl md:text-2xl', clientData?.status === 'attention' ? 'text-red-500' : 'text-surface-500']">mark_email_read</span>
          </div>
          <div class="flex-1 min-w-0">
            <p class="text-[10px] md:text-xs text-surface-500 uppercase tracking-wide">They Replied</p>
            <p :class="['text-lg md:text-2xl font-bold', clientData?.status === 'attention' ? 'text-red-500' : 'text-surface-900 dark:text-surface-100']">{{ getWaitingTime(effectiveLastInbound) }}</p>
            <p class="text-[10px] md:text-xs text-surface-400 truncate">{{ effectiveLastInbound ? 'Last response from client' : 'No replies yet' }}</p>
          </div>
        </div>
      </div>
      
      
      <!-- Associated Accounts -->
      <div v-if="associatedAccounts.length > 0" class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border border-surface-200 dark:border-surface-700 p-4">
        <h3 class="text-sm font-semibold text-surface-900 dark:text-surface-100 mb-3 flex items-center gap-2">
          <span class="material-symbols-rounded text-lg">group_add</span>
          Associated Accounts ({{ associatedAccounts.length }})
        </h3>
        <div class="space-y-2">
          <div 
            v-for="assoc in associatedAccounts" 
            :key="assoc.id"
            class="flex items-center justify-between p-2 rounded-lg bg-surface-50 dark:bg-surface-800"
          >
            <div class="flex items-center gap-3">
              <div class="w-8 h-8 rounded-full bg-amber-100 dark:bg-amber-500/20 flex items-center justify-center text-xs font-medium text-amber-600 dark:text-amber-400">
                {{ getInitials(assoc.display_name || assoc.domain) }}
              </div>
              <div>
                <p class="text-sm font-medium text-surface-900 dark:text-surface-100">{{ assoc.display_name || assoc.domain }}</p>
                <p class="text-xs text-surface-500">{{ assoc.domain }}</p>
              </div>
            </div>
            <button 
              @click="promoteAssociated(assoc.id)"
              class="text-xs px-2 py-1 rounded-full bg-primary-100 dark:bg-primary-500/20 text-primary-600 dark:text-primary-400 hover:bg-primary-200 dark:hover:bg-primary-500/30 transition-colors"
              title="Promote to full client"
            >
              Promote
            </button>
          </div>
        </div>
      </div>

      <!-- Two-column layout for Boards and Mood Boards (adapts columns based on enabled addons) -->
      <div v-if="addons.kanbanBoardsEnabled.value || addons.moodboardsEnabled.value" :class="(addons.kanbanBoardsEnabled.value && addons.moodboardsEnabled.value) ? 'grid grid-cols-1 lg:grid-cols-2 gap-4' : 'grid grid-cols-1 gap-4'">
        <!-- Linked Boards (gated by kanban_boards addon) -->
        <div v-if="addons.kanbanBoardsEnabled.value" class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border border-surface-200 dark:border-surface-700 p-4">
          <div class="flex items-center justify-between mb-3">
            <h3 class="text-sm font-semibold text-surface-900 dark:text-surface-100 flex items-center gap-2">
              <span class="material-symbols-rounded text-lg">dashboard</span>
              Linked Boards ({{ linkedBoards.length }})
            </h3>
            <div class="flex items-center gap-2">
              <button
                v-if="linkedBoards.length > 0 && !clientData?.drive_folder_id"
                @click="syncDriveFolder"
                :disabled="syncingDriveFolder"
                class="flex items-center gap-1 px-3 py-1.5 text-xs bg-primary-50 hover:bg-primary-100 dark:bg-primary-500/10 dark:hover:bg-primary-500/20 text-primary-600 dark:text-primary-400 rounded-lg transition-colors disabled:opacity-50"
                title="Sync Drive folder from linked boards"
              >
                <span class="material-symbols-rounded text-sm" :class="{ 'animate-spin': syncingDriveFolder }">sync</span>
                Sync Folder
              </button>
              <button
                @click="openLinkBoardModal"
                class="flex items-center gap-1 px-3 py-1.5 text-xs bg-surface-100 hover:bg-surface-200 dark:bg-surface-700 dark:hover:bg-surface-600 text-surface-700 dark:text-surface-300 rounded-lg transition-colors"
                title="Link a board to this client"
              >
                <span class="material-symbols-rounded text-sm">add</span>
                Link Board
              </button>
            </div>
          </div>
          <div v-if="linkedBoards.length > 0" class="space-y-2">
            <div 
              v-for="board in linkedBoards" 
              :key="board.board_id"
              class="flex items-center gap-3 p-3 rounded-lg bg-surface-50 dark:bg-surface-800 hover:bg-surface-100 dark:hover:bg-surface-700 cursor-pointer transition-colors group"
            >
              <div 
                @click="$emit('openBoard', board.board_id)"
                class="w-10 h-10 rounded-lg flex items-center justify-center text-white text-sm font-bold flex-shrink-0"
                :style="{ backgroundColor: board.background_color || '#3b82f6' }"
              >
                {{ (board.board_name || 'B').substring(0, 2).toUpperCase() }}
              </div>
              <div class="flex-1 min-w-0" @click="$emit('openBoard', board.board_id)">
                <p class="font-medium text-surface-900 dark:text-surface-100 truncate">
                  {{ board.board_name || 'Unnamed Board' }}
                </p>
              </div>
              <button
                @click.stop="unlinkBoard(board.board_id)"
                class="p-1 rounded-lg text-surface-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-500/10 opacity-0 group-hover:opacity-100 transition-all"
                title="Unlink board"
              >
                <span class="material-symbols-rounded text-lg">close</span>
              </button>
              <span @click="$emit('openBoard', board.board_id)" class="material-symbols-rounded text-surface-400">chevron_right</span>
            </div>
          </div>
          <div v-else class="text-center py-6">
            <span class="material-symbols-rounded text-3xl text-surface-300 dark:text-surface-600">dashboard_customize</span>
            <p class="text-sm text-surface-500 mt-2">No boards linked</p>
            <button
              @click="openLinkBoardModal"
              class="mt-3 px-4 py-2 text-sm bg-primary-500 hover:bg-primary-600 text-white rounded-lg transition-colors"
            >
              Link a Board
            </button>
          </div>
        </div>
        
        <!-- Linked Mood Boards (gated by moodboards addon) -->
        <div v-if="addons.moodboardsEnabled.value" class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border border-surface-200 dark:border-surface-700 p-4">
          <div class="flex items-center justify-between mb-3">
            <h3 class="text-sm font-semibold text-surface-900 dark:text-surface-100 flex items-center gap-2">
              <span class="material-symbols-rounded text-lg">dashboard_customize</span>
              Mood Boards ({{ clientMoodBoards.length }})
            </h3>
            <button
              @click="$router.push('/mood')"
              class="p-1.5 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-500 transition-colors"
              title="Open mood boards"
            >
              <span class="material-symbols-rounded text-lg">open_in_new</span>
            </button>
          </div>
          <div v-if="clientMoodBoards.length > 0" class="space-y-2">
            <div 
              v-for="mBoard in clientMoodBoards" 
              :key="mBoard.id"
              @click="$router.push('/mood/' + mBoard.id)"
              class="flex items-center gap-3 p-3 rounded-lg bg-surface-50 dark:bg-surface-800 hover:bg-surface-100 dark:hover:bg-surface-700 cursor-pointer transition-colors"
            >
              <div 
                class="w-10 h-10 rounded-lg flex items-center justify-center flex-shrink-0"
                :style="{ backgroundColor: mBoard.background_color || '#f5f5f5' }"
              >
                <span class="material-symbols-rounded text-lg text-surface-600">dashboard_customize</span>
              </div>
              <div class="flex-1 min-w-0">
                <p class="font-medium text-surface-900 dark:text-surface-100 truncate">{{ mBoard.name }}</p>
                <p class="text-xs text-surface-500">{{ mBoard.item_count || 0 }} items</p>
              </div>
              <span class="material-symbols-rounded text-surface-400">chevron_right</span>
            </div>
          </div>
          <div v-else class="text-center py-6">
            <span class="material-symbols-rounded text-3xl text-surface-300 dark:text-surface-600">dashboard_customize</span>
            <p class="text-sm text-surface-500 mt-2">No mood boards linked</p>
            <button
              @click="$router.push('/mood')"
              class="mt-3 px-4 py-2 text-sm bg-primary-500 hover:bg-primary-600 text-white rounded-full transition-colors"
            >
              Create a Mood Board
            </button>
          </div>
        </div>
      </div>

      </template><!-- END OVERVIEW TAB -->

      <!-- ========== CONTACTS TAB ========== -->
      <template v-if="activeTab === 'contacts'">

      <!-- Company Address Banner (if set) -->
      <div v-if="clientData?.address || clientData?.billing_address || clientData?.billing_city" class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border border-surface-200 dark:border-surface-700 p-4">
        <div class="flex items-center gap-4">
          <div class="w-10 h-10 rounded-lg bg-surface-100 dark:bg-surface-800 flex items-center justify-center flex-shrink-0">
            <span class="material-symbols-rounded text-xl text-surface-500">location_on</span>
          </div>
          <div class="flex-1 min-w-0">
            <p class="text-xs text-surface-500 uppercase tracking-wide font-medium mb-0.5">Company Address</p>
            <p class="text-sm text-surface-900 dark:text-surface-100">
              <template v-if="clientData?.billing_address || clientData?.billing_city">
                {{ [clientData.billing_address, [clientData.billing_zip, clientData.billing_city].filter(Boolean).join(' ')].filter(Boolean).join(', ') }}
                <span v-if="clientData.billing_country && clientData.billing_country !== 'HU'" class="text-surface-400"> ({{ clientData.billing_country }})</span>
              </template>
              <template v-else>
                {{ clientData.address }}
              </template>
            </p>
          </div>
          <div v-if="clientData?.phone" class="flex items-center gap-2 flex-shrink-0 border-l border-surface-200 dark:border-surface-700 pl-4">
            <span class="material-symbols-rounded text-sm text-surface-400">phone</span>
            <span class="text-sm text-surface-700 dark:text-surface-300">{{ clientData.phone }}</span>
          </div>
        </div>
      </div>

      <!-- Contacts Table -->
      <div class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border border-surface-200 dark:border-surface-700 overflow-hidden">
        <!-- Header -->
        <div class="px-4 py-3 border-b border-surface-200 dark:border-surface-700 flex items-center justify-between">
          <h3 class="text-sm font-semibold text-surface-900 dark:text-surface-100 flex items-center gap-2">
            <span class="material-symbols-rounded text-lg">people</span>
            Contacts ({{ contacts.length || 0 }})
          </h3>
          <div class="flex items-center gap-2">
            <button 
              @click="runMultiEmailExtraction"
              :disabled="extractingSignatures"
              class="flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium bg-surface-100 text-surface-700 hover:bg-surface-200 dark:bg-surface-700 dark:text-surface-300 dark:hover:bg-surface-600 rounded-full transition-colors"
              title="Extract phone & address from email signatures"
            >
              <span class="material-symbols-rounded text-sm" :class="{ 'animate-spin': extractingSignatures }">
                {{ extractingSignatures ? 'sync' : 'signature' }}
              </span>
              Extract Info
            </button>
            <button 
              @click="showAddContactModal = true"
              class="flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium bg-primary-500 text-white hover:bg-primary-600 rounded-full transition-colors"
            >
              <span class="material-symbols-rounded text-sm">person_add</span>
              Add Contact
            </button>
          </div>
        </div>

        <!-- Table -->
        <div v-if="contacts.length > 0" class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="bg-surface-50 dark:bg-surface-800/50 text-left">
                <th class="px-4 py-2.5 text-xs font-semibold text-surface-500 uppercase tracking-wider">Name</th>
                <th class="px-4 py-2.5 text-xs font-semibold text-surface-500 uppercase tracking-wider">Email</th>
                <th class="px-4 py-2.5 text-xs font-semibold text-surface-500 uppercase tracking-wider hidden md:table-cell">Position</th>
                <th class="px-4 py-2.5 text-xs font-semibold text-surface-500 uppercase tracking-wider hidden lg:table-cell">Phone</th>
                <th class="px-4 py-2.5 text-xs font-semibold text-surface-500 uppercase tracking-wider hidden xl:table-cell">Last Activity</th>
                <th class="px-4 py-2.5 text-xs font-semibold text-surface-500 uppercase tracking-wider text-right">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-surface-100 dark:divide-surface-700/50">
              <tr 
                v-for="contact in contacts" 
                :key="'ct-' + (contact.id || contact.email)"
                class="group hover:bg-surface-50 dark:hover:bg-surface-800/30 transition-colors"
              >
                <!-- Name -->
                <td class="px-4 py-3">
                  <div class="flex items-center gap-2.5">
                    <div class="w-8 h-8 rounded-full bg-primary-500/10 border border-primary-500/30 flex items-center justify-center text-xs font-semibold text-primary-500 dark:text-primary-400 flex-shrink-0">
                      {{ getInitials(contact.name || contact.email) }}
                    </div>
                    <div class="min-w-0">
                      <p class="font-medium text-surface-900 dark:text-surface-100 truncate">
                        {{ contact.name || contact.email.split('@')[0] }}
                      </p>
                      <span v-if="contact.is_primary" class="text-[10px] font-medium text-primary-500 bg-primary-500/10 px-1.5 py-0.5 rounded-full">Primary</span>
                    </div>
                  </div>
                </td>

                <!-- Email -->
                <td class="px-4 py-3">
                  <span class="text-surface-600 dark:text-surface-400 truncate block max-w-[200px]">{{ contact.email }}</span>
                </td>

                <!-- Position -->
                <td class="px-4 py-3 hidden md:table-cell">
                  <span v-if="contact.position" class="text-surface-600 dark:text-surface-400">{{ contact.position }}</span>
                  <span v-else class="text-surface-300 dark:text-surface-600">--</span>
                </td>

                <!-- Phone -->
                <td class="px-4 py-3 hidden lg:table-cell">
                  <span v-if="contact.phone" class="text-surface-600 dark:text-surface-400 flex items-center gap-1">
                    {{ contact.phone }}
                    <button 
                      @click.stop="copyToClipboard(contact.phone)"
                      class="p-0.5 rounded opacity-0 group-hover:opacity-100 hover:bg-surface-200 dark:hover:bg-surface-700 transition-all"
                      title="Copy phone"
                    >
                      <span class="material-symbols-rounded text-xs text-surface-400">content_copy</span>
                    </button>
                  </span>
                  <span v-else class="text-surface-300 dark:text-surface-600">--</span>
                </td>

                <!-- Last Activity -->
                <td class="px-4 py-3 hidden xl:table-cell">
                  <div v-if="contact.last_email_at && !contact.is_placeholder">
                    <p class="text-xs text-surface-500">{{ formatLastActivity(contact.last_email_at) }}</p>
                    <p v-if="contact.email_count > 1" class="text-[10px] text-surface-400">{{ contact.email_count }} emails</p>
                  </div>
                  <span v-else class="text-surface-300 dark:text-surface-600">--</span>
                </td>

                <!-- Actions -->
                <td class="px-4 py-3 text-right">
                  <div class="flex items-center justify-end gap-0.5 opacity-0 group-hover:opacity-100 transition-opacity">
                    <button 
                      @click.stop="editContact(contact)"
                      class="p-1.5 rounded-lg hover:bg-surface-200 dark:hover:bg-surface-700 text-surface-400 hover:text-surface-600 dark:hover:text-surface-300 transition-colors"
                      title="Edit contact"
                    >
                      <span class="material-symbols-rounded text-sm">edit</span>
                    </button>
                    <button 
                      v-if="!contact.is_placeholder"
                      @click.stop="composeToContact(contact)"
                      class="p-1.5 rounded-lg hover:bg-primary-100 dark:hover:bg-primary-500/10 text-primary-500 transition-colors"
                      title="Send email"
                    >
                      <span class="material-symbols-rounded text-sm">mail</span>
                    </button>
                    <button 
                      @click.stop="deleteContact(contact)"
                      class="p-1.5 rounded-lg hover:bg-red-100 dark:hover:bg-red-500/10 text-red-400 hover:text-red-500 transition-colors"
                      title="Delete contact"
                    >
                      <span class="material-symbols-rounded text-sm">delete</span>
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Empty state -->
        <div v-else class="text-center py-10">
          <span class="material-symbols-rounded text-4xl text-surface-300 dark:text-surface-600">person_off</span>
          <p class="text-sm text-surface-500 mt-2">No contacts found</p>
          <p class="text-xs text-surface-400 mt-1">Contacts appear when you exchange emails with this client</p>
          <button 
            @click="showAddContactModal = true"
            class="mt-4 px-4 py-2 text-sm bg-primary-500 hover:bg-primary-600 text-white rounded-full transition-colors"
          >
            Add First Contact
          </button>
        </div>
      </div>

      </template><!-- END CONTACTS TAB -->

      <!-- ========== ACTIVITY TAB ========== -->
      <template v-if="activeTab === 'activity'">

      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <!-- Recent Email Activity -->
        <div v-if="recentEmails.length > 0" class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border border-surface-200 dark:border-surface-700 p-4">
          <h3 class="text-sm font-semibold text-surface-900 dark:text-surface-100 mb-3 flex items-center gap-2">
            <span class="material-symbols-rounded text-lg">email</span>
            Recent Email Activity
          </h3>
          <div class="space-y-2">
            <div 
              v-for="email in recentEmails" 
              :key="'act-' + email.uid"
              class="flex items-center gap-3 p-3 rounded-lg hover:bg-surface-50 dark:hover:bg-surface-800 cursor-pointer transition-colors"
              @click="openEmail(email)"
            >
              <div :class="[
                'w-8 h-8 rounded-full flex items-center justify-center text-sm',
                email.direction === 'sent' 
                  ? 'bg-blue-100 dark:bg-blue-500/20 text-blue-600 dark:text-blue-400'
                  : 'bg-green-100 dark:bg-green-500/20 text-green-600 dark:text-green-400'
              ]">
                <span class="material-symbols-rounded text-lg">
                  {{ email.direction === 'sent' ? 'north_east' : 'south_west' }}
                </span>
              </div>
              <div class="flex-1 min-w-0">
                <p class="text-sm font-medium text-surface-900 dark:text-surface-100 truncate">
                  {{ email.subject || '(No subject)' }}
                </p>
                <p class="text-xs text-surface-500">
                  {{ email.direction === 'sent' ? 'Sent to' : 'From' }} {{ email.contact }}
                </p>
              </div>
              <span class="text-xs text-surface-400">{{ formatLastActivity(email.date) }}</span>
            </div>
          </div>
        </div>
        <div v-else class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border border-surface-200 dark:border-surface-700 p-8 text-center">
          <span class="material-symbols-rounded text-4xl text-surface-300 dark:text-surface-600">email</span>
          <p class="text-sm text-surface-500 mt-2">No recent email activity</p>
        </div>

        <!-- Activity Log -->
        <div v-if="clientData" class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border border-surface-200 dark:border-surface-700 p-4">
          <ActivityLog :client-id="clientData.id" :limit="50" />
        </div>
      </div>

      </template><!-- END ACTIVITY TAB -->

      <!-- ========== TIME TRACKING TAB ========== -->
      <template v-if="activeTab === 'timetracking'">

      <div class="space-y-4">
        <!-- Summary card with link to Time Tracker -->
        <div class="bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700 p-5">
          <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
              <div class="w-10 h-10 rounded-xl bg-indigo-100 dark:bg-indigo-500/20 flex items-center justify-center">
                <span class="material-symbols-rounded text-xl text-indigo-600 dark:text-indigo-400">schedule</span>
              </div>
              <div>
                <h3 class="text-base font-semibold text-surface-900 dark:text-surface-100">Time Tracking</h3>
                <p class="text-sm text-surface-500">
                  View detailed time breakdown for this client
                </p>
              </div>
            </div>
            <router-link
              :to="`/workload?mode=task-time&client_id=${clientData?.id}`"
              class="flex items-center gap-1.5 px-4 py-2 rounded-full bg-indigo-500 text-white text-sm font-medium hover:bg-indigo-600 transition-colors"
            >
              <span class="material-symbols-rounded text-lg">schedule</span>
              View Time
            </router-link>
          </div>
        </div>

        <!-- Keep contextual widgets: Team + Tracked Websites + Drive Index -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
          <div class="space-y-4">
            <ClientTeamCompact 
              v-if="clientData" 
              :client-id="clientData.id"
              :team-time="timeStats?.team_time"
              @updated="fetchTimeStats"
            />
          </div>
          <div class="space-y-4">
            <ClientTrackedWebsites 
              v-if="clientData && addons.kanbanBoardsEnabled.value" 
              :client-id="clientData.id"
              :linked-boards="linkedBoards"
            />
          </div>
          <div class="space-y-4">
            <ClientDriveIndex
              v-if="clientData"
              :client-id="clientData.id"
              :drive-folder-id="clientData.drive_folder_id || null"
              @change-folder="showLinkDriveModal = true"
            />
          </div>
        </div>
      </div>

      </template><!-- END TIME TRACKING TAB -->

      <!-- ========== FINANCIALS TAB (when CRM Pro NOT active) ========== -->
      <template v-if="activeTab === 'financials'">

      <!-- Financials Section (standalone) -->
      <div v-if="financials && (financials.boards?.length > 0 || financials.all_milestones?.length > 0)" class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border border-surface-200 dark:border-surface-700 p-4">
        <div class="flex items-center justify-between mb-4">
          <h3 class="text-sm font-semibold text-surface-900 dark:text-surface-100 flex items-center gap-2">
            <span class="material-symbols-rounded text-lg text-primary-500">payments</span>
            Financials
            <span v-if="financials.boards?.length > 1" class="text-xs font-normal text-surface-500">
              ({{ financials.boards.length }} projects)
            </span>
          </h3>
          <div class="text-right">
            <p class="text-xs text-surface-500">Total Expected</p>
            <div class="flex flex-wrap gap-2 justify-end">
              <p 
                v-for="(amount, currency) in financials.totals_by_currency" 
                :key="'fin-' + currency"
                class="text-lg font-bold text-primary-600 dark:text-primary-400"
              >
                {{ formatCurrencyWithCode(amount, currency) }}
              </p>
            </div>
          </div>
        </div>
        
        <div class="space-y-3">
          <div 
            v-for="board in financials.boards" 
            :key="'fin-' + board.board_id"
            class="border border-surface-200 dark:border-surface-700 rounded-lg overflow-hidden"
          >
            <button 
              @click="toggleBoardExpanded(board.board_id)"
              class="w-full flex items-center justify-between p-3 bg-surface-50 dark:bg-surface-800 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
            >
              <div class="flex items-center gap-3 min-w-0">
                <span class="material-symbols-rounded text-sm text-surface-400">
                  {{ expandedBoards[board.board_id] ? 'expand_more' : 'chevron_right' }}
                </span>
                <span class="font-medium text-surface-900 dark:text-surface-100 truncate">
                  {{ board.board_name }}
                </span>
                <div class="flex items-center gap-1.5">
                  <div class="w-16 h-1.5 bg-surface-200 dark:bg-surface-700 rounded-full overflow-hidden">
                    <div 
                      class="h-full bg-primary-500 rounded-full transition-all"
                      :style="{ width: `${getBoardProgress(board)}%` }"
                    ></div>
                  </div>
                  <span class="text-xs text-surface-500">{{ getBoardProgress(board) }}%</span>
                </div>
              </div>
              <div class="flex items-center gap-2 flex-shrink-0">
                <span 
                  v-for="(amount, currency) in board.totals_by_currency" 
                  :key="'fin-b-' + currency"
                  class="text-sm font-semibold text-surface-900 dark:text-surface-100"
                >
                  {{ formatCurrencyWithCode(amount, currency) }}
                </span>
              </div>
            </button>
            <div v-if="expandedBoards[board.board_id]" class="border-t border-surface-200 dark:border-surface-700">
              <div 
                v-for="(milestone, idx) in board.milestones" 
                :key="milestone.list_id"
                class="flex items-center justify-between p-3 hover:bg-surface-50 dark:hover:bg-surface-800/50 transition-colors"
                :class="{ 'border-t border-surface-100 dark:border-surface-700': idx > 0 }"
              >
                <div class="flex items-center gap-3 min-w-0">
                  <div 
                    :class="[
                      'w-2 h-2 rounded-full flex-shrink-0',
                      milestone.completion_percent === 100 ? 'bg-green-500' : 
                      milestone.completion_percent > 0 ? 'bg-amber-500' : 'bg-surface-300 dark:bg-surface-600'
                    ]"
                  ></div>
                  <div class="min-w-0">
                    <p class="text-sm text-surface-700 dark:text-surface-300 truncate">
                      {{ milestone.list_name }}
                      <span v-if="milestone.is_milestone" class="text-amber-500 ml-1">
                        <span class="material-symbols-rounded text-xs align-middle">flag</span>
                      </span>
                    </p>
                    <div class="flex items-center gap-3 text-xs text-surface-400">
                      <span v-if="milestone.total_todos > 0">
                        {{ milestone.completed_todos }}/{{ milestone.total_todos }} todos ({{ milestone.completion_percent }}%)
                      </span>
                      <span v-else-if="milestone.total_cards > 0">
                        {{ milestone.completed_cards }}/{{ milestone.total_cards }} cards ({{ milestone.completion_percent }}%)
                      </span>
                      <span v-else-if="milestone.completion_percent > 0">
                        {{ milestone.completion_percent }}% done
                      </span>
                      <span v-if="milestone.invoice_date" class="flex items-center gap-1">
                        <span class="material-symbols-rounded text-xs">receipt</span>
                        {{ formatDate(milestone.invoice_date) }}
                      </span>
                    </div>
                  </div>
                </div>
                <div class="text-right flex-shrink-0">
                  <p class="text-sm font-medium text-surface-900 dark:text-surface-100">
                    {{ formatCurrencyWithCode(milestone.expected_amount, milestone.currency) }}
                  </p>
                </div>
              </div>
            </div>
          </div>
        </div>
        
        <!-- Monthly Cash Flow Projection -->
        <div v-if="Object.keys(financials.monthly_projections || {}).length > 0" class="mt-4 pt-4 border-t border-surface-200 dark:border-surface-700">
          <h4 class="text-xs font-semibold text-surface-500 uppercase mb-3">Cash Flow Projection</h4>
          <div class="flex items-end gap-1 h-20 mb-2">
            <div 
              v-for="(currencies, month) in financials.monthly_projections" 
              :key="'fin-p-' + month"
              class="flex-1 flex flex-col items-center"
            >
              <div 
                class="w-full bg-primary-500/80 rounded-t transition-all hover:bg-primary-500"
                :style="{ height: `${getProjectionBarHeight(currencies)}%` }"
                :title="formatProjectionTooltip(month, currencies)"
              ></div>
            </div>
          </div>
          <div class="flex gap-1">
            <div 
              v-for="(currencies, month) in financials.monthly_projections" 
              :key="'fin-l-' + month"
              class="flex-1 text-center"
            >
              <p class="text-[10px] text-surface-500 truncate">{{ formatMonthShort(month) }}</p>
              <p class="text-xs font-medium text-surface-700 dark:text-surface-300 truncate">
                {{ formatProjectionTotal(currencies) }}
              </p>
            </div>
          </div>
        </div>
      </div>
      <div v-else class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border border-surface-200 dark:border-surface-700 p-8 text-center">
        <span class="material-symbols-rounded text-4xl text-surface-300 dark:text-surface-600">payments</span>
        <p class="text-sm text-surface-500 mt-2">No financial data yet</p>
        <p class="text-xs text-surface-400 mt-1">Link boards with milestones to track financials</p>
      </div>

      </template><!-- END FINANCIALS TAB -->

      <!-- ========== CRM PRO TAB ========== -->
      <template v-if="activeTab === 'crmpro'">

      <!-- ================================================================ -->
      <!-- CRM Pro Section (only when addon enabled) -->
      <!-- ================================================================ -->
      <template v-if="clientData?.id">
        <!-- CRM Quick Access Nav -->
        <div id="crm-section" class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border border-surface-200 dark:border-surface-700 p-3">
          <div class="flex items-center gap-2 mb-2">
            <span class="material-symbols-rounded text-lg text-primary-500">conversion_path</span>
            <h3 class="text-sm font-semibold text-surface-900 dark:text-surface-100">CRM Pro</h3>
            <div class="flex-1"></div>
            <button @click="$router.push('/crm/pipeline')" class="text-xs text-primary-600 hover:text-primary-700 dark:text-primary-400 font-medium flex items-center gap-1">
              <span class="material-symbols-rounded text-sm">view_kanban</span> Pipeline
            </button>
            <button @click="$router.push('/crm/invoices')" class="text-xs text-primary-600 hover:text-primary-700 dark:text-primary-400 font-medium flex items-center gap-1 ml-2">
              <span class="material-symbols-rounded text-sm">receipt_long</span> Invoices
            </button>
            <button @click="$router.push('/crm/dashboard')" class="text-xs text-primary-600 hover:text-primary-700 dark:text-primary-400 font-medium flex items-center gap-1 ml-2">
              <span class="material-symbols-rounded text-sm">monitoring</span> Dashboard
            </button>
          </div>
          <div class="flex flex-wrap gap-1.5">
            <button
              v-for="sec in crmQuickNav" :key="sec.id"
              @click="scrollToCrmSection(sec.id)"
              class="px-3 py-1.5 rounded-full text-xs font-medium bg-surface-100 dark:bg-surface-700 text-surface-600 dark:text-surface-300 hover:bg-primary-100 dark:hover:bg-primary-500/20 hover:text-primary-700 dark:hover:text-primary-300 transition-colors flex items-center gap-1"
            >
              <span class="material-symbols-rounded text-sm">{{ sec.icon }}</span>
              {{ sec.label }}
            </button>
          </div>
        </div>

        <!-- CRM 2-Column Grid: Portal + Calls -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
          <div id="crm-portal">
            <CrmPortalSection
              :client-id="clientData.id"
              :contacts="clientData.contacts || []"
            />
          </div>
          <div id="crm-calls">
            <CrmPortalCallButton :client-id="clientData.id" />
          </div>
        </div>

        <!-- CRM 2-Column Grid: Updates + Documents -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
          <div id="crm-updates">
            <CrmUpdateComposer :client-id="clientData.id" />
          </div>
          <div id="crm-documents">
            <CrmDocumentsSection
              :client-id="clientData.id"
              :contacts="clientData.contacts || []"
              :linked-boards="clientData.linked_boards || []"
            />
          </div>
        </div>

        <!-- CRM 2-Column Grid: Invoices + Tags -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
          <div id="crm-invoices">
            <CrmInvoicesSection :client-id="clientData.id" />
          </div>
          <div id="crm-tags">
            <CrmTagsSection :client-id="clientData.id" />
          </div>
        </div>

        <!-- CRM 2-Column Grid: Reminders + Call Log -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
          <div id="crm-reminders">
            <CrmRemindersSection :client-id="clientData.id" />
          </div>
          <div id="crm-calllog">
            <CrmCallLog :client-id="clientData.id" />
          </div>
        </div>

        <!-- Meeting Notes (full width) -->
        <div id="crm-meetings">
          <CrmMeetingNotes :client-id="clientData.id" />
        </div>

        <!-- Unified Activity Timeline (full width) -->
        <div id="crm-timeline">
          <CrmTimeline :client-id="clientData.id" />
        </div>
      </template>

      </template><!-- END CRM PRO TAB -->

      <!-- ========== FINANCE TAB (was Invoices) ========== -->
      <template v-if="activeTab === 'finance'">

      <!-- Financials + Pipeline side by side -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">

      <!-- Financials Section - Grouped by Board/Project (LEFT) -->
      <div v-if="financials && (financials.boards?.length > 0 || financials.all_milestones?.length > 0)" class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border border-surface-200 dark:border-surface-700 p-4">
        <div class="flex items-center justify-between mb-4">
          <h3 class="text-sm font-semibold text-surface-900 dark:text-surface-100 flex items-center gap-2">
            <span class="material-symbols-rounded text-lg text-primary-500">payments</span>
            Financials
            <span v-if="financials.boards?.length > 1" class="text-xs font-normal text-surface-500">
              ({{ financials.boards.length }} projects)
            </span>
          </h3>
          <div class="text-right">
            <p class="text-xs text-surface-500">Total Expected</p>
            <div class="flex flex-wrap gap-2 justify-end">
              <p 
                v-for="(amount, currency) in financials.totals_by_currency" 
                :key="currency"
                class="text-lg font-bold text-primary-600 dark:text-primary-400"
              >
                {{ formatCurrencyWithCode(amount, currency) }}
              </p>
            </div>
          </div>
        </div>
        
        <!-- Projects/Boards with Milestones -->
        <div class="space-y-3">
          <div 
            v-for="board in financials.boards" 
            :key="board.board_id"
            class="border border-surface-200 dark:border-surface-700 rounded-lg overflow-hidden"
          >
            <!-- Board Header -->
            <button 
              @click="toggleBoardExpanded(board.board_id)"
              class="w-full flex items-center justify-between p-3 bg-surface-50 dark:bg-surface-800 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
            >
              <div class="flex items-center gap-3 min-w-0">
                <span class="material-symbols-rounded text-sm text-surface-400">
                  {{ expandedBoards[board.board_id] ? 'expand_more' : 'chevron_right' }}
                </span>
                <span class="font-medium text-surface-900 dark:text-surface-100 truncate">
                  {{ board.board_name }}
                </span>
                <div class="flex items-center gap-1.5">
                  <div class="w-16 h-1.5 bg-surface-200 dark:bg-surface-700 rounded-full overflow-hidden">
                    <div class="h-full bg-primary-500 rounded-full transition-all" :style="{ width: `${getBoardProgress(board)}%` }"></div>
                  </div>
                  <span class="text-xs text-surface-500">{{ getBoardProgress(board) }}%</span>
                </div>
              </div>
              <div class="flex items-center gap-2 flex-shrink-0">
                <span v-for="(amount, currency) in board.totals_by_currency" :key="currency" class="text-sm font-semibold text-surface-900 dark:text-surface-100">
                  {{ formatCurrencyWithCode(amount, currency) }}
                </span>
              </div>
            </button>
            
            <!-- Milestones (collapsible) -->
            <div v-if="expandedBoards[board.board_id]" class="border-t border-surface-200 dark:border-surface-700">
              <div 
                v-for="(milestone, idx) in board.milestones" 
                :key="milestone.list_id"
                class="flex items-center justify-between p-3 hover:bg-surface-50 dark:hover:bg-surface-800/50 transition-colors"
                :class="{ 'border-t border-surface-100 dark:border-surface-700': idx > 0 }"
              >
                <div class="flex items-center gap-3 min-w-0">
                  <div :class="['w-2 h-2 rounded-full flex-shrink-0', milestone.completion_percent === 100 ? 'bg-green-500' : milestone.completion_percent > 0 ? 'bg-amber-500' : 'bg-surface-300 dark:bg-surface-600']"></div>
                  <div class="min-w-0">
                    <p class="text-sm text-surface-700 dark:text-surface-300 truncate">
                      {{ milestone.list_name }}
                      <span v-if="milestone.is_milestone" class="text-amber-500 ml-1"><span class="material-symbols-rounded text-xs align-middle">flag</span></span>
                    </p>
                    <div class="flex items-center gap-3 text-xs text-surface-400">
                      <span v-if="milestone.total_todos > 0">{{ milestone.completed_todos }}/{{ milestone.total_todos }} todos ({{ milestone.completion_percent }}%)</span>
                      <span v-else-if="milestone.total_cards > 0">{{ milestone.completed_cards }}/{{ milestone.total_cards }} cards ({{ milestone.completion_percent }}%)</span>
                      <span v-else-if="milestone.completion_percent > 0">{{ milestone.completion_percent }}% done</span>
                      <span v-if="milestone.invoice_date" class="flex items-center gap-1"><span class="material-symbols-rounded text-xs">receipt</span>{{ formatDate(milestone.invoice_date) }}</span>
                    </div>
                  </div>
                </div>
                <div class="text-right flex-shrink-0">
                  <p class="text-sm font-medium text-surface-900 dark:text-surface-100">{{ formatCurrencyWithCode(milestone.expected_amount, milestone.currency) }}</p>
                </div>
              </div>
            </div>
          </div>
        </div>
        
        <!-- Monthly Cash Flow Projection -->
        <div v-if="Object.keys(financials.monthly_projections || {}).length > 0" class="mt-4 pt-4 border-t border-surface-200 dark:border-surface-700">
          <h4 class="text-xs font-semibold text-surface-500 uppercase mb-3">Cash Flow Projection</h4>
          <div class="flex items-end gap-1 h-20 mb-2">
            <div v-for="(currencies, month) in financials.monthly_projections" :key="month" class="flex-1 flex flex-col items-center">
              <div class="w-full bg-primary-500/80 rounded-t transition-all hover:bg-primary-500" :style="{ height: `${getProjectionBarHeight(currencies)}%` }" :title="formatProjectionTooltip(month, currencies)"></div>
            </div>
          </div>
          <div class="flex gap-1">
            <div v-for="(currencies, month) in financials.monthly_projections" :key="month + '-label'" class="flex-1 text-center">
              <p class="text-[10px] text-surface-500 truncate">{{ formatMonthShort(month) }}</p>
              <p class="text-xs font-medium text-surface-700 dark:text-surface-300 truncate">{{ formatProjectionTotal(currencies) }}</p>
            </div>
          </div>
        </div>
      </div>

      <!-- Pipeline Deals for this Client (RIGHT) -->
      <div class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border border-surface-200 dark:border-surface-700 p-4">
        <div class="flex items-center justify-between mb-4">
          <h3 class="text-sm font-semibold text-surface-900 dark:text-surface-100 flex items-center gap-2">
            <span class="material-symbols-rounded text-lg text-amber-500">conversion_path</span>
            Pipeline
            <span v-if="clientDeals.length" class="text-xs font-normal text-surface-500">({{ clientDeals.length }} deals)</span>
          </h3>
          <button @click="$router.push('/crm/pipeline')" class="text-xs text-primary-600 hover:text-primary-700 dark:text-primary-400 font-medium flex items-center gap-1">
            <span class="material-symbols-rounded text-sm">view_kanban</span>
            Full Pipeline
          </button>
        </div>
        
        <div v-if="loadingDeals" class="flex items-center justify-center py-6">
          <span class="material-symbols-rounded text-2xl text-surface-400 animate-spin">sync</span>
        </div>
        
        <div v-else-if="clientDeals.length > 0" class="space-y-3">
          <div class="flex items-center gap-2 mb-2 flex-wrap">
            <template v-for="(deals, stage) in groupedDeals" :key="stage">
              <div class="flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium" :class="pipelineStageColors[stage]">
                <span class="w-1.5 h-1.5 rounded-full bg-current"></span>
                {{ pipelineStageLabels[stage] || stage }} ({{ deals.length }})
              </div>
            </template>
          </div>
          <div 
            v-for="deal in clientDeals" :key="deal.id"
            class="flex items-center gap-3 p-3 rounded-lg border border-surface-200 dark:border-surface-700 hover:bg-surface-50 dark:hover:bg-surface-800/50 transition-colors"
          >
            <div class="flex-shrink-0">
              <div class="px-2 py-0.5 rounded-full text-[10px] font-semibold uppercase tracking-wide" :class="pipelineStageColors[deal.pipeline_stage]">
                {{ pipelineStageLabels[deal.pipeline_stage] || deal.pipeline_stage }}
              </div>
            </div>
            <div class="flex-1 min-w-0">
              <p class="text-sm font-medium text-surface-900 dark:text-surface-100 truncate">{{ deal.title }}</p>
              <p v-if="deal.description" class="text-xs text-surface-400 truncate mt-0.5">{{ deal.description }}</p>
            </div>
            <div class="text-right flex-shrink-0">
              <p v-if="deal.expected_value" class="text-sm font-bold text-primary-600 dark:text-primary-400">{{ formatCurrencyWithCode(deal.expected_value, deal.currency || 'HUF') }}</p>
              <div class="flex items-center gap-1 mt-0.5">
                <div class="w-10 h-1.5 bg-surface-200 dark:bg-surface-700 rounded-full overflow-hidden">
                  <div class="h-full rounded-full transition-all" :class="deal.probability >= 70 ? 'bg-green-500' : deal.probability >= 40 ? 'bg-amber-500' : 'bg-red-400'" :style="{ width: deal.probability + '%' }"></div>
                </div>
                <span class="text-[10px] text-surface-400">{{ deal.probability }}%</span>
              </div>
            </div>
            <div v-if="deal.expected_close_date" class="flex-shrink-0">
              <span class="text-xs text-surface-400 flex items-center gap-0.5"><span class="material-symbols-rounded text-xs">event</span>{{ formatInvoiceDate(deal.expected_close_date) }}</span>
            </div>
          </div>
        </div>
        
        <div v-else class="text-center py-6">
          <span class="material-symbols-rounded text-3xl text-surface-300 dark:text-surface-600">conversion_path</span>
          <p class="text-sm text-surface-500 mt-2">No pipeline deals for this client</p>
          <button @click="$router.push('/crm/pipeline')" class="mt-3 px-4 py-2 text-sm bg-primary-500 hover:bg-primary-600 text-white rounded-full transition-colors">Create a Deal</button>
        </div>
      </div>

      </div><!-- END Financials + Pipeline grid -->

      <!-- Invoice Summary Cards -->
      <div v-if="invoiceSummary.total_revenue > 0" class="grid grid-cols-2 md:grid-cols-4 gap-3">
        <div class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border border-surface-200 dark:border-surface-700 p-4 text-center">
          <p class="text-xs text-surface-500 mb-1">Total Revenue</p>
          <p class="text-lg font-bold text-green-600 dark:text-green-400">{{ formatInvoiceMoney(invoiceSummary.total_revenue) }}</p>
        </div>
        <div class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border border-surface-200 dark:border-surface-700 p-4 text-center">
          <p class="text-xs text-surface-500 mb-1">Outstanding</p>
          <p class="text-lg font-bold text-amber-600 dark:text-amber-400">{{ formatInvoiceMoney(invoiceSummary.outstanding) }}</p>
        </div>
        <div class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border border-surface-200 dark:border-surface-700 p-4 text-center">
          <p class="text-xs text-surface-500 mb-1">Net Revenue</p>
          <p class="text-lg font-bold text-blue-600 dark:text-blue-400">{{ formatInvoiceMoney(invoiceSummary.net_revenue) }}</p>
        </div>
        <div class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border border-surface-200 dark:border-surface-700 p-4 text-center">
          <p class="text-xs text-surface-500 mb-1">Invoices</p>
          <p class="text-lg font-bold text-surface-900 dark:text-surface-100">{{ clientInvoices.length }}</p>
        </div>
      </div>

      <!-- Invoice List -->
      <div class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border border-surface-200 dark:border-surface-700 p-4">
        <div class="flex items-center justify-between mb-4">
          <h3 class="text-sm font-semibold text-surface-900 dark:text-surface-100 flex items-center gap-2">
            <span class="material-symbols-rounded text-lg text-primary-500">receipt_long</span>
            Invoices
          </h3>
          <div class="flex items-center gap-2">
            <button
              @click="$router.push('/crm/invoices')"
              class="flex items-center gap-1 px-3 py-1.5 text-xs font-medium bg-surface-100 text-surface-700 hover:bg-surface-200 dark:bg-surface-700 dark:text-surface-300 dark:hover:bg-surface-600 rounded-full transition-colors"
            >
              <span class="material-symbols-rounded text-sm">open_in_new</span>
              Full View
            </button>
            <button
              @click="createNewInvoice"
              class="flex items-center gap-1 px-3 py-1.5 text-xs font-medium bg-primary-500 text-white hover:bg-primary-600 rounded-full transition-colors"
            >
              <span class="material-symbols-rounded text-sm">add</span>
              New Invoice
            </button>
          </div>
        </div>

        <!-- Status filter pills -->
        <div class="flex items-center gap-1.5 mb-4 flex-wrap">
          <button
            v-for="status in ['all', 'draft', 'sent', 'paid', 'overdue', 'partial', 'cancelled']"
            :key="status"
            @click="invoiceFilter = status"
            :class="[
              'px-3 py-1 rounded-full text-xs font-medium transition-colors',
              invoiceFilter === status
                ? 'bg-primary-500 text-white'
                : 'bg-surface-100 dark:bg-surface-700 text-surface-600 dark:text-surface-400 hover:bg-surface-200 dark:hover:bg-surface-600'
            ]"
          >
            {{ status === 'all' ? 'All' : status.charAt(0).toUpperCase() + status.slice(1) }}
            <span v-if="invoiceStatusCounts[status]" class="ml-1 opacity-70">({{ invoiceStatusCounts[status] }})</span>
          </button>
        </div>

        <!-- Loading -->
        <div v-if="loadingInvoices" class="flex items-center justify-center py-8">
          <span class="material-symbols-rounded text-2xl text-surface-400 animate-spin">sync</span>
        </div>

        <!-- Invoice table -->
        <div v-else-if="filteredClientInvoices.length > 0" class="space-y-1.5">
          <div 
            v-for="inv in filteredClientInvoices" 
            :key="inv.id"
            class="flex items-center gap-3 p-3 rounded-lg border border-surface-100 dark:border-surface-700 hover:bg-surface-50 dark:hover:bg-surface-800/50 transition-colors group"
          >
            <!-- Status icon -->
            <div class="flex-shrink-0 w-8 h-8 rounded-lg flex items-center justify-center" :class="invoiceStatusColors[inv.status]">
              <span class="material-symbols-rounded text-lg">{{ invoiceStatusIcons[inv.status] || 'receipt' }}</span>
            </div>

            <!-- Invoice info -->
            <div class="flex-1 min-w-0">
              <div class="flex items-center gap-2">
                <p class="text-sm font-semibold text-surface-900 dark:text-surface-100">{{ inv.invoice_number }}</p>
                <span class="px-1.5 py-0.5 rounded text-[10px] font-medium uppercase" :class="invoiceStatusColors[inv.status]">
                  {{ inv.status }}
                </span>
              </div>
              <p v-if="inv.notes" class="text-xs text-surface-400 truncate mt-0.5">{{ inv.notes }}</p>
            </div>

            <!-- Dates -->
            <div class="flex-shrink-0 text-right hidden md:block">
              <p class="text-xs text-surface-500">{{ formatInvoiceDate(inv.issue_date) }}</p>
              <p v-if="inv.due_date" class="text-[10px] text-surface-400">
                Due: {{ formatInvoiceDate(inv.due_date) }}
              </p>
            </div>

            <!-- Amount -->
            <div class="flex-shrink-0 text-right">
              <p class="text-sm font-bold text-surface-900 dark:text-surface-100">
                {{ formatInvoiceMoney(inv.total, inv.currency) }}
              </p>
              <p v-if="inv.paid_amount > 0 && inv.paid_amount < inv.total" class="text-[10px] text-green-500">
                Paid: {{ formatInvoiceMoney(inv.paid_amount, inv.currency) }}
              </p>
            </div>

            <!-- Actions -->
            <div class="flex items-center gap-0.5 flex-shrink-0 opacity-0 group-hover:opacity-100 transition-opacity">
              <button 
                @click="viewInvoice(inv)"
                class="p-1.5 rounded-lg hover:bg-surface-200 dark:hover:bg-surface-700 text-surface-400 hover:text-surface-600 dark:hover:text-surface-300 transition-colors"
                title="Preview"
              >
                <span class="material-symbols-rounded text-sm">visibility</span>
              </button>
              <button 
                @click="editInvoice(inv)"
                class="p-1.5 rounded-lg hover:bg-surface-200 dark:hover:bg-surface-700 text-surface-400 hover:text-surface-600 dark:hover:text-surface-300 transition-colors"
                title="Edit"
              >
                <span class="material-symbols-rounded text-sm">edit</span>
              </button>
              <button 
                v-if="inv.status === 'draft'"
                @click="sendInvoice(inv)"
                class="p-1.5 rounded-lg hover:bg-blue-100 dark:hover:bg-blue-500/10 text-blue-500 transition-colors"
                title="Mark as sent"
              >
                <span class="material-symbols-rounded text-sm">send</span>
              </button>
              <button 
                v-if="inv.status === 'draft'"
                @click="deleteInvoice(inv)"
                class="p-1.5 rounded-lg hover:bg-red-100 dark:hover:bg-red-500/10 text-red-400 hover:text-red-500 transition-colors"
                title="Delete"
              >
                <span class="material-symbols-rounded text-sm">delete</span>
              </button>
            </div>
          </div>
        </div>

        <!-- Empty state -->
        <div v-else class="text-center py-8">
          <span class="material-symbols-rounded text-4xl text-surface-300 dark:text-surface-600">receipt_long</span>
          <p class="text-sm text-surface-500 mt-2">
            {{ invoiceFilter === 'all' ? 'No invoices for this client' : 'No ' + invoiceFilter + ' invoices' }}
          </p>
          <button
            @click="createNewInvoice"
            class="mt-3 px-4 py-2 text-sm bg-primary-500 hover:bg-primary-600 text-white rounded-full transition-colors"
          >
            Create First Invoice
          </button>
        </div>
      </div>

      </template><!-- END FINANCE TAB -->

    </div>
    
    <!-- Invoice Editor Modal -->
    <Teleport to="body">
      <CrmInvoiceEditor 
        v-if="showInvoiceEditor" 
        :invoice="editingInvoice"
        :default-client-id="clientData?.id"
        @close="showInvoiceEditor = false"
        @saved="onInvoiceSaved"
      />
    </Teleport>

    <!-- Invoice Preview Modal -->
    <Teleport to="body">
      <CrmInvoicePreview
        v-if="showInvoicePreview"
        :invoice="previewInvoice"
        @close="showInvoicePreview = false"
      />
    </Teleport>

    <!-- Edit Modal - Extended with all fields -->
    <div v-if="showEditModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4" @mousedown.self="showEditModal = false">
      <div class="bg-white dark:bg-surface-800 rounded-xl shadow-xl w-full max-w-lg p-6 max-h-[90vh] overflow-y-auto" @mousedown.stop>
        <h3 class="text-lg font-semibold text-surface-900 dark:text-surface-100 mb-4 flex items-center gap-2">
          <span class="material-symbols-rounded">edit</span>
          Edit Client
        </h3>
        
        <div class="space-y-4">
          <!-- Display Name -->
          <div>
            <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">Display Name</label>
            <input 
              v-model="editForm.display_name"
              type="text"
              placeholder="Client name"
              class="w-full px-4 py-2 border border-surface-300 dark:border-surface-600 rounded-lg bg-white dark:bg-surface-900 text-surface-900 dark:text-surface-100 focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
            />
          </div>
          
          <!-- Phone -->
          <div>
            <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">Phone</label>
            <input 
              v-model="editForm.phone"
              type="text"
              placeholder="+36 1 234 5678"
              class="w-full px-4 py-2 border border-surface-300 dark:border-surface-600 rounded-lg bg-white dark:bg-surface-900 text-surface-900 dark:text-surface-100 focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
            />
          </div>
          
          <!-- Address -->
          <div>
            <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">Address</label>
            <textarea 
              v-model="editForm.address"
              rows="2"
              placeholder="Street, City, ZIP"
              class="w-full px-4 py-2 border border-surface-300 dark:border-surface-600 rounded-lg bg-white dark:bg-surface-900 text-surface-900 dark:text-surface-100 focus:ring-2 focus:ring-primary-500 focus:border-primary-500 resize-none"
            ></textarea>
          </div>
          
          <!-- Payment Terms -->
          <div>
            <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">Payment Terms (days)</label>
            <select 
              v-model="editForm.payment_terms_days"
              class="w-full px-4 py-2 border border-surface-300 dark:border-surface-600 rounded-lg bg-white dark:bg-surface-900 text-surface-900 dark:text-surface-100 focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
            >
              <option :value="8">8 days</option>
              <option :value="15">15 days</option>
              <option :value="30">30 days (default)</option>
              <option :value="45">45 days</option>
              <option :value="60">60 days</option>
            </select>
          </div>
          
          <!-- Hourly Rate -->
          <div>
            <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">Hourly Rate</label>
            <div class="relative">
              <input 
                :value="formatNumberInput(editForm.hourly_rate)"
                @input="editForm.hourly_rate = parseNumberInput($event.target.value)"
                type="text"
                inputmode="numeric"
                placeholder="0"
                class="w-full px-4 py-2 pr-16 border border-surface-300 dark:border-surface-600 rounded-lg bg-white dark:bg-surface-900 text-surface-900 dark:text-surface-100 focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
              />
              <span class="absolute right-4 top-1/2 -translate-y-1/2 text-surface-400 text-sm">Ft/hr</span>
            </div>
            <p class="text-xs text-surface-500 mt-1">Used for calculating time tracking value</p>
          </div>
          
          <!-- Notes -->
          <div>
            <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">Notes</label>
            <textarea 
              v-model="editForm.notes"
              rows="3"
              placeholder="Internal notes about this client..."
              class="w-full px-4 py-2 border border-surface-300 dark:border-surface-600 rounded-lg bg-white dark:bg-surface-900 text-surface-900 dark:text-surface-100 focus:ring-2 focus:ring-primary-500 focus:border-primary-500 resize-none"
            ></textarea>
          </div>
          
          <!-- Company / Billing Details Divider -->
          <div class="pt-2 pb-1">
            <div class="flex items-center gap-2 text-xs font-semibold text-surface-500 dark:text-surface-400 uppercase tracking-wider">
              <span class="material-symbols-rounded text-sm">receipt_long</span>
              Company / Billing Details
            </div>
            <div class="h-px bg-surface-200 dark:bg-surface-700 mt-1.5"></div>
          </div>
          
          <!-- Billing Name (Company legal name) -->
          <div>
            <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">Company Name (for invoices)</label>
            <input 
              v-model="editForm.billing_name"
              type="text"
              placeholder="Kft. / Bt. legal name"
              class="w-full px-4 py-2 border border-surface-300 dark:border-surface-600 rounded-lg bg-white dark:bg-surface-900 text-surface-900 dark:text-surface-100 focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
            />
          </div>
          
          <!-- Tax Number -->
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">Tax Number</label>
              <input 
                v-model="editForm.billing_tax_id"
                type="text"
                placeholder="12345678-2-42"
                class="w-full px-4 py-2 border border-surface-300 dark:border-surface-600 rounded-lg bg-white dark:bg-surface-900 text-surface-900 dark:text-surface-100 focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
              />
            </div>
            <div>
              <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">EU VAT Number</label>
              <input 
                v-model="editForm.billing_eu_tax_id"
                type="text"
                placeholder="HU12345678"
                class="w-full px-4 py-2 border border-surface-300 dark:border-surface-600 rounded-lg bg-white dark:bg-surface-900 text-surface-900 dark:text-surface-100 focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
              />
            </div>
          </div>
          
          <!-- Billing Address -->
          <div>
            <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">Billing Address</label>
            <input 
              v-model="editForm.billing_address"
              type="text"
              placeholder="Street, number"
              class="w-full px-4 py-2 border border-surface-300 dark:border-surface-600 rounded-lg bg-white dark:bg-surface-900 text-surface-900 dark:text-surface-100 focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
            />
          </div>
          
          <!-- City + ZIP + Country -->
          <div class="grid grid-cols-3 gap-3">
            <div>
              <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">ZIP</label>
              <input 
                v-model="editForm.billing_zip"
                type="text"
                placeholder="1146"
                class="w-full px-4 py-2 border border-surface-300 dark:border-surface-600 rounded-lg bg-white dark:bg-surface-900 text-surface-900 dark:text-surface-100 focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
              />
            </div>
            <div>
              <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">City</label>
              <input 
                v-model="editForm.billing_city"
                type="text"
                placeholder="Budapest"
                class="w-full px-4 py-2 border border-surface-300 dark:border-surface-600 rounded-lg bg-white dark:bg-surface-900 text-surface-900 dark:text-surface-100 focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
              />
            </div>
            <div>
              <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">Country</label>
              <input 
                v-model="editForm.billing_country"
                type="text"
                placeholder="HU"
                maxlength="5"
                class="w-full px-4 py-2 border border-surface-300 dark:border-surface-600 rounded-lg bg-white dark:bg-surface-900 text-surface-900 dark:text-surface-100 focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
              />
            </div>
          </div>
          
          <!-- Bank Account -->
          <div>
            <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">Bank Account</label>
            <input 
              v-model="editForm.billing_bank_account"
              type="text"
              placeholder="12345678-12345678-12345678"
              class="w-full px-4 py-2 border border-surface-300 dark:border-surface-600 rounded-lg bg-white dark:bg-surface-900 text-surface-900 dark:text-surface-100 focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
            />
          </div>
        </div>
        
        <div class="flex justify-end gap-2 mt-6">
          <button 
            @click="showEditModal = false"
            class="px-4 py-2 text-surface-600 dark:text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700 rounded-lg transition-colors"
          >
            Cancel
          </button>
          <button 
            @click="saveClientInfo"
            class="px-4 py-2 bg-primary-500 text-white rounded-lg hover:bg-primary-600 transition-colors"
          >
            Save
          </button>
        </div>
      </div>
    </div>
    
    <!-- Merge Modal -->
    <div v-if="showMergeModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4" @mousedown.self="showMergeModal = false">
      <div class="bg-white dark:bg-surface-800 rounded-xl shadow-xl w-full max-w-md p-6" @mousedown.stop>
        <h3 class="text-lg font-semibold text-surface-900 dark:text-surface-100 mb-4 flex items-center gap-2">
          <span class="material-symbols-rounded">merge</span>
          Merge Clients
        </h3>
        
        <p class="text-sm text-surface-600 dark:text-surface-400 mb-4">
          Select another client to merge into <strong>{{ clientData?.display_name }}</strong>. 
          All contacts, boards, and activity will be combined.
        </p>
        
        <select 
          v-model="mergeTargetId"
          class="w-full px-4 py-2 border border-surface-300 dark:border-surface-600 rounded-lg bg-white dark:bg-surface-900 text-surface-900 dark:text-surface-100"
        >
          <option :value="null">Select a client to merge...</option>
          <option 
            v-for="c in otherClients" 
            :key="c.id" 
            :value="c.id"
          >
            {{ c.display_name || c.domain }}
          </option>
        </select>
        
        <div class="flex justify-end gap-2 mt-4">
          <button 
            @click="showMergeModal = false"
            class="px-4 py-2 text-surface-600 dark:text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700 rounded-lg transition-colors"
          >
            Cancel
          </button>
          <button 
            @click="performMerge"
            :disabled="!mergeTargetId"
            class="px-4 py-2 bg-red-500 text-white rounded-lg hover:bg-red-600 transition-colors disabled:opacity-50"
          >
            Merge
          </button>
        </div>
      </div>
    </div>
    
    <!-- Link Board Modal -->
    <div v-if="showLinkBoardModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4" @mousedown.self="showLinkBoardModal = false">
      <div class="bg-white dark:bg-surface-800 rounded-xl shadow-xl w-full max-w-md p-6" @mousedown.stop>
        <h3 class="text-lg font-semibold text-surface-900 dark:text-surface-100 mb-4 flex items-center gap-2">
          <span class="material-symbols-rounded">dashboard</span>
          Link Board to Client
        </h3>
        
        <div v-if="availableBoardsToLink.length > 0">
          <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">Select a board</label>
          <select
            v-model="selectedBoardToLink"
            class="w-full px-3 py-2 bg-white dark:bg-surface-900 border border-surface-300 dark:border-surface-600 rounded-lg text-surface-900 dark:text-surface-100 focus:outline-none focus:ring-2 focus:ring-primary-500"
          >
            <option :value="null">Select a board...</option>
            <option 
              v-for="board in availableBoardsToLink" 
              :key="board.id" 
              :value="board.id"
            >
              {{ board.name }}
            </option>
          </select>
        </div>
        <div v-else class="text-center py-4">
          <span class="material-symbols-rounded text-3xl text-surface-300 dark:text-surface-600">dashboard_customize</span>
          <p class="text-sm text-surface-500 mt-2">No boards available to link</p>
          <p class="text-xs text-surface-400 mt-1">All boards are already linked or you have no boards yet</p>
        </div>
        
        <div class="flex justify-end gap-2 mt-6">
          <button 
            @click="showLinkBoardModal = false"
            class="px-4 py-2 text-surface-600 dark:text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700 rounded-lg transition-colors"
          >
            Cancel
          </button>
          <button 
            v-if="availableBoardsToLink.length > 0"
            @click="linkSelectedBoard"
            :disabled="!selectedBoardToLink || linkingBoard"
            class="px-4 py-2 bg-primary-500 text-white rounded-lg hover:bg-primary-600 transition-colors disabled:opacity-50 flex items-center gap-2"
          >
            <span v-if="linkingBoard" class="material-symbols-rounded text-sm animate-spin">sync</span>
            {{ linkingBoard ? 'Linking...' : 'Link Board' }}
          </button>
        </div>
      </div>
    </div>
    
    <!-- Edit Contact Modal -->
    <div v-if="showEditContactModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4" @mousedown.self="showEditContactModal = false">
      <div class="bg-white dark:bg-surface-800 rounded-xl shadow-xl w-full max-w-md p-6" @mousedown.stop>
        <h3 class="text-lg font-semibold text-surface-900 dark:text-surface-100 mb-4 flex items-center gap-2">
          <span class="material-symbols-rounded">edit</span>
          Edit Contact
        </h3>
        
        <div class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">Name</label>
            <input 
              v-model="contactForm.name"
              type="text"
              class="w-full px-3 py-2 bg-white dark:bg-surface-900 border border-surface-300 dark:border-surface-600 rounded-lg text-surface-900 dark:text-surface-100 focus:outline-none focus:ring-2 focus:ring-primary-500"
              placeholder="Contact name"
            />
          </div>
          
          <div>
            <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">Email</label>
            <input 
              v-model="contactForm.email"
              type="email"
              :disabled="editingContact && editingContact.id && editingContact.id !== 0 && !editingContact.is_placeholder"
              :class="[
                'w-full px-3 py-2 border border-surface-300 dark:border-surface-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500',
                editingContact && editingContact.id && editingContact.id !== 0 && !editingContact.is_placeholder
                  ? 'bg-surface-100 dark:bg-surface-800 text-surface-500 cursor-not-allowed'
                  : 'bg-white dark:bg-surface-900 text-surface-900 dark:text-surface-100'
              ]"
            />
            <p v-if="!editingContact?.id || editingContact?.id === 0 || editingContact?.is_placeholder" class="text-xs text-surface-500 mt-1">
              This contact will be saved to the database
            </p>
          </div>
          
          <div>
            <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">Phone</label>
            <input 
              v-model="contactForm.phone"
              type="tel"
              class="w-full px-3 py-2 bg-white dark:bg-surface-900 border border-surface-300 dark:border-surface-600 rounded-lg text-surface-900 dark:text-surface-100 focus:outline-none focus:ring-2 focus:ring-primary-500"
              placeholder="+1 234 567 8900"
            />
          </div>
          
          <div>
            <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">Position</label>
            <input 
              v-model="contactForm.position"
              type="text"
              class="w-full px-3 py-2 bg-white dark:bg-surface-900 border border-surface-300 dark:border-surface-600 rounded-lg text-surface-900 dark:text-surface-100 focus:outline-none focus:ring-2 focus:ring-primary-500"
              placeholder="CEO, Manager, etc."
            />
          </div>
        </div>
        
        <div class="flex justify-end gap-2 mt-6">
          <button 
            @click="showEditContactModal = false"
            class="px-4 py-2 text-surface-600 dark:text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700 rounded-lg transition-colors"
          >
            Cancel
          </button>
          <button 
            @click="saveContact"
            class="px-4 py-2 bg-primary-500 hover:bg-primary-600 text-white rounded-lg transition-colors"
          >
            Save
          </button>
        </div>
      </div>
    </div>
    
    <!-- Add Contact Modal -->
    <div v-if="showAddContactModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4" @mousedown.self="showAddContactModal = false">
      <div class="bg-white dark:bg-surface-800 rounded-xl shadow-xl w-full max-w-md p-6" @mousedown.stop>
        <h3 class="text-lg font-semibold text-surface-900 dark:text-surface-100 mb-4 flex items-center gap-2">
          <span class="material-symbols-rounded">person_add</span>
          Add Contact
        </h3>
        
        <div class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">Email *</label>
            <input 
              v-model="contactForm.email"
              type="email"
              class="w-full px-3 py-2 bg-white dark:bg-surface-900 border border-surface-300 dark:border-surface-600 rounded-lg text-surface-900 dark:text-surface-100 focus:outline-none focus:ring-2 focus:ring-primary-500"
              placeholder="contact@example.com"
            />
          </div>
          
          <div>
            <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">Name</label>
            <input 
              v-model="contactForm.name"
              type="text"
              class="w-full px-3 py-2 bg-white dark:bg-surface-900 border border-surface-300 dark:border-surface-600 rounded-lg text-surface-900 dark:text-surface-100 focus:outline-none focus:ring-2 focus:ring-primary-500"
              placeholder="Contact name"
            />
          </div>
          
          <div>
            <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">Phone</label>
            <input 
              v-model="contactForm.phone"
              type="tel"
              class="w-full px-3 py-2 bg-white dark:bg-surface-900 border border-surface-300 dark:border-surface-600 rounded-lg text-surface-900 dark:text-surface-100 focus:outline-none focus:ring-2 focus:ring-primary-500"
              placeholder="+1 234 567 8900"
            />
          </div>
          
          <div>
            <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">Position</label>
            <input 
              v-model="contactForm.position"
              type="text"
              class="w-full px-3 py-2 bg-white dark:bg-surface-900 border border-surface-300 dark:border-surface-600 rounded-lg text-surface-900 dark:text-surface-100 focus:outline-none focus:ring-2 focus:ring-primary-500"
              placeholder="CEO, Manager, etc."
            />
          </div>
        </div>
        
        <div class="flex justify-end gap-2 mt-6">
          <button 
            @click="showAddContactModal = false; contactForm = { name: '', email: '', phone: '', position: '' }"
            class="px-4 py-2 text-surface-600 dark:text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700 rounded-lg transition-colors"
          >
            Cancel
          </button>
          <button 
            @click="addContact"
            class="px-4 py-2 bg-primary-500 hover:bg-primary-600 text-white rounded-lg transition-colors"
          >
            Add Contact
          </button>
        </div>
      </div>
    </div>
    
    <!-- Link Drive Folder Modal -->
    <div v-if="showLinkDriveModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4" @mousedown.self="showLinkDriveModal = false">
      <div class="bg-white dark:bg-surface-800 rounded-xl shadow-xl w-full max-w-md p-6" @mousedown.stop>
        <h3 class="text-lg font-semibold text-surface-900 dark:text-surface-100 mb-4 flex items-center gap-2">
          <span class="material-symbols-rounded">folder</span>
          Link Drive Folder
        </h3>
        
        <!-- Loading state -->
        <div v-if="loadingDriveFolders" class="flex items-center justify-center py-8">
          <span class="material-symbols-rounded text-2xl text-surface-400 animate-spin">sync</span>
        </div>
        
        <!-- Folder selection -->
        <div v-else class="space-y-4">
          <p class="text-sm text-surface-500 dark:text-surface-400">
            Select an existing folder or create a new one for this client's files.
          </p>
          
          <!-- Existing folders -->
          <div v-if="driveFolders.length > 0" class="max-h-48 overflow-y-auto border border-surface-200 dark:border-surface-700 rounded-lg">
            <div 
              v-for="folder in driveFolders" 
              :key="folder.id"
              @click="selectedDriveFolderId = folder.id"
              :class="[
                'flex items-center gap-3 px-3 py-2 cursor-pointer transition-colors',
                selectedDriveFolderId === folder.id 
                  ? 'bg-primary-50 dark:bg-primary-500/10' 
                  : 'hover:bg-surface-50 dark:hover:bg-surface-700'
              ]"
            >
              <span class="material-symbols-rounded text-lg" :class="selectedDriveFolderId === folder.id ? 'text-primary-500' : 'text-surface-400'">
                folder
              </span>
              <span class="text-sm text-surface-700 dark:text-surface-300">{{ folder.name }}</span>
              <span v-if="selectedDriveFolderId === folder.id" class="material-symbols-rounded text-primary-500 ml-auto">check</span>
            </div>
          </div>
          
          <div v-else class="text-center py-4 text-surface-500">
            No folders found. Create one below.
          </div>
          
          <!-- Divider -->
          <div class="flex items-center gap-3 text-sm text-surface-400">
            <div class="flex-1 h-px bg-surface-200 dark:bg-surface-700"></div>
            <span>or</span>
            <div class="flex-1 h-px bg-surface-200 dark:bg-surface-700"></div>
          </div>
          
          <!-- Create new folder button -->
          <button 
            @click="createAndLinkFolder"
            class="w-full flex items-center justify-center gap-2 px-4 py-2 border-2 border-dashed border-surface-300 dark:border-surface-600 rounded-lg hover:border-primary-500 hover:bg-primary-50 dark:hover:bg-primary-500/10 text-surface-600 dark:text-surface-400 transition-colors"
          >
            <span class="material-symbols-rounded">create_new_folder</span>
            Create "{{ clientData?.display_name || clientData?.domain || 'Client' }}" folder
          </button>
        </div>
        
        <!-- Actions -->
        <div class="flex justify-between mt-6">
          <button 
            v-if="clientData?.drive_folder_id"
            @click="unlinkDriveFolder(); showLinkDriveModal = false"
            class="px-4 py-2 text-red-500 hover:bg-red-50 dark:hover:bg-red-500/10 rounded-lg transition-colors"
          >
            Unlink
          </button>
          <div v-else></div>
          
          <div class="flex gap-2">
            <button 
              @click="showLinkDriveModal = false"
              class="px-4 py-2 text-surface-600 dark:text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700 rounded-lg transition-colors"
            >
              Cancel
            </button>
            <button 
              @click="linkSelectedDriveFolder"
              :disabled="!selectedDriveFolderId"
              class="px-4 py-2 bg-primary-500 text-white rounded-lg hover:bg-primary-600 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
            >
              Link Folder
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { useClientsStore } from '@/stores/clients';
import { useComposeStore } from '@/stores/compose';
import { useBoardsStore } from '@/addons/kanban-boards/stores/boards';
import { useMoodBoardsStore } from '@/addons/moodboards/stores/moodBoards';
import { useMailboxStore } from '@/stores/mailbox';
import { useDriveStore } from '@/stores/drive';
import { useToastStore } from '@/stores/toast';
import api from '@/services/api';
import { isDebugEnabled } from '@/utils/debug';
import ClientStatusBadge from './ClientStatusBadge.vue';
import ActivityLog from '@/components/ActivityLog.vue';
import ClientDriveIndex from './ClientDriveIndex.vue';
import ClientTeamCompact from './ClientTeamCompact.vue';
import ClientTrackedWebsites from './ClientTrackedWebsites.vue';
import CrmPortalSection from '@/addons/crm-pro/components/CrmPortalSection.vue';
import CrmUpdateComposer from '@/addons/crm-pro/components/CrmUpdateComposer.vue';
import CrmDocumentsSection from '@/addons/crm-pro/components/CrmDocumentsSection.vue';
import CrmPortalCallButton from '@/addons/crm-pro/components/CrmPortalCallButton.vue';
import CrmInvoicesSection from '@/addons/crm-pro/components/CrmInvoicesSection.vue';
import CrmTagsSection from '@/addons/crm-pro/components/CrmTagsSection.vue';
import CrmRemindersSection from '@/addons/crm-pro/components/CrmRemindersSection.vue';
import CrmCallLog from '@/addons/crm-pro/components/CrmCallLog.vue';
import CrmMeetingNotes from '@/addons/crm-pro/components/CrmMeetingNotes.vue';
import CrmTimeline from '@/addons/crm-pro/components/CrmTimeline.vue';
import CrmInvoiceEditor from '@/addons/crm-pro/components/CrmInvoiceEditor.vue';
import CrmInvoicePreview from '@/addons/crm-pro/components/CrmInvoicePreview.vue';
import { useAddons } from '@/composables/useAddons';

const props = defineProps({
  clientId: {
    type: Number,
    default: null
  }
});

const emit = defineEmits(['compose', 'openBoard']);

const router = useRouter();
const clientsStore = useClientsStore();
const composeStore = useComposeStore();
const boardsStore = useBoardsStore();
const mailboxStore = useMailboxStore();
const toast = useToastStore();
const driveStore = useDriveStore();
const addons = useAddons();

// Tab system
const activeTab = ref('overview');
const availableTabs = computed(() => {
  const tabs = [
    { id: 'overview', icon: 'dashboard', label: 'Overview' },
    { id: 'contacts', icon: 'people', label: 'Contacts' },
    { id: 'activity', icon: 'timeline', label: 'Activity' },
  ];
  if (addons.timeTrackerEnabled.value) {
    tabs.push({ id: 'timetracking', icon: 'timer', label: 'Time Tracking' });
  }
  if (addons.crmProEnabled.value) {
    tabs.push({ id: 'crmpro', icon: 'conversion_path', label: 'CRM Pro' });
    tabs.push({ id: 'finance', icon: 'payments', label: 'Finance' });
  } else {
    tabs.push({ id: 'financials', icon: 'payments', label: 'Financials' });
  }
  return tabs;
});

// CRM quick navigation items
const crmQuickNav = [
  { id: 'crm-portal', icon: 'key', label: 'Portal' },
  { id: 'crm-calls', icon: 'video_call', label: 'Calls' },
  { id: 'crm-updates', icon: 'campaign', label: 'Updates' },
  { id: 'crm-documents', icon: 'description', label: 'Documents' },
  { id: 'crm-invoices', icon: 'receipt_long', label: 'Invoices' },
  { id: 'crm-tags', icon: 'sell', label: 'Tags' },
  { id: 'crm-reminders', icon: 'notification_important', label: 'Reminders' },
  { id: 'crm-calllog', icon: 'phone_in_talk', label: 'Call Log' },
  { id: 'crm-meetings', icon: 'groups', label: 'Meetings' },
  { id: 'crm-timeline', icon: 'timeline', label: 'Timeline' },
];

function scrollToCrmSection(sectionId) {
  const el = document.getElementById(sectionId);
  if (el) {
    el.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }
}

// Board stats from linked boards
const boardStats = ref({ openTasks: 0, overdueTasks: 0 });

// Drive folder linking
const showLinkDriveModal = ref(false);
const driveFolders = ref([]);
const selectedDriveFolderId = ref(null);
const loadingDriveFolders = ref(false);
const syncingDriveFolder = ref(false);

// Email statistics
const emailStats = ref({
  totalEmails: 0,
  sentEmails: 0,
  receivedEmails: 0,
  conversations: 0,
  activeThreads: 0,
  theirResponseTime: null,
  yourResponseTime: null,
  lastOutboundAt: null,
  lastInboundAt: null
});

// Recent emails
const recentEmails = ref([]);
const discoveredContacts = ref([]);

// Edit modal
const showEditModal = ref(false);
const editForm = ref({
  display_name: '',
  phone: '',
  address: '',
  notes: '',
  payment_terms_days: 30,
  hourly_rate: null,
  billing_name: '',
  billing_tax_id: '',
  billing_eu_tax_id: '',
  billing_address: '',
  billing_city: '',
  billing_zip: '',
  billing_country: 'HU',
  billing_bank_account: '',
});

// Merge modal
const showMergeModal = ref(false);
const mergeTargetId = ref(null);

// Contact editing
const showAddContactModal = ref(false);
const showEditContactModal = ref(false);
const extractingSignatures = ref(false);
const editingContact = ref(null);
const contactForm = ref({
  name: '',
  email: '',
  phone: '',
  position: ''
});

// Associated accounts
const associatedAccounts = ref([]);

// Financials
const financials = ref(null);
const expandedBoards = ref({});

// Time tracking stats for team compact component
const timeStats = ref(null);

// Pipeline deals for this client
const clientDeals = ref([]);
const loadingDeals = ref(false);

// Invoices tab data
const clientInvoices = ref([]);
const invoiceSummary = ref({});
const loadingInvoices = ref(false);
const invoiceFilter = ref('all');
const showInvoiceEditor = ref(false);
const editingInvoice = ref(null);
const showInvoicePreview = ref(false);
const previewInvoice = ref(null);

// Board linking
const showLinkBoardModal = ref(false);
const linkingBoard = ref(false);
const selectedBoardToLink = ref(null);

// Available boards to link (exclude already linked ones)
const availableBoardsToLink = computed(() => {
  const linkedIds = linkedBoards.value.map(b => b.board_id);
  return boardsStore.boards.filter(b => !linkedIds.includes(b.id) && !b.archived);
});

// Toggle board expansion in financials
function toggleBoardExpanded(boardId) {
  expandedBoards.value[boardId] = !expandedBoards.value[boardId];
}

// Get overall progress for a board (based on todos if available, otherwise cards)
function getBoardProgress(board) {
  if (!board.milestones?.length) return 0;
  
  // Sum up all todos/cards across milestones
  let totalTodos = 0;
  let completedTodos = 0;
  let totalCards = 0;
  let completedCards = 0;
  
  board.milestones.forEach(m => {
    totalTodos += m.total_todos || 0;
    completedTodos += m.completed_todos || 0;
    totalCards += m.total_cards || 0;
    completedCards += m.completed_cards || 0;
  });
  
  // Prefer todos if available
  if (totalTodos > 0) {
    return Math.round((completedTodos / totalTodos) * 100);
  }
  if (totalCards > 0) {
    return Math.round((completedCards / totalCards) * 100);
  }
  
  // Fallback to averaging completion_percent
  const total = board.milestones.reduce((sum, m) => sum + (m.completion_percent || 0), 0);
  return Math.round(total / board.milestones.length);
}

// Get projection bar height as percentage (relative to max)
function getProjectionBarHeight(currencies) {
  if (!financials.value?.monthly_projections) return 0;
  
  // Get total for this month
  const monthTotal = Object.values(currencies).reduce((sum, amt) => sum + amt, 0);
  
  // Get max total across all months
  const allMonths = Object.values(financials.value.monthly_projections);
  const maxTotal = Math.max(...allMonths.map(c => Object.values(c).reduce((s, a) => s + a, 0)));
  
  if (maxTotal === 0) return 0;
  return Math.round((monthTotal / maxTotal) * 100);
}

// Format projection tooltip
function formatProjectionTooltip(month, currencies) {
  const lines = Object.entries(currencies).map(([curr, amt]) => 
    `${formatCurrencyWithCode(amt, curr)}`
  );
  return `${formatMonth(month)}: ${lines.join(', ')}`;
}

// Format month short (Jan, Feb, etc.)
function formatMonthShort(month) {
  const date = new Date(month + '-01');
  return date.toLocaleString('en-US', { month: 'short' });
}

// Format projection total (sum all currencies, show primary)
function formatProjectionTotal(currencies) {
  // Find the currency with highest amount
  let maxCurrency = 'HUF';
  let maxAmount = 0;
  for (const [curr, amt] of Object.entries(currencies)) {
    if (amt > maxAmount) {
      maxAmount = amt;
      maxCurrency = curr;
    }
  }
  // Format with K/M suffix for readability
  if (maxAmount >= 1000000) {
    return `${(maxAmount / 1000000).toFixed(1)}M`;
  } else if (maxAmount >= 1000) {
    return `${Math.round(maxAmount / 1000)}K`;
  }
  return maxAmount.toString();
}

// Computed
const loading = computed(() => clientsStore.clientLoading);
const client = computed(() => clientsStore.currentClient);

// Extract client data with proper fallbacks
const clientData = computed(() => {
  if (!client.value) return null;
  return client.value.client || client.value;
});

const linkedBoards = computed(() => client.value?.linked_boards || []);

// Mood boards
const moodBoardsStore = useMoodBoardsStore();
const clientMoodBoards = ref([]);

async function fetchClientMoodBoards() {
  if (!props.clientId || !addons.moodboardsEnabled.value) return;
  clientMoodBoards.value = await moodBoardsStore.fetchClientBoards(props.clientId);
}

// Other clients for merge (exclude current)
const otherClients = computed(() => 
  clientsStore.clients.filter(c => c.id !== props.clientId)
);

// Effective last outbound/inbound dates - prefer email stats over client data
const effectiveLastOutbound = computed(() => {
  return emailStats.value.lastOutboundAt || clientData.value?.last_outbound_at || null;
});

const effectiveLastInbound = computed(() => {
  return emailStats.value.lastInboundAt || clientData.value?.last_inbound_at || null;
});

// Merge database contacts with discovered contacts from email scan
const contacts = computed(() => {
  const dbContacts = client.value?.client?.contacts || [];
  const discovered = discoveredContacts.value || [];
  
  // If we have discovered contacts, merge them with db contacts
  if (discovered.length > 0) {
    const contactMap = new Map();
    
    // Add discovered contacts first
    discovered.forEach(c => {
      contactMap.set(c.email.toLowerCase(), {
        id: 0,
        email: c.email,
        name: c.name || '',
        phone: null,
        position: null,
        email_count: c.email_count || 1,
        last_email_at: c.last_email_at
      });
    });
    
    // Merge with db contacts (db takes priority - includes phone, position)
    dbContacts.forEach(c => {
      if (c.is_placeholder) return; // Skip placeholder contacts
      const key = c.email.toLowerCase();
      if (contactMap.has(key)) {
        const existing = contactMap.get(key);
        existing.name = c.name || existing.name;
        existing.id = c.id || existing.id;
        // IMPORTANT: Copy phone and position from db contact
        existing.phone = c.phone || existing.phone;
        existing.position = c.position || existing.position;
      } else {
        contactMap.set(key, c);
      }
    });
    
    return Array.from(contactMap.values())
      .sort((a, b) => (b.email_count || 0) - (a.email_count || 0));
  }
  
  // No discovered contacts - filter out placeholders only if there are real contacts
  const realContacts = dbContacts.filter(c => !c.is_placeholder);
  // If we have real contacts, return only those; otherwise keep placeholders as fallback
  return realContacts.length > 0 ? realContacts : dbContacts;
});
const openWork = computed(() => client.value?.open_work || {});
const responsibility = computed(() => client.value?.responsibility || 'No status');

// Watch for clientId changes
watch(() => props.clientId, async (newId) => {
  if (newId) {
    // Reset to overview tab when switching clients
    activeTab.value = 'overview';
    // Reset data before fetching new client data (prevents stale data flash)
    discoveredContacts.value = [];
    associatedAccounts.value = [];
    financials.value = null;
    clientDeals.value = [];
    clientInvoices.value = [];
    invoiceSummary.value = {};
    
    await clientsStore.fetchClient(newId);
    // Fetch board stats, email stats, associated accounts, financials, and time stats after client is loaded
    await Promise.all([
      fetchBoardStats(),
      fetchEmailStats(),
      fetchAssociatedAccounts(),
      fetchFinancials(),
      fetchTimeStats(),
      fetchClientMoodBoards(),
      fetchClientDeals(),
      fetchClientInvoices()
    ]);
    
    // Auto-sync Drive folder if client has boards but no folder linked
    if (clientData.value && linkedBoards.value.length > 0 && !clientData.value.drive_folder_id) {
      isDebugEnabled() && console.log('[ClientSnapshot] Auto-syncing Drive folder from boards...');
      await syncDriveFolder();
    }
  } else {
    clientsStore.clearCurrentClient();
    boardStats.value = { openTasks: 0, overdueTasks: 0 };
    discoveredContacts.value = [];
    associatedAccounts.value = [];
    financials.value = null;
    timeStats.value = null;
    clientMoodBoards.value = [];
    emailStats.value = {
      totalEmails: 0,
      sentEmails: 0,
      receivedEmails: 0,
      conversations: 0,
      activeThreads: 0,
      theirResponseTime: null,
      yourResponseTime: null
    };
    recentEmails.value = [];
  }
}, { immediate: true });

// Re-fetch board stats when linked boards change
watch(linkedBoards, async () => {
  if (props.clientId) {
    await fetchBoardStats();
  }
}, { deep: true });

// Methods
function formatLastActivity(dateStr) {
  return clientsStore.formatLastActivity(dateStr);
}

function getWaitingTime(dateStr) {
  if (!dateStr) return '—';
  
  const date = new Date(dateStr);
  const now = new Date();
  const diffMs = now - date;
  const diffMins = Math.floor(diffMs / 60000);
  const diffHours = Math.floor(diffMs / 3600000);
  const diffDays = Math.floor(diffMs / 86400000);
  
  if (diffMins < 1) return 'Just now';
  if (diffMins < 60) return `${diffMins}m`;
  if (diffHours < 24) return `${diffHours}h`;
  if (diffDays === 1) return '1 day';
  if (diffDays < 7) return `${diffDays} days`;
  if (diffDays < 14) return `${Math.floor(diffDays / 7)} week`;
  if (diffDays < 30) return `${Math.floor(diffDays / 7)} weeks`;
  return `${Math.floor(diffDays / 30)} months`;
}

function getWaitingContext(status, direction) {
  if (status === 'waiting') {
    return direction === 'outbound' ? 'You emailed them' : 'Work in progress';
  }
  if (status === 'attention') {
    return 'No response received';
  }
  if (status === 'active') {
    return direction === 'inbound' ? 'They responded' : 'Communication active';
  }
  return '';
}

// Status styling helper functions
function getStatusBoxClasses() {
  const status = clientData.value?.status
  
  if (status === 'attention') {
    return 'bg-red-50 dark:bg-red-900/20 border-red-300 dark:border-red-500/50'
  }
  // All other statuses use subtle neutral styling
  return 'bg-white dark:bg-[rgb(var(--color-surface))] border-surface-200 dark:border-surface-700'
}

function getStatusHeaderClasses() {
  const status = clientData.value?.status
  const direction = clientData.value?.last_email_direction
  
  if (status === 'waiting' && direction === 'outbound') {
    return 'text-amber-800 dark:text-amber-200'
  }
  if (status === 'attention') {
    return 'text-red-800 dark:text-red-200'
  }
  if (status === 'active') {
    return 'text-green-800 dark:text-green-200'
  }
  return 'text-surface-900 dark:text-surface-100'
}

function getStatusTextClasses() {
  const status = clientData.value?.status
  
  if (status === 'attention') {
    return 'text-red-700 dark:text-red-300'
  }
  // All other statuses use neutral text
  return 'text-surface-900 dark:text-surface-100'
}

function getStatusIconClasses() {
  const status = clientData.value?.status
  
  if (status === 'attention') {
    return 'text-red-600 dark:text-red-400'
  }
  // All other statuses use neutral icon
  return 'text-surface-500'
}

function getStatusBadgeClasses() {
  const status = clientData.value?.status
  
  if (status === 'attention') {
    return 'bg-red-100 dark:bg-red-800/30'
  }
  // All other statuses use neutral background
  return 'bg-surface-100 dark:bg-surface-800'
}

function getStatusIcon() {
  const status = clientData.value?.status
  const direction = clientData.value?.last_email_direction
  
  if (status === 'waiting' && direction === 'outbound') {
    return 'hourglass_top'
  }
  if (status === 'attention') {
    return 'priority_high'
  }
  if (status === 'active') {
    return 'check_circle'
  }
  return 'schedule'
}

function getNextDeadlineText() {
  if (!clientData.value?.next_deadline) {
    if (boardStats.value.openTasks > 0) {
      return 'No due date';
    }
    return '—';
  }
  
  const deadline = new Date(clientData.value.next_deadline);
  const now = new Date();
  const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
  const deadlineDate = new Date(deadline.getFullYear(), deadline.getMonth(), deadline.getDate());
  const diffDays = Math.floor((deadlineDate - today) / 86400000);
  
  if (diffDays < 0) {
    return `${Math.abs(diffDays)}d overdue`;
  }
  if (diffDays === 0) {
    return 'Today';
  }
  if (diffDays === 1) {
    return 'Tomorrow';
  }
  if (diffDays < 7) {
    return `${diffDays} days`;
  }
  
  return deadline.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
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
  const domainPart = domain.includes('@') ? domain.split('@')[1] : domain;
  return genericDomains.includes(domainPart.toLowerCase());
}

async function viewEmails() {
  const domain = clientData.value?.domain;
  if (!domain) return;
  
  // Build search term using involves: operator (searches FROM, TO, and CC)
  // This finds all emails with this client across all folders
  const searchTerm = domain.includes('@') ? `involves:${domain}` : `involves:@${domain}`;
  
  // Navigate to inbox with search, enabling all-folder search
  router.push(`/?search=${encodeURIComponent(searchTerm)}&searchAll=1`);
}

// Open a specific email from the recent activity list
async function openEmail(email) {
  if (!email.uid || !email.folder) {
    // Fallback to view all emails if we don't have the specific email info
    viewEmails();
    return;
  }
  
  // Navigate to mailbox with the specific folder and message UID
  const folderPath = (email.folder || 'INBOX')
    .replace(/\./g, '/')
    .replace(/ /g, '_')
    .toLowerCase()
  router.push(`/email/${folderPath}/message/${email.uid}`);
}

// Compose email to client
async function composeToClient() {
  // Get the first contact or use domain
  const recipients = [];
  
  if (contacts.value.length > 0) {
    // Add first contact as default recipient
    recipients.push({
      email: contacts.value[0].email,
      name: contacts.value[0].name || ''
    });
  } else if (clientData.value?.domain && clientData.value.domain.includes('@')) {
    // If domain is actually an email (like for personal contacts)
    recipients.push({ email: clientData.value.domain, name: '' });
  }
  
  await composeStore.openWithRecipients(recipients);
}

// Compose email to a specific contact
async function composeToContact(contact) {
  const recipients = [{
    email: contact.email,
    name: contact.name || ''
  }];
  await composeStore.openWithRecipients(recipients);
}

// Copy to clipboard
async function copyToClipboard(text) {
  try {
    await navigator.clipboard.writeText(text);
    toast.success('Copied to clipboard');
  } catch (e) {
    console.error('Failed to copy:', e);
    toast.error('Failed to copy');
  }
}

// Edit contact
function editContact(contact) {
  editingContact.value = contact;
  contactForm.value = {
    name: contact.name || '',
    email: contact.email || '',
    phone: contact.phone || '',
    position: contact.position || ''
  };
  showEditContactModal.value = true;
}

// Save contact
async function saveContact() {
  if (!editingContact.value) return;
  
  try {
    let response;
    
    // If contact has no valid ID (placeholder contact), create it instead of updating
    if (!editingContact.value.id || editingContact.value.id === 0 || editingContact.value.is_placeholder) {
      const payload = {
        email: editingContact.value.email || contactForm.value.email,
        name: contactForm.value.name,
        phone: contactForm.value.phone,
        position: contactForm.value.position
      };
      isDebugEnabled() && console.log('Creating contact with payload:', payload);
      response = await api.post(`/clients/${props.clientId}/contacts`, payload);
      isDebugEnabled() && console.log('Create contact response:', response.data);
      
      if (response.data.success) {
        // Reload client data to get updated contacts from server
        await clientsStore.fetchClient(props.clientId);
        toast.success('Contact saved');
        showEditContactModal.value = false;
      }
    } else {
      // Update existing contact
      const payload = {
        name: contactForm.value.name,
        phone: contactForm.value.phone,
        position: contactForm.value.position
      };
      isDebugEnabled() && console.log('Updating contact with payload:', payload, 'contactId:', editingContact.value.id);
      response = await api.put(`/clients/${props.clientId}/contacts/${editingContact.value.id}`, payload);
      isDebugEnabled() && console.log('Update contact response:', response.data);
      
      if (response.data.success) {
        // Reload client data to get updated contacts from server
        await clientsStore.fetchClient(props.clientId);
        toast.success('Contact updated');
        showEditContactModal.value = false;
      }
    }
  } catch (e) {
    console.error('Failed to save contact:', e);
    toast.error('Failed to save contact');
  }
}

// Add new contact
async function addContact() {
  if (!contactForm.value.email) {
    toast.warning('Email is required');
    return;
  }
  
  try {
    const response = await api.post(`/clients/${props.clientId}/contacts`, {
      email: contactForm.value.email,
      name: contactForm.value.name,
      phone: contactForm.value.phone,
      position: contactForm.value.position
    });
    
    if (response.data.success) {
      // Reload client data to get updated contacts from server
      await clientsStore.fetchClient(props.clientId);
      toast.success('Contact added');
      showAddContactModal.value = false;
      contactForm.value = { name: '', email: '', phone: '', position: '' };
    }
  } catch (e) {
    console.error('Failed to add contact:', e);
    toast.error('Failed to add contact');
  }
}

// Delete contact
async function runMultiEmailExtraction() {
  if (!props.clientId) return;
  extractingSignatures.value = true;
  
  try {
    const result = await clientsStore.extractSignatureMultiEmail(props.clientId, true);
    if (result) {
      console.log('[ExtractInfo] Full result:', JSON.stringify(result, null, 2));
      const scanned = result.emails_scanned || 0;
      const extractions = result.extractions || [];
      const applied = result.applied || [];
      
      if (extractions.length > 0) {
        const phonesFound = extractions.filter(e => e.phone).length;
        const positionsFound = extractions.filter(e => e.position).length;
        const addressesFound = extractions.filter(e => e.address).length;
        
        const parts = [];
        if (phonesFound) parts.push(`${phonesFound} phone(s)`);
        if (positionsFound) parts.push(`${positionsFound} position(s)`);
        if (addressesFound) parts.push(`${addressesFound} address(es)`);
        
        let message = `Scanned ${scanned} emails.`;
        if (parts.length > 0) {
          message += ` Found ${parts.join(', ')}.`;
        }
        if (applied.length > 0) {
          message += ` Applied to ${applied.length} contact(s).`;
        }
        
        toast.success(message);
        await clientsStore.fetchClient(props.clientId);
      } else if (result.message) {
        toast.info(result.message);
      } else {
        toast.info(`Scanned ${scanned} emails. No contact info found in signatures.`);
      }
    } else {
      toast.warning('Could not extract contact info from emails.');
    }
  } catch (e) {
    console.error('Multi-email extraction failed:', e);
    toast.error('Failed to extract signatures from emails');
  } finally {
    extractingSignatures.value = false;
  }
}

async function deleteContact(contact) {
  const confirmed = window.confirm(`Delete contact "${contact.name || contact.email}"? This cannot be undone.`);
  if (!confirmed) return;
  
  // If contact has a real DB id, delete from server
  if (contact.id) {
    try {
      const response = await api.delete(`/clients/${props.clientId}/contacts/${contact.id}`);
      
      if (response.data.success) {
        await clientsStore.fetchClient(props.clientId);
        toast.success('Contact deleted');
      }
    } catch (e) {
      console.error('Failed to delete contact:', e);
      toast.error('Failed to delete contact');
    }
  } else {
    // Discovered-only contact (no DB entry) - remove from local discovered list
    discoveredContacts.value = discoveredContacts.value.filter(
      c => c.email.toLowerCase() !== contact.email.toLowerCase()
    );
    toast.success('Contact removed');
  }
}

// Open linked Drive folder
function openDriveFolder() {
  if (clientData.value?.drive_folder_id) {
    // If client has a linked folder, open that
    router.push(`/drive?folder=${clientData.value.drive_folder_id}`);
  } else {
    // Otherwise, just open the Drive (root)
    router.push('/drive');
  }
}

// Load Drive folders for linking modal. Uses the flat all-folders list
// and labels each entry with its parent path so same-named folders in
// different locations are distinguishable.
async function loadDriveFolders() {
  loadingDriveFolders.value = true;
  try {
    const all = await driveStore.fetchAllFolders();
    const byId = {};
    for (const f of all) byId[f.id] = f;
    driveFolders.value = all.map((f) => {
      const path = [];
      let cur = f;
      // Walk up at most a few levels to keep labels short.
      for (let depth = 0; depth < 4 && cur?.parent_id && byId[cur.parent_id]; depth++) {
        cur = byId[cur.parent_id];
        path.unshift(cur.name);
      }
      return { ...f, name: path.length ? `${path.join(' / ')} / ${f.name}` : f.name };
    });
  } catch (e) {
    console.error('Failed to load Drive folders:', e);
  } finally {
    loadingDriveFolders.value = false;
  }
}

// Link selected Drive folder
async function linkSelectedDriveFolder() {
  if (!selectedDriveFolderId.value || !props.clientId) return;
  
  const success = await clientsStore.linkDriveFolder(props.clientId, selectedDriveFolderId.value);
  if (success) {
    showLinkDriveModal.value = false;
    selectedDriveFolderId.value = null;
  }
}

// Sync Drive folder from linked boards
async function syncDriveFolder() {
  if (!props.clientId || syncingDriveFolder.value) return;
  
  syncingDriveFolder.value = true;
  try {
    const response = await api.post(`/clients/${props.clientId}/sync-drive-folder`);
    
    if (response.data.success) {
      // Reload client data to get updated drive_folder_id
      await clientsStore.fetchClient(props.clientId);
      
      if (response.data.data?.drive_folder_id) {
        toast.success(`Drive folder synced: ${response.data.data.folder_name || 'Success'}`);
      } else {
        toast.info('No Drive folder found to sync from boards');
      }
    } else {
      toast.error(response.data.message || 'Failed to sync folder');
    }
  } catch (error) {
    console.error('Failed to sync drive folder:', error);
    toast.error('Failed to sync Drive folder');
  } finally {
    syncingDriveFolder.value = false;
  }
}

// Create new folder and link it
async function createAndLinkFolder() {
  const clientName = clientData.value?.display_name || clientData.value?.domain || 'Client';
  try {
    // Create at Drive root explicitly (the store's createFolder would
    // default to the last-browsed Drive folder, which is wrong here)
    // and tag it with the client so its contents inherit the client.
    const response = await api.post('/drive/folders', {
      name: clientName,
      parent_id: null,
      client_id: props.clientId,
    });
    const folder = response.data?.data?.folder;
    if (!folder) throw new Error(response.data?.message || 'Create failed');
    await clientsStore.linkDriveFolder(props.clientId, folder.id);
    showLinkDriveModal.value = false;
    toast.success(`Linked Drive folder "${folder.name}"`);
  } catch (e) {
    console.error('Failed to create and link folder:', e);
    toast.error('Failed to create folder');
  }
}

// Unlink Drive folder
async function unlinkDriveFolder() {
  if (!props.clientId) return;
  await clientsStore.unlinkDriveFolder(props.clientId);
}

// Open board link modal
async function openLinkBoardModal() {
  await boardsStore.fetchBoards();
  selectedBoardToLink.value = null;
  showLinkBoardModal.value = true;
}

// Link selected board to client
async function linkSelectedBoard() {
  if (!props.clientId || !selectedBoardToLink.value) return;
  
  linkingBoard.value = true;
  try {
    const success = await clientsStore.linkBoard(props.clientId, selectedBoardToLink.value);
    if (success) {
      toast.success('Board linked successfully');
      showLinkBoardModal.value = false;
      selectedBoardToLink.value = null;
      // Refresh board stats
      await fetchBoardStats();
    } else {
      toast.error('Failed to link board');
    }
  } catch (error) {
    console.error('Failed to link board:', error);
    toast.error('Failed to link board');
  } finally {
    linkingBoard.value = false;
  }
}

// Unlink board from client
async function unlinkBoard(boardId) {
  if (!props.clientId || !boardId) return;
  
  const success = await clientsStore.unlinkBoard(props.clientId, boardId);
  if (success) {
    toast.success('Board unlinked');
    await fetchBoardStats();
  } else {
    toast.error('Failed to unlink board');
  }
}

// Watch for modal open to load folders
watch(showLinkDriveModal, (open) => {
  if (open) {
    loadDriveFolders();
    selectedDriveFolderId.value = clientData.value?.drive_folder_id || null;
  }
});

// Fetch board stats from linked boards
async function fetchBoardStats() {
  if (linkedBoards.value.length === 0) {
    boardStats.value = { openTasks: 0, overdueTasks: 0 };
    return;
  }
  
  let openTasks = 0;
  let overdueTasks = 0;
  const now = new Date();
  const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
  const missingBoardIds = [];
  
  for (const link of linkedBoards.value) {
    try {
      // Use silent mode to avoid logging 404 errors for deleted boards
      const board = await boardsStore.fetchBoard(link.board_id, { silent: true });
      if (board && board.lists) {
        for (const list of board.lists) {
          for (const card of list.cards || []) {
            if (!card.completed) {
              openTasks++;
              if (card.due_date && new Date(card.due_date) < today) {
                overdueTasks++;
              }
            }
          }
        }
      } else if (!board) {
        // Board was deleted - track for cleanup
        missingBoardIds.push(link.board_id);
      }
    } catch (e) {
      // Silent - board may have been deleted
    }
  }
  
  // Clean up stale board links
  for (const boardId of missingBoardIds) {
    try {
      await api.delete(`/clients/${props.clientId}/boards/${boardId}`);
    } catch (e) {
      // Ignore cleanup errors
    }
  }
  
  // Refresh client if we cleaned up any boards
  if (missingBoardIds.length > 0) {
    await clientsStore.fetchClient(props.clientId);
  }
  
  boardStats.value = { openTasks, overdueTasks };
}

// Fetch email statistics for the client
async function fetchEmailStats() {
  const domain = clientData.value?.domain;
  if (!domain) return;
  
  try {
    // Try to get stats from a dedicated endpoint
    const response = await api.get(`/clients/${props.clientId}/email-stats`);
    if (response.data.success) {
      const stats = response.data.data;
      emailStats.value = {
        totalEmails: stats.total_emails || 0,
        sentEmails: stats.sent_emails || 0,
        receivedEmails: stats.received_emails || 0,
        conversations: stats.conversations || 0,
        activeThreads: stats.active_threads || 0,
        theirResponseTime: formatResponseTime(stats.their_avg_response_hours),
        yourResponseTime: formatResponseTime(stats.your_avg_response_hours),
        lastOutboundAt: stats.last_outbound_at || null,
        lastInboundAt: stats.last_inbound_at || null
      };
      recentEmails.value = stats.recent_emails || [];
      
      // Use discovered contacts from email scan
      if (stats.discovered_contacts && stats.discovered_contacts.length > 0) {
        discoveredContacts.value = stats.discovered_contacts;
      }
    }
  } catch (e) {
    console.error('Failed to fetch email stats:', e);
    // Set defaults
    emailStats.value = {
      totalEmails: 0,
      sentEmails: 0,
      receivedEmails: 0,
      conversations: 0,
      activeThreads: 0,
      theirResponseTime: null,
      yourResponseTime: null,
      lastOutboundAt: null,
      lastInboundAt: null
    };
    discoveredContacts.value = [];
  }
}

function formatResponseTime(hours) {
  if (!hours || hours <= 0) return null;
  
  if (hours < 1) {
    const mins = Math.round(hours * 60);
    return `${mins}m`;
  } else if (hours < 24) {
    return `${Math.round(hours)}h`;
  } else {
    const days = Math.round(hours / 24);
    return `${days}d`;
  }
}

// Save all client info
async function saveClientInfo() {
  if (!props.clientId) return;
  
  const updates = {};
  if (editForm.value.display_name?.trim()) {
    updates.display_name = editForm.value.display_name.trim();
  }
  if (editForm.value.phone !== undefined) {
    updates.phone = editForm.value.phone?.trim() || null;
  }
  if (editForm.value.address !== undefined) {
    updates.address = editForm.value.address?.trim() || null;
  }
  if (editForm.value.notes !== undefined) {
    updates.notes = editForm.value.notes?.trim() || null;
  }
  if (editForm.value.payment_terms_days) {
    updates.payment_terms_days = editForm.value.payment_terms_days;
  }
  if (editForm.value.hourly_rate !== undefined) {
    updates.hourly_rate = editForm.value.hourly_rate || null;
  }
  // Billing/company fields
  const billingFields = [
    'billing_name', 'billing_tax_id', 'billing_eu_tax_id',
    'billing_address', 'billing_city', 'billing_zip', 'billing_country', 'billing_bank_account',
  ];
  for (const bf of billingFields) {
    if (editForm.value[bf] !== undefined) {
      updates[bf] = editForm.value[bf]?.trim() || null;
    }
  }
  
  await clientsStore.updateClient(props.clientId, updates);
  showEditModal.value = false;
}

// Fetch associated accounts
async function fetchAssociatedAccounts() {
  if (!props.clientId) return;
  associatedAccounts.value = await clientsStore.fetchAssociatedAccounts(props.clientId);
}

// Fetch time stats for team compact component
async function fetchTimeStats() {
  if (!props.clientId || !addons.timeTrackerEnabled.value) return;
  try {
    const response = await api.get(`/clients/${props.clientId}/time-stats`, { params: { period: 'week' } });
    if (response.data.success) {
      timeStats.value = response.data.data;
    }
  } catch (e) {
    console.error('Failed to fetch time stats:', e);
  }
}

// Fetch financials
async function fetchFinancials() {
  if (!props.clientId) return;
  financials.value = await clientsStore.fetchClientFinancials(props.clientId);
  
  // Auto-expand first board, or all boards if only 1-2 projects
  if (financials.value?.boards) {
    expandedBoards.value = {};
    financials.value.boards.forEach((board, idx) => {
      // Expand first board, or all if 2 or fewer
      if (idx === 0 || financials.value.boards.length <= 2) {
        expandedBoards.value[board.board_id] = true;
      }
    });
  }
}

// Fetch pipeline deals for this client
async function fetchClientDeals() {
  if (!props.clientId || !addons.crmProEnabled.value) return;
  const targetClientId = props.clientId;
  loadingDeals.value = true;
  try {
    const res = await api.get('/crm/deals', { params: { client_id: targetClientId } });
    // Guard: ignore result if client changed during fetch
    if (props.clientId !== targetClientId) return;
    if (res.data?.success) {
      const deals = res.data.data?.deals || [];
      // Safety filter: only keep deals that belong to this client
      clientDeals.value = deals.filter(d => Number(d.client_id) === Number(targetClientId));
    }
  } catch (e) {
    clientDeals.value = [];
  } finally {
    loadingDeals.value = false;
  }
}

// Fetch invoices for this client (Invoices tab)
async function fetchClientInvoices() {
  if (!props.clientId || !addons.crmProEnabled.value) return;
  loadingInvoices.value = true;
  try {
    const res = await api.get('/crm/invoices', { params: { client_id: props.clientId } });
    if (res.data?.success) {
      clientInvoices.value = res.data.data?.invoices || [];
      invoiceSummary.value = res.data.data?.summary || {};
    }
  } catch (e) {
    clientInvoices.value = [];
  } finally {
    loadingInvoices.value = false;
  }
}

// Filtered invoices for the Invoices tab
const filteredClientInvoices = computed(() => {
  if (invoiceFilter.value === 'all') return clientInvoices.value;
  return clientInvoices.value.filter(i => i.status === invoiceFilter.value);
});

const invoiceStatusCounts = computed(() => {
  const counts = { all: clientInvoices.value.length };
  for (const inv of clientInvoices.value) {
    counts[inv.status] = (counts[inv.status] || 0) + 1;
  }
  return counts;
});

// Group deals by stage for summary bar
const groupedDeals = computed(() => {
  const groups = {};
  for (const deal of clientDeals.value) {
    const stage = deal.pipeline_stage || 'lead';
    if (!groups[stage]) groups[stage] = [];
    groups[stage].push(deal);
  }
  return groups;
});

// Pipeline stage helpers
const pipelineStageLabels = {
  lead: 'Lead', contacted: 'Contacted', proposal: 'Proposal',
  negotiation: 'Negotiation', won: 'Won', lost: 'Lost',
};
const pipelineStageColors = {
  lead: 'bg-surface-200 dark:bg-surface-600 text-surface-700 dark:text-surface-300',
  contacted: 'bg-blue-100 dark:bg-blue-500/20 text-blue-700 dark:text-blue-300',
  proposal: 'bg-purple-100 dark:bg-purple-500/20 text-purple-700 dark:text-purple-300',
  negotiation: 'bg-amber-100 dark:bg-amber-500/20 text-amber-700 dark:text-amber-300',
  won: 'bg-green-100 dark:bg-green-500/20 text-green-700 dark:text-green-300',
  lost: 'bg-red-100 dark:bg-red-500/20 text-red-700 dark:text-red-300',
};

// Invoice status helpers
const invoiceStatusColors = {
  draft: 'text-surface-400 bg-surface-100 dark:bg-surface-700',
  sent: 'text-blue-600 bg-blue-50 dark:bg-blue-500/10',
  viewed: 'text-cyan-600 bg-cyan-50 dark:bg-cyan-500/10',
  partial: 'text-amber-600 bg-amber-50 dark:bg-amber-500/10',
  paid: 'text-green-600 bg-green-50 dark:bg-green-500/10',
  overdue: 'text-red-600 bg-red-50 dark:bg-red-500/10',
  cancelled: 'text-surface-400 bg-surface-100 dark:bg-surface-700',
  refunded: 'text-purple-600 bg-purple-50 dark:bg-purple-500/10',
};
const invoiceStatusIcons = {
  draft: 'edit_note', sent: 'send', viewed: 'visibility', partial: 'payments',
  paid: 'check_circle', overdue: 'warning', cancelled: 'cancel', refunded: 'undo',
};

function formatInvoiceMoney(amount, currency = 'HUF') {
  return new Intl.NumberFormat('hu-HU', { style: 'currency', currency, maximumFractionDigits: 0 }).format(amount || 0);
}

function formatInvoiceDate(d) {
  if (!d) return '';
  return new Date(d).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
}

async function deleteInvoice(inv) {
  if (!confirm(`Delete draft invoice ${inv.invoice_number}?`)) return;
  try {
    await api.delete(`/crm/invoices/${inv.id}`);
    toast.success('Invoice deleted');
    fetchClientInvoices();
  } catch (e) {
    toast.error(e.response?.data?.message || 'Failed to delete');
  }
}

async function sendInvoice(inv) {
  try {
    await api.post(`/crm/invoices/${inv.id}/send`);
    toast.success('Invoice marked as sent');
    fetchClientInvoices();
  } catch (e) {
    toast.error(e.response?.data?.message || 'Failed to send');
  }
}

function createNewInvoice() {
  editingInvoice.value = null;
  showInvoiceEditor.value = true;
}

function editInvoice(inv) {
  editingInvoice.value = inv;
  showInvoiceEditor.value = true;
}

function viewInvoice(inv) {
  previewInvoice.value = inv;
  showInvoicePreview.value = true;
}

function onInvoiceSaved() {
  showInvoiceEditor.value = false;
  editingInvoice.value = null;
  fetchClientInvoices();
}

// Format currency with specific currency code (commas as thousand separator)
function formatCurrencyWithCode(amount, currency = 'EUR') {
  if (!amount && amount !== 0) return '-';
  // Use commas for all currencies
  if (currency === 'HUF') {
    const formatted = new Intl.NumberFormat('en-US', {
      minimumFractionDigits: 0,
      maximumFractionDigits: 0
    }).format(amount);
    return `${formatted} Ft`;
  }
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: currency,
    minimumFractionDigits: 2,
    maximumFractionDigits: 2
  }).format(amount);
}

// Format hourly rate (uses client currency)
function formatHourlyRate(rate, currency = 'HUF') {
  if (!rate && rate !== 0) return '-';
  if (currency === 'HUF') {
    const formatted = new Intl.NumberFormat('en-US', {
      minimumFractionDigits: 0,
      maximumFractionDigits: 0
    }).format(rate);
    return `${formatted} Ft`;
  }
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: currency,
    minimumFractionDigits: 2,
    maximumFractionDigits: 2
  }).format(rate);
}

// Format number for input display (with commas)
function formatNumberInput(value) {
  if (!value && value !== 0) return '';
  return new Intl.NumberFormat('en-US', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 0
  }).format(value);
}

// Parse number input (remove commas)
function parseNumberInput(value) {
  if (!value) return null;
  const cleaned = value.replace(/[^0-9.-]/g, '');
  const num = parseFloat(cleaned);
  return isNaN(num) ? null : num;
}

// Format currency (legacy - uses EUR)
function formatCurrency(amount) {
  if (!amount && amount !== 0) return '-';
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'EUR',
    minimumFractionDigits: 0,
    maximumFractionDigits: 0
  }).format(amount);
}

// Format date
function formatDate(dateStr) {
  if (!dateStr) return '-';
  return new Date(dateStr).toLocaleDateString('en-US', {
    month: 'short',
    day: 'numeric'
  });
}

// Format month
function formatMonth(monthStr) {
  if (!monthStr) return '-';
  const [year, month] = monthStr.split('-');
  return new Date(year, month - 1).toLocaleDateString('en-US', {
    month: 'short',
    year: '2-digit'
  });
}

// Promote associated account to full client
async function promoteAssociated(assocId) {
  await clientsStore.promoteToClient(assocId);
  // Refresh associated accounts
  await fetchAssociatedAccounts();
}

// Merge clients
async function performMerge() {
  if (!mergeTargetId.value || !props.clientId) return;
  
  // Current client is primary, merge target is secondary (will be deleted)
  await clientsStore.mergeClients(props.clientId, mergeTargetId.value);
  showMergeModal.value = false;
  mergeTargetId.value = null;
  
  // Refresh current client data
  await clientsStore.fetchClient(props.clientId);
  await fetchAssociatedAccounts();
}

// Remove domain alias
async function removeAlias(aliasId, aliasDomain) {
  if (!aliasId) return;
  try {
    const res = await api.delete(`/clients/${props.clientId}/aliases/${aliasId}`);
    if (res.data?.success) {
      toast.success(`Domain "${aliasDomain}" unlinked. It will create its own client on next sync.`);
      await clientsStore.fetchClient(props.clientId);
    } else {
      toast.error(res.data?.message || 'Failed to remove alias');
    }
  } catch (e) {
    toast.error(e.response?.data?.message || 'Failed to remove alias');
  }
}

// Initialize edit form when modal opens
watch(showEditModal, (show) => {
  if (show && clientData.value) {
    editForm.value = {
      display_name: clientData.value.display_name || '',
      phone: clientData.value.phone || '',
      address: clientData.value.address || '',
      notes: clientData.value.notes || '',
      payment_terms_days: clientData.value.payment_terms_days || 30,
      hourly_rate: clientData.value.hourly_rate || null,
      billing_name: clientData.value.billing_name || '',
      billing_tax_id: clientData.value.billing_tax_id || '',
      billing_eu_tax_id: clientData.value.billing_eu_tax_id || '',
      billing_address: clientData.value.billing_address || '',
      billing_city: clientData.value.billing_city || '',
      billing_zip: clientData.value.billing_zip || '',
      billing_country: clientData.value.billing_country || 'HU',
      billing_bank_account: clientData.value.billing_bank_account || '',
    };
  }
});
</script>
