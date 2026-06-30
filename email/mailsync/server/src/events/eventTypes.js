/**
 * Mail Sync Event Types
 * 
 * All WebSocket events must include:
 * - eventId: UUID v4, unique per event
 * - timestamp: Server timestamp when event was generated
 * - version: Incrementing counter per user for ordering
 * 
 * Events are idempotent - processing the same eventId twice has no effect.
 */

// Server -> Client event types
export const EventTypes = {
  // New message arrived (from IMAP IDLE or sent mail)
  MESSAGE_NEW: 'MESSAGE_NEW',
  
  // Message was deleted (permanent delete or expunge)
  MESSAGE_DELETED: 'MESSAGE_DELETED',
  
  // Message was moved between folders
  MESSAGE_MOVED: 'MESSAGE_MOVED',
  
  // Message flags changed (read, starred, etc.)
  FLAGS_CHANGED: 'FLAGS_CHANGED',
  
  // Folder counts updated
  FOLDER_COUNTS: 'FOLDER_COUNTS',
  
  // Conversation updated (members changed, split/merged)
  CONVERSATION_UPDATED: 'CONVERSATION_UPDATED',
  
  // Folder created/renamed/deleted
  FOLDER_CHANGED: 'FOLDER_CHANGED',
  
  // Connection status events
  CONNECTED: 'CONNECTED',
  RECONNECTED: 'RECONNECTED',
  
  // Error events
  ERROR: 'ERROR',
  
  // Sync status
  SYNC_STATUS: 'SYNC_STATUS',
  
  // Settings changed (theme, accent color, density, layout)
  SETTINGS_CHANGED: 'SETTINGS_CHANGED',
  
  // ============================================
  // CALENDAR EVENTS
  // ============================================
  
  // Calendar event created
  CALENDAR_EVENT_CREATED: 'CALENDAR_EVENT_CREATED',
  
  // Calendar event updated
  CALENDAR_EVENT_UPDATED: 'CALENDAR_EVENT_UPDATED',
  
  // Calendar event deleted
  CALENDAR_EVENT_DELETED: 'CALENDAR_EVENT_DELETED',
  
  // Calendar created/updated/deleted
  CALENDAR_CHANGED: 'CALENDAR_CHANGED',
  
  // Event participant response changed
  CALENDAR_RSVP_CHANGED: 'CALENDAR_RSVP_CHANGED',
  
  // ============================================
  // BOARDS (KANBAN) EVENTS
  // ============================================
  
  // Board created/updated/archived
  BOARD_CREATED: 'BOARD_CREATED',
  BOARD_UPDATED: 'BOARD_UPDATED',
  BOARD_DELETED: 'BOARD_DELETED',
  
  // List created/updated/archived/moved
  LIST_CREATED: 'LIST_CREATED',
  LIST_UPDATED: 'LIST_UPDATED',
  LIST_DELETED: 'LIST_DELETED',
  LIST_MOVED: 'LIST_MOVED',
  
  // Card events
  CARD_CREATED: 'CARD_CREATED',
  CARD_UPDATED: 'CARD_UPDATED',
  CARD_DELETED: 'CARD_DELETED',
  CARD_MOVED: 'CARD_MOVED',
  CARD_ARCHIVED: 'CARD_ARCHIVED',
  
  // Card checklist/comment events
  CARD_CHECKLIST_UPDATED: 'CARD_CHECKLIST_UPDATED',
  CHECKLIST_UPDATED: 'CHECKLIST_UPDATED',  // PHP backend sends this
  CARD_COMMENT_ADDED: 'CARD_COMMENT_ADDED',
  CARD_COMMENT_DELETED: 'CARD_COMMENT_DELETED',
  
  // Card assignment/label events
  CARD_ASSIGNED: 'CARD_ASSIGNED',
  CARD_UNASSIGNED: 'CARD_UNASSIGNED',
  CARD_LABEL_ADDED: 'CARD_LABEL_ADDED',
  CARD_LABEL_REMOVED: 'CARD_LABEL_REMOVED',
  
  // ============================================
  // CLIENT EVENTS
  // ============================================
  
  // Client created/updated/deleted
  CLIENT_CREATED: 'CLIENT_CREATED',
  CLIENT_UPDATED: 'CLIENT_UPDATED',
  CLIENT_DELETED: 'CLIENT_DELETED',
  
  // Client contact events
  CLIENT_CONTACT_ADDED: 'CLIENT_CONTACT_ADDED',
  CLIENT_CONTACT_UPDATED: 'CLIENT_CONTACT_UPDATED',
  CLIENT_CONTACT_REMOVED: 'CLIENT_CONTACT_REMOVED',
  
  // Time tracking events
  TIME_ENTRY_CREATED: 'TIME_ENTRY_CREATED',
  TIME_ENTRY_UPDATED: 'TIME_ENTRY_UPDATED',
  TIME_ENTRY_DELETED: 'TIME_ENTRY_DELETED',
  TIME_TRACKING_STARTED: 'TIME_TRACKING_STARTED',
  TIME_TRACKING_STOPPED: 'TIME_TRACKING_STOPPED',
  
  // ============================================
  // TODO EVENTS
  // ============================================
  
  TODO_CREATED: 'TODO_CREATED',
  TODO_UPDATED: 'TODO_UPDATED',
  TODO_DELETED: 'TODO_DELETED',
  TODO_COMPLETED: 'TODO_COMPLETED',
  
  // ============================================
  // DRIVE EVENTS (metadata only, files via FlowOneDrive)
  // ============================================
  
  DRIVE_FILE_CREATED: 'DRIVE_FILE_CREATED',
  DRIVE_FILE_UPDATED: 'DRIVE_FILE_UPDATED',
  DRIVE_FILE_DELETED: 'DRIVE_FILE_DELETED',
  DRIVE_FILE_SHARED: 'DRIVE_FILE_SHARED',
  DRIVE_FOLDER_CREATED: 'DRIVE_FOLDER_CREATED',
  DRIVE_FOLDER_UPDATED: 'DRIVE_FOLDER_UPDATED',
  DRIVE_FOLDER_DELETED: 'DRIVE_FOLDER_DELETED',
  
  // ============================================
  // COLLEAGUE EVENTS
  // ============================================
  
  // Colleague profile updated (name, avatar, status, etc.)
  COLLEAGUE_UPDATED: 'COLLEAGUE_UPDATED',
  
  // Colleague group created/updated/deleted/members changed
  COLLEAGUE_GROUP_UPDATED: 'COLLEAGUE_GROUP_UPDATED',
  
  // ============================================
  // CHAT EVENTS (Direct Messaging)
  // ============================================
  
  // New chat message received
  CHAT_MESSAGE_NEW: 'CHAT_MESSAGE_NEW',
  
  // Message was edited
  CHAT_MESSAGE_EDITED: 'CHAT_MESSAGE_EDITED',
  
  // Message was deleted
  CHAT_MESSAGE_DELETED: 'CHAT_MESSAGE_DELETED',
  
  // Message was pinned/unpinned
  CHAT_MESSAGE_PINNED: 'CHAT_MESSAGE_PINNED',
  
  // Reaction added to message
  CHAT_REACTION_ADDED: 'CHAT_REACTION_ADDED',
  
  // Reaction removed from message
  CHAT_REACTION_REMOVED: 'CHAT_REACTION_REMOVED',
  
  // User started typing
  CHAT_TYPING_START: 'CHAT_TYPING_START',
  
  // User stopped typing
  CHAT_TYPING_STOP: 'CHAT_TYPING_STOP',
  
  // Message was read
  CHAT_READ_RECEIPT: 'CHAT_READ_RECEIPT',
  
  // New conversation created
  CHAT_CONVERSATION_CREATED: 'CHAT_CONVERSATION_CREATED',
  
  // Conversation settings updated (background, etc.)
  CHAT_SETTINGS_UPDATED: 'CHAT_SETTINGS_UPDATED',
  
  // View Together - collaborative viewing session started
  CHAT_VIEW_SESSION_START: 'CHAT_VIEW_SESSION_START',
  
  // View Together - session ended
  CHAT_VIEW_SESSION_END: 'CHAT_VIEW_SESSION_END',
  
  // View Together - position sync
  CHAT_VIEW_SYNC: 'CHAT_VIEW_SYNC',
  
  // ============================================
  // CALL EVENTS (Voice/Video/Screen Share)
  // ============================================
  
  // Call initiated (caller -> callee(s))
  CALL_INITIATE: 'CALL_INITIATE',
  
  // Call ringing on callee device
  CALL_RINGING: 'CALL_RINGING',
  
  // Call answered
  CALL_ANSWER: 'CALL_ANSWER',
  
  // Call rejected by callee
  CALL_REJECT: 'CALL_REJECT',
  
  // Call ended / hung up
  CALL_HANGUP: 'CALL_HANGUP',
  
  // ICE candidate exchange
  CALL_ICE_CANDIDATE: 'CALL_ICE_CANDIDATE',
  
  // Media state changed (mic muted, camera off, etc.)
  CALL_MEDIA_STATE: 'CALL_MEDIA_STATE',
  
  // Participant joined the call (group calls)
  CALL_PARTICIPANT_JOINED: 'CALL_PARTICIPANT_JOINED',
  
  // Participant left the call
  CALL_PARTICIPANT_LEFT: 'CALL_PARTICIPANT_LEFT',
  
  // Screen share started
  CALL_SCREEN_SHARE_START: 'CALL_SCREEN_SHARE_START',
  
  // Screen share stopped
  CALL_SCREEN_SHARE_STOP: 'CALL_SCREEN_SHARE_STOP',
  
  // Call missed (no answer timeout)
  CALL_MISSED: 'CALL_MISSED',
  
  // Call dismissed on this device (answered/rejected/hung up on another device)
  CALL_DISMISSED: 'CALL_DISMISSED',
  
  // SDP renegotiation (mid-call track changes like screen share on voice calls)
  CALL_SDP_OFFER: 'CALL_SDP_OFFER',
  CALL_SDP_ANSWER: 'CALL_SDP_ANSWER',
  
  // Active call status response (sent in reply to CALL_ACTIVE_QUERY)
  CALL_ACTIVE_STATUS: 'CALL_ACTIVE_STATUS',
  
  // ============================================
  // HUDDLE EVENTS (Persistent audio rooms)
  // ============================================
  
  // A participant joined the huddle (broadcast to others)
  HUDDLE_PARTICIPANT_JOINED: 'HUDDLE_PARTICIPANT_JOINED',
  
  // A participant left the huddle
  HUDDLE_PARTICIPANT_LEFT: 'HUDDLE_PARTICIPANT_LEFT',
  
  // Huddle ended (last person left)
  HUDDLE_ENDED: 'HUDDLE_ENDED',
  
  // SDP offer/answer for huddle WebRTC audio
  HUDDLE_SDP_OFFER: 'HUDDLE_SDP_OFFER',
  HUDDLE_SDP_ANSWER: 'HUDDLE_SDP_ANSWER',
  
  // ICE candidate for huddle WebRTC
  HUDDLE_ICE_CANDIDATE: 'HUDDLE_ICE_CANDIDATE',
  
  // Media state in huddle (mute/deafen)
  HUDDLE_MEDIA_STATE: 'HUDDLE_MEDIA_STATE',

  // Speaking state in huddle (voice activity detection)
  HUDDLE_SPEAKING: 'HUDDLE_SPEAKING',

  // ============================================
  // MOOD BOARD EVENTS (Real-time collaboration)
  // ============================================
  
  // Item created/updated/deleted on mood board
  MOOD_BOARD_ITEM_CREATED: 'MOOD_BOARD_ITEM_CREATED',
  MOOD_BOARD_ITEM_UPDATED: 'MOOD_BOARD_ITEM_UPDATED',
  MOOD_BOARD_ITEM_DELETED: 'MOOD_BOARD_ITEM_DELETED',
  MOOD_BOARD_ITEMS_MOVED: 'MOOD_BOARD_ITEMS_MOVED',
  
  // Connection created/deleted
  MOOD_BOARD_CONNECTION_CREATED: 'MOOD_BOARD_CONNECTION_CREATED',
  MOOD_BOARD_CONNECTION_DELETED: 'MOOD_BOARD_CONNECTION_DELETED',
  
  // Activity feed (collaborative history)
  MOOD_BOARD_ACTIVITY: 'MOOD_BOARD_ACTIVITY',
  
  // Cursor position from collaborator
  MOOD_BOARD_CURSOR: 'MOOD_BOARD_CURSOR',
  
  // User joined/left mood board view
  MOOD_BOARD_PRESENCE_JOIN: 'MOOD_BOARD_PRESENCE_JOIN',
  MOOD_BOARD_PRESENCE_LEAVE: 'MOOD_BOARD_PRESENCE_LEAVE',

  // Comment events (relayed from client broadcasts)
  MOOD_BOARD_COMMENT_ADDED: 'MOOD_BOARD_COMMENT_ADDED',
  MOOD_BOARD_COMMENT_DELETED: 'MOOD_BOARD_COMMENT_DELETED',
  MOOD_BOARD_THREAD_DELETED: 'MOOD_BOARD_THREAD_DELETED',
  MOOD_BOARD_THREAD_RESOLVED: 'MOOD_BOARD_THREAD_RESOLVED',
  
  // ============================================
  // PROJECT HUB EVENTS
  // ============================================

  // Comment enhanced events
  CARD_COMMENT_UPDATED: 'CARD_COMMENT_UPDATED',
  CARD_COMMENT_REACTION: 'CARD_COMMENT_REACTION',

  // Multi-assignee events
  CARD_ASSIGNEE_ADDED: 'CARD_ASSIGNEE_ADDED',
  CARD_ASSIGNEE_UPDATED: 'CARD_ASSIGNEE_UPDATED',
  CARD_ASSIGNEE_REMOVED: 'CARD_ASSIGNEE_REMOVED',

  // Subtask events
  CARD_SUBTASK_CREATED: 'CARD_SUBTASK_CREATED',
  CARD_SUBTASK_UPDATED: 'CARD_SUBTASK_UPDATED',

  // Work session events
  CARD_WORK_SESSION: 'CARD_WORK_SESSION',

  // Hierarchy events
  SPACE_UPDATED: 'SPACE_UPDATED',
  FOLDER_UPDATED: 'FOLDER_UPDATED',

  // Dependency events
  CARD_DEPENDENCY_ADDED: 'CARD_DEPENDENCY_ADDED',
  CARD_DEPENDENCY_REMOVED: 'CARD_DEPENDENCY_REMOVED',

  // ============================================
  // PRESENCE EVENTS (Online/Away/Offline status)
  // ============================================
  
  // User came online
  PRESENCE_ONLINE: 'PRESENCE_ONLINE',
  
  // User went offline
  PRESENCE_OFFLINE: 'PRESENCE_OFFLINE',
  
  // User status changed (active, away, do_not_disturb)
  PRESENCE_STATUS_CHANGED: 'PRESENCE_STATUS_CHANGED',
  
  // Bulk presence update (initial load of who's online)
  PRESENCE_BULK_UPDATE: 'PRESENCE_BULK_UPDATE',
  
  // ============================================
  // NOTIFICATION EVENTS
  // ============================================
  
  // A new notification was created server-side (missed call, etc.)
  // Delivered via Redis pub/sub from the PHP backend
  NOTIFICATION_CREATED: 'NOTIFICATION_CREATED',
}

// Client -> Server message types
export const ClientMessageTypes = {
  // Subscribe to folder IDLE
  SUBSCRIBE_FOLDER: 'SUBSCRIBE_FOLDER',
  
  // Unsubscribe from folder
  UNSUBSCRIBE_FOLDER: 'UNSUBSCRIBE_FOLDER',
  
  // Request missed events since version
  REPLAY_EVENTS: 'REPLAY_EVENTS',
  
  // Acknowledge received events (optional, for reliable delivery)
  ACK_EVENT: 'ACK_EVENT',
  
  // Heartbeat/ping
  PING: 'PING',
  
  // ============================================
  // SUBSCRIPTION TYPES FOR DESKTOP APP
  // ============================================
  
  // Subscribe to all events for a user (desktop app mode)
  SUBSCRIBE_ALL: 'SUBSCRIBE_ALL',
  
  // Subscribe to specific entity types
  SUBSCRIBE_CALENDARS: 'SUBSCRIBE_CALENDARS',
  SUBSCRIBE_BOARDS: 'SUBSCRIBE_BOARDS',
  SUBSCRIBE_CLIENTS: 'SUBSCRIBE_CLIENTS',
  SUBSCRIBE_DRIVE: 'SUBSCRIBE_DRIVE',
  SUBSCRIBE_CHAT: 'SUBSCRIBE_CHAT',
  
  // Unsubscribe from entity types
  UNSUBSCRIBE_CALENDARS: 'UNSUBSCRIBE_CALENDARS',
  UNSUBSCRIBE_BOARDS: 'UNSUBSCRIBE_BOARDS',
  UNSUBSCRIBE_CLIENTS: 'UNSUBSCRIBE_CLIENTS',
  UNSUBSCRIBE_DRIVE: 'UNSUBSCRIBE_DRIVE',
  UNSUBSCRIBE_CHAT: 'UNSUBSCRIBE_CHAT',
  
  // ============================================
  // CALL MESSAGES
  // ============================================
  
  // Initiate a call to a conversation
  CALL_INITIATE: 'CALL_INITIATE',
  
  // Answer an incoming call
  CALL_ANSWER: 'CALL_ANSWER',
  
  // Reject an incoming call
  CALL_REJECT: 'CALL_REJECT',
  
  // Hang up / leave call
  CALL_HANGUP: 'CALL_HANGUP',
  
  // Send ICE candidate
  CALL_ICE_CANDIDATE: 'CALL_ICE_CANDIDATE',
  
  // Update media state (mute, camera, etc.)
  CALL_MEDIA_STATE: 'CALL_MEDIA_STATE',
  
  // Start/stop screen sharing
  CALL_SCREEN_SHARE_START: 'CALL_SCREEN_SHARE_START',
  CALL_SCREEN_SHARE_STOP: 'CALL_SCREEN_SHARE_STOP',
  
  // SDP renegotiation (mid-call track changes)
  CALL_SDP_OFFER: 'CALL_SDP_OFFER',
  CALL_SDP_ANSWER: 'CALL_SDP_ANSWER',
  
  // Query active call for a conversation
  CALL_ACTIVE_QUERY: 'CALL_ACTIVE_QUERY',
  
  // ============================================
  // HUDDLE MESSAGES (Persistent audio rooms)
  // ============================================
  
  // Signal joining a huddle (triggers WebRTC setup with existing participants)
  HUDDLE_JOIN: 'HUDDLE_JOIN',
  
  // Signal leaving a huddle
  HUDDLE_LEAVE: 'HUDDLE_LEAVE',
  
  // SDP offer/answer for huddle audio
  HUDDLE_SDP_OFFER: 'HUDDLE_SDP_OFFER',
  HUDDLE_SDP_ANSWER: 'HUDDLE_SDP_ANSWER',
  
  // ICE candidate for huddle
  HUDDLE_ICE_CANDIDATE: 'HUDDLE_ICE_CANDIDATE',
  
  // Media state update (mute/deafen)
  HUDDLE_MEDIA_STATE: 'HUDDLE_MEDIA_STATE',

  // Speaking state update (voice activity detection)
  HUDDLE_SPEAKING: 'HUDDLE_SPEAKING',

  // ============================================
  // MOOD BOARD MESSAGES
  // ============================================
  
  // Subscribe/unsubscribe to mood board collaboration
  SUBSCRIBE_MOOD_BOARD: 'SUBSCRIBE_MOOD_BOARD',
  UNSUBSCRIBE_MOOD_BOARD: 'UNSUBSCRIBE_MOOD_BOARD',
  
  // Send cursor position to other collaborators
  MOOD_BOARD_CURSOR_MOVE: 'MOOD_BOARD_CURSOR_MOVE',

  // Broadcast comment events to other collaborators
  MOOD_BOARD_COMMENT_BROADCAST: 'MOOD_BOARD_COMMENT_BROADCAST',
  MOOD_BOARD_COMMENT_DELETE_BROADCAST: 'MOOD_BOARD_COMMENT_DELETE_BROADCAST',
  MOOD_BOARD_THREAD_DELETE_BROADCAST: 'MOOD_BOARD_THREAD_DELETE_BROADCAST',
  MOOD_BOARD_THREAD_RESOLVE_BROADCAST: 'MOOD_BOARD_THREAD_RESOLVE_BROADCAST',
  
  // ============================================
  // PRESENCE MESSAGES
  // ============================================
  
  // Subscribe to presence updates for organization
  SUBSCRIBE_PRESENCE: 'SUBSCRIBE_PRESENCE',
  
  // Update own presence status
  PRESENCE_UPDATE: 'PRESENCE_UPDATE',
  
  // Heartbeat to maintain online status
  PRESENCE_HEARTBEAT: 'PRESENCE_HEARTBEAT',
  
  // Subscribe to specific users' presence (for cross-domain chat partners)
  SUBSCRIBE_PRESENCE_USERS: 'SUBSCRIBE_PRESENCE_USERS',
}

