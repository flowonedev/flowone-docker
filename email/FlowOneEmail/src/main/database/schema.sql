-- FlowOne Email Desktop Local Database Schema
-- SQLite database for offline-first functionality

-- ============================================
-- SYNC INFRASTRUCTURE TABLES
-- ============================================

-- Track sync state per entity type
CREATE TABLE IF NOT EXISTS sync_state (
    entity_type TEXT PRIMARY KEY,
    sync_cursor TEXT,
    last_sync_at TEXT,
    last_full_sync_at TEXT
);

-- Offline changes queue - pending upload when back online
CREATE TABLE IF NOT EXISTS sync_queue (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    entity_type TEXT NOT NULL,
    entity_id INTEGER,
    action TEXT NOT NULL,  -- create, update, delete, send
    payload TEXT NOT NULL, -- JSON data
    created_at TEXT DEFAULT (datetime('now')),
    attempts INTEGER DEFAULT 0,
    last_error TEXT,
    last_attempt_at TEXT
);

-- Sync event log for debugging
CREATE TABLE IF NOT EXISTS sync_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event_type TEXT NOT NULL,
    entity_type TEXT,
    entity_id INTEGER,
    payload TEXT,
    direction TEXT, -- incoming, outgoing
    status TEXT,    -- success, error
    error_message TEXT,
    created_at TEXT DEFAULT (datetime('now'))
);

-- ============================================
-- EMAIL TABLES
-- ============================================

-- Email accounts (cached from cloud)
CREATE TABLE IF NOT EXISTS email_accounts (
    id INTEGER PRIMARY KEY,
    remote_id INTEGER UNIQUE,
    email TEXT NOT NULL,
    display_name TEXT,
    is_primary INTEGER DEFAULT 0,
    is_oauth INTEGER DEFAULT 0,
    provider TEXT,
    sync_enabled INTEGER DEFAULT 1,
    last_sync_at TEXT,
    created_at TEXT DEFAULT (datetime('now'))
);

-- Email folders
CREATE TABLE IF NOT EXISTS email_folders (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    remote_id INTEGER,
    account_id INTEGER,
    name TEXT NOT NULL,
    full_path TEXT NOT NULL,
    parent_path TEXT,
    delimiter TEXT DEFAULT '/',
    type TEXT DEFAULT 'user',  -- inbox, sent, drafts, trash, spam, archive, user
    system INTEGER DEFAULT 0,  -- 1 if system folder
    flags TEXT,              -- JSON array
    unread_count INTEGER DEFAULT 0,
    total_count INTEGER DEFAULT 0,
    uidvalidity INTEGER,
    last_uid INTEGER,
    highest_modseq INTEGER DEFAULT 0,
    sync_enabled INTEGER DEFAULT 1,
    sync_status TEXT DEFAULT 'synced',
    last_sync_at TEXT,
    FOREIGN KEY (account_id) REFERENCES email_accounts(id),
    UNIQUE(account_id, full_path)
);

-- Emails
CREATE TABLE IF NOT EXISTS emails (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    remote_id INTEGER,       -- UID from IMAP
    account_id INTEGER,
    folder_id INTEGER,
    message_id TEXT,         -- Message-ID header
    conversation_id TEXT,    -- For threading
    subject TEXT,
    from_address TEXT,
    from_name TEXT,
    to_addresses TEXT,       -- JSON array
    cc_addresses TEXT,       -- JSON array
    bcc_addresses TEXT,      -- JSON array
    reply_to TEXT,
    date_sent TEXT,
    date_received TEXT,
    snippet TEXT,            -- Preview text
    body_html TEXT,
    body_text TEXT,
    is_read INTEGER DEFAULT 0,
    is_starred INTEGER DEFAULT 0,
    is_answered INTEGER DEFAULT 0,
    is_forwarded INTEGER DEFAULT 0,
    is_draft INTEGER DEFAULT 0,
    is_queued INTEGER DEFAULT 0,   -- Waiting to be sent
    has_attachments INTEGER DEFAULT 0,
    labels TEXT,             -- JSON array
    headers TEXT,            -- JSON of important headers
    size INTEGER,
    sync_status TEXT DEFAULT 'synced',
    local_updated_at TEXT,
    remote_updated_at TEXT,
    created_at TEXT DEFAULT (datetime('now')),
    FOREIGN KEY (account_id) REFERENCES email_accounts(id),
    FOREIGN KEY (folder_id) REFERENCES email_folders(id),
    UNIQUE(account_id, folder_id, remote_id)
);

CREATE INDEX IF NOT EXISTS idx_emails_folder ON emails(folder_id);
CREATE INDEX IF NOT EXISTS idx_emails_conversation ON emails(conversation_id);
CREATE INDEX IF NOT EXISTS idx_emails_date ON emails(date_received DESC);

-- Email labels
CREATE TABLE IF NOT EXISTS email_labels (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    remote_id INTEGER UNIQUE,
    account_id INTEGER,
    name TEXT NOT NULL,
    color TEXT,
    sync_status TEXT DEFAULT 'synced',
    FOREIGN KEY (account_id) REFERENCES email_accounts(id)
);

-- Email attachments metadata
CREATE TABLE IF NOT EXISTS email_attachments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email_id INTEGER,
    filename TEXT NOT NULL,
    mime_type TEXT,
    size INTEGER,
    content_id TEXT,        -- For inline images
    part_number TEXT,
    is_inline INTEGER DEFAULT 0,
    drive_file_id INTEGER,  -- Link to FlowOneDrive if saved
    local_path TEXT,        -- Cached locally
    downloaded INTEGER DEFAULT 0,
    FOREIGN KEY (email_id) REFERENCES emails(id) ON DELETE CASCADE
);

-- ============================================
-- CALENDAR TABLES
-- ============================================

-- Calendars
CREATE TABLE IF NOT EXISTS calendars (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    remote_id INTEGER UNIQUE,
    name TEXT NOT NULL,
    color TEXT,
    description TEXT,
    is_default INTEGER DEFAULT 0,
    is_visible INTEGER DEFAULT 1,
    can_edit INTEGER DEFAULT 1,
    sync_source TEXT,       -- local, google, microsoft
    external_id TEXT,       -- ID from Google/Microsoft
    sync_status TEXT DEFAULT 'synced',
    created_at TEXT DEFAULT (datetime('now')),
    updated_at TEXT
);

-- Calendar events
CREATE TABLE IF NOT EXISTS calendar_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    remote_id INTEGER UNIQUE,
    calendar_id INTEGER,
    title TEXT NOT NULL,
    description TEXT,
    location TEXT,
    start_time TEXT NOT NULL,
    end_time TEXT NOT NULL,
    all_day INTEGER DEFAULT 0,
    timezone TEXT,
    recurrence_rule TEXT,   -- RRULE format
    recurrence_id TEXT,     -- For recurring event instances
    color TEXT,
    status TEXT DEFAULT 'confirmed',  -- confirmed, tentative, cancelled
    visibility TEXT DEFAULT 'default',
    busy_status TEXT DEFAULT 'busy',
    reminders TEXT,         -- JSON array
    attendees TEXT,         -- JSON array
    organizer TEXT,
    linked_email_uid INTEGER,
    linked_email_folder TEXT,
    external_id TEXT,       -- ID from Google/Microsoft
    sync_status TEXT DEFAULT 'synced',
    local_updated_at TEXT,
    remote_updated_at TEXT,
    created_at TEXT DEFAULT (datetime('now')),
    FOREIGN KEY (calendar_id) REFERENCES calendars(id)
);

CREATE INDEX IF NOT EXISTS idx_events_calendar ON calendar_events(calendar_id);
CREATE INDEX IF NOT EXISTS idx_events_time ON calendar_events(start_time, end_time);

-- Event participants
CREATE TABLE IF NOT EXISTS event_participants (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id INTEGER,
    email TEXT NOT NULL,
    name TEXT,
    status TEXT DEFAULT 'pending',  -- pending, accepted, declined, tentative
    role TEXT DEFAULT 'attendee',   -- organizer, attendee
    FOREIGN KEY (event_id) REFERENCES calendar_events(id) ON DELETE CASCADE,
    UNIQUE(event_id, email)
);

-- ============================================
-- BOARDS (KANBAN) TABLES
-- ============================================

-- Boards
CREATE TABLE IF NOT EXISTS boards (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    remote_id INTEGER UNIQUE,
    name TEXT NOT NULL,
    description TEXT,
    color TEXT,
    background_type TEXT DEFAULT 'color',
    background_value TEXT,
    is_archived INTEGER DEFAULT 0,
    is_starred INTEGER DEFAULT 0,
    owner_email TEXT,
    drive_folder_id INTEGER,
    client_id INTEGER,
    sync_status TEXT DEFAULT 'synced',
    local_updated_at TEXT,
    remote_updated_at TEXT,
    created_at TEXT DEFAULT (datetime('now'))
);

-- Board lists (columns)
CREATE TABLE IF NOT EXISTS board_lists (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    remote_id INTEGER UNIQUE,
    board_id INTEGER,
    name TEXT NOT NULL,
    position INTEGER DEFAULT 0,
    is_archived INTEGER DEFAULT 0,
    wip_limit INTEGER,      -- Work in progress limit
    sync_status TEXT DEFAULT 'synced',
    FOREIGN KEY (board_id) REFERENCES boards(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_lists_board ON board_lists(board_id);

-- Board cards
CREATE TABLE IF NOT EXISTS board_cards (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    remote_id INTEGER UNIQUE,
    list_id INTEGER,
    title TEXT NOT NULL,
    description TEXT,
    position INTEGER DEFAULT 0,
    due_date TEXT,
    due_complete INTEGER DEFAULT 0,
    cover_image TEXT,
    cover_color TEXT,
    is_archived INTEGER DEFAULT 0,
    assignees TEXT,         -- JSON array of emails
    labels TEXT,            -- JSON array of label IDs
    checklist_progress TEXT, -- JSON {completed, total}
    sync_status TEXT DEFAULT 'synced',
    local_updated_at TEXT,
    remote_updated_at TEXT,
    created_at TEXT DEFAULT (datetime('now')),
    FOREIGN KEY (list_id) REFERENCES board_lists(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_cards_list ON board_cards(list_id);
CREATE INDEX IF NOT EXISTS idx_cards_due ON board_cards(due_date);

-- Board labels
CREATE TABLE IF NOT EXISTS board_labels (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    remote_id INTEGER UNIQUE,
    board_id INTEGER,
    name TEXT,
    color TEXT NOT NULL,
    FOREIGN KEY (board_id) REFERENCES boards(id) ON DELETE CASCADE
);

-- Card checklists
CREATE TABLE IF NOT EXISTS card_checklists (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    remote_id INTEGER UNIQUE,
    card_id INTEGER,
    title TEXT NOT NULL,
    position INTEGER DEFAULT 0,
    FOREIGN KEY (card_id) REFERENCES board_cards(id) ON DELETE CASCADE
);

-- Checklist items
CREATE TABLE IF NOT EXISTS checklist_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    remote_id INTEGER UNIQUE,
    checklist_id INTEGER,
    text TEXT NOT NULL,
    is_checked INTEGER DEFAULT 0,
    position INTEGER DEFAULT 0,
    FOREIGN KEY (checklist_id) REFERENCES card_checklists(id) ON DELETE CASCADE
);

-- Card comments
CREATE TABLE IF NOT EXISTS card_comments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    remote_id INTEGER UNIQUE,
    card_id INTEGER,
    author_email TEXT,
    content TEXT NOT NULL,
    created_at TEXT DEFAULT (datetime('now')),
    updated_at TEXT,
    FOREIGN KEY (card_id) REFERENCES board_cards(id) ON DELETE CASCADE
);

-- ============================================
-- CLIENTS TABLES
-- ============================================

-- Clients
CREATE TABLE IF NOT EXISTS clients (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    remote_id INTEGER UNIQUE,
    name TEXT NOT NULL,
    email TEXT,
    company TEXT,
    phone TEXT,
    address TEXT,
    notes TEXT,
    avatar_url TEXT,
    is_active INTEGER DEFAULT 1,
    hourly_rate REAL,
    currency TEXT DEFAULT 'EUR',
    drive_folder_id INTEGER,
    sync_status TEXT DEFAULT 'synced',
    local_updated_at TEXT,
    remote_updated_at TEXT,
    created_at TEXT DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_clients_email ON clients(email);

-- Client contacts
CREATE TABLE IF NOT EXISTS client_contacts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    remote_id INTEGER UNIQUE,
    client_id INTEGER,
    name TEXT NOT NULL,
    email TEXT,
    phone TEXT,
    role TEXT,
    is_primary INTEGER DEFAULT 0,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
);

-- Time tracking entries
CREATE TABLE IF NOT EXISTS time_entries (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    remote_id INTEGER UNIQUE,
    client_id INTEGER,
    board_id INTEGER,
    card_id INTEGER,
    description TEXT,
    duration_seconds INTEGER NOT NULL,
    started_at TEXT,
    ended_at TEXT,
    is_billable INTEGER DEFAULT 1,
    is_running INTEGER DEFAULT 0,
    source TEXT,            -- manual, desktop, browser
    sync_status TEXT DEFAULT 'synced',
    created_at TEXT DEFAULT (datetime('now')),
    FOREIGN KEY (client_id) REFERENCES clients(id),
    FOREIGN KEY (board_id) REFERENCES boards(id),
    FOREIGN KEY (card_id) REFERENCES board_cards(id)
);

CREATE INDEX IF NOT EXISTS idx_time_client ON time_entries(client_id);
CREATE INDEX IF NOT EXISTS idx_time_date ON time_entries(started_at);

-- ============================================
-- TODOS TABLES
-- ============================================

CREATE TABLE IF NOT EXISTS todos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    remote_id INTEGER UNIQUE,
    title TEXT NOT NULL,
    description TEXT,
    is_completed INTEGER DEFAULT 0,
    due_date TEXT,
    priority INTEGER DEFAULT 0,
    position INTEGER DEFAULT 0,
    calendar_event_id INTEGER,
    email_uid INTEGER,
    email_folder TEXT,
    sync_status TEXT DEFAULT 'synced',
    created_at TEXT DEFAULT (datetime('now')),
    completed_at TEXT
);

-- ============================================
-- DRIVE CACHE TABLES (Metadata only, files handled by FlowOneDrive)
-- ============================================

CREATE TABLE IF NOT EXISTS drive_folders_cache (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    remote_id INTEGER UNIQUE,
    parent_id INTEGER,
    name TEXT NOT NULL,
    color TEXT,
    is_shared INTEGER DEFAULT 0,
    owner_email TEXT,
    updated_at TEXT
);

CREATE TABLE IF NOT EXISTS drive_files_cache (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    remote_id INTEGER UNIQUE,
    folder_id INTEGER,
    filename TEXT NOT NULL,
    mime_type TEXT,
    size INTEGER,
    is_shared INTEGER DEFAULT 0,
    updated_at TEXT,
    FOREIGN KEY (folder_id) REFERENCES drive_folders_cache(remote_id)
);

-- ============================================
-- USER SETTINGS
-- ============================================

CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY,
    value TEXT,
    updated_at TEXT DEFAULT (datetime('now'))
);

-- ============================================
-- COLLEAGUE TABLES (cached from cloud)
-- ============================================

-- Organization colleagues
CREATE TABLE IF NOT EXISTS colleagues (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    remote_id INTEGER UNIQUE,
    organization_domain TEXT NOT NULL,
    email TEXT NOT NULL UNIQUE,
    display_name TEXT,
    avatar_path TEXT,
    job_title TEXT,
    department TEXT,
    phone TEXT,
    is_admin INTEGER DEFAULT 0,
    status TEXT DEFAULT 'active',       -- active, away, offline, do_not_disturb
    last_seen_at TEXT,
    profile_updated_at TEXT,
    synced_from_mailserver INTEGER DEFAULT 0,
    sync_status TEXT DEFAULT 'synced',
    created_at TEXT DEFAULT (datetime('now')),
    updated_at TEXT
);

CREATE INDEX IF NOT EXISTS idx_colleagues_domain ON colleagues(organization_domain);
CREATE INDEX IF NOT EXISTS idx_colleagues_status ON colleagues(status);

-- Colleague groups
CREATE TABLE IF NOT EXISTS colleague_groups (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    remote_id INTEGER UNIQUE,
    organization_domain TEXT NOT NULL,
    name TEXT NOT NULL,
    description TEXT,
    color TEXT DEFAULT '#6366f1',
    icon TEXT DEFAULT 'group',
    sort_order INTEGER DEFAULT 0,
    created_by TEXT NOT NULL,
    sync_status TEXT DEFAULT 'synced',
    created_at TEXT DEFAULT (datetime('now')),
    updated_at TEXT
);

CREATE INDEX IF NOT EXISTS idx_cgroups_domain ON colleague_groups(organization_domain);

-- Colleague group memberships
CREATE TABLE IF NOT EXISTS colleague_group_members (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    remote_id INTEGER UNIQUE,
    group_id INTEGER NOT NULL,
    colleague_id INTEGER NOT NULL,
    added_by TEXT NOT NULL,
    added_at TEXT DEFAULT (datetime('now')),
    UNIQUE(group_id, colleague_id),
    FOREIGN KEY (group_id) REFERENCES colleague_groups(id) ON DELETE CASCADE,
    FOREIGN KEY (colleague_id) REFERENCES colleagues(id) ON DELETE CASCADE
);

-- ============================================
-- MAILING LIST TABLES (cached from cloud)
-- ============================================

-- Mailing lists
CREATE TABLE IF NOT EXISTS mailing_lists (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    remote_id INTEGER UNIQUE,
    user_email TEXT NOT NULL,
    name TEXT NOT NULL,
    description TEXT,
    color TEXT DEFAULT '#6366f1',
    icon TEXT DEFAULT 'mail',
    sort_order INTEGER DEFAULT 0,
    sync_status TEXT DEFAULT 'synced',
    created_at TEXT DEFAULT (datetime('now')),
    updated_at TEXT,
    UNIQUE(user_email, name)
);

CREATE INDEX IF NOT EXISTS idx_mlists_user ON mailing_lists(user_email);

-- Mailing list contacts
CREATE TABLE IF NOT EXISTS mailing_list_contacts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    remote_id INTEGER UNIQUE,
    list_id INTEGER NOT NULL,
    email TEXT NOT NULL,
    name TEXT,
    phone TEXT,
    position TEXT,
    company TEXT,
    notes TEXT,
    sync_status TEXT DEFAULT 'synced',
    created_at TEXT DEFAULT (datetime('now')),
    updated_at TEXT,
    UNIQUE(list_id, email),
    FOREIGN KEY (list_id) REFERENCES mailing_lists(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_mlcontacts_email ON mailing_list_contacts(email);

-- Mailing list import history
CREATE TABLE IF NOT EXISTS mailing_list_imports (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    remote_id INTEGER UNIQUE,
    list_id INTEGER NOT NULL,
    user_email TEXT NOT NULL,
    filename TEXT,
    total_rows INTEGER DEFAULT 0,
    imported_count INTEGER DEFAULT 0,
    skipped_count INTEGER DEFAULT 0,
    error_count INTEGER DEFAULT 0,
    errors TEXT,                        -- JSON
    created_at TEXT DEFAULT (datetime('now')),
    FOREIGN KEY (list_id) REFERENCES mailing_lists(id) ON DELETE CASCADE
);

-- ============================================
-- EMAIL CAMPAIGN TABLES (cached from cloud)
-- ============================================

-- Email campaigns
CREATE TABLE IF NOT EXISTS email_campaigns (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    remote_id INTEGER UNIQUE,
    campaign_id TEXT NOT NULL UNIQUE,    -- UUID
    user_email TEXT NOT NULL,
    subject TEXT NOT NULL,
    body_html TEXT,
    body_text TEXT,
    from_name TEXT,
    attachments TEXT,                    -- JSON
    in_reply_to TEXT,
    reference_ids TEXT,
    track_read INTEGER DEFAULT 1,
    total_recipients INTEGER DEFAULT 0,
    sent_count INTEGER DEFAULT 0,
    failed_count INTEGER DEFAULT 0,
    status TEXT DEFAULT 'pending',       -- pending, processing, completed, paused, cancelled
    sync_status TEXT DEFAULT 'synced',
    created_at TEXT DEFAULT (datetime('now')),
    started_at TEXT,
    completed_at TEXT
);

CREATE INDEX IF NOT EXISTS idx_campaigns_user ON email_campaigns(user_email, status);
CREATE INDEX IF NOT EXISTS idx_campaigns_status ON email_campaigns(status);

-- Email queue items (per-recipient)
CREATE TABLE IF NOT EXISTS email_queue (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    remote_id INTEGER UNIQUE,
    campaign_id TEXT NOT NULL,
    recipient_email TEXT NOT NULL,
    recipient_name TEXT,
    recipient_type TEXT DEFAULT 'to',    -- to, cc, bcc
    status TEXT DEFAULT 'pending',       -- pending, sending, sent, failed, rate_limited
    attempts INTEGER DEFAULT 0,
    max_attempts INTEGER DEFAULT 3,
    scheduled_at TEXT DEFAULT (datetime('now')),
    sent_at TEXT,
    error_message TEXT,
    last_attempt_at TEXT,
    FOREIGN KEY (campaign_id) REFERENCES email_campaigns(campaign_id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_queue_campaign ON email_queue(campaign_id);
CREATE INDEX IF NOT EXISTS idx_queue_status ON email_queue(status, scheduled_at);

-- Campaign activity log
CREATE TABLE IF NOT EXISTS email_campaign_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    remote_id INTEGER UNIQUE,
    campaign_id TEXT NOT NULL,
    event_type TEXT NOT NULL,
    recipient_email TEXT,
    message TEXT,
    created_at TEXT DEFAULT (datetime('now')),
    FOREIGN KEY (campaign_id) REFERENCES email_campaigns(campaign_id) ON DELETE CASCADE
);

-- ============================================
-- CHAT TABLES (cached from cloud)
-- ============================================

-- Chat conversations (DM, group, channel)
CREATE TABLE IF NOT EXISTS chat_conversations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    remote_id INTEGER UNIQUE,
    organization_domain TEXT NOT NULL,
    type TEXT NOT NULL DEFAULT 'direct', -- direct, group, channel
    name TEXT,                           -- Group/channel name (null for DM)
    avatar TEXT,                         -- Group avatar URL
    description TEXT,
    settings TEXT,                       -- JSON
    
    -- Channel-specific
    is_public INTEGER DEFAULT 1,
    slug TEXT,                           -- #channel-name
    topic TEXT,
    purpose TEXT,
    is_default INTEGER DEFAULT 0,
    
    -- Metadata
    created_by INTEGER NOT NULL,
    last_message_at TEXT,
    last_message_preview TEXT,
    last_message_sender_id INTEGER,
    message_count INTEGER DEFAULT 0,
    
    sync_status TEXT DEFAULT 'synced',
    created_at TEXT DEFAULT (datetime('now')),
    updated_at TEXT
);

CREATE INDEX IF NOT EXISTS idx_chatconv_domain ON chat_conversations(organization_domain);
CREATE INDEX IF NOT EXISTS idx_chatconv_last ON chat_conversations(organization_domain, last_message_at);
CREATE INDEX IF NOT EXISTS idx_chatconv_slug ON chat_conversations(organization_domain, slug);

-- Chat participants
CREATE TABLE IF NOT EXISTS chat_participants (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    remote_id INTEGER UNIQUE,
    conversation_id INTEGER NOT NULL,
    colleague_id INTEGER NOT NULL,
    last_read_message_id INTEGER,
    last_read_at TEXT,
    unread_count INTEGER DEFAULT 0,
    is_pinned INTEGER DEFAULT 0,
    is_muted INTEGER DEFAULT 0,
    is_archived INTEGER DEFAULT 0,
    is_admin INTEGER DEFAULT 0,
    added_by INTEGER,
    nickname TEXT,
    joined_at TEXT DEFAULT (datetime('now')),
    UNIQUE(conversation_id, colleague_id),
    FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_chatpart_colleague ON chat_participants(colleague_id);
CREATE INDEX IF NOT EXISTS idx_chatpart_unread ON chat_participants(colleague_id, unread_count);

-- DM lookup (canonical ordering: a < b)
CREATE TABLE IF NOT EXISTS chat_dm_lookup (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    remote_id INTEGER UNIQUE,
    conversation_id INTEGER NOT NULL,
    colleague_a_id INTEGER NOT NULL,
    colleague_b_id INTEGER NOT NULL,
    UNIQUE(colleague_a_id, colleague_b_id),
    FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE
);

-- Chat messages
CREATE TABLE IF NOT EXISTS chat_messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    remote_id INTEGER UNIQUE,
    conversation_id INTEGER NOT NULL,
    sender_id INTEGER NOT NULL,
    content TEXT NOT NULL,
    content_type TEXT DEFAULT 'text',    -- text, file, image, system, voice, call, embed
    reply_to_id INTEGER,
    attachments TEXT,                    -- JSON
    voice_duration REAL,                -- Seconds (for voice messages)
    is_edited INTEGER DEFAULT 0,
    is_pinned INTEGER DEFAULT 0,
    pinned_at TEXT,
    pinned_by INTEGER,
    edited_at TEXT,
    deleted_at TEXT,                     -- Soft delete
    sync_status TEXT DEFAULT 'synced',
    created_at TEXT DEFAULT (datetime('now')),
    FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_chatmsg_conv ON chat_messages(conversation_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_chatmsg_sender ON chat_messages(sender_id);
CREATE INDEX IF NOT EXISTS idx_chatmsg_reply ON chat_messages(reply_to_id);
CREATE INDEX IF NOT EXISTS idx_chatmsg_pinned ON chat_messages(conversation_id, is_pinned, pinned_at DESC);

-- Chat message reactions
CREATE TABLE IF NOT EXISTS chat_message_reactions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    remote_id INTEGER UNIQUE,
    message_id INTEGER NOT NULL,
    colleague_id INTEGER NOT NULL,
    emoji TEXT NOT NULL,
    created_at TEXT DEFAULT (datetime('now')),
    UNIQUE(message_id, colleague_id, emoji),
    FOREIGN KEY (message_id) REFERENCES chat_messages(id) ON DELETE CASCADE
);

-- Chat read receipts
CREATE TABLE IF NOT EXISTS chat_read_receipts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    remote_id INTEGER UNIQUE,
    message_id INTEGER NOT NULL,
    colleague_id INTEGER NOT NULL,
    read_at TEXT DEFAULT (datetime('now')),
    UNIQUE(message_id, colleague_id),
    FOREIGN KEY (message_id) REFERENCES chat_messages(id) ON DELETE CASCADE
);

-- Chat attachments
CREATE TABLE IF NOT EXISTS chat_attachments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    remote_id INTEGER UNIQUE,
    conversation_id INTEGER NOT NULL,
    message_id INTEGER NOT NULL,
    uploader_id INTEGER NOT NULL,
    filename TEXT NOT NULL,
    original_filename TEXT NOT NULL,
    file_path TEXT NOT NULL,
    file_size INTEGER NOT NULL,
    mime_type TEXT NOT NULL,
    file_category TEXT DEFAULT 'other',  -- image, video, audio, document, archive, other
    image_width INTEGER,
    image_height INTEGER,
    thumbnail_path TEXT,
    drive_file_id INTEGER,
    saved_to_drive_at TEXT,
    created_at TEXT DEFAULT (datetime('now')),
    FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE,
    FOREIGN KEY (message_id) REFERENCES chat_messages(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_chatatt_conv ON chat_attachments(conversation_id);
CREATE INDEX IF NOT EXISTS idx_chatatt_category ON chat_attachments(conversation_id, file_category);

-- Chat mentions
CREATE TABLE IF NOT EXISTS chat_mentions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    remote_id INTEGER UNIQUE,
    message_id INTEGER NOT NULL,
    conversation_id INTEGER NOT NULL,
    mentioned_colleague_id INTEGER,     -- NULL for @here/@channel
    mention_type TEXT DEFAULT 'user',   -- user, here, channel
    created_at TEXT DEFAULT (datetime('now')),
    FOREIGN KEY (message_id) REFERENCES chat_messages(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_mentions_colleague ON chat_mentions(mentioned_colleague_id, created_at DESC);

-- Chat group invitations
CREATE TABLE IF NOT EXISTS chat_group_invitations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    remote_id INTEGER UNIQUE,
    conversation_id INTEGER NOT NULL,
    invited_email TEXT NOT NULL,
    invited_by INTEGER NOT NULL,
    token TEXT NOT NULL UNIQUE,
    status TEXT DEFAULT 'pending',       -- pending, accepted, declined, expired
    message TEXT,
    expires_at TEXT,
    responded_at TEXT,
    created_at TEXT DEFAULT (datetime('now')),
    UNIQUE(conversation_id, invited_email),
    FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE
);

-- Chat webhooks
CREATE TABLE IF NOT EXISTS chat_webhooks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    remote_id INTEGER UNIQUE,
    conversation_id INTEGER NOT NULL,
    creator_id INTEGER NOT NULL,
    name TEXT NOT NULL DEFAULT 'Webhook',
    avatar_url TEXT,
    token TEXT NOT NULL UNIQUE,
    is_active INTEGER DEFAULT 1,
    last_used_at TEXT,
    created_at TEXT DEFAULT (datetime('now')),
    FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE
);

-- Chat huddles (persistent audio rooms)
CREATE TABLE IF NOT EXISTS chat_huddles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    remote_id INTEGER UNIQUE,
    conversation_id INTEGER NOT NULL,
    started_by INTEGER NOT NULL,
    started_at TEXT DEFAULT (datetime('now')),
    ended_at TEXT,
    is_active INTEGER DEFAULT 1,
    FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_huddle_active ON chat_huddles(conversation_id, is_active);

-- Huddle participants
CREATE TABLE IF NOT EXISTS chat_huddle_participants (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    remote_id INTEGER UNIQUE,
    huddle_id INTEGER NOT NULL,
    colleague_id INTEGER NOT NULL,
    joined_at TEXT DEFAULT (datetime('now')),
    left_at TEXT,
    is_muted INTEGER DEFAULT 0,
    is_deafened INTEGER DEFAULT 0,
    UNIQUE(huddle_id, colleague_id),
    FOREIGN KEY (huddle_id) REFERENCES chat_huddles(id) ON DELETE CASCADE
);

-- ============================================
-- CALL HISTORY (cached from cloud)
-- ============================================

CREATE TABLE IF NOT EXISTS call_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    remote_id INTEGER UNIQUE,
    call_id TEXT NOT NULL UNIQUE,
    conversation_id INTEGER NOT NULL,
    initiated_by INTEGER NOT NULL,
    call_type TEXT DEFAULT 'voice',      -- voice, video
    status TEXT DEFAULT 'completed',     -- completed, missed, rejected, no_answer, cancelled
    started_at TEXT DEFAULT (datetime('now')),
    answered_at TEXT,
    ended_at TEXT,
    duration_seconds INTEGER DEFAULT 0,
    participants TEXT,                    -- JSON
    had_screen_share INTEGER DEFAULT 0,
    sync_status TEXT DEFAULT 'synced',
    created_at TEXT DEFAULT (datetime('now')),
    FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_callhist_conv ON call_history(conversation_id, started_at DESC);
CREATE INDEX IF NOT EXISTS idx_callhist_status ON call_history(status);

-- ============================================
-- DEVICE REGISTRY (cached from cloud)
-- ============================================

CREATE TABLE IF NOT EXISTS devices (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    remote_id INTEGER UNIQUE,
    email TEXT NOT NULL,
    device_id TEXT NOT NULL,
    device_name TEXT,
    platform TEXT DEFAULT 'web',         -- web, desktop, drive
    os TEXT,
    app_version TEXT,
    status TEXT DEFAULT 'active',        -- active, blocked, wipe_pending, wiped
    last_ip TEXT,
    last_seen_at TEXT DEFAULT (datetime('now')),
    wipe_requested_at TEXT,
    wipe_confirmed_at TEXT,
    sync_status TEXT DEFAULT 'synced',
    created_at TEXT DEFAULT (datetime('now')),
    UNIQUE(email, device_id)
);

CREATE INDEX IF NOT EXISTS idx_devices_email ON devices(email);

-- ============================================
-- EMAIL TEMPLATES (cached from cloud)
-- ============================================

CREATE TABLE IF NOT EXISTS email_templates (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    remote_id INTEGER UNIQUE,
    created_by TEXT NOT NULL,
    organization_domain TEXT NOT NULL,
    name TEXT NOT NULL,
    description TEXT,
    category TEXT DEFAULT 'custom',      -- text, media, layout, cta, custom
    icon TEXT DEFAULT 'dashboard_customize',
    html_content TEXT NOT NULL,
    thumbnail TEXT,
    is_shared INTEGER DEFAULT 0,
    sort_order INTEGER DEFAULT 0,
    sync_status TEXT DEFAULT 'synced',
    created_at TEXT DEFAULT (datetime('now')),
    updated_at TEXT
);

CREATE INDEX IF NOT EXISTS idx_templates_creator ON email_templates(created_by);
CREATE INDEX IF NOT EXISTS idx_templates_org ON email_templates(organization_domain);

-- ============================================
-- CRM TABLES (cached from cloud)
-- ============================================

-- CRM Invoices
CREATE TABLE IF NOT EXISTS crm_invoices (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    remote_id INTEGER UNIQUE,
    client_id INTEGER NOT NULL,
    user_email TEXT NOT NULL,
    invoice_number TEXT NOT NULL,
    status TEXT DEFAULT 'draft',        -- draft, sent, viewed, partial, paid, overdue, cancelled, refunded
    issue_date TEXT NOT NULL,
    due_date TEXT NOT NULL,
    subtotal REAL NOT NULL DEFAULT 0,
    tax_rate REAL DEFAULT 0,
    tax_amount REAL DEFAULT 0,
    discount_amount REAL DEFAULT 0,
    total REAL NOT NULL DEFAULT 0,
    currency TEXT DEFAULT 'HUF',
    paid_amount REAL DEFAULT 0,
    paid_at TEXT,
    payment_method TEXT,
    payment_reference TEXT,
    notes TEXT,
    internal_notes TEXT,
    is_recurring INTEGER DEFAULT 0,
    recurrence_interval TEXT,           -- weekly, monthly, quarterly, yearly
    recurrence_end_date TEXT,
    parent_invoice_id INTEGER,
    portal_document_id INTEGER,
    drive_file_id INTEGER,
    board_card_id INTEGER,
    billing_provider TEXT DEFAULT 'manual', -- billingo, szamlazz, manual
    external_invoice_id TEXT,
    external_invoice_url TEXT,
    external_pdf_url TEXT,
    sent_at TEXT,
    viewed_at TEXT,
    reminder_count INTEGER DEFAULT 0,
    last_reminder_at TEXT,
    sync_status TEXT DEFAULT 'synced',
    created_at TEXT DEFAULT (datetime('now')),
    updated_at TEXT
);

CREATE INDEX IF NOT EXISTS idx_crm_invoices_client ON crm_invoices(client_id);
CREATE INDEX IF NOT EXISTS idx_crm_invoices_status ON crm_invoices(status);
CREATE INDEX IF NOT EXISTS idx_crm_invoices_due ON crm_invoices(due_date);

-- CRM Invoice Items
CREATE TABLE IF NOT EXISTS crm_invoice_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    remote_id INTEGER UNIQUE,
    invoice_id INTEGER NOT NULL,
    description TEXT NOT NULL,
    quantity REAL DEFAULT 1,
    unit TEXT,
    unit_price REAL NOT NULL,
    tax_rate REAL,
    total REAL NOT NULL,
    sort_order INTEGER DEFAULT 0,
    board_card_id INTEGER,
    FOREIGN KEY (invoice_id) REFERENCES crm_invoices(id) ON DELETE CASCADE
);

-- CRM Invoice Payments
CREATE TABLE IF NOT EXISTS crm_invoice_payments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    remote_id INTEGER UNIQUE,
    invoice_id INTEGER NOT NULL,
    amount REAL NOT NULL,
    payment_date TEXT NOT NULL,
    payment_method TEXT,
    reference TEXT,
    notes TEXT,
    recorded_by TEXT NOT NULL,
    created_at TEXT DEFAULT (datetime('now')),
    FOREIGN KEY (invoice_id) REFERENCES crm_invoices(id) ON DELETE CASCADE
);

-- CRM Expenses
CREATE TABLE IF NOT EXISTS crm_expenses (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    remote_id INTEGER UNIQUE,
    client_id INTEGER NOT NULL,
    user_email TEXT NOT NULL,
    description TEXT NOT NULL,
    amount REAL NOT NULL,
    currency TEXT DEFAULT 'HUF',
    expense_date TEXT NOT NULL,
    category TEXT,
    receipt_drive_file_id INTEGER,
    notes TEXT,
    sync_status TEXT DEFAULT 'synced',
    created_at TEXT DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_crm_expenses_client ON crm_expenses(client_id);

-- CRM Deals (Sales Pipeline)
CREATE TABLE IF NOT EXISTS crm_deals (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    remote_id INTEGER UNIQUE,
    client_id INTEGER NOT NULL,
    user_email TEXT NOT NULL,
    title TEXT NOT NULL,
    description TEXT,
    pipeline_stage TEXT DEFAULT 'lead',  -- lead, contacted, proposal, negotiation, won, lost
    expected_value REAL,
    currency TEXT DEFAULT 'HUF',
    probability INTEGER DEFAULT 50,
    expected_close_date TEXT,
    actual_close_date TEXT,
    lost_reason TEXT,
    contact_id INTEGER,
    assigned_to TEXT,
    board_id INTEGER,
    invoice_id INTEGER,
    sync_status TEXT DEFAULT 'synced',
    created_at TEXT DEFAULT (datetime('now')),
    updated_at TEXT
);

CREATE INDEX IF NOT EXISTS idx_crm_deals_client ON crm_deals(client_id);
CREATE INDEX IF NOT EXISTS idx_crm_deals_stage ON crm_deals(pipeline_stage);

-- CRM Deal Stage History
CREATE TABLE IF NOT EXISTS crm_deal_stage_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    remote_id INTEGER UNIQUE,
    deal_id INTEGER NOT NULL,
    from_stage TEXT,
    to_stage TEXT NOT NULL,
    changed_by TEXT NOT NULL,
    changed_at TEXT DEFAULT (datetime('now')),
    FOREIGN KEY (deal_id) REFERENCES crm_deals(id) ON DELETE CASCADE
);

-- CRM Tags
CREATE TABLE IF NOT EXISTS crm_tags (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    remote_id INTEGER UNIQUE,
    user_email TEXT NOT NULL,
    name TEXT NOT NULL,
    color TEXT DEFAULT '#6366f1',
    tag_group TEXT,
    sync_status TEXT DEFAULT 'synced',
    UNIQUE(user_email, name)
);

-- CRM Tag Assignments
CREATE TABLE IF NOT EXISTS crm_tag_assignments (
    client_id INTEGER NOT NULL,
    tag_id INTEGER NOT NULL,
    assigned_at TEXT DEFAULT (datetime('now')),
    PRIMARY KEY (client_id, tag_id),
    FOREIGN KEY (tag_id) REFERENCES crm_tags(id) ON DELETE CASCADE
);

-- CRM Custom Field Definitions
CREATE TABLE IF NOT EXISTS crm_custom_field_definitions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    remote_id INTEGER UNIQUE,
    user_email TEXT NOT NULL,
    field_name TEXT NOT NULL,
    field_type TEXT NOT NULL,            -- text, number, date, select, url, email, phone
    field_options TEXT,                   -- JSON
    is_required INTEGER DEFAULT 0,
    sort_order INTEGER DEFAULT 0,
    UNIQUE(user_email, field_name)
);

-- CRM Custom Field Values
CREATE TABLE IF NOT EXISTS crm_custom_field_values (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    remote_id INTEGER UNIQUE,
    client_id INTEGER NOT NULL,
    field_id INTEGER NOT NULL,
    field_value TEXT,
    UNIQUE(client_id, field_id),
    FOREIGN KEY (field_id) REFERENCES crm_custom_field_definitions(id) ON DELETE CASCADE
);

-- CRM Reminders
CREATE TABLE IF NOT EXISTS crm_reminders (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    remote_id INTEGER UNIQUE,
    client_id INTEGER NOT NULL,
    user_email TEXT NOT NULL,
    title TEXT NOT NULL,
    description TEXT,
    remind_at TEXT NOT NULL,
    is_completed INTEGER DEFAULT 0,
    completed_at TEXT,
    is_recurring INTEGER DEFAULT 0,
    recurrence_interval TEXT,            -- daily, weekly, biweekly, monthly
    contact_id INTEGER,
    deal_id INTEGER,
    notification_sent INTEGER DEFAULT 0,
    sync_status TEXT DEFAULT 'synced',
    created_at TEXT DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_crm_reminders_pending ON crm_reminders(is_completed, remind_at);

-- CRM Call Log
CREATE TABLE IF NOT EXISTS crm_call_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    remote_id INTEGER UNIQUE,
    client_id INTEGER NOT NULL,
    user_email TEXT NOT NULL,
    contact_id INTEGER,
    direction TEXT NOT NULL,             -- inbound, outbound
    duration_minutes INTEGER,
    outcome TEXT DEFAULT 'connected',    -- connected, no_answer, voicemail, busy, callback_requested
    notes TEXT,
    follow_up_reminder_id INTEGER,
    call_date TEXT NOT NULL,
    sync_status TEXT DEFAULT 'synced',
    created_at TEXT DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_crm_call_log_client ON crm_call_log(client_id);

-- CRM Meeting Notes
CREATE TABLE IF NOT EXISTS crm_meeting_notes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    remote_id INTEGER UNIQUE,
    client_id INTEGER NOT NULL,
    user_email TEXT NOT NULL,
    title TEXT NOT NULL,
    content TEXT,
    meeting_date TEXT NOT NULL,
    attendees TEXT,                       -- JSON
    action_items TEXT,                   -- JSON
    calendar_event_id INTEGER,
    portal_call_id INTEGER,
    deal_id INTEGER,
    sync_status TEXT DEFAULT 'synced',
    created_at TEXT DEFAULT (datetime('now')),
    updated_at TEXT
);

CREATE INDEX IF NOT EXISTS idx_crm_meeting_notes_client ON crm_meeting_notes(client_id);

-- CRM Automation Rules
CREATE TABLE IF NOT EXISTS crm_automation_rules (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    remote_id INTEGER UNIQUE,
    user_email TEXT NOT NULL,
    name TEXT NOT NULL,
    description TEXT,
    is_active INTEGER DEFAULT 1,
    trigger_type TEXT NOT NULL,
    trigger_config TEXT NOT NULL,         -- JSON
    action_type TEXT NOT NULL,
    action_config TEXT NOT NULL,          -- JSON
    last_run_at TEXT,
    run_count INTEGER DEFAULT 0,
    sync_status TEXT DEFAULT 'synced',
    created_at TEXT DEFAULT (datetime('now')),
    updated_at TEXT
);

-- CRM Automation Log
CREATE TABLE IF NOT EXISTS crm_automation_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    remote_id INTEGER UNIQUE,
    rule_id INTEGER NOT NULL,
    user_email TEXT NOT NULL,
    target_type TEXT NOT NULL,            -- deal, client, invoice
    target_id INTEGER NOT NULL,
    action_taken TEXT NOT NULL,
    result_detail TEXT,
    created_at TEXT DEFAULT (datetime('now')),
    FOREIGN KEY (rule_id) REFERENCES crm_automation_rules(id) ON DELETE CASCADE
);

-- CRM Sequences
CREATE TABLE IF NOT EXISTS crm_sequences (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    remote_id INTEGER UNIQUE,
    user_email TEXT NOT NULL,
    name TEXT NOT NULL,
    description TEXT,
    trigger_stage TEXT,
    is_active INTEGER DEFAULT 1,
    steps TEXT NOT NULL,                  -- JSON
    sync_status TEXT DEFAULT 'synced',
    created_at TEXT DEFAULT (datetime('now')),
    updated_at TEXT
);

-- CRM Sequence Enrollments
CREATE TABLE IF NOT EXISTS crm_sequence_enrollments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    remote_id INTEGER UNIQUE,
    sequence_id INTEGER NOT NULL,
    deal_id INTEGER,
    client_id INTEGER NOT NULL,
    user_email TEXT NOT NULL,
    current_step INTEGER DEFAULT 0,
    status TEXT DEFAULT 'active',        -- active, completed, cancelled, paused
    next_run_at TEXT,
    started_at TEXT DEFAULT (datetime('now')),
    completed_at TEXT,
    FOREIGN KEY (sequence_id) REFERENCES crm_sequences(id) ON DELETE CASCADE
);

-- CRM Sharing
CREATE TABLE IF NOT EXISTS crm_shares (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    remote_id INTEGER UNIQUE,
    owner_email TEXT NOT NULL,
    shared_with_email TEXT NOT NULL,
    permission TEXT DEFAULT 'viewer',    -- viewer, editor, manager
    is_active INTEGER DEFAULT 1,
    sync_status TEXT DEFAULT 'synced',
    created_at TEXT DEFAULT (datetime('now')),
    updated_at TEXT,
    UNIQUE(owner_email, shared_with_email)
);

-- CRM Group Access
CREATE TABLE IF NOT EXISTS crm_group_access (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    remote_id INTEGER UNIQUE,
    owner_email TEXT NOT NULL,
    group_id INTEGER NOT NULL,
    permission TEXT DEFAULT 'viewer',
    granted_by TEXT NOT NULL,
    created_at TEXT DEFAULT (datetime('now')),
    UNIQUE(owner_email, group_id)
);

-- CRM Billing Settings
CREATE TABLE IF NOT EXISTS crm_billing_settings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    remote_id INTEGER UNIQUE,
    user_email TEXT NOT NULL UNIQUE,
    provider TEXT DEFAULT 'none',        -- billingo, szamlazz, none
    api_key TEXT,
    billingo_block_id INTEGER,
    szamlazz_agent_key TEXT,
    company_name TEXT,
    company_address TEXT,
    company_tax_number TEXT,
    company_eu_tax_number TEXT,
    company_bank_account TEXT,
    company_bank_name TEXT,
    company_email TEXT,
    company_phone TEXT,
    company_logo_drive_file_id INTEGER,
    default_currency TEXT DEFAULT 'HUF',
    default_tax_rate REAL DEFAULT 27.00,
    default_payment_terms_days INTEGER DEFAULT 8,
    default_payment_method TEXT DEFAULT 'bank_transfer',
    default_language TEXT DEFAULT 'hu',
    auto_save_to_drive INTEGER DEFAULT 1,
    drive_invoices_folder_id INTEGER,
    sync_status TEXT DEFAULT 'synced',
    created_at TEXT DEFAULT (datetime('now')),
    updated_at TEXT
);

-- ============================================
-- MOOD BOARD TABLES (cached from cloud)
-- ============================================

-- Mood boards (canvases)
CREATE TABLE IF NOT EXISTS mood_boards (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    remote_id INTEGER UNIQUE,
    owner_email TEXT NOT NULL,
    client_id INTEGER,
    name TEXT NOT NULL,
    description TEXT,
    background_color TEXT DEFAULT '#f5f5f5',
    background_image TEXT,
    canvas_width INTEGER DEFAULT 4000,
    canvas_height INTEGER DEFAULT 3000,
    zoom_level REAL DEFAULT 1.00,
    viewport_x INTEGER DEFAULT 0,
    viewport_y INTEGER DEFAULT 0,
    is_template INTEGER DEFAULT 0,
    archived INTEGER DEFAULT 0,
    share_token TEXT,
    share_mode TEXT DEFAULT 'off',       -- off, view, edit
    share_password TEXT,
    share_expires TEXT,
    sync_status TEXT DEFAULT 'synced',
    created_at TEXT DEFAULT (datetime('now')),
    updated_at TEXT
);

CREATE INDEX IF NOT EXISTS idx_mood_boards_owner ON mood_boards(owner_email);
CREATE INDEX IF NOT EXISTS idx_mood_boards_share ON mood_boards(share_token);

-- Mood board items (notes, images, text, etc.)
CREATE TABLE IF NOT EXISTS mood_board_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    remote_id INTEGER UNIQUE,
    board_id INTEGER NOT NULL,
    parent_id INTEGER,
    type TEXT NOT NULL,                   -- note, image, text, link, todo_list, file, color_swatch, board_link, frame, image_set, calendar_event
    pos_x INTEGER NOT NULL DEFAULT 0,
    pos_y INTEGER NOT NULL DEFAULT 0,
    width INTEGER DEFAULT 240,
    height INTEGER,
    rotation REAL DEFAULT 0,
    z_index INTEGER DEFAULT 0,
    locked INTEGER DEFAULT 0,
    title TEXT,
    content TEXT,
    color TEXT,
    color_data TEXT,                      -- JSON
    url TEXT,
    drive_file_id INTEGER,
    image_url TEXT,
    thumbnail_url TEXT,
    linked_board_id INTEGER,
    linked_card_id INTEGER,
    calendar_event_id INTEGER,
    style_data TEXT,                      -- JSON
    created_by TEXT,
    sync_status TEXT DEFAULT 'synced',
    created_at TEXT DEFAULT (datetime('now')),
    updated_at TEXT,
    FOREIGN KEY (board_id) REFERENCES mood_boards(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_mood_items_board ON mood_board_items(board_id);

-- Mood board todos
CREATE TABLE IF NOT EXISTS mood_board_todos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    remote_id INTEGER UNIQUE,
    item_id INTEGER NOT NULL,
    text TEXT NOT NULL,
    completed INTEGER DEFAULT 0,
    position INTEGER DEFAULT 0,
    created_at TEXT DEFAULT (datetime('now')),
    FOREIGN KEY (item_id) REFERENCES mood_board_items(id) ON DELETE CASCADE
);

-- Mood board connections (arrows)
CREATE TABLE IF NOT EXISTS mood_board_connections (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    remote_id INTEGER UNIQUE,
    board_id INTEGER NOT NULL,
    from_item_id INTEGER NOT NULL,
    to_item_id INTEGER NOT NULL,
    line_style TEXT DEFAULT 'solid',     -- solid, dashed, dotted
    line_color TEXT DEFAULT '#666666',
    line_width INTEGER DEFAULT 2,
    arrow_start INTEGER DEFAULT 0,
    arrow_end INTEGER DEFAULT 1,
    label TEXT,
    created_at TEXT DEFAULT (datetime('now')),
    FOREIGN KEY (board_id) REFERENCES mood_boards(id) ON DELETE CASCADE,
    FOREIGN KEY (from_item_id) REFERENCES mood_board_items(id) ON DELETE CASCADE,
    FOREIGN KEY (to_item_id) REFERENCES mood_board_items(id) ON DELETE CASCADE
);

-- Mood board members
CREATE TABLE IF NOT EXISTS mood_board_members (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    remote_id INTEGER UNIQUE,
    board_id INTEGER NOT NULL,
    email TEXT NOT NULL,
    role TEXT DEFAULT 'editor',          -- viewer, editor, admin
    invited_by TEXT,
    added_at TEXT DEFAULT (datetime('now')),
    UNIQUE(board_id, email),
    FOREIGN KEY (board_id) REFERENCES mood_boards(id) ON DELETE CASCADE
);

-- Mood board client links
CREATE TABLE IF NOT EXISTS mood_board_client_links (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    remote_id INTEGER UNIQUE,
    client_id INTEGER NOT NULL,
    mood_board_id INTEGER NOT NULL,
    linked_at TEXT DEFAULT (datetime('now')),
    UNIQUE(client_id, mood_board_id),
    FOREIGN KEY (mood_board_id) REFERENCES mood_boards(id) ON DELETE CASCADE
);

-- Mood board image set items
CREATE TABLE IF NOT EXISTS mood_board_image_set_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    remote_id INTEGER UNIQUE,
    item_id INTEGER NOT NULL,
    image_url TEXT NOT NULL,
    thumbnail_url TEXT,
    drive_file_id INTEGER,
    original_filename TEXT,
    file_size INTEGER,
    width_px INTEGER,
    height_px INTEGER,
    position INTEGER DEFAULT 0,
    created_at TEXT DEFAULT (datetime('now')),
    FOREIGN KEY (item_id) REFERENCES mood_board_items(id) ON DELETE CASCADE
);

-- Mood board uploads
CREATE TABLE IF NOT EXISTS mood_board_uploads (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    remote_id INTEGER UNIQUE,
    board_id INTEGER NOT NULL,
    item_id INTEGER,
    original_filename TEXT NOT NULL,
    stored_filename TEXT NOT NULL,
    file_path TEXT NOT NULL,
    mime_type TEXT,
    file_size INTEGER DEFAULT 0,
    width_px INTEGER,
    height_px INTEGER,
    uploaded_by TEXT,
    created_at TEXT DEFAULT (datetime('now')),
    FOREIGN KEY (board_id) REFERENCES mood_boards(id) ON DELETE CASCADE
);

-- Mood board group access
CREATE TABLE IF NOT EXISTS mood_board_group_access (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    remote_id INTEGER UNIQUE,
    board_id INTEGER NOT NULL,
    group_id INTEGER NOT NULL,
    role TEXT DEFAULT 'editor',
    granted_by TEXT NOT NULL,
    created_at TEXT DEFAULT (datetime('now')),
    UNIQUE(board_id, group_id),
    FOREIGN KEY (board_id) REFERENCES mood_boards(id) ON DELETE CASCADE
);

-- Mood board board links (bidirectional)
CREATE TABLE IF NOT EXISTS mood_board_board_links (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    remote_id INTEGER UNIQUE,
    mood_board_id INTEGER NOT NULL,
    kanban_board_id INTEGER NOT NULL,
    linked_by TEXT NOT NULL,
    created_at TEXT DEFAULT (datetime('now')),
    UNIQUE(mood_board_id, kanban_board_id),
    FOREIGN KEY (mood_board_id) REFERENCES mood_boards(id) ON DELETE CASCADE
);

-- Mood board saved components (reusable blocks)
CREATE TABLE IF NOT EXISTS mood_board_components (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    remote_id INTEGER UNIQUE,
    owner_email TEXT NOT NULL,
    name TEXT NOT NULL DEFAULT 'Untitled Component',
    description TEXT,
    thumbnail_url TEXT,
    items_data TEXT NOT NULL,             -- JSON
    is_global INTEGER DEFAULT 0,
    category TEXT DEFAULT 'custom',
    sync_status TEXT DEFAULT 'synced',
    created_at TEXT DEFAULT (datetime('now')),
    updated_at TEXT
);

-- Mood board user palettes
CREATE TABLE IF NOT EXISTS mood_board_user_palettes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    remote_id INTEGER UNIQUE,
    email TEXT NOT NULL,
    name TEXT NOT NULL DEFAULT 'Untitled Palette',
    colors TEXT,                          -- JSON
    gradients TEXT,                       -- JSON
    is_shared INTEGER DEFAULT 0,
    sync_status TEXT DEFAULT 'synced',
    created_at TEXT DEFAULT (datetime('now')),
    updated_at TEXT
);

-- Mood board share view analytics
CREATE TABLE IF NOT EXISTS mood_board_share_views (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    remote_id INTEGER UNIQUE,
    board_id INTEGER NOT NULL,
    session_id TEXT NOT NULL,
    visitor_ip TEXT,
    user_agent TEXT,
    referrer TEXT,
    device_type TEXT DEFAULT 'unknown',
    browser TEXT,
    os TEXT,
    duration_seconds INTEGER DEFAULT 0,
    slides_viewed INTEGER DEFAULT 0,
    started_at TEXT DEFAULT (datetime('now')),
    last_heartbeat_at TEXT,
    FOREIGN KEY (board_id) REFERENCES mood_boards(id) ON DELETE CASCADE
);

-- ============================================
-- PORTAL TABLES (cached from cloud)
-- ============================================

-- Portal access (which contacts can log in)
CREATE TABLE IF NOT EXISTS portal_access (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    remote_id INTEGER UNIQUE,
    client_id INTEGER NOT NULL,
    contact_id INTEGER,
    email TEXT NOT NULL,
    name TEXT,
    is_active INTEGER DEFAULT 1,
    last_login_at TEXT,
    session_count INTEGER DEFAULT 0,
    created_by TEXT NOT NULL,
    sync_status TEXT DEFAULT 'synced',
    created_at TEXT DEFAULT (datetime('now')),
    updated_at TEXT,
    UNIQUE(client_id, email)
);

-- Portal updates (pushed to clients)
CREATE TABLE IF NOT EXISTS portal_updates (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    remote_id INTEGER UNIQUE,
    client_id INTEGER NOT NULL,
    created_by TEXT NOT NULL,
    title TEXT NOT NULL,
    content_html TEXT,
    content_text TEXT,
    update_type TEXT DEFAULT 'general',  -- general, design, milestone, deliverable
    mood_board_id INTEGER,
    mood_board_share_token TEXT,
    drive_file_ids TEXT,                 -- JSON
    board_id INTEGER,
    board_card_id INTEGER,
    is_pinned INTEGER DEFAULT 0,
    sync_status TEXT DEFAULT 'synced',
    created_at TEXT DEFAULT (datetime('now')),
    updated_at TEXT
);

CREATE INDEX IF NOT EXISTS idx_portal_updates_client ON portal_updates(client_id);

-- Portal comments
CREATE TABLE IF NOT EXISTS portal_comments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    remote_id INTEGER UNIQUE,
    update_id INTEGER NOT NULL,
    author_type TEXT NOT NULL,           -- internal, portal
    author_email TEXT NOT NULL,
    author_name TEXT,
    content_text TEXT NOT NULL,
    parent_comment_id INTEGER,
    sync_status TEXT DEFAULT 'synced',
    created_at TEXT DEFAULT (datetime('now')),
    updated_at TEXT,
    FOREIGN KEY (update_id) REFERENCES portal_updates(id) ON DELETE CASCADE
);

-- Portal documents
CREATE TABLE IF NOT EXISTS portal_documents (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    remote_id INTEGER UNIQUE,
    client_id INTEGER NOT NULL,
    created_by TEXT NOT NULL,
    title TEXT NOT NULL,
    description TEXT,
    document_type TEXT NOT NULL,          -- contract, invoice, proposal, quote, nda, agreement, receipt, other
    status TEXT DEFAULT 'draft',          -- draft, sent, viewed, signing, signed, rejected, expired, archived
    filename TEXT NOT NULL,
    original_name TEXT NOT NULL,
    mime_type TEXT,
    file_size INTEGER DEFAULT 0,
    file_path TEXT NOT NULL,
    drive_file_id INTEGER,
    signing_method TEXT DEFAULT 'both',
    requires_all_signers INTEGER DEFAULT 1,
    signing_deadline TEXT,
    amount REAL,
    currency TEXT DEFAULT 'HUF',
    reference_number TEXT,
    version INTEGER DEFAULT 1,
    parent_document_id INTEGER,
    viewed_at TEXT,
    completed_at TEXT,
    reminder_sent_at TEXT,
    sync_status TEXT DEFAULT 'synced',
    created_at TEXT DEFAULT (datetime('now')),
    updated_at TEXT
);

CREATE INDEX IF NOT EXISTS idx_portal_documents_client ON portal_documents(client_id);
CREATE INDEX IF NOT EXISTS idx_portal_documents_status ON portal_documents(status);

-- Portal document signers
CREATE TABLE IF NOT EXISTS portal_document_signers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    remote_id INTEGER UNIQUE,
    document_id INTEGER NOT NULL,
    portal_access_id INTEGER,
    signer_email TEXT NOT NULL,
    signer_name TEXT,
    status TEXT DEFAULT 'pending',       -- pending, signed, rejected
    signed_at TEXT,
    signature_type TEXT,
    uploaded_file_path TEXT,
    uploaded_filename TEXT,
    signature_data TEXT,
    signature_ip TEXT,
    rejection_reason TEXT,
    reminder_count INTEGER DEFAULT 0,
    last_reminder_at TEXT,
    sign_order INTEGER DEFAULT 0,
    created_at TEXT DEFAULT (datetime('now')),
    UNIQUE(document_id, signer_email),
    FOREIGN KEY (document_id) REFERENCES portal_documents(id) ON DELETE CASCADE
);

-- Portal calls
CREATE TABLE IF NOT EXISTS portal_calls (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    remote_id INTEGER UNIQUE,
    client_id INTEGER NOT NULL,
    created_by TEXT NOT NULL,
    room_name TEXT NOT NULL UNIQUE,
    call_type TEXT DEFAULT 'instant',    -- instant, scheduled
    status TEXT DEFAULT 'waiting',       -- waiting, active, ended
    scheduled_at TEXT,
    started_at TEXT,
    ended_at TEXT,
    duration_seconds INTEGER DEFAULT 0,
    had_screen_share INTEGER DEFAULT 0,
    notes TEXT,
    sync_status TEXT DEFAULT 'synced',
    created_at TEXT DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_portal_calls_client ON portal_calls(client_id);
CREATE INDEX IF NOT EXISTS idx_portal_calls_status ON portal_calls(status);

-- Portal update files
CREATE TABLE IF NOT EXISTS portal_update_files (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    remote_id INTEGER UNIQUE,
    update_id INTEGER NOT NULL,
    filename TEXT NOT NULL,
    original_name TEXT NOT NULL,
    mime_type TEXT,
    file_size INTEGER DEFAULT 0,
    drive_file_id INTEGER,
    created_at TEXT DEFAULT (datetime('now')),
    FOREIGN KEY (update_id) REFERENCES portal_updates(id) ON DELETE CASCADE
);

-- Insert default sync state entries
INSERT OR IGNORE INTO sync_state (entity_type, sync_cursor, last_sync_at) VALUES
    ('email', NULL, NULL),
    ('calendar', NULL, NULL),
    ('board', NULL, NULL),
    ('client', NULL, NULL),
    ('todo', NULL, NULL),
    ('colleague', NULL, NULL),
    ('mailing_list', NULL, NULL),
    ('campaign', NULL, NULL),
    ('chat', NULL, NULL),
    ('device', NULL, NULL),
    ('template', NULL, NULL),
    ('crm', NULL, NULL),
    ('mood_board', NULL, NULL),
    ('portal', NULL, NULL);

