<script setup>
import { ref, computed, onMounted, onUnmounted, watch, nextTick, defineAsyncComponent } from 'vue'
import { useProjectHubStore } from '@/addons/project-hub/stores/projectHub'
import { useBoardsStore } from '@/addons/kanban-boards/stores/boards'
import { useAuthStore } from '@/stores/auth'
import { useColleaguesStore } from '@/addons/team/stores/colleagues'
import { useMailSync, EventTypes } from '@/services/mailSyncSocket'
import UserAvatar from '@/components/shared/UserAvatar.vue'
import api from '@/services/api'

const DriveFilePicker = defineAsyncComponent(() => import('@/components/DriveFilePicker.vue'))

const props = defineProps({
  cardId: { type: [Number, String], required: true },
  boardId: { type: [Number, String], default: null },
})

const hubStore = useProjectHubStore()
const boardsStore = useBoardsStore()
const authStore = useAuthStore()
const colleaguesStore = useColleaguesStore()
const { on } = useMailSync()

const comments = ref([])
const loading = ref(false)
const replyTo = ref(null)
const editingComment = ref(null)
const editContent = ref('')
const showMentions = ref(false)
const mentionQuery = ref('')
const mentionAnchorPos = ref(0)
const unreadCount = ref(0)
const commentReactions = ref({})

// Rich text
const editorRef = ref(null)
const showDrivePicker = ref(false)
const pendingAttachments = ref([])

const sortedComments = computed(() => {
  const topLevel = comments.value.filter(c => !c.parent_comment_id)
  return topLevel.map(c => ({
    ...c,
    replies: comments.value
      .filter(r => r.parent_comment_id && Number(r.parent_comment_id) === Number(c.id))
      .sort((a, b) => new Date(a.created_at) - new Date(b.created_at)),
  }))
})

const wsUnsubs = []

onMounted(async () => {
  await colleaguesStore.init()
  if (props.boardId) {
    const bid = Number(props.boardId)
    if (!Number.isNaN(bid) && bid > 0) {
      try {
        await boardsStore.fetchBoard(bid)
      } catch {
        /* board may already be loaded */
      }
    }
  }
  await fetchComments()
  await markRead()

  wsUnsubs.push(on(EventTypes.CARD_COMMENT_UPDATED, (p) => {
    if (p.card_id == props.cardId) fetchComments()
  }))
  wsUnsubs.push(on(EventTypes.CARD_COMMENT_REACTION, (p) => {
    if (p.card_id == props.cardId) fetchComments()
  }))
})

onUnmounted(() => {
  wsUnsubs.forEach(fn => { if (typeof fn === 'function') fn() })
})

async function fetchComments() {
  loading.value = true
  try {
    const card = await boardsStore.getCard(props.cardId)
    comments.value = card?.comments || []
    unreadCount.value = await hubStore.fetchUnreadCount(props.cardId)
    await loadAllReactions()
  } catch (err) {
    console.error('Failed to fetch comments:', err)
  } finally {
    loading.value = false
  }
}

async function loadAllReactions() {
  const ids = comments.value.map(c => c.id).filter(Boolean)
  if (ids.length === 0) return
  try {
    const { data } = await api.post('/project-hub/comments/reactions/batch', { comment_ids: ids })
    const map = data?.reactions || {}
    for (const c of comments.value) {
      commentReactions.value[c.id] = map[c.id] || []
    }
  } catch { /* non-critical */ }
}

function getReactionCount(commentId, emoji) {
  const reactions = commentReactions.value[commentId] || []
  const r = reactions.find(r => r.emoji === emoji)
  return r ? parseInt(r.count) : 0
}

function hasMyReaction(commentId, emoji) {
  const reactions = commentReactions.value[commentId] || []
  const r = reactions.find(r => r.emoji === emoji)
  if (!r) return false
  const users = (r.users || '').split(',').map(u => u.trim().toLowerCase())
  return users.includes(authStore.userEmail?.toLowerCase())
}

async function markRead() {
  try {
    await hubStore.markCommentsRead(props.cardId)
    unreadCount.value = 0
  } catch { /* non-fatal */ }
}

// Rich text toolbar actions
function execFormat(cmd, value = null) {
  document.execCommand(cmd, false, value)
  editorRef.value?.focus()
}

function getEditorContent() {
  return editorRef.value?.innerHTML?.trim() || ''
}

function clearEditor() {
  if (editorRef.value) editorRef.value.innerHTML = ''
  pendingAttachments.value = []
}

async function postComment() {
  const text = getEditorContent()
  if (!text && pendingAttachments.value.length === 0) return

  try {
    const parentId = replyTo.value ? replyTo.value.id : null
    const commentText = text || buildAttachmentSummary(pendingAttachments.value)
    const structured = buildStructuredMentions(text)
    const comment = await boardsStore.addComment(
      props.cardId,
      commentText,
      parentId,
      structured.length ? structured : null,
    )
    if (comment) {
      let commentsFolderId = null
      if (pendingAttachments.value.length) {
        try {
          const { data: folderRes } = await api.post(`/boards/cards/${props.cardId}/asset-folders`, { name: 'Comments', parent_id: null })
          commentsFolderId = folderRes?.data?.folder?.id || null
        } catch { /* best-effort */ }
      }

      for (const att of pendingAttachments.value) {
        try {
          await api.post(`/project-hub/comments/${comment.id}/attachments`, att)
        } catch { /* best-effort */ }

        if (att.type === 'drive_file' && att.drive_file_id) {
          try {
            await boardsStore.addDriveAttachment(props.cardId, att.drive_file_id, att.name, commentsFolderId)
          } catch { /* best-effort, may already exist */ }
        } else if (att.type === 'url' && att.url) {
          try {
            await boardsStore.addUrlAttachment(props.cardId, att.url, att.name)
          } catch { /* best-effort */ }
        }
      }

      clearEditor()
      replyTo.value = null
      await fetchComments()
    }
  } catch (err) {
    console.error('Failed to post comment:', err)
  }
}

function buildAttachmentSummary(attachments) {
  const names = attachments.map(a => a.name || 'file').join(', ')
  return `<p><span class="material-symbols-rounded" style="font-size:14px;vertical-align:middle">attach_file</span> ${names}</p>`
}

async function startEdit(comment) {
  editingComment.value = comment.id
  editContent.value = comment.content
  await nextTick()
}

async function saveEdit(commentId) {
  if (!editContent.value.trim()) return
  try {
    await api.put(`/boards/comments/${commentId}`, {
      content: editContent.value.trim(),
    })
    const c = comments.value.find(c => c.id === commentId)
    if (c) {
      c.content = editContent.value.trim()
      c.edited_at = new Date().toISOString()
    }
    editingComment.value = null
    editContent.value = ''
  } catch (err) {
    console.error('Failed to edit comment:', err)
  }
}

function cancelEdit() {
  editingComment.value = null
  editContent.value = ''
}

async function deleteComment(commentId) {
  try {
    await boardsStore.deleteComment(commentId)
    comments.value = comments.value.filter(c => c.id !== commentId && c.parent_comment_id !== commentId)
  } catch (err) {
    console.error('Failed to delete comment:', err)
  }
}

async function toggleReaction(commentId, emoji) {
  try {
    const result = await hubStore.toggleReaction(commentId, emoji)
    if (result?.reactions) {
      commentReactions.value[commentId] = result.reactions
    } else {
      try {
        const { data } = await api.get(`/project-hub/comments/${commentId}/reactions`)
        if (data?.reactions) commentReactions.value[commentId] = data.reactions
      } catch { /* fallback */ }
    }
  } catch (err) {
    console.error('Failed to toggle reaction:', err)
  }
}

function startReply(comment) {
  replyTo.value = comment
  nextTick(() => {
    if (editorRef.value) {
      editorRef.value.innerHTML = `<span class="mention">@${comment.user_email}</span>&nbsp;`
      editorRef.value.focus()
    }
  })
}

function cancelReply() {
  replyTo.value = null
  clearEditor()
}

function extractMentions(text) {
  const pattern = /@([\w.+-]+@[\w.-]+)/g
  const found = []
  let match
  while ((match = pattern.exec(text)) !== null) {
    found.push(match[1].toLowerCase())
  }
  return [...new Set(found)]
}

function buildStructuredMentions(html) {
  const emails = extractMentions(html)
  return emails.map((e) => ({
    email: e,
    name: colleaguesStore.colleagueByEmail[e]?.display_name || e.split('@')[0],
  }))
}

const mentionCandidates = computed(() => {
  const q = (mentionQuery.value || '').toLowerCase()
  const bid = props.boardId ? Number(props.boardId) : null
  if (!bid || Number(boardsStore.currentBoard?.id) !== bid) return []
  const list = boardsStore.currentMembers || []
  return list
    .filter((m) => {
      const em = String(m.user_email || m.email || '').toLowerCase()
      const name = String(m.display_name || m.name || '').toLowerCase()
      if (!em) return false
      if (!q) return true
      return em.includes(q) || name.includes(q)
    })
    .slice(0, 12)
})

function insertMention(member) {
  const email = String(member.user_email || member.email || '').toLowerCase()
  if (!email) return
  const ed = editorRef.value
  if (!ed) return
  ed.focus()
  const sel = window.getSelection()
  if (!sel?.rangeCount) {
    showMentions.value = false
    return
  }
  const r = sel.getRangeAt(0)
  const node = r.startContainer
  if (node.nodeType !== Node.TEXT_NODE) {
    showMentions.value = false
    return
  }
  const full = node.textContent || ''
  const upto = full.slice(0, r.startOffset)
  const m = upto.match(/@([\w.+\-%]*)$/)
  if (!m) {
    showMentions.value = false
    return
  }
  const start = r.startOffset - m[0].length
  const nr = document.createRange()
  nr.setStart(node, Math.max(0, start))
  nr.setEnd(node, r.startOffset)
  nr.deleteContents()
  const span = document.createElement('span')
  span.className = 'mention'
  span.textContent = `@${email}`
  nr.insertNode(span)
  const space = document.createTextNode('\u00a0')
  if (span.parentNode) {
    span.parentNode.insertBefore(space, span.nextSibling)
  }
  sel.removeAllRanges()
  const nr2 = document.createRange()
  nr2.setStartAfter(space)
  nr2.collapse(true)
  sel.addRange(nr2)
  showMentions.value = false
}

function handleEditorInput(e) {
  const text = e.target.textContent || ''
  const sel = window.getSelection()
  if (!sel.rangeCount) return
  const pos = sel.getRangeAt(0).startOffset
  const before = text.substring(0, pos)
  const atMatch = before.match(/@(\w*)$/)

  if (atMatch) {
    showMentions.value = true
    mentionQuery.value = atMatch[1]
    mentionAnchorPos.value = pos - atMatch[0].length
  } else {
    showMentions.value = false
  }
}

// Attachments
function handleDriveFileSelected(file) {
  pendingAttachments.value.push({
    type: 'drive_file',
    drive_file_id: file.id,
    name: file.original_name || file.name,
  })
  showDrivePicker.value = false
}

function addUrlAttachment() {
  const url = prompt('Enter URL:')
  if (url && url.trim()) {
    pendingAttachments.value.push({ type: 'url', url: url.trim(), name: url.trim() })
  }
}

function removePendingAttachment(index) {
  pendingAttachments.value.splice(index, 1)
}

const reactionEmojis = ['thumb_up', 'favorite', 'check_circle', 'lightbulb', 'celebration']
const reactionLabels = { thumb_up: '+1', favorite: 'Love', check_circle: 'Done', lightbulb: 'Idea', celebration: 'Party' }

function formatDate(dateStr) {
  if (!dateStr) return ''
  const d = new Date(dateStr)
  const now = new Date()
  const diff = now - d
  if (diff < 60000) return 'just now'
  if (diff < 3600000) return `${Math.floor(diff / 60000)}m ago`
  if (diff < 86400000) return `${Math.floor(diff / 3600000)}h ago`
  return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
}

/** Structured mentions from API (JSON array of {email, name}). */
function parseCommentMentions(comment) {
  const m = comment?.mentions
  if (!m) return []
  if (Array.isArray(m)) return m.filter(x => x && x.email)
  if (typeof m === 'string') {
    try {
      const j = JSON.parse(m)
      return Array.isArray(j) ? j.filter(x => x && x.email) : []
    } catch {
      return []
    }
  }
  return []
}
</script>

<template>
  <div>
    <h4 class="text-xs font-semibold text-surface-500 uppercase tracking-wide mb-2 flex items-center gap-2">
      <span class="material-symbols-rounded text-lg">chat_bubble</span>
      Comments
      <span
        v-if="unreadCount > 0"
        class="ml-1 px-2 py-0.5 rounded-full bg-primary-500 text-white text-[10px] font-bold"
      >
        {{ unreadCount }}
      </span>
    </h4>

    <!-- Reply indicator -->
    <div
      v-if="replyTo"
      class="mb-1.5 px-2.5 py-1.5 bg-primary-50 dark:bg-primary-900/20 rounded-lg flex items-center justify-between text-xs"
    >
      <span class="text-surface-600 dark:text-surface-300">
        Replying to <strong>{{ replyTo.user_email }}</strong>
      </span>
      <button
        class="text-surface-400 hover:text-surface-600 dark:hover:text-surface-200"
        @click="cancelReply"
      >
        <span class="material-symbols-rounded text-[14px]">close</span>
      </button>
    </div>

    <!-- Compact comment composer -->
    <div class="flex items-start gap-2 mb-3">
      <UserAvatar :email="authStore.userEmail" size="sm" class="mt-1 shrink-0" :show-presence="true" />
      <div class="flex-1 min-w-0">
        <div class="border border-surface-200 dark:border-surface-600 rounded-lg bg-surface-50 dark:bg-surface-700 focus-within:border-primary-500 transition-colors">
          <!-- Formatting toolbar inline -->
          <div class="flex items-center gap-px px-1.5 py-1 border-b border-surface-200/60 dark:border-surface-600/60">
            <button @click="execFormat('bold')" class="p-0.5 rounded hover:bg-surface-200/60 dark:hover:bg-surface-600 transition-colors" title="Bold">
              <span class="material-symbols-rounded text-[15px] text-surface-400">format_bold</span>
            </button>
            <button @click="execFormat('italic')" class="p-0.5 rounded hover:bg-surface-200/60 dark:hover:bg-surface-600 transition-colors" title="Italic">
              <span class="material-symbols-rounded text-[15px] text-surface-400">format_italic</span>
            </button>
            <button @click="execFormat('underline')" class="p-0.5 rounded hover:bg-surface-200/60 dark:hover:bg-surface-600 transition-colors" title="Underline">
              <span class="material-symbols-rounded text-[15px] text-surface-400">format_underlined</span>
            </button>
            <button @click="execFormat('strikeThrough')" class="p-0.5 rounded hover:bg-surface-200/60 dark:hover:bg-surface-600 transition-colors" title="Strikethrough">
              <span class="material-symbols-rounded text-[15px] text-surface-400">strikethrough_s</span>
            </button>
            <div class="w-px h-3.5 bg-surface-300/50 dark:bg-surface-600 mx-0.5"></div>
            <button @click="execFormat('insertUnorderedList')" class="p-0.5 rounded hover:bg-surface-200/60 dark:hover:bg-surface-600 transition-colors" title="Bullet list">
              <span class="material-symbols-rounded text-[15px] text-surface-400">format_list_bulleted</span>
            </button>
            <button @click="execFormat('insertOrderedList')" class="p-0.5 rounded hover:bg-surface-200/60 dark:hover:bg-surface-600 transition-colors" title="Numbered list">
              <span class="material-symbols-rounded text-[15px] text-surface-400">format_list_numbered</span>
            </button>
            <div class="w-px h-3.5 bg-surface-300/50 dark:bg-surface-600 mx-0.5"></div>
            <button @click="showDrivePicker = true" class="p-0.5 rounded hover:bg-surface-200/60 dark:hover:bg-surface-600 transition-colors" title="Attach Drive file">
              <span class="material-symbols-rounded text-[15px] text-surface-400">attach_file</span>
            </button>
            <button @click="addUrlAttachment" class="p-0.5 rounded hover:bg-surface-200/60 dark:hover:bg-surface-600 transition-colors" title="Add link">
              <span class="material-symbols-rounded text-[15px] text-surface-400">link</span>
            </button>
          </div>

          <!-- Contenteditable editor -->
          <div class="relative">
            <div
              ref="editorRef"
              contenteditable="true"
              class="w-full min-h-[36px] max-h-32 overflow-y-auto px-2.5 py-1.5 text-sm text-surface-900 dark:text-surface-100 outline-none bg-transparent"
              :data-placeholder="replyTo ? 'Write a reply...' : 'Write a comment... Use @email to mention'"
              @input="handleEditorInput"
              @keydown.meta.enter="postComment"
              @keydown.ctrl.enter="postComment"
            ></div>

            <div
              v-if="showMentions && mentionCandidates.length"
              class="absolute left-0 right-0 top-full mt-0.5 z-30 max-h-40 overflow-y-auto rounded-lg border border-surface-200 dark:border-surface-600 bg-white dark:bg-surface-800 shadow-lg py-0.5"
            >
              <button
                v-for="m in mentionCandidates"
                :key="(m.user_email || m.email || '') + (m.display_name || m.name || '')"
                type="button"
                class="w-full text-left px-2.5 py-1.5 text-xs hover:bg-surface-100 dark:hover:bg-surface-700 flex flex-col gap-0.5"
                @mousedown.prevent="insertMention(m)"
              >
                <span class="font-medium text-surface-800 dark:text-surface-100">{{ m.display_name || m.name || (m.user_email || m.email) }}</span>
                <span class="text-[10px] text-surface-500">{{ m.user_email || m.email }}</span>
              </button>
            </div>
          </div>

          <!-- Pending attachments preview -->
          <div v-if="pendingAttachments.length > 0" class="flex flex-wrap gap-1.5 px-2.5 pb-1.5">
            <div
              v-for="(att, idx) in pendingAttachments"
              :key="idx"
              class="flex items-center gap-1 px-1.5 py-0.5 bg-surface-200/60 dark:bg-surface-600 rounded text-[11px] text-surface-600 dark:text-surface-400"
            >
              <span class="material-symbols-rounded text-[12px]">
                {{ att.type === 'url' ? 'link' : 'description' }}
              </span>
              <span class="truncate max-w-[100px]">{{ att.name }}</span>
              <button @click="removePendingAttachment(idx)" class="hover:text-red-500">
                <span class="material-symbols-rounded text-[11px]">close</span>
              </button>
            </div>
          </div>
        </div>

        <div class="flex items-center gap-2 mt-1.5">
          <button
            @click="postComment"
            class="px-3 py-1 bg-primary-500 hover:bg-primary-600 text-white rounded-full text-xs font-medium transition-colors"
          >
            {{ replyTo ? 'Reply' : 'Comment' }}
          </button>
          <span class="text-[10px] text-surface-400">Cmd+Enter to send</span>
        </div>
      </div>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="flex justify-center py-3">
      <span class="material-symbols-rounded animate-spin text-primary-500">progress_activity</span>
    </div>

    <!-- Comments list (threaded, YouTube-style) -->
    <div v-else class="space-y-0">
      <div
        v-for="comment in sortedComments"
        :key="comment.id"
        class="comment-thread flex gap-2"
      >
        <!-- Avatar column: parent avatar + vertical stem to replies -->
        <div class="flex flex-col items-center shrink-0 w-8 pt-2">
          <UserAvatar :email="comment.user_email || ''" size="sm" class="mt-0.5 shrink-0" :show-presence="true" />
          <div v-if="comment.replies?.length" class="thread-stem flex-1 w-0.5 mt-1 rounded-full bg-surface-300 dark:bg-surface-600"></div>
        </div>

        <!-- Content column: main comment + replies -->
        <div class="flex-1 min-w-0">
          <!-- Main comment -->
          <div class="py-2 group hover:bg-surface-50/50 dark:hover:bg-surface-700/30 -mx-1 px-1 rounded-lg transition-colors">
            <div class="flex items-center gap-1.5">
              <span class="text-[13px] font-semibold text-surface-900 dark:text-surface-100">
                {{ comment.user_email }}
              </span>
              <span class="text-[11px] text-surface-400">{{ formatDate(comment.created_at) }}</span>
              <span v-if="comment.edited_at" class="text-[11px] text-surface-400 italic">(edited)</span>
              <div class="ml-auto flex items-center gap-0.5 opacity-0 group-hover:opacity-100 transition-opacity">
                <button @click="startReply(comment)" class="p-0.5 hover:bg-surface-200 dark:hover:bg-surface-600 rounded" title="Reply">
                  <span class="material-symbols-rounded text-[14px] text-surface-400">reply</span>
                </button>
                <button v-if="comment.user_email === authStore.userEmail" @click="startEdit(comment)" class="p-0.5 hover:bg-surface-200 dark:hover:bg-surface-600 rounded" title="Edit">
                  <span class="material-symbols-rounded text-[14px] text-surface-400">edit</span>
                </button>
                <button @click="deleteComment(comment.id)" class="p-0.5 hover:bg-red-100 dark:hover:bg-red-900/20 rounded" title="Delete">
                  <span class="material-symbols-rounded text-[14px] text-red-400">delete</span>
                </button>
              </div>
            </div>

            <!-- Editing state -->
            <template v-if="editingComment === comment.id">
              <div
                contenteditable="true"
                class="w-full min-h-[32px] px-2.5 py-1.5 mt-1 bg-surface-50 dark:bg-surface-700 border border-primary-400 rounded-lg text-sm text-surface-900 dark:text-surface-100 outline-none"
                @keydown.meta.enter="saveEdit(comment.id)"
                @keydown.ctrl.enter="saveEdit(comment.id)"
                @keydown.escape="cancelEdit"
                v-html="editContent"
                @blur="editContent = $event.target.innerHTML"
              ></div>
              <div class="flex items-center gap-2 mt-1">
                <button @click="saveEdit(comment.id)" class="px-2.5 py-0.5 bg-primary-500 text-white rounded-full text-[11px] font-medium">Save</button>
                <button @click="cancelEdit" class="px-2 py-0.5 text-surface-500 text-[11px]">Cancel</button>
              </div>
            </template>

            <!-- Rendered content -->
            <div
              v-else
              class="text-[13px] text-surface-700 dark:text-surface-300 comment-content prose prose-sm max-w-none leading-relaxed"
              v-html="comment.content"
            ></div>
            <div v-if="parseCommentMentions(comment).length" class="flex flex-wrap gap-1 mt-1.5">
              <a
                v-for="(men, mi) in parseCommentMentions(comment)"
                :key="mi + '-' + men.email"
                :href="`mailto:${men.email}`"
                class="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded-full bg-primary-100/90 dark:bg-primary-900/35 text-[10px] font-medium text-primary-700 dark:text-primary-300 hover:opacity-90"
              >@{{ men.name || men.email.split('@')[0] }}</a>
            </div>

            <!-- Comment attachments -->
            <div v-if="comment.attachments?.length" class="flex flex-wrap gap-1.5 mt-1">
              <a
                v-for="att in comment.attachments"
                :key="att.id"
                :href="att.url || '#'"
                target="_blank"
                class="flex items-center gap-1 px-1.5 py-0.5 bg-surface-100 dark:bg-surface-700 rounded text-[11px] text-surface-500 hover:bg-surface-200 dark:hover:bg-surface-600 transition-colors"
              >
                <span class="material-symbols-rounded text-[12px]">
                  {{ att.type === 'url' ? 'link' : 'description' }}
                </span>
                <span class="truncate max-w-[100px]">{{ att.name || att.url }}</span>
              </a>
            </div>

            <!-- Reactions row -->
            <div class="flex items-center gap-0.5 mt-1 flex-wrap">
              <button
                v-for="emoji in reactionEmojis"
                :key="emoji"
                class="flex items-center gap-0.5 px-1 py-px rounded-full text-[9px] border transition-colors"
                :class="hasMyReaction(comment.id, emoji)
                  ? 'border-primary-300 bg-primary-50 dark:bg-primary-900/30 dark:border-primary-700 text-primary-600 dark:text-primary-400'
                  : 'border-transparent hover:border-surface-300 dark:hover:border-surface-600 text-surface-400'"
                @click="toggleReaction(comment.id, emoji)"
                :title="reactionLabels[emoji]"
              >
                <span class="material-symbols-rounded text-[11px]">{{ emoji }}</span>
                <span v-if="getReactionCount(comment.id, emoji) > 0" class="text-[9px] font-medium">{{ getReactionCount(comment.id, emoji) }}</span>
              </button>
            </div>
          </div>

          <!-- Replies (YouTube-style curved connector from parent stem) -->
          <div v-if="comment.replies?.length" class="replies-container">
            <div
              v-for="(reply, rIdx) in comment.replies"
              :key="reply.id"
              class="reply-item relative flex gap-2 py-1.5 group/reply"
            >
              <div class="reply-elbow"></div>
              <UserAvatar :email="reply.user_email || ''" size="xs" class="mt-0.5 shrink-0 relative z-[1]" :show-presence="true" />
              <div class="flex-1 min-w-0">
                <div class="flex items-center gap-1.5">
                  <span class="text-[12px] font-semibold text-surface-800 dark:text-surface-200">{{ reply.user_email }}</span>
                  <span class="text-[10px] text-surface-400">{{ formatDate(reply.created_at) }}</span>
                  <span v-if="reply.edited_at" class="text-[10px] text-surface-400 italic">(edited)</span>
                  <button
                    @click="deleteComment(reply.id)"
                    class="ml-auto p-0.5 opacity-0 group-hover/reply:opacity-100 hover:text-red-500 transition-all"
                  >
                    <span class="material-symbols-rounded text-[12px]">delete</span>
                  </button>
                </div>
                <div
                  class="text-[12px] text-surface-600 dark:text-surface-400 comment-content prose prose-sm max-w-none leading-relaxed"
                  v-html="reply.content"
                ></div>
                <div v-if="parseCommentMentions(reply).length" class="flex flex-wrap gap-1 mt-1">
                  <a
                    v-for="(men, mi) in parseCommentMentions(reply)"
                    :key="'r' + reply.id + '-' + mi + '-' + men.email"
                    :href="'mailto:' + men.email"
                    class="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded-full bg-primary-100/90 dark:bg-primary-900/35 text-[9px] font-medium text-primary-700 dark:text-primary-300"
                  >@{{ men.name || men.email.split('@')[0] }}</a>
                </div>
                <div v-if="reply.attachments?.length" class="flex flex-wrap gap-1.5 mt-1">
                  <a
                    v-for="att in reply.attachments"
                    :key="att.id"
                    :href="att.url || '#'"
                    target="_blank"
                    class="flex items-center gap-1 px-1.5 py-0.5 bg-surface-100 dark:bg-surface-700 rounded text-[11px] text-surface-500 hover:bg-surface-200 dark:hover:bg-surface-600 transition-colors"
                  >
                    <span class="material-symbols-rounded text-[12px]">
                      {{ att.type === 'url' ? 'link' : 'description' }}
                    </span>
                    <span class="truncate max-w-[100px]">{{ att.name || att.url }}</span>
                  </a>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Drive File Picker -->
    <DriveFilePicker
      :show="showDrivePicker"
      title="Attach file to comment"
      @select="handleDriveFileSelected"
      @cancel="showDrivePicker = false"
    />
  </div>
</template>

<style scoped>
[contenteditable]:empty::before {
  content: attr(data-placeholder);
  color: rgb(var(--color-surface-400, 156 163 175));
  pointer-events: none;
}

.comment-content :deep(.mention) {
  color: rgb(var(--color-primary-600));
  font-weight: 500;
}

/*
 * YouTube-style threaded reply connectors.
 *
 * Layout: comment-thread is a 2-column flex (avatar col | content col).
 * Avatar col (w-8 = 32px) holds the parent avatar + a flex-1 stem.
 * The stem automatically spans from the avatar to the bottom of all replies.
 * Each reply has a curved elbow that reaches back from the content column
 * into the avatar column to connect with the stem.
 *
 * Stem center X = 16px (half of 32px col, via items-center).
 * Gap = 8px (gap-2). Content col starts at 40px.
 * Elbow left: -24px from reply-item => 40 - 24 = 16px = stem center.
 */

.replies-container {
  position: relative;
}

.reply-elbow {
  position: absolute;
  left: -24px;
  top: 0;
  width: 34px;
  height: calc(50% + 2px);
  border-left: 2px solid rgb(var(--color-surface-300, 209 213 219));
  border-bottom: 2px solid rgb(var(--color-surface-300, 209 213 219));
  border-bottom-left-radius: 12px;
  pointer-events: none;
}

:root.dark .reply-elbow,
.dark .reply-elbow {
  border-color: rgb(var(--color-surface-600, 75 85 99));
}
</style>
