<template>
  <div class="h-[100dvh] flex flex-col bg-surface-50 dark:bg-surface-900 ambient-tint" :class="isMobile ? 'overflow-y-auto pb-20' : 'overflow-hidden'">
    <!-- Header -->
    <AppHeader
      current-view="clients"
      icon="monitoring"
      :title="$t('clientsOverviewView.clientsOverview')"
    >
      <template #title-badge>
        <HowItWorksButton @click="showStepGuide = true" />
        <HowItWorksButton variant="features" :active="showFeatureGuide" @click="showFeatureGuide = !showFeatureGuide" />
      </template>
    </AppHeader>

    <!-- Toolbar -->
    <div class="flex-shrink-0 border-b border-surface-200 dark:border-surface-700 bg-white dark:bg-[rgb(var(--color-surface))] px-4 py-3">
      <div class="flex flex-wrap items-center gap-3">
        <!-- Search -->
        <div class="relative flex-1 min-w-[200px] max-w-md">
          <span class="material-symbols-rounded absolute left-3 top-1/2 -translate-y-1/2 text-surface-400 text-lg">search</span>
          <input
            v-model="searchQuery"
            type="text"
            :placeholder="$t('clientsOverviewView.searchClientsContactsTasks')"
            class="w-full pl-10 pr-4 py-2 text-sm border border-surface-300 dark:border-surface-600 rounded-full bg-white dark:bg-surface-800 text-surface-900 dark:text-surface-100 focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
          />
        </div>

        <!-- Status filter -->
        <div class="flex items-center gap-1.5">
          <button
            v-for="filter in statusFilters"
            :key="filter.value"
            @click="activeStatus = filter.value"
            :class="[
              'px-3 py-1.5 rounded-full text-xs font-medium transition-colors',
              activeStatus === filter.value
                ? filter.pillClass || 'bg-primary-500 text-white'
                : 'bg-surface-100 text-surface-700 hover:bg-surface-200 dark:bg-surface-700 dark:text-surface-300 dark:hover:bg-surface-600'
            ]"
          >
            {{ filter.label }}
            <span v-if="filter.value === 'active_work' && activeWorkCount > 0" class="ml-1 opacity-75">{{ activeWorkCount }}</span>
          </button>
        </div>

        <!-- Time period -->
        <select
          v-model="timePeriod"
          class="text-xs border border-surface-300 dark:border-surface-600 rounded-full px-3 py-1.5 bg-white dark:bg-surface-800 text-surface-900 dark:text-surface-100 focus:ring-2 focus:ring-primary-500"
        >
          <option value="week">{{ $t('clientsOverviewView.thisWeek') }}</option>
          <option value="month">{{ $t('clientsOverviewView.thisMonth') }}</option>
          <option value="quarter">{{ $t('clientsOverviewView.thisQuarter') }}</option>
          <option value="all">{{ $t('clientsOverviewView.allTime') }}</option>
        </select>

        <!-- Action buttons -->
        <div class="flex items-center gap-2 ml-auto">
          <router-link
            to="/clients"
            class="flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium bg-surface-100 text-surface-700 hover:bg-surface-200 dark:bg-surface-700 dark:text-surface-300 dark:hover:bg-surface-600 rounded-full transition-colors"
          >
            <span class="material-symbols-rounded text-sm">arrow_back</span>
            {{ $t('clientsOverviewView.back') }}
          </router-link>
          <button
            @click="exportCsv"
            :disabled="exporting"
            class="flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium bg-surface-100 text-surface-700 hover:bg-surface-200 dark:bg-surface-700 dark:text-surface-300 dark:hover:bg-surface-600 rounded-full transition-colors"
          >
            <span class="material-symbols-rounded text-sm" :class="{ 'animate-spin': exporting }">
              {{ exporting ? 'sync' : 'download' }}
            </span>
            {{ $t('clientsOverviewView.exportCsv') }}
          </button>
          <button
            @click="triggerImport"
            :disabled="importing"
            class="flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium bg-surface-100 text-surface-700 hover:bg-surface-200 dark:bg-surface-700 dark:text-surface-300 dark:hover:bg-surface-600 rounded-full transition-colors"
          >
            <span class="material-symbols-rounded text-sm" :class="{ 'animate-spin': importing }">
              {{ importing ? 'sync' : 'upload' }}
            </span>
            {{ $t('clientsOverviewView.importCsv') }}
          </button>
          <input
            ref="csvFileInput"
            type="file"
            accept=".csv,text/csv"
            class="hidden"
            @change="handleImportFile"
          />
          <button
            @click="showAddClientModal = true"
            class="flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium bg-green-500 text-white hover:bg-green-600 rounded-full transition-colors"
          >
            <span class="material-symbols-rounded text-sm">person_add</span>
            {{ $t('clientsOverviewView.addNewClient') }}
          </button>
          <router-link
            to="/clients"
            class="flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium bg-primary-500 text-white hover:bg-primary-600 rounded-full transition-colors"
          >
            <span class="material-symbols-rounded text-sm">groups</span>
            {{ $t('clientsOverviewView.clientCards') }}
          </router-link>
        </div>
      </div>
    </div>

    <!-- Feature Guide -->
    <div class="px-4 md:px-6 pt-4">
      <FeatureGuide v-model="showFeatureGuide" :tiers="guideData.tiers" :integrations="guideData.integrations" :title-key="guideData.titleKey" :footer-key="guideData.footerKey" :layer-key="guideData.layerKey" :layer-icon="guideData.layerIcon" />
    </div>

    <!-- Summary Cards -->
    <div class="flex-shrink-0 px-4 py-3 grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-3">
      <div class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border border-surface-200 dark:border-surface-700 p-3 text-center">
        <p class="text-2xl font-bold text-surface-900 dark:text-surface-100">{{ summaryStats.totalClients }}</p>
        <p class="text-xs text-surface-500">{{ $t('clientsOverviewView.totalClients') }}</p>
      </div>
      <div class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border-2 border-green-300 dark:border-green-700 p-3 text-center">
        <p class="text-2xl font-bold text-green-600 dark:text-green-400">{{ summaryStats.activeWorkClients }}</p>
        <p class="text-xs text-surface-500">{{ $t('clientsOverviewView.activeWork') }}</p>
      </div>
      <div class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border border-surface-200 dark:border-surface-700 p-3 text-center">
        <p class="text-2xl font-bold text-primary-500">{{ summaryStats.totalTasks }}</p>
        <p class="text-xs text-surface-500">{{ $t('clientsOverviewView.totalTasks') }}</p>
      </div>
      <div class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border border-surface-200 dark:border-surface-700 p-3 text-center">
        <p class="text-2xl font-bold text-green-500">{{ summaryStats.completedTasks }}</p>
        <p class="text-xs text-surface-500">{{ $t('clientsOverviewView.completed') }}</p>
      </div>
      <div class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border border-surface-200 dark:border-surface-700 p-3 text-center">
        <p class="text-2xl font-bold text-red-500">{{ summaryStats.overdueTasks }}</p>
        <p class="text-xs text-surface-500">{{ $t('clientsOverviewView.overdue') }}</p>
      </div>
      <div class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border border-surface-200 dark:border-surface-700 p-3 text-center">
        <p class="text-2xl font-bold text-amber-500">{{ formatDuration(summaryStats.totalTimeSeconds) }}</p>
        <p class="text-xs text-surface-500">{{ $t('clientsOverviewView.totalTime') }}</p>
      </div>
      <!-- Financial totals -->
      <div class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border-2 border-emerald-300 dark:border-emerald-700 p-3 text-center">
        <div v-if="Object.keys(summaryStats.financialTotals).length > 0">
          <p 
            v-for="(amount, currency) in summaryStats.financialTotals" 
            :key="currency"
            class="text-lg font-bold text-emerald-600 dark:text-emerald-400 leading-tight"
          >
            {{ formatCurrency(amount, currency) }}
          </p>
        </div>
        <p v-else class="text-2xl font-bold text-surface-300 dark:text-surface-600">-</p>
        <p class="text-xs text-surface-500">{{ $t('clientsOverviewView.totalIncome') }}</p>
      </div>
    </div>

    <!-- Main Table -->
    <div class="flex-1 overflow-auto px-4 pb-4">
      <!-- Loading -->
      <div v-if="loading" class="flex items-center justify-center py-20">
        <span class="material-symbols-rounded text-4xl text-surface-400 animate-spin">sync</span>
        <p class="ml-3 text-surface-500">{{ $t('clientsOverviewView.loadingOverviewData') }}</p>
      </div>

      <!-- Table -->
      <div v-else class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border border-surface-200 dark:border-surface-700 overflow-hidden">
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="border-b border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-800/50">
                <th 
                  v-for="col in visibleColumns" 
                  :key="col.key"
                  @click="sortByColumn(col.key)"
                  class="px-4 py-3 text-left text-xs font-semibold text-surface-600 dark:text-surface-400 uppercase tracking-wide cursor-pointer hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors select-none whitespace-nowrap"
                >
                  <span class="flex items-center gap-1">
                    {{ col.label }}
                    <span v-if="sortColumn === col.key" class="material-symbols-rounded text-xs text-primary-500">
                      {{ sortDirection === 'asc' ? 'arrow_upward' : 'arrow_downward' }}
                    </span>
                  </span>
                </th>
              </tr>
            </thead>
            <tbody>
              <template v-for="client in filteredAndSortedClients" :key="client.id">
                <!-- Client row -->
                <tr 
                  @click="toggleExpand(client.id)"
                  class="border-b border-surface-100 dark:border-surface-800 hover:bg-surface-50 dark:hover:bg-surface-800/30 cursor-pointer transition-colors"
                  :class="[
                    expandedClient === client.id ? 'bg-primary-50/50 dark:bg-primary-900/10' : '',
                    client.has_active_work ? 'border-l-3 border-l-green-500' : ''
                  ]"
                >
                  <!-- Client name + active work indicator -->
                  <td class="px-4 py-3">
                    <div class="flex items-center gap-2">
                      <span class="material-symbols-rounded text-sm text-surface-400 transition-transform" :class="{ 'rotate-90': expandedClient === client.id }">
                        chevron_right
                      </span>
                      <!-- Active work dot -->
                      <span 
                        v-if="client.has_active_work"
                        class="w-2.5 h-2.5 rounded-full bg-green-500 flex-shrink-0 animate-pulse"
                        :title="$t('clientsOverviewView.activeWorkInProgress')"
                      ></span>
                      <span 
                        v-else
                        class="w-2.5 h-2.5 rounded-full bg-surface-300 dark:bg-surface-600 flex-shrink-0"
                        :title="$t('clientsOverviewView.noActiveWork')"
                      ></span>
                      <div>
                        <p class="font-medium text-surface-900 dark:text-surface-100">{{ client.display_name }}</p>
                        <p class="text-xs text-surface-400">{{ client.domain }}</p>
                      </div>
                    </div>
                  </td>

                  <!-- Status -->
                  <td class="px-4 py-3">
                    <span :class="getStatusClasses(client.status)" class="px-2 py-0.5 rounded-full text-xs font-medium">
                      {{ capitalize(client.status) }}
                    </span>
                  </td>

                  <!-- Contacts count -->
                  <td class="px-4 py-3 text-surface-600 dark:text-surface-400">
                    {{ client.contacts?.length || 0 }}
                  </td>

                  <!-- Boards -->
                  <td class="px-4 py-3 text-surface-600 dark:text-surface-400">
                    {{ client.boards?.length || 0 }}
                  </td>

                  <!-- Total tasks -->
                  <td class="px-4 py-3">
                    <div class="flex items-center gap-2">
                      <span class="text-surface-900 dark:text-surface-100 font-medium">{{ client.total_tasks || 0 }}</span>
                      <div v-if="client.total_tasks > 0" class="flex-1 max-w-[80px] h-1.5 bg-surface-200 dark:bg-surface-700 rounded-full overflow-hidden">
                        <div class="h-full bg-green-500 rounded-full" :style="{ width: (client.progress || 0) + '%' }"></div>
                      </div>
                      <span v-if="client.total_tasks > 0" class="text-xs text-surface-400">{{ client.progress || 0 }}%</span>
                    </div>
                  </td>

                  <!-- Open / Overdue -->
                  <td class="px-4 py-3">
                    <span class="text-surface-600 dark:text-surface-400">{{ client.open_tasks || 0 }}</span>
                    <span v-if="client.overdue_tasks > 0" class="text-red-500 ml-1">({{ $t('clientsOverviewView.nOverdue', { count: client.overdue_tasks }) }})</span>
                  </td>

                  <!-- Financials (unified: milestones + card estimates + invoices) -->
                  <td class="px-4 py-3">
                    <div v-if="hasAnyFinancials(client)" class="space-y-1">
                      <!-- Milestones (payment schedule) -->
                      <div v-if="hasFinancials(client)">
                        <div 
                          v-for="(amount, currency) in client.financials" 
                          :key="'m-'+currency"
                          class="text-xs font-semibold text-emerald-600 dark:text-emerald-400 whitespace-nowrap flex items-center gap-1"
                        >
                          <span class="material-symbols-rounded text-[10px]">flag</span>
                          {{ formatCurrency(amount, currency) }}
                        </div>
                      </div>
                      <!-- Card estimates -->
                      <div v-if="client.card_estimates && Object.keys(client.card_estimates).length > 0">
                        <div 
                          v-for="(data, currency) in client.card_estimates" 
                          :key="'e-'+currency"
                          class="text-xs font-semibold text-primary-600 dark:text-primary-400 whitespace-nowrap flex items-center gap-1"
                        >
                          <span class="material-symbols-rounded text-[10px]">credit_score</span>
                          {{ formatCurrency(data.revenue, currency) }}
                        </div>
                      </div>
                      <!-- Invoice totals -->
                      <div v-if="client.invoice_summary?.total_invoiced && Object.keys(client.invoice_summary.total_invoiced).length > 0">
                        <div 
                          v-for="(amount, currency) in client.invoice_summary.total_invoiced" 
                          :key="'i-'+currency"
                          class="text-xs font-semibold text-green-600 dark:text-green-400 whitespace-nowrap flex items-center gap-1"
                        >
                          <span class="material-symbols-rounded text-[10px]">receipt_long</span>
                          {{ formatCurrency(amount, currency) }}
                        </div>
                        <div 
                          v-for="(amount, currency) in (client.invoice_summary?.overdue || {})" 
                          :key="'o-'+currency"
                          class="text-[10px] text-red-500 whitespace-nowrap"
                        >
                          {{ $t('clientsOverviewView.amountOverdue', { amount: formatCurrency(amount, currency) }) }}
                        </div>
                      </div>
                    </div>
                    <span v-else class="text-surface-300 dark:text-surface-600">-</span>
                  </td>

                  <!-- My Time -->
                  <td class="px-4 py-3 text-surface-600 dark:text-surface-400 font-mono text-xs">
                    {{ formatDuration(client.time?.my_total_seconds || 0) }}
                  </td>

                  <!-- Team Time -->
                  <td class="px-4 py-3 text-surface-600 dark:text-surface-400 font-mono text-xs">
                    {{ formatDuration(client.time?.team_total_seconds || 0) }}
                  </td>

                  <!-- Last Activity -->
                  <td class="px-4 py-3 text-xs text-surface-400">
                    {{ client.last_activity_at ? formatRelative(client.last_activity_at) : '-' }}
                  </td>

                  <!-- Actions -->
                  <td class="px-4 py-3">
                    <router-link 
                      :to="`/clients/${client.id}`"
                      @click.stop
                      class="p-1.5 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 inline-flex"
                      :title="$t('clientsOverviewView.openClient')"
                    >
                      <span class="material-symbols-rounded text-sm text-primary-500">open_in_new</span>
                    </router-link>
                  </td>
                </tr>

                <!-- Expanded details row -->
                <tr v-if="expandedClient === client.id">
                  <td :colspan="visibleColumns.length" class="px-0 py-0">
                    <div class="bg-surface-50 dark:bg-surface-800/30 border-b border-surface-200 dark:border-surface-700">
                      <div class="grid grid-cols-1 lg:grid-cols-4 gap-4 p-4">
                        
                        <!-- Contacts detail -->
                        <div class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-lg border border-surface-200 dark:border-surface-700 p-3">
                          <h4 class="text-xs font-semibold text-surface-500 uppercase tracking-wide mb-2 flex items-center gap-1">
                            <span class="material-symbols-rounded text-sm">people</span>
                            {{ $t('clientsOverviewView.contacts') }}
                          </h4>
                          <div v-if="client.contacts?.length > 0" class="space-y-2">
                            <div v-for="contact in client.contacts" :key="contact.id || contact.email" class="flex items-center gap-2 text-xs">
                              <div class="w-6 h-6 rounded-full bg-primary-500/10 flex items-center justify-center text-[10px] font-medium text-primary-500 flex-shrink-0">
                                {{ getInitials(contact.name || contact.email) }}
                              </div>
                              <div class="min-w-0">
                                <p class="text-surface-900 dark:text-surface-100 truncate">{{ contact.name || contact.email }}</p>
                                <div class="flex items-center gap-2 text-surface-400">
                                  <span>{{ contact.email }}</span>
                                  <span v-if="contact.phone">{{ contact.phone }}</span>
                                </div>
                              </div>
                            </div>
                          </div>
                          <p v-else class="text-xs text-surface-400">{{ $t('clientsOverviewView.noContacts') }}</p>
                        </div>

                        <!-- Boards / Tasks detail -->
                        <div class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-lg border border-surface-200 dark:border-surface-700 p-3">
                          <h4 class="text-xs font-semibold text-surface-500 uppercase tracking-wide mb-2 flex items-center gap-1">
                            <span class="material-symbols-rounded text-sm">dashboard</span>
                            {{ $t('clientsOverviewView.boardsAndTasks') }}
                          </h4>
                          <div v-if="client.boards?.length > 0" class="space-y-2">
                            <div v-for="board in client.boards" :key="board.id" class="text-xs">
                              <div class="flex items-center justify-between">
                                <p class="font-medium text-surface-900 dark:text-surface-100">{{ board.name }}</p>
                                <span class="text-surface-400">{{ board.completed_tasks }}/{{ board.total_tasks }}</span>
                              </div>
                              <div class="mt-1 h-1 bg-surface-200 dark:bg-surface-700 rounded-full overflow-hidden">
                                <div class="h-full bg-green-500 rounded-full" :style="{ width: (board.progress || 0) + '%' }"></div>
                              </div>
                              <!-- Board-level financials -->
                              <div v-if="board.financials && Object.keys(board.financials).length > 0" class="flex items-center gap-2 mt-1">
                                <span class="material-symbols-rounded text-[10px] text-emerald-500">payments</span>
                                <span 
                                  v-for="(amt, curr) in board.financials" 
                                  :key="curr" 
                                  class="text-emerald-600 dark:text-emerald-400 font-medium"
                                >
                                  {{ formatCurrency(amt, curr) }}
                                </span>
                              </div>
                              <span v-if="board.overdue_tasks > 0" class="text-red-500 text-[10px]">{{ $t('clientsOverviewView.nOverdue', { count: board.overdue_tasks }) }}</span>
                            </div>
                          </div>
                          <p v-else class="text-xs text-surface-400">{{ $t('clientsOverviewView.noLinkedBoards') }}</p>
                        </div>

                        <!-- Financials detail (unified) -->
                        <div class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-lg border border-surface-200 dark:border-surface-700 p-3">
                          <h4 class="text-xs font-semibold text-surface-500 uppercase tracking-wide mb-2 flex items-center gap-1">
                            <span class="material-symbols-rounded text-sm">payments</span>
                            {{ $t('clientsOverviewView.financials') }}
                          </h4>
                          <div v-if="hasAnyFinancials(client)" class="space-y-3">
                            <!-- Payment Schedule (milestones) -->
                            <div v-if="hasFinancials(client)">
                              <p class="text-[10px] font-medium text-surface-400 uppercase tracking-wide mb-1 flex items-center gap-1">
                                <span class="material-symbols-rounded text-[10px]">flag</span> {{ $t('clientsOverviewView.paymentSchedule') }}
                              </p>
                              <div v-for="(amount, currency) in client.financials" :key="'m-'+currency" class="mb-1">
                                <p class="text-sm font-bold text-emerald-600 dark:text-emerald-400">{{ formatCurrency(amount, currency) }}</p>
                              </div>
                            </div>
                            <!-- Card Estimates -->
                            <div v-if="client.card_estimates && Object.keys(client.card_estimates).length > 0">
                              <p class="text-[10px] font-medium text-surface-400 uppercase tracking-wide mb-1 flex items-center gap-1">
                                <span class="material-symbols-rounded text-[10px]">credit_score</span> {{ $t('clientsOverviewView.cardEstimates') }}
                              </p>
                              <div v-for="(data, currency) in client.card_estimates" :key="'e-'+currency" class="mb-1">
                                <p class="text-sm font-bold text-primary-600 dark:text-primary-400">{{ formatCurrency(data.revenue, currency) }}</p>
                                <p v-if="data.cost > 0" class="text-[10px] text-surface-400">{{ $t('clientsOverviewView.costLabel', { amount: formatCurrency(data.cost, currency) }) }}</p>
                              </div>
                            </div>
                            <!-- Invoiced -->
                            <div v-if="client.invoice_summary?.total_invoiced && Object.keys(client.invoice_summary.total_invoiced).length > 0">
                              <p class="text-[10px] font-medium text-surface-400 uppercase tracking-wide mb-1 flex items-center gap-1">
                                <span class="material-symbols-rounded text-[10px]">receipt_long</span> {{ $t('clientsOverviewView.invoiced') }}
                              </p>
                              <div v-for="(amount, currency) in client.invoice_summary.total_invoiced" :key="'i-'+currency" class="mb-1">
                                <p class="text-sm font-bold text-green-600 dark:text-green-400">{{ formatCurrency(amount, currency) }}</p>
                                <p v-if="client.invoice_summary.total_paid?.[currency]" class="text-[10px] text-green-500">
                                  {{ $t('clientsOverviewView.paid') }} {{ formatCurrency(client.invoice_summary.total_paid[currency], currency) }}
                                </p>
                                <p v-if="client.invoice_summary.overdue?.[currency]" class="text-[10px] text-red-500">
                                  {{ $t('clientsOverviewView.overdueLabel') }} {{ formatCurrency(client.invoice_summary.overdue[currency], currency) }}
                                </p>
                              </div>
                            </div>
                            <!-- Hourly rate & payment terms -->
                            <div v-if="client.hourly_rate || client.payment_terms_days" class="pt-2 border-t border-surface-200 dark:border-surface-700 space-y-1">
                              <p v-if="client.hourly_rate" class="text-xs text-surface-500 flex items-center gap-1">
                                <span class="material-symbols-rounded text-[10px]">schedule</span>
                                {{ $t('clientsOverviewView.hourlyRateLabel', { rate: formatCurrency(client.hourly_rate, 'HUF') }) }}
                              </p>
                              <p v-if="client.payment_terms_days" class="text-xs text-surface-500 flex items-center gap-1">
                                <span class="material-symbols-rounded text-[10px]">event</span>
                                {{ $t('clientsOverviewView.paymentTerms', { days: client.payment_terms_days }) }}
                              </p>
                            </div>
                          </div>
                          <p v-else class="text-xs text-surface-400">{{ $t('clientsOverviewView.noFinancialsSet') }}</p>
                        </div>

                        <!-- Team Members & Time -->
                        <div class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-lg border border-surface-200 dark:border-surface-700 p-3">
                          <h4 class="text-xs font-semibold text-surface-500 uppercase tracking-wide mb-2 flex items-center gap-1">
                            <span class="material-symbols-rounded text-sm">group</span>
                            {{ $t('clientsOverviewView.team') }}
                          </h4>
                          <div v-if="client.team?.length > 0" class="space-y-1.5">
                            <div v-for="member in client.team" :key="member.email" class="flex items-center justify-between text-xs">
                              <div class="flex items-center gap-2 min-w-0">
                                <div class="w-5 h-5 rounded-full bg-primary-500/10 flex items-center justify-center text-[9px] font-medium text-primary-500 flex-shrink-0">
                                  {{ getInitials(member.name || member.email) }}
                                </div>
                                <span class="text-surface-900 dark:text-surface-100 truncate">{{ member.name || member.email }}</span>
                                <span v-if="member.role === 'owner'" class="px-1.5 py-0.5 rounded-full bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400 text-[10px] flex-shrink-0">{{ $t('clientsOverviewView.owner') }}</span>
                              </div>
                              <span class="font-mono text-surface-500 flex-shrink-0 ml-2">{{ formatDuration(member.total_seconds || 0) }}</span>
                            </div>
                          </div>
                          <p v-else class="text-xs text-surface-400">{{ $t('clientsOverviewView.noTeamMembers') }}</p>

                          <!-- Time by activity -->
                          <div v-if="client.time?.by_activity?.length > 0" class="mt-3 pt-3 border-t border-surface-200 dark:border-surface-700">
                            <h5 class="text-[10px] font-semibold text-surface-400 uppercase tracking-wide mb-1">{{ $t('clientsOverviewView.byActivity') }}</h5>
                            <div v-for="activity in client.time.by_activity" :key="activity.type" class="flex items-center justify-between text-xs">
                              <span class="text-surface-600 dark:text-surface-400 capitalize">{{ activity.type?.replace(/_/g, ' ') || $t('clientsOverviewView.general') }}</span>
                              <span class="font-mono text-surface-500">{{ formatDuration(activity.total_seconds || 0) }}</span>
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>
                  </td>
                </tr>
              </template>

              <!-- Empty state -->
              <tr v-if="filteredAndSortedClients.length === 0">
                <td :colspan="visibleColumns.length" class="text-center py-12">
                  <span class="material-symbols-rounded text-5xl text-surface-300 dark:text-surface-600">search_off</span>
                  <p class="mt-2 text-surface-500">{{ $t('clientsOverviewView.noClientsMatchYourFilters') }}</p>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Add Client Modal -->
    <Teleport to="body">
      <div v-if="showAddClientModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50" @click.self="showAddClientModal = false">
        <div class="bg-white dark:bg-surface-800 rounded-2xl shadow-xl w-full max-w-md p-6">
      <h2 class="text-lg font-bold text-surface-900 dark:text-white mb-4">{{ $t('clientsOverviewView.addNewClient') }}</h2>
          <div class="space-y-3">
            <div>
          <label class="block text-sm font-medium text-surface-700 dark:text-surface-200 mb-1">{{ $t('clientsOverviewView.clientName') }}</label>
          <input v-model="newClientForm.name" :placeholder="$t('clientsOverviewView.companyOrPersonName')"
                     class="w-full px-4 py-2.5 rounded-xl border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-sm focus:ring-2 focus:ring-primary-500 outline-none" />
            </div>
            <div>
          <label class="block text-sm font-medium text-surface-700 dark:text-surface-200 mb-1">{{ $t('clientsOverviewView.domain') }}</label>
          <input v-model="newClientForm.domain" :placeholder="$t('clientsOverviewView.examplecom')"
                     class="w-full px-4 py-2.5 rounded-xl border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-sm focus:ring-2 focus:ring-primary-500 outline-none" />
            </div>
            <div>
          <label class="block text-sm font-medium text-surface-700 dark:text-surface-200 mb-1">{{ $t('clientsOverviewView.contactEmail') }}</label>
          <input v-model="newClientForm.email" :placeholder="$t('clientsOverviewView.contactexamplecom')"
                     class="w-full px-4 py-2.5 rounded-xl border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-sm focus:ring-2 focus:ring-primary-500 outline-none" />
            </div>
          </div>
          <div class="flex justify-end gap-3 mt-6">
        <button @click="showAddClientModal = false" class="px-4 py-2 text-sm text-surface-500">{{ $t('clientsOverviewView.cancel') }}</button>
            <button @click="addClient" :disabled="addingClient || !newClientForm.name.trim() || !newClientForm.domain.trim()"
                    class="px-6 py-2.5 rounded-xl bg-green-500 hover:bg-green-600 text-white text-sm font-medium disabled:opacity-50">
          {{ addingClient ? $t('clientsOverviewView.adding') : $t('clientsOverviewView.addClient') }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- ComposeWindow is now rendered globally in App.vue -->
    
    <!-- Mobile Bottom Navigation -->
    <MobileBottomNav v-if="isMobile" />

    <StepGuide
      v-if="showStepGuide"
      :title-key="clientsGuide.titleKey"
      :subtitle-key="clientsGuide.subtitleKey"
      :header-icon="clientsGuide.headerIcon"
      :header-color="clientsGuide.headerColor"
      :storage-key="clientsGuide.storageKey"
      :steps="clientsGuide.steps"
      @close="showStepGuide = false"
    />
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useClientsStore } from '@/stores/clients';
import { useComposeStore } from '@/stores/compose';
import { useThemeStore } from '@/stores/theme';
import { useToastStore } from '@/stores/toast';
import api from '@/services/api';
import AppHeader from '@/components/shared/AppHeader.vue';
import FeatureGuide from '@/components/shared/FeatureGuide.vue';
import HowItWorksButton from '@/components/shared/HowItWorksButton.vue'
// ComposeModal moved to App.vue as ComposeWindow for cross-view persistence
import MobileBottomNav from '@/components/MobileBottomNav.vue';
import { featureGuides } from '@/data/featureGuides';
import StepGuide from '@/components/shared/StepGuide.vue'
import { clientsGuide } from '@/data/stepGuides'

const clientsStore = useClientsStore();
const composeStore = useComposeStore();
const themeStore = useThemeStore();
const toast = useToastStore();
const { t } = useI18n();

const isMobile = ref(false)
function checkMobile() {
  isMobile.value = window.innerWidth < 768
}

const showFeatureGuide = ref(false);
const showStepGuide = ref(false);
const guideData = featureGuides.clients;

// State
const loading = ref(false);
const overviewData = ref(null);
const searchQuery = ref(localStorage.getItem('overviewSearch') || '');
const activeStatus = ref(localStorage.getItem('overviewStatus') ? JSON.parse(localStorage.getItem('overviewStatus')) : null);
const timePeriod = ref(localStorage.getItem('overviewPeriod') || 'all');
const sortColumn = ref(localStorage.getItem('overviewSortCol') || 'has_active_work');
const sortDirection = ref(localStorage.getItem('overviewSortDir') || 'desc');
const expandedClient = ref(null);
const showAddClientModal = ref(false);
const newClientForm = ref({ name: '', domain: '', email: '' });
const addingClient = ref(false);
const exporting = ref(false);
const importing = ref(false);
const csvFileInput = ref(null);

// Column definitions - added Financials column
const visibleColumns = computed(() => [
  { key: 'display_name', label: t('clientsOverviewView.client') },
  { key: 'status', label: t('clientsOverviewView.status') },
  { key: 'contacts', label: t('clientsOverviewView.contacts') },
  { key: 'boards', label: t('clientsOverviewView.boards') },
  { key: 'total_tasks', label: t('clientsOverviewView.tasks') },
  { key: 'open_tasks', label: t('clientsOverviewView.openOverdue') },
  { key: 'financials', label: t('clientsOverviewView.financials') },
  { key: 'my_time', label: t('clientsOverviewView.myTime') },
  { key: 'team_time', label: t('clientsOverviewView.teamTime') },
  { key: 'last_activity_at', label: t('clientsOverviewView.lastActivity') },
  { key: 'actions', label: '' }
]);

const statusFilters = computed(() => [
  { value: null, label: t('clientsOverviewView.all') },
  { value: 'active_work', label: t('clientsOverviewView.activeWork'), pillClass: 'bg-green-500 text-white' },
  { value: 'attention', label: t('clientsOverviewView.attention') },
  { value: 'waiting', label: t('clientsOverviewView.waiting') },
  { value: 'active', label: t('clientsOverviewView.active') }
]);

// Computed
const clients = computed(() => overviewData.value?.clients || []);

const activeWorkCount = computed(() => {
  return clients.value.filter(c => c.has_active_work).length;
});

const summaryStats = computed(() => {
  const list = clients.value;
  
  // Aggregate financials across all clients (milestones + card estimates + invoices)
  const financialTotals = {};
  list.forEach(c => {
    // Milestones (payment schedule)
    if (c.financials && typeof c.financials === 'object') {
      Object.entries(c.financials).forEach(([currency, amount]) => {
        financialTotals[currency] = (financialTotals[currency] || 0) + amount;
      });
    }
    // Card estimates (Board Pro)
    if (c.card_estimates && typeof c.card_estimates === 'object') {
      Object.entries(c.card_estimates).forEach(([currency, data]) => {
        if (data.revenue) financialTotals[currency] = (financialTotals[currency] || 0) + data.revenue;
      });
    }
  });
  
  return {
    totalClients: list.length,
    activeWorkClients: list.filter(c => c.has_active_work).length,
    totalContacts: list.reduce((sum, c) => sum + (c.contacts?.length || 0), 0),
    totalTasks: list.reduce((sum, c) => sum + (c.total_tasks || 0), 0),
    completedTasks: list.reduce((sum, c) => sum + (c.completed_tasks || 0), 0),
    overdueTasks: list.reduce((sum, c) => sum + (c.overdue_tasks || 0), 0),
    totalTimeSeconds: list.reduce((sum, c) => sum + (c.time?.team_total_seconds || c.time?.my_total_seconds || 0), 0),
    financialTotals
  };
});

const filteredAndSortedClients = computed(() => {
  let result = [...clients.value];

  // Status filter
  if (activeStatus.value === 'active_work') {
    result = result.filter(c => c.has_active_work);
  } else if (activeStatus.value) {
    result = result.filter(c => c.status === activeStatus.value);
  }

  // Search filter
  if (searchQuery.value.trim()) {
    const q = searchQuery.value.toLowerCase().trim();
    result = result.filter(c => {
      if (c.display_name?.toLowerCase().includes(q)) return true;
      if (c.domain?.toLowerCase().includes(q)) return true;
      if (c.contacts?.some(ct => 
        ct.name?.toLowerCase().includes(q) || 
        ct.email?.toLowerCase().includes(q) ||
        ct.phone?.includes(q)
      )) return true;
      if (c.boards?.some(b => b.name?.toLowerCase().includes(q))) return true;
      return false;
    });
  }

  // Sort - always push active work clients to top first, then apply selected sort
  result.sort((a, b) => {
    // Primary sort: active work clients always first (unless user explicitly sorted by something else)
    if (sortColumn.value === 'has_active_work') {
      const activeA = a.has_active_work ? 1 : 0;
      const activeB = b.has_active_work ? 1 : 0;
      if (activeA !== activeB) {
        return sortDirection.value === 'desc' ? activeB - activeA : activeA - activeB;
      }
      // Secondary sort for active work: by last activity (most recent first)
      const dateA = a.last_activity_at || '';
      const dateB = b.last_activity_at || '';
      return dateB.localeCompare(dateA);
    }

    let aVal, bVal;
    
    switch (sortColumn.value) {
      case 'display_name':
        aVal = (a.display_name || '').toLowerCase();
        bVal = (b.display_name || '').toLowerCase();
        break;
      case 'status': {
        const statusOrder = { attention: 0, waiting: 1, active: 2 };
        aVal = statusOrder[a.status] ?? 3;
        bVal = statusOrder[b.status] ?? 3;
        break;
      }
      case 'contacts':
        aVal = a.contacts?.length || 0;
        bVal = b.contacts?.length || 0;
        break;
      case 'boards':
        aVal = a.boards?.length || 0;
        bVal = b.boards?.length || 0;
        break;
      case 'total_tasks':
        aVal = a.total_tasks || 0;
        bVal = b.total_tasks || 0;
        break;
      case 'open_tasks':
        aVal = a.open_tasks || 0;
        bVal = b.open_tasks || 0;
        break;
      case 'financials':
        // Sort by total financial value (sum all currencies - rough sort)
        aVal = getFinancialSortValue(a);
        bVal = getFinancialSortValue(b);
        break;
      case 'my_time':
        aVal = a.time?.my_total_seconds || 0;
        bVal = b.time?.my_total_seconds || 0;
        break;
      case 'team_time':
        aVal = a.time?.team_total_seconds || 0;
        bVal = b.time?.team_total_seconds || 0;
        break;
      case 'last_activity_at':
        aVal = a.last_activity_at || '';
        bVal = b.last_activity_at || '';
        break;
      default:
        aVal = 0;
        bVal = 0;
    }
    
    if (typeof aVal === 'string') {
      const cmp = aVal.localeCompare(bVal);
      return sortDirection.value === 'asc' ? cmp : -cmp;
    }
    return sortDirection.value === 'asc' ? aVal - bVal : bVal - aVal;
  });

  return result;
});

// Methods
function sortByColumn(key) {
  if (key === 'actions') return;
  if (sortColumn.value === key) {
    sortDirection.value = sortDirection.value === 'asc' ? 'desc' : 'asc';
  } else {
    sortColumn.value = key;
    // Sensible defaults: numbers and financials sort desc, names sort asc
    if (['display_name', 'status', 'last_activity_at'].includes(key)) {
      sortDirection.value = 'asc';
    } else {
      sortDirection.value = 'desc';
    }
  }
}

function toggleExpand(clientId) {
  expandedClient.value = expandedClient.value === clientId ? null : clientId;
}

function hasFinancials(client) {
  return client.financials && typeof client.financials === 'object' && Object.keys(client.financials).length > 0;
}

function hasAnyFinancials(client) {
  if (hasFinancials(client)) return true;
  if (client.card_estimates && Object.keys(client.card_estimates).length > 0) return true;
  if (client.invoice_summary?.total_invoiced && Object.keys(client.invoice_summary.total_invoiced).length > 0) return true;
  return false;
}

function getFinancialSortValue(client) {
  let total = 0;
  // Milestones
  if (client.financials) {
    total += Object.values(client.financials).reduce((sum, amt) => sum + (amt || 0), 0);
  }
  // Card estimates
  if (client.card_estimates) {
    total += Object.values(client.card_estimates).reduce((sum, d) => sum + (d?.revenue || 0), 0);
  }
  // Invoiced
  if (client.invoice_summary?.total_invoiced) {
    total += Object.values(client.invoice_summary.total_invoiced).reduce((sum, amt) => sum + (amt || 0), 0);
  }
  return total;
}

function getStatusClasses(status) {
  switch (status) {
    case 'attention': return 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400';
    case 'waiting': return 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400';
    case 'active': return 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400';
    default: return 'bg-surface-100 text-surface-600 dark:bg-surface-700 dark:text-surface-400';
  }
}

function capitalize(str) {
  return str ? str.charAt(0).toUpperCase() + str.slice(1) : '';
}

function getInitials(name) {
  if (!name) return '?';
  return name.split(/[\s@.]/).filter(Boolean).slice(0, 2).map(w => w[0].toUpperCase()).join('');
}

function formatDuration(seconds) {
  if (!seconds || seconds <= 0) return '0h';
  const h = Math.floor(seconds / 3600);
  const m = Math.floor((seconds % 3600) / 60);
  if (h > 0 && m > 0) return `${h}h ${m}m`;
  if (h > 0) return `${h}h`;
  return `${m}m`;
}

function formatCurrency(amount, currency = 'HUF') {
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
    minimumFractionDigits: 0,
    maximumFractionDigits: 2
  }).format(amount);
}

function formatRelative(dateStr) {
  if (!dateStr) return '';
  const date = new Date(dateStr);
  const now = new Date();
  const diffMs = now - date;
  const diffMins = Math.floor(diffMs / 60000);
  const diffHours = Math.floor(diffMs / 3600000);
  const diffDays = Math.floor(diffMs / 86400000);
  
  if (diffMins < 1) return 'just now';
  if (diffMins < 60) return `${diffMins}m ago`;
  if (diffHours < 24) return `${diffHours}h ago`;
  if (diffDays < 7) return `${diffDays}d ago`;
  if (diffDays < 30) return `${Math.floor(diffDays / 7)}w ago`;
  return date.toLocaleDateString();
}

async function exportCsv() {
  exporting.value = true;
  try {
    await clientsStore.exportClients();
  } finally {
    exporting.value = false;
  }
}

function triggerImport() {
  csvFileInput.value?.click();
}

async function handleImportFile(event) {
  const file = event.target.files?.[0];
  if (!file) return;

  if (!file.name.endsWith('.csv') && file.type !== 'text/csv') {
    toast.error(t('clientsOverviewView.pleaseSelectACsvFile'));
    return;
  }

  importing.value = true;
  try {
    const reader = new FileReader();
    const base64 = await new Promise((resolve, reject) => {
      reader.onload = () => {
        const result = reader.result;
        const base64Data = result.split(',')[1];
        resolve(base64Data);
      };
      reader.onerror = reject;
      reader.readAsDataURL(file);
    });

    const res = await api.post('/clients/import', { csv_data: base64 });
    if (res.data?.success) {
      const data = res.data.data || {};
      toast.success(t('clientsOverviewView.importCompleteSummary', {
        created: data.created || 0,
        updated: data.updated || 0,
        skipped: data.skipped || 0,
        contactsAdded: data.contacts_added || 0,
      }));

      if (data.errors?.length > 0) {
        toast.warning(t('clientsOverviewView.rowsHadIssuesCheckConsole', { count: data.errors.length }));
        console.warn('[CSV Import] Row errors:', data.errors);
      }

      // Refresh overview data
      loadOverview();
    } else {
      toast.error(res.data?.message || t('clientsOverviewView.importFailed'));
    }
  } catch (e) {
    toast.error(e.response?.data?.message || t('clientsOverviewView.failedToImportCsv'));
  } finally {
    importing.value = false;
    if (csvFileInput.value) csvFileInput.value.value = '';
  }
}

async function addClient() {
  if (!newClientForm.value.name.trim() || !newClientForm.value.domain.trim()) {
    toast.error(t('clientsOverviewView.nameAndDomainAreRequired'));
    return;
  }
  addingClient.value = true;
  try {
    const payload = {
      display_name: newClientForm.value.name.trim(),
      domain: newClientForm.value.domain.trim().toLowerCase(),
    };
    if (newClientForm.value.email.trim()) {
      payload.contact_email = newClientForm.value.email.trim().toLowerCase();
    }
    const res = await api.post('/clients/manual', payload);
    if (res.data?.success) {
      toast.success(t('clientsOverviewView.clientCreated', { name: payload.display_name }));
      showAddClientModal.value = false;
      newClientForm.value = { name: '', domain: '', email: '' };
      loadOverview();
    } else {
      toast.error(res.data?.message || t('clientsOverviewView.failedToCreateClient'));
    }
  } catch (e) {
    toast.error(e.response?.data?.message || t('clientsOverviewView.failedToCreateClient'));
  } finally {
    addingClient.value = false;
  }
}

async function loadOverview() {
  loading.value = true;
  try {
    const data = await clientsStore.fetchOverview({ period: timePeriod.value });
    overviewData.value = data;
  } finally {
    loading.value = false;
  }
}

// Persist filter settings to localStorage
watch(searchQuery, (val) => localStorage.setItem('overviewSearch', val));
watch(activeStatus, (val) => localStorage.setItem('overviewStatus', JSON.stringify(val)));
watch(sortColumn, (val) => localStorage.setItem('overviewSortCol', val));
watch(sortDirection, (val) => localStorage.setItem('overviewSortDir', val));

watch(timePeriod, (val) => {
  localStorage.setItem('overviewPeriod', val);
  loadOverview();
});

onMounted(() => {
  checkMobile()
  window.addEventListener('resize', checkMobile)
  loadOverview();
});

onUnmounted(() => {
  window.removeEventListener('resize', checkMobile)
});
</script>
