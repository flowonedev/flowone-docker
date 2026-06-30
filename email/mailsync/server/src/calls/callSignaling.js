/**
 * Call Signaling Handler
 * 
 * Manages call lifecycle signaling through the existing WebSocket infrastructure.
 * 
 * SCALING CONSTRAINT: active call state lives in the in-process `activeCalls`
 * Map below — it is NOT shared across processes. The mailsync server must run as
 * a SINGLE instance (or behind routing that pins every participant of a call to
 * the same node). With multiple unpinned instances, a CALL_INITIATE handled on
 * node A and a CALL_ANSWER handled on node B would not find the call
 * (CALL_NOT_FOUND). Backing `activeCalls` with Redis would be required for true
 * multi-instance scaling.
 * 
 * Media routing is handled entirely by LiveKit SFU — this module only manages
 * call state (ringing, answered, rejected, hangup) and participant tracking.
 * No SDP or ICE candidate relay is needed.
 * 
 * Supports both 1:1 and group (1:many) calls.
 * 
 * Flow:
 * 1. Caller sends CALL_INITIATE -> relayed to all participants in conversation
 * 2. Callee sends CALL_ANSWER -> relayed back to caller + CALL_PARTICIPANT_JOINED to others
 * 3. Both parties connect to the same LiveKit room (callId = room name)
 * 4. CALL_HANGUP from either party -> in group calls, call continues until last participant leaves
 *
 * Email normalization: all participant emails are lowercased on initiate so call
 * state keys match the WebSocket identity (`clientInfo.userEmail`, also
 * lowercased). Mixing cases previously created duplicate participantStatus keys
 * and broke answer/reject/hangup matching.
 */

import { v4 as uuidv4 } from 'uuid'
import { EventTypes } from '../events/eventTypes.js'

// In-memory active calls: callId -> { conversationId, callType, initiatedBy, participants, participantStatus, startedAt }
const activeCalls = new Map()

// Timeout for unanswered calls (30 seconds)
const CALL_TIMEOUT = 30000

// Grace period before cleaning up a disconnected user's call (allows reconnect)
const DISCONNECT_GRACE_PERIOD = 15000

// Per-participant timers: callId -> Map<email, timerId>
const participantTimers = new Map()

// Disconnect grace timers: userEmail -> timerId
const disconnectGraceTimers = new Map()

/**
 * Generate a unique call ID
 */
function generateCallId() {
  return `call_${uuidv4()}`
}

/**
 * Check if a call has any active (answered) participants besides the caller
 */
function hasActiveParticipants(call) {
  for (const [email, status] of Object.entries(call.participantStatus)) {
    if (email === call.initiatedBy) continue
    if (status === 'answered') return true
  }
  return false
}

/**
 * Check if all non-caller participants have resolved (answered, rejected, or timed out)
 */
function allParticipantsResolved(call) {
  for (const [email, status] of Object.entries(call.participantStatus)) {
    if (email === call.initiatedBy) continue
    if (status === 'pending') return false
  }
  return true
}

/**
 * Get list of participants who have answered the call
 */
function getAnsweredParticipants(call) {
  return Object.entries(call.participantStatus)
    .filter(([, status]) => status === 'answered')
    .map(([email]) => email)
}

/**
 * Clean up per-participant timers for a call
 */
function clearParticipantTimers(callId) {
  const timers = participantTimers.get(callId)
  if (timers) {
    for (const [, timerId] of timers) {
      clearTimeout(timerId)
    }
    participantTimers.delete(callId)
  }
}

/**
 * Handle CALL_INITIATE message from client
 * Creates a new call and notifies other participants
 */
export async function handleCallInitiate(ws, clientInfo, message, clientManager, eventStore, redisPubSub) {
  const { conversationId, callType, participants } = message
  
  if (!conversationId || !callType || !['voice', 'video'].includes(callType)) {
    clientManager.sendToClient(ws, {
      type: EventTypes.ERROR,
      payload: { code: 'INVALID_CALL', message: 'Invalid call parameters' }
    })
    return
  }
  
  if (!participants || !Array.isArray(participants) || participants.length === 0) {
    clientManager.sendToClient(ws, {
      type: EventTypes.ERROR,
      payload: { code: 'INVALID_CALL', message: 'No participants specified' }
    })
    return
  }
  
  // Check if there's already an active call in this conversation
  for (const [, call] of activeCalls) {
    if (call.conversationId === conversationId) {
      clientManager.sendToClient(ws, {
        type: EventTypes.ERROR,
        payload: { code: 'CALL_IN_PROGRESS', message: 'A call is already in progress in this conversation' }
      })
      return
    }
  }
  
  const callId = generateCallId()
  // The WS identity is lowercased by ClientManager; lowercase here too so call
  // state keys are consistent across initiate/answer/reject/hangup/cleanup.
  const callerEmail = (clientInfo.userEmail || '').toLowerCase()
  
  // Normalize + dedupe callee emails, and drop the caller if echoed back. All
  // call state below is keyed by these lowercased emails.
  const calleeEmails = [...new Set(
    participants
      .filter(e => typeof e === 'string' && e.trim())
      .map(e => e.trim().toLowerCase())
  )].filter(e => e !== callerEmail)
  
  if (calleeEmails.length === 0) {
    clientManager.sendToClient(ws, {
      type: EventTypes.ERROR,
      payload: { code: 'INVALID_CALL', message: 'No participants specified' }
    })
    return
  }
  
  const allParticipants = [callerEmail, ...calleeEmails]
  
  // Build per-participant status map
  const participantStatus = { [callerEmail]: 'answered' } // caller is automatically "answered"
  for (const email of calleeEmails) {
    participantStatus[email] = 'pending'
  }
  
  // Store active call
  activeCalls.set(callId, {
    conversationId,
    callType,
    initiatedBy: callerEmail,
    participants: allParticipants,
    participantStatus,
    startedAt: Date.now(),
    answeredAt: null,
    status: 'ringing'
  })
  
  console.log(`[CallSignaling] Call initiated: ${callId} by ${callerEmail} (${callType}) to ${calleeEmails.join(', ')}`)
  
  // Send CALL_INITIATE to all other participants
  // Use broadcastToUser (not broadcastToSubscribed) to ensure delivery
  // even if the callee hasn't explicitly subscribed to chat events
  for (const participantEmail of calleeEmails) {
    const event = await eventStore.createEvent(
      EventTypes.CALL_INITIATE,
      participantEmail,
      {
        callId,
        conversationId,
        callType,
        callerEmail,
        callerName: clientInfo.userInfo?.name || callerEmail.split('@')[0],
        participants: allParticipants
      }
    )
    const delivered = await clientManager.broadcastToUser(participantEmail, event)
    console.log(`[CallSignaling] CALL_INITIATE -> ${participantEmail}: delivered to ${delivered} client(s)`)
  }
  
  // Confirm to caller that call was initiated
  clientManager.sendToClient(ws, {
    type: EventTypes.CALL_RINGING,
    payload: {
      callId,
      conversationId,
      callType,
      participants: [callerEmail, ...participants]
    }
  })
  
  // ALWAYS send push notification for incoming calls, regardless of WebSocket state.
  // A user may have a stale desktop tab open (WS connected) but their phone in their pocket.
  // Push is the only reliable way to alert mobile users about incoming calls.
  // The service worker / native app will suppress display if the user is already viewing the call UI.
  for (const participantEmail of calleeEmails) {
    if (redisPubSub?.pushService) {
      const isOnline = clientManager.hasConnectedClients(participantEmail)
      console.log(`[CallSignaling] Sending call invite to ${participantEmail} (online: ${isOnline})`)
      // sendCallInvite rings the native full-screen call UI: APNs VoIP -> CallKit
      // on iOS Chat devices, data-only FCM -> full-screen-intent on Android Chat
      // devices, and falls back to the legacy alert ring only when no Chat app
      // is installed. This replaces the old plain CALL_INCOMING alert push.
      redisPubSub.pushService.sendCallInvite(participantEmail, {
        callId,
        conversationId,
        callType,
        callerEmail,
        callerName: clientInfo.userInfo?.name || callerEmail.split('@')[0],
        callStartedAt: Date.now()
      }).catch(err => {
        console.error(`[CallSignaling] Call invite error for ${participantEmail}:`, err.message)
      })
    }
  }
  
  // Notify the caller's OTHER devices (e.g. desktop) about this call
  // so they can show the "Call in progress" banner even though they didn't initiate it
  const callerActiveEvent = await eventStore.createEvent(
    EventTypes.CALL_ACTIVE_STATUS,
    callerEmail,
    {
      conversationId,
      active: true,
      callId,
      callType,
      initiatedBy: callerEmail,
      participants: [callerEmail],
      startedAt: Date.now(),
      answeredAt: null
    }
  )
  // broadcastToUser sends to ALL clients, including the initiating WS.
  // The call store's handleActiveCallStatus on the initiating device will
  // just re-confirm what it already knows, which is harmless.
  await clientManager.broadcastToUser(callerEmail, callerActiveEvent)
  
  // Set per-participant timeouts for unanswered callees
  const pushSvc = redisPubSub?.pushService || null
  const perParticipantTimers = new Map()
  
  for (const participantEmail of calleeEmails) {
    const timer = setTimeout(async () => {
      await handleParticipantTimeout(callId, participantEmail, clientManager, eventStore, pushSvc)
    }, CALL_TIMEOUT)
    
    perParticipantTimers.set(participantEmail, timer)
  }
  
  participantTimers.set(callId, perParticipantTimers)
}

/**
 * Handle CALL_ANSWER message
 */
export async function handleCallAnswer(ws, clientInfo, message, clientManager, eventStore, pushService = null) {
  const { callId } = message
  const call = activeCalls.get(callId)
  
  if (!call) {
    clientManager.sendToClient(ws, {
      type: EventTypes.ERROR,
      payload: { code: 'CALL_NOT_FOUND', message: 'Call not found or already ended' }
    })
    return
  }
  
  const answererEmail = clientInfo.userEmail
  
  // Update per-participant status
  call.participantStatus[answererEmail] = 'answered'
  
  // Set call to active on first answer
  if (call.status === 'ringing') {
    call.status = 'active'
    call.answeredAt = Date.now()
  }
  
  // Clear this participant's timeout timer
  const timers = participantTimers.get(callId)
  if (timers) {
    const timer = timers.get(answererEmail)
    if (timer) {
      clearTimeout(timer)
      timers.delete(answererEmail)
    }
    // Clean up the timers map if empty
    if (timers.size === 0) {
      participantTimers.delete(callId)
    }
  }
  
  console.log(`[CallSignaling] Call answered: ${callId} by ${answererEmail}`)
  
  // Get list of already-connected participants (excluding the answerer)
  const connectedParticipants = getAnsweredParticipants(call).filter(e => e !== answererEmail)
  
  // Send CALL_ANSWER to the caller (LiveKit handles media connection)
  const callerEvent = await eventStore.createEvent(
    EventTypes.CALL_ANSWER,
    call.initiatedBy,
    {
      callId,
      conversationId: call.conversationId,
      answeredBy: answererEmail
    }
  )
  await clientManager.broadcastToUser(call.initiatedBy, callerEvent)
  
  // For group calls: Notify all OTHER already-connected participants that someone joined
  // so they can create peer connections to the new participant
  for (const connectedEmail of connectedParticipants) {
    if (connectedEmail === call.initiatedBy) continue // caller already got CALL_ANSWER above
    
    const joinedEvent = await eventStore.createEvent(
      EventTypes.CALL_PARTICIPANT_JOINED,
      connectedEmail,
      {
        callId,
        conversationId: call.conversationId,
        participantEmail: answererEmail,
        participantName: clientInfo.userInfo?.name || answererEmail.split('@')[0],
        // List of all currently connected participants so the receiver knows the full state
        connectedParticipants: [...connectedParticipants, answererEmail]
      }
    )
    await clientManager.broadcastToUser(connectedEmail, joinedEvent)
  }
  
  // Tell the answerer which participants are already connected
  // so they can create peer connections to all of them.
  // Always include ALL connected participants (including the caller).
  // For normal answerers who exchanged SDP via CALL_ANSWER, they'll skip
  // the caller (PC already exists). For late joiners (no SDP in their
  // CALL_ANSWER), they need to connect to everyone including the caller.
  if (connectedParticipants.length > 0) {
    const peersEvent = await eventStore.createEvent(
      EventTypes.CALL_PARTICIPANT_JOINED,
      answererEmail,
      {
        callId,
        conversationId: call.conversationId,
        // Special: when participantEmail is null, connectedParticipants is the list
        // of people the receiver needs to connect to
        participantEmail: null,
        connectedParticipants: connectedParticipants
      }
    )
    await clientManager.broadcastToUser(answererEmail, peersEvent)
  }
  
  // Broadcast CALL_ACTIVE_STATUS to ALL participants (including caller's other devices)
  // so that desktop/web clients that are viewing this conversation show the "Call in progress" banner
  const joinedParticipants = getAnsweredParticipants(call)
  for (const participantEmail of call.participants) {
    const activeStatusEvent = await eventStore.createEvent(
      EventTypes.CALL_ACTIVE_STATUS,
      participantEmail,
      {
        conversationId: call.conversationId,
        active: true,
        callId,
        callType: call.callType,
        initiatedBy: call.initiatedBy,
        participants: joinedParticipants,
        startedAt: call.startedAt,
        answeredAt: call.answeredAt
      }
    )
    await clientManager.broadcastToUser(participantEmail, activeStatusEvent)
  }
  
  // Notify answerer's OTHER devices to dismiss the incoming call
  // (e.g. user answered on phone, desktop should stop ringing)
  const dismissEvent = await eventStore.createEvent(
    EventTypes.CALL_DISMISSED,
    answererEmail,
    {
      callId,
      conversationId: call.conversationId,
      reason: 'answered_elsewhere'
    }
  )
  await clientManager.broadcastToUser(answererEmail, dismissEvent)

  // Tear down the native call UI (CallKit / Android FSI) on the answerer's
  // OTHER devices — a WS CALL_DISMISSED can't reach an app that was killed and
  // is only showing the system call screen from the VoIP/FSI ring.
  if (pushService) {
    pushService.sendCallCancel(answererEmail, {
      callId,
      conversationId: call.conversationId,
      reason: 'answered_elsewhere'
    }).catch(() => {})
  }
}

/**
 * Handle CALL_REJECT message
 */
export async function handleCallReject(ws, clientInfo, message, clientManager, eventStore, pushService = null) {
  const { callId, reason } = message
  const call = activeCalls.get(callId)
  
  if (!call) return
  
  const rejectorEmail = clientInfo.userEmail
  
  console.log(`[CallSignaling] Call rejected: ${callId} by ${rejectorEmail} (${reason || 'declined'})`)
  
  // Update per-participant status
  call.participantStatus[rejectorEmail] = 'rejected'
  
  // Clear this participant's timeout timer
  const timers = participantTimers.get(callId)
  if (timers) {
    const timer = timers.get(rejectorEmail)
    if (timer) {
      clearTimeout(timer)
      timers.delete(rejectorEmail)
    }
  }
  
  // Notify all other participants about the rejection
  for (const participantEmail of call.participants) {
    if (participantEmail === rejectorEmail) continue
    
    const event = await eventStore.createEvent(
      EventTypes.CALL_REJECT,
      participantEmail,
      {
        callId,
        conversationId: call.conversationId,
        rejectedBy: rejectorEmail,
        reason: reason || 'declined'
      }
    )
    await clientManager.broadcastToUser(participantEmail, event)
  }
  
  // Notify rejector's OTHER devices to dismiss the incoming call
  const dismissEvent = await eventStore.createEvent(
    EventTypes.CALL_DISMISSED,
    rejectorEmail,
    {
      callId,
      conversationId: call.conversationId,
      reason: 'rejected_elsewhere'
    }
  )
  await clientManager.broadcastToUser(rejectorEmail, dismissEvent)

  // End the native call UI (CallKit / Android FSI) on the rejector's OTHER
  // devices that may only be showing the system ring from the VoIP/FSI push.
  if (pushService) {
    pushService.sendCallCancel(rejectorEmail, {
      callId,
      conversationId: call.conversationId,
      reason: 'rejected_elsewhere'
    }).catch(() => {})
  }
  
  // Check if all non-caller participants have resolved
  if (allParticipantsResolved(call) && !hasActiveParticipants(call)) {
    // Nobody answered - clean up the call
    console.log(`[CallSignaling] All participants rejected/timed out for call ${callId}, cleaning up`)
    
    // Broadcast CALL_ACTIVE_STATUS idle to ALL participants so their banners clear
    for (const participantEmail of call.participants) {
      const idleEvent = await eventStore.createEvent(
        EventTypes.CALL_ACTIVE_STATUS,
        participantEmail,
        {
          callId: null,
          conversationId: call.conversationId,
          active: false,
          status: 'idle'
        }
      )
      await clientManager.broadcastToUser(participantEmail, idleEvent)
    }
    
    clearParticipantTimers(callId)
    activeCalls.delete(callId)
  }
}

/**
 * Handle CALL_HANGUP message
 */
export async function handleCallHangup(ws, clientInfo, message, clientManager, eventStore, pushService = null) {
  const { callId } = message
  const call = activeCalls.get(callId)
  
  if (!call) return
  
  const hangerEmail = clientInfo.userEmail
  const duration = call.answeredAt ? Math.floor((Date.now() - call.answeredAt) / 1000) : 0
  
  console.log(`[CallSignaling] Participant left: ${callId} by ${hangerEmail} (duration: ${duration}s)`)
  
  // Update participant status
  call.participantStatus[hangerEmail] = 'left'
  
  // Clear this participant's timeout timer if any
  const timers = participantTimers.get(callId)
  if (timers) {
    const timer = timers.get(hangerEmail)
    if (timer) {
      clearTimeout(timer)
      timers.delete(hangerEmail)
    }
  }
  
  // Check if call should end: no active participants remain
  const remaining = getAnsweredParticipants(call)
  const isCallEnding = remaining.length <= 1
  
  // Identify pending participants (never answered - they get CALL_MISSED when call ends)
  const pendingParticipants = Object.entries(call.participantStatus)
    .filter(([email, status]) => status === 'pending' && email !== hangerEmail)
    .map(([email]) => email)
  
  // Notify other participants
  // - If call is ending: send CALL_HANGUP only to answered/active participants
  //   (pending participants will get CALL_MISSED instead)
  // - If call continues: send CALL_HANGUP to everyone so they can update participant list
  for (const participantEmail of call.participants) {
    if (participantEmail === hangerEmail) continue
    
    // Skip pending participants when call is ending - they'll receive CALL_MISSED below
    if (isCallEnding && call.participantStatus[participantEmail] === 'pending') continue
    
    const event = await eventStore.createEvent(
      EventTypes.CALL_HANGUP,
      participantEmail,
      {
        callId,
        conversationId: call.conversationId,
        hungUpBy: hangerEmail,
        duration,
        callType: call.callType
      }
    )
    await clientManager.broadcastToUser(participantEmail, event)
  }
  
  // Also dismiss on hanger's OTHER devices (e.g. hung up on phone, desktop should clean up)
  const dismissEvent = await eventStore.createEvent(
    EventTypes.CALL_DISMISSED,
    hangerEmail,
    {
      callId,
      conversationId: call.conversationId,
      reason: 'hung_up_elsewhere',
      duration
    }
  )
  await clientManager.broadcastToUser(hangerEmail, dismissEvent)
  
  if (isCallEnding) {
    // Send CALL_MISSED to all pending participants (they never answered)
    // This triggers missed call notifications and cleans up their ringing UI
    if (pendingParticipants.length > 0) {
      const callerName = call.initiatedBy.split('@')[0]
      
      for (const participantEmail of pendingParticipants) {
        console.log(`[CallSignaling] Sending CALL_MISSED to pending participant: ${participantEmail}`)
        
        const missedEvent = await eventStore.createEvent(
          EventTypes.CALL_MISSED,
          participantEmail,
          {
            callId,
            conversationId: call.conversationId,
            callType: call.callType,
            callerEmail: call.initiatedBy,
            callerName
          }
        )
        const delivered = await clientManager.broadcastToUser(participantEmail, missedEvent)
        
        // Always send push for missed calls - user may have a stale WS connection
        // or their phone is the device they'll actually check
        if (pushService) {
          console.log(`[CallSignaling] Sending missed call push to ${participantEmail} (WS delivered: ${delivered})`)
          // Caller cancelled before this callee answered: stop the ringing
          // native call UI (CallKit / Android FSI) before the missed banner.
          pushService.sendCallCancel(participantEmail, {
            callId,
            conversationId: call.conversationId,
            reason: 'cancelled'
          }).catch(() => {})
          pushService.sendPushDirectly(participantEmail, {
            type: 'CALL_MISSED',
            payload: {
              callId,
              conversationId: call.conversationId,
              callType: call.callType,
              callerEmail: call.initiatedBy,
              callerName
            }
          }).catch(err => {
            console.error(`[CallSignaling] Push notification error for ${participantEmail}:`, err.message)
          })
        }
      }
    }
    
    // Only 0 or 1 person left - end the call for the last person too
    if (remaining.length === 1) {
      const lastEmail = remaining[0]
      const endEvent = await eventStore.createEvent(
        EventTypes.CALL_HANGUP,
        lastEmail,
        {
          callId,
          conversationId: call.conversationId,
          hungUpBy: 'system',
          duration,
          callType: call.callType,
          reason: 'all_others_left'
        }
      )
      await clientManager.broadcastToUser(lastEmail, endEvent)
    }
    
    console.log(`[CallSignaling] Call ended (no active participants): ${callId}`)
    
    // Broadcast CALL_ACTIVE_STATUS idle to ALL original participants so their banners update
    for (const participantEmail of call.participants) {
      const idleEvent = await eventStore.createEvent(
        EventTypes.CALL_ACTIVE_STATUS,
        participantEmail,
        {
          callId: null,
          conversationId: call.conversationId,
          active: false,
          status: 'idle'
        }
      )
      await clientManager.broadcastToUser(participantEmail, idleEvent)
    }
    
    clearParticipantTimers(callId)
    activeCalls.delete(callId)
  }
}

/**
 * Handle CALL_MEDIA_STATE message
 * Relay media state changes (mute, camera on/off) to other participants
 */
export async function handleCallMediaState(ws, clientInfo, message, clientManager, eventStore) {
  const { callId, audio, video, screenShare } = message
  const call = activeCalls.get(callId)
  
  if (!call) return
  
  for (const participantEmail of call.participants) {
    if (participantEmail === clientInfo.userEmail) continue
    
    const event = await eventStore.createEvent(
      EventTypes.CALL_MEDIA_STATE,
      participantEmail,
      {
        callId,
        participantEmail: clientInfo.userEmail,
        audio,
        video,
        screenShare
      }
    )
    await clientManager.broadcastToUser(participantEmail, event)
  }
}

/**
 * Handle CALL_SCREEN_SHARE_START/STOP
 */
export async function handleCallScreenShare(ws, clientInfo, message, clientManager, eventStore, isStart) {
  const { callId, screenStreamId } = message
  const call = activeCalls.get(callId)
  
  if (!call) return
  
  const eventType = isStart ? EventTypes.CALL_SCREEN_SHARE_START : EventTypes.CALL_SCREEN_SHARE_STOP
  
  for (const participantEmail of call.participants) {
    if (participantEmail === clientInfo.userEmail) continue
    
    const payload = {
      callId,
      participantEmail: clientInfo.userEmail
    }
    
    // Pass screen stream ID so receiver can reliably identify screen tracks
    if (isStart && screenStreamId) {
      payload.screenStreamId = screenStreamId
    }
    
    const event = await eventStore.createEvent(
      eventType,
      participantEmail,
      payload
    )
    await clientManager.broadcastToUser(participantEmail, event)
  }
}

/**
 * Handle per-participant timeout (no answer from a specific participant)
 */
async function handleParticipantTimeout(callId, participantEmail, clientManager, eventStore, pushService = null) {
  const call = activeCalls.get(callId)
  if (!call) return
  
  // Only timeout if participant is still pending
  if (call.participantStatus[participantEmail] !== 'pending') return
  
  call.participantStatus[participantEmail] = 'timed_out'
  
  console.log(`[CallSignaling] Participant timed out: ${participantEmail} in call ${callId}`)
  
  // Send CALL_MISSED to the participant who didn't answer
  const callerName = call.initiatedBy.split('@')[0]
  const missedEvent = await eventStore.createEvent(
    EventTypes.CALL_MISSED,
    participantEmail,
    {
      callId,
      conversationId: call.conversationId,
      callType: call.callType,
      callerEmail: call.initiatedBy,
      callerName
    }
  )
  const delivered = await clientManager.broadcastToUser(participantEmail, missedEvent)
  
  // Always send push for missed calls - user may have a stale WS connection
  // but their phone is the device they'll actually check
  if (pushService) {
    console.log(`[CallSignaling] Sending missed call push to ${participantEmail} (WS delivered: ${delivered})`)
    // First end the ringing native call UI (CallKit / Android FSI) so the
    // system call screen can't keep ringing after the server gave up...
    pushService.sendCallCancel(participantEmail, {
      callId,
      conversationId: call.conversationId,
      reason: 'missed'
    }).catch(() => {})
    // ...then deliver the persistent "Missed call" banner.
    pushService.sendPushDirectly(participantEmail, {
      type: 'CALL_MISSED',
      payload: {
        callId,
        conversationId: call.conversationId,
        callType: call.callType,
        callerEmail: call.initiatedBy,
        callerName
      }
    }).catch(err => {
      console.error(`[CallSignaling] Push notification error for ${participantEmail}:`, err.message)
    })
  }
  
  // Notify the caller that this participant didn't answer (for UI feedback)
  const noAnswerEvent = await eventStore.createEvent(
    EventTypes.CALL_REJECT,
    call.initiatedBy,
    {
      callId,
      conversationId: call.conversationId,
      rejectedBy: participantEmail,
      reason: 'no_answer'
    }
  )
  await clientManager.broadcastToUser(call.initiatedBy, noAnswerEvent)
  
  // Check if all non-caller participants have resolved and none answered
  if (allParticipantsResolved(call) && !hasActiveParticipants(call)) {
    // Nobody answered at all - send CALL_MISSED to caller and clean up
    console.log(`[CallSignaling] Call timed out (no participants answered): ${callId}`)
    
    const callerMissedEvent = await eventStore.createEvent(
      EventTypes.CALL_MISSED,
      call.initiatedBy,
      {
        callId,
        conversationId: call.conversationId,
        callType: call.callType,
        participants: call.participants
      }
    )
    await clientManager.broadcastToUser(call.initiatedBy, callerMissedEvent)
    
    // Broadcast CALL_ACTIVE_STATUS idle to ALL participants so their banners clear
    for (const pEmail of call.participants) {
      const idleEvent = await eventStore.createEvent(
        EventTypes.CALL_ACTIVE_STATUS,
        pEmail,
        {
          callId: null,
          conversationId: call.conversationId,
          active: false,
          status: 'idle'
        }
      )
      await clientManager.broadcastToUser(pEmail, idleEvent)
    }
    
    clearParticipantTimers(callId)
    activeCalls.delete(callId)
  }
}

/**
 * Get active call for a conversation (if any)
 */
export function getActiveCall(conversationId) {
  for (const [callId, call] of activeCalls) {
    if (call.conversationId === conversationId) {
      return { callId, ...call }
    }
  }
  return null
}

/**
 * Handle CALL_ACTIVE_QUERY message
 * Client asks if there's an active call for a given conversation.
 * If conversationId is 'all', returns all active calls the user is part of.
 * Responds directly to the asking client.
 */
export function handleCallActiveQuery(ws, clientInfo, message, clientManager) {
  const { conversationId } = message
  if (!conversationId) return
  
  // "all" mode: return all active calls where the user is a participant
  if (conversationId === 'all') {
    const userEmail = clientInfo?.userEmail?.toLowerCase()
    if (!userEmail) return
    
    for (const [callId, call] of activeCalls) {
      if (call.status !== 'active') continue
      
      // Check if the user is a participant of this call's conversation
      const isParticipant = call.participants.some(p => p.toLowerCase() === userEmail)
      if (!isParticipant) continue
      
      const joinedParticipants = Object.entries(call.participantStatus)
        .filter(([, status]) => status === 'answered')
        .map(([email]) => email)
      
      clientManager.sendToClient(ws, {
        type: EventTypes.CALL_ACTIVE_STATUS,
        payload: {
          conversationId: call.conversationId,
          active: true,
          callId,
          callType: call.callType,
          initiatedBy: call.initiatedBy,
          participants: joinedParticipants,
          startedAt: call.startedAt,
          answeredAt: call.answeredAt
        }
      })
    }
    return
  }
  
  const call = getActiveCall(conversationId)
  
  if (call && call.status === 'active') {
    // Return call info with list of actually-joined (answered) participants
    const joinedParticipants = Object.entries(call.participantStatus)
      .filter(([, status]) => status === 'answered')
      .map(([email]) => email)
    
    clientManager.sendToClient(ws, {
      type: EventTypes.CALL_ACTIVE_STATUS,
      payload: {
        conversationId,
        active: true,
        callId: call.callId,
        callType: call.callType,
        initiatedBy: call.initiatedBy,
        participants: joinedParticipants,
        startedAt: call.startedAt,
        answeredAt: call.answeredAt
      }
    })
  } else {
    clientManager.sendToClient(ws, {
      type: EventTypes.CALL_ACTIVE_STATUS,
      payload: {
        conversationId,
        active: false
      }
    })
  }
}

/**
 * Clean up calls for a disconnected user.
 * Called when a user loses ALL WebSocket connections.
 * Uses a grace period to allow reconnection before ending the call.
 */
export function scheduleUserCallCleanup(userEmail, clientManager, eventStore, pushService = null) {
  const emailLower = userEmail.toLowerCase()
  
  // Cancel any existing grace timer (e.g. user reconnected and disconnected again)
  cancelUserCallCleanup(emailLower)
  
  // Check if user is in any active call
  let isInCall = false
  for (const [, call] of activeCalls) {
    const status = call.participantStatus[emailLower] || call.participantStatus[userEmail]
    if (status === 'answered' || status === 'pending') {
      isInCall = true
      break
    }
  }
  
  if (!isInCall) return
  
  console.log(`[CallSignaling] User ${emailLower} disconnected while in call, starting ${DISCONNECT_GRACE_PERIOD / 1000}s grace period`)
  
  const timer = setTimeout(async () => {
    disconnectGraceTimers.delete(emailLower)
    
    // Recheck - user may have reconnected during grace period
    if (clientManager.hasConnectedClients(emailLower)) {
      console.log(`[CallSignaling] User ${emailLower} reconnected during grace period, skipping cleanup`)
      return
    }
    
    console.log(`[CallSignaling] Grace period expired for ${emailLower}, cleaning up their calls`)
    await cleanupUserCalls(emailLower, clientManager, eventStore, pushService)
  }, DISCONNECT_GRACE_PERIOD)
  
  disconnectGraceTimers.set(emailLower, timer)
}

/**
 * Cancel a pending disconnect grace timer (user reconnected)
 */
export function cancelUserCallCleanup(userEmail) {
  const emailLower = userEmail.toLowerCase()
  const timer = disconnectGraceTimers.get(emailLower)
  if (timer) {
    clearTimeout(timer)
    disconnectGraceTimers.delete(emailLower)
    console.log(`[CallSignaling] Cancelled disconnect grace timer for ${emailLower} (reconnected)`)
  }
}

/**
 * Actually clean up all calls for a disconnected user
 */
async function cleanupUserCalls(userEmail, clientManager, eventStore, pushService = null) {
  const emailLower = userEmail.toLowerCase()
  
  for (const [callId, call] of activeCalls) {
    // Find calls where this user is an answered participant
    const userStatus = call.participantStatus[emailLower] || call.participantStatus[userEmail]
    
    if (userStatus === 'answered') {
      // User was in this active call - simulate a hangup
      const duration = call.answeredAt ? Math.floor((Date.now() - call.answeredAt) / 1000) : 0
      
      console.log(`[CallSignaling] Auto-hanging up ${emailLower} from call ${callId} (connection lost, duration: ${duration}s)`)
      
      call.participantStatus[emailLower] = 'left'
      if (call.participantStatus[userEmail]) {
        call.participantStatus[userEmail] = 'left'
      }
      
      const remaining = getAnsweredParticipants(call)
      const isCallEnding = remaining.length <= 1
      
      // Notify other participants that this user's connection was lost
      for (const participantEmail of call.participants) {
        if (participantEmail.toLowerCase() === emailLower) continue
        if (isCallEnding && call.participantStatus[participantEmail] === 'pending') continue
        
        const event = await eventStore.createEvent(
          EventTypes.CALL_HANGUP,
          participantEmail,
          {
            callId,
            conversationId: call.conversationId,
            hungUpBy: emailLower,
            duration,
            callType: call.callType,
            reason: 'connection_lost'
          }
        )
        await clientManager.broadcastToUser(participantEmail, event)
      }
      
      if (isCallEnding) {
        // Handle pending participants - they get CALL_MISSED
        const pendingParticipants = Object.entries(call.participantStatus)
          .filter(([email, status]) => status === 'pending' && email.toLowerCase() !== emailLower)
          .map(([email]) => email)
        
        for (const pendingEmail of pendingParticipants) {
          // Stop the ringing native call UI (CallKit / Android FSI) FIRST: a
          // pending callee may only be reachable via the VoIP/FSI push (app
          // killed, no WS), so a WS CALL_MISSED alone can't silence the ring.
          // Without this, a caller dropping their connection left callees
          // ringing until the 30s timeout.
          if (pushService) {
            pushService.sendCallCancel(pendingEmail, {
              callId,
              conversationId: call.conversationId,
              reason: 'cancelled'
            }).catch(() => {})
          }
          const missedEvent = await eventStore.createEvent(
            EventTypes.CALL_MISSED,
            pendingEmail,
            {
              callId,
              conversationId: call.conversationId,
              callType: call.callType,
              callerEmail: call.initiatedBy,
              callerName: call.initiatedBy.split('@')[0]
            }
          )
          await clientManager.broadcastToUser(pendingEmail, missedEvent)
        }
        
        // End call for last remaining participant
        if (remaining.length === 1) {
          const lastEmail = remaining[0]
          const endEvent = await eventStore.createEvent(
            EventTypes.CALL_HANGUP,
            lastEmail,
            {
              callId,
              conversationId: call.conversationId,
              hungUpBy: 'system',
              duration,
              callType: call.callType,
              reason: 'other_participant_connection_lost'
            }
          )
          await clientManager.broadcastToUser(lastEmail, endEvent)
        }
        
        // Broadcast idle status
        for (const participantEmail of call.participants) {
          const idleEvent = await eventStore.createEvent(
            EventTypes.CALL_ACTIVE_STATUS,
            participantEmail,
            {
              callId: null,
              conversationId: call.conversationId,
              active: false,
              status: 'idle'
            }
          )
          await clientManager.broadcastToUser(participantEmail, idleEvent)
        }
        
        clearParticipantTimers(callId)
        activeCalls.delete(callId)
      }
    } else if (userStatus === 'pending') {
      // User had a pending (ringing) call and disconnected - mark as timed out
      call.participantStatus[emailLower] = 'timed_out'
      if (call.participantStatus[userEmail]) {
        call.participantStatus[userEmail] = 'timed_out'
      }
      
      // Clear their timer
      const timers = participantTimers.get(callId)
      if (timers) {
        const timer = timers.get(emailLower) || timers.get(userEmail)
        if (timer) clearTimeout(timer)
        timers.delete(emailLower)
        timers.delete(userEmail)
      }
      
      // Check if all resolved
      if (allParticipantsResolved(call) && !hasActiveParticipants(call)) {
        clearParticipantTimers(callId)
        activeCalls.delete(callId)
      }
    }
  }
}

/**
 * Clean up all active calls (for shutdown) - notifies participants before clearing
 */
export async function cleanupAllCalls(clientManager, eventStore) {
  // Notify all participants in active calls about the shutdown
  if (clientManager && eventStore) {
    for (const [callId, call] of activeCalls) {
      const duration = call.answeredAt ? Math.floor((Date.now() - call.answeredAt) / 1000) : 0
      
      for (const participantEmail of call.participants) {
        try {
          const event = await eventStore.createEvent(
            EventTypes.CALL_HANGUP,
            participantEmail,
            {
              callId,
              conversationId: call.conversationId,
              hungUpBy: 'system',
              duration,
              callType: call.callType,
              reason: 'server_shutdown'
            }
          )
          await clientManager.broadcastToUser(participantEmail, event)
        } catch (e) {
          // Best effort during shutdown
        }
      }
    }
  }
  
  // Clear all grace timers
  for (const [, timer] of disconnectGraceTimers) {
    clearTimeout(timer)
  }
  disconnectGraceTimers.clear()
  
  for (const [callId] of participantTimers) {
    clearParticipantTimers(callId)
  }
  participantTimers.clear()
  activeCalls.clear()
  console.log('[CallSignaling] All active calls cleaned up')
}
