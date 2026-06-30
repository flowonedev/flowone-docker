import { defineStore } from "pinia";
import { ref, computed } from "vue";
import api from "@/services/api";
import { useSettingsStore } from "./settings";
import { useAccountsStore } from "./accounts";
import { useLayoutStore } from "./layout";
import { useMailboxStore } from "./mailbox";
import { useConversationsStore } from "./conversations";
import * as windowService from "@/services/composeWindowService";

// Track events silently (non-blocking)
async function trackEvent(eventType, eventData = {}) {
  try {
    await api.post('/statistics/log-event', { event_type: eventType, event_data: eventData })
  } catch (e) {
    // Silent fail - don't disrupt main functionality
  }
}

function setAnsweredFlag(original) {
  const uid = original.uid;
  const folder = original.folder || 'INBOX';
  if (!uid) return;
  const mailbox = useMailboxStore();
  mailbox.setFlag(uid, 'answered', true, folder);
}

export const useComposeStore = defineStore("compose", () => {
  const settingsStore = useSettingsStore();
  const accountsStore = useAccountsStore();
  const layoutStore = useLayoutStore();

  function shouldUseInline() {
    return settingsStore.settings.compose_style === 'inline' && !layoutStore.isMobile;
  }

  // Get large attachment threshold from settings (in bytes)
  const largeAttachmentThreshold = computed(() => {
    const thresholdMB = settingsStore.settings.large_attachment_threshold ?? 10;
    return thresholdMB * 1024 * 1024; // Convert MB to bytes
  });
  const isOpen = ref(false);
  const isMinimized = ref(false);
  const mode = ref("new"); // 'new', 'reply', 'replyAll', 'forward'
  const originalMessage = ref(null);
  const draftUid = ref(null);
  const sending = ref(false);
  const saving = ref(false);
  const forceCampaign = ref(false);
  const campaignDraftId = ref(null);
  
  // Track if user has made any edits (to avoid auto-saving empty reply/forward)
  const hasUserEdits = ref(false);
  
  // Store the initial body content (signature/quoted text) to detect if user actually changed anything
  const initialBody = ref("");

  // Available send addresses (primary + linked/separate accounts)
  const sendAddresses = ref([]);
  // Selected "from" address (null = use primary/default)
  const fromAddress = ref(null);

  const draft = ref({
    to: [],
    cc: [],
    bcc: [],
    subject: "",
    body: "",
    attachments: [],
    important: false,
  });

  const autoSaveInterval = ref(null);

  const windows = windowService.getWindows();

  async function open(modeType = "new", original = null, options = {}) {
    if (shouldUseInline()) {
      await windowService.openWindow(modeType, original, options, settingsStore, accountsStore);
      return;
    }
    mode.value = modeType;
    originalMessage.value = original;
    draftUid.value = null;

    // Ensure settings are loaded for signature
    if (!settingsStore.loaded) {
      await settingsStore.fetchSettings();
    }

    // Fetch available send addresses
    await fetchSendAddresses();

    // Set from address based on current active account
    const activeAccountId = accountsStore.activeAccountId;
    if (activeAccountId && activeAccountId !== "primary") {
      // Find the matching send address for the active account
      const activeAddress = sendAddresses.value.find(
        (a) => a.account_id === parseInt(activeAccountId) && !a.is_primary
      );
      fromAddress.value =
        activeAddress ||
        sendAddresses.value.find((a) => a.is_primary) ||
        sendAddresses.value[0] ||
        null;
    } else {
      // Use primary account
      fromAddress.value =
        sendAddresses.value.find((a) => a.is_primary) ||
        sendAddresses.value[0] ||
        null;
    }

    if (modeType === "new") {
      resetDraft();
      forceCampaign.value = !!options.forceCampaign;
      campaignDraftId.value = options.campaignDraftId || null;
      
      if (options.preload) {
        draft.value.subject = options.preload.subject || '';
        draft.value.body = options.preload.body || '';
        draft.value.attachments = options.preload.attachments || [];
      }
      
      if (!options.forceCampaign) {
        applySignature();
      }
    } else if (modeType === "reply" || modeType === "replyAll") {
      setupReply(original, modeType === "replyAll");
    } else if (modeType === "forward") {
      setupForward(original);
    }

    // Store the initial body (signature/quoted text) so we can detect real changes
    initialBody.value = draft.value.body;
    
    isOpen.value = true;
    isMinimized.value = false;
    
    // Don't mark as edited until user actually makes changes
    // Auto-save will start when markAsEdited() is called
    hasUserEdits.value = false;
  }

  async function fetchSendAddresses() {
    try {
      const response = await api.get("/accounts/send-addresses");
      if (response.data.success) {
        sendAddresses.value = response.data.data.addresses;
      }
    } catch (e) {
      console.error("Failed to fetch send addresses:", e);
      // Fallback to just primary email
      const primaryEmail = localStorage.getItem("webmail_email");
      sendAddresses.value = [
        {
          email: primaryEmail,
          name: null,
          is_primary: true,
        },
      ];
    }
  }

  function openDraft(draftMessage) {
    if (shouldUseInline()) {
      windowService.openWindowDraft(draftMessage);
      return;
    }
    mode.value = "draft";
    originalMessage.value = draftMessage;
    draftUid.value = draftMessage.uid;

    // Helper to ensure value is always an array of recipient objects
    const ensureRecipientArray = (val) => {
      if (!val) return [];
      if (Array.isArray(val)) return val;
      // If it's a string, try to parse as email addresses
      if (typeof val === 'string') {
        return val.split(/,\s*/).filter(e => e.trim()).map(email => ({
          email: email.trim(),
          name: '',
          display: email.trim()
        }));
      }
      return [];
    };

    // Populate draft from the message
    draft.value = {
      to: ensureRecipientArray(draftMessage.to),
      cc: ensureRecipientArray(draftMessage.cc),
      bcc: ensureRecipientArray(draftMessage.bcc),
      subject: draftMessage.subject || "",
      body: draftMessage.body_html || draftMessage.body_text || "",
      attachments: (Array.isArray(draftMessage.attachments) ? draftMessage.attachments : [])
        .map(a => ({ ...a, name: a.name || a.filename || 'attachment' })),
      important: !!draftMessage.important,
    };

    isOpen.value = true;
    isMinimized.value = false;
    hasUserEdits.value = true; // Editing existing draft = user has made edits before
    startAutoSave();
  }

  async function close(saveOnClose = true) {
    // Save draft before closing only if user has made edits
    if (saveOnClose && hasUserEdits.value && hasContent()) {
      await saveDraft();
    }

    isOpen.value = false;
    isMinimized.value = false;
    stopAutoSave();
    resetDraft();
  }

  function resetDraft() {
    draft.value = {
      to: [],
      cc: [],
      bcc: [],
      subject: "",
      body: "",
      attachments: [],
      important: false,
    };
    draftUid.value = null;
    originalMessage.value = null;
    hasUserEdits.value = false;
    initialBody.value = "";
    forceCampaign.value = false;
    campaignDraftId.value = null;
  }

  function applySignature() {
    // Get signature for current from address (account-specific if available)
    const signature = getSignatureForCurrentAccount();
    if (signature) {
      draft.value.body = `<p><br></p>${signature}`;
    }
  }

  // Wrap a signature block so the compose editor can collapse/expand it.
  // The SignatureExtension keeps this <div data-signature> wrapper intact
  // through TipTap editing round-trips.
  function wrapSignature(html) {
    if (!html) return "";
    return `<div data-signature="true">${html}</div>`;
  }

  // Get signature based on current from address (wrapped for collapse support)
  function getSignatureForCurrentAccount() {
    // If using a secondary account with its own signature, use that
    if (
      fromAddress.value &&
      !fromAddress.value.is_primary &&
      fromAddress.value.signature
    ) {
      const sig = fromAddress.value.signature;
      if (sig && sig.trim() !== "") {
        return wrapSignature(`<p><br></p><p>--</p>${sig}`);
      }
      return ""; // Secondary account with no signature set - don't use primary's
    }

    // If primary account or no account-specific signature, use global settings
    return wrapSignature(settingsStore.getSignatureHtml());
  }

  function setupReply(original, replyAll = false) {
    if (!original) return;

    // Set recipient
    const replyTo = original.reply_to?.[0] || original.from?.[0];
    draft.value.to = replyTo ? [replyTo] : [];

    // Resolve the From identity FIRST so the reply-all filter below knows
    // which address we're actually sending from. Without this the CC
    // filter ran against the pre-selected From (often the primary login),
    // not the address we're about to send as.
    //
    // Only auto-switch From to a linked account if the primary login did
    // NOT appear among the original recipients. If both primary and a
    // linked address were in To/Cc, keep the primary (the inbox the user
    // is reading).
    const recipientEmails = [
      ...(original.to || []),
      ...(original.cc || []),
    ]
      .map((r) => r?.email?.toLowerCase())
      .filter(Boolean);
    const primaryAddr = sendAddresses.value.find((a) => a?.is_primary);
    const primaryReceived = !!(
      primaryAddr &&
      recipientEmails.includes(primaryAddr.email?.toLowerCase())
    );
    if (!primaryReceived) {
      const matchingAddress = sendAddresses.value.find(
        (a) =>
          recipientEmails.includes(a.email?.toLowerCase()) && !a.is_primary
      );
      if (matchingAddress) {
        fromAddress.value = matchingAddress;
      }
    }

    // Reply-all: include everyone else in CC.
    //
    // We deliberately filter ONLY the currently-selected From identity
    // (the address the reply will be sent as). Other linked accounts the
    // user owns stay in CC because the user often wants their other
    // identities to receive a copy - this matches Gmail/Outlook behavior.
    // The dedupe against draft.to still prevents the reply-target from
    // showing up in both To and Cc (which previously caused the backend
    // send loop to deliver multiple copies to the same recipient).
    if (replyAll) {
      const fromEmail = fromAddress.value?.email?.toLowerCase() || '';

      const toEmails = new Set(
        (draft.value.to || []).map((r) => r?.email?.toLowerCase()).filter(Boolean)
      );

      const seen = new Set();
      const otherRecipients = [];
      for (const r of [...(original.to || []), ...(original.cc || [])]) {
        const email = r?.email?.toLowerCase();
        if (!email) continue;
        if (fromEmail && email === fromEmail) continue;
        if (toEmails.has(email)) continue;
        if (seen.has(email)) continue;
        seen.add(email);
        otherRecipients.push(r);
      }
      draft.value.cc = otherRecipients;
    }

    // Subject
    const subject = original.subject || "";
    draft.value.subject = subject.match(/^Re:/i) ? subject : `Re: ${subject}`;

    // Quote original message
    const dateValue = typeof original.timestamp === 'string' 
      ? new Date(original.timestamp) 
      : new Date((original.timestamp || 0) * 1000);
    const date = isNaN(dateValue.getTime()) ? '' : dateValue.toLocaleString();
    const from =
      original.from?.[0]?.display || original.from?.[0]?.email || "Unknown";
    const quotedBody = original.body_html || formatPlainTextToHtml(original.body_text);

    // Get signature for current from address
    const signature = getSignatureForCurrentAccount();

    draft.value.body = `<p><br></p>${signature}<p><br></p><p>On ${date}, ${from} wrote:</p><blockquote>${quotedBody}</blockquote>`;
  }

  // Convert plain text body to proper HTML paragraphs
  function formatPlainTextToHtml(text) {
    if (!text) return '<p><br></p>';
    return text
      .split(/\n\n+/)
      .map(p => `<p>${p.replace(/\n/g, '<br>')}</p>`)
      .join('');
  }

  function setupForward(original) {
    if (!original) return;

    // Subject
    const subject = original.subject || "";
    draft.value.subject = subject.match(/^Fwd:/i) ? subject : `Fwd: ${subject}`;

    // Forward body
    const dateValue = typeof original.timestamp === 'string' 
      ? new Date(original.timestamp) 
      : new Date((original.timestamp || 0) * 1000);
    const date = isNaN(dateValue.getTime()) ? '' : dateValue.toLocaleString();
    const from =
      original.from?.[0]?.display || original.from?.[0]?.email || "Unknown";
    const to = original.to?.map((r) => r.display || r.email).join(", ") || "";
    const quotedBody = original.body_html || formatPlainTextToHtml(original.body_text);

    // Get signature for current from address
    const signature = getSignatureForCurrentAccount();

    draft.value.body = `<p><br></p>${signature}<p><br></p><blockquote><p>---------- Forwarded message ---------</p><p>From: ${from}</p><p>Date: ${date}</p><p>To: ${to}</p><p>Subject: ${original.subject || "(No subject)"}</p><p><br></p>${quotedBody}</blockquote>`;

    // Include attachments
    draft.value.attachments =
      original.attachments?.map((a) => ({
        ...a,
        fromOriginal: true,
        source_folder: original.folder,
        source_uid: original.uid,
      })) || [];
  }

  function startAutoSave() {
    stopAutoSave();
    autoSaveInterval.value = setInterval(() => {
      // Only auto-save if user has made edits and there's content
      if (hasUserEdits.value && hasContent()) {
        saveDraft();
      }
    }, 10000); // Auto-save every 10 seconds
  }

  function stopAutoSave() {
    if (autoSaveInterval.value) {
      clearInterval(autoSaveInterval.value);
      autoSaveInterval.value = null;
    }
  }
  
  function minimize() {
    isMinimized.value = true;
  }

  function maximize() {
    isMinimized.value = false;
  }

  function markAsEdited() {
    if (!hasUserEdits.value) {
      hasUserEdits.value = true;
      // Start auto-save now that user has started editing
      startAutoSave();
    }
  }

  function hasContent() {
    // Check if body has meaningful content beyond the initial signature/quoted text
    const bodyChanged = draft.value.body.trim() !== "" && draft.value.body.trim() !== initialBody.value.trim();
    
    return (
      draft.value.to.length > 0 ||
      draft.value.cc.length > 0 ||
      draft.value.bcc.length > 0 ||
      draft.value.subject.trim() !== "" ||
      bodyChanged ||
      draft.value.attachments.length > 0
    );
  }

  async function saveDraft() {
    if (saving.value) return;

    saving.value = true;
    try {
      const response = await api.post("/messages/draft", {
        to: draft.value.to,
        cc: draft.value.cc,
        subject: draft.value.subject,
        body_html: draft.value.body,
        attachments: draft.value.attachments.filter((a) => !a.fromOriginal),
        draft_uid: draftUid.value,
        important: draft.value.important,
      });

      if (response.data.success && response.data.data?.uid) {
        draftUid.value = response.data.data.uid;
        
        // Refresh folders to update counts
        const mailbox = useMailboxStore();
        mailbox.fetchFolders(true);
      }

      return true;
    } catch (e) {
      console.error("Failed to save draft:", e);
      return false;
    } finally {
      saving.value = false;
    }
  }

  // Scheduled send
  const scheduledAt = ref(null); // ISO date string when email should be sent
  const scheduleId = ref(null); // UUID from backend after scheduling

  async function scheduleSend(scheduledTime) {
    if (sending.value) return { success: false };
    if (draft.value.to.length === 0) {
      return { success: false, error: "Please add at least one recipient" };
    }

    sending.value = true;
    try {
      // Build drive links section
      let bodyHtml = draft.value.body;
      const driveLinksSection = buildDriveLinksSection();
      if (driveLinksSection) {
        bodyHtml = driveLinksSection + bodyHtml;
      }

      const driveFileIds = draft.value.attachments
        // Only files freshly uploaded FOR this email (not ones picked from the
        // user's existing Drive) should be reorganized into Attachments/Sent.
        // Picked files (fromDrive) stay exactly where the user put them.
        .filter(a => a.driveLink && a.fileId && !a.fromDrive)
        .map(a => a.fileId);

      const payload = {
        to: draft.value.to,
        cc: draft.value.cc,
        bcc: draft.value.bcc,
        subject: draft.value.subject,
        body_html: bodyHtml,
        attachments: draft.value.attachments.filter(a => !a.driveLink),
        drive_file_ids: driveFileIds.length > 0 ? driveFileIds : undefined,
        draft_uid: draftUid.value,
        in_reply_to: originalMessage.value?.message_id,
        references: originalMessage.value?.references,
        from_name: fromAddress.value?.name || settingsStore.settings.display_name || '',
        important: draft.value.important,
        scheduled_at: scheduledTime,
        timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
        schedule_kind: 'scheduled_send',
      };

      if (fromAddress.value && !fromAddress.value.is_primary) {
        payload.from_account_id = fromAddress.value.account_id;
        payload.from_email = fromAddress.value.email;
      }

      const response = await api.post("/messages/schedule", payload);
      if (response.data.success) {
        scheduleId.value = response.data.data.schedule_id;
        // scheduledTime is UTC (from toISOString) — mark with 'Z' for correct Date parsing
        const utcTime = scheduledTime && !scheduledTime.endsWith('Z') ? scheduledTime.replace(' ', 'T') + 'Z' : scheduledTime;
        scheduledAt.value = utcTime;
        close(false);
        // Refresh sidebar scheduled count
        try {
          const { useMailboxStore } = await import('@/stores/mailbox');
          useMailboxStore().refreshScheduledCount();
        } catch (e) { /* silent */ }
        return { success: true, schedule_id: response.data.data.schedule_id, scheduled_at: utcTime };
      } else {
        return { success: false, error: response.data.message };
      }
    } catch (e) {
      return { success: false, error: e.response?.data?.message || "Failed to schedule email" };
    } finally {
      sending.value = false;
    }
  }

  async function cancelScheduledEmail(id) {
    try {
      const response = await api.delete(`/messages/schedule/${id}`);
      if (response.data.success) {
        scheduleId.value = null;
        scheduledAt.value = null;
        // Refresh sidebar scheduled count
        try {
          const { useMailboxStore } = await import('@/stores/mailbox');
          useMailboxStore().refreshScheduledCount();
        } catch (e) { /* silent */ }
        return { success: true };
      }
      return { success: false, error: response.data.message };
    } catch (e) {
      return { success: false, error: e.response?.data?.message || "Failed to cancel" };
    }
  }

  async function getScheduledEmails() {
    try {
      const response = await api.get("/messages/scheduled");
      if (response.data.success) {
        return response.data.data.scheduled || [];
      }
      return [];
    } catch (e) {
      return [];
    }
  }

  async function send() {
    if (sending.value) return { success: false };

    if (draft.value.to.length === 0) {
      return { success: false, error: "Please add at least one recipient" };
    }

    sending.value = true;
    try {
      // Build the email body with drive links section at the TOP
      let bodyHtml = draft.value.body;
      const driveLinksSection = buildDriveLinksSection();
      if (driveLinksSection) {
        // Put Drive attachments at the TOP of the email, before the body content
        bodyHtml = driveLinksSection + bodyHtml;
      }
      
      // Collect Drive file IDs so backend can move them to Attachments/Sent/{Subject}/
      const driveFileIds = draft.value.attachments
        // Only files freshly uploaded FOR this email (not ones picked from the
        // user's existing Drive) should be reorganized into Attachments/Sent.
        // Picked files (fromDrive) stay exactly where the user put them.
        .filter(a => a.driveLink && a.fileId && !a.fromDrive)
        .map(a => a.fileId);

      const payload = {
        to: draft.value.to,
        cc: draft.value.cc,
        bcc: draft.value.bcc,
        subject: draft.value.subject,
        body_html: bodyHtml,
        attachments: draft.value.attachments.filter(a => !a.driveLink),
        drive_file_ids: driveFileIds.length > 0 ? driveFileIds : undefined,
        draft_uid: draftUid.value,
        in_reply_to: originalMessage.value?.message_id,
        references: originalMessage.value?.references,
        from_name: fromAddress.value?.name || settingsStore.settings.display_name || '',
        important: draft.value.important,
      };

      if (fromAddress.value && !fromAddress.value.is_primary) {
        payload.from_account_id = fromAddress.value.account_id;
        payload.from_email = fromAddress.value.email;
      }

      // Check undo-send delay setting
      const undoDelay = settingsStore.settings.undo_send_delay || 0;

      if (undoDelay > 0) {
        // Route through scheduled-email path with undo_send kind
        const scheduledAt = new Date(Date.now() + undoDelay * 1000 + 5000).toISOString();
        const schedulePayload = {
          ...payload,
          scheduled_at: scheduledAt,
          timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
          schedule_kind: 'undo_send',
        };

        const response = await api.post("/messages/schedule", schedulePayload);
        if (response.data.success) {
          if ((mode.value === 'reply' || mode.value === 'replyAll') && originalMessage.value) {
            setAnsweredFlag(originalMessage.value);
          }
          close(false);
          return {
            success: true,
            undoSend: true,
            schedule_id: response.data.data.schedule_id,
            delay: undoDelay,
          };
        } else {
          return { success: false, error: response.data.message };
        }
      }

      // Direct send (no delay)
      const response = await api.post("/messages/send", payload);

      if (response.data.success) {
        // Track email sent event
        const recipients = [
          ...draft.value.to.map(r => r.email),
          ...draft.value.cc.map(r => r.email),
          ...draft.value.bcc.map(r => r.email)
        ];
        trackEvent('email_sent', { 
          to: recipients,
          subject: draft.value.subject,
          has_attachments: draft.value.attachments.length > 0,
          is_reply: mode.value === 'reply' || mode.value === 'replyAll',
          is_forward: mode.value === 'forward'
        });
        
        // Track email replied event separately (for reply time statistics)
        if ((mode.value === 'reply' || mode.value === 'replyAll') && originalMessage.value) {
          const originalTimestamp = originalMessage.value.timestamp || 
            (originalMessage.value.date ? Math.floor(new Date(originalMessage.value.date).getTime() / 1000) : 0);
          const replyTimeSeconds = originalTimestamp > 0 
            ? Math.floor(Date.now() / 1000) - originalTimestamp 
            : null;
          
          trackEvent('email_replied', {
            to: originalMessage.value.from?.[0]?.email || originalMessage.value.from_email,
            original_subject: originalMessage.value.subject,
            reply_time_seconds: replyTimeSeconds
          });

          setAnsweredFlag(originalMessage.value);
        }

        // Add sent message to conversation (for instant UI update)
        const sentMessage = response.data.data?.sent_message;

        const mailbox = useMailboxStore();
        const conversationsStore = useConversationsStore();
        
        if (sentMessage && sentMessage.message_id && originalMessage.value) {
          const originalFolder = originalMessage.value.folder || mailbox.currentFolder;
          
          let forceConversationId = originalMessage.value.conversation_id || originalMessage.value.conversationKey;
          
          if (forceConversationId && (forceConversationId.startsWith('temp:') || forceConversationId.startsWith('uid:'))) {
            try {
              const realConvId = await conversationsStore.getConversationForMessage(
                originalFolder,
                originalMessage.value.message_id,
                originalMessage.value.uid
              );
              if (realConvId) {
                forceConversationId = realConvId;
              }
            } catch (e) {
              // Use computed ID if backend lookup fails
            }
          }
          
          if (forceConversationId && !forceConversationId.startsWith('temp:') && !forceConversationId.startsWith('uid:')) {
            try {
              await conversationsStore.assignMessages(
                sentMessage.folder || 'Sent', 
                [{
                  uid: sentMessage.uid || 0,
                  message_id: sentMessage.message_id,
                  subject: sentMessage.subject,
                  date: sentMessage.date || new Date().toISOString(),
                  from: sentMessage.from || [{ email: localStorage.getItem('webmail_email'), name: '' }],
                  references: sentMessage.references || (originalMessage.value.message_id ? [originalMessage.value.message_id] : []),
                  in_reply_to: sentMessage.in_reply_to || originalMessage.value.message_id
                }],
                forceConversationId
              );
              if (originalFolder) {
                await conversationsStore.fetchConversations(originalFolder);
              }
              
              const currentFolder = mailbox.currentFolder;
              if (currentFolder && currentFolder !== originalFolder) {
                await conversationsStore.fetchConversations(currentFolder);
              }
            } catch (e) {
              console.error('[Compose] Failed to persist sent message to conversation DB:', e);
            }
          } else {
            console.warn('[Compose] No valid conversation ID for original message, sent message will auto-group by headers');
          }
        }
        
        if (draftUid.value) {
          mailbox.removeMessageFromList(draftUid.value);
        }
        
        setTimeout(async () => {
          await mailbox.fetchFolders(true);
          await mailbox.refreshCurrentFolder();
        }, 300);
        
        close(false);
        return { success: true };
      } else {
        return { success: false, error: response.data.message };
      }
    } catch (e) {
      return {
        success: false,
        error: e.response?.data?.message || "Failed to send email",
      };
    } finally {
      sending.value = false;
    }
  }

  function setFromAddress(address) {
    const oldAddress = fromAddress.value;
    fromAddress.value = address;

    // If switching between accounts, update signature in body
    if (oldAddress?.email !== address?.email && draft.value.body) {
      // Get new signature for the selected account (already wrapped)
      const newSignature = getSignatureForCurrentAccount();

      // Preferred path: replace the wrapped <div data-signature> block in
      // place. Non-greedy match stops at the first </div>; after TipTap
      // normalisation the signature contains no nested data-signature divs,
      // so this safely swaps only the signature and preserves its position
      // (important for replies/forwards where it precedes quoted text).
      const divPattern = /<div data-signature="true">[\s\S]*?<\/div>/i;
      if (divPattern.test(draft.value.body)) {
        draft.value.body = draft.value.body.replace(
          divPattern,
          newSignature || ""
        );
      } else {
        // Legacy fallback (older drafts without the wrapper): remove
        // everything after the "--" marker, then append the new signature.
        const signaturePattern = /<p><br><\/p><p>--<\/p>[\s\S]*$/i;
        let bodyWithoutSig = draft.value.body.replace(signaturePattern, "");

        if (bodyWithoutSig === draft.value.body) {
          const sigMarkerIndex = draft.value.body.lastIndexOf("<p>--</p>");
          if (sigMarkerIndex > 0) {
            const beforeMarker = draft.value.body.substring(0, sigMarkerIndex);
            const lastBreak = beforeMarker.lastIndexOf("<p><br></p>");
            if (lastBreak >= 0) {
              bodyWithoutSig = draft.value.body.substring(0, lastBreak);
            }
          }
        }

        draft.value.body = bodyWithoutSig + (newSignature || "");
      }
    }
  }

  async function uploadAttachment(file) {
    // Check if file is too large for regular attachment (0 = disabled)
    const threshold = largeAttachmentThreshold.value;
    if (threshold > 0 && file.size > threshold) {
      return await uploadLargeAttachment(file);
    }

    const formData = new FormData();
    formData.append("file", file);

    try {
      const response = await api.post("/attachments/upload", formData, {
        headers: { "Content-Type": "multipart/form-data" },
      });

      if (response.data.success) {
        draft.value.attachments.push(response.data.data);
        return { success: true, data: response.data.data };
      }
    } catch (e) {
      return {
        success: false,
        error: e.response?.data?.message || "Upload failed",
      };
    }
  }

  // Upload large file to Drive and insert share link
  async function uploadLargeAttachment(file) {
    // Lazy import to avoid circular dependency
    const { useDriveStore } = await import("./drive");
    const drive = useDriveStore();

    // Upload to Drive with 90-day expiry (2160 hours)
    const result = await drive.uploadAndShare(file, 2160);

    if (!result.success) {
      return {
        success: false,
        error: result.error || "Failed to upload large file to Drive",
      };
    }

    // Add as a "virtual" attachment for display (marked as drive link)
    // The actual link will be added to the email when sending
    draft.value.attachments.push({
      name: file.name,
      size: file.size,
      driveLink: true,
      url: result.url,
      fileId: result.file.id,
      willExpire: true, // Mark as auto-expiring
    });

    return {
      success: true,
      data: {
        name: file.name,
        size: file.size,
        driveLink: true,
        url: result.url,
      },
      uploadedToDrive: true,
    };
  }
  
  // Build drive links section for email body (called when sending)
  // Creates beautiful HTML cards matching our webmail app style - works in Gmail too
  function buildDriveLinksSection() {
    const driveAttachments = draft.value.attachments.filter(a => a.driveLink);
    if (driveAttachments.length === 0) return '';
    
    const formatSize = (bytes) => {
      if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(2) + " GB";
      if (bytes >= 1048576) return (bytes / 1048576).toFixed(2) + " MB";
      if (bytes >= 1024) return (bytes / 1024).toFixed(2) + " KB";
      return bytes + " B";
    };
    
    // Get file icon (using text symbols that work everywhere)
    const getFileIcon = (filename) => {
      const ext = filename.split('.').pop()?.toLowerCase() || '';
      if (['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp'].includes(ext)) return 'image';
      if (['mp4', 'avi', 'mov', 'mkv', 'webm', 'wmv'].includes(ext)) return 'movie';
      if (['mp3', 'wav', 'ogg', 'flac', 'aac'].includes(ext)) return 'audio_file';
      if (['pdf'].includes(ext)) return 'picture_as_pdf';
      if (['doc', 'docx', 'txt', 'rtf'].includes(ext)) return 'description';
      if (['xls', 'xlsx', 'csv'].includes(ext)) return 'table_chart';
      if (['zip', 'rar', 'tar', 'gz', '7z'].includes(ext)) return 'folder_zip';
      if (['ppt', 'pptx'].includes(ext)) return 'slideshow';
      return 'attach_file';
    };
    
    // Build HTML for Gmail/external email clients
    // FIXED purple/violet color scheme - professional look for external recipients
    let html = `
<div style="margin-top: 24px; padding-top: 20px; border-top: 1px solid #e9d5ff;">
  <table cellpadding="0" cellspacing="0" border="0" width="100%">
    <tr>
      <td style="padding-bottom: 12px; font-size: 11px; font-weight: 600; color: #7c3aed; text-transform: uppercase; letter-spacing: 0.5px;">
        DRIVE ATTACHMENTS (${driveAttachments.length})
      </td>
    </tr>
  </table>
`;
    
    for (const att of driveAttachments) {
      const size = formatSize(att.size);
      
      // Outlook-compatible card: links INSIDE cells, not wrapping the table.
      // Outlook's Word rendering engine ignores <a> around block/table elements.
      html += `
  <table cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom: 8px;">
    <tr>
      <td>
        <table cellpadding="0" cellspacing="0" border="0" style="max-width: 420px; background-color: #faf5ff; border: 1px solid #e9d5ff; border-radius: 12px;">
          <tr>
            <td style="padding: 12px 16px;">
              <table cellpadding="0" cellspacing="0" border="0" width="100%">
                <tr>
                  <td width="48" valign="middle">
                    <table cellpadding="0" cellspacing="0" border="0">
                      <tr>
                        <td style="width: 40px; height: 40px; background-color: #ede9fe; border-radius: 8px; text-align: center; vertical-align: middle;">
                          <a href="${att.url}" target="_blank" style="color: #7c3aed; font-size: 18px; line-height: 40px; text-decoration: none; display: inline-block; width: 40px; height: 40px;">&#9729;</a>
                        </td>
                      </tr>
                    </table>
                  </td>
                  <td style="padding-left: 12px;" valign="middle">
                    <a href="${att.url}" target="_blank" style="text-decoration: none; color: inherit;">
                      <span style="color: #1f2937; font-size: 14px; font-weight: 500; word-break: break-word;">${att.name}</span><br>
                      <span style="color: #6b7280; font-size: 12px;">${size}</span>
                      ${att.willExpire ? `<span style="display: inline-block; margin-left: 8px; color: #d97706; font-size: 11px;">&#9201; 7d</span>` : ''}
                      <span style="display: inline-block; margin-left: 8px; color: #7c3aed; font-size: 11px;">&#9729; Drive</span>
                    </a>
                  </td>
                  <td width="90" valign="middle" style="text-align: right;">
                    <a href="${att.url}" target="_blank" style="display: inline-block; padding: 8px 16px; background-color: #7c3aed; color: #ffffff; font-size: 12px; font-weight: 600; text-decoration: none; border-radius: 6px; line-height: 1;">Download</a>
                  </td>
                </tr>
              </table>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
`;
    }
    
    html += `</div>`;
    
    return html;
  }

  function removeAttachment(index) {
    draft.value.attachments.splice(index, 1);
  }

  function addRecipient(type, recipient) {
    if (!draft.value[type].find((r) => r.email === recipient.email)) {
      draft.value[type].push(recipient);
    }
  }

  function removeRecipient(type, email) {
    draft.value[type] = draft.value[type].filter((r) => r.email !== email);
  }

  // Open compose with prefilled recipients (for client compose)
  async function openWithRecipients(recipients = []) {
    if (shouldUseInline()) {
      const win = await windowService.openWindow('new', null, {}, settingsStore, accountsStore);
      if (win && recipients.length > 0) {
        win.draft.to = recipients.map(r => ({ email: r.email || r, name: r.name || '', display: r.name ? `${r.name} <${r.email || r}>` : (r.email || r) }));
      }
      return;
    }
    await open('new');
    if (recipients.length > 0) {
      draft.value.to = recipients.map(r => ({
        email: r.email || r,
        name: r.name || '',
        display: r.name ? `${r.name} <${r.email || r}>` : (r.email || r)
      }));
    }
  }

  /**
   * Open a scheduled email for editing
   * Fetches full payload from backend, cancels the schedule, and opens in compose
   */
  async function openScheduledEmail(scheduleIdToEdit, { undoSend = false } = {}) {
    try {
      // Fetch full scheduled email data
      const response = await api.get(`/messages/schedule/${scheduleIdToEdit}`);
      if (!response.data.success || !response.data.data?.scheduled) {
        return { success: false, error: 'Scheduled email not found' };
      }

      const scheduled = response.data.data.scheduled;
      const payload = scheduled.email_payload || {};

      const ensureRecipientArray = (val) => {
        if (!val) return [];
        if (Array.isArray(val)) return val.map(r => ({
          email: r.email || r,
          name: r.name || '',
          display: r.name ? `${r.name} <${r.email || r}>` : (r.email || r)
        }));
        return [];
      };

      // Cancel the existing schedule
      // For undo_send, use stricter cancel (pending only) via query param
      const cancelUrl = undoSend
        ? `/messages/schedule/${scheduleIdToEdit}?undo_send=1`
        : `/messages/schedule/${scheduleIdToEdit}`;
      const cancelResponse = await api.delete(cancelUrl);

      if (!cancelResponse.data.success) {
        const errMsg = cancelResponse.data.message || cancelResponse.data.error || 'Failed to cancel';
        return { success: false, error: errMsg, tooLate: cancelResponse.status === 409 };
      }

      // Open compose modal
      await open('new');

      // Restore attachments from the scheduled payload
      const restoredAttachments = Array.isArray(payload.attachments)
        ? payload.attachments.map(a => ({
            filename: a.filename || a.name || 'attachment',
            original_name: a.filename || a.name || 'attachment',
            size: a.size || 0,
            mime_type: a.content_type || a.mime_type || 'application/octet-stream',
            path: a.path || a.tmp_path || '',
            fromScheduled: true,
          }))
        : [];

      // Restore drive-linked attachments
      const driveAttachments = Array.isArray(payload.drive_file_ids) && payload.drive_file_ids.length > 0
        ? payload.drive_file_ids.map(id => ({
            driveLink: true,
            fileId: id,
            filename: `Drive file #${id}`,
          }))
        : [];

      // Full draft restore
      draft.value = {
        to: ensureRecipientArray(payload.to),
        cc: ensureRecipientArray(payload.cc),
        bcc: ensureRecipientArray(payload.bcc),
        subject: payload.subject || '',
        body: payload.body_html || '',
        attachments: [...restoredAttachments, ...driveAttachments],
        important: !!payload.important,
      };

      // Restore sender account if not primary
      if (payload.from_account_id && payload.from_email) {
        const accounts = accountsStore.accounts || [];
        const match = accounts.find(a => a.account_id === payload.from_account_id || a.id === payload.from_account_id);
        if (match) {
          fromAddress.value = match;
        } else {
          fromAddress.value = {
            account_id: payload.from_account_id,
            email: payload.from_email,
            is_primary: false,
          };
        }
      }

      // Restore reply/forward context
      if (payload.in_reply_to || payload.references) {
        if (!originalMessage.value) {
          originalMessage.value = {};
        }
        originalMessage.value.message_id = payload.in_reply_to || originalMessage.value.message_id;
        originalMessage.value.references = payload.references || originalMessage.value.references;
      }

      // Store the original schedule time
      const rawDt = scheduled.scheduled_at;
      const utcDt = rawDt && !rawDt.endsWith('Z') ? rawDt.replace(' ', 'T') + 'Z' : rawDt;
      scheduledAt.value = utcDt;
      scheduleId.value = null;

      hasUserEdits.value = true;

      // Refresh sidebar scheduled count
      try {
        const { useMailboxStore } = await import('@/stores/mailbox');
        useMailboxStore().refreshScheduledCount();
      } catch (e) { /* silent */ }

      return { success: true, scheduled_at: utcDt };
    } catch (e) {
      // Handle 409 Conflict (too late to cancel)
      if (e.response?.status === 409) {
        return { success: false, error: e.response.data?.message || 'Email is already being sent', tooLate: true };
      }
      console.error('Failed to open scheduled email:', e);
      return { success: false, error: e.response?.data?.message || 'Failed to open scheduled email' };
    }
  }

  return {
    isOpen,
    isMinimized,
    mode,
    draft,
    draftUid,
    originalMessage,
    sending,
    saving,
    forceCampaign,
    campaignDraftId,
    sendAddresses,
    fromAddress,
    hasUserEdits,

    scheduledAt,
    scheduleId,

    open,
    openDraft,
    openWithRecipients,
    openScheduledEmail,
    close,
    minimize,
    maximize,
    resetDraft,
    saveDraft,
    send,
    scheduleSend,
    cancelScheduledEmail,
    getScheduledEmails,
    uploadAttachment,
    removeAttachment,
    addRecipient,
    removeRecipient,
    setFromAddress,
    fetchSendAddresses,
    markAsEdited,

    windows,
    closeWindow: windowService.closeWindow,
    sendFromWindow: windowService.sendFromWindow,
    saveDraftForWindow: windowService.saveDraftForWindow,
    uploadAttachmentToWindow: windowService.uploadAttachmentToWindow,
    minimizeWindow: windowService.minimizeWindow,
    maximizeWindow: windowService.maximizeWindow,
    markWindowAsEdited: windowService.markWindowAsEdited,
    addWindowRecipient: windowService.addWindowRecipient,
    removeWindowRecipient: windowService.removeWindowRecipient,
    removeWindowAttachment: windowService.removeWindowAttachment,
    setWindowFromAddress: windowService.setWindowFromAddress,
  };
});
