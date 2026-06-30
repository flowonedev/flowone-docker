<template>
  <div class="statistics-tab">
    <!-- Header with Period Selector -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
      <div class="flex items-center justify-between sm:block">
        <div>
          <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-100">Statistics</h2>
          <p class="text-sm text-surface-500 mt-0.5 hidden sm:block">
            Complete analytics of your email, calendar, drive, and productivity
          </p>
        </div>
        <!-- Refresh Button (mobile: inline with title) -->
        <button
          @click="refresh"
          :disabled="loading"
          class="p-2 rounded-full hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors sm:hidden"
          title="Refresh statistics"
        >
          <span 
            class="material-symbols-rounded text-xl text-surface-500"
            :class="{ 'animate-spin': loading }"
          >refresh</span>
        </button>
      </div>
      
      <div class="flex items-center gap-3">
        <!-- Period Selector -->
        <div class="flex bg-surface-100 dark:bg-surface-700 rounded-full p-1 flex-1 sm:flex-none">
          <button
            v-for="p in periods"
            :key="p.value"
            @click="setPeriod(p.value)"
            :class="[
              'flex-1 sm:flex-none px-3 py-1.5 text-sm font-medium rounded-full transition-all text-center',
              period === p.value
                ? 'bg-white dark:bg-surface-600 text-primary-600 dark:text-primary-400 shadow-sm'
                : 'text-surface-600 dark:text-surface-300 hover:text-surface-900 dark:hover:text-surface-100'
            ]"
          >
            {{ p.label }}
          </button>
        </div>
        
        <!-- Refresh Button (desktop) -->
        <button
          @click="refresh"
          :disabled="loading"
          class="p-2 rounded-full hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors hidden sm:flex"
          title="Refresh statistics"
        >
          <span 
            class="material-symbols-rounded text-xl text-surface-500"
            :class="{ 'animate-spin': loading }"
          >refresh</span>
        </button>
      </div>
    </div>
    
    <!-- Last Updated -->
    <p v-if="lastUpdated" class="text-xs text-surface-400 mb-4">
      Last updated: {{ formatDate(lastUpdated) }}
    </p>
    
    <!-- Loading State -->
    <div v-if="loading && !hasData" class="flex items-center justify-center py-20">
      <div class="text-center">
        <span class="material-symbols-rounded text-4xl text-primary-500 animate-spin">progress_activity</span>
        <p class="text-surface-500 mt-2">Loading statistics...</p>
      </div>
    </div>
    
    <!-- Error State -->
    <div v-else-if="error" class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-xl p-6 text-center">
      <span class="material-symbols-rounded text-3xl text-red-500">error</span>
      <p class="text-red-600 dark:text-red-400 mt-2">{{ error }}</p>
      <button 
        @click="refresh"
        class="mt-4 px-4 py-2 bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300 rounded-full text-sm font-medium hover:bg-red-200 dark:hover:bg-red-900/50 transition-colors"
      >
        Try Again
      </button>
    </div>
    
    <!-- Statistics Content -->
    <div v-else class="space-y-8">
      
      <!-- ==================== EMAIL SECTION ==================== -->
      <section>
        <div class="flex items-center gap-2 mb-4">
          <span class="material-symbols-rounded text-primary-500">mail</span>
          <h3 class="text-base font-semibold text-surface-900 dark:text-surface-100">Email Statistics</h3>
        </div>
        
        <!-- Email Overview Cards -->
        <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-3 sm:gap-4 mb-6">
          <StatCard title="Sent" :value="emailsSent" icon="send" color="primary" />
          <StatCard title="Unique Recipients" :value="emailStats?.totals?.total_recipients || 0" icon="group" color="blue" />
          <StatCard v-if="emailTrackingEnabled" title="Read by Recipients" :value="emailStats?.totals?.emails_read || 0" icon="mark_email_read" color="green" />
          <StatCard title="Moved" :value="emailStats?.totals?.emails_moved || 0" icon="drive_file_move" color="amber" />
          <StatCard title="Deleted" :value="emailStats?.totals?.emails_deleted || 0" icon="delete" color="red" />
          <StatCard title="Avg Reply" :value="avgReplyTime || 'N/A'" icon="timer" color="purple" :is-text="true" />
        </div>
        
        <!-- Email Charts -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
          <!-- Email Activity Chart -->
          <div class="bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700 p-4 sm:p-5">
            <h4 class="text-sm font-semibold text-surface-900 dark:text-surface-100 mb-4">Emails Sent Over Time</h4>
            <apexchart
              v-if="isMounted && emailChartOptions && emailChartSeries.length > 0 && emailChartSeries[0].data?.length > 0"
              :key="'email-' + period"
              type="area"
              height="280"
              :options="emailChartOptions"
              :series="emailChartSeries"
            />
            <div v-else class="h-[280px] flex items-center justify-center text-surface-400 flex-col">
              <span class="material-symbols-rounded text-4xl mb-2">show_chart</span>
              <p>Send emails to see activity chart</p>
              <p class="text-xs mt-1">Emails sent with tracking enabled will appear here</p>
            </div>
          </div>
          
          <!-- Top Contacts -->
          <div class="bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700 p-4 sm:p-5">
            <h4 class="text-sm font-semibold text-surface-900 dark:text-surface-100 mb-4">People You Email Most</h4>
            <apexchart
              v-if="isMounted && contactsChartOptions && topContacts.length > 0"
              :key="'contacts-' + period"
              type="bar"
              height="280"
              :options="contactsChartOptions"
              :series="contactsChartSeries"
            />
            <div v-else class="h-[280px] flex items-center justify-center text-surface-400 flex-col">
              <span class="material-symbols-rounded text-4xl mb-2">contacts</span>
              <p>Send emails to see your top contacts</p>
            </div>
          </div>
        </div>
        
        <!-- Top Contacts List -->
        <div class="bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700 p-4 sm:p-5 mt-6">
          <h4 class="text-sm font-semibold text-surface-900 dark:text-surface-100 mb-4">Frequently Contacted</h4>
          <div v-if="topContacts.length > 0" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
            <div
              v-for="(contact, index) in topContacts.slice(0, 9)"
              :key="contact.contact_email"
              class="flex items-center gap-3 p-3 bg-surface-50 dark:bg-surface-700/50 rounded-lg"
            >
              <div class="w-8 h-8 rounded-full bg-primary-100 dark:bg-primary-500/20 flex items-center justify-center text-primary-600 dark:text-primary-400 font-bold text-sm">
                {{ index + 1 }}
              </div>
              <div class="flex-1 min-w-0">
                <p class="text-sm font-medium text-surface-900 dark:text-surface-100 truncate">
                  {{ contact.contact_name || contact.contact_email.split('@')[0] }}
                </p>
                <p class="text-xs text-surface-500 truncate">{{ contact.contact_email }}</p>
              </div>
              <div class="text-right">
                <p class="text-lg font-bold text-primary-600 dark:text-primary-400">{{ contact.emails_sent || contact.total_emails }}</p>
                <p class="text-[10px] text-surface-400">emails</p>
              </div>
            </div>
          </div>
          <div v-else class="py-8 text-center text-surface-400">
            <span class="material-symbols-rounded text-3xl mb-2">forum</span>
            <p>Contact data will appear as you send emails</p>
          </div>
        </div>
      </section>
      
      <!-- ==================== READ RECEIPTS SECTION ==================== -->
      <section v-if="emailTrackingEnabled">
        <div class="flex items-center gap-2 mb-4">
          <span class="material-symbols-rounded text-green-500">visibility</span>
          <h3 class="text-base font-semibold text-surface-900 dark:text-surface-100">Email Read Tracking</h3>
        </div>
        
        <div class="bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700 p-4 sm:p-5">
          <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <div class="text-center p-4 bg-green-50 dark:bg-green-900/20 rounded-xl">
              <span class="material-symbols-rounded text-3xl text-green-600 dark:text-green-400">mark_email_read</span>
              <p class="text-2xl font-bold text-green-700 dark:text-green-300 mt-2">{{ readReceipts?.total_read || 0 }}</p>
              <p class="text-sm text-green-600 dark:text-green-400">Emails Read</p>
            </div>
            <div class="text-center p-4 bg-blue-50 dark:bg-blue-900/20 rounded-xl">
              <span class="material-symbols-rounded text-3xl text-blue-600 dark:text-blue-400">speed</span>
              <p class="text-2xl font-bold text-blue-700 dark:text-blue-300 mt-2">{{ formatReadTime(readReceipts?.avg_read_time) }}</p>
              <p class="text-sm text-blue-600 dark:text-blue-400">Avg Time to Read</p>
            </div>
            <div class="text-center p-4 bg-purple-50 dark:bg-purple-900/20 rounded-xl">
              <span class="material-symbols-rounded text-3xl text-purple-600 dark:text-purple-400">emoji_events</span>
              <p class="text-lg font-bold text-purple-700 dark:text-purple-300 mt-2 truncate">{{ readReceipts?.fastest_reader || 'N/A' }}</p>
              <p class="text-sm text-purple-600 dark:text-purple-400">Fastest Reader</p>
            </div>
          </div>
          
          <!-- Fastest Readers List -->
          <div v-if="readReceipts?.top_readers?.length > 0" class="mt-6">
            <h5 class="text-sm font-medium text-surface-700 dark:text-surface-300 mb-3">Who Reads Your Emails Fastest</h5>
            <div class="space-y-2">
              <div 
                v-for="reader in readReceipts.top_readers.slice(0, 5)" 
                :key="reader.email"
                class="flex items-center gap-3 p-2 bg-surface-50 dark:bg-surface-700 rounded-lg"
              >
                <div class="w-8 h-8 rounded-full bg-primary-100 dark:bg-primary-500/20 flex items-center justify-center text-primary-600 dark:text-primary-400 text-sm font-medium">
                  {{ (reader.name || reader.email)[0].toUpperCase() }}
                </div>
                <div class="flex-1 min-w-0">
                  <p class="text-sm font-medium text-surface-900 dark:text-surface-100 truncate">{{ reader.name || reader.email }}</p>
                </div>
                <div class="text-right">
                  <p class="text-sm font-medium text-green-600">{{ formatReadTime(reader.avg_time) }}</p>
                  <p class="text-xs text-surface-400">avg time</p>
                </div>
              </div>
            </div>
          </div>
          <div v-else class="mt-4 text-center text-surface-400 py-4">
            <p class="text-sm">Read tracking data will appear when recipients open your tracked emails</p>
          </div>
        </div>
      </section>
      
      <!-- ==================== FOLDERS SECTION ==================== -->
      <section>
        <div class="flex items-center gap-2 mb-4">
          <span class="material-symbols-rounded text-amber-500">folder</span>
          <h3 class="text-base font-semibold text-surface-900 dark:text-surface-100">Folder Statistics</h3>
        </div>
        
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
          <!-- Folder Distribution (Real email counts) -->
          <div class="bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700 p-4 sm:p-5">
            <h4 class="text-sm font-semibold text-surface-900 dark:text-surface-100 mb-4">Email Distribution by Folder</h4>
            <apexchart
              v-if="isMounted && realFolderChartOptions && realFolderStats.length > 0"
              :key="'folders-' + realFolderStats.length"
              type="donut"
              height="280"
              :options="realFolderChartOptions"
              :series="realFolderChartSeries"
            />
            <div v-else class="h-[280px] flex items-center justify-center text-surface-400 flex-col">
              <span class="material-symbols-rounded text-4xl mb-2">folder_open</span>
              <p>Loading folder data...</p>
            </div>
          </div>
          
          <!-- Folders by Email Count -->
          <div class="bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700 p-4 sm:p-5">
            <h4 class="text-sm font-semibold text-surface-900 dark:text-surface-100 mb-4">Folders by Email Count</h4>
            <div v-if="realFolderStats.length > 0" class="space-y-3">
              <div 
                v-for="(folder, index) in realFolderStats.slice(0, 8)" 
                :key="folder.folder"
                class="flex items-center gap-3"
              >
                <div class="w-6 h-6 rounded bg-amber-100 dark:bg-amber-500/20 flex items-center justify-center text-amber-600 dark:text-amber-400 text-xs font-bold">
                  {{ index + 1 }}
                </div>
                <span class="material-symbols-rounded text-surface-400 text-lg">{{ getFolderIcon(folder.type) }}</span>
                <div class="flex-1 min-w-0">
                  <p class="text-sm font-medium text-surface-900 dark:text-surface-100 truncate">{{ folder.folder }}</p>
                  <p v-if="folder.unread > 0" class="text-xs text-primary-500">{{ folder.unread }} unread</p>
                </div>
                <div class="flex items-center gap-2">
                  <div class="w-24 h-2 bg-surface-200 dark:bg-surface-600 rounded-full overflow-hidden">
                    <div 
                      class="h-full bg-amber-500 rounded-full"
                      :style="{ width: `${(folder.total / (realFolderStats[0]?.total || 1)) * 100}%` }"
                    ></div>
                  </div>
                  <span class="text-sm text-surface-500 w-16 text-right">{{ formatNumber(folder.total) }}</span>
                </div>
              </div>
            </div>
            <div v-else class="h-[280px] flex items-center justify-center text-surface-400 flex-col">
              <span class="material-symbols-rounded text-4xl mb-2">folder</span>
              <p>Loading folder data...</p>
            </div>
          </div>
        </div>
        
        <!-- Total Email Summary -->
        <div v-if="realFolderStats.length > 0" class="mt-4 grid grid-cols-2 md:grid-cols-4 gap-4">
          <div class="bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700 p-4 text-center">
            <p class="text-2xl font-bold text-surface-900 dark:text-surface-100">{{ formatNumber(totalEmailsInFolders) }}</p>
            <p class="text-xs text-surface-500">Total Emails</p>
          </div>
          <div class="bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700 p-4 text-center">
            <p class="text-2xl font-bold text-primary-600 dark:text-primary-400">{{ formatNumber(totalUnreadEmails) }}</p>
            <p class="text-xs text-surface-500">Unread</p>
          </div>
          <div class="bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700 p-4 text-center">
            <p class="text-2xl font-bold text-surface-900 dark:text-surface-100">{{ mailboxStore.folders?.length || 0 }}</p>
            <p class="text-xs text-surface-500">Total Folders</p>
          </div>
          <div class="bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700 p-4 text-center">
            <p class="text-2xl font-bold text-surface-900 dark:text-surface-100">{{ realFolderStats.length }}</p>
            <p class="text-xs text-surface-500">Folders with Emails</p>
          </div>
        </div>
      </section>
      
      <!-- ==================== CALENDAR SECTION ==================== -->
      <section v-if="calendarEnabled">
        <div class="flex items-center gap-2 mb-4">
          <span class="material-symbols-rounded text-blue-500">calendar_month</span>
          <h3 class="text-base font-semibold text-surface-900 dark:text-surface-100">Calendar Statistics</h3>
        </div>
        
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 sm:gap-4 mb-6">
          <StatCard title="Events Created" :value="calendarStats?.events_created || 0" icon="event" color="blue" />
          <StatCard title="Upcoming" :value="calendarStats?.upcoming_events || 0" icon="upcoming" color="green" />
          <StatCard title="This Week" :value="calendarStats?.events_this_week || 0" icon="date_range" color="amber" />
          <StatCard title="Recurring" :value="calendarStats?.recurring_events || 0" icon="event_repeat" color="purple" />
        </div>
        
        <div class="bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700 p-4 sm:p-5">
          <h4 class="text-sm font-semibold text-surface-900 dark:text-surface-100 mb-4">Event Activity</h4>
          <apexchart
            v-if="isMounted && calendarChartOptions && calendarChartData.length > 0 && calendarChartData.some(v => v > 0)"
            :key="'calendar-' + period"
            type="bar"
            height="200"
            :options="calendarChartOptions"
            :series="[{ name: 'Events', data: calendarChartData }]"
          />
          <div v-else class="h-[200px] flex items-center justify-center text-surface-400 flex-col">
            <span class="material-symbols-rounded text-4xl mb-2">event</span>
            <p>Create calendar events to see activity trends</p>
          </div>
        </div>
      </section>
      
      <!-- ==================== DRIVE SECTION ==================== -->
      <section>
        <div class="flex items-center gap-2 mb-4">
          <span class="material-symbols-rounded text-teal-500">cloud</span>
          <h3 class="text-base font-semibold text-surface-900 dark:text-surface-100">Drive Statistics</h3>
        </div>
        
        <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-3 sm:gap-4 mb-6">
          <StatCard title="Total Files" :value="driveStats?.total_files || 0" icon="description" color="teal" />
          <StatCard title="Total Folders" :value="driveStats?.total_folders || 0" icon="folder" color="blue" />
          <StatCard title="Space Used" :value="formatBytes(driveStats?.used_space || 0)" icon="database" color="amber" :is-text="true" />
          <StatCard title="Shared Files" :value="driveStats?.shared_files || 0" icon="share" color="green" />
          <StatCard title="Shared Folders" :value="driveStats?.shared_folders || 0" icon="folder_shared" color="purple" />
          <StatCard title="Email Attachments" :value="driveStats?.email_attachments || 0" icon="attach_email" color="red" />
        </div>
        
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
          <!-- Storage Usage & Recent Activity -->
          <div class="bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700 p-4 sm:p-5">
            <h4 class="text-sm font-semibold text-surface-900 dark:text-surface-100 mb-4">Storage Overview</h4>
            <div class="space-y-4">
              <div v-if="driveStats?.total_space !== -1">
                <div class="flex justify-between text-sm mb-2">
                  <span class="text-surface-600 dark:text-surface-400">Used Space</span>
                  <span class="font-medium text-surface-900 dark:text-surface-100">
                    {{ formatBytes(driveStats?.used_space || 0) }} / {{ formatBytes(driveStats?.total_space || 5368709120) }}
                  </span>
                </div>
                <div class="h-4 bg-surface-200 dark:bg-surface-600 rounded-full overflow-hidden">
                  <div 
                    class="h-full bg-gradient-to-r from-teal-500 to-teal-400 rounded-full transition-all"
                    :style="{ width: `${Math.min((driveStats?.used_space || 0) / (driveStats?.total_space || 5368709120) * 100, 100)}%` }"
                  ></div>
                </div>
              </div>
              <div v-else class="text-center py-2">
                <p class="text-2xl font-bold text-teal-700 dark:text-teal-300">{{ formatBytes(driveStats?.used_space || 0) }}</p>
                <p class="text-xs text-surface-500">Total Storage Used (Unlimited)</p>
              </div>
              
              <div class="grid grid-cols-3 gap-3 pt-4">
                <div class="text-center p-3 bg-teal-50 dark:bg-teal-900/20 rounded-lg">
                  <p class="text-xl font-bold text-teal-700 dark:text-teal-300">{{ driveStats?.recent_uploads || 0 }}</p>
                  <p class="text-xs text-teal-600 dark:text-teal-400">Uploaded this {{ period }}</p>
                </div>
                <div class="text-center p-3 bg-red-50 dark:bg-red-900/20 rounded-lg">
                  <p class="text-xl font-bold text-red-700 dark:text-red-300">{{ driveStats?.large_files || 0 }}</p>
                  <p class="text-xs text-red-600 dark:text-red-400">Large Files (>10MB)</p>
                </div>
                <div class="text-center p-3 bg-purple-50 dark:bg-purple-900/20 rounded-lg">
                  <p class="text-xl font-bold text-purple-700 dark:text-purple-300">{{ (driveStats?.shared_files || 0) + (driveStats?.shared_folders || 0) }}</p>
                  <p class="text-xs text-purple-600 dark:text-purple-400">Total Shared</p>
                </div>
              </div>
            </div>
          </div>
          
          <!-- File Types -->
          <div class="bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700 p-4 sm:p-5">
            <h4 class="text-sm font-semibold text-surface-900 dark:text-surface-100 mb-4">Files by Type</h4>
            <apexchart
              v-if="isMounted && driveTypeChartOptions && driveFileTypes.length > 0"
              :key="'drive-' + driveFileTypes.length"
              type="donut"
              height="220"
              :options="driveTypeChartOptions"
              :series="driveFileTypes.map(t => t.count)"
            />
            <div v-else class="h-[220px] flex items-center justify-center text-surface-400 flex-col">
              <span class="material-symbols-rounded text-4xl mb-2">insert_drive_file</span>
              <p>Upload files to see type distribution</p>
            </div>
          </div>
        </div>
        
        <!-- Top Folders -->
        <div v-if="driveStats?.top_folders?.length" class="bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700 p-4 sm:p-5 mt-6">
          <h4 class="text-sm font-semibold text-surface-900 dark:text-surface-100 mb-4">Top Folders by Content</h4>
          <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-3">
            <div 
              v-for="folder in driveStats.top_folders.slice(0, 10)" 
              :key="folder.id"
              class="flex items-center gap-3 p-3 bg-surface-50 dark:bg-surface-700/50 rounded-lg"
            >
              <span class="material-symbols-rounded text-teal-500">folder</span>
              <div class="flex-1 min-w-0">
                <p class="text-sm font-medium text-surface-900 dark:text-surface-100 truncate">{{ folder.name }}</p>
                <p class="text-xs text-surface-500">{{ folder.file_count }} files - {{ formatBytes(folder.total_size) }}</p>
              </div>
            </div>
          </div>
        </div>
      </section>
      
      <!-- ==================== BOARDS SECTION ==================== -->
      <section v-if="kanbanBoardsEnabled">
        <div class="flex items-center gap-2 mb-4">
          <span class="material-symbols-rounded text-indigo-500">dashboard</span>
          <h3 class="text-base font-semibold text-surface-900 dark:text-surface-100">Board Statistics</h3>
        </div>
        
        <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-3 sm:gap-4 mb-6">
          <StatCard title="Boards" :value="boardStats?.total_boards || 0" icon="dashboard" color="primary" />
          <StatCard title="Total Cards" :value="boardStats?.total_cards || 0" icon="view_kanban" color="blue" />
          <StatCard title="Completed" :value="boardStats?.completed_cards || 0" icon="check_circle" color="green" />
          <StatCard title="Pending" :value="boardStats?.pending_cards || 0" icon="pending" color="amber" />
          <StatCard title="Overdue" :value="boardStats?.overdue_cards || 0" icon="warning" color="red" />
          <StatCard title="Due This Week" :value="boardStats?.cards_due_this_week || 0" icon="event" color="purple" />
        </div>
        
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
          <!-- Board Overview -->
          <div class="bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700 p-4 sm:p-5">
            <h4 class="text-sm font-semibold text-surface-900 dark:text-surface-100 mb-4">Board Activity</h4>
            <div class="space-y-4">
              <div class="grid grid-cols-2 gap-4">
                <div class="text-center p-4 bg-indigo-50 dark:bg-indigo-900/20 rounded-xl">
                  <span class="material-symbols-rounded text-3xl text-indigo-600 dark:text-indigo-400">add_task</span>
                  <p class="text-2xl font-bold text-indigo-700 dark:text-indigo-300 mt-2">{{ boardStats?.cards_created_period || 0 }}</p>
                  <p class="text-xs text-indigo-600 dark:text-indigo-400">Created this {{ period }}</p>
                </div>
                <div class="text-center p-4 bg-green-50 dark:bg-green-900/20 rounded-xl">
                  <span class="material-symbols-rounded text-3xl text-green-600 dark:text-green-400">task_alt</span>
                  <p class="text-2xl font-bold text-green-700 dark:text-green-300 mt-2">{{ boardStats?.cards_completed_period || 0 }}</p>
                  <p class="text-xs text-green-600 dark:text-green-400">Completed this {{ period }}</p>
                </div>
              </div>
              
              <div class="pt-2">
                <div class="flex justify-between text-sm mb-2">
                  <span class="text-surface-600 dark:text-surface-400">Completion Rate</span>
                  <span class="font-medium text-surface-900 dark:text-surface-100">{{ boardStats?.completion_rate || 0 }}%</span>
                </div>
                <div class="h-3 bg-surface-200 dark:bg-surface-600 rounded-full overflow-hidden">
                  <div 
                    class="h-full bg-gradient-to-r from-green-500 to-green-400 rounded-full transition-all"
                    :style="{ width: `${boardStats?.completion_rate || 0}%` }"
                  ></div>
                </div>
              </div>
              
              <div class="grid grid-cols-3 gap-3 pt-2">
                <div class="text-center p-2 bg-surface-50 dark:bg-surface-700/50 rounded-lg">
                  <p class="text-lg font-bold text-surface-700 dark:text-surface-300">{{ boardStats?.total_lists || 0 }}</p>
                  <p class="text-xs text-surface-500">Lists</p>
                </div>
                <div class="text-center p-2 bg-surface-50 dark:bg-surface-700/50 rounded-lg">
                  <p class="text-lg font-bold text-surface-700 dark:text-surface-300">{{ boardStats?.total_checklists || 0 }}</p>
                  <p class="text-xs text-surface-500">Checklists</p>
                </div>
                <div class="text-center p-2 bg-surface-50 dark:bg-surface-700/50 rounded-lg">
                  <p class="text-lg font-bold text-surface-700 dark:text-surface-300">{{ boardStats?.total_comments || 0 }}</p>
                  <p class="text-xs text-surface-500">Comments</p>
                </div>
              </div>
            </div>
          </div>
          
          <!-- Upcoming Deadlines -->
          <div class="bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700 p-4 sm:p-5">
            <h4 class="text-sm font-semibold text-surface-900 dark:text-surface-100 mb-4">Upcoming Deadlines</h4>
            <div v-if="boardStats?.upcoming_deadlines?.length" class="space-y-2 max-h-[280px] overflow-y-auto">
              <div 
                v-for="card in boardStats.upcoming_deadlines" 
                :key="card.id"
                class="flex items-center gap-3 p-2 bg-surface-50 dark:bg-surface-700/50 rounded-lg"
              >
                <div class="flex-shrink-0">
                  <span 
                    class="material-symbols-rounded text-lg"
                    :class="getDeadlineColor(card.due_date)"
                  >schedule</span>
                </div>
                <div class="flex-1 min-w-0">
                  <p class="text-sm font-medium text-surface-900 dark:text-surface-100 truncate">{{ card.title }}</p>
                  <p class="text-xs text-surface-500 truncate">{{ card.board_name }} / {{ card.list_name }}</p>
                </div>
                <div class="text-right flex-shrink-0">
                  <p class="text-xs font-medium" :class="getDeadlineColor(card.due_date)">{{ formatDeadline(card.due_date) }}</p>
                </div>
              </div>
            </div>
            <div v-else class="h-[280px] flex items-center justify-center text-surface-400 flex-col">
              <span class="material-symbols-rounded text-4xl mb-2">event_available</span>
              <p>No upcoming deadlines</p>
            </div>
          </div>
        </div>
        
        <!-- Boards List -->
        <div v-if="boardStats?.boards?.length" class="bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700 p-4 sm:p-5 mt-6">
          <h4 class="text-sm font-semibold text-surface-900 dark:text-surface-100 mb-4">Your Boards</h4>
          <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            <div 
              v-for="board in boardStats.boards" 
              :key="board.id"
              class="p-4 bg-surface-50 dark:bg-surface-700/50 rounded-lg"
            >
              <div class="flex items-center gap-2 mb-3">
                <span class="material-symbols-rounded text-indigo-500">dashboard</span>
                <p class="font-medium text-surface-900 dark:text-surface-100 truncate">{{ board.name }}</p>
              </div>
              <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 text-center">
                <div>
                  <p class="text-lg font-bold text-surface-700 dark:text-surface-300">{{ board.list_count }}</p>
                  <p class="text-[10px] text-surface-500">Lists</p>
                </div>
                <div>
                  <p class="text-lg font-bold text-blue-600 dark:text-blue-400">{{ board.total_cards }}</p>
                  <p class="text-[10px] text-surface-500">Cards</p>
                </div>
                <div>
                  <p class="text-lg font-bold text-green-600 dark:text-green-400">{{ board.completed_cards }}</p>
                  <p class="text-[10px] text-surface-500">Done</p>
                </div>
                <div>
                  <p class="text-lg font-bold text-red-600 dark:text-red-400">{{ board.overdue_cards }}</p>
                  <p class="text-[10px] text-surface-500">Overdue</p>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>
      
      <!-- ==================== CLIENTS SECTION ==================== -->
      <section v-if="clientStats?.total_clients > 0">
        <div class="flex items-center gap-2 mb-4">
          <span class="material-symbols-rounded text-orange-500">group</span>
          <h3 class="text-base font-semibold text-surface-900 dark:text-surface-100">Client Statistics</h3>
        </div>
        
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 sm:gap-4 mb-6">
          <StatCard title="Total Clients" :value="clientStats?.total_clients || 0" icon="group" color="amber" />
          <StatCard title="Added This Period" :value="clientStats?.clients_added_period || 0" icon="person_add" color="green" />
          <StatCard title="With Boards" :value="clientStats?.clients_with_boards || 0" icon="dashboard" color="primary" />
          <StatCard title="With Drive Folder" :value="clientStats?.clients_with_drive_folder || 0" icon="folder" color="teal" />
        </div>
        
        <!-- Top Clients by Activity -->
        <div v-if="clientStats?.top_clients?.length" class="bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700 p-4 sm:p-5">
          <h4 class="text-sm font-semibold text-surface-900 dark:text-surface-100 mb-4">Clients with Most Active Tasks</h4>
          <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
            <div 
              v-for="client in clientStats.top_clients" 
              :key="client.id"
              class="flex items-center gap-3 p-3 bg-surface-50 dark:bg-surface-700/50 rounded-lg"
            >
              <div class="w-10 h-10 rounded-full bg-orange-100 dark:bg-orange-500/20 flex items-center justify-center text-orange-600 dark:text-orange-400 font-bold">
                {{ (client.display_name || client.email)[0].toUpperCase() }}
              </div>
              <div class="flex-1 min-w-0">
                <p class="text-sm font-medium text-surface-900 dark:text-surface-100 truncate">{{ client.display_name || client.email.split('@')[0] }}</p>
                <p class="text-xs text-surface-500">{{ client.board_count }} boards - {{ client.pending_tasks }} pending tasks</p>
              </div>
            </div>
          </div>
        </div>
      </section>
      
      <!-- ==================== TASKS SECTION (Simple Todos) ==================== -->
      <section v-if="tasksEnabled">
        <div class="flex items-center gap-2 mb-4">
          <span class="material-symbols-rounded text-green-500">task_alt</span>
          <h3 class="text-base font-semibold text-surface-900 dark:text-surface-100">Quick Tasks (Todos)</h3>
        </div>
        
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 sm:gap-4 mb-6">
          <StatCard title="Created" :value="taskStats?.created || 0" icon="add_task" color="blue" />
          <StatCard title="Completed" :value="taskStats?.completed || 0" icon="check_circle" color="green" />
          <StatCard title="Pending" :value="(taskStats?.created || 0) - (taskStats?.completed || 0)" icon="pending" color="amber" />
          <StatCard title="Completion Rate" :value="`${taskCompletionRate}%`" icon="percent" color="purple" :is-text="true" />
        </div>
        
        <div class="bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700 p-4 sm:p-5">
          <h4 class="text-sm font-semibold text-surface-900 dark:text-surface-100 mb-4">Task Completion</h4>
          <div class="flex items-center justify-center">
            <apexchart
              v-if="isMounted && taskStats"
              :key="'task-' + taskCompletionRate"
              type="radialBar"
              height="250"
              :options="taskChartOptions"
              :series="[taskCompletionRate]"
            />
          </div>
        </div>
      </section>
      
      <!-- ==================== TIME TRACKING SECTION ==================== -->
      <section v-if="timeTrackerEnabled">
        <div class="bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700 p-5 flex items-center justify-between">
          <div class="flex items-center gap-3">
            <span class="material-symbols-rounded text-2xl text-indigo-500">schedule</span>
            <div>
              <h3 class="text-base font-semibold text-surface-900 dark:text-surface-100">Time Tracking</h3>
              <p class="text-sm text-surface-500">
                {{ formatTimeSpent(timeStats?.total_seconds || 0) }} tracked this period
              </p>
            </div>
          </div>
          <router-link to="/time" class="flex items-center gap-1.5 px-4 py-2 rounded-full bg-primary-500 text-white text-sm font-medium hover:bg-primary-600 transition-colors">
            <span class="material-symbols-rounded text-lg">open_in_new</span>
            View Details
          </router-link>
        </div>
      </section>
      
      <!-- ==================== AI USAGE SECTION ==================== -->
      <section v-if="aiAssistantEnabled">
        <div class="flex items-center gap-2 mb-4">
          <span class="material-symbols-rounded text-purple-500">auto_awesome</span>
          <h3 class="text-base font-semibold text-surface-900 dark:text-surface-100">AI Usage</h3>
        </div>
        
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 sm:gap-4">
          <div class="bg-gradient-to-br from-purple-50 to-purple-100 dark:from-purple-900/30 dark:to-purple-800/20 rounded-xl p-3 sm:p-5">
            <div class="flex items-center gap-2 mb-2 sm:mb-3">
              <span class="material-symbols-rounded text-purple-600 dark:text-purple-400">summarize</span>
              <span class="text-sm text-purple-600 dark:text-purple-400 font-medium">Summaries</span>
            </div>
            <p class="text-2xl sm:text-3xl font-bold text-purple-700 dark:text-purple-300">{{ aiStats?.summaries || 0 }}</p>
            <p class="text-[10px] sm:text-xs text-purple-500 mt-1">Email summaries generated</p>
          </div>
          <div class="bg-gradient-to-br from-indigo-50 to-indigo-100 dark:from-indigo-900/30 dark:to-indigo-800/20 rounded-xl p-3 sm:p-5">
            <div class="flex items-center gap-2 mb-2 sm:mb-3">
              <span class="material-symbols-rounded text-indigo-600 dark:text-indigo-400">edit_note</span>
              <span class="text-sm text-indigo-600 dark:text-indigo-400 font-medium">Rewrites</span>
            </div>
            <p class="text-2xl sm:text-3xl font-bold text-indigo-700 dark:text-indigo-300">{{ aiStats?.rewrites || 0 }}</p>
            <p class="text-[10px] sm:text-xs text-indigo-500 mt-1">Text rewrites performed</p>
          </div>
          <div class="bg-gradient-to-br from-pink-50 to-pink-100 dark:from-pink-900/30 dark:to-pink-800/20 rounded-xl p-3 sm:p-5">
            <div class="flex items-center gap-2 mb-2 sm:mb-3">
              <span class="material-symbols-rounded text-pink-600 dark:text-pink-400">draft</span>
              <span class="text-sm text-pink-600 dark:text-pink-400 font-medium">Drafts</span>
            </div>
            <p class="text-2xl sm:text-3xl font-bold text-pink-700 dark:text-pink-300">{{ aiStats?.draft_replies || 0 }}</p>
            <p class="text-[10px] sm:text-xs text-pink-500 mt-1">AI-generated replies</p>
          </div>
          <div class="bg-gradient-to-br from-cyan-50 to-cyan-100 dark:from-cyan-900/30 dark:to-cyan-800/20 rounded-xl p-3 sm:p-5">
            <div class="flex items-center gap-2 mb-2 sm:mb-3">
              <span class="material-symbols-rounded text-cyan-600 dark:text-cyan-400">analytics</span>
              <span class="text-sm text-cyan-600 dark:text-cyan-400 font-medium">Total</span>
            </div>
            <p class="text-2xl sm:text-3xl font-bold text-cyan-700 dark:text-cyan-300">{{ totalAIUsage }}</p>
            <p class="text-[10px] sm:text-xs text-cyan-500 mt-1">Total AI operations</p>
          </div>
        </div>
      </section>
      
      <!-- ==================== PREFERENCES SECTION ==================== -->
      <section>
        <div class="flex items-center gap-2 mb-4">
          <span class="material-symbols-rounded text-rose-500">palette</span>
          <h3 class="text-base font-semibold text-surface-900 dark:text-surface-100">Usage Preferences</h3>
        </div>
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
          <!-- Theme Usage -->
          <div class="bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700 p-4 sm:p-5">
            <h4 class="text-sm font-semibold text-surface-900 dark:text-surface-100 mb-4">Theme Usage</h4>
            <div v-if="preferenceStats?.theme?.length > 0" class="flex gap-4">
              <div
                v-for="pref in preferenceStats.theme"
                :key="pref.value"
                class="flex-1 text-center p-4 rounded-xl"
                :class="pref.value === 'dark' ? 'bg-surface-800 dark:bg-surface-900' : 'bg-surface-100 dark:bg-surface-200'"
              >
                <span 
                  class="material-symbols-rounded text-3xl mb-2"
                  :class="pref.value === 'dark' ? 'text-surface-300' : 'text-amber-500'"
                >{{ pref.value === 'dark' ? 'dark_mode' : 'light_mode' }}</span>
                <p class="text-xl font-bold" :class="pref.value === 'dark' ? 'text-white' : 'text-surface-900'">{{ pref.count }}</p>
                <p class="text-xs capitalize" :class="pref.value === 'dark' ? 'text-surface-400' : 'text-surface-500'">{{ pref.value }} mode</p>
              </div>
            </div>
            <div v-else class="py-8 text-center text-surface-400">
              <p class="text-sm">Theme usage will be tracked as you switch themes</p>
            </div>
          </div>
          
          <!-- Accent Colors -->
          <div class="bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700 p-4 sm:p-5">
            <h4 class="text-sm font-semibold text-surface-900 dark:text-surface-100 mb-4">Accent Colors Used</h4>
            <div v-if="preferenceStats?.accent_color?.length > 0" class="grid grid-cols-3 gap-3">
              <div
                v-for="pref in preferenceStats.accent_color"
                :key="pref.value"
                class="text-center p-3 bg-surface-50 dark:bg-surface-700 rounded-lg"
              >
                <div 
                  class="w-8 h-8 rounded-full mx-auto mb-2"
                  :style="{ backgroundColor: getAccentColor(pref.value) }"
                ></div>
                <p class="text-lg font-bold text-surface-900 dark:text-surface-100">{{ pref.count }}</p>
                <p class="text-xs text-surface-500 capitalize">{{ pref.value }}</p>
              </div>
            </div>
            <div v-else class="py-8 text-center text-surface-400">
              <p class="text-sm">Accent color usage will be tracked</p>
            </div>
          </div>
        </div>
      </section>
      
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue'
import { storeToRefs } from 'pinia'
import { useStatisticsStore } from '@/stores/statistics'
import { useMailboxStore } from '@/stores/mailbox'
import { useAddons } from '@/composables/useAddons'
import VueApexCharts from 'vue3-apexcharts'

// Register ApexCharts component
const apexchart = VueApexCharts

// Track if component is mounted (prevents ApexCharts "Element not found" errors)
const isMounted = ref(false)
onMounted(() => { isMounted.value = true })
onBeforeUnmount(() => { isMounted.value = false })

// Stores
const statisticsStore = useStatisticsStore()
const mailboxStore = useMailboxStore()
const { kanbanBoardsEnabled, calendarEnabled, tasksEnabled, emailTrackingEnabled, timeTrackerEnabled, aiAssistantEnabled } = useAddons()
const {
  loading,
  error,
  period,
  lastUpdated,
  hasData,
  emailStats,
  topContacts,
  activeConversations,
  taskStats,
  calendarStats,
  driveStats,
  boardStats,
  clientStats,
  aiStats,
  timeStats,
  folderStats,
  preferenceStats,
  readReceipts,
  emailsSent,
  emailsReceived,
  avgReplyTime,
  taskCompletionRate,
  totalAIUsage
} = storeToRefs(statisticsStore)

// Computed for drive file types
const driveFileTypes = computed(() => {
  return driveStats.value?.file_types || []
})

// Real folder email counts from mailbox store (actual emails, not usage)
const realFolderStats = computed(() => {
  const folders = mailboxStore.folders || []
  if (!folders.length) return []
  
  // Sort by total email count descending
  return [...folders]
    .filter(f => f.total > 0) // Only folders with emails
    .sort((a, b) => b.total - a.total)
    .map(f => ({
      folder: f.name,
      total: f.total,
      unread: f.unread || 0,
      type: f.type
    }))
})

// Periods
const periods = [
  { value: 'week', label: 'Week' },
  { value: 'month', label: 'Month' },
  { value: 'year', label: 'Year' },
  { value: 'all', label: 'All' }
]

// Chart options
const isDark = computed(() => document.documentElement.classList.contains('dark'))

const chartColors = computed(() => ({
  primary: isDark.value ? '#818cf8' : '#6366f1',
  green: isDark.value ? '#4ade80' : '#22c55e',
  amber: isDark.value ? '#fbbf24' : '#f59e0b',
  purple: isDark.value ? '#c084fc' : '#a855f7',
  blue: isDark.value ? '#60a5fa' : '#3b82f6',
  teal: isDark.value ? '#2dd4bf' : '#14b8a6',
  red: isDark.value ? '#f87171' : '#ef4444',
  pink: isDark.value ? '#f472b6' : '#ec4899',
  text: isDark.value ? '#e2e8f0' : '#475569',
  grid: isDark.value ? '#334155' : '#e2e8f0'
}))

// Email Activity Chart
const emailChartOptions = computed(() => {
  return {
    chart: {
      type: 'area',
      toolbar: { show: false },
      animations: { enabled: true, easing: 'easeinout', speed: 800 },
      background: 'transparent'
    },
    colors: [chartColors.value.primary, chartColors.value.green],
    dataLabels: { enabled: false },
    stroke: { curve: 'smooth', width: 2 },
    fill: {
      type: 'gradient',
      gradient: { shadeIntensity: 1, opacityFrom: 0.4, opacityTo: 0.1, stops: [0, 90, 100] }
    },
    xaxis: {
      type: 'datetime',
      labels: { style: { colors: chartColors.value.text } }
    },
    yaxis: { labels: { style: { colors: chartColors.value.text } } },
    grid: { borderColor: chartColors.value.grid, strokeDashArray: 4 },
    legend: { position: 'top', horizontalAlign: 'right', labels: { colors: chartColors.value.text } },
    tooltip: { theme: isDark.value ? 'dark' : 'light', x: { format: 'dd MMM yyyy' } }
  }
})

const emailChartSeries = computed(() => {
  if (!emailStats.value?.daily?.length) return []
  
  const sentData = []
  const receivedData = []
  
  emailStats.value.daily.forEach(d => {
    if (!d || !d.date) return
    const timestamp = new Date(d.date).getTime()
    if (isNaN(timestamp)) return
    const val = Number(d.value) || 0
    if (d.stat_type === 'emails_sent') {
      sentData.push({ x: timestamp, y: val })
    } else if (d.stat_type === 'emails_received') {
      receivedData.push({ x: timestamp, y: val })
    }
  })
  
  if (sentData.length === 0 && receivedData.length === 0) return []
  
  return [
    { name: 'Sent', data: sentData },
    { name: 'Received', data: receivedData }
  ]
})

// Contacts Chart
const contactsChartOptions = computed(() => {
  if (!topContacts.value?.length) return null
  
  return {
    chart: { type: 'bar', toolbar: { show: false }, animations: { enabled: true, speed: 500 }, background: 'transparent' },
    plotOptions: { bar: { horizontal: true, borderRadius: 4, distributed: true } },
    colors: [chartColors.value.primary, chartColors.value.green, chartColors.value.amber, chartColors.value.purple, chartColors.value.blue],
    dataLabels: { enabled: false },
    xaxis: {
      categories: topContacts.value.slice(0, 5).map(c => c.contact_name || (c.contact_email || '').split('@')[0] || 'Unknown'),
      labels: { style: { colors: chartColors.value.text } }
    },
    yaxis: { labels: { style: { colors: chartColors.value.text } } },
    grid: { borderColor: chartColors.value.grid, strokeDashArray: 4 },
    legend: { show: false },
    tooltip: { theme: isDark.value ? 'dark' : 'light' }
  }
})

const contactsChartSeries = computed(() => {
  if (!topContacts.value?.length) return []
  return [{ name: 'Emails', data: topContacts.value.slice(0, 5).map(c => c.total_emails ?? ((c.emails_sent ?? 0) + (c.emails_received ?? 0))) }]
})

// Folder Chart (old usage-based - keeping for backward compatibility)
const folderChartOptions = computed(() => {
  if (!folderStats.value?.length) return null
  return {
    chart: { type: 'donut', animations: { enabled: true, speed: 500 }, background: 'transparent' },
    labels: folderStats.value.slice(0, 6).map(f => f.folder),
    colors: [chartColors.value.primary, chartColors.value.green, chartColors.value.amber, chartColors.value.purple, chartColors.value.blue, chartColors.value.teal],
    dataLabels: { enabled: false },
    legend: { position: 'bottom', labels: { colors: chartColors.value.text } },
    tooltip: { theme: isDark.value ? 'dark' : 'light' },
    plotOptions: { pie: { donut: { size: '65%' } } }
  }
})

const folderChartSeries = computed(() => {
  if (!folderStats.value?.length) return []
  return folderStats.value.slice(0, 6).map(f => f.usage_count)
})

// Real Folder Chart (actual email counts)
const realFolderChartOptions = computed(() => {
  if (!realFolderStats.value?.length) return null
  return {
    chart: { type: 'donut', animations: { enabled: true, speed: 500 }, background: 'transparent' },
    labels: realFolderStats.value.slice(0, 6).map(f => f.folder),
    colors: [chartColors.value.primary, chartColors.value.green, chartColors.value.amber, chartColors.value.purple, chartColors.value.blue, chartColors.value.teal],
    dataLabels: { enabled: false },
    legend: { position: 'bottom', labels: { colors: chartColors.value.text } },
    tooltip: { 
      theme: isDark.value ? 'dark' : 'light',
      y: { formatter: (val) => `${formatNumber(val)} emails` }
    },
    plotOptions: { pie: { donut: { size: '65%' } } }
  }
})

const realFolderChartSeries = computed(() => {
  if (!realFolderStats.value?.length) return []
  return realFolderStats.value.slice(0, 6).map(f => f.total)
})

// Total emails computed
const totalEmailsInFolders = computed(() => {
  return realFolderStats.value.reduce((sum, f) => sum + f.total, 0)
})

const totalUnreadEmails = computed(() => {
  return realFolderStats.value.reduce((sum, f) => sum + (f.unread || 0), 0)
})

// Time Chart
const timeChartOptions = computed(() => {
  if (!timeStats.value?.by_section?.length) return null
  const sectionLabels = { email: 'Email', calendar: 'Calendar', drive: 'Drive', settings: 'Settings', todo: 'Tasks', mood: 'Mood Boards', boards: 'Projects / Boards', time_tracker: 'Time Tracker', clients: 'Clients', chat: 'Chat', team: 'Team', crm: 'CRM', financials: 'Financials', automation: 'Automation', other: 'Other' }
  return {
    chart: { type: 'donut', animations: { enabled: true, speed: 500 }, background: 'transparent' },
    labels: timeStats.value.by_section.map(s => sectionLabels[s.section] || s.section),
    colors: [chartColors.value.primary, chartColors.value.blue, chartColors.value.teal, chartColors.value.amber, chartColors.value.purple],
    dataLabels: { enabled: false },
    legend: { position: 'bottom', labels: { colors: chartColors.value.text } },
    tooltip: { theme: isDark.value ? 'dark' : 'light', y: { formatter: (val) => formatTimeSpent(val) } },
    plotOptions: { pie: { donut: { size: '65%', labels: { show: true, total: { show: true, label: 'Total', color: chartColors.value.text, formatter: () => formatTimeSpent(timeStats.value.total_seconds || 0) } } } } }
  }
})

const timeChartSeries = computed(() => {
  if (!timeStats.value?.by_section?.length) return []
  return timeStats.value.by_section.map(s => s.total_seconds)
})

// Task Chart
const taskChartOptions = computed(() => ({
  chart: { type: 'radialBar', animations: { enabled: true, speed: 500 }, background: 'transparent' },
  plotOptions: {
    radialBar: {
      hollow: { size: '60%' },
      track: { background: isDark.value ? '#334155' : '#e2e8f0' },
      dataLabels: { name: { show: true, fontSize: '14px', color: chartColors.value.text }, value: { show: true, fontSize: '28px', fontWeight: 600, color: chartColors.value.text, formatter: (val) => `${Math.round(val)}%` } }
    }
  },
  colors: [chartColors.value.green],
  labels: ['Completed']
}))

// Calendar Chart
const calendarChartOptions = computed(() => ({
  chart: { type: 'bar', toolbar: { show: false }, background: 'transparent' },
  plotOptions: { bar: { borderRadius: 4, columnWidth: '60%' } },
  colors: [chartColors.value.blue],
  dataLabels: { enabled: false },
  xaxis: { categories: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'], labels: { style: { colors: chartColors.value.text } } },
  yaxis: { labels: { style: { colors: chartColors.value.text } } },
  grid: { borderColor: chartColors.value.grid, strokeDashArray: 4 },
  tooltip: { theme: isDark.value ? 'dark' : 'light' }
}))

const calendarChartData = computed(() => {
  // Placeholder - would need backend data
  return calendarStats.value?.events_by_day || []
})

// Drive Type Chart
const driveTypeChartOptions = computed(() => {
  if (!driveFileTypes.value?.length) return null
  return {
    chart: { type: 'donut', background: 'transparent' },
    labels: driveFileTypes.value.map(t => t.type),
    colors: [chartColors.value.teal, chartColors.value.blue, chartColors.value.amber, chartColors.value.purple, chartColors.value.red],
    dataLabels: { enabled: false },
    legend: { position: 'bottom', labels: { colors: chartColors.value.text } },
    tooltip: { theme: isDark.value ? 'dark' : 'light' }
  }
})

// Helpers
function formatDate(date) {
  if (!date) return ''
  return new Date(date).toLocaleString()
}

function formatTimeSpent(seconds) {
  if (!seconds || seconds < 1) return '0m'
  if (seconds < 60) return `${Math.round(seconds)}s`
  if (seconds < 3600) return `${Math.round(seconds / 60)}m`
  const hours = Math.floor(seconds / 3600)
  const mins = Math.round((seconds % 3600) / 60)
  return mins > 0 ? `${hours}h ${mins}m` : `${hours}h`
}

function formatReadTime(seconds) {
  if (!seconds) return 'N/A'
  if (seconds < 60) return `${Math.round(seconds)}s`
  if (seconds < 3600) return `${Math.round(seconds / 60)}m`
  return `${(seconds / 3600).toFixed(1)}h`
}

function formatBytes(bytes) {
  if (!bytes || bytes === 0) return '0 B'
  const k = 1024
  const sizes = ['B', 'KB', 'MB', 'GB', 'TB']
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i]
}

function getAccentColor(name) {
  const colors = {
    indigo: '#6366f1', purple: '#a855f7', pink: '#ec4899', rose: '#f43f5e',
    red: '#ef4444', orange: '#f97316', amber: '#f59e0b', yellow: '#eab308',
    lime: '#84cc16', green: '#22c55e', emerald: '#10b981', teal: '#14b8a6',
    cyan: '#06b6d4', sky: '#0ea5e9', blue: '#3b82f6', gold: '#eab308', mono: '#404040'
  }
  return colors[name] || '#6366f1'
}

function formatDeadline(dateStr) {
  if (!dateStr) return ''
  const date = new Date(dateStr)
  const now = new Date()
  const diffDays = Math.ceil((date - now) / (1000 * 60 * 60 * 24))
  
  if (diffDays === 0) return 'Today'
  if (diffDays === 1) return 'Tomorrow'
  if (diffDays < 7) return `In ${diffDays} days`
  return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' })
}

function getDeadlineColor(dateStr) {
  if (!dateStr) return 'text-surface-500'
  const date = new Date(dateStr)
  const now = new Date()
  const diffDays = Math.ceil((date - now) / (1000 * 60 * 60 * 24))
  
  if (diffDays < 0) return 'text-red-600 dark:text-red-400'
  if (diffDays <= 1) return 'text-amber-600 dark:text-amber-400'
  if (diffDays <= 3) return 'text-yellow-600 dark:text-yellow-400'
  return 'text-green-600 dark:text-green-400'
}

function getTimeBySection(section) {
  if (!timeStats.value?.by_section) return 0
  const found = timeStats.value.by_section.find(s => s.section === section)
  return found?.total_seconds || 0
}

function formatNumber(num) {
  if (!num) return '0'
  if (num >= 1000000) return (num / 1000000).toFixed(1) + 'M'
  if (num >= 1000) return (num / 1000).toFixed(1) + 'K'
  return num.toLocaleString()
}

function getFolderIcon(type) {
  const icons = {
    inbox: 'inbox',
    sent: 'send',
    drafts: 'drafts',
    trash: 'delete',
    spam: 'report',
    junk: 'report',
    archive: 'archive',
    starred: 'star',
    important: 'label_important'
  }
  return icons[type] || 'folder'
}

// Actions
function setPeriod(newPeriod) {
  statisticsStore.setPeriod(newPeriod)
}

function refresh() {
  statisticsStore.refresh()
}

// Load on mount
onMounted(() => {
  statisticsStore.fetchOverview()
  
  // Ensure folders are loaded for real folder stats
  if (!mailboxStore.folders?.length) {
    mailboxStore.fetchFolders(true)
  }
})

// Watch for dark mode changes
watch(isDark, () => {
  statisticsStore.refresh()
})
</script>

<script>
// StatCard component
const StatCard = {
  props: {
    title: String,
    value: [String, Number],
    icon: String,
    color: String,
    isText: Boolean
  },
  template: `
    <div class="bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700 p-3 sm:p-4">
      <div class="flex items-center gap-2 sm:gap-3">
        <div :class="['w-8 h-8 sm:w-10 sm:h-10 rounded-lg flex items-center justify-center flex-shrink-0', colorClasses.bg]">
          <span :class="['material-symbols-rounded text-lg sm:text-xl', colorClasses.text]">{{ icon }}</span>
        </div>
        <div class="flex-1 min-w-0">
          <p class="text-[10px] sm:text-xs text-surface-500 uppercase tracking-wide truncate">{{ title }}</p>
          <p class="text-base sm:text-lg font-bold text-surface-900 dark:text-surface-100 truncate">
            <span v-if="!isText" class="counter">{{ animatedValue }}</span>
            <span v-else>{{ value }}</span>
          </p>
        </div>
      </div>
    </div>
  `,
  data() {
    return { animatedValue: 0 }
  },
  computed: {
    colorClasses() {
      const colors = {
        primary: { bg: 'bg-primary-100 dark:bg-primary-500/20', text: 'text-primary-600 dark:text-primary-400' },
        green: { bg: 'bg-green-100 dark:bg-green-500/20', text: 'text-green-600 dark:text-green-400' },
        amber: { bg: 'bg-amber-100 dark:bg-amber-500/20', text: 'text-amber-600 dark:text-amber-400' },
        purple: { bg: 'bg-purple-100 dark:bg-purple-500/20', text: 'text-purple-600 dark:text-purple-400' },
        blue: { bg: 'bg-blue-100 dark:bg-blue-500/20', text: 'text-blue-600 dark:text-blue-400' },
        teal: { bg: 'bg-teal-100 dark:bg-teal-500/20', text: 'text-teal-600 dark:text-teal-400' },
        red: { bg: 'bg-red-100 dark:bg-red-500/20', text: 'text-red-600 dark:text-red-400' }
      }
      return colors[this.color] || colors.primary
    }
  },
  watch: {
    value: { immediate: true, handler(newVal) { if (this.isText) return; this.animateValue(newVal) } }
  },
  methods: {
    animateValue(target) {
      const duration = 800
      const start = this.animatedValue
      const change = (parseInt(target) || 0) - start
      const startTime = performance.now()
      const animate = (currentTime) => {
        const elapsed = currentTime - startTime
        const progress = Math.min(elapsed / duration, 1)
        const eased = 1 - Math.pow(1 - progress, 3)
        this.animatedValue = Math.round(start + change * eased)
        if (progress < 1) requestAnimationFrame(animate)
      }
      requestAnimationFrame(animate)
    }
  }
}
</script>

<style scoped>
.statistics-tab {
  @apply w-full;
}
.animate-spin {
  animation: spin 1s linear infinite;
}
@keyframes spin {
  from { transform: rotate(0deg); }
  to { transform: rotate(360deg); }
}
</style>
