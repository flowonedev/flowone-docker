/**
 * Huddle Signaling Handler
 * 
 * Manages WebRTC audio signaling for huddles (persistent audio rooms).
 * Unlike calls (ring-and-answer), huddles are drop-in/drop-out audio rooms.
 * 
 * Flow:
 * 1. User joins huddle -> HUDDLE_JOIN -> server tracks participant + notifies others
 * 2. Existing participants send SDP offers to the new joiner
 * 3. ICE candidates exchanged bidirectionally
 * 4. User leaves -> HUDDLE_LEAVE -> server removes participant + notifies others
 * 5. Last person leaves -> huddle ends
 */

import { EventTypes } from '../events/eventTypes.js'

// In-memory active huddles: huddleId -> { conversationId, participants: Set<email> }
const activeHuddles = new Map()

/**
 * Handle HUDDLE_JOIN message
 * Client has joined a huddle (already registered via HTTP API) and needs WebRTC connections.
 */
export async function handleHuddleJoin(ws, clientInfo, message, clientManager, eventStore) {
  const { huddleId, conversationId } = message
  if (!huddleId || !conversationId) return
  
  const joinerEmail = clientInfo.userEmail
  console.log(`[HuddleSignaling] ${joinerEmail} joining huddle ${huddleId} in conversation ${conversationId}`)
  
  // Get or create in-memory huddle tracking
  let huddle = activeHuddles.get(huddleId)
  if (!huddle) {
    huddle = { conversationId, participants: new Set() }
    activeHuddles.set(huddleId, huddle)
  }
  
  // Get existing participants BEFORE adding the new one
  const existingParticipants = [...huddle.participants].filter(e => e !== joinerEmail)
  
  // Add the joiner
  huddle.participants.add(joinerEmail)
  
  // Notify existing participants that someone joined
  // They will create peer connections and send SDP offers to the joiner
  for (const participantEmail of existingParticipants) {
    const event = await eventStore.createEvent(
      EventTypes.HUDDLE_PARTICIPANT_JOINED,
      participantEmail,
      {
        huddleId,
        conversationId,
        participantEmail: joinerEmail,
        // Tell existing participant which peers are already connected
        existingParticipants: existingParticipants
      }
    )
    await clientManager.broadcastToUser(participantEmail, event)
  }
  
  // Tell the joiner who is already in the huddle so they know what connections to expect
  if (existingParticipants.length > 0) {
    const joinAckEvent = await eventStore.createEvent(
      EventTypes.HUDDLE_PARTICIPANT_JOINED,
      joinerEmail,
      {
        huddleId,
        conversationId,
        participantEmail: null, // null means "this is your join ack"
        existingParticipants: existingParticipants
      }
    )
    await clientManager.broadcastToUser(joinerEmail, joinAckEvent)
  }
  
  console.log(`[HuddleSignaling] Huddle ${huddleId} now has ${huddle.participants.size} participants: ${[...huddle.participants].join(', ')}`)
}

/**
 * Handle HUDDLE_LEAVE message
 */
export async function handleHuddleLeave(ws, clientInfo, message, clientManager, eventStore) {
  const { huddleId } = message
  if (!huddleId) return
  
  const leaverEmail = clientInfo.userEmail
  const huddle = activeHuddles.get(huddleId)
  
  if (!huddle) return
  
  huddle.participants.delete(leaverEmail)
  console.log(`[HuddleSignaling] ${leaverEmail} left huddle ${huddleId}, ${huddle.participants.size} remaining`)
  
  if (huddle.participants.size === 0) {
    // Huddle ended
    activeHuddles.delete(huddleId)
    console.log(`[HuddleSignaling] Huddle ${huddleId} ended (no participants)`)
    return
  }
  
  // Notify remaining participants
  for (const participantEmail of huddle.participants) {
    const event = await eventStore.createEvent(
      EventTypes.HUDDLE_PARTICIPANT_LEFT,
      participantEmail,
      {
        huddleId,
        conversationId: huddle.conversationId,
        participantEmail: leaverEmail
      }
    )
    await clientManager.broadcastToUser(participantEmail, event)
  }
}

/**
 * Handle HUDDLE_SDP_OFFER - relay SDP offer to target participant
 */
export async function handleHuddleSdpOffer(ws, clientInfo, message, clientManager, eventStore) {
  const { huddleId, sdp, targetEmail } = message
  if (!huddleId || !sdp || !targetEmail) return
  
  const huddle = activeHuddles.get(huddleId)
  if (!huddle || !huddle.participants.has(targetEmail)) return
  
  const event = await eventStore.createEvent(
    EventTypes.HUDDLE_SDP_OFFER,
    targetEmail,
    {
      huddleId,
      sdp,
      fromEmail: clientInfo.userEmail
    }
  )
  await clientManager.broadcastToUser(targetEmail, event)
}

/**
 * Handle HUDDLE_SDP_ANSWER - relay SDP answer to target participant
 */
export async function handleHuddleSdpAnswer(ws, clientInfo, message, clientManager, eventStore) {
  const { huddleId, sdp, targetEmail } = message
  if (!huddleId || !sdp || !targetEmail) return
  
  const huddle = activeHuddles.get(huddleId)
  if (!huddle || !huddle.participants.has(targetEmail)) return
  
  const event = await eventStore.createEvent(
    EventTypes.HUDDLE_SDP_ANSWER,
    targetEmail,
    {
      huddleId,
      sdp,
      fromEmail: clientInfo.userEmail
    }
  )
  await clientManager.broadcastToUser(targetEmail, event)
}

/**
 * Handle HUDDLE_ICE_CANDIDATE - relay ICE candidate to target participant
 */
export async function handleHuddleIceCandidate(ws, clientInfo, message, clientManager, eventStore) {
  const { huddleId, candidate, targetEmail } = message
  if (!huddleId || !candidate || !targetEmail) return
  
  const huddle = activeHuddles.get(huddleId)
  if (!huddle || !huddle.participants.has(targetEmail)) return
  
  const event = await eventStore.createEvent(
    EventTypes.HUDDLE_ICE_CANDIDATE,
    targetEmail,
    {
      huddleId,
      candidate,
      fromEmail: clientInfo.userEmail
    }
  )
  await clientManager.broadcastToUser(targetEmail, event)
}

/**
 * Handle HUDDLE_MEDIA_STATE - relay mute/deafen state to other participants
 */
export async function handleHuddleMediaState(ws, clientInfo, message, clientManager, eventStore) {
  const { huddleId, isMuted, isDeafened } = message
  if (!huddleId) return
  
  const huddle = activeHuddles.get(huddleId)
  if (!huddle) return
  
  for (const participantEmail of huddle.participants) {
    if (participantEmail === clientInfo.userEmail) continue
    
    const event = await eventStore.createEvent(
      EventTypes.HUDDLE_MEDIA_STATE,
      participantEmail,
      {
        huddleId,
        participantEmail: clientInfo.userEmail,
        isMuted: !!isMuted,
        isDeafened: !!isDeafened
      }
    )
    await clientManager.broadcastToUser(participantEmail, event)
  }
}

/**
 * Handle HUDDLE_SPEAKING - relay speaking state to other participants
 */
export async function handleHuddleSpeaking(ws, clientInfo, message, clientManager, eventStore) {
  const { huddleId, isSpeaking } = message
  if (!huddleId) return

  const huddle = activeHuddles.get(huddleId)
  if (!huddle) return

  for (const participantEmail of huddle.participants) {
    if (participantEmail === clientInfo.userEmail) continue

    const event = await eventStore.createEvent(
      EventTypes.HUDDLE_SPEAKING,
      participantEmail,
      {
        huddleId,
        email: clientInfo.userEmail,
        isSpeaking: !!isSpeaking
      }
    )
    await clientManager.broadcastToUser(participantEmail, event)
  }
}

/**
 * Clean up a user from all huddles (on disconnect)
 */
export async function cleanupUserHuddles(userEmail, clientManager, eventStore) {
  for (const [huddleId, huddle] of activeHuddles) {
    if (!huddle.participants.has(userEmail)) continue
    
    huddle.participants.delete(userEmail)
    console.log(`[HuddleSignaling] ${userEmail} disconnected from huddle ${huddleId}, ${huddle.participants.size} remaining`)
    
    if (huddle.participants.size === 0) {
      activeHuddles.delete(huddleId)
      continue
    }
    
    // Notify remaining participants
    for (const participantEmail of huddle.participants) {
      const event = await eventStore.createEvent(
        EventTypes.HUDDLE_PARTICIPANT_LEFT,
        participantEmail,
        {
          huddleId,
          conversationId: huddle.conversationId,
          participantEmail: userEmail
        }
      )
      await clientManager.broadcastToUser(participantEmail, event)
    }
  }
}

/**
 * Clean up all huddles (for shutdown)
 */
export function cleanupAllHuddles() {
  activeHuddles.clear()
  console.log('[HuddleSignaling] All active huddles cleaned up')
}

