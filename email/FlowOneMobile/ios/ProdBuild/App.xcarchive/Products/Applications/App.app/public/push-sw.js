/**
 * Push Notification Handler for Service Worker
 * 
 * This file is imported by the VitePWA-generated service worker via importScripts.
 * It handles incoming push events and notification clicks.
 * 
 * iOS Safari compatibility:
 * - No notification actions (buttons) - iOS ignores them
 * - No vibrate - iOS ignores it  
 * - No requireInteraction - iOS ignores it
 * - Keep payloads simple for maximum compatibility
 * Actions still work on Windows (Chrome/Edge) and Android.
 */

// Handle incoming push notifications
self.addEventListener('push', (event) => {
  let data = {
    title: 'FlowOne',
    body: 'You have a new notification',
    type: 'general',
    url: '/'
  }

  if (event.data) {
    try {
      data = { ...data, ...event.data.json() }
    } catch (e) {
      data.body = event.data.text()
    }
  }

  // Handle stale call notifications: if the push is for an incoming call
  // but the call started more than 30 seconds ago, convert to missed call
  if (data.type === 'call' && data.callStartedAt) {
    const elapsed = Date.now() - data.callStartedAt
    if (elapsed > 30000) {
      // Call timeout already passed - show as missed call instead
      data.title = 'Missed call'
      data.body = `from ${data.body?.replace('Incoming call from ', '') || 'Unknown'}`
      data.type = 'missed_call'
      data.tag = `missed-call-${data.tag || Date.now()}`
    }
  }

  const options = {
    body: data.body || '',
    // ?v=3 matches the manifest icon version and busts the old Workbox precache
    // / HTTP cache, so notifications show the current FlowOne logo (not the old
    // cached icon). Bump alongside the manifest icon version whenever it changes.
    icon: '/pwa-192x192.png?v=3',
    badge: '/pwa-192x192.png?v=3',
    tag: data.tag || `webmail-${data.type}-${Date.now()}`,
    renotify: true,
    data: {
      url: data.url || '/',
      type: data.type || 'general',
      conversationId: data.conversationId || null,
      callId: data.callId || null,
      callType: data.callType || null,
      folder: data.folder || null,
      uid: data.uid || null
    }
  }

  // Set tag based on notification type (groups notifications)
  if (data.type === 'chat') {
    options.tag = `webmail-chat-${data.conversationId || 'general'}`
  } else if (data.type === 'email') {
    options.tag = `webmail-email-${data.uid || 'general'}`
  } else if (data.type === 'call') {
    options.tag = `webmail-call-${data.callId || Date.now()}`
    // Add Answer / Decline action buttons (Windows/Android — silently ignored on iOS)
    options.actions = [
      { action: 'answer', title: 'Answer' },
      { action: 'decline', title: 'Decline' }
    ]
    options.requireInteraction = true  // Keep visible until user acts
    options.renotify = true
  } else if (data.type === 'missed_call') {
    // Reuse the incoming-call tag scheme so the missed banner REPLACES the ring
    // for the same call instead of stacking a second notification.
    options.tag = data.callId ? `webmail-call-${data.callId}` : (data.tag || `webmail-missed-call-${Date.now()}`)
    // Missed calls should always show, even if a previous notification exists
    options.renotify = true
  }

  // Check if the app is currently focused before showing the notification.
  // If the user is actively using the app, suppress the OS notification to avoid
  // double-alerting (the in-app UI already handles the event via WebSocket).
  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windowClients) => {
      const hasFocusedClient = windowClients.some(client => client.focused)
      
      if (hasFocusedClient) {
        // App is focused — forward to the page instead of showing OS notification.
        // This lets the in-app UI handle it (sound, badge, toast) without duplicate OS alert.
        for (const client of windowClients) {
          if (client.focused) {
            client.postMessage({
              type: 'PUSH_RECEIVED',
              payload: data
            })
          }
        }
        // Still update the badge (doesn't produce a visible alert)
        if (self.navigator && self.navigator.setAppBadge) {
          return self.navigator.setAppBadge().catch(() => {})
        }
        return Promise.resolve()
      }
      
      // App is NOT focused — show OS notification
      return Promise.all([
        self.registration.showNotification(data.title || 'FlowOne', options),
        self.navigator && self.navigator.setAppBadge 
          ? self.navigator.setAppBadge().catch(() => {})
          : Promise.resolve()
      ])
    })
  )
})

// Handle notification click (including action buttons)
self.addEventListener('notificationclick', (event) => {
  const notification = event.notification
  const action = event.action // 'answer', 'decline', or '' (body click)
  notification.close()

  const notificationData = notification.data || {}

  // ── Call action buttons ──────────────────────────────────────────
  if (notificationData.type === 'call' && notificationData.callId) {
    if (action === 'decline') {
      // Send decline message to any open client window, then done
      event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windowClients) => {
          for (const client of windowClients) {
            client.postMessage({
              type: 'CALL_ACTION_FROM_NOTIFICATION',
              action: 'decline',
              callId: notificationData.callId
            })
          }
        })
      )
      return
    }

    // 'answer' action or body click — open/focus app and trigger answer
    const answerUrl = `/chat?conversation=${notificationData.conversationId}&answerCall=${notificationData.callId}`
    event.waitUntil(
      self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windowClients) => {
        // Try to focus an existing window and tell it to answer
        for (const client of windowClients) {
          if (client.url.includes(self.location.origin) && 'focus' in client) {
            client.postMessage({
              type: 'CALL_ACTION_FROM_NOTIFICATION',
              action: 'answer',
              callId: notificationData.callId,
              conversationId: notificationData.conversationId,
              url: answerUrl
            })
            return client.focus()
          }
        }
        // No existing window — open a new one
        return self.clients.openWindow(answerUrl)
      })
    )
    return
  }

  // ── Standard notification click ──────────────────────────────────
  let url = notificationData.url || '/'

  if (notificationData.type === 'chat' && notificationData.conversationId) {
    url = `/chat?conversation=${notificationData.conversationId}`
  } else if (notificationData.type === 'missed_call' && notificationData.conversationId) {
    url = `/chat?conversation=${notificationData.conversationId}`
  } else if (notificationData.type === 'email' && notificationData.folder) {
    url = `/${notificationData.folder}`
  }

  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windowClients) => {
      // Try to focus an existing window
      for (const client of windowClients) {
        if (client.url.includes(self.location.origin) && 'focus' in client) {
          // Tell the page to navigate
          client.postMessage({
            type: 'NOTIFICATION_CLICK',
            url: url,
            notificationType: notificationData.type,
            conversationId: notificationData.conversationId,
            folder: notificationData.folder,
            uid: notificationData.uid
          })
          return client.focus()
        }
      }
      // No existing window - open a new one
      return self.clients.openWindow(url)
    })
  )
})
