<template>
  <Teleport to="body">
    <div
      v-if="visible"
      ref="modal"
      class="fixed bg-surface-900 border border-surface-700 rounded-xl shadow-2xl"
      :style="{ left: position.x + 'px', top: position.y + 'px', width: '460px', maxHeight: '75vh', zIndex: 10000 }"
    >
      <!-- Draggable Header -->
      <div
        @mousedown="startDrag"
        class="flex items-center justify-between px-4 py-3 bg-surface-800 rounded-t-xl cursor-move border-b border-surface-700 select-none"
      >
        <div class="flex items-center gap-2">
          <span class="material-symbols-rounded text-primary-400">lightbulb</span>
          <h3 class="font-semibold text-white">{{ title }}</h3>
          <span v-if="isLive" class="text-xs bg-cyan-500/20 text-cyan-400 px-2 py-0.5 rounded-full flex items-center gap-1">
            <span class="w-1.5 h-1.5 rounded-full bg-cyan-400 animate-pulse"></span>
            Live
          </span>
          <span v-if="matchCount > 0" class="text-xs bg-green-500/20 text-green-400 px-2 py-0.5 rounded-full">
            {{ matchCount }}/{{ totalCount }} OK
          </span>
        </div>
        <button @click="$emit('close')" class="p-1 hover:bg-surface-700 rounded-lg transition-colors">
          <span class="material-symbols-rounded text-surface-400 hover:text-white">close</span>
        </button>
      </div>

      <!-- Content -->
      <div class="p-4 overflow-y-auto" style="max-height: calc(75vh - 60px)">
        
        <!-- Dynamic Dependencies Section -->
        <div v-if="dependencies.length > 0" class="mb-4">
          <h4 class="flex items-center gap-2 text-sm font-semibold text-purple-400 mb-2">
            <span class="material-symbols-rounded text-base">link</span>
            Dependencies
          </h4>
          <div class="space-y-2">
            <div 
              v-for="dep in dependencies" 
              :key="dep.id"
              :class="[
                'rounded-lg p-3 flex items-start gap-3',
                dep.met ? 'bg-green-500/10 border border-green-500/30' : 'bg-purple-500/10 border border-purple-500/20'
              ]"
            >
              <span 
                :class="['material-symbols-rounded text-lg mt-0.5', dep.met ? 'text-green-400' : 'text-purple-400']"
              >{{ dep.met ? 'check_circle' : 'pending' }}</span>
              <div class="flex-1">
                <p :class="['text-sm font-medium', dep.met ? 'text-green-300' : 'text-purple-300']">{{ dep.label }}</p>
                <p class="text-xs text-surface-400">{{ dep.description }}</p>
                <code v-if="dep.value && !dep.met" class="text-xs text-orange-400 mt-1 block">{{ dep.value }}</code>
              </div>
            </div>
          </div>
        </div>

        <!-- Scaling Recommendations (for PHP/OLS) -->
        <div v-if="scalingRecommendations.length > 0" class="mb-4">
          <h4 class="flex items-center gap-2 text-sm font-semibold text-cyan-400 mb-2">
            <span class="material-symbols-rounded text-base">trending_up</span>
            Scaling ({{ siteCount }} sites)
          </h4>
          <div class="space-y-2">
            <div 
              v-for="rec in scalingRecommendations" 
              :key="rec.key"
              :class="[
                'rounded-lg p-3',
                isScalingMatch(rec) ? 'bg-green-500/10 border border-green-500/30' : 'bg-cyan-500/10 border border-cyan-500/20'
              ]"
            >
              <div class="flex items-center justify-between mb-1">
                <div class="flex items-center gap-2">
                  <span 
                    :class="['material-symbols-rounded text-sm', isScalingMatch(rec) ? 'text-green-400' : 'text-cyan-400']"
                  >{{ isScalingMatch(rec) ? 'check_circle' : 'tune' }}</span>
                  <code :class="['text-xs font-mono', isScalingMatch(rec) ? 'text-green-300' : 'text-cyan-300']">{{ rec.key }}</code>
                </div>
                <button @click="copyToClipboard(formatConfigLine(rec.key, rec.value), rec.key)" class="p-1 hover:bg-surface-700 rounded transition-colors" :title="copiedKey === rec.key ? 'Copied!' : 'Copy config line'">
                  <span class="material-symbols-rounded text-sm" :class="copiedKey === rec.key ? 'text-green-400' : 'text-surface-400'">{{ copiedKey === rec.key ? 'check' : 'content_copy' }}</span>
                </button>
              </div>
              <div class="flex items-center gap-2">
                <code 
                  class="text-xs font-mono block bg-surface-950 px-2 py-1 rounded flex-1 select-all cursor-pointer transition-colors" 
                  :class="copiedKey === rec.key ? 'text-green-400 bg-green-950/50' : 'text-white/90'"
                  @click="copyToClipboard(formatConfigLine(rec.key, rec.value), rec.key)" 
                  title="Click to copy"
                >{{ formatConfigLine(rec.key, rec.value) }}</code>
                <span v-if="copiedKey === rec.key" class="text-xs text-green-400 whitespace-nowrap">Copied!</span>
                <span v-else-if="isScalingMatch(rec)" class="text-xs text-green-400 whitespace-nowrap">✓ OK</span>
              </div>
              <p class="text-xs text-surface-400 mt-1">{{ rec.description }}</p>
              <div class="flex gap-2 mt-2 text-xs">
                <span class="px-2 py-0.5 rounded bg-surface-800 text-surface-400">≤5: {{ rec.small }}</span>
                <span class="px-2 py-0.5 rounded bg-surface-800 text-surface-400">≤15: {{ rec.medium }}</span>
                <span class="px-2 py-0.5 rounded bg-surface-800 text-surface-400">20+: {{ rec.large }}</span>
              </div>
            </div>
          </div>
        </div>

        <!-- Security Must-Haves -->
        <div v-if="guide.security?.length" class="mb-4">
          <h4 class="flex items-center gap-2 text-sm font-semibold text-red-400 mb-2">
            <span class="material-symbols-rounded text-base">shield</span>
            Security (Must Enable)
            <span class="text-xs text-surface-500 font-normal ml-auto">{{ countMatches(guide.security) }}/{{ guide.security.length }}</span>
          </h4>
          <div class="space-y-2">
            <div 
              v-for="item in guide.security" 
              :key="item.key" 
              :class="[
                'rounded-lg p-3 transition-all',
                isMatch(item) 
                  ? 'bg-green-500/10 border border-green-500/30' 
                  : 'bg-red-500/10 border border-red-500/20'
              ]"
            >
              <div class="flex items-center justify-between mb-1">
                <div class="flex items-center gap-2 group relative">
                  <span 
                    v-if="isMatch(item)" 
                    class="material-symbols-rounded text-sm text-green-400"
                  >check_circle</span>
                  <span v-else class="material-symbols-rounded text-sm text-red-400">error</span>
                  <code 
                    :class="['text-xs font-mono cursor-help', isMatch(item) ? 'text-green-300' : 'text-red-300']"
                  >{{ item.key }}</code>
                  <span 
                    v-if="item.tooltip" 
                    class="material-symbols-rounded text-xs text-surface-500 cursor-help"
                    :title="item.tooltip"
                  >info</span>
                  <!-- Tooltip popup -->
                  <div v-if="item.tooltip" class="guide-tooltip">
                    {{ item.tooltip }}
                  </div>
                </div>
                <div class="flex items-center gap-1">
                  <button 
                    v-if="item.file" 
                    @click="emit('open-file', item.file)" 
                    class="file-badge"
                    :title="`Edit ${item.file}`"
                  >
                    <span class="material-symbols-rounded text-xs">edit_document</span>
                    {{ getFileName(item.file) }}
                  </button>
                  <button @click="copyToClipboard(formatConfigLine(item.key, item.value), item.key)" class="p-1 hover:bg-surface-700 rounded transition-colors" :title="copiedKey === item.key ? 'Copied!' : 'Copy config line'">
                    <span class="material-symbols-rounded text-sm" :class="copiedKey === item.key ? 'text-green-400' : 'text-surface-400'">{{ copiedKey === item.key ? 'check' : 'content_copy' }}</span>
                  </button>
                </div>
              </div>
              <div class="flex items-center gap-2">
                <code 
                  class="text-xs font-mono block bg-surface-950 px-2 py-1 rounded flex-1 select-all cursor-pointer transition-colors" 
                  :class="copiedKey === item.key ? 'text-green-400 bg-green-950/50' : 'text-white/90'"
                  @click="copyToClipboard(formatConfigLine(item.key, item.value), item.key)" 
                  title="Click to copy"
                >{{ formatConfigLine(item.key, item.value) }}</code>
                <span v-if="copiedKey === item.key" class="text-xs text-green-400 whitespace-nowrap">Copied!</span>
                <span v-else-if="isMatch(item)" class="text-xs text-green-400 whitespace-nowrap">✓ Set</span>
              </div>
              <div v-if="!isMatch(item) && getCurrentValue(item.key)" class="mt-1 text-xs text-surface-500">
                Current: <code class="text-orange-400">{{ formatConfigLine(item.key, getCurrentValue(item.key)) }}</code>
              </div>
              <p class="text-xs text-surface-400 mt-1">{{ item.description }}</p>
            </div>
          </div>
        </div>

        <!-- Recommended Settings -->
        <div v-if="guide.recommended?.length" class="mb-4">
          <h4 class="flex items-center gap-2 text-sm font-semibold text-yellow-400 mb-2">
            <span class="material-symbols-rounded text-base">star</span>
            Recommended
            <span class="text-xs text-surface-500 font-normal ml-auto">{{ countMatches(guide.recommended) }}/{{ guide.recommended.length }}</span>
          </h4>
          <div class="space-y-2">
            <div 
              v-for="item in guide.recommended" 
              :key="item.key" 
              :class="[
                'rounded-lg p-3 transition-all',
                isMatch(item) 
                  ? 'bg-green-500/10 border border-green-500/30' 
                  : 'bg-yellow-500/10 border border-yellow-500/20'
              ]"
            >
              <div class="flex items-center justify-between mb-1">
                <div class="flex items-center gap-2 group relative">
                  <span v-if="isMatch(item)" class="material-symbols-rounded text-sm text-green-400">check_circle</span>
                  <span v-else class="material-symbols-rounded text-sm text-yellow-400">info</span>
                  <code 
                    :class="['text-xs font-mono cursor-help', isMatch(item) ? 'text-green-300' : 'text-yellow-300']"
                  >{{ item.key }}</code>
                  <span 
                    v-if="item.tooltip" 
                    class="material-symbols-rounded text-xs text-surface-500 cursor-help"
                    :title="item.tooltip"
                  >info</span>
                  <!-- Tooltip popup -->
                  <div v-if="item.tooltip" class="guide-tooltip">
                    {{ item.tooltip }}
                  </div>
                </div>
                <div class="flex items-center gap-1">
                  <button 
                    v-if="item.file" 
                    @click="emit('open-file', item.file)" 
                    class="file-badge"
                    :title="`Edit ${item.file}`"
                  >
                    <span class="material-symbols-rounded text-xs">edit_document</span>
                    {{ getFileName(item.file) }}
                  </button>
                  <button @click="copyToClipboard(formatConfigLine(item.key, item.value), item.key)" class="p-1 hover:bg-surface-700 rounded transition-colors" :title="copiedKey === item.key ? 'Copied!' : 'Copy config line'">
                    <span class="material-symbols-rounded text-sm" :class="copiedKey === item.key ? 'text-green-400' : 'text-surface-400'">{{ copiedKey === item.key ? 'check' : 'content_copy' }}</span>
                  </button>
                </div>
              </div>
              <div class="flex items-center gap-2">
                <code 
                  class="text-xs font-mono block bg-surface-950 px-2 py-1 rounded flex-1 select-all cursor-pointer transition-colors" 
                  :class="copiedKey === item.key ? 'text-green-400 bg-green-950/50' : 'text-white/90'"
                  @click="copyToClipboard(formatConfigLine(item.key, item.value), item.key)" 
                  title="Click to copy"
                >{{ formatConfigLine(item.key, item.value) }}</code>
                <span v-if="copiedKey === item.key" class="text-xs text-green-400 whitespace-nowrap">Copied!</span>
                <span v-else-if="isMatch(item)" class="text-xs text-green-400 whitespace-nowrap">✓ Set</span>
              </div>
              <div v-if="!isMatch(item) && getCurrentValue(item.key)" class="mt-1 text-xs text-surface-500">
                Current: <code class="text-orange-400">{{ formatConfigLine(item.key, getCurrentValue(item.key)) }}</code>
              </div>
              <p class="text-xs text-surface-400 mt-1">{{ item.description }}</p>
            </div>
          </div>
        </div>

        <!-- Performance Tuning -->
        <div v-if="guide.performance?.length" class="mb-4">
          <h4 class="flex items-center gap-2 text-sm font-semibold text-blue-400 mb-2">
            <span class="material-symbols-rounded text-base">speed</span>
            Performance
            <span class="text-xs text-surface-500 font-normal ml-auto">{{ countMatches(guide.performance) }}/{{ guide.performance.length }}</span>
          </h4>
          <div class="space-y-2">
            <div 
              v-for="item in guide.performance" 
              :key="item.key" 
              :class="[
                'rounded-lg p-3 transition-all',
                isMatch(item) 
                  ? 'bg-green-500/10 border border-green-500/30' 
                  : 'bg-blue-500/10 border border-blue-500/20'
              ]"
            >
              <div class="flex items-center justify-between mb-1">
                <div class="flex items-center gap-2 group relative">
                  <span v-if="isMatch(item)" class="material-symbols-rounded text-sm text-green-400">check_circle</span>
                  <span v-else class="material-symbols-rounded text-sm text-blue-400">tune</span>
                  <code 
                    :class="['text-xs font-mono cursor-help', isMatch(item) ? 'text-green-300' : 'text-blue-300']"
                  >{{ item.key }}</code>
                  <span 
                    v-if="item.tooltip" 
                    class="material-symbols-rounded text-xs text-surface-500 cursor-help"
                    :title="item.tooltip"
                  >info</span>
                  <!-- Tooltip popup -->
                  <div v-if="item.tooltip" class="guide-tooltip">
                    {{ item.tooltip }}
                  </div>
                </div>
                <div class="flex items-center gap-1">
                  <button 
                    v-if="item.file" 
                    @click="emit('open-file', item.file)" 
                    class="file-badge"
                    :title="`Edit ${item.file}`"
                  >
                    <span class="material-symbols-rounded text-xs">edit_document</span>
                    {{ getFileName(item.file) }}
                  </button>
                  <button @click="copyToClipboard(formatConfigLine(item.key, item.value), item.key)" class="p-1 hover:bg-surface-700 rounded transition-colors" :title="copiedKey === item.key ? 'Copied!' : 'Copy config line'">
                    <span class="material-symbols-rounded text-sm" :class="copiedKey === item.key ? 'text-green-400' : 'text-surface-400'">{{ copiedKey === item.key ? 'check' : 'content_copy' }}</span>
                  </button>
                </div>
              </div>
              <div class="flex items-center gap-2">
                <code 
                  class="text-xs font-mono block bg-surface-950 px-2 py-1 rounded flex-1 select-all cursor-pointer transition-colors" 
                  :class="copiedKey === item.key ? 'text-green-400 bg-green-950/50' : 'text-white/90'"
                  @click="copyToClipboard(formatConfigLine(item.key, item.value), item.key)" 
                  title="Click to copy"
                >{{ formatConfigLine(item.key, item.value) }}</code>
                <span v-if="copiedKey === item.key" class="text-xs text-green-400 whitespace-nowrap">Copied!</span>
                <span v-else-if="isMatch(item)" class="text-xs text-green-400 whitespace-nowrap">✓ Set</span>
              </div>
              <div v-if="!isMatch(item) && getCurrentValue(item.key)" class="mt-1 text-xs text-surface-500">
                Current: <code class="text-orange-400">{{ formatConfigLine(item.key, getCurrentValue(item.key)) }}</code>
              </div>
              <p class="text-xs text-surface-400 mt-1">{{ item.description }}</p>
            </div>
          </div>
        </div>

        <!-- Notes -->
        <div v-if="guide.notes?.length" class="mb-2">
          <h4 class="flex items-center gap-2 text-sm font-semibold text-surface-300 mb-2">
            <span class="material-symbols-rounded text-base">info</span>
            Notes
          </h4>
          <ul class="text-xs text-surface-400 space-y-1 list-disc list-inside">
            <li v-for="(note, i) in guide.notes" :key="i">{{ note }}</li>
          </ul>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { ref, computed, onUnmounted } from 'vue'

const props = defineProps({
  visible: Boolean,
  service: String,
  currentSettings: {
    type: Object,
    default: () => ({})
  },
  // Indicates if showing live parsed values from editor
  isLive: {
    type: Boolean,
    default: false
  },
  // Context for dynamic recommendations
  hostname: {
    type: String,
    default: ''
  },
  siteCount: {
    type: Number,
    default: 5
  },
  sslStatus: {
    type: Object,
    default: () => ({})
  }
})

const emit = defineEmits(['close', 'open-file'])

const modal = ref(null)
const position = ref({ x: window.innerWidth - 500, y: 80 })
const isDragging = ref(false)
const dragOffset = ref({ x: 0, y: 0 })

const title = computed(() => {
  const titles = {
    ssh: 'SSH Best Practices',
    postfix: 'Postfix Best Practices',
    dovecot: 'Dovecot Best Practices',
    mysql: 'MySQL Best Practices',
    php: 'PHP Best Practices',
    ols: 'OpenLiteSpeed Best Practices',
    pdns: 'PowerDNS Best Practices',
  }
  return titles[props.service] || 'Configuration Guide'
})

// Dynamic dependencies based on service
const dependencies = computed(() => {
  const deps = []
  const settings = props.currentSettings || {}
  const host = props.hostname || 'your-domain.com'
  
  if (props.service === 'dovecot') {
    // Dovecot needs SSL certificates
    deps.push({
      id: 'ssl_cert',
      label: `SSL Certificate for mail.${host}`,
      description: 'IMAP/POP3 require valid SSL certificates for secure connections',
      met: settings.ssl_cert && settings.ssl_cert !== 'cert.pem' && !settings.ssl_cert.includes('snakeoil'),
      value: settings.ssl_cert ? `Current: ${settings.ssl_cert}` : 'Not configured'
    })
    deps.push({
      id: 'postfix_running',
      label: 'Postfix SMTP configured',
      description: 'Dovecot works with Postfix for mail delivery (LMTP/LDA)',
      met: true, // We assume Postfix is configured
      value: null
    })
  }
  
  if (props.service === 'postfix') {
    deps.push({
      id: 'ssl_cert',
      label: `SSL Certificate for mail.${host}`,
      description: 'SMTP TLS requires valid SSL certificates',
      met: settings.smtpd_tls_cert_file && settings.smtpd_tls_cert_file.includes('letsencrypt'),
      value: settings.smtpd_tls_cert_file || 'Not configured'
    })
    deps.push({
      id: 'dns_mx',
      label: `MX record pointing to mail.${host}`,
      description: 'DNS MX records required for receiving mail',
      met: true, // Would need DNS check
      value: null
    })
    deps.push({
      id: 'dkim',
      label: 'DKIM signing configured (OpenDKIM)',
      description: 'DKIM improves deliverability and prevents spoofing',
      met: settings.smtpd_milters && settings.smtpd_milters.includes('8891'),
      value: settings.smtpd_milters || 'No milters configured'
    })
  }
  
  if (props.service === 'mysql') {
    deps.push({
      id: 'backup',
      label: 'Automated backups configured',
      description: 'Regular database backups are critical',
      met: true, // Assume yes
      value: null
    })
  }
  
  if (props.service === 'php') {
    deps.push({
      id: 'opcache',
      label: 'OPcache extension loaded',
      description: 'OPcache dramatically improves PHP performance',
      met: settings['opcache.enable'] === '1' || settings['opcache.enable'] === 1,
      value: settings['opcache.enable'] ? `opcache.enable = ${settings['opcache.enable']}` : 'OPcache not enabled'
    })
    deps.push({
      id: 'redis',
      label: 'Redis for session storage (recommended)',
      description: 'Redis provides faster session handling than files',
      met: settings['session.save_handler'] === 'redis',
      value: `session.save_handler = ${settings['session.save_handler'] || 'files'}`
    })
  }
  
  return deps
})

// Scaling recommendations for PHP/OLS
const scalingRecommendations = computed(() => {
  const count = props.siteCount || 5
  const settings = props.currentSettings || {}
  
  if (props.service === 'php') {
    return [
      {
        key: 'memory_limit',
        description: 'Per-script memory limit',
        small: '128M',
        medium: '256M',
        large: '512M',
        value: count <= 5 ? '128M' : count <= 15 ? '256M' : '512M',
        current: settings.memory_limit
      },
      {
        key: 'max_execution_time',
        description: 'Script timeout in seconds',
        small: '30',
        medium: '60',
        large: '120',
        value: count <= 5 ? '30' : count <= 15 ? '60' : '120',
        current: settings.max_execution_time
      },
      {
        key: 'opcache.memory_consumption',
        description: 'OPcache memory in MB',
        small: '128',
        medium: '256',
        large: '512',
        value: count <= 5 ? '128' : count <= 15 ? '256' : '512',
        current: settings['opcache.memory_consumption']
      },
      {
        key: 'opcache.max_accelerated_files',
        description: 'Max files in OPcache',
        small: '4000',
        medium: '10000',
        large: '20000',
        value: count <= 5 ? '4000' : count <= 15 ? '10000' : '20000',
        current: settings['opcache.max_accelerated_files']
      },
      {
        key: 'realpath_cache_size',
        description: 'Path resolution cache',
        small: '2M',
        medium: '4M',
        large: '8M',
        value: count <= 5 ? '2M' : count <= 15 ? '4M' : '8M',
        current: settings.realpath_cache_size
      },
    ]
  }
  
  if (props.service === 'ols') {
    return [
      {
        key: 'PHP LSAPI Children',
        description: 'PHP worker processes per vhost',
        small: '10',
        medium: '25',
        large: '50',
        value: count <= 5 ? '10' : count <= 15 ? '25' : '50',
        current: settings.maxChildren
      },
      {
        key: 'Max Connections',
        description: 'Max concurrent connections',
        small: '2000',
        medium: '5000',
        large: '10000',
        value: count <= 5 ? '2000' : count <= 15 ? '5000' : '10000',
        current: settings.maxConnections
      },
      {
        key: 'Max Keep-Alive Requests',
        description: 'Requests per keep-alive connection',
        small: '1000',
        medium: '5000',
        large: '10000',
        value: count <= 5 ? '1000' : count <= 15 ? '5000' : '10000',
        current: settings.maxKeepAliveReq
      },
    ]
  }
  
  return []
})

// Check if scaling recommendation matches
const isScalingMatch = (rec) => {
  if (!rec.current) return false
  const normalized = normalizeValue(rec.current)
  const target = normalizeValue(rec.value)
  
  // Parse numeric values
  const numCurrent = parseFloat(normalized.replace(/[mk]/gi, ''))
  const numTarget = parseFloat(target.replace(/[mk]/gi, ''))
  
  if (!isNaN(numCurrent) && !isNaN(numTarget)) {
    // Allow some flexibility - within 50% is OK
    return numCurrent >= numTarget * 0.5 && numCurrent <= numTarget * 2
  }
  
  return normalized === target
}

// Key mappings for different naming conventions
const keyMappings = {
  'PermitRootLogin': 'permit_root_login',
  'PasswordAuthentication': 'password_authentication',
  'PubkeyAuthentication': 'pubkey_authentication',
  'PermitEmptyPasswords': 'permit_empty_passwords',
  'X11Forwarding': 'x11_forwarding',
  'MaxAuthTries': 'max_auth_tries',
  'LoginGraceTime': 'login_grace_time',
  'ClientAliveInterval': 'client_alive_interval',
  'ClientAliveCountMax': 'client_alive_count_max',
  'UseDNS': 'use_dns',
  'bind-address': 'bind_address',
  'skip-name-resolve': 'skip_name_resolve',
  'local-infile': 'local_infile',
}

const normalizeValue = (val) => {
  if (val === null || val === undefined) return ''
  let str = String(val).toLowerCase().trim()
  
  // Remove Dovecot's < prefix for file paths
  if (str.startsWith('<')) str = str.substring(1)
  
  // Normalize boolean-like values
  if (['yes', 'on', 'true', '1', 'enabled'].includes(str)) return 'yes'
  if (['no', 'off', 'false', '0', 'disabled'].includes(str)) return 'no'
  if (str === 'required') return 'yes'
  
  // Remove extra whitespace in lists
  str = str.replace(/\s*,\s*/g, ', ').replace(/\s+/g, ' ')
  
  return str
}

const getSettingKey = (guideKey) => {
  if (keyMappings[guideKey]) return keyMappings[guideKey]
  return guideKey
}

const getCurrentValue = (guideKey) => {
  const key = getSettingKey(guideKey)
  const settings = props.currentSettings || {}
  
  if (settings[key] !== undefined) return settings[key]
  if (settings[key.toLowerCase()] !== undefined) return settings[key.toLowerCase()]
  
  const altKey1 = key.replace(/_/g, '-')
  const altKey2 = key.replace(/-/g, '_')
  if (settings[altKey1] !== undefined) return settings[altKey1]
  if (settings[altKey2] !== undefined) return settings[altKey2]
  
  return null
}

const isMatch = (item) => {
  const currentVal = getCurrentValue(item.key)
  if (currentVal === null || currentVal === undefined) return false
  
  const normalizedCurrent = normalizeValue(currentVal)
  const normalizedRecommended = normalizeValue(item.value)
  
  // Exact match
  if (normalizedCurrent === normalizedRecommended) return true
  
  // Numeric comparison
  const numCurrent = parseFloat(normalizedCurrent)
  const numRecommended = parseFloat(normalizedRecommended)
  if (!isNaN(numCurrent) && !isNaN(numRecommended) && numCurrent === numRecommended) return true
  
  // File path comparison (ignore the < prefix and compare paths)
  if (item.key.includes('cert') || item.key.includes('key') || item.key.includes('_file')) {
    const currentPath = normalizedCurrent.replace(/^</, '')
    const recommendedPath = normalizedRecommended.replace(/^</, '')
    // Check if paths match (both with or without < prefix)
    if (currentPath === recommendedPath) return true
    // Check if just the filename/structure matches (e.g., fullchain.pem, privkey.pem)
    if (currentPath.endsWith('/fullchain.pem') && recommendedPath.endsWith('/fullchain.pem')) return true
    if (currentPath.endsWith('/privkey.pem') && recommendedPath.endsWith('/privkey.pem')) return true
    if (currentPath.endsWith('/chain.pem') && recommendedPath.endsWith('/chain.pem')) return true
  }
  
  // Partial match for complex values (like restriction lists)
  if (normalizedRecommended.includes(',')) {
    const recommendedParts = normalizedRecommended.split(',').map(p => p.trim())
    const currentParts = normalizedCurrent.split(',').map(p => p.trim())
    // Check if current has at least all required parts
    const hasAllRequired = recommendedParts.every(part => 
      currentParts.some(cp => cp.includes(part) || part.includes(cp))
    )
    if (hasAllRequired) return true
  }
  
  // For auth_mechanisms: "plain" is essential, "login" is optional for legacy compatibility
  if (item.key === 'auth_mechanisms') {
    const currentMechs = normalizedCurrent.split(/\s+/)
    // As long as 'plain' is present, consider it OK (login is legacy/optional)
    if (currentMechs.includes('plain')) return true
  }
  
  // For space-separated lists, check if current contains the recommended parts
  if (normalizedRecommended.includes(' ') && !normalizedRecommended.includes('=')) {
    const recommendedParts = normalizedRecommended.split(/\s+/)
    const currentParts = normalizedCurrent.split(/\s+/)
    // Check if essential parts are present
    const hasEssential = recommendedParts.some(part => currentParts.includes(part))
    if (hasEssential && currentParts.length > 0) return true
  }
  
  return false
}

const countMatches = (items) => {
  if (!items) return 0
  return items.filter(item => isMatch(item)).length
}

const totalCount = computed(() => {
  const g = guide.value
  return (g.security?.length || 0) + (g.recommended?.length || 0) + (g.performance?.length || 0)
})

const matchCount = computed(() => {
  const g = guide.value
  return countMatches(g.security) + countMatches(g.recommended) + countMatches(g.performance)
})

const guides = {
  ssh: {
    security: [
      { key: 'PermitRootLogin', value: 'prohibit-password', file: '/etc/ssh/sshd_config', description: 'Allow root only with SSH keys, never password', tooltip: 'Allows root login only via SSH keys. Password-based root login is a major security risk - bots constantly try common passwords.' },
      { key: 'PasswordAuthentication', value: 'no', file: '/etc/ssh/sshd_config', description: 'Disable password auth (use SSH keys only)', tooltip: 'CRITICAL: Disables password-based login entirely. SSH keys are far more secure. Make sure you have a working key before enabling!' },
      { key: 'PubkeyAuthentication', value: 'yes', file: '/etc/ssh/sshd_config', description: 'Enable SSH key authentication', tooltip: 'Enables authentication using SSH key pairs. Required when you disable password authentication.' },
      { key: 'PermitEmptyPasswords', value: 'no', file: '/etc/ssh/sshd_config', description: 'Never allow empty passwords', tooltip: 'Prevents accounts with no password from logging in via SSH. Always keep this disabled.' },
      { key: 'X11Forwarding', value: 'no', file: '/etc/ssh/sshd_config', description: 'Disable X11 unless needed', tooltip: 'X11 forwarding can be a security risk. Only enable if you specifically need graphical app forwarding.' },
    ],
    recommended: [
      { key: 'MaxAuthTries', value: '3', file: '/etc/ssh/sshd_config', description: 'Limit failed auth attempts', tooltip: 'Disconnects after 3 failed attempts. Works with fail2ban to prevent brute force attacks.' },
      { key: 'LoginGraceTime', value: '60', file: '/etc/ssh/sshd_config', description: 'Time limit for login (seconds)', tooltip: 'Disconnects if authentication takes too long. Prevents connections from hanging indefinitely.' },
      { key: 'ClientAliveInterval', value: '300', file: '/etc/ssh/sshd_config', description: 'Keep-alive interval (5 min)', tooltip: 'Sends a keep-alive message every 300 seconds. Helps detect dead connections.' },
      { key: 'ClientAliveCountMax', value: '2', file: '/etc/ssh/sshd_config', description: 'Disconnect after 2 missed keep-alives', tooltip: 'With interval of 300s and count of 2, dead connections are closed after ~10 minutes.' },
      { key: 'UseDNS', value: 'no', file: '/etc/ssh/sshd_config', description: 'Disable DNS lookups (faster login)', tooltip: 'Skips reverse DNS lookups during login. Speeds up connections, especially when DNS is slow.' },
    ],
    notes: [
      'Config file: /etc/ssh/sshd_config',
      'Always test SSH config before disconnecting: sshd -t',
      'Keep a backup session open when making changes',
      'Use fail2ban for brute force protection',
    ],
  },
  postfix: {
    security: [
      { 
        key: 'smtpd_helo_required', 
        value: 'yes', 
        file: '/etc/postfix/main.cf',
        description: 'Require HELO/EHLO command',
        tooltip: 'Forces clients to identify themselves before sending. Spambots often skip this step - enabling it blocks simple spam attacks.' 
      },
      { 
        key: 'disable_vrfy_command', 
        value: 'yes', 
        file: '/etc/postfix/main.cf',
        description: 'Disable user enumeration',
        tooltip: 'Prevents attackers from checking which email addresses exist on your server. VRFY command can be abused to harvest valid emails.' 
      },
      { 
        key: 'smtpd_tls_security_level', 
        value: 'may', 
        file: '/etc/postfix/main.cf',
        description: 'Enable TLS for incoming SMTP',
        tooltip: 'Enables encryption for incoming mail. Use "may" (opportunistic) for port 25, or "encrypt" for submission ports (587/465).' 
      },
      { 
        key: 'smtpd_tls_auth_only', 
        value: 'yes', 
        file: '/etc/postfix/main.cf',
        description: 'Only allow auth over TLS',
        tooltip: 'CRITICAL: Prevents passwords from being sent in plain text. Without this, login credentials can be intercepted by attackers.' 
      },
      { 
        key: 'smtpd_sasl_auth_enable', 
        value: 'yes', 
        file: '/etc/postfix/main.cf',
        description: 'Enable SASL authentication',
        tooltip: 'Allows authenticated users to send mail. Required for outgoing mail from email clients like Outlook/Thunderbird.' 
      },
      { 
        key: 'smtpd_tls_cert_file', 
        value: '/etc/letsencrypt/live/mail.domain.com/fullchain.pem', 
        file: '/etc/postfix/main.cf',
        description: 'SSL certificate path',
        tooltip: 'Path to your SSL certificate. Use Let\'s Encrypt for free valid certificates. Invalid certs cause connection warnings/failures.' 
      },
      { 
        key: 'smtpd_tls_key_file', 
        value: '/etc/letsencrypt/live/mail.domain.com/privkey.pem', 
        file: '/etc/postfix/main.cf',
        description: 'SSL private key path',
        tooltip: 'Path to your SSL private key. Must match the certificate. Ensure proper permissions (600) for security.' 
      },
    ],
    recommended: [
      { 
        key: 'smtpd_helo_restrictions', 
        value: 'permit_mynetworks, permit_sasl_authenticated, reject_invalid_helo_hostname, reject_non_fqdn_helo_hostname', 
        file: '/etc/postfix/main.cf',
        description: 'HELO validation rules',
        tooltip: 'Validates that the HELO hostname is valid and fully qualified. Blocks mail from servers with malformed or fake hostnames.' 
      },
      { 
        key: 'smtpd_sender_restrictions', 
        value: 'permit_mynetworks, permit_sasl_authenticated, reject_non_fqdn_sender, reject_unknown_sender_domain', 
        file: '/etc/postfix/main.cf',
        description: 'Sender validation rules',
        tooltip: 'Validates sender addresses. Rejects mail from non-existent domains and malformed addresses - common spam indicators.' 
      },
      { 
        key: 'smtpd_recipient_restrictions', 
        value: 'permit_mynetworks, permit_sasl_authenticated, reject_unauth_destination, reject_non_fqdn_recipient', 
        file: '/etc/postfix/main.cf',
        description: 'Recipient validation',
        tooltip: 'Controls who can relay mail through your server. The reject_unauth_destination prevents your server from being an open relay.' 
      },
      { 
        key: 'smtpd_relay_restrictions', 
        value: 'permit_mynetworks, permit_sasl_authenticated, defer_unauth_destination', 
        file: '/etc/postfix/main.cf',
        description: 'Relay access control',
        tooltip: 'Modern Postfix uses this for relay control. Prevents unauthorized relay while allowing authenticated users.' 
      },
      { 
        key: 'smtpd_delay_reject', 
        value: 'yes', 
        file: '/etc/postfix/main.cf',
        description: 'Delay rejection for better logging',
        tooltip: 'Collects more information before rejecting. Helps identify spam sources and provides better logs for troubleshooting.' 
      },
      { 
        key: 'message_size_limit', 
        value: '52428800', 
        file: '/etc/postfix/main.cf',
        description: '50MB max message size',
        tooltip: 'Limits attachment sizes to prevent abuse and disk fill attacks. 50MB is generous for most use cases. Adjust based on needs.' 
      },
      { 
        key: 'smtp_tls_security_level', 
        value: 'may', 
        file: '/etc/postfix/main.cf',
        description: 'Use TLS when available for outbound',
        tooltip: 'Opportunistic encryption for outgoing mail. Uses TLS when the receiving server supports it, improving privacy.' 
      },
      { 
        key: 'mailbox_size_limit', 
        value: '0', 
        file: '/etc/postfix/main.cf',
        description: 'Mailbox size (0=unlimited)',
        tooltip: 'Set to 0 for unlimited, or specify bytes. Consider using quotas at the IMAP level (Dovecot) for better control.' 
      },
      { 
        key: 'virtual_mailbox_domains', 
        value: 'proxy:mysql:/etc/postfix/mysql-virtual_domains.cf', 
        file: '/etc/postfix/main.cf',
        description: 'Virtual domain lookup',
        tooltip: 'Database lookup for hosted domains. The proxy: prefix uses proxymap for caching and reduces database connections.' 
      },
      { 
        key: 'virtual_mailbox_maps', 
        value: 'proxy:mysql:/etc/postfix/mysql-virtual_mailboxes.cf', 
        file: '/etc/postfix/main.cf',
        description: 'Virtual mailbox lookup',
        tooltip: 'Database lookup for mailbox locations. The proxy: prefix improves performance by caching lookups.' 
      },
    ],
    performance: [
      { 
        key: 'default_process_limit', 
        value: '100', 
        file: '/etc/postfix/main.cf',
        description: 'Max concurrent processes',
        tooltip: 'Limits resource usage. Too low causes delays during high load; too high can exhaust system resources.' 
      },
      { 
        key: 'smtpd_client_connection_count_limit', 
        value: '10', 
        file: '/etc/postfix/main.cf',
        description: 'Connections per IP',
        tooltip: 'Prevents single IPs from monopolizing connections. Protects against connection-based DoS attacks.' 
      },
      { 
        key: 'smtpd_client_message_rate_limit', 
        value: '100', 
        file: '/etc/postfix/main.cf',
        description: 'Messages per client/time unit',
        tooltip: 'Limits emails per client to prevent spammers from sending thousands of messages through a single session.' 
      },
      { 
        key: 'smtpd_client_recipient_rate_limit', 
        value: '100', 
        file: '/etc/postfix/main.cf',
        description: 'Recipients per client/time unit',
        tooltip: 'Limits recipients per client. A spammer sending to many recipients hits this limit quickly.' 
      },
      { 
        key: 'anvil_rate_time_unit', 
        value: '60s', 
        file: '/etc/postfix/main.cf',
        description: 'Rate limit time window',
        tooltip: 'Time window for rate limits. 60s means limits apply per minute. Adjust based on legitimate traffic patterns.' 
      },
      { 
        key: 'smtp_destination_concurrency_limit', 
        value: '2', 
        file: '/etc/postfix/main.cf',
        description: 'Concurrent connections per destination',
        tooltip: 'Limits simultaneous connections to each remote server. Prevents your server from overwhelming recipients.' 
      },
      { 
        key: 'smtp_destination_rate_delay', 
        value: '1s', 
        file: '/etc/postfix/main.cf',
        description: 'Delay between messages to same destination',
        tooltip: 'Adds delay between emails to the same server. Helps avoid being flagged as a spam source by rate-limiting receivers.' 
      },
    ],
    notes: [
      'Always run "postfix check" after changes',
      'Test with: postfix reload (not restart)',
      'Check queue: mailq or postqueue -p',
      'View config: postconf -n (non-defaults only)',
      'DKIM signing requires OpenDKIM milter',
      'For submission (port 587): edit master.cf to enable',
    ],
  },
  dovecot: {
    security: [
      { 
        key: 'ssl', 
        value: 'required', 
        file: '/etc/dovecot/conf.d/10-ssl.conf',
        description: 'Require SSL/TLS for all connections',
        tooltip: 'CRITICAL: Forces encrypted connections for IMAP/POP3. Without this, emails and passwords are sent in plain text over the network.' 
      },
      { 
        key: 'ssl_min_protocol', 
        value: 'TLSv1.2', 
        file: '/etc/dovecot/conf.d/10-ssl.conf',
        description: 'Minimum TLS version',
        tooltip: 'TLSv1.0 and TLSv1.1 have known vulnerabilities. TLSv1.2 is the minimum secure version. Consider TLSv1.3 for newer clients.' 
      },
      { 
        key: 'ssl_cert', 
        value: '</etc/letsencrypt/live/mail.domain.com/fullchain.pem', 
        file: '/etc/dovecot/conf.d/10-ssl.conf',
        description: 'SSL certificate (note < prefix)',
        tooltip: 'Path to SSL certificate. The < prefix tells Dovecot to read from file. Use Let\'s Encrypt for free valid certificates.' 
      },
      { 
        key: 'ssl_key', 
        value: '</etc/letsencrypt/live/mail.domain.com/privkey.pem', 
        file: '/etc/dovecot/conf.d/10-ssl.conf',
        description: 'SSL private key (note < prefix)',
        tooltip: 'Path to private key file. The < prefix is required. Ensure file has proper permissions (600) for security.' 
      },
      { 
        key: 'disable_plaintext_auth', 
        value: 'yes', 
        file: '/etc/dovecot/conf.d/10-auth.conf',
        description: 'No plain auth without SSL',
        tooltip: 'CRITICAL: Prevents login credentials from being transmitted unencrypted. Essential for security - attackers could otherwise intercept passwords.' 
      },
      { 
        key: 'auth_mechanisms', 
        value: 'plain login', 
        file: '/etc/dovecot/conf.d/10-auth.conf',
        description: 'Auth methods (over TLS)',
        tooltip: 'PLAIN and LOGIN are secure when used over TLS. These are compatible with all email clients. Avoid deprecated methods like CRAM-MD5.' 
      },
    ],
    recommended: [
      { 
        key: 'ssl_prefer_server_ciphers', 
        value: 'yes', 
        file: '/etc/dovecot/conf.d/10-ssl.conf',
        description: 'Use server cipher preferences',
        tooltip: 'Forces clients to use your preferred cipher order. Ensures the strongest available encryption is used rather than whatever the client prefers.' 
      },
      { 
        key: 'ssl_cipher_list', 
        value: 'ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384', 
        file: '/etc/dovecot/conf.d/10-ssl.conf',
        description: 'Modern cipher suite',
        tooltip: 'Specifies which encryption ciphers to use. This list prioritizes strong, modern ciphers. Older ciphers may be needed for legacy clients.' 
      },
      { 
        key: 'mail_max_userip_connections', 
        value: '20', 
        file: '/etc/dovecot/conf.d/20-imap.conf',
        description: 'Max connections per user/IP',
        tooltip: 'Prevents a single user/IP from exhausting server resources. 20 allows multiple devices while protecting against abuse.' 
      },
      { 
        key: 'auth_verbose', 
        value: 'no', 
        file: '/etc/dovecot/conf.d/10-logging.conf',
        description: 'Disable verbose auth (production)',
        tooltip: 'Reduces log spam in production. Enable temporarily for debugging authentication issues, then disable to save disk space.' 
      },
      { 
        key: 'auth_debug', 
        value: 'no', 
        file: '/etc/dovecot/conf.d/10-logging.conf',
        description: 'Disable auth debug (production)',
        tooltip: 'Detailed auth logging. Only enable when troubleshooting login issues, as it generates significant log volume.' 
      },
      { 
        key: 'mail_debug', 
        value: 'no', 
        file: '/etc/dovecot/conf.d/10-logging.conf',
        description: 'Disable mail debugging (production)',
        tooltip: 'Debug logging generates massive log files and impacts performance. Only enable when actively troubleshooting issues.' 
      },
      { 
        key: 'verbose_ssl', 
        value: 'no', 
        file: '/etc/dovecot/conf.d/10-logging.conf',
        description: 'Disable SSL debug logging',
        tooltip: 'Logs SSL/TLS handshake details. Enable only when diagnosing certificate or encryption issues.' 
      },
      { 
        key: 'login_greeting', 
        value: 'Mail Server Ready', 
        file: '/etc/dovecot/dovecot.conf',
        description: 'Custom login banner',
        tooltip: 'Hides Dovecot version from banner. Reduces information disclosure to potential attackers scanning for vulnerabilities.' 
      },
    ],
    performance: [
      { 
        key: 'mail_location', 
        value: 'maildir:/var/vmail/%d/%n/Maildir', 
        file: '/etc/dovecot/conf.d/10-mail.conf',
        description: 'Maildir format with domain/user',
        tooltip: 'Maildir stores each email as a separate file, enabling concurrent access and preventing corruption. The %d/%n structure organizes by domain/user.' 
      },
      { 
        key: 'mail_uid', 
        value: 'vmail', 
        file: '/etc/dovecot/conf.d/10-mail.conf',
        description: 'Mail storage user',
        tooltip: 'Unix user that owns mail files. Using a dedicated user (vmail) improves security by isolating mail storage.' 
      },
      { 
        key: 'mail_gid', 
        value: 'vmail', 
        file: '/etc/dovecot/conf.d/10-mail.conf',
        description: 'Mail storage group',
        tooltip: 'Unix group for mail files. Should match mail_uid for proper permissions.' 
      },
      { 
        key: 'first_valid_uid', 
        value: '1000', 
        file: '/etc/dovecot/conf.d/10-mail.conf',
        description: 'Minimum allowed UID',
        tooltip: 'Security feature preventing access as system users. Set to your vmail user\'s UID or 1000+ for normal users.' 
      },
      { 
        key: 'mail_plugins', 
        value: '$mail_plugins zlib quota', 
        file: '/etc/dovecot/conf.d/10-mail.conf',
        description: 'Enable compression + quotas',
        tooltip: 'Enables mail compression and quota enforcement. Compression saves 50-70% disk space; quotas prevent runaway mailbox sizes.' 
      },
      { 
        key: 'protocol imap', 
        value: 'mail_plugins = $mail_plugins imap_zlib imap_quota', 
        file: '/etc/dovecot/conf.d/20-imap.conf',
        description: 'IMAP-specific plugins',
        tooltip: 'Enables IMAP compression (bandwidth savings) and quota reporting to email clients.' 
      },
      { 
        key: 'plugin/zlib_save', 
        value: 'gz', 
        file: '/etc/dovecot/conf.d/10-mail.conf',
        description: 'Compress saved mail with gzip',
        tooltip: 'Gzip compression format. Good balance of speed and compression ratio. Alternative: zstd for better ratio but requires newer Dovecot.' 
      },
      { 
        key: 'plugin/zlib_save_level', 
        value: '6', 
        file: '/etc/dovecot/conf.d/10-mail.conf',
        description: 'Compression level (1-9)',
        tooltip: 'Level 6 offers good compression with reasonable CPU usage. Lower for busy servers, higher for maximum storage savings.' 
      },
      { 
        key: 'service imap-login/process_min_avail', 
        value: '2', 
        file: '/etc/dovecot/conf.d/10-master.conf',
        description: 'Pre-forked IMAP processes',
        tooltip: 'Keeps login processes ready for incoming connections. Improves response time for new connections.' 
      },
      { 
        key: 'service imap-login/client_limit', 
        value: '1000', 
        file: '/etc/dovecot/conf.d/10-master.conf',
        description: 'Max clients per process',
        tooltip: 'How many simultaneous connections each login process handles. Higher values use less memory but may impact response time.' 
      },
    ],
    notes: [
      'Test config: doveconf -n (shows non-default settings)',
      'Check auth: doveadm auth test user@domain password',
      'View active connections: doveadm who',
      'SSL certs need < prefix in config files',
      'Reload config: doveadm reload (or systemctl reload dovecot)',
      'Check quotas: doveadm quota get -u user@domain',
    ],
  },
  mysql: {
    security: [
      { 
        key: 'bind-address', 
        value: '127.0.0.1', 
        file: '/etc/mysql/mysql.conf.d/mysqld.cnf',
        description: 'Listen only on localhost',
        tooltip: 'CRITICAL: Prevents remote connections. Only local apps (like your web server) can connect. Never bind to 0.0.0.0 in production.'
      },
      { 
        key: 'skip-name-resolve', 
        value: '1', 
        file: '/etc/mysql/mysql.conf.d/mysqld.cnf',
        description: 'Disable DNS lookups',
        tooltip: 'Improves connection speed by skipping DNS resolution. Use IP addresses in GRANT statements instead of hostnames.'
      },
      { 
        key: 'local-infile', 
        value: '0', 
        file: '/etc/mysql/mysql.conf.d/mysqld.cnf',
        description: 'Disable LOAD DATA LOCAL',
        tooltip: 'Security risk: LOAD DATA LOCAL can be exploited to read files. Disable unless specifically needed.'
      },
    ],
    recommended: [
      { 
        key: 'max_connections', 
        value: '150', 
        file: '/etc/mysql/mysql.conf.d/mysqld.cnf',
        description: 'Max concurrent connections',
        tooltip: 'Adjust based on your app needs. Too low causes "Too many connections" errors. Each connection uses ~256KB RAM.'
      },
      { 
        key: 'wait_timeout', 
        value: '600', 
        file: '/etc/mysql/mysql.conf.d/mysqld.cnf',
        description: 'Close idle connections (10 min)',
        tooltip: 'Closes idle connections after this many seconds. Prevents connection pool exhaustion from abandoned connections.'
      },
      { 
        key: 'interactive_timeout', 
        value: '600', 
        file: '/etc/mysql/mysql.conf.d/mysqld.cnf',
        description: 'Interactive session timeout',
        tooltip: 'Timeout for interactive sessions (mysql CLI). Usually same as wait_timeout.'
      },
      { 
        key: 'sql_mode', 
        value: 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO', 
        file: '/etc/mysql/mysql.conf.d/mysqld.cnf',
        description: 'Strict SQL mode',
        tooltip: 'Enforces data integrity. Prevents silent truncation and invalid dates. Required by many modern frameworks.'
      },
    ],
    performance: [
      { 
        key: 'innodb_buffer_pool_size', 
        value: '1G', 
        file: '/etc/mysql/mysql.conf.d/mysqld.cnf',
        description: '70-80% of RAM for dedicated servers',
        tooltip: 'MOST IMPORTANT: Cache for InnoDB data/indexes. Larger = less disk I/O. For shared servers use 25-50% of RAM.'
      },
      { 
        key: 'innodb_log_file_size', 
        value: '256M', 
        file: '/etc/mysql/mysql.conf.d/mysqld.cnf',
        description: 'Transaction log size',
        tooltip: 'Larger logs improve write performance but increase recovery time. 256M-1G is typical for write-heavy loads.'
      },
      { 
        key: 'innodb_flush_log_at_trx_commit', 
        value: '2', 
        file: '/etc/mysql/mysql.conf.d/mysqld.cnf',
        description: 'Better performance (slight risk)',
        tooltip: '1=safest (flush every commit), 2=flush every second (1s data loss risk on crash), 0=OS decides (fastest, riskiest).'
      },
      { 
        key: 'tmp_table_size', 
        value: '64M', 
        file: '/etc/mysql/mysql.conf.d/mysqld.cnf',
        description: 'Memory for temp tables',
        tooltip: 'Max size for in-memory temp tables. Larger tables go to disk (slower). Match with max_heap_table_size.'
      },
      { 
        key: 'max_heap_table_size', 
        value: '64M', 
        file: '/etc/mysql/mysql.conf.d/mysqld.cnf',
        description: 'Max memory table size',
        tooltip: 'Max size for MEMORY engine tables. Should match tmp_table_size for consistent temp table behavior.'
      },
    ],
    notes: [
      'MySQL: /etc/mysql/mysql.conf.d/mysqld.cnf',
      'MariaDB: /etc/mysql/mariadb.conf.d/50-server.cnf',
      'Test config: mysqld --validate-config',
      'Always backup before changes',
      'Use mysqltuner.pl for recommendations',
      'innodb_buffer_pool_size is most impactful',
    ],
  },
  php: {
    security: [
      { key: 'expose_php', value: 'Off', description: 'Hide PHP version in headers', tooltip: 'Removes X-Powered-By header that reveals PHP version. Minor security improvement but reduces attack surface.' },
      { key: 'display_errors', value: 'Off', description: 'Never show errors in production', tooltip: 'CRITICAL: Prevents error messages from exposing file paths, database queries, and other sensitive info to users.' },
      { key: 'log_errors', value: 'On', description: 'Log errors to file instead', tooltip: 'Logs errors to file for debugging while keeping them hidden from users. Check /var/log/php*.log' },
      { key: 'allow_url_fopen', value: 'Off', description: 'Disable remote file opens', tooltip: 'Prevents file_get_contents() and similar from opening remote URLs. Mitigates SSRF vulnerabilities.' },
      { key: 'allow_url_include', value: 'Off', description: 'Disable remote includes', tooltip: 'CRITICAL: Prevents include/require from loading remote files. Major RFI vulnerability if enabled.' },
      { key: 'disable_functions', value: 'exec,passthru,shell_exec,system,proc_open,popen', description: 'Disable dangerous functions', tooltip: 'Blocks shell command execution. Note: Some apps (like WordPress) may need some functions. Test carefully.' },
    ],
    recommended: [
      { key: 'upload_max_filesize', value: '64M', description: 'Max upload size', tooltip: 'Maximum file upload size. Adjust based on your needs (media uploads, imports, etc.)' },
      { key: 'post_max_size', value: '64M', description: 'Max POST data (>= upload_max)', tooltip: 'Must be >= upload_max_filesize. Total POST request size including all form fields and files.' },
      { key: 'max_input_vars', value: '3000', description: 'Max form variables', tooltip: 'Limits form fields. Increase for complex forms (e.g., WooCommerce variable products). Default 1000 often too low.' },
      { key: 'date.timezone', value: 'UTC', description: 'Set explicit timezone', tooltip: 'Prevents warnings and ensures consistent timestamps. Use your server timezone or UTC for global apps.' },
      { key: 'session.cookie_httponly', value: '1', description: 'Prevent JS access to cookies', tooltip: 'Session cookies cannot be read by JavaScript. Prevents XSS attacks from stealing sessions.' },
      { key: 'session.cookie_secure', value: '1', description: 'Cookies only over HTTPS', tooltip: 'Session cookies only sent over HTTPS. Enable only if your site is fully HTTPS.' },
    ],
    performance: [
      { key: 'opcache.enable', value: '1', description: 'Enable opcode cache', tooltip: 'CRITICAL for performance: Caches compiled PHP code. Can improve response times by 2-3x.' },
      { key: 'opcache.validate_timestamps', value: '0', description: 'Disable stat calls (production)', tooltip: 'Production only: Skips file modification checks. Requires cache clear after deployments. Huge performance boost.' },
      { key: 'opcache.revalidate_freq', value: '60', description: 'Check for changes interval', tooltip: 'How often to check if files changed (in seconds). Only matters if validate_timestamps=1.' },
      { key: 'output_buffering', value: '4096', description: 'Buffer output for performance', tooltip: 'Buffers output before sending. Allows headers to be set anywhere and improves perceived performance.' },
    ],
    notes: [
      'Config file varies by version: /etc/php/X.X/fpm/php.ini',
      'Restart PHP-FPM after changes: systemctl restart phpX.X-fpm',
      'In production: opcache.validate_timestamps=0',
      'Check loaded config: php --ini',
      'OPcache is huge for performance',
    ],
  },
  ols: {
    security: [
      { key: 'Server Signature', value: 'Off', file: '/usr/local/lsws/conf/httpd_config.conf', description: 'Hide server version in headers', tooltip: 'Removes server version from HTTP headers. Reduces information exposed to attackers.' },
      { key: 'Admin Console Access', value: 'localhost only', file: '/usr/local/lsws/admin/conf/admin_config.conf', description: 'Restrict admin panel access', tooltip: 'Only allow admin panel access from localhost. Use SSH tunnel for remote access.' },
    ],
    recommended: [
      { key: 'Connection Timeout', value: '300', file: '/usr/local/lsws/conf/httpd_config.conf', description: 'Keep-alive timeout (seconds)', tooltip: 'How long to keep idle connections open. Higher values reduce connection overhead but use more memory.' },
      { key: 'Max Request Body Size', value: '64M', file: '/usr/local/lsws/conf/httpd_config.conf', description: 'Max upload size', tooltip: 'Maximum size of POST request body. Increase for large file uploads. Also set in PHP.' },
      { key: 'GZIP Compression', value: 'On', file: '/usr/local/lsws/conf/httpd_config.conf', description: 'Enable response compression', tooltip: 'Compresses text responses (HTML, CSS, JS). Reduces bandwidth by 70-90% for text content.' },
    ],
    performance: [
      { key: 'Static File Cache', value: '86400', file: '/usr/local/lsws/conf/httpd_config.conf', description: 'Cache headers for 24 hours', tooltip: 'Sets Cache-Control headers for static files. Reduces server load and improves client performance.' },
      { key: 'Smart Keep-Alive', value: 'On', file: '/usr/local/lsws/conf/httpd_config.conf', description: 'Intelligent connection management', tooltip: 'LiteSpeed feature that optimizes keep-alive based on server load and client behavior.' },
    ],
    notes: [
      'Main config: /usr/local/lsws/conf/httpd_config.conf',
      'Graceful restart: /usr/local/lsws/bin/lswsctrl restart',
      'Check config: /usr/local/lsws/bin/lswsctrl configtest',
      'Admin panel: https://server:7080',
      'Use LiteSpeed Cache plugin for WordPress',
    ],
  },
  pdns: {
    security: [
      { key: 'webserver-allow-from', value: '127.0.0.1', file: '/etc/powerdns/pdns.conf', description: 'Restrict API access', tooltip: 'Limits which IPs can access the REST API. Keep restricted to localhost or trusted IPs only.' },
      { key: 'api-key', value: '(strong random key)', file: '/etc/powerdns/pdns.conf', description: 'Secure API key', tooltip: 'Authentication key for the REST API. Use a long random string. Never commit to version control.' },
    ],
    recommended: [
      { key: 'allow-axfr-ips', value: 'slave-server-ip', file: '/etc/powerdns/pdns.conf', description: 'Restrict zone transfers', tooltip: 'Only allow zone transfers (AXFR) from your slave DNS servers. Prevents zone data leakage.' },
      { key: 'also-notify', value: 'slave-server-ip', file: '/etc/powerdns/pdns.conf', description: 'Notify slaves on changes', tooltip: 'Sends NOTIFY messages to slave servers when zones change. Enables fast replication.' },
      { key: 'master', value: 'yes', file: '/etc/powerdns/pdns.conf', description: 'Run as master server', tooltip: 'Enables this server to act as master. Required for sending NOTIFY and allowing AXFR.' },
      { key: 'log-dns-queries', value: 'no', file: '/etc/powerdns/pdns.conf', description: 'Disable query logging (production)', tooltip: 'Only enable for debugging. Query logging can fill disks quickly on busy servers.' },
    ],
    performance: [
      { key: 'cache-ttl', value: '60', file: '/etc/powerdns/pdns.conf', description: 'Positive cache TTL', tooltip: 'How long to cache successful lookups. Higher values reduce database load but delay updates.' },
      { key: 'negquery-cache-ttl', value: '60', file: '/etc/powerdns/pdns.conf', description: 'Negative cache TTL', tooltip: 'How long to cache NXDOMAIN responses. Prevents repeated lookups for non-existent domains.' },
      { key: 'query-cache-ttl', value: '20', file: '/etc/powerdns/pdns.conf', description: 'Query result cache', tooltip: 'In-memory cache for query results. Reduces backend queries for popular domains.' },
      { key: 'distributor-threads', value: '3', file: '/etc/powerdns/pdns.conf', description: 'Backend query threads', tooltip: 'Number of threads for backend queries. Increase for high-traffic servers with fast database.' },
    ],
    notes: [
      'Config file: /etc/powerdns/pdns.conf',
      'Test config: pdns_server --config-check',
      'Reload zones: pdns_control reload',
      'Test resolution: dig @localhost domain.com',
      'Check status: pdns_control ping',
    ],
  },
}

// Dynamic hostname for SSL paths - detect from current config if available
const certHostname = computed(() => {
  const settings = props.currentSettings || {}
  const host = props.hostname || 'your-domain.com'
  
  // Try to detect hostname from current SSL cert path
  if (props.service === 'dovecot' && settings.ssl_cert) {
    // Extract hostname from path like </etc/letsencrypt/live/domain.com/fullchain.pem
    const match = settings.ssl_cert.match(/\/live\/([^/]+)\//)
    if (match) return match[1]
  }
  if (props.service === 'postfix' && settings.smtpd_tls_cert_file) {
    const match = settings.smtpd_tls_cert_file.match(/\/live\/([^/]+)\//)
    if (match) return match[1]
  }
  
  // Fallback: use hostname prop (with or without mail. prefix based on convention)
  return host.startsWith('mail.') ? host : host
})

const guide = computed(() => {
  const baseGuide = guides[props.service]
  if (!baseGuide) return {}
  
  // For postfix and dovecot, replace placeholder paths with actual/detected hostname
  if (props.service === 'postfix' || props.service === 'dovecot') {
    const replaceHostname = (items) => {
      if (!items) return items
      return items.map(item => ({
        ...item,
        value: item.value?.replace(/mail\.domain\.com/g, certHostname.value) || item.value
      }))
    }
    
    return {
      ...baseGuide,
      security: replaceHostname(baseGuide.security),
      recommended: replaceHostname(baseGuide.recommended),
      performance: replaceHostname(baseGuide.performance),
    }
  }
  
  return baseGuide
})

const copiedKey = ref(null)
// Safe clipboard function with fallback for non-HTTPS contexts
const copyToClipboard = async (text, key = null) => {
  try {
    if (navigator.clipboard && window.isSecureContext) {
      await navigator.clipboard.writeText(text)
    } else {
      // Fallback for non-HTTPS or older browsers
      const textArea = document.createElement('textarea')
      textArea.value = text
      textArea.style.position = 'fixed'
      textArea.style.left = '-9999px'
      document.body.appendChild(textArea)
      textArea.select()
      document.execCommand('copy')
      document.body.removeChild(textArea)
    }
    copiedKey.value = key || text
    setTimeout(() => { copiedKey.value = null }, 1500)
  } catch (e) {
    console.error('Failed to copy', e)
  }
}

// Format config line for display and copy (key = value)
const formatConfigLine = (key, value) => {
  // Different services use different formats
  if (props.service === 'ssh') {
    return `${key} ${value}` // SSH uses space separator
  }
  // Postfix and Dovecot use = separator
  return `${key} = ${value}`
}

const getFileName = (filePath) => {
  if (!filePath) return ''
  const parts = filePath.split('/')
  return parts[parts.length - 1]
}

const startDrag = (e) => {
  isDragging.value = true
  dragOffset.value = {
    x: e.clientX - position.value.x,
    y: e.clientY - position.value.y,
  }
  document.addEventListener('mousemove', onDrag)
  document.addEventListener('mouseup', stopDrag)
}

const onDrag = (e) => {
  if (!isDragging.value) return
  position.value = {
    x: Math.max(0, Math.min(e.clientX - dragOffset.value.x, window.innerWidth - 480)),
    y: Math.max(0, Math.min(e.clientY - dragOffset.value.y, window.innerHeight - 100)),
  }
}

const stopDrag = () => {
  isDragging.value = false
  document.removeEventListener('mousemove', onDrag)
  document.removeEventListener('mouseup', stopDrag)
}

onUnmounted(() => {
  document.removeEventListener('mousemove', onDrag)
  document.removeEventListener('mouseup', stopDrag)
})
</script>

<style scoped>
/* Tooltip styles */
.guide-tooltip {
  position: absolute;
  left: 0;
  top: 100%;
  margin-top: 8px;
  padding: 10px 12px;
  background: #0f172a;
  border: 1px solid #334155;
  border-radius: 8px;
  font-size: 12px;
  line-height: 1.5;
  color: #cbd5e1;
  width: 280px;
  max-width: 90vw;
  z-index: 10001;
  opacity: 0;
  visibility: hidden;
  transform: translateY(-4px);
  transition: all 0.2s ease;
  box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.5);
  pointer-events: none;
}

.guide-tooltip::before {
  content: '';
  position: absolute;
  top: -6px;
  left: 20px;
  width: 10px;
  height: 10px;
  background: #0f172a;
  border-left: 1px solid #334155;
  border-top: 1px solid #334155;
  transform: rotate(45deg);
}

.group:hover .guide-tooltip {
  opacity: 1;
  visibility: visible;
  transform: translateY(0);
}

/* File badge - clickable link to open config file */
.file-badge {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 2px 8px;
  font-size: 10px;
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
  color: #a5b4fc;
  background: rgba(99, 102, 241, 0.15);
  border: 1px solid rgba(99, 102, 241, 0.3);
  border-radius: 4px;
  cursor: pointer;
  transition: all 0.15s ease;
  white-space: nowrap;
}

.file-badge:hover {
  background: rgba(99, 102, 241, 0.3);
  border-color: rgba(99, 102, 241, 0.5);
  color: #c7d2fe;
  transform: translateY(-1px);
}

.file-badge .material-symbols-rounded {
  font-size: 12px;
}
</style>
