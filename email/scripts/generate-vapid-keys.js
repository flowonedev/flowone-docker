#!/usr/bin/env node
/**
 * VAPID Key Generator for Web Push Notifications
 * 
 * Run this once to generate VAPID keys:
 *   cd mailsync/server && npm install web-push
 *   node ../../scripts/generate-vapid-keys.js
 * 
 * Then add the output to your environment variables (.env files).
 */

import webpush from 'web-push'

const vapidKeys = webpush.generateVAPIDKeys()

console.log('\n=== VAPID Keys Generated ===\n')
console.log('Add these to your environment variables:\n')
console.log(`VAPID_PUBLIC_KEY=${vapidKeys.publicKey}`)
console.log(`VAPID_PRIVATE_KEY=${vapidKeys.privateKey}`)
console.log(`VAPID_SUBJECT=mailto:admin@devcon1.hu`)
console.log('\n--- Add to these files ---')
console.log('1. mailsync/server/.env')
console.log('2. Backend web server environment (Apache/Nginx)')
console.log('\nPublic key goes to frontend (safe to expose).')
console.log('Private key stays on server ONLY.\n')

