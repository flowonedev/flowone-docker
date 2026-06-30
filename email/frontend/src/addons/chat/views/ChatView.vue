<script setup>
import { ref, onMounted, onUnmounted, watch, computed, nextTick } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useChatStore } from '@/addons/chat/stores/chat'
import { useColleaguesStore } from '@/addons/team/stores/colleagues'
import { useAccountsStore } from '@/stores/accounts'
import AppHeader from '@/components/shared/AppHeader.vue'
import FeatureGuide from '@/components/shared/FeatureGuide.vue'
import HowItWorksButton from '@/components/shared/HowItWorksButton.vue'
import StepGuide from '@/components/shared/StepGuide.vue'
import ChatSidebar from '../components/ChatSidebar.vue'
import ChatRail from '../components/ChatRail.vue'
import { featureGuides } from '@/data/featureGuides'
import { chatGuide } from '@/data/stepGuides'
import ChatConversation from '../components/ChatConversation.vue'
import ThreadPanel from '../components/ThreadPanel.vue'
import ChannelMembersPanel from '../components/ChannelMembersPanel.vue'
import StatusPicker from '../components/StatusPicker.vue'
import AllThreadsPanel from '../components/AllThreadsPanel.vue'
import SavedMessages from '../components/SavedMessages.vue'
import ScheduledMessagesList from '../components/ScheduledMessagesList.vue'
import MobileBottomNav from '@/components/MobileBottomNav.vue'
import NewConversationModal from '../components/NewConversationModal.vue'
import GroupChatModal from '../components/GroupChatModal.vue'
import ChannelCreateModal from '../components/ChannelCreateModal.vue'

const route = useRoute()
const router = useRouter()
const chatStore = useChatStore()
const colleaguesStore = useColleaguesStore()
const accountsStore = useAccountsStore()

// Feature guide
const showFeatureGuide = ref(false)
const showStepGuide = ref(false)
const guideData = featureGuides.chat

// Mobile detection
const isMobile = ref(window.innerWidth < 768)
const chatViewRef = ref(null)

function updateMobileState() {
  isMobile.value = window.innerWidth < 768
}

// VisualViewport handler: keeps header visible when iOS/Android keyboard opens.
// On mobile PWA, 100vh/dvh do NOT shrink when the keyboard appears (keyboard is an overlay).
// The only reliable way is to listen to window.visualViewport.resize and set height in JS.
// IMPORTANT: only applies when a conversation is active (keyboard can open).
// In sidebar/list mode, use normal CSS height to avoid creating a gap at the bottom.
function handleViewportResize() {
  if (!chatViewRef.value || !window.visualViewport) return
  
  // In sidebar mode (no conversation) - clear any inline override, let CSS handle it
  if (!chatStore.activeConversationId) {
    chatViewRef.value.style.height = ''
    chatViewRef.value.style.transform = ''
    return
  }
  
  const vv = window.visualViewport
  // Set the container to exactly the visible area (excludes keyboard)
  chatViewRef.value.style.height = vv.height + 'px'
  // If the viewport scrolled (iOS can do this), offset to stay in view
  chatViewRef.value.style.transform = vv.offsetTop ? `translateY(${vv.offsetTop}px)` : ''
}

// Show sidebar on mobile (when no conversation selected or explicitly showing sidebar)
const showMobileSidebar = computed(() => {
  if (!isMobile.value) return true
  return !chatStore.activeConversationId
})

// Show conversation on mobile (when conversation is selected)
const showMobileConversation = computed(() => {
  if (!isMobile.value) return true
  return !!chatStore.activeConversationId
})

// Handle mobile back button
function handleMobileBack() {
  chatStore.setActiveConversation(null)
}


// New conversation modal
const showNewConversation = ref(false)
const showGroupModal = ref(false)
const showChannelModal = ref(false)
const preSelectedMembers = ref([])

function openNewChat() {
  showNewConversation.value = true
}

function handleOpenGroup(memberIds) {
  preSelectedMembers.value = memberIds || []
  showGroupModal.value = true
}

function handleOpenChannel() {
  showChannelModal.value = true
}

function handleGroupCreated(conv) {
  showGroupModal.value = false
  if (conv?.id) {
    chatStore.setActiveConversation(conv.id)
    chatStore.fetchConversations()
  }
}

function handleGroupBack() {
  showGroupModal.value = false
  showNewConversation.value = true
}

function handleChannelCreated() {
  showChannelModal.value = false
  chatStore.fetchConversations()
}

function handleChannelBack() {
  showChannelModal.value = false
  showNewConversation.value = true
}

// When switching between sidebar and conversation, reset/apply viewport handler
watch(() => chatStore.activeConversationId, () => {
  nextTick(() => handleViewportResize())
})

// Lifecycle
onMounted(async () => {
  // Initialize stores
  await colleaguesStore.init()
  await chatStore.init()
  await accountsStore.fetchAccounts()
  
  // Refresh presence data to get latest online statuses
  colleaguesStore.refreshPresence()
  
  // Handle deep link from search: ?conversation=ID&message=MSG_ID
  const conversationId = route.query.conversation ? parseInt(route.query.conversation) : null
  const messageId = route.query.message ? parseInt(route.query.message) : null
  if (conversationId) {
    await chatStore.setActiveConversation(conversationId)
    if (messageId) {
      // Set pending scroll so ChatConversation picks it up
      chatStore.pendingScrollToMessage = messageId
    }
    // Clear query params without triggering navigation
    router.replace({ path: '/chat', query: {} })
  }
  
  // Mobile detection
  window.addEventListener('resize', updateMobileState)
  updateMobileState()
  
  // VisualViewport: resize container when keyboard opens/closes on mobile PWA
  if (window.visualViewport) {
    window.visualViewport.addEventListener('resize', handleViewportResize)
    window.visualViewport.addEventListener('scroll', handleViewportResize)
    // Set initial height
    nextTick(() => handleViewportResize())
  }
})

onUnmounted(() => {
  chatStore.cleanup()
  window.removeEventListener('resize', updateMobileState)
  if (window.visualViewport) {
    window.visualViewport.removeEventListener('resize', handleViewportResize)
    window.visualViewport.removeEventListener('scroll', handleViewportResize)
  }
})
</script>

<template>
  <div ref="chatViewRef" class="chat-view flex flex-col bg-surface-50 dark:bg-surface-900 ambient-tint" :class="isMobile ? (chatStore.activeConversationId ? 'overflow-y-auto' : 'overflow-y-auto pb-20') : 'overflow-hidden'">
    <!-- Top bar (hidden on mobile when conversation is open) -->
    <AppHeader
      v-show="!isMobile || !chatStore.activeConversationId"
      current-view="chat"
      icon="chat"
      :title="$t('chatView.chat')"
    >
      <template #title-badge>
        <span 
          v-if="chatStore.totalUnread > 0"
          class="px-2 py-0.5 text-xs font-medium bg-red-500 text-white rounded-full"
        >
          {{ chatStore.totalUnread }}
        </span>
        <HowItWorksButton @click="showStepGuide = true" />
        <HowItWorksButton variant="features" :active="showFeatureGuide" @click="showFeatureGuide = !showFeatureGuide" />
      </template>
    </AppHeader>
    
    <!-- Feature Guide -->
    <FeatureGuide v-model="showFeatureGuide" :tiers="guideData.tiers" :integrations="guideData.integrations" :title-key="guideData.titleKey" :footer-key="guideData.footerKey" :layer-key="guideData.layerKey" :layer-icon="guideData.layerIcon" />
    
    <!-- Main content -->
    <div class="flex-1 flex overflow-hidden">
      <!-- Thin icon rail (Teams/WhatsApp style) — desktop/web only -->
      <ChatRail v-show="!isMobile" />

      <!-- Sidebar (hidden on mobile when conversation is open) -->
      <ChatSidebar 
        v-show="showMobileSidebar"
        :class="isMobile ? 'w-full' : 'w-80'"
        @new-chat="openNewChat"
        @select-conversation="chatStore.setActiveConversation"
      />
      
      <!-- Conversation area (hidden on mobile when showing sidebar) -->
      <div v-show="showMobileConversation" class="flex-1 flex overflow-hidden">
        <div class="flex-1 flex flex-col overflow-hidden">
          <ChatConversation 
            v-if="chatStore.activeConversationId" 
            :show-back-button="isMobile"
            :use-safe-area="isMobile"
            :has-footer-nav="false"
            @back="handleMobileBack"
          />
          
          <!-- Empty state (hidden on mobile since sidebar is shown instead) -->
          <div 
            v-else-if="!isMobile"
            class="flex-1 flex flex-col items-center justify-center text-center p-8"
          >
            <div class="w-20 h-20 rounded-full bg-primary-100 dark:bg-primary-500/20 flex items-center justify-center mb-4">
              <span class="material-symbols-rounded text-4xl text-primary-500">forum</span>
            </div>
            <h2 class="text-xl font-semibold text-surface-900 dark:text-surface-100 mb-2">
              {{ $t('chatView.welcomeToChat') }}
            </h2>
            <p class="text-surface-500 max-w-md mb-6">
              {{ $t('chatView.startAConversationWithYourColleagues') }}
            </p>
            <button
              @click="openNewChat"
              class="flex items-center gap-2 px-6 py-3 bg-primary-500 text-white rounded-full hover:bg-primary-600 transition-colors font-medium"
            >
              <span class="material-symbols-rounded">add</span>
              {{ $t('chatView.newConversation') }}
            </button>
          </div>
        </div>
        
        <!-- Thread Panel (slides in from right) -->
        <ThreadPanel v-if="!isMobile && !chatStore.showMembersPanel" />

        <!-- Channel Members Panel (right sidebar) -->
        <ChannelMembersPanel v-if="!isMobile && chatStore.showMembersPanel" />
      </div>
    </div>
    
    <!-- New Conversation Modal -->
    <NewConversationModal
      :show="showNewConversation"
      @close="showNewConversation = false"
      @open-group="handleOpenGroup"
      @open-channel="handleOpenChannel"
      @started="showNewConversation = false"
    />

    <!-- Group Chat Modal -->
    <GroupChatModal
      :show="showGroupModal"
      @close="showGroupModal = false"
      @back="handleGroupBack"
      @created="handleGroupCreated"
    />

    <!-- Channel Create Modal -->
    <ChannelCreateModal
      v-if="showChannelModal"
      @close="showChannelModal = false"
      @back="handleChannelBack"
      @created="handleChannelCreated"
    />

    <!-- Secondary chat panels — opened from the desktop rail or the mobile
         actions row, rendered once here and driven by chatStore.activePanel. -->
    <AllThreadsPanel
      v-if="chatStore.activePanel === 'threads'"
      @close="chatStore.closePanel()"
    />

    <ScheduledMessagesList
      v-if="chatStore.activePanel === 'scheduled'"
      @close="chatStore.closePanel()"
    />

    <Teleport to="body">
      <div
        v-if="chatStore.activePanel === 'saved'"
        class="fixed inset-0 z-[10000] flex items-center justify-center"
      >
        <div class="absolute inset-0 bg-black/40" @click="chatStore.closePanel()"></div>
        <div class="relative mx-4">
          <SavedMessages @close="chatStore.closePanel()" />
        </div>
      </div>
    </Teleport>

    <Teleport to="body">
      <div
        v-if="chatStore.activePanel === 'status'"
        class="fixed inset-0 z-[10000]"
        :class="isMobile ? '' : 'flex items-center justify-center'"
      >
        <div class="absolute inset-0 bg-black/40" @click="chatStore.closePanel()"></div>
        <div :class="isMobile ? 'absolute bottom-0 left-0 right-0' : 'relative mx-4'">
          <StatusPicker
            :mobile="isMobile"
            @close="chatStore.closePanel()"
            @updated="chatStore.closePanel()"
          />
        </div>
      </div>
    </Teleport>
    
    <!-- Mobile Bottom Navigation (hidden when conversation is open) -->
    <MobileBottomNav v-if="isMobile && !chatStore.activeConversationId" :show-todo-button="false" />

    <StepGuide
      v-if="showStepGuide"
      :title-key="chatGuide.titleKey"
      :subtitle-key="chatGuide.subtitleKey"
      :header-icon="chatGuide.headerIcon"
      :header-color="chatGuide.headerColor"
      :storage-key="chatGuide.storageKey"
      :steps="chatGuide.steps"
      @close="showStepGuide = false"
    />
  </div>
</template>

<style scoped>
/* Use dvh (dynamic viewport height) which shrinks when keyboard opens on mobile */
.chat-view {
  height: 100vh; /* fallback */
  height: 100dvh;
}

.btn-ghost {
  @apply flex items-center justify-center rounded-lg text-surface-600 dark:text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors;
}
.btn-icon {
  @apply w-9 h-9;
}

/* Safe area handling for PWA on iOS/Android - matches AppHeader spacing exactly */
.header-safe-area {
  padding-top: calc(0.5rem + env(safe-area-inset-top, 0px));
  padding-bottom: 0.5rem;
  min-height: calc(3.5rem + env(safe-area-inset-top, 0px));
  /* Push content to bottom of header, away from the notch - same as AppHeader's min-h-safe-top */
  align-items: flex-end !important;
}
</style>

