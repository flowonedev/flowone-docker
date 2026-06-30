import { ref } from 'vue'
import api from '@/services/api'

const windows = ref([])

let _sendAddressesCache = []

async function fetchSendAddresses() {
  try {
    const response = await api.get('/accounts/send-addresses')
    if (response.data.success) {
      _sendAddressesCache = response.data.data.addresses
      return _sendAddressesCache
    }
  } catch (e) {
    const primaryEmail = localStorage.getItem('webmail_email')
    _sendAddressesCache = [{ email: primaryEmail, name: null, is_primary: true }]
  }
  return _sendAddressesCache
}

export function getSendAddresses() {
  return _sendAddressesCache
}

function formatPlainTextToHtml(text) {
  if (!text) return '<p><br></p>'
  return text.split(/\n\n+/).map(p => `<p>${p.replace(/\n/g, '<br>')}</p>`).join('')
}

function getSignatureForAccount(win, settingsStore) {
  if (win.fromAddress && !win.fromAddress.is_primary && win.fromAddress.signature) {
    const sig = win.fromAddress.signature
    if (sig && sig.trim() !== '') return `<p><br></p><p>--</p>${sig}`
    return ''
  }
  return settingsStore.getSignatureHtml()
}

function hasWindowContent(win) {
  const bodyChanged = win.draft.body.trim() !== '' && win.draft.body.trim() !== win.initialBody.trim()
  return (
    win.draft.to.length > 0 || win.draft.cc.length > 0 || win.draft.bcc.length > 0 ||
    win.draft.subject.trim() !== '' || bodyChanged || win.draft.attachments.length > 0
  )
}

function buildDriveLinksSection(attachments) {
  const driveAttachments = attachments.filter(a => a.driveLink)
  if (driveAttachments.length === 0) return ''
  const formatSize = (bytes) => {
    if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(2) + ' GB'
    if (bytes >= 1048576) return (bytes / 1048576).toFixed(2) + ' MB'
    if (bytes >= 1024) return (bytes / 1024).toFixed(2) + ' KB'
    return bytes + ' B'
  }
  let html = `<div style="margin-top:24px;padding-top:20px;border-top:1px solid #e9d5ff;"><table cellpadding="0" cellspacing="0" border="0" width="100%"><tr><td style="padding-bottom:12px;font-size:11px;font-weight:600;color:#7c3aed;text-transform:uppercase;letter-spacing:0.5px;">DRIVE ATTACHMENTS (${driveAttachments.length})</td></tr></table>`
  for (const att of driveAttachments) {
    const size = formatSize(att.size)
    html += `<table cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:8px;"><tr><td><table cellpadding="0" cellspacing="0" border="0" style="max-width:420px;background-color:#faf5ff;border:1px solid #e9d5ff;border-radius:12px;"><tr><td style="padding:12px 16px;"><table cellpadding="0" cellspacing="0" border="0" width="100%"><tr><td width="48" valign="middle"><table cellpadding="0" cellspacing="0" border="0"><tr><td style="width:40px;height:40px;background-color:#ede9fe;border-radius:8px;text-align:center;vertical-align:middle;"><a href="${att.url}" target="_blank" style="color:#7c3aed;font-size:18px;line-height:40px;text-decoration:none;display:inline-block;width:40px;height:40px;">&#9729;</a></td></tr></table></td><td style="padding-left:12px;" valign="middle"><a href="${att.url}" target="_blank" style="text-decoration:none;color:inherit;"><span style="color:#1f2937;font-size:14px;font-weight:500;word-break:break-word;">${att.name}</span><br><span style="color:#6b7280;font-size:12px;">${size}</span>${att.willExpire ? '<span style="display:inline-block;margin-left:8px;color:#d97706;font-size:11px;">&#9201; 7d</span>' : ''}<span style="display:inline-block;margin-left:8px;color:#7c3aed;font-size:11px;">&#9729; Drive</span></a></td><td width="90" valign="middle" style="text-align:right;"><a href="${att.url}" target="_blank" style="display:inline-block;padding:8px 16px;background-color:#7c3aed;color:#ffffff;font-size:12px;font-weight:600;text-decoration:none;border-radius:6px;line-height:1;">Download</a></td></tr></table></td></tr></table></td></tr></table>`
  }
  html += '</div>'
  return html
}

async function trackEvent(eventType, eventData = {}) {
  try { await api.post('/statistics/log-event', { event_type: eventType, event_data: eventData }) } catch (e) { /* silent */ }
}

// --- Public API ---

export function getWindows() {
  return windows
}

export async function openWindow(modeType = 'new', original = null, options = {}, settingsStore, accountsStore) {
  if (!settingsStore.loaded) await settingsStore.fetchSettings()
  const addresses = await fetchSendAddresses()

  const win = {
    id: Date.now() + Math.random(),
    isMinimized: false,
    mode: modeType,
    draft: { to: [], cc: [], bcc: [], subject: '', body: '', attachments: [], important: false },
    draftUid: null,
    originalMessage: original,
    sending: false,
    saving: false,
    hasUserEdits: false,
    initialBody: '',
    fromAddress: null,
    forceCampaign: options.forceCampaign || false,
    campaignDraftId: options.campaignDraftId || null,
    autoSaveIntervalId: null,
  }

  const activeAccountId = accountsStore.activeAccountId
  if (activeAccountId && activeAccountId !== 'primary') {
    const match = addresses.find(a => a.account_id === parseInt(activeAccountId) && !a.is_primary)
    win.fromAddress = match || addresses.find(a => a.is_primary) || addresses[0] || null
  } else {
    win.fromAddress = addresses.find(a => a.is_primary) || addresses[0] || null
  }

  if (modeType === 'new') {
    if (!options.forceCampaign) {
      const sig = getSignatureForAccount(win, settingsStore)
      if (sig) win.draft.body = `<p><br></p>${sig}`
    }
    if (options.preload) {
      win.draft.subject = options.preload.subject || ''
      win.draft.body = options.preload.body || ''
      win.draft.attachments = options.preload.attachments || []
    }
  } else if (modeType === 'reply' || modeType === 'replyAll') {
    setupReply(win, original, modeType === 'replyAll', addresses, settingsStore)
  } else if (modeType === 'forward') {
    setupForward(win, original, settingsStore)
  }

  win.initialBody = win.draft.body
  windows.value.push(win)
  return win
}

export function openWindowDraft(draftMessage) {
  const ensureArr = (val) => {
    if (!val) return []
    if (Array.isArray(val)) return val
    if (typeof val === 'string') return val.split(/,\s*/).filter(e => e.trim()).map(email => ({ email: email.trim(), name: '', display: email.trim() }))
    return []
  }
  const win = {
    id: Date.now() + Math.random(),
    isMinimized: false,
    mode: 'draft',
    draft: {
      to: ensureArr(draftMessage.to),
      cc: ensureArr(draftMessage.cc),
      bcc: ensureArr(draftMessage.bcc),
      subject: draftMessage.subject || '',
      body: draftMessage.body_html || draftMessage.body_text || '',
      attachments: (Array.isArray(draftMessage.attachments) ? draftMessage.attachments : []).map(a => ({ ...a, name: a.name || a.filename || 'attachment' })),
      important: !!draftMessage.important,
    },
    draftUid: draftMessage.uid,
    originalMessage: draftMessage,
    sending: false,
    saving: false,
    hasUserEdits: true,
    initialBody: '',
    fromAddress: null,
    forceCampaign: false,
    campaignDraftId: null,
    autoSaveIntervalId: null,
  }
  win.fromAddress = _sendAddressesCache.find(a => a.is_primary) || _sendAddressesCache[0] || null
  windows.value.push(win)
  startWindowAutoSave(win.id)
  return win
}

export async function closeWindow(windowId, saveOnClose = true) {
  const win = windows.value.find(w => w.id === windowId)
  if (!win) return
  if (saveOnClose && win.hasUserEdits && hasWindowContent(win)) {
    await saveDraftForWindow(windowId)
  }
  if (win.autoSaveIntervalId) {
    clearInterval(win.autoSaveIntervalId)
    win.autoSaveIntervalId = null
  }
  windows.value = windows.value.filter(w => w.id !== windowId)
}

export async function sendFromWindow(windowId, settingsStore) {
  const win = windows.value.find(w => w.id === windowId)
  if (!win || win.sending) return { success: false }
  if (win.draft.to.length === 0) return { success: false, error: 'Please add at least one recipient' }

  win.sending = true
  try {
    let bodyHtml = win.draft.body
    const driveSection = buildDriveLinksSection(win.draft.attachments)
    if (driveSection) bodyHtml = driveSection + bodyHtml

    // Only move files freshly uploaded for this email; keep files the user
    // picked from their existing Drive (fromDrive) where they already are.
    const driveFileIds = win.draft.attachments.filter(a => a.driveLink && a.fileId && !a.fromDrive).map(a => a.fileId)
    const payload = {
      to: win.draft.to, cc: win.draft.cc, bcc: win.draft.bcc,
      subject: win.draft.subject, body_html: bodyHtml,
      attachments: win.draft.attachments.filter(a => !a.driveLink),
      drive_file_ids: driveFileIds.length > 0 ? driveFileIds : undefined,
      draft_uid: win.draftUid,
      in_reply_to: win.originalMessage?.message_id,
      references: win.originalMessage?.references,
      from_name: win.fromAddress?.name || settingsStore.settings.display_name || '',
      important: win.draft.important,
    }
    if (win.fromAddress && !win.fromAddress.is_primary) {
      payload.from_account_id = win.fromAddress.account_id
      payload.from_email = win.fromAddress.email
    }

    const undoDelay = settingsStore.settings.undo_send_delay || 0
    if (undoDelay > 0) {
      const scheduledAt = new Date(Date.now() + undoDelay * 1000 + 5000).toISOString()
      const resp = await api.post('/messages/schedule', {
        ...payload, scheduled_at: scheduledAt,
        timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
        schedule_kind: 'undo_send',
      })
      if (resp.data.success) {
        await closeWindow(windowId, false)
        return { success: true, undoSend: true, schedule_id: resp.data.data.schedule_id, delay: undoDelay }
      }
      return { success: false, error: resp.data.message }
    }

    const response = await api.post('/messages/send', payload)
    if (response.data.success) {
      const allRecipients = [...win.draft.to, ...win.draft.cc, ...win.draft.bcc].map(r => r.email)
      trackEvent('email_sent', { to: allRecipients, subject: win.draft.subject, has_attachments: win.draft.attachments.length > 0, is_reply: win.mode === 'reply' || win.mode === 'replyAll', is_forward: win.mode === 'forward' })

      const { useMailboxStore } = await import('@/stores/mailbox')
      const mailbox = useMailboxStore()
      if (win.draftUid) mailbox.removeMessageFromList(win.draftUid)
      setTimeout(async () => { await mailbox.fetchFolders(true); await mailbox.refreshCurrentFolder() }, 300)

      await closeWindow(windowId, false)
      return { success: true }
    }
    return { success: false, error: response.data.message }
  } catch (e) {
    return { success: false, error: e.response?.data?.message || 'Failed to send email' }
  } finally {
    win.sending = false
  }
}

export async function scheduleSendFromWindow(windowId, scheduledTime) {
  const win = windows.value.find(w => w.id === windowId)
  if (!win || win.sending) return { success: false }
  if (win.draft.to.length === 0) return { success: false, error: 'Please add at least one recipient' }

  win.sending = true
  try {
    let bodyHtml = win.draft.body
    const driveSection = buildDriveLinksSection(win.draft.attachments)
    if (driveSection) bodyHtml = driveSection + bodyHtml

    // Only move files freshly uploaded for this email; keep files the user
    // picked from their existing Drive (fromDrive) where they already are.
    const driveFileIds = win.draft.attachments.filter(a => a.driveLink && a.fileId && !a.fromDrive).map(a => a.fileId)
    const { useSettingsStore } = await import('@/stores/settings')
    const settingsStore = useSettingsStore()
    const payload = {
      to: win.draft.to, cc: win.draft.cc, bcc: win.draft.bcc,
      subject: win.draft.subject, body_html: bodyHtml,
      attachments: win.draft.attachments.filter(a => !a.driveLink),
      drive_file_ids: driveFileIds.length > 0 ? driveFileIds : undefined,
      draft_uid: win.draftUid,
      in_reply_to: win.originalMessage?.message_id,
      references: win.originalMessage?.references,
      from_name: win.fromAddress?.name || settingsStore.settings.display_name || '',
      important: win.draft.important,
      scheduled_at: scheduledTime,
      timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
      schedule_kind: 'scheduled_send',
    }
    if (win.fromAddress && !win.fromAddress.is_primary) {
      payload.from_account_id = win.fromAddress.account_id
      payload.from_email = win.fromAddress.email
    }

    const response = await api.post('/messages/schedule', payload)
    if (response.data.success) {
      await closeWindow(windowId, false)
      try {
        const { useMailboxStore } = await import('@/stores/mailbox')
        useMailboxStore().refreshScheduledCount()
      } catch (e) { /* silent */ }
      return { success: true, schedule_id: response.data.data.schedule_id }
    }
    return { success: false, error: response.data.message }
  } catch (e) {
    return { success: false, error: e.response?.data?.message || 'Failed to schedule email' }
  } finally {
    win.sending = false
  }
}

export async function saveDraftForWindow(windowId) {
  const win = windows.value.find(w => w.id === windowId)
  if (!win || win.saving) return false
  win.saving = true
  try {
    const response = await api.post('/messages/draft', {
      to: win.draft.to, cc: win.draft.cc,
      subject: win.draft.subject, body_html: win.draft.body,
      attachments: win.draft.attachments.filter(a => !a.fromOriginal),
      draft_uid: win.draftUid,
      important: win.draft.important,
    })
    if (response.data.success && response.data.data?.uid) {
      win.draftUid = response.data.data.uid
      const { useMailboxStore } = await import('@/stores/mailbox')
      useMailboxStore().fetchFolders(true)
    }
    return true
  } catch (e) {
    console.error('Failed to save window draft:', e)
    return false
  } finally {
    win.saving = false
  }
}

export async function uploadAttachmentToWindow(windowId, file, settingsStore) {
  const win = windows.value.find(w => w.id === windowId)
  if (!win) return { success: false }
  const thresholdMB = settingsStore.settings.large_attachment_threshold ?? 10
  const thresholdBytes = thresholdMB * 1024 * 1024
  if (thresholdBytes > 0 && file.size > thresholdBytes) {
    const { useDriveStore } = await import('@/stores/drive')
    const drive = useDriveStore()
    const result = await drive.uploadAndShare(file, 2160)
    if (!result.success) return { success: false, error: result.error || 'Failed to upload large file' }
    win.draft.attachments.push({ name: file.name, size: file.size, driveLink: true, url: result.url, fileId: result.file.id, willExpire: true })
    return { success: true, uploadedToDrive: true }
  }
  const formData = new FormData()
  formData.append('file', file)
  try {
    const response = await api.post('/attachments/upload', formData, { headers: { 'Content-Type': 'multipart/form-data' } })
    if (response.data.success) {
      win.draft.attachments.push(response.data.data)
      return { success: true, data: response.data.data }
    }
    return { success: false }
  } catch (e) {
    return { success: false, error: e.response?.data?.message || 'Upload failed' }
  }
}

export function minimizeWindow(id) {
  const win = windows.value.find(w => w.id === id)
  if (win) win.isMinimized = true
}

export function maximizeWindow(id) {
  const win = windows.value.find(w => w.id === id)
  if (win) win.isMinimized = false
}

export function markWindowAsEdited(id) {
  const win = windows.value.find(w => w.id === id)
  if (!win || win.hasUserEdits) return
  win.hasUserEdits = true
  startWindowAutoSave(id)
}

function startWindowAutoSave(id) {
  const win = windows.value.find(w => w.id === id)
  if (!win) return
  if (win.autoSaveIntervalId) clearInterval(win.autoSaveIntervalId)
  win.autoSaveIntervalId = setInterval(() => {
    if (win.hasUserEdits && hasWindowContent(win)) saveDraftForWindow(id)
  }, 10000)
}

export function addWindowRecipient(id, type, recipient) {
  const win = windows.value.find(w => w.id === id)
  if (!win) return
  if (!win.draft[type].find(r => r.email === recipient.email)) win.draft[type].push(recipient)
}

export function removeWindowRecipient(id, type, email) {
  const win = windows.value.find(w => w.id === id)
  if (!win) return
  win.draft[type] = win.draft[type].filter(r => r.email !== email)
}

export function removeWindowAttachment(id, index) {
  const win = windows.value.find(w => w.id === id)
  if (!win) return
  win.draft.attachments.splice(index, 1)
}

export function setWindowFromAddress(id, address, settingsStore) {
  const win = windows.value.find(w => w.id === id)
  if (!win) return
  const oldAddress = win.fromAddress
  win.fromAddress = address
  if (oldAddress?.email !== address?.email && win.draft.body) {
    const signaturePattern = /<p><br><\/p><p>--<\/p>[\s\S]*$/i
    let bodyWithoutSig = win.draft.body.replace(signaturePattern, '')
    if (bodyWithoutSig === win.draft.body) {
      const idx = win.draft.body.lastIndexOf('<p>--</p>')
      if (idx > 0) {
        const before = win.draft.body.substring(0, idx)
        const lb = before.lastIndexOf('<p><br></p>')
        if (lb >= 0) bodyWithoutSig = win.draft.body.substring(0, lb)
      }
    }
    const newSig = getSignatureForAccount(win, settingsStore)
    win.draft.body = bodyWithoutSig + (newSig || '')
  }
}

// --- Setup helpers ---

function setupReply(win, original, replyAll, addresses, settingsStore) {
  if (!original) return
  const replyTo = original.reply_to?.[0] || original.from?.[0]
  win.draft.to = replyTo ? [replyTo] : []

  // Resolve From identity FIRST so the reply-all CC filter below knows
  // which address we're actually sending from. See stores/compose.js
  // setupReply for the full rationale.
  const recipientEmails = [
    ...(original.to || []),
    ...(original.cc || []),
  ]
    .map(r => r?.email?.toLowerCase())
    .filter(Boolean)
  const primaryAddr = addresses.find(a => a?.is_primary)
  const primaryReceived = !!(
    primaryAddr &&
    recipientEmails.includes(primaryAddr.email?.toLowerCase())
  )
  if (!primaryReceived) {
    const match = addresses.find(
      a => recipientEmails.includes(a.email?.toLowerCase()) && !a.is_primary
    )
    if (match) win.fromAddress = match
  }

  if (replyAll) {
    // Filter ONLY the currently-selected From identity from CC; keep
    // other linked accounts so the user's other identities still receive
    // a copy (Gmail/Outlook behavior). Dedup against draft.to to avoid
    // delivering multiple copies to the same recipient via the backend
    // send loop.
    const fromEmail = win.fromAddress?.email?.toLowerCase() || ''

    const toEmails = new Set(
      (win.draft.to || []).map(r => r?.email?.toLowerCase()).filter(Boolean)
    )

    const seen = new Set()
    const cc = []
    for (const r of [...(original.to || []), ...(original.cc || [])]) {
      const email = r?.email?.toLowerCase()
      if (!email) continue
      if (fromEmail && email === fromEmail) continue
      if (toEmails.has(email)) continue
      if (seen.has(email)) continue
      seen.add(email)
      cc.push(r)
    }
    win.draft.cc = cc
  }
  const subject = original.subject || ''
  win.draft.subject = subject.match(/^Re:/i) ? subject : `Re: ${subject}`
  const dateValue = typeof original.timestamp === 'string' ? new Date(original.timestamp) : new Date((original.timestamp || 0) * 1000)
  const date = isNaN(dateValue.getTime()) ? '' : dateValue.toLocaleString()
  const from = original.from?.[0]?.display || original.from?.[0]?.email || 'Unknown'
  const quotedBody = original.body_html || formatPlainTextToHtml(original.body_text)
  const signature = getSignatureForAccount(win, settingsStore)
  win.draft.body = `<p><br></p>${signature}<p><br></p><p>On ${date}, ${from} wrote:</p><blockquote>${quotedBody}</blockquote>`
}

function setupForward(win, original, settingsStore) {
  if (!original) return
  const subject = original.subject || ''
  win.draft.subject = subject.match(/^Fwd:/i) ? subject : `Fwd: ${subject}`
  const dateValue = typeof original.timestamp === 'string' ? new Date(original.timestamp) : new Date((original.timestamp || 0) * 1000)
  const date = isNaN(dateValue.getTime()) ? '' : dateValue.toLocaleString()
  const from = original.from?.[0]?.display || original.from?.[0]?.email || 'Unknown'
  const to = original.to?.map(r => r.display || r.email).join(', ') || ''
  const quotedBody = original.body_html || formatPlainTextToHtml(original.body_text)
  const signature = getSignatureForAccount(win, settingsStore)
  win.draft.body = `<p><br></p>${signature}<p><br></p><blockquote><p>---------- Forwarded message ---------</p><p>From: ${from}</p><p>Date: ${date}</p><p>To: ${to}</p><p>Subject: ${original.subject || '(No subject)'}</p><p><br></p>${quotedBody}</blockquote>`
  win.draft.attachments = original.attachments?.map(a => ({ ...a, fromOriginal: true, source_folder: original.folder, source_uid: original.uid })) || []
}
