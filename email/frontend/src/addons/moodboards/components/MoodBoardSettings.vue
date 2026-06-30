<template>
  <div class="fixed top-0 right-0 w-96 h-full bg-white dark:bg-surface-800 shadow-xl border-l border-surface-200 dark:border-surface-700 z-[10002] flex flex-col">
    <!-- Header -->
    <div class="flex items-center justify-between p-4 border-b border-surface-200 dark:border-surface-700">
      <h3 class="text-sm font-semibold text-surface-900 dark:text-surface-100">Board Settings</h3>
      <button @click="$emit('close')" class="p-1 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-500">
        <span class="material-symbols-rounded text-lg">close</span>
      </button>
    </div>

    <!-- Tabs -->
    <div class="flex border-b border-surface-200 dark:border-surface-700">
      <button
        v-for="tab in tabs"
        :key="tab.id"
        @click="activeTab = tab.id"
        :class="[
          'flex-1 px-3 py-2.5 text-xs font-medium transition-colors flex items-center justify-center gap-1.5',
          activeTab === tab.id
            ? 'text-primary-600 dark:text-primary-400 border-b-2 border-primary-500'
            : 'text-surface-500 hover:text-surface-700 dark:hover:text-surface-300'
        ]"
      >
        <span class="material-symbols-rounded text-sm">{{ tab.icon }}</span>
        {{ tab.label }}
      </button>
    </div>

    <!-- Content -->
    <div class="flex-1 overflow-y-auto p-4 space-y-5">

      <!-- GENERAL TAB -->
      <template v-if="activeTab === 'general'">
        <!-- Name -->
        <div>
          <label class="block text-xs font-medium text-surface-500 dark:text-surface-400 mb-1">Name</label>
          <input
            :value="board.name"
            @change="$emit('update-field', 'name', $event.target.value)"
            class="w-full px-3 py-2 text-sm rounded-xl border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-surface-900 dark:text-surface-100 focus:ring-2 focus:ring-primary-500 focus:border-transparent outline-none"
          />
        </div>

        <!-- Description -->
        <div>
          <label class="block text-xs font-medium text-surface-500 dark:text-surface-400 mb-1">Description</label>
          <textarea
            :value="board.description"
            @change="$emit('update-field', 'description', $event.target.value)"
            rows="3"
            class="w-full px-3 py-2 text-sm rounded-xl border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-surface-900 dark:text-surface-100 focus:ring-2 focus:ring-primary-500 focus:border-transparent resize-none outline-none"
            placeholder="Board description..."
          />
        </div>

        <!-- Connection Panel Position -->
        <div>
          <label class="block text-xs font-medium text-surface-500 dark:text-surface-400 mb-1">
            <span class="material-symbols-rounded text-sm align-middle mr-1">settings_ethernet</span>
            Connection Settings Panel Position
          </label>
          <p class="text-[11px] text-surface-400 mb-2">Horizontal position when clicking a connection line</p>
          <div class="flex items-center gap-3">
            <span class="text-[10px] text-surface-400 flex-shrink-0">Left</span>
            <input
              type="range"
              min="10"
              max="90"
              :value="board.conn_panel_position ?? 70"
              @input="connPanelPreview = $event.target.value"
              @change="$emit('update-field', 'conn_panel_position', parseInt($event.target.value))"
              class="flex-1 h-1.5 appearance-none bg-surface-200 dark:bg-surface-600 rounded-full cursor-pointer accent-primary-500"
            />
            <span class="text-[10px] text-surface-400 flex-shrink-0">Right</span>
            <span class="text-xs font-mono font-medium text-surface-600 dark:text-surface-300 w-10 text-right">{{ connPanelPreview ?? board.conn_panel_position ?? 70 }}%</span>
          </div>
        </div>

      </template>

      <!-- SHARING TAB -->
      <template v-if="activeTab === 'sharing'">
        <!-- Public Link Section -->
        <div class="p-4 -mx-4 -mt-5 mb-2 bg-surface-50 dark:bg-surface-700/30 border-b border-surface-200 dark:border-surface-700">
          <div class="flex items-center justify-between mb-3">
            <div class="flex items-center gap-2">
              <span class="material-symbols-rounded text-lg text-primary-500">public</span>
              <span class="text-xs font-semibold text-surface-700 dark:text-surface-300 uppercase tracking-wide">Public Link</span>
            </div>
            <!-- Toggle -->
            <button
              @click="shareEnabled ? handleDisableShareLink() : handleEnableShareLink()"
              :disabled="creatingShareLink"
              class="relative w-11 h-6 rounded-full transition-colors duration-200 focus:outline-none"
              :class="shareEnabled ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'"
            >
              <span
                class="absolute top-0.5 left-0.5 w-5 h-5 bg-white rounded-full shadow transition-transform duration-200"
                :class="shareEnabled ? 'translate-x-5' : 'translate-x-0'"
              />
            </button>
          </div>

          <template v-if="shareEnabled">
            <!-- Link display + copy -->
            <div class="flex gap-2 mb-3">
              <input
                :value="shareUrl"
                readonly
                class="flex-1 px-3 py-2 text-xs rounded-xl border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-surface-600 dark:text-surface-400 outline-none truncate font-mono"
                @click="$event.target.select()"
              />
              <button
                @click="copyShareLink"
                class="px-3 py-2 rounded-xl text-sm font-medium transition-colors flex items-center gap-1.5 flex-shrink-0"
                :class="shareLinkCopied
                  ? 'bg-green-500 text-white'
                  : 'bg-primary-500 text-white hover:bg-primary-600'"
              >
                <span class="material-symbols-rounded text-sm">{{ shareLinkCopied ? 'check' : 'content_copy' }}</span>
                {{ shareLinkCopied ? 'Copied' : 'Copy' }}
              </button>
            </div>

            <!-- Mode + settings -->
            <div class="space-y-3">
              <div>
                <label class="block text-[11px] font-medium text-surface-500 dark:text-surface-400 mb-1.5">Access Mode</label>
                <div class="flex gap-2">
                  <button
                    @click="shareMode = 'view'; handleUpdateShareLink()"
                    class="flex-1 px-3 py-2 rounded-xl text-xs font-medium transition-all flex items-center justify-center gap-1.5"
                    :class="(board.share_mode || shareMode) === 'view'
                      ? 'bg-primary-500 text-white shadow-sm'
                      : 'bg-white dark:bg-surface-700 text-surface-600 dark:text-surface-300 border border-surface-300 dark:border-surface-600 hover:border-primary-400'"
                  >
                    <span class="material-symbols-rounded text-sm">visibility</span>
                    View Only
                  </button>
                  <button
                    @click="shareMode = 'edit'; handleUpdateShareLink()"
                    class="flex-1 px-3 py-2 rounded-xl text-xs font-medium transition-all flex items-center justify-center gap-1.5"
                    :class="(board.share_mode || shareMode) === 'edit'
                      ? 'bg-primary-500 text-white shadow-sm'
                      : 'bg-white dark:bg-surface-700 text-surface-600 dark:text-surface-300 border border-surface-300 dark:border-surface-600 hover:border-primary-400'"
                  >
                    <span class="material-symbols-rounded text-sm">edit</span>
                    Can Edit
                  </button>
                </div>
              </div>

              <div class="flex gap-2">
                <div class="flex-1">
                  <label class="block text-[11px] font-medium text-surface-500 dark:text-surface-400 mb-1.5">Password</label>
                  <div class="flex gap-1.5">
                    <input
                      v-model="sharePassword"
                      type="password"
                      :placeholder="board.share_password ? '(password set)' : 'Optional'"
                      class="flex-1 px-3 py-1.5 text-xs rounded-xl border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-surface-900 dark:text-surface-100 outline-none"
                    />
                    <button
                      v-if="sharePassword"
                      @click="handleUpdateShareLink()"
                      class="px-2 py-1.5 rounded-xl bg-primary-500 text-white text-xs hover:bg-primary-600 transition-colors"
                      title="Save password"
                    >
                      <span class="material-symbols-rounded text-sm">check</span>
                    </button>
                  </div>
                </div>
                <div>
                  <label class="block text-[11px] font-medium text-surface-500 dark:text-surface-400 mb-1.5">Expiry</label>
                  <select
                    v-model="shareExpiryHours"
                    @change="handleUpdateShareLink()"
                    class="px-2 py-1.5 text-xs rounded-xl border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-surface-900 dark:text-surface-100 outline-none"
                  >
                    <option v-for="opt in expiryOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                  </select>
                </div>
              </div>

              <!-- Comment settings -->
              <div class="space-y-2.5 pt-1">
                <div class="flex items-center justify-between">
                  <div class="flex items-center gap-2">
                    <span class="material-symbols-rounded text-sm text-surface-500">comment</span>
                    <span class="text-xs font-medium text-surface-700 dark:text-surface-300">Allow Comments</span>
                  </div>
                  <button
                    @click="toggleAllowComments"
                    class="relative w-9 h-5 rounded-full transition-colors duration-200 focus:outline-none"
                    :class="board.allow_comments !== false ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'"
                  >
                    <span
                      class="absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full shadow transition-transform duration-200"
                      :class="board.allow_comments !== false ? 'translate-x-4' : 'translate-x-0'"
                    />
                  </button>
                </div>
                <div class="flex items-center justify-between">
                  <div class="flex items-center gap-2">
                    <span class="material-symbols-rounded text-sm text-surface-500">notifications</span>
                    <span class="text-xs font-medium text-surface-700 dark:text-surface-300">Email on New Comment</span>
                  </div>
                  <button
                    @click="toggleNotifyOnComment"
                    class="relative w-9 h-5 rounded-full transition-colors duration-200 focus:outline-none"
                    :class="board.notify_on_comment !== false ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'"
                  >
                    <span
                      class="absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full shadow transition-transform duration-200"
                      :class="board.notify_on_comment !== false ? 'translate-x-4' : 'translate-x-0'"
                    />
                  </button>
                </div>
              </div>

              <!-- Quick stats -->
              <div v-if="shareStats?.summary" class="flex gap-2 pt-1">
                <div class="flex-1 px-3 py-2 rounded-xl bg-white dark:bg-surface-700 border border-surface-200 dark:border-surface-600 text-center">
                  <p class="text-lg font-bold text-surface-900 dark:text-surface-100">{{ shareStats.summary.total_views }}</p>
                  <p class="text-[10px] text-surface-400">Views</p>
                </div>
                <div class="flex-1 px-3 py-2 rounded-xl bg-white dark:bg-surface-700 border border-surface-200 dark:border-surface-600 text-center">
                  <p class="text-lg font-bold text-surface-900 dark:text-surface-100">{{ shareStats.summary.unique_visitors }}</p>
                  <p class="text-[10px] text-surface-400">Visitors</p>
                </div>
                <div class="flex-1 px-3 py-2 rounded-xl bg-white dark:bg-surface-700 border border-surface-200 dark:border-surface-600 text-center">
                  <p class="text-lg font-bold text-surface-900 dark:text-surface-100">{{ formatDuration(shareStats.summary.avg_duration_seconds) }}</p>
                  <p class="text-[10px] text-surface-400">Avg Time</p>
                </div>
              </div>
            </div>
          </template>

          <template v-else>
            <p class="text-xs text-surface-400">
              Create a public link to share this board with anyone — no login required.
            </p>
          </template>
        </div>

        <!-- Divider -->
        <div class="border-t border-surface-200 dark:border-surface-700 -mx-4 mb-2"></div>

        <!-- Owner -->
        <div>
          <label class="block text-xs font-medium text-surface-500 dark:text-surface-400 mb-2">Owner</label>
          <div class="flex items-center gap-3 p-3 rounded-xl bg-surface-50 dark:bg-surface-700/50">
            <div class="w-8 h-8 rounded-full bg-primary-500 flex items-center justify-center text-white text-xs font-bold flex-shrink-0">
              {{ board.owner_email?.charAt(0).toUpperCase() }}
            </div>
            <div class="flex-1 min-w-0">
              <p class="text-sm font-medium text-surface-900 dark:text-surface-100 truncate">{{ board.owner_email }}</p>
              <p class="text-[11px] text-primary-500">Owner</p>
            </div>
          </div>
        </div>

        <!-- Add member -->
        <div>
          <label class="block text-xs font-medium text-surface-500 dark:text-surface-400 mb-2">Share with People</label>
          <div class="flex gap-2">
            <div class="flex-1 relative" ref="peopleDropdownRef">
              <input
                v-model="memberSearch"
                @focus="showMemberDropdown = true"
                @input="showMemberDropdown = true"
                type="text"
                placeholder="Search colleagues..."
                class="w-full px-3 py-2 text-sm rounded-xl border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-surface-900 dark:text-surface-100 focus:ring-2 focus:ring-primary-500 focus:border-transparent outline-none"
              />
              <!-- Colleagues dropdown -->
              <div
                v-if="showMemberDropdown && filteredColleagues.length > 0"
                class="absolute top-full left-0 right-0 mt-1 bg-white dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-xl shadow-lg z-10 max-h-48 overflow-y-auto"
              >
                <button
                  v-for="colleague in filteredColleagues"
                  :key="colleague.id"
                  @click="selectColleague(colleague)"
                  class="w-full px-3 py-2 text-sm text-left hover:bg-surface-50 dark:hover:bg-surface-600 text-surface-700 dark:text-surface-300 flex items-center gap-2"
                >
                  <div
                    class="w-6 h-6 rounded-full flex items-center justify-center text-white text-[10px] font-bold flex-shrink-0"
                    :style="{ backgroundColor: getMemberColor(colleague.email) }"
                  >
                    {{ (colleague.display_name || colleague.email).charAt(0).toUpperCase() }}
                  </div>
                  <div class="flex-1 min-w-0">
                    <span class="block truncate">{{ colleague.display_name || colleague.email }}</span>
                    <span v-if="colleague.display_name" class="block text-[10px] text-surface-400 truncate">{{ colleague.email }}</span>
                  </div>
                  <span
                    v-if="colleaguesStore.getColleagueStatus(colleague.email) !== 'offline'"
                    class="w-2 h-2 rounded-full bg-green-500 flex-shrink-0"
                  />
                </button>
              </div>
              <div
                v-else-if="showMemberDropdown && memberSearch && filteredColleagues.length === 0"
                class="absolute top-full left-0 right-0 mt-1 bg-white dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-xl shadow-lg z-10 px-3 py-3"
              >
                <p class="text-xs text-surface-400 text-center">No colleagues found</p>
              </div>
            </div>
            <select
              v-model="newMemberRole"
              class="px-2 py-2 text-sm rounded-xl border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-surface-900 dark:text-surface-100 outline-none"
            >
              <option value="viewer">Viewer</option>
              <option value="editor">Editor</option>
              <option value="admin">Admin</option>
            </select>
          </div>
          <button
            @click="handleAddMember"
            :disabled="!newMemberEmail || addingMember"
            class="mt-2 w-full flex items-center justify-center gap-1.5 px-4 py-2 text-sm rounded-xl bg-primary-500 text-white hover:bg-primary-600 transition-colors disabled:opacity-50 disabled:pointer-events-none"
          >
            <span class="material-symbols-rounded text-sm">person_add</span>
            {{ addingMember ? 'Adding...' : 'Add Member' }}
          </button>
        </div>

        <!-- Member list -->
        <div v-if="members.length > 0">
          <label class="block text-xs font-medium text-surface-500 dark:text-surface-400 mb-2">Members ({{ members.length }})</label>
          <div class="space-y-1.5">
            <div
              v-for="member in members"
              :key="member.email"
              class="flex items-center gap-2 p-2.5 rounded-xl bg-surface-50 dark:bg-surface-700/50 group"
            >
              <div
                class="w-8 h-8 rounded-full flex items-center justify-center text-white text-xs font-bold flex-shrink-0"
                :style="{ backgroundColor: getMemberColor(member.email) }"
              >
                {{ member.display_name ? member.display_name.charAt(0).toUpperCase() : member.email.charAt(0).toUpperCase() }}
              </div>
              <div class="flex-1 min-w-0">
                <p class="text-sm text-surface-900 dark:text-surface-100 truncate">
                  {{ member.display_name || member.email }}
                </p>
                <p v-if="member.display_name" class="text-[11px] text-surface-400 truncate">{{ member.email }}</p>
              </div>
              <select
                :value="member.role"
                @change="handleUpdateRole(member.email, $event.target.value)"
                class="text-xs px-1.5 py-1 rounded-lg border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-surface-600 dark:text-surface-300 outline-none"
              >
                <option value="viewer">Viewer</option>
                <option value="editor">Editor</option>
                <option value="admin">Admin</option>
              </select>
              <button
                @click="handleRemoveMember(member.email)"
                class="p-1 rounded-lg text-surface-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 opacity-0 group-hover:opacity-100 transition-all"
                title="Remove member"
              >
                <span class="material-symbols-rounded text-sm">close</span>
              </button>
            </div>
          </div>
        </div>

        <!-- Divider -->
        <div class="border-t border-surface-200 dark:border-surface-700"></div>

        <!-- Group access -->
        <div>
          <label class="block text-xs font-medium text-surface-500 dark:text-surface-400 mb-2">Share with Groups</label>
          <div v-if="availableGroups.length > 0" class="flex gap-2">
            <select
              v-model="selectedGroupId"
              class="flex-1 px-3 py-2 text-sm rounded-xl border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-surface-900 dark:text-surface-100 outline-none"
            >
              <option value="">Select a group...</option>
              <option v-for="group in availableGroups" :key="group.id" :value="group.id">
                {{ group.name }}
              </option>
            </select>
            <select
              v-model="newGroupRole"
              class="px-2 py-2 text-sm rounded-xl border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-surface-900 dark:text-surface-100 outline-none"
            >
              <option value="viewer">Viewer</option>
              <option value="editor">Editor</option>
            </select>
          </div>
          <button
            v-if="availableGroups.length > 0"
            @click="handleAddGroup"
            :disabled="!selectedGroupId"
            class="mt-2 w-full flex items-center justify-center gap-1.5 px-4 py-2 text-sm rounded-xl bg-primary-500 text-white hover:bg-primary-600 transition-colors disabled:opacity-50 disabled:pointer-events-none"
          >
            <span class="material-symbols-rounded text-sm">group_add</span>
            Add Group
          </button>
          <p v-else class="text-xs text-surface-400 italic">No groups available. Create groups in Colleagues settings.</p>
        </div>

        <!-- Shared groups list -->
        <div v-if="sharedGroups.length > 0">
          <label class="block text-xs font-medium text-surface-500 dark:text-surface-400 mb-2">Shared Groups</label>
          <div class="space-y-1.5">
            <div
              v-for="group in sharedGroups"
              :key="group.group_id"
              class="flex items-center gap-2 p-2.5 rounded-xl bg-surface-50 dark:bg-surface-700/50 group/item"
            >
              <div
                class="w-8 h-8 rounded-full flex items-center justify-center text-white text-xs font-bold flex-shrink-0"
                :style="{ backgroundColor: group.group_color || '#6366f1' }"
              >
                <span class="material-symbols-rounded text-sm">{{ group.group_icon || 'group' }}</span>
              </div>
              <div class="flex-1 min-w-0">
                <p class="text-sm text-surface-900 dark:text-surface-100 truncate">{{ group.group_name }}</p>
                <p class="text-[11px] text-surface-400">{{ group.member_count || 0 }} members -- {{ group.role }}</p>
              </div>
              <button
                @click="handleRemoveGroup(group.group_id)"
                class="p-1 rounded-lg text-surface-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 opacity-0 group-hover/item:opacity-100 transition-all"
                title="Remove group access"
              >
                <span class="material-symbols-rounded text-sm">close</span>
              </button>
            </div>
          </div>
        </div>
      </template>

      <!-- LINKS TAB -->
      <template v-if="activeTab === 'links'">
        <!-- Client assignment -->
        <div>
          <label class="block text-xs font-medium text-surface-500 dark:text-surface-400 mb-2">
            <span class="material-symbols-rounded text-sm align-middle mr-1">person</span>
            Assign to Client
          </label>
          <div v-if="board.client" class="flex items-center gap-2 p-3 rounded-xl bg-primary-50 dark:bg-primary-900/20 border border-primary-200 dark:border-primary-800">
            <span class="material-symbols-rounded text-lg text-primary-500">person</span>
            <div class="flex-1 min-w-0">
              <p class="text-sm font-medium text-surface-900 dark:text-surface-100">{{ board.client.display_name || board.client.domain }}</p>
              <p class="text-[11px] text-surface-400">{{ board.client.domain }}</p>
            </div>
            <button
              @click="handleUnlinkClient"
              class="p-1.5 rounded-lg text-surface-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors"
              title="Unlink from client"
            >
              <span class="material-symbols-rounded text-sm">link_off</span>
            </button>
          </div>
          <div v-else>
            <div class="relative">
              <input
                v-model="clientSearch"
                type="text"
                placeholder="Search clients..."
                class="w-full px-3 py-2 text-sm rounded-xl border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-surface-900 dark:text-surface-100 focus:ring-2 focus:ring-primary-500 focus:border-transparent outline-none"
                @focus="showClientDropdown = true"
              />
              <div
                v-if="showClientDropdown && filteredClients.length > 0"
                class="absolute top-full left-0 right-0 mt-1 bg-white dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-xl shadow-lg z-10 max-h-40 overflow-y-auto"
              >
                <button
                  v-for="client in filteredClients"
                  :key="client.id"
                  @click="handleLinkClient(client)"
                  class="w-full px-3 py-2 text-sm text-left hover:bg-surface-50 dark:hover:bg-surface-600 text-surface-700 dark:text-surface-300 flex items-center gap-2"
                >
                  <span class="material-symbols-rounded text-sm text-surface-400">person</span>
                  <span>{{ client.display_name || client.domain }}</span>
                  <span v-if="client.display_name" class="text-[10px] text-surface-400 ml-auto">{{ client.domain }}</span>
                </button>
              </div>
            </div>
          </div>
        </div>

        <!-- Divider -->
        <div class="border-t border-surface-200 dark:border-surface-700"></div>

        <!-- Linked kanban boards (gated by kanban_boards addon) -->
        <div v-if="kanbanBoardsEnabled">
          <label class="block text-xs font-medium text-surface-500 dark:text-surface-400 mb-2">
            <span class="material-symbols-rounded text-sm align-middle mr-1">view_kanban</span>
            Linked Todo Boards
          </label>

          <!-- Add link -->
          <div class="relative">
            <input
              v-model="boardSearch"
              type="text"
              placeholder="Search todo boards..."
              class="w-full px-3 py-2 text-sm rounded-xl border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-surface-900 dark:text-surface-100 focus:ring-2 focus:ring-primary-500 focus:border-transparent outline-none"
              @focus="showBoardDropdown = true"
            />
            <div
              v-if="showBoardDropdown && filteredKanbanBoards.length > 0"
              class="absolute top-full left-0 right-0 mt-1 bg-white dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-xl shadow-lg z-10 max-h-40 overflow-y-auto"
            >
              <button
                v-for="kb in filteredKanbanBoards"
                :key="kb.id"
                @click="handleLinkKanbanBoard(kb)"
                class="w-full px-3 py-2 text-sm text-left hover:bg-surface-50 dark:hover:bg-surface-600 text-surface-700 dark:text-surface-300 flex items-center gap-2"
              >
                <span class="material-symbols-rounded text-sm text-surface-400">view_kanban</span>
                <span>{{ kb.name }}</span>
                <span class="text-[10px] text-surface-400 ml-auto">{{ kb.card_count || 0 }} cards</span>
              </button>
            </div>
          </div>

          <!-- Linked boards list -->
          <div v-if="linkedBoards.length > 0" class="mt-3 space-y-1.5">
            <div
              v-for="link in linkedBoards"
              :key="link.kanban_board_id"
              class="flex items-center gap-2 p-2.5 rounded-xl bg-surface-50 dark:bg-surface-700/50 group"
            >
              <span class="material-symbols-rounded text-lg text-indigo-500">view_kanban</span>
              <div class="flex-1 min-w-0">
                <p class="text-sm text-surface-900 dark:text-surface-100 truncate">{{ link.board_name }}</p>
                <p class="text-[11px] text-surface-400">{{ link.card_count || 0 }} cards</p>
              </div>
              <button
                @click="handleUnlinkKanbanBoard(link.kanban_board_id)"
                class="p-1 rounded-lg text-surface-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 opacity-0 group-hover:opacity-100 transition-all"
                title="Unlink board"
              >
                <span class="material-symbols-rounded text-sm">link_off</span>
              </button>
            </div>
          </div>
          <p v-else class="mt-2 text-xs text-surface-400 italic">No linked todo boards yet.</p>
        </div>
      </template>

      <!-- ANALYTICS TAB -->
      <template v-if="activeTab === 'analytics'">
        <div v-if="loadingStats" class="flex items-center justify-center py-12">
          <span class="material-symbols-rounded text-2xl text-surface-400 animate-spin">sync</span>
        </div>

        <template v-else-if="shareStats">
          <!-- Share Status -->
          <div class="flex items-center gap-3 p-3 rounded-xl border" :class="shareStats.share_info.is_active && !shareStats.share_info.is_expired ? 'bg-green-50 dark:bg-green-900/15 border-green-200 dark:border-green-800' : 'bg-surface-50 dark:bg-surface-700/30 border-surface-200 dark:border-surface-700'">
            <span class="material-symbols-rounded text-xl" :class="shareStats.share_info.is_active && !shareStats.share_info.is_expired ? 'text-green-500' : 'text-surface-400'">
              {{ shareStats.share_info.is_active && !shareStats.share_info.is_expired ? 'link' : 'link_off' }}
            </span>
            <div class="flex-1 min-w-0">
              <p class="text-sm font-medium" :class="shareStats.share_info.is_active && !shareStats.share_info.is_expired ? 'text-green-700 dark:text-green-400' : 'text-surface-500'">
                {{ shareStats.share_info.is_active && !shareStats.share_info.is_expired ? 'Link Active' : shareStats.share_info.is_expired ? 'Link Expired' : 'No Active Link' }}
              </p>
              <p class="text-[11px] text-surface-400">
                {{ shareStats.share_info.mode === 'edit' ? 'Edit access' : shareStats.share_info.mode === 'view' ? 'View only' : 'Disabled' }}
                <span v-if="shareStats.share_info.has_password" class="ml-1.5">-- Password protected</span>
                <span v-if="shareStats.share_info.expires_at" class="ml-1.5">-- Expires {{ formatDate(shareStats.share_info.expires_at) }}</span>
              </p>
            </div>
          </div>

          <!-- Summary Cards -->
          <div class="grid grid-cols-2 gap-3">
            <div class="px-4 py-3 rounded-xl bg-surface-50 dark:bg-surface-700/30 border border-surface-200 dark:border-surface-700">
              <div class="flex items-center gap-2 mb-1">
                <span class="material-symbols-rounded text-sm text-primary-500">visibility</span>
                <span class="text-[11px] text-surface-400 font-medium">Total Views</span>
              </div>
              <p class="text-2xl font-bold text-surface-900 dark:text-surface-100">{{ shareStats.summary.total_views }}</p>
            </div>
            <div class="px-4 py-3 rounded-xl bg-surface-50 dark:bg-surface-700/30 border border-surface-200 dark:border-surface-700">
              <div class="flex items-center gap-2 mb-1">
                <span class="material-symbols-rounded text-sm text-indigo-500">people</span>
                <span class="text-[11px] text-surface-400 font-medium">Unique Visitors</span>
              </div>
              <p class="text-2xl font-bold text-surface-900 dark:text-surface-100">{{ shareStats.summary.unique_visitors }}</p>
            </div>
            <div class="px-4 py-3 rounded-xl bg-surface-50 dark:bg-surface-700/30 border border-surface-200 dark:border-surface-700">
              <div class="flex items-center gap-2 mb-1">
                <span class="material-symbols-rounded text-sm text-amber-500">schedule</span>
                <span class="text-[11px] text-surface-400 font-medium">Avg Session</span>
              </div>
              <p class="text-2xl font-bold text-surface-900 dark:text-surface-100">{{ formatDuration(shareStats.summary.avg_duration_seconds) }}</p>
            </div>
            <div class="px-4 py-3 rounded-xl bg-surface-50 dark:bg-surface-700/30 border border-surface-200 dark:border-surface-700">
              <div class="flex items-center gap-2 mb-1">
                <span class="material-symbols-rounded text-sm text-green-500">timer</span>
                <span class="text-[11px] text-surface-400 font-medium">Total Time</span>
              </div>
              <p class="text-2xl font-bold text-surface-900 dark:text-surface-100">{{ formatDuration(shareStats.summary.total_duration_seconds) }}</p>
            </div>
          </div>

          <!-- Views by Day (mini chart) -->
          <div v-if="shareStats.views_by_day?.length > 0">
            <label class="block text-xs font-medium text-surface-500 dark:text-surface-400 mb-2">Views (Last 30 Days)</label>
            <div class="space-y-1">
              <div
                v-for="day in shareStats.views_by_day.slice(0, 14)"
                :key="day.date"
                class="flex items-center gap-2"
              >
                <span class="text-[11px] text-surface-400 w-16 flex-shrink-0">{{ new Date(day.date).toLocaleDateString('en', { month: 'short', day: 'numeric' }) }}</span>
                <div class="flex-1 h-4 bg-surface-100 dark:bg-surface-700 rounded-full overflow-hidden">
                  <div
                    class="h-full bg-primary-500 rounded-full transition-all"
                    :style="{ width: Math.min(100, (day.views / Math.max(...shareStats.views_by_day.map(d => d.views))) * 100) + '%' }"
                  />
                </div>
                <span class="text-[11px] font-medium text-surface-600 dark:text-surface-300 w-6 text-right">{{ day.views }}</span>
              </div>
            </div>
          </div>

          <!-- Device Breakdown -->
          <div v-if="shareStats.devices?.length > 0">
            <label class="block text-xs font-medium text-surface-500 dark:text-surface-400 mb-2">Devices</label>
            <div class="flex gap-2 flex-wrap">
              <div
                v-for="device in shareStats.devices"
                :key="device.device_type"
                class="px-3 py-2 rounded-xl bg-surface-50 dark:bg-surface-700/30 border border-surface-200 dark:border-surface-700 flex items-center gap-2"
              >
                <span class="material-symbols-rounded text-sm text-surface-400">
                  {{ device.device_type === 'desktop' ? 'computer' : device.device_type === 'mobile' ? 'smartphone' : device.device_type === 'tablet' ? 'tablet' : 'devices' }}
                </span>
                <span class="text-xs text-surface-600 dark:text-surface-300 capitalize">{{ device.device_type }}</span>
                <span class="text-xs font-bold text-surface-900 dark:text-surface-100">{{ device.count }}</span>
              </div>
            </div>
          </div>

          <!-- Browser Breakdown -->
          <div v-if="shareStats.browsers?.length > 0">
            <label class="block text-xs font-medium text-surface-500 dark:text-surface-400 mb-2">Browsers</label>
            <div class="flex gap-2 flex-wrap">
              <div
                v-for="br in shareStats.browsers"
                :key="br.browser"
                class="px-3 py-1.5 rounded-xl bg-surface-50 dark:bg-surface-700/30 border border-surface-200 dark:border-surface-700 text-xs"
              >
                <span class="text-surface-600 dark:text-surface-300">{{ br.browser }}</span>
                <span class="font-bold text-surface-900 dark:text-surface-100 ml-1.5">{{ br.count }}</span>
              </div>
            </div>
          </div>

          <!-- Recent Visitors -->
          <div v-if="shareStats.recent_visitors?.length > 0">
            <label class="block text-xs font-medium text-surface-500 dark:text-surface-400 mb-2">Recent Visitors ({{ shareStats.recent_visitors.length }})</label>
            <div class="space-y-1.5 max-h-60 overflow-y-auto">
              <div
                v-for="visitor in shareStats.recent_visitors"
                :key="visitor.session_id"
                class="flex items-center gap-2 p-2.5 rounded-xl bg-surface-50 dark:bg-surface-700/30"
              >
                <span class="material-symbols-rounded text-lg text-surface-300">
                  {{ visitor.device_type === 'mobile' ? 'smartphone' : visitor.device_type === 'tablet' ? 'tablet' : 'computer' }}
                </span>
                <div class="flex-1 min-w-0">
                  <p class="text-xs text-surface-700 dark:text-surface-300">
                    {{ visitor.browser || 'Unknown' }} / {{ visitor.os || 'Unknown' }}
                  </p>
                  <p class="text-[10px] text-surface-400">
                    {{ formatDate(visitor.started_at) }}
                    <span v-if="visitor.duration_seconds > 0" class="ml-1">-- {{ formatDuration(visitor.duration_seconds) }}</span>
                    <span v-if="visitor.slides_viewed > 0" class="ml-1">-- {{ visitor.slides_viewed }} slides</span>
                  </p>
                </div>
                <span v-if="visitor.visitor_ip" class="text-[10px] text-surface-300 font-mono">{{ visitor.visitor_ip }}</span>
              </div>
            </div>
          </div>

          <!-- Refresh -->
          <button
            @click="loadShareStats()"
            class="w-full flex items-center justify-center gap-1.5 px-4 py-2 text-xs rounded-xl border border-surface-300 dark:border-surface-600 text-surface-500 hover:bg-surface-50 dark:hover:bg-surface-700 transition-colors"
          >
            <span class="material-symbols-rounded text-sm">refresh</span>
            Refresh Stats
          </button>
        </template>

        <template v-else>
          <div class="flex flex-col items-center justify-center py-12 text-surface-400">
            <span class="material-symbols-rounded text-4xl mb-2">monitoring</span>
            <p class="text-sm">No analytics data yet</p>
            <p class="text-xs mt-1">Share your board publicly to start tracking views</p>
          </div>
        </template>
      </template>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import { useMoodBoardsStore } from '@/addons/moodboards/stores/moodBoards'
import { useColleaguesStore } from '@/addons/team/stores/colleagues'
import { useToastStore } from '@/stores/toast'
import { useAddons } from '@/composables/useAddons'
import api from '@/services/api'
import { getPublicOrigin } from '@/services/serverRegistry'

const { kanbanBoardsEnabled } = useAddons()
const props = defineProps({
  board: { type: Object, required: true },
  initialTab: { type: String, default: 'general' }
})

const emit = defineEmits(['close', 'update-field'])

const store = useMoodBoardsStore()
const colleaguesStore = useColleaguesStore()
const toast = useToastStore()

// Connection panel position preview
const connPanelPreview = ref(null)

// Tabs
const tabs = [
  { id: 'general', label: 'General', icon: 'tune' },
  { id: 'sharing', label: 'Sharing', icon: 'share' },
  { id: 'links', label: 'Links', icon: 'link' },
  { id: 'analytics', label: 'Analytics', icon: 'monitoring' },
]
const activeTab = ref(props.initialTab || 'general')

// React to initialTab changes when panel is reopened
watch(() => props.initialTab, (val) => {
  if (val) activeTab.value = val
})

// Reload stats when switching to analytics tab
watch(activeTab, (tab) => {
  if (tab === 'analytics') loadShareStats()
})

// Public sharing
const shareEnabled = computed(() => !!props.board.share_token && props.board.share_mode !== 'off')
const shareMode = ref(props.board.share_mode && props.board.share_mode !== 'off' ? props.board.share_mode : 'view')
const sharePassword = ref('')
const shareExpiryHours = ref(null)
const creatingShareLink = ref(false)
const shareLinkCopied = ref(false)

const shareUrl = computed(() => {
  if (!props.board.share_token) return ''
  // Derive from the active deployment origin (white-label safe; native-safe via
  // serverRegistry) instead of a hardcoded flowone.pro host.
  const base = getPublicOrigin()
  if (!base) return ''
  return `${base}/mood/share/${props.board.share_token}`
})

// Analytics state
const shareStats = ref(null)
const loadingStats = ref(false)
const sharedBoardsList = ref([])

// Sharing (colleague members)
const newMemberEmail = ref('')
const memberSearch = ref('')
const showMemberDropdown = ref(false)
const peopleDropdownRef = ref(null)
const newMemberRole = ref('editor')
const addingMember = ref(false)
const selectedGroupId = ref('')
const newGroupRole = ref('editor')

// Links
const clientSearch = ref('')
const showClientDropdown = ref(false)
const boardSearch = ref('')
const showBoardDropdown = ref(false)
const allClients = ref([])
const allKanbanBoards = ref([])

// Computed
const members = computed(() => props.board.members || [])
const sharedGroups = computed(() => props.board.groups || [])
const linkedBoards = computed(() => props.board.linked_boards || [])

// Colleagues filtered for sharing (exclude owner, already-members)
const filteredColleagues = computed(() => {
  const existingEmails = new Set([
    ...(members.value.map(m => m.email?.toLowerCase())),
    props.board.owner_email?.toLowerCase()
  ])
  let list = (colleaguesStore.sortedColleagues || []).filter(c =>
    !existingEmails.has(c.email?.toLowerCase())
  )
  if (memberSearch.value) {
    const q = memberSearch.value.toLowerCase()
    list = list.filter(c =>
      (c.display_name || '').toLowerCase().includes(q) ||
      c.email.toLowerCase().includes(q)
    )
  }
  return list.slice(0, 15)
})

function selectColleague(colleague) {
  newMemberEmail.value = colleague.email
  memberSearch.value = colleague.display_name || colleague.email
  showMemberDropdown.value = false
}

const availableGroups = computed(() => {
  const sharedIds = new Set(sharedGroups.value.map(g => g.group_id))
  return (colleaguesStore.groups || []).filter(g => !sharedIds.has(g.id))
})

const filteredClients = computed(() => {
  if (!clientSearch.value) return allClients.value.slice(0, 10)
  const q = clientSearch.value.toLowerCase()
  return allClients.value
    .filter(c => (c.display_name || '').toLowerCase().includes(q) || (c.domain || '').toLowerCase().includes(q))
    .slice(0, 10)
})

const filteredKanbanBoards = computed(() => {
  const linkedIds = new Set(linkedBoards.value.map(l => l.kanban_board_id))
  let boards = allKanbanBoards.value.filter(b => !linkedIds.has(b.id))
  if (boardSearch.value) {
    const q = boardSearch.value.toLowerCase()
    boards = boards.filter(b => b.name.toLowerCase().includes(q))
  }
  return boards.slice(0, 10)
})

// Color util
function getMemberColor(email) {
  const colors = ['#6366f1', '#8b5cf6', '#ec4899', '#f97316', '#22c55e', '#3b82f6', '#ef4444', '#14b8a6']
  let hash = 0
  for (let i = 0; i < email.length; i++) hash = email.charCodeAt(i) + ((hash << 5) - hash)
  return colors[Math.abs(hash) % colors.length]
}

// ========================================
// MEMBER HANDLERS
// ========================================

async function handleAddMember() {
  if (!newMemberEmail.value) return
  addingMember.value = true
  const success = await store.addMember(newMemberEmail.value, newMemberRole.value)
  if (success) {
    toast.show('Member added', 'success')
    newMemberEmail.value = ''
    memberSearch.value = ''
  } else {
    toast.show('Failed to add member. Check the email is valid.', 'error')
  }
  addingMember.value = false
}

async function handleUpdateRole(email, role) {
  const success = await store.updateMemberRole(email, role)
  if (success) {
    toast.show('Role updated', 'success')
  }
}

async function handleRemoveMember(email) {
  const success = await store.removeMember(email)
  if (success) {
    toast.show('Member removed', 'success')
  }
}

// ========================================
// GROUP HANDLERS
// ========================================

async function handleAddGroup() {
  if (!selectedGroupId.value) return
  const success = await store.addGroupAccess(parseInt(selectedGroupId.value), newGroupRole.value)
  if (success) {
    toast.show('Group access granted', 'success')
    selectedGroupId.value = ''
  }
}

async function handleRemoveGroup(groupId) {
  const success = await store.removeGroupAccess(groupId)
  if (success) {
    toast.show('Group access removed', 'success')
  }
}

// ========================================
// PUBLIC SHARE HANDLERS
// ========================================

async function handleEnableShareLink() {
  creatingShareLink.value = true
  const result = await store.createShareLink(props.board.id, {
    mode: shareMode.value,
    password: sharePassword.value || null,
    expiresHours: shareExpiryHours.value
  })
  if (result) {
    toast.show('Public share link created', 'success')
    sharePassword.value = ''
    // Refresh board data
    await store.fetchBoard(props.board.id)
  } else {
    toast.show('Failed to create share link', 'error')
  }
  creatingShareLink.value = false
}

async function toggleAllowComments() {
  const newVal = props.board.allow_comments === false ? true : false
  const ok = await store.updateBoard(props.board.id, { allow_comments: newVal ? 1 : 0 })
  if (ok) {
    toast.show(newVal ? 'Comments enabled' : 'Comments disabled', 'success')
    await store.fetchBoard(props.board.id)
  } else {
    toast.show('Failed to update setting', 'error')
  }
}

async function toggleNotifyOnComment() {
  const newVal = props.board.notify_on_comment === false ? true : false
  const ok = await store.updateBoard(props.board.id, { notify_on_comment: newVal ? 1 : 0 })
  if (ok) {
    toast.show(newVal ? 'Notifications enabled' : 'Notifications disabled', 'success')
    await store.fetchBoard(props.board.id)
  } else {
    toast.show('Failed to update setting', 'error')
  }
}

async function handleUpdateShareLink() {
  const data = { mode: shareMode.value }
  if (sharePassword.value) data.password = sharePassword.value
  if (shareExpiryHours.value !== undefined) data.expires_hours = shareExpiryHours.value
  
  const result = await store.updateShareLink(props.board.id, data)
  if (result) {
    toast.show('Share link updated', 'success')
    sharePassword.value = ''
    await store.fetchBoard(props.board.id)
  } else {
    toast.show('Failed to update share link', 'error')
  }
}

async function handleDisableShareLink() {
  const success = await store.removeShareLink(props.board.id)
  if (success) {
    toast.show('Share link disabled', 'success')
    await store.fetchBoard(props.board.id)
  } else {
    toast.show('Failed to disable share link', 'error')
  }
}

async function copyShareLink() {
  if (!shareUrl.value) return
  try {
    await navigator.clipboard.writeText(shareUrl.value)
    shareLinkCopied.value = true
    toast.show('Link copied to clipboard', 'success')
    setTimeout(() => shareLinkCopied.value = false, 2000)
  } catch (e) {
    toast.show('Failed to copy link', 'error')
  }
}

async function loadShareStats() {
  if (!props.board.id) return
  loadingStats.value = true
  shareStats.value = await store.fetchShareStats(props.board.id)
  loadingStats.value = false
}

async function loadSharedBoardsList() {
  sharedBoardsList.value = await store.fetchSharedBoards()
}

function formatDuration(seconds) {
  if (!seconds || seconds < 1) return '0s'
  if (seconds < 60) return `${seconds}s`
  const m = Math.floor(seconds / 60)
  const s = seconds % 60
  if (m < 60) return `${m}m ${s}s`
  const h = Math.floor(m / 60)
  return `${h}h ${m % 60}m`
}

function formatDate(dateStr) {
  if (!dateStr) return '-'
  const d = new Date(dateStr)
  const now = new Date()
  const diff = now - d
  if (diff < 60000) return 'just now'
  if (diff < 3600000) return `${Math.floor(diff / 60000)}m ago`
  if (diff < 86400000) return `${Math.floor(diff / 3600000)}h ago`
  if (diff < 604800000) return `${Math.floor(diff / 86400000)}d ago`
  return d.toLocaleDateString()
}

// Expiry options
const expiryOptions = [
  { value: null, label: 'Never expires' },
  { value: 1, label: '1 hour' },
  { value: 24, label: '1 day' },
  { value: 168, label: '7 days' },
  { value: 720, label: '30 days' },
  { value: 2160, label: '90 days' }
]

// ========================================
// CLIENT HANDLERS
// ========================================

async function handleLinkClient(client) {
  showClientDropdown.value = false
  clientSearch.value = ''
  const success = await store.linkToClient(client.id, props.board.id)
  if (success) {
    toast.show(`Linked to ${client.display_name || client.domain}`, 'success')
    // Refresh board to get client info
    await store.fetchBoard(props.board.id)
  }
}

async function handleUnlinkClient() {
  if (!props.board.client_id) return
  const success = await store.unlinkFromClient(props.board.client_id, props.board.id)
  if (success) {
    toast.show('Client unlinked', 'success')
    await store.fetchBoard(props.board.id)
  }
}

// ========================================
// BOARD LINK HANDLERS
// ========================================

async function handleLinkKanbanBoard(kb) {
  showBoardDropdown.value = false
  boardSearch.value = ''
  const success = await store.linkToKanbanBoard(kb.id)
  if (success) {
    toast.show(`Linked to "${kb.name}"`, 'success')
  }
}

async function handleUnlinkKanbanBoard(kanbanBoardId) {
  const success = await store.unlinkFromKanbanBoard(kanbanBoardId)
  if (success) {
    toast.show('Board link removed', 'success')
  }
}

// ========================================
// DATA LOADING
// ========================================

async function loadClients() {
  try {
    const response = await api.get('/clients')
    if (response.data.success) {
      allClients.value = response.data.data.clients || response.data.data || []
    }
  } catch (e) {
    console.error('Failed to load clients:', e)
  }
}

async function loadKanbanBoards() {
  try {
    const response = await api.get('/boards')
    if (response.data.success) {
      allKanbanBoards.value = response.data.data.boards || response.data.data || []
    }
  } catch (e) {
    console.error('Failed to load kanban boards:', e)
  }
}

// Close dropdowns on outside click
function handleOutsideClick(e) {
  if (!e.target.closest('.relative')) {
    showClientDropdown.value = false
    showBoardDropdown.value = false
  }
  if (peopleDropdownRef.value && !peopleDropdownRef.value.contains(e.target)) {
    showMemberDropdown.value = false
  }
}

onMounted(() => {
  colleaguesStore.fetchGroups()
  if (!colleaguesStore.colleagues?.length) {
    colleaguesStore.fetchColleagues()
  }
  loadClients()
  loadKanbanBoards()
  loadShareStats()
  document.addEventListener('click', handleOutsideClick)
})

onUnmounted(() => {
  document.removeEventListener('click', handleOutsideClick)
})
</script>

