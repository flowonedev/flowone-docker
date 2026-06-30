<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '@/services/api'
import { useToastStore } from '@/stores/toast'

const route = useRoute()
const router = useRouter()
const toast = useToastStore()

// Active tab
const activeTab = ref('ols')

const tabs = [
  { id: 'ols', label: 'OpenLiteSpeed', icon: 'bolt' },
  { id: 'php', label: 'PHP', icon: 'code' },
  { id: 'mysql', label: 'MySQL', icon: 'database' },
  { id: 'postfix', label: 'Postfix', icon: 'forward_to_inbox' },
  { id: 'dovecot', label: 'Dovecot', icon: 'inbox' },
  { id: 'logs', label: 'Logs', icon: 'article' },
]

// Set active tab from route query
onMounted(() => {
  if (route.query.tab && tabs.find(t => t.id === route.query.tab)) {
    activeTab.value = route.query.tab
  }
})

// Update URL when tab changes
watch(activeTab, (newTab) => {
  router.replace({ query: { tab: newTab } })
})

// ============================================
// OpenLiteSpeed State & Logic
// ============================================
const olsLoading = ref(true)
const olsSaving = ref(false)
const olsStatus = ref(null)
const olsSettings = ref({})
const olsOriginalSettings = ref({})
const olsEditMode = ref(false)

const olsSettingDefinitions = [
  // Server Settings
  { key: 'serverName', label: 'Server Name', description: 'Server hostname identifier', placeholder: 'server1', section: 'server' },
  { key: 'adminEmails', label: 'Admin Email', description: 'Administrator email address', placeholder: 'admin@example.com', section: 'server' },
  
  // Tuning Settings
  { key: 'tuning_maxConnections', label: 'Max Connections', description: 'Maximum concurrent connections', placeholder: '2000', section: 'tuning' },
  { key: 'tuning_maxSSLConnections', label: 'Max SSL Connections', description: 'Maximum concurrent SSL connections', placeholder: '1000', section: 'tuning' },
  { key: 'tuning_connTimeout', label: 'Connection Timeout', description: 'Connection timeout in seconds', placeholder: '300', section: 'tuning' },
  { key: 'tuning_maxKeepAliveReq', label: 'Max Keep-Alive Requests', description: 'Maximum requests per keep-alive connection', placeholder: '1000', section: 'tuning' },
  { key: 'tuning_keepAliveTimeout', label: 'Keep-Alive Timeout', description: 'Keep-alive connection timeout in seconds', placeholder: '5', section: 'tuning' },
  { key: 'tuning_maxReqURLLen', label: 'Max Request URL Length', description: 'Maximum URL length in bytes', placeholder: '8192', section: 'tuning' },
  { key: 'tuning_maxReqHeaderSize', label: 'Max Request Header Size', description: 'Maximum request header size in bytes', placeholder: '16380', section: 'tuning' },
  { key: 'tuning_maxReqBodySize', label: 'Max Request Body Size', description: 'Maximum request body size', placeholder: '2047M', section: 'tuning' },
  { key: 'tuning_maxDynRespHeaderSize', label: 'Max Dynamic Response Header', description: 'Maximum dynamic response header size', placeholder: '8192', section: 'tuning' },
  { key: 'tuning_maxDynRespSize', label: 'Max Dynamic Response Size', description: 'Maximum dynamic response size', placeholder: '2047M', section: 'tuning' },
  
  // Compression Settings
  { key: 'tuning_enableGzipCompress', label: 'Enable GZIP', description: 'Enable GZIP compression for responses', placeholder: '1', type: 'toggle', section: 'compression' },
  { key: 'tuning_gzipCompressLevel', label: 'GZIP Level', description: 'GZIP compression level (1-9, higher = more compression)', placeholder: '6', section: 'compression' },
  { key: 'tuning_gzipStaticCompressLevel', label: 'GZIP Static Level', description: 'Compression level for static files', placeholder: '6', section: 'compression' },
  { key: 'tuning_gzipAutoUpdateStatic', label: 'Auto Update Static', description: 'Auto-update compressed static files', placeholder: '1', type: 'toggle', section: 'compression' },
  { key: 'tuning_enableBrCompress', label: 'Enable Brotli', description: 'Enable Brotli compression (modern browsers)', placeholder: '1', type: 'toggle', section: 'compression' },
  { key: 'tuning_brStaticCompressLevel', label: 'Brotli Level', description: 'Brotli compression level (1-6)', placeholder: '6', section: 'compression' },
  { key: 'tuning_compressibleTypes', label: 'Compressible Types', description: 'MIME types to compress', placeholder: 'text/*, application/javascript, application/json', section: 'compression' },
  
  // Cache Settings
  { key: 'tuning_maxCachedFileSize', label: 'Max Cached File Size', description: 'Maximum size of cached files in bytes', placeholder: '4096', section: 'cache' },
  { key: 'tuning_totalInMemCacheSize', label: 'Total In-Memory Cache', description: 'Total memory for file caching', placeholder: '20M', section: 'cache' },
  { key: 'tuning_useSendfile', label: 'Use Sendfile', description: 'Use sendfile() for static files (faster)', placeholder: '1', type: 'toggle', section: 'cache' },
  { key: 'tuning_fileETag', label: 'File ETag', description: 'Include ETag headers for caching', placeholder: '28', section: 'cache' },
  
  // Security Settings
  { key: 'security_followSymbolLink', label: 'Follow Symlinks', description: 'Allow following symbolic links (1=Yes, 0=No)', placeholder: '1', section: 'security' },
  { key: 'security_checkSymbolLink', label: 'Check Symlinks', description: 'Check symbolic link ownership (1=Yes, 0=No)', placeholder: '0', section: 'security' },
  { key: 'security_maxCGIInstances', label: 'Max CGI Instances', description: 'Maximum concurrent CGI processes', placeholder: '500', section: 'security' },
]

// Group settings by section
const olsSections = [
  { id: 'server', label: 'Server Settings', icon: 'dns' },
  { id: 'tuning', label: 'Performance Tuning', icon: 'speed' },
  { id: 'compression', label: 'Compression', icon: 'compress' },
  { id: 'cache', label: 'Caching', icon: 'cached' },
  { id: 'security', label: 'Security', icon: 'shield' },
]

const getSettingsBySection = (sectionId) => {
  return olsSettingDefinitions.filter(s => s.section === sectionId)
}

const olsHasChanges = computed(() => {
  return JSON.stringify(olsSettings.value) !== JSON.stringify(olsOriginalSettings.value)
})

const fetchOlsStatus = async () => {
  try {
    const response = await api.get('/ols/status')
    if (response.data.success) {
      olsStatus.value = response.data.data
    }
  } catch (e) {
    console.error('Failed to fetch OLS status', e)
  }
}

const fetchOlsSettings = async () => {
  olsLoading.value = true
  try {
    const response = await api.get('/ols/settings')
    if (response.data.success) {
      olsSettings.value = response.data.data.settings || {}
      olsOriginalSettings.value = { ...olsSettings.value }
    }
  } catch (e) {
    toast.error('Failed to load OpenLiteSpeed settings')
  } finally {
    olsLoading.value = false
  }
}

const saveOlsSettings = async () => {
  olsSaving.value = true
  try {
    const response = await api.put('/ols/settings', {
      settings: olsSettings.value
    })
    if (response.data.success) {
      toast.success('OpenLiteSpeed settings saved. Restart to apply changes.')
      olsOriginalSettings.value = { ...olsSettings.value }
      olsEditMode.value = false
    } else {
      toast.error(response.data.error || 'Failed to save settings')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to save settings')
  } finally {
    olsSaving.value = false
  }
}

const cancelOlsEdit = () => {
  olsSettings.value = { ...olsOriginalSettings.value }
  olsEditMode.value = false
}

const restartOls = async () => {
  olsSaving.value = true
  try {
    const response = await api.post('/ols/restart')
    if (response.data.success) {
      toast.success('OpenLiteSpeed restarted successfully')
      await fetchOlsStatus()
    } else {
      toast.error(response.data.error || 'Failed to restart OpenLiteSpeed')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to restart OpenLiteSpeed')
  } finally {
    olsSaving.value = false
  }
}

const reloadOls = async () => {
  olsSaving.value = true
  try {
    const response = await api.post('/ols/reload')
    if (response.data.success) {
      toast.success('OpenLiteSpeed reloaded successfully')
    } else {
      toast.error(response.data.error || 'Failed to reload OpenLiteSpeed')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to reload OpenLiteSpeed')
  } finally {
    olsSaving.value = false
  }
}

// ============================================
// PHP State & Logic
// ============================================
const phpLoading = ref(true)
const phpSaving = ref(false)
const phpVersions = ref([])
const selectedVersion = ref(null)
const phpSettings = ref({})
const phpOriginalSettings = ref({})
const phpEditMode = ref(false)

const phpSettingDefinitions = [
  // Core Settings
  { key: 'memory_limit', label: 'Memory Limit', description: 'Maximum memory a script can consume', placeholder: '256M', section: 'core' },
  { key: 'max_execution_time', label: 'Max Execution Time', description: 'Maximum time a script can run (seconds)', placeholder: '30', section: 'core' },
  { key: 'max_input_time', label: 'Max Input Time', description: 'Maximum time to parse input data (seconds)', placeholder: '60', section: 'core' },
  { key: 'upload_max_filesize', label: 'Upload Max Filesize', description: 'Maximum size of uploaded files', placeholder: '64M', section: 'core' },
  { key: 'post_max_size', label: 'Post Max Size', description: 'Maximum size of POST data', placeholder: '64M', section: 'core' },
  { key: 'max_input_vars', label: 'Max Input Vars', description: 'Maximum number of input variables', placeholder: '1000', section: 'core' },
  { key: 'max_file_uploads', label: 'Max File Uploads', description: 'Maximum number of files uploaded at once', placeholder: '20', section: 'core' },
  { key: 'display_errors', label: 'Display Errors', description: 'Show errors on screen (dev only)', placeholder: 'Off', type: 'toggle', section: 'core' },
  { key: 'error_reporting', label: 'Error Reporting', description: 'Which errors to report', placeholder: 'E_ALL & ~E_DEPRECATED', section: 'core' },
  { key: 'date.timezone', label: 'Timezone', description: 'Default timezone', placeholder: 'UTC', section: 'core' },
  
  // OPCache Settings
  { key: 'opcache.enable', label: 'Enable OPCache', description: 'Enable the opcode cache', placeholder: '1', type: 'toggle', section: 'opcache' },
  { key: 'opcache.enable_cli', label: 'Enable for CLI', description: 'Enable OPCache for CLI scripts', placeholder: '0', type: 'toggle', section: 'opcache' },
  { key: 'opcache.memory_consumption', label: 'Memory (MB)', description: 'Memory allocated to OPCache', placeholder: '128', section: 'opcache' },
  { key: 'opcache.interned_strings_buffer', label: 'Strings Buffer (MB)', description: 'Memory for interned strings', placeholder: '8', section: 'opcache' },
  { key: 'opcache.max_accelerated_files', label: 'Max Cached Files', description: 'Maximum number of cached scripts', placeholder: '10000', section: 'opcache' },
  { key: 'opcache.revalidate_freq', label: 'Revalidate Frequency', description: 'How often to check for file changes (sec)', placeholder: '2', section: 'opcache' },
  { key: 'opcache.validate_timestamps', label: 'Validate Timestamps', description: 'Check if files changed (disable in prod)', placeholder: '1', type: 'toggle', section: 'opcache' },
  { key: 'opcache.save_comments', label: 'Save Comments', description: 'Save docblock comments in bytecode', placeholder: '1', type: 'toggle', section: 'opcache' },
  
  // Session Settings
  { key: 'session.gc_maxlifetime', label: 'Session Lifetime', description: 'Session garbage collection max lifetime (sec)', placeholder: '1440', section: 'session' },
  { key: 'session.cookie_lifetime', label: 'Cookie Lifetime', description: 'Session cookie lifetime (0 = until browser close)', placeholder: '0', section: 'session' },
  { key: 'session.save_handler', label: 'Save Handler', description: 'Session storage handler', placeholder: 'files', section: 'session' },
  
  // Security Settings
  { key: 'expose_php', label: 'Expose PHP', description: 'Show PHP version in headers', placeholder: 'Off', type: 'toggle', section: 'security' },
  { key: 'allow_url_fopen', label: 'URL fopen', description: 'Allow opening URLs as files', placeholder: 'On', type: 'toggle', section: 'security' },
]

// PHP settings sections
const phpSections = [
  { id: 'core', label: 'Core Settings', icon: 'settings' },
  { id: 'opcache', label: 'OPCache', icon: 'speed' },
  { id: 'session', label: 'Sessions', icon: 'key' },
  { id: 'security', label: 'Security', icon: 'security' },
]

const getPhpSettingsBySection = (sectionId) => {
  return phpSettingDefinitions.filter(s => s.section === sectionId)
}

const phpHasChanges = computed(() => {
  return JSON.stringify(phpSettings.value) !== JSON.stringify(phpOriginalSettings.value)
})

const fetchPhpVersions = async () => {
  phpLoading.value = true
  try {
    const response = await api.get('/php/versions')
    if (response.data.success) {
      phpVersions.value = response.data.data.versions || []
      if (phpVersions.value.length > 0 && !selectedVersion.value) {
        selectedVersion.value = phpVersions.value[0].version
      }
    }
  } catch (e) {
    toast.error('Failed to load PHP versions')
  } finally {
    phpLoading.value = false
  }
}

const fetchPhpSettings = async () => {
  if (!selectedVersion.value) return
  
  phpLoading.value = true
  try {
    const response = await api.get(`/php/${selectedVersion.value}/settings`)
    if (response.data.success) {
      phpSettings.value = response.data.data.settings || {}
      phpOriginalSettings.value = { ...phpSettings.value }
    }
  } catch (e) {
    toast.error('Failed to load PHP settings')
  } finally {
    phpLoading.value = false
  }
}

const savePhpSettings = async () => {
  phpSaving.value = true
  try {
    const response = await api.put(`/php/${selectedVersion.value}/settings`, {
      settings: phpSettings.value
    })
    if (response.data.success) {
      toast.success('PHP settings saved successfully')
      phpOriginalSettings.value = { ...phpSettings.value }
      phpEditMode.value = false
    } else {
      toast.error(response.data.error || 'Failed to save settings')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to save settings')
  } finally {
    phpSaving.value = false
  }
}

const cancelPhpEdit = () => {
  phpSettings.value = { ...phpOriginalSettings.value }
  phpEditMode.value = false
}

const restartPhp = async () => {
  phpSaving.value = true
  try {
    const response = await api.post(`/php/${selectedVersion.value}/restart`)
    if (response.data.success) {
      toast.success(`PHP ${selectedVersion.value} restarted successfully`)
    } else {
      toast.error(response.data.error || 'Failed to restart PHP')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to restart PHP')
  } finally {
    phpSaving.value = false
  }
}

watch(selectedVersion, () => {
  if (selectedVersion.value) {
    fetchPhpSettings()
  }
})

// ============================================
// MySQL State & Logic
// ============================================
const mysqlLoading = ref(true)
const mysqlSaving = ref(false)
const mysqlStatus = ref(null)
const mysqlSettings = ref({})
const mysqlOriginalSettings = ref({})
const mysqlEditMode = ref(false)
const mysqlVariables = ref([])
const mysqlSearchQuery = ref('')

const mysqlSettingDefinitions = [
  // Connection Settings
  { key: 'max_connections', label: 'Max Connections', description: 'Maximum simultaneous client connections', placeholder: '151', section: 'connection' },
  { key: 'max_allowed_packet', label: 'Max Allowed Packet', description: 'Maximum size of one packet', placeholder: '64M', section: 'connection' },
  { key: 'wait_timeout', label: 'Wait Timeout', description: 'Seconds to wait for activity on a connection', placeholder: '28800', section: 'connection' },
  { key: 'interactive_timeout', label: 'Interactive Timeout', description: 'Timeout for interactive connections', placeholder: '28800', section: 'connection' },
  { key: 'connect_timeout', label: 'Connect Timeout', description: 'Connection timeout in seconds', placeholder: '10', section: 'connection' },
  
  // InnoDB Settings
  { key: 'innodb_buffer_pool_size', label: 'Buffer Pool Size', description: 'Memory for caching data and indexes (70-80% of RAM for dedicated)', placeholder: '128M', section: 'innodb' },
  { key: 'innodb_log_file_size', label: 'Log File Size', description: 'Size of each InnoDB redo log file', placeholder: '48M', section: 'innodb' },
  { key: 'innodb_flush_log_at_trx_commit', label: 'Flush Log at Commit', description: '1=ACID, 2=once per sec, 0=let OS handle', placeholder: '1', section: 'innodb' },
  { key: 'innodb_file_per_table', label: 'File Per Table', description: 'Store each table in its own file', placeholder: 'ON', type: 'toggle', section: 'innodb' },
  { key: 'innodb_io_capacity', label: 'I/O Capacity', description: 'I/O operations per second for background tasks', placeholder: '200', section: 'innodb' },
  
  // Performance Settings
  { key: 'tmp_table_size', label: 'Tmp Table Size', description: 'Max size of internal in-memory temp tables', placeholder: '16M', section: 'performance' },
  { key: 'max_heap_table_size', label: 'Max Heap Table Size', description: 'Maximum size for MEMORY tables', placeholder: '16M', section: 'performance' },
  { key: 'table_open_cache', label: 'Table Open Cache', description: 'Number of open tables cached', placeholder: '2000', section: 'performance' },
  { key: 'thread_cache_size', label: 'Thread Cache Size', description: 'Number of threads to cache for reuse', placeholder: '8', section: 'performance' },
  { key: 'sort_buffer_size', label: 'Sort Buffer Size', description: 'Buffer size for sorting operations', placeholder: '256K', section: 'performance' },
  { key: 'join_buffer_size', label: 'Join Buffer Size', description: 'Buffer size for joins without indexes', placeholder: '256K', section: 'performance' },
  
  // Logging Settings
  { key: 'slow_query_log', label: 'Slow Query Log', description: 'Enable logging of slow queries', placeholder: 'OFF', type: 'toggle', section: 'logging' },
  { key: 'long_query_time', label: 'Long Query Time', description: 'Queries longer than this (sec) are logged', placeholder: '10', section: 'logging' },
  { key: 'log_bin', label: 'Binary Logging', description: 'Enable binary logging for replication', placeholder: 'mysql-bin', section: 'logging' },
  { key: 'expire_logs_days', label: 'Expire Logs Days', description: 'Days to keep binary logs', placeholder: '10', section: 'logging' },
  { key: 'sync_binlog', label: 'Sync Binlog', description: 'Sync binary log to disk on each commit', placeholder: '1', section: 'logging' },
  
  // Character Set
  { key: 'character_set_server', label: 'Server Charset', description: 'Default character set', placeholder: 'utf8mb4', section: 'charset' },
  { key: 'collation_server', label: 'Server Collation', description: 'Default collation', placeholder: 'utf8mb4_unicode_ci', section: 'charset' },
]

// MySQL settings sections
const mysqlSections = [
  { id: 'connection', label: 'Connection Settings', icon: 'cable' },
  { id: 'innodb', label: 'InnoDB Storage', icon: 'storage' },
  { id: 'performance', label: 'Performance', icon: 'speed' },
  { id: 'logging', label: 'Logging', icon: 'description' },
  { id: 'charset', label: 'Character Set', icon: 'translate' },
]

const getMysqlSettingsBySection = (sectionId) => {
  return mysqlSettingDefinitions.filter(s => s.section === sectionId)
}

const mysqlHasChanges = computed(() => {
  return JSON.stringify(mysqlSettings.value) !== JSON.stringify(mysqlOriginalSettings.value)
})

const filteredMysqlVariables = computed(() => {
  if (!mysqlSearchQuery.value) return mysqlVariables.value.slice(0, 50)
  const q = mysqlSearchQuery.value.toLowerCase()
  return mysqlVariables.value.filter(v => 
    v.name.toLowerCase().includes(q) || 
    v.value.toString().toLowerCase().includes(q)
  ).slice(0, 50)
})

const fetchMysqlStatus = async () => {
  try {
    const response = await api.get('/mysql/status')
    if (response.data.success) {
      mysqlStatus.value = response.data.data
    }
  } catch (e) {
    console.error('Failed to fetch MySQL status', e)
  }
}

const fetchMysqlSettings = async () => {
  mysqlLoading.value = true
  try {
    const response = await api.get('/mysql/settings')
    if (response.data.success) {
      mysqlSettings.value = response.data.data.settings || {}
      mysqlOriginalSettings.value = { ...mysqlSettings.value }
      mysqlVariables.value = response.data.data.variables || []
    }
  } catch (e) {
    toast.error('Failed to load MySQL settings')
  } finally {
    mysqlLoading.value = false
  }
}

const saveMysqlSettings = async () => {
  mysqlSaving.value = true
  try {
    const response = await api.put('/mysql/settings', {
      settings: mysqlSettings.value
    })
    if (response.data.success) {
      toast.success('MySQL settings saved successfully')
      mysqlOriginalSettings.value = { ...mysqlSettings.value }
      mysqlEditMode.value = false
    } else {
      toast.error(response.data.error || 'Failed to save settings')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to save settings')
  } finally {
    mysqlSaving.value = false
  }
}

const cancelMysqlEdit = () => {
  mysqlSettings.value = { ...mysqlOriginalSettings.value }
  mysqlEditMode.value = false
}

const restartMysql = async () => {
  mysqlSaving.value = true
  try {
    const response = await api.post('/mysql/restart')
    if (response.data.success) {
      toast.success('MySQL restarted successfully')
      await fetchMysqlStatus()
    } else {
      toast.error(response.data.error || 'Failed to restart MySQL')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to restart MySQL')
  } finally {
    mysqlSaving.value = false
  }
}

// ============================================
// Postfix State & Logic
// ============================================
const postfixLoading = ref(true)
const postfixSaving = ref(false)
const postfixStatus = ref(null)
const postfixSettings = ref({})
const postfixOriginalSettings = ref({})
const postfixEditMode = ref(false)
const postfixQueue = ref([])

const postfixSettingDefinitions = [
  { key: 'myhostname', label: 'Hostname', description: 'Mail server hostname (FQDN)', placeholder: 'mail.example.com' },
  { key: 'mydomain', label: 'Domain', description: 'Mail domain', placeholder: 'example.com' },
  { key: 'message_size_limit', label: 'Message Size Limit', description: 'Maximum email size in bytes (0 = unlimited)', placeholder: '52428800' },
  { key: 'mailbox_size_limit', label: 'Mailbox Size Limit', description: 'Maximum mailbox size in bytes (0 = unlimited)', placeholder: '0' },
  { key: 'smtpd_recipient_limit', label: 'Recipient Limit', description: 'Maximum recipients per message', placeholder: '100' },
  { key: 'maximal_queue_lifetime', label: 'Max Queue Lifetime', description: 'How long to keep undeliverable mail', placeholder: '5d' },
  { key: 'bounce_queue_lifetime', label: 'Bounce Queue Lifetime', description: 'How long to keep bounce messages', placeholder: '5d' },
  { key: 'smtp_tls_security_level', label: 'TLS Security Level', description: 'Outbound TLS security level', placeholder: 'may', type: 'select', options: ['none', 'may', 'encrypt', 'dane', 'verify', 'secure'] },
  { key: 'smtpd_tls_security_level', label: 'SMTPD TLS Level', description: 'Inbound TLS security level', placeholder: 'may', type: 'select', options: ['none', 'may', 'encrypt'] },
  { key: 'smtpd_use_tls', label: 'Use TLS', description: 'Enable TLS for incoming connections', placeholder: 'yes', type: 'toggle' },
]

const postfixHasChanges = computed(() => {
  return JSON.stringify(postfixSettings.value) !== JSON.stringify(postfixOriginalSettings.value)
})

const formatBytes = (bytes) => {
  if (bytes === 0) return 'Unlimited'
  const k = 1024
  const sizes = ['B', 'KB', 'MB', 'GB']
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i]
}

const fetchPostfixStatus = async () => {
  try {
    const response = await api.get('/postfix/status')
    if (response.data.success) {
      postfixStatus.value = response.data.data
    }
  } catch (e) {
    console.error('Failed to fetch Postfix status', e)
  }
}

const fetchPostfixSettings = async () => {
  postfixLoading.value = true
  try {
    const response = await api.get('/postfix/settings')
    if (response.data.success) {
      postfixSettings.value = response.data.data.settings || {}
      postfixOriginalSettings.value = { ...postfixSettings.value }
      postfixQueue.value = response.data.data.queue || []
    }
  } catch (e) {
    toast.error('Failed to load Postfix settings')
  } finally {
    postfixLoading.value = false
  }
}

const savePostfixSettings = async () => {
  postfixSaving.value = true
  try {
    const response = await api.put('/postfix/settings', {
      settings: postfixSettings.value
    })
    if (response.data.success) {
      toast.success('Postfix settings saved successfully')
      postfixOriginalSettings.value = { ...postfixSettings.value }
      postfixEditMode.value = false
    } else {
      toast.error(response.data.error || 'Failed to save settings')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to save settings')
  } finally {
    postfixSaving.value = false
  }
}

const cancelPostfixEdit = () => {
  postfixSettings.value = { ...postfixOriginalSettings.value }
  postfixEditMode.value = false
}

const restartPostfix = async () => {
  postfixSaving.value = true
  try {
    const response = await api.post('/postfix/restart')
    if (response.data.success) {
      toast.success('Postfix restarted successfully')
      await fetchPostfixStatus()
    } else {
      toast.error(response.data.error || 'Failed to restart Postfix')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to restart Postfix')
  } finally {
    postfixSaving.value = false
  }
}

const flushQueue = async () => {
  postfixSaving.value = true
  try {
    const response = await api.post('/postfix/flush')
    if (response.data.success) {
      toast.success('Mail queue flushed')
      await fetchPostfixSettings()
    } else {
      toast.error(response.data.error || 'Failed to flush queue')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to flush queue')
  } finally {
    postfixSaving.value = false
  }
}

// ============================================
// Dovecot State & Logic
// ============================================
const dovecotLoading = ref(true)
const dovecotSaving = ref(false)
const dovecotStatus = ref(null)
const dovecotSettings = ref({})
const dovecotOriginalSettings = ref({})
const dovecotEditMode = ref(false)
const dovecotConnections = ref([])

const dovecotSettingDefinitions = [
  { key: 'mail_location', label: 'Mail Location', description: 'Path to mailbox storage', placeholder: 'maildir:~/Maildir' },
  { key: 'mail_max_userip_connections', label: 'Max Connections per IP', description: 'Maximum connections per user from single IP', placeholder: '10' },
  { key: 'default_process_limit', label: 'Default Process Limit', description: 'Maximum number of service processes', placeholder: '100' },
  { key: 'default_client_limit', label: 'Default Client Limit', description: 'Maximum number of client connections per process', placeholder: '1000' },
  { key: 'auth_mechanisms', label: 'Auth Mechanisms', description: 'Allowed authentication mechanisms', placeholder: 'plain login' },
  { key: 'ssl', label: 'SSL Mode', description: 'SSL/TLS mode for connections', placeholder: 'required', type: 'select', options: ['no', 'yes', 'required'] },
  { key: 'ssl_min_protocol', label: 'SSL Min Protocol', description: 'Minimum TLS version', placeholder: 'TLSv1.2', type: 'select', options: ['TLSv1', 'TLSv1.1', 'TLSv1.2', 'TLSv1.3'] },
  { key: 'verbose_ssl', label: 'Verbose SSL', description: 'Log SSL handshakes and errors', placeholder: 'no', type: 'toggle' },
  { key: 'auth_verbose', label: 'Verbose Auth', description: 'Log authentication attempts', placeholder: 'no', type: 'toggle' },
  { key: 'mail_debug', label: 'Mail Debug', description: 'Enable mail debugging', placeholder: 'no', type: 'toggle' },
]

const dovecotHasChanges = computed(() => {
  return JSON.stringify(dovecotSettings.value) !== JSON.stringify(dovecotOriginalSettings.value)
})

const fetchDovecotStatus = async () => {
  try {
    const response = await api.get('/dovecot/status')
    if (response.data.success) {
      dovecotStatus.value = response.data.data
    }
  } catch (e) {
    console.error('Failed to fetch Dovecot status', e)
  }
}

const fetchDovecotSettings = async () => {
  dovecotLoading.value = true
  try {
    const response = await api.get('/dovecot/settings')
    if (response.data.success) {
      dovecotSettings.value = response.data.data.settings || {}
      dovecotOriginalSettings.value = { ...dovecotSettings.value }
      dovecotConnections.value = response.data.data.connections || []
    }
  } catch (e) {
    toast.error('Failed to load Dovecot settings')
  } finally {
    dovecotLoading.value = false
  }
}

const saveDovecotSettings = async () => {
  dovecotSaving.value = true
  try {
    const response = await api.put('/dovecot/settings', {
      settings: dovecotSettings.value
    })
    if (response.data.success) {
      toast.success('Dovecot settings saved successfully')
      dovecotOriginalSettings.value = { ...dovecotSettings.value }
      dovecotEditMode.value = false
    } else {
      toast.error(response.data.error || 'Failed to save settings')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to save settings')
  } finally {
    dovecotSaving.value = false
  }
}

const cancelDovecotEdit = () => {
  dovecotSettings.value = { ...dovecotOriginalSettings.value }
  dovecotEditMode.value = false
}

const restartDovecot = async () => {
  dovecotSaving.value = true
  try {
    const response = await api.post('/dovecot/restart')
    if (response.data.success) {
      toast.success('Dovecot restarted successfully')
      await fetchDovecotStatus()
    } else {
      toast.error(response.data.error || 'Failed to restart Dovecot')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to restart Dovecot')
  } finally {
    dovecotSaving.value = false
  }
}

// ============================================
// Logs State & Logic
// ============================================
const logsLoading = ref(false)
const logsService = ref('openlitespeed')
const logsType = ref('journalctl')
const logsFilter = ref('')
const logsSearch = ref('')
const logsLines = ref([])
const logsTotal = ref(0)
const logsAvailableTypes = ref([])
const logsAvailableFilters = ref({})
const logsPhpVersion = ref('')

const logsServices = [
  { id: 'openlitespeed', label: 'OpenLiteSpeed', icon: 'bolt' },
  { id: 'php', label: 'PHP', icon: 'code' },
  { id: 'mysql', label: 'MySQL', icon: 'database' },
  { id: 'postfix', label: 'Postfix (Mail)', icon: 'forward_to_inbox' },
  { id: 'dovecot', label: 'Dovecot (IMAP)', icon: 'inbox' },
]

const fetchLogTypes = async () => {
  try {
    const response = await api.get(`/system-logs/${logsService.value}/types`)
    if (response.data.success) {
      logsAvailableTypes.value = response.data.data.types || []
      logsAvailableFilters.value = response.data.data.filters || {}
      
      // Set default type if available - prefer journalctl first
      if (logsAvailableTypes.value.length > 0) {
        const journalType = logsAvailableTypes.value.find(t => t.id === 'journalctl' && t.exists)
        if (journalType) {
          logsType.value = 'journalctl'
        } else {
          const existingType = logsAvailableTypes.value.find(t => t.exists)
          if (existingType) {
            logsType.value = existingType.id
          } else {
            logsType.value = logsAvailableTypes.value[0].id
          }
        }
      }
    }
  } catch (e) {
    console.error('Failed to load log types', e)
  }
}

const fetchLogs = async () => {
  logsLoading.value = true
  try {
    const params = {
      type: logsType.value,
      lines: 200,
    }
    
    if (logsFilter.value) {
      params.filter = logsFilter.value
    }
    if (logsSearch.value) {
      params.search = logsSearch.value
    }
    if (logsService.value === 'php' && logsPhpVersion.value) {
      params.version = logsPhpVersion.value
    }
    
    const response = await api.get(`/system-logs/${logsService.value}`, { params })
    if (response.data.success) {
      logsLines.value = response.data.data.lines || []
      logsTotal.value = response.data.data.total || 0
    }
  } catch (e) {
    toast.error('Failed to load logs')
  } finally {
    logsLoading.value = false
  }
}

const loadLogsTab = async () => {
  logsLoading.value = true
  
  // Set PHP version if available
  if (phpVersions.value.length > 0 && !logsPhpVersion.value) {
    logsPhpVersion.value = phpVersions.value[0].version
  }
  
  await fetchLogTypes()
  await fetchLogs()
}

const changeLogsService = async (service) => {
  logsService.value = service
  logsFilter.value = ''
  logsSearch.value = ''
  await fetchLogTypes()
  await fetchLogs()
}

const applyLogsFilter = (filter) => {
  logsFilter.value = logsFilter.value === filter ? '' : filter
  fetchLogs()
}

// Parse a log line into structured components
const parseLogLine = (line) => {
  const parsed = {
    raw: line,
    timestamp: null,
    level: 'info',
    service: null,
    message: line,
    ip: null,
    method: null,
    status: null,
    url: null,
  }

  // Try to extract timestamp (various formats)
  // Format: Dec 25 15:30:45 or 2024-12-25 15:30:45 or [25/Dec/2024:15:30:45]
  const timestampPatterns = [
    /^(\w{3}\s+\d{1,2}\s+\d{2}:\d{2}:\d{2})\s+/,
    /^(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})\s*/,
    /^\[(\d{2}\/\w{3}\/\d{4}:\d{2}:\d{2}:\d{2}[^\]]*)\]\s*/,
    /^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})/,
  ]
  
  for (const pattern of timestampPatterns) {
    const match = line.match(pattern)
    if (match) {
      parsed.timestamp = match[1]
      parsed.message = line.substring(match[0].length)
      break
    }
  }

  // Detect log level
  const lowerLine = line.toLowerCase()
  if (lowerLine.includes('error') || lowerLine.includes('fatal') || lowerLine.includes('crit') || lowerLine.includes('panic') || lowerLine.includes('emerg')) {
    parsed.level = 'error'
  } else if (lowerLine.includes('warn')) {
    parsed.level = 'warning'
  } else if (lowerLine.includes('notice') || lowerLine.includes('info')) {
    parsed.level = 'info'
  } else if (lowerLine.includes('debug')) {
    parsed.level = 'debug'
  } else if (lowerLine.includes('success') || lowerLine.includes('delivered') || lowerLine.includes('status=sent')) {
    parsed.level = 'success'
  } else if (lowerLine.includes('failed') || lowerLine.includes('denied') || lowerLine.includes('rejected') || lowerLine.includes('blocked')) {
    parsed.level = 'error'
  }

  // Extract IP address
  const ipMatch = line.match(/(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})/)
  if (ipMatch) {
    parsed.ip = ipMatch[1]
  }

  // Detect HTTP method and status for access logs
  const httpMatch = line.match(/"(GET|POST|PUT|DELETE|HEAD|OPTIONS|PATCH)\s+([^"]+)"\s+(\d{3})/)
  if (httpMatch) {
    parsed.method = httpMatch[1]
    parsed.url = httpMatch[2]
    parsed.status = parseInt(httpMatch[3])
    
    // Set level based on HTTP status
    if (parsed.status >= 500) parsed.level = 'error'
    else if (parsed.status >= 400) parsed.level = 'warning'
    else if (parsed.status >= 300) parsed.level = 'info'
    else parsed.level = 'success'
  }

  // Extract service name from syslog format
  const serviceMatch = parsed.message.match(/^(\S+)\s+(\w+)(?:\[\d+\])?:\s*(.*)/)
  if (serviceMatch) {
    parsed.service = serviceMatch[2]
    parsed.message = serviceMatch[3] || parsed.message
  }

  return parsed
}

// Get CSS classes for log level
const getLogLevelClasses = (level) => {
  switch (level) {
    case 'error':
      return 'border-l-red-500 bg-red-500/5'
    case 'warning':
      return 'border-l-amber-500 bg-amber-500/5'
    case 'success':
      return 'border-l-green-500 bg-green-500/5'
    case 'debug':
      return 'border-l-purple-500 bg-purple-500/5'
    case 'info':
      return 'border-l-blue-500 bg-blue-500/5'
    default:
      return 'border-l-surface-500 bg-transparent'
  }
}

// Get text color for log level
const getLogLevelColor = (level) => {
  switch (level) {
    case 'error': return 'text-red-400'
    case 'warning': return 'text-amber-400'
    case 'success': return 'text-green-400'
    case 'debug': return 'text-purple-400'
    case 'info': return 'text-blue-400'
    default: return 'text-surface-400'
  }
}

// Get badge for log level
const getLogLevelBadge = (level) => {
  const badges = {
    error: { text: 'ERR', class: 'bg-red-500/20 text-red-400' },
    warning: { text: 'WRN', class: 'bg-amber-500/20 text-amber-400' },
    success: { text: 'OK', class: 'bg-green-500/20 text-green-400' },
    debug: { text: 'DBG', class: 'bg-purple-500/20 text-purple-400' },
    info: { text: 'INF', class: 'bg-blue-500/20 text-blue-400' },
  }
  return badges[level] || { text: 'LOG', class: 'bg-surface-500/20 text-surface-400' }
}

// Get HTTP status badge
const getHttpStatusBadge = (status) => {
  if (!status) return null
  if (status >= 500) return { text: status, class: 'bg-red-500/20 text-red-400' }
  if (status >= 400) return { text: status, class: 'bg-amber-500/20 text-amber-400' }
  if (status >= 300) return { text: status, class: 'bg-blue-500/20 text-blue-400' }
  return { text: status, class: 'bg-green-500/20 text-green-400' }
}

// Legacy function for backwards compatibility
const getLogLineClass = (line) => {
  const parsed = parseLogLine(line)
  return getLogLevelColor(parsed.level)
}

// ============================================
// Load data based on active tab
// ============================================
const loadTabData = (tab) => {
  switch (tab) {
    case 'ols':
      if (!olsStatus.value) fetchOlsStatus()
      if (Object.keys(olsSettings.value).length === 0) fetchOlsSettings()
      break
    case 'php':
      if (phpVersions.value.length === 0) fetchPhpVersions()
      break
    case 'mysql':
      if (!mysqlStatus.value) fetchMysqlStatus()
      if (mysqlVariables.value.length === 0) fetchMysqlSettings()
      break
    case 'postfix':
      if (!postfixStatus.value) fetchPostfixStatus()
      if (Object.keys(postfixSettings.value).length === 0) fetchPostfixSettings()
      break
    case 'dovecot':
      if (!dovecotStatus.value) fetchDovecotStatus()
      if (Object.keys(dovecotSettings.value).length === 0) fetchDovecotSettings()
      break
    case 'logs':
      loadLogsTab()
      break
  }
}

watch(activeTab, (newTab) => {
  loadTabData(newTab)
}, { immediate: true })

onMounted(() => {
  loadTabData(activeTab.value)
})
</script>

<template>
  <div>
    <!-- Header -->
    <div class="page-header">
      <div>
        <h1 class="page-title">Server Configuration</h1>
        <p class="page-subtitle">Manage OpenLiteSpeed, PHP, MySQL, Postfix, and Dovecot settings</p>
      </div>
    </div>

    <!-- Tabs -->
    <div class="border-b border-surface-200 dark:border-surface-700 mb-6 overflow-x-auto">
      <nav class="flex gap-1 -mb-px min-w-max">
        <button
          v-for="tab in tabs"
          :key="tab.id"
          @click="activeTab = tab.id"
          :class="[
            'flex items-center gap-2 px-4 py-3 text-sm font-medium border-b-2 transition-colors whitespace-nowrap',
            activeTab === tab.id
              ? 'border-primary-500 text-primary-600 dark:text-primary-400'
              : 'border-transparent text-surface-500 hover:text-surface-700 dark:hover:text-surface-300'
          ]"
        >
          <span class="material-symbols-rounded text-lg">{{ tab.icon }}</span>
          {{ tab.label }}
        </button>
      </nav>
    </div>

    <!-- OpenLiteSpeed Tab -->
    <div v-if="activeTab === 'ols'" class="space-y-6">
      <!-- Status Card -->
      <div class="card p-6">
        <div class="flex items-center justify-between flex-wrap gap-4">
          <div class="flex items-center gap-6 flex-wrap">
            <div class="flex items-center gap-3">
              <div :class="[
                'w-3 h-3 rounded-full',
                olsStatus?.running ? 'bg-green-500' : 'bg-red-500'
              ]" />
              <span class="font-medium">
                {{ olsStatus?.running ? 'Running' : 'Stopped' }}
              </span>
            </div>
            
            <div v-if="olsStatus?.version" class="text-surface-500">
              Version: <span class="font-mono">{{ olsStatus.version }}</span>
            </div>
            
            <div v-if="olsStatus?.uptime" class="text-surface-500">
              Uptime: <span class="font-mono">{{ olsStatus.uptime }}</span>
            </div>
            
            <div v-if="olsStatus?.pid" class="text-surface-500">
              PID: <span class="font-mono">{{ olsStatus.pid }}</span>
            </div>
          </div>
          
          <div class="flex items-center gap-3 flex-wrap">
            <button 
              @click="reloadOls"
              class="btn-secondary"
              :disabled="olsSaving"
            >
              <span class="material-symbols-rounded">sync</span>
              Graceful Reload
            </button>
            
            <button 
              @click="restartOls"
              class="btn-secondary"
              :disabled="olsSaving"
            >
              <span class="material-symbols-rounded">refresh</span>
              Restart
            </button>
            
            <button 
              v-if="!olsEditMode"
              @click="olsEditMode = true"
              class="btn-primary"
              :disabled="olsLoading"
            >
              <span class="material-symbols-rounded">edit</span>
              Edit Settings
            </button>
            
            <template v-else>
              <button 
                @click="cancelOlsEdit"
                class="btn-secondary"
                :disabled="olsSaving"
              >
                Cancel
              </button>
              <button 
                @click="saveOlsSettings"
                class="btn-primary"
                :disabled="olsSaving || !olsHasChanges"
              >
                <span v-if="olsSaving" class="spinner"></span>
                <span class="material-symbols-rounded">save</span>
                Save Changes
              </button>
            </template>
          </div>
        </div>
      </div>

      <!-- Listeners Card -->
      <div v-if="olsStatus?.listeners?.length" class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div v-for="listener in olsStatus.listeners" :key="listener.name" class="card p-4 text-center">
          <span :class="[
            'material-symbols-rounded text-3xl mb-2',
            listener.secure ? 'text-green-500' : 'text-primary-500'
          ]">
            {{ listener.secure ? 'lock' : 'language' }}
          </span>
          <p class="font-semibold">{{ listener.name }}</p>
          <p class="text-sm text-surface-500">{{ listener.address }}</p>
        </div>
      </div>

      <!-- Loading state -->
      <div v-if="olsLoading" class="card p-12">
        <div class="flex items-center justify-center gap-3 text-surface-500">
          <span class="spinner"></span>
          <span>Loading OpenLiteSpeed configuration...</span>
        </div>
      </div>

      <!-- Settings by Section -->
      <div v-else class="space-y-6">
        <div v-for="section in olsSections" :key="section.id" class="card p-6">
          <h3 class="text-lg font-semibold mb-4 flex items-center gap-2">
            <span class="material-symbols-rounded text-primary-500">{{ section.icon }}</span>
            {{ section.label }}
          </h3>
          
          <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            <div 
              v-for="def in getSettingsBySection(section.id)" 
              :key="def.key"
              class="p-4 bg-surface-50 dark:bg-surface-800/50 rounded-xl"
            >
              <div class="flex items-start justify-between mb-2">
                <div class="flex-1">
                  <label class="block font-medium text-sm">{{ def.label }}</label>
                  <p class="text-xs text-surface-500">{{ def.description }}</p>
                </div>
                <span 
                  v-if="olsSettings[def.key] !== olsOriginalSettings[def.key]" 
                  class="text-xs px-2 py-0.5 rounded-full bg-amber-100 dark:bg-amber-500/20 text-amber-600 dark:text-amber-400"
                >
                  Modified
                </span>
              </div>
              
              <!-- Toggle for toggle type -->
              <template v-if="def.type === 'toggle'">
                <label class="relative inline-flex items-center cursor-pointer" :class="{ 'pointer-events-none opacity-60': !olsEditMode }">
                  <input 
                    type="checkbox" 
                    v-model="olsSettings[def.key]"
                    :true-value="'1'"
                    :false-value="'0'"
                    :disabled="!olsEditMode"
                    class="sr-only peer" 
                  />
                  <div class="w-11 h-6 bg-surface-300 dark:bg-surface-600 rounded-full peer peer-checked:bg-primary-500 transition-colors"></div>
                  <div class="absolute left-0.5 top-0.5 w-5 h-5 bg-white rounded-full shadow peer-checked:translate-x-5 transition-transform"></div>
                </label>
              </template>
              
              <!-- Regular input -->
              <template v-else>
                <input
                  v-model="olsSettings[def.key]"
                  :disabled="!olsEditMode"
                  :placeholder="def.placeholder"
                  class="input w-full mt-2"
                  :class="!olsEditMode && 'bg-surface-100 dark:bg-surface-700'"
                />
              </template>
            </div>
          </div>
        </div>
      </div>

      <!-- Info Card -->
      <div class="card p-6 bg-blue-50 dark:bg-blue-500/10 border-blue-200 dark:border-blue-500/20">
        <div class="flex gap-4">
          <span class="material-symbols-rounded text-blue-500 text-xl">info</span>
          <div>
            <h4 class="font-medium text-blue-700 dark:text-blue-400 mb-1">About OpenLiteSpeed</h4>
            <ul class="text-sm text-blue-600 dark:text-blue-300 space-y-1">
              <li>OpenLiteSpeed is the high-performance web server powering your sites.</li>
              <li>Use "Graceful Reload" to apply config changes without dropping connections.</li>
              <li>Use "Restart" for a full service restart when required.</li>
              <li>Config file location: /usr/local/lsws/conf/httpd_config.conf</li>
            </ul>
          </div>
        </div>
      </div>
    </div>

    <!-- PHP Tab -->
    <div v-if="activeTab === 'php'" class="space-y-6">
      <!-- Version Selector -->
      <div class="card p-6">
        <div class="flex items-center justify-between flex-wrap gap-4">
          <div class="flex items-center gap-4">
            <label class="font-medium">PHP Version:</label>
            <select 
              v-model="selectedVersion" 
              class="input w-48"
              :disabled="phpLoading"
            >
              <option v-for="php in phpVersions" :key="php.version" :value="php.version">
                PHP {{ php.version }} {{ php.active ? '(Active)' : '' }}
              </option>
            </select>
          </div>
          
          <div class="flex items-center gap-3">
            <button 
              @click="restartPhp"
              class="btn-secondary"
              :disabled="phpSaving || !selectedVersion"
            >
              <span class="material-symbols-rounded">refresh</span>
              Restart PHP-FPM
            </button>
            
            <button 
              v-if="!phpEditMode"
              @click="phpEditMode = true"
              class="btn-primary"
              :disabled="phpLoading"
            >
              <span class="material-symbols-rounded">edit</span>
              Edit Settings
            </button>
            
            <template v-else>
              <button 
                @click="cancelPhpEdit"
                class="btn-secondary"
                :disabled="phpSaving"
              >
                Cancel
              </button>
              <button 
                @click="savePhpSettings"
                class="btn-primary"
                :disabled="phpSaving || !phpHasChanges"
              >
                <span v-if="phpSaving" class="spinner"></span>
                <span class="material-symbols-rounded">save</span>
                Save Changes
              </button>
            </template>
          </div>
        </div>
      </div>

      <!-- Loading state -->
      <div v-if="phpLoading" class="card p-12">
        <div class="flex items-center justify-center gap-3 text-surface-500">
          <span class="spinner"></span>
          <span>Loading PHP configuration...</span>
        </div>
      </div>

      <!-- Settings by Section -->
      <div v-else class="space-y-6">
        <div v-for="section in phpSections" :key="section.id" class="card p-6">
          <h3 class="text-lg font-semibold mb-4 flex items-center gap-2">
            <span class="material-symbols-rounded text-primary-500">{{ section.icon }}</span>
            {{ section.label }}
          </h3>
          
          <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            <div 
              v-for="def in getPhpSettingsBySection(section.id)" 
              :key="def.key"
              class="p-4 bg-surface-50 dark:bg-surface-800/50 rounded-xl"
            >
              <div class="flex items-start justify-between mb-2">
                <div class="flex-1">
                  <label class="block font-medium text-sm">{{ def.label }}</label>
                  <p class="text-xs text-surface-500">{{ def.description }}</p>
                </div>
                <span 
                  v-if="phpSettings[def.key] !== phpOriginalSettings[def.key]" 
                  class="text-xs px-2 py-0.5 rounded-full bg-amber-100 dark:bg-amber-500/20 text-amber-600 dark:text-amber-400"
                >
                  Modified
                </span>
              </div>
              
              <!-- Toggle for boolean settings -->
              <template v-if="def.type === 'toggle'">
                <div class="flex items-center gap-3 mt-2">
                  <button
                    @click="phpSettings[def.key] = (phpSettings[def.key] === 'On' || phpSettings[def.key] === '1') ? (def.key.startsWith('opcache') ? '0' : 'Off') : (def.key.startsWith('opcache') ? '1' : 'On')"
                    :disabled="!phpEditMode"
                    :class="[
                      'relative inline-flex h-6 w-11 items-center rounded-full transition-colors',
                      (phpSettings[def.key] === 'On' || phpSettings[def.key] === '1') ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600',
                      !phpEditMode && 'opacity-60 cursor-not-allowed'
                    ]"
                  >
                    <span
                      :class="[
                        'inline-block h-4 w-4 transform rounded-full bg-white transition-transform',
                        (phpSettings[def.key] === 'On' || phpSettings[def.key] === '1') ? 'translate-x-6' : 'translate-x-1'
                      ]"
                    />
                  </button>
                  <span class="text-sm font-medium">
                    {{ phpSettings[def.key] || def.placeholder }}
                  </span>
                </div>
              </template>
              
              <!-- Input for other settings -->
              <template v-else>
                <input
                  v-model="phpSettings[def.key]"
                  :disabled="!phpEditMode"
                  :placeholder="def.placeholder"
                  class="input w-full mt-2"
                  :class="!phpEditMode && 'bg-surface-100 dark:bg-surface-700'"
                />
              </template>
            </div>
          </div>
        </div>
      </div>

      <!-- Info Card -->
      <div class="card p-6 bg-blue-50 dark:bg-blue-500/10 border-blue-200 dark:border-blue-500/20">
        <div class="flex gap-4">
          <span class="material-symbols-rounded text-blue-500 text-xl">info</span>
          <div>
            <h4 class="font-medium text-blue-700 dark:text-blue-400 mb-1">Important Notes</h4>
            <ul class="text-sm text-blue-600 dark:text-blue-300 space-y-1">
              <li>Changes to PHP configuration will affect all sites using this PHP version.</li>
              <li>After saving, you may need to restart PHP-FPM for changes to take effect.</li>
              <li>Some settings may be overridden at the site level via .htaccess or per-vhost config.</li>
            </ul>
          </div>
        </div>
      </div>
    </div>

    <!-- MySQL Tab -->
    <div v-if="activeTab === 'mysql'" class="space-y-6">
      <!-- Status Card -->
      <div class="card p-6">
        <div class="flex items-center justify-between flex-wrap gap-4">
          <div class="flex items-center gap-6 flex-wrap">
            <div class="flex items-center gap-3">
              <div :class="[
                'w-3 h-3 rounded-full',
                mysqlStatus?.running ? 'bg-green-500' : 'bg-red-500'
              ]" />
              <span class="font-medium">
                {{ mysqlStatus?.running ? 'Running' : 'Stopped' }}
              </span>
            </div>
            
            <div v-if="mysqlStatus?.version" class="text-surface-500">
              Version: <span class="font-mono">{{ mysqlStatus.version }}</span>
            </div>
            
            <div v-if="mysqlStatus?.uptime" class="text-surface-500">
              Uptime: <span class="font-mono">{{ mysqlStatus.uptime }}</span>
            </div>
          </div>
          
          <div class="flex items-center gap-3">
            <button 
              @click="restartMysql"
              class="btn-secondary"
              :disabled="mysqlSaving"
            >
              <span class="material-symbols-rounded">refresh</span>
              Restart MySQL
            </button>
            
            <button 
              v-if="!mysqlEditMode"
              @click="mysqlEditMode = true"
              class="btn-primary"
              :disabled="mysqlLoading"
            >
              <span class="material-symbols-rounded">edit</span>
              Edit Settings
            </button>
            
            <template v-else>
              <button 
                @click="cancelMysqlEdit"
                class="btn-secondary"
                :disabled="mysqlSaving"
              >
                Cancel
              </button>
              <button 
                @click="saveMysqlSettings"
                class="btn-primary"
                :disabled="mysqlSaving || !mysqlHasChanges"
              >
                <span v-if="mysqlSaving" class="spinner"></span>
                <span class="material-symbols-rounded">save</span>
                Save Changes
              </button>
            </template>
          </div>
        </div>
      </div>

      <!-- Loading state -->
      <div v-if="mysqlLoading" class="card p-12">
        <div class="flex items-center justify-center gap-3 text-surface-500">
          <span class="spinner"></span>
          <span>Loading MySQL configuration...</span>
        </div>
      </div>

      <template v-else>
        <!-- Settings by Section -->
        <div class="space-y-6">
          <div v-for="section in mysqlSections" :key="section.id" class="card p-6">
            <h3 class="text-lg font-semibold mb-4 flex items-center gap-2">
              <span class="material-symbols-rounded text-primary-500">{{ section.icon }}</span>
              {{ section.label }}
            </h3>
            
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
              <div 
                v-for="def in getMysqlSettingsBySection(section.id)" 
                :key="def.key"
                class="p-4 bg-surface-50 dark:bg-surface-800/50 rounded-xl"
              >
                <div class="flex items-start justify-between mb-2">
                  <div class="flex-1">
                    <label class="block font-medium text-sm">{{ def.label }}</label>
                    <p class="text-xs text-surface-500">{{ def.description }}</p>
                  </div>
                  <span 
                    v-if="mysqlSettings[def.key] !== mysqlOriginalSettings[def.key]" 
                    class="text-xs px-2 py-0.5 rounded-full bg-amber-100 dark:bg-amber-500/20 text-amber-600 dark:text-amber-400"
                  >
                    Modified
                  </span>
                </div>
                
                <!-- Toggle for boolean settings -->
                <template v-if="def.type === 'toggle'">
                  <div class="flex items-center gap-3 mt-2">
                    <button
                      @click="mysqlSettings[def.key] = mysqlSettings[def.key] === 'ON' ? 'OFF' : 'ON'"
                      :disabled="!mysqlEditMode"
                      :class="[
                        'relative inline-flex h-6 w-11 items-center rounded-full transition-colors',
                        mysqlSettings[def.key] === 'ON' ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600',
                        !mysqlEditMode && 'opacity-60 cursor-not-allowed'
                      ]"
                    >
                      <span
                        :class="[
                          'inline-block h-4 w-4 transform rounded-full bg-white transition-transform',
                          mysqlSettings[def.key] === 'ON' ? 'translate-x-6' : 'translate-x-1'
                        ]"
                      />
                    </button>
                    <span class="text-sm font-medium">
                      {{ mysqlSettings[def.key] || def.placeholder }}
                    </span>
                  </div>
                </template>
                
                <!-- Input for other settings -->
                <template v-else>
                  <input
                    v-model="mysqlSettings[def.key]"
                    :disabled="!mysqlEditMode"
                    :placeholder="def.placeholder"
                    class="input w-full mt-2"
                    :class="!mysqlEditMode && 'bg-surface-100 dark:bg-surface-700'"
                  />
                </template>
              </div>
            </div>
          </div>
        </div>

        <!-- All Variables -->
        <div class="card p-6">
          <div class="flex items-center justify-between mb-4 flex-wrap gap-4">
            <h3 class="font-semibold flex items-center gap-2">
              <span class="material-symbols-rounded text-primary-500">data_object</span>
              All Server Variables
            </h3>
            <div class="relative">
              <span class="material-symbols-rounded absolute left-3 top-1/2 -translate-y-1/2 text-surface-400">search</span>
              <input 
                v-model="mysqlSearchQuery"
                type="text" 
                placeholder="Search variables..."
                class="input pl-10 w-64"
              />
            </div>
          </div>

          <div class="overflow-x-auto">
            <table class="w-full">
              <thead>
                <tr class="text-left border-b border-surface-200 dark:border-surface-700">
                  <th class="pb-3 font-medium">Variable</th>
                  <th class="pb-3 font-medium">Value</th>
                </tr>
              </thead>
              <tbody class="font-mono text-sm">
                <tr 
                  v-for="v in filteredMysqlVariables" 
                  :key="v.name"
                  class="border-b border-surface-100 dark:border-surface-800"
                >
                  <td class="py-2 text-surface-600 dark:text-surface-400">{{ v.name }}</td>
                  <td class="py-2">{{ v.value }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          
          <p v-if="mysqlVariables.length > 50 && !mysqlSearchQuery" class="mt-4 text-sm text-surface-500">
            Showing first 50 variables. Use search to find specific variables.
          </p>
        </div>
      </template>

      <!-- Info Card -->
      <div class="card p-6 bg-amber-50 dark:bg-amber-500/10 border-amber-200 dark:border-amber-500/20">
        <div class="flex gap-4">
          <span class="material-symbols-rounded text-amber-500 text-xl">warning</span>
          <div>
            <h4 class="font-medium text-amber-700 dark:text-amber-400 mb-1">Warning</h4>
            <ul class="text-sm text-amber-600 dark:text-amber-300 space-y-1">
              <li>Changing MySQL settings can affect all databases and applications.</li>
              <li>Incorrect settings may cause MySQL to fail to start.</li>
              <li>Always backup your configuration before making changes.</li>
            </ul>
          </div>
        </div>
      </div>
    </div>

    <!-- Postfix Tab -->
    <div v-if="activeTab === 'postfix'" class="space-y-6">
      <!-- Status Card -->
      <div class="card p-6">
        <div class="flex items-center justify-between flex-wrap gap-4">
          <div class="flex items-center gap-6 flex-wrap">
            <div class="flex items-center gap-3">
              <div :class="[
                'w-3 h-3 rounded-full',
                postfixStatus?.running ? 'bg-green-500' : 'bg-red-500'
              ]" />
              <span class="font-medium">
                {{ postfixStatus?.running ? 'Running' : 'Stopped' }}
              </span>
            </div>
            
            <div v-if="postfixStatus?.version" class="text-surface-500">
              Version: <span class="font-mono">{{ postfixStatus.version }}</span>
            </div>
            
            <div class="text-surface-500">
              Queue: <span class="font-mono">{{ postfixQueue.length }} messages</span>
            </div>
          </div>
          
          <div class="flex items-center gap-3 flex-wrap">
            <button 
              @click="flushQueue"
              class="btn-secondary"
              :disabled="postfixSaving || postfixQueue.length === 0"
            >
              <span class="material-symbols-rounded">outbox</span>
              Flush Queue
            </button>
            
            <button 
              @click="restartPostfix"
              class="btn-secondary"
              :disabled="postfixSaving"
            >
              <span class="material-symbols-rounded">refresh</span>
              Restart Postfix
            </button>
            
            <button 
              v-if="!postfixEditMode"
              @click="postfixEditMode = true"
              class="btn-primary"
              :disabled="postfixLoading"
            >
              <span class="material-symbols-rounded">edit</span>
              Edit Settings
            </button>
            
            <template v-else>
              <button 
                @click="cancelPostfixEdit"
                class="btn-secondary"
                :disabled="postfixSaving"
              >
                Cancel
              </button>
              <button 
                @click="savePostfixSettings"
                class="btn-primary"
                :disabled="postfixSaving || !postfixHasChanges"
              >
                <span v-if="postfixSaving" class="spinner"></span>
                <span class="material-symbols-rounded">save</span>
                Save Changes
              </button>
            </template>
          </div>
        </div>
      </div>

      <!-- Loading state -->
      <div v-if="postfixLoading" class="card p-12">
        <div class="flex items-center justify-center gap-3 text-surface-500">
          <span class="spinner"></span>
          <span>Loading Postfix configuration...</span>
        </div>
      </div>

      <template v-else>
        <!-- Settings Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
          <div 
            v-for="def in postfixSettingDefinitions" 
            :key="def.key"
            class="card p-5"
          >
            <div class="flex items-start justify-between">
              <div class="flex-1">
                <label class="block font-medium mb-1">{{ def.label }}</label>
                <p class="text-sm text-surface-500 mb-3">{{ def.description }}</p>
              </div>
              <span 
                v-if="postfixSettings[def.key] !== postfixOriginalSettings[def.key]" 
                class="text-xs px-2 py-0.5 rounded-full bg-amber-100 dark:bg-amber-500/20 text-amber-600 dark:text-amber-400"
              >
                Modified
              </span>
            </div>
            
            <!-- Toggle for boolean settings -->
            <template v-if="def.type === 'toggle'">
              <div class="flex items-center gap-3">
                <button
                  @click="postfixSettings[def.key] = postfixSettings[def.key] === 'yes' ? 'no' : 'yes'"
                  :disabled="!postfixEditMode"
                  :class="[
                    'relative inline-flex h-6 w-11 items-center rounded-full transition-colors',
                    postfixSettings[def.key] === 'yes' ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600',
                    !postfixEditMode && 'opacity-60 cursor-not-allowed'
                  ]"
                >
                  <span
                    :class="[
                      'inline-block h-4 w-4 transform rounded-full bg-white transition-transform',
                      postfixSettings[def.key] === 'yes' ? 'translate-x-6' : 'translate-x-1'
                    ]"
                  />
                </button>
                <span class="text-sm font-medium">
                  {{ postfixSettings[def.key] || def.placeholder }}
                </span>
              </div>
            </template>
            
            <!-- Select for options -->
            <template v-else-if="def.type === 'select'">
              <select
                v-model="postfixSettings[def.key]"
                :disabled="!postfixEditMode"
                class="input w-full"
                :class="!postfixEditMode && 'bg-surface-50 dark:bg-surface-800'"
              >
                <option v-for="opt in def.options" :key="opt" :value="opt">{{ opt }}</option>
              </select>
            </template>
            
            <!-- Input for other settings -->
            <template v-else>
              <input
                v-model="postfixSettings[def.key]"
                :disabled="!postfixEditMode"
                :placeholder="def.placeholder"
                class="input w-full"
                :class="!postfixEditMode && 'bg-surface-50 dark:bg-surface-800'"
              />
            </template>
          </div>
        </div>

        <!-- Queue Table -->
        <div v-if="postfixQueue.length > 0" class="card p-6">
          <h3 class="font-semibold mb-4 flex items-center gap-2">
            <span class="material-symbols-rounded text-amber-500">schedule_send</span>
            Mail Queue ({{ postfixQueue.length }} messages)
          </h3>

          <div class="overflow-x-auto">
            <table class="w-full">
              <thead>
                <tr class="text-left border-b border-surface-200 dark:border-surface-700">
                  <th class="pb-3 font-medium">ID</th>
                  <th class="pb-3 font-medium">From</th>
                  <th class="pb-3 font-medium">To</th>
                  <th class="pb-3 font-medium">Size</th>
                  <th class="pb-3 font-medium">Status</th>
                </tr>
              </thead>
              <tbody class="text-sm">
                <tr 
                  v-for="msg in postfixQueue.slice(0, 20)" 
                  :key="msg.id"
                  class="border-b border-surface-100 dark:border-surface-800"
                >
                  <td class="py-2 font-mono text-xs">{{ msg.id }}</td>
                  <td class="py-2">{{ msg.from }}</td>
                  <td class="py-2">{{ msg.to }}</td>
                  <td class="py-2">{{ formatBytes(msg.size) }}</td>
                  <td class="py-2">
                    <span :class="[
                      'px-2 py-0.5 text-xs rounded-full',
                      msg.status === 'active' ? 'bg-green-100 dark:bg-green-500/20 text-green-600' : 'bg-amber-100 dark:bg-amber-500/20 text-amber-600'
                    ]">
                      {{ msg.status }}
                    </span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          
          <p v-if="postfixQueue.length > 20" class="mt-4 text-sm text-surface-500">
            Showing first 20 of {{ postfixQueue.length }} messages.
          </p>
        </div>
      </template>

      <!-- Info Card -->
      <div class="card p-6 bg-blue-50 dark:bg-blue-500/10 border-blue-200 dark:border-blue-500/20">
        <div class="flex gap-4">
          <span class="material-symbols-rounded text-blue-500 text-xl">info</span>
          <div>
            <h4 class="font-medium text-blue-700 dark:text-blue-400 mb-1">About Postfix</h4>
            <ul class="text-sm text-blue-600 dark:text-blue-300 space-y-1">
              <li>Postfix is the SMTP server responsible for sending and receiving emails.</li>
              <li>Changes require a restart to take effect.</li>
              <li>TLS settings affect email security and deliverability.</li>
            </ul>
          </div>
        </div>
      </div>
    </div>

    <!-- Dovecot Tab -->
    <div v-if="activeTab === 'dovecot'" class="space-y-6">
      <!-- Status Card -->
      <div class="card p-6">
        <div class="flex items-center justify-between flex-wrap gap-4">
          <div class="flex items-center gap-6 flex-wrap">
            <div class="flex items-center gap-3">
              <div :class="[
                'w-3 h-3 rounded-full',
                dovecotStatus?.running ? 'bg-green-500' : 'bg-red-500'
              ]" />
              <span class="font-medium">
                {{ dovecotStatus?.running ? 'Running' : 'Stopped' }}
              </span>
            </div>
            
            <div v-if="dovecotStatus?.version" class="text-surface-500">
              Version: <span class="font-mono">{{ dovecotStatus.version }}</span>
            </div>
            
            <div class="text-surface-500">
              Active: <span class="font-mono">{{ dovecotConnections.length }} connections</span>
            </div>
          </div>
          
          <div class="flex items-center gap-3">
            <button 
              @click="restartDovecot"
              class="btn-secondary"
              :disabled="dovecotSaving"
            >
              <span class="material-symbols-rounded">refresh</span>
              Restart Dovecot
            </button>
            
            <button 
              v-if="!dovecotEditMode"
              @click="dovecotEditMode = true"
              class="btn-primary"
              :disabled="dovecotLoading"
            >
              <span class="material-symbols-rounded">edit</span>
              Edit Settings
            </button>
            
            <template v-else>
              <button 
                @click="cancelDovecotEdit"
                class="btn-secondary"
                :disabled="dovecotSaving"
              >
                Cancel
              </button>
              <button 
                @click="saveDovecotSettings"
                class="btn-primary"
                :disabled="dovecotSaving || !dovecotHasChanges"
              >
                <span v-if="dovecotSaving" class="spinner"></span>
                <span class="material-symbols-rounded">save</span>
                Save Changes
              </button>
            </template>
          </div>
        </div>
      </div>

      <!-- Protocols Card -->
      <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div class="card p-4 text-center">
          <span class="material-symbols-rounded text-3xl text-primary-500 mb-2">mail</span>
          <p class="font-semibold">IMAP</p>
          <p class="text-sm text-surface-500">Port 143</p>
        </div>
        <div class="card p-4 text-center">
          <span class="material-symbols-rounded text-3xl text-green-500 mb-2">lock</span>
          <p class="font-semibold">IMAPS</p>
          <p class="text-sm text-surface-500">Port 993</p>
        </div>
        <div class="card p-4 text-center">
          <span class="material-symbols-rounded text-3xl text-blue-500 mb-2">download</span>
          <p class="font-semibold">POP3</p>
          <p class="text-sm text-surface-500">Port 110</p>
        </div>
        <div class="card p-4 text-center">
          <span class="material-symbols-rounded text-3xl text-purple-500 mb-2">enhanced_encryption</span>
          <p class="font-semibold">POP3S</p>
          <p class="text-sm text-surface-500">Port 995</p>
        </div>
      </div>

      <!-- Loading state -->
      <div v-if="dovecotLoading" class="card p-12">
        <div class="flex items-center justify-center gap-3 text-surface-500">
          <span class="spinner"></span>
          <span>Loading Dovecot configuration...</span>
        </div>
      </div>

      <template v-else>
        <!-- Settings Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
          <div 
            v-for="def in dovecotSettingDefinitions" 
            :key="def.key"
            class="card p-5"
          >
            <div class="flex items-start justify-between">
              <div class="flex-1">
                <label class="block font-medium mb-1">{{ def.label }}</label>
                <p class="text-sm text-surface-500 mb-3">{{ def.description }}</p>
              </div>
              <span 
                v-if="dovecotSettings[def.key] !== dovecotOriginalSettings[def.key]" 
                class="text-xs px-2 py-0.5 rounded-full bg-amber-100 dark:bg-amber-500/20 text-amber-600 dark:text-amber-400"
              >
                Modified
              </span>
            </div>
            
            <!-- Toggle for boolean settings -->
            <template v-if="def.type === 'toggle'">
              <div class="flex items-center gap-3">
                <button
                  @click="dovecotSettings[def.key] = dovecotSettings[def.key] === 'yes' ? 'no' : 'yes'"
                  :disabled="!dovecotEditMode"
                  :class="[
                    'relative inline-flex h-6 w-11 items-center rounded-full transition-colors',
                    dovecotSettings[def.key] === 'yes' ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600',
                    !dovecotEditMode && 'opacity-60 cursor-not-allowed'
                  ]"
                >
                  <span
                    :class="[
                      'inline-block h-4 w-4 transform rounded-full bg-white transition-transform',
                      dovecotSettings[def.key] === 'yes' ? 'translate-x-6' : 'translate-x-1'
                    ]"
                  />
                </button>
                <span class="text-sm font-medium">
                  {{ dovecotSettings[def.key] || def.placeholder }}
                </span>
              </div>
            </template>
            
            <!-- Select for options -->
            <template v-else-if="def.type === 'select'">
              <select
                v-model="dovecotSettings[def.key]"
                :disabled="!dovecotEditMode"
                class="input w-full"
                :class="!dovecotEditMode && 'bg-surface-50 dark:bg-surface-800'"
              >
                <option v-for="opt in def.options" :key="opt" :value="opt">{{ opt }}</option>
              </select>
            </template>
            
            <!-- Input for other settings -->
            <template v-else>
              <input
                v-model="dovecotSettings[def.key]"
                :disabled="!dovecotEditMode"
                :placeholder="def.placeholder"
                class="input w-full"
                :class="!dovecotEditMode && 'bg-surface-50 dark:bg-surface-800'"
              />
            </template>
          </div>
        </div>

        <!-- Active Connections -->
        <div v-if="dovecotConnections.length > 0" class="card p-6">
          <h3 class="font-semibold mb-4 flex items-center gap-2">
            <span class="material-symbols-rounded text-green-500">group</span>
            Active Connections ({{ dovecotConnections.length }})
          </h3>

          <div class="overflow-x-auto">
            <table class="w-full">
              <thead>
                <tr class="text-left border-b border-surface-200 dark:border-surface-700">
                  <th class="pb-3 font-medium">User</th>
                  <th class="pb-3 font-medium">Protocol</th>
                  <th class="pb-3 font-medium">IP Address</th>
                  <th class="pb-3 font-medium">Connected</th>
                </tr>
              </thead>
              <tbody class="text-sm">
                <tr 
                  v-for="conn in dovecotConnections.slice(0, 20)" 
                  :key="conn.id"
                  class="border-b border-surface-100 dark:border-surface-800"
                >
                  <td class="py-2">{{ conn.user }}</td>
                  <td class="py-2">
                    <span class="px-2 py-0.5 text-xs rounded-full bg-primary-100 dark:bg-primary-500/20 text-primary-600">
                      {{ conn.protocol }}
                    </span>
                  </td>
                  <td class="py-2 font-mono text-xs">{{ conn.ip }}</td>
                  <td class="py-2 text-surface-500">{{ conn.connected }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          
          <p v-if="dovecotConnections.length > 20" class="mt-4 text-sm text-surface-500">
            Showing first 20 of {{ dovecotConnections.length }} connections.
          </p>
        </div>
      </template>

      <!-- Info Card -->
      <div class="card p-6 bg-blue-50 dark:bg-blue-500/10 border-blue-200 dark:border-blue-500/20">
        <div class="flex gap-4">
          <span class="material-symbols-rounded text-blue-500 text-xl">info</span>
          <div>
            <h4 class="font-medium text-blue-700 dark:text-blue-400 mb-1">About Dovecot</h4>
            <ul class="text-sm text-blue-600 dark:text-blue-300 space-y-1">
              <li>Dovecot handles IMAP and POP3 for email clients to retrieve mail.</li>
              <li>SSL/TLS settings affect connection security.</li>
              <li>Debug logging can help troubleshoot authentication issues.</li>
            </ul>
          </div>
        </div>
      </div>
    </div>

    <!-- ============================================ -->
    <!-- Logs Tab -->
    <!-- ============================================ -->
    <div v-if="activeTab === 'logs'" class="space-y-6">
      <!-- Service Selector -->
      <div class="flex flex-wrap gap-2">
        <button
          v-for="svc in logsServices"
          :key="svc.id"
          @click="changeLogsService(svc.id)"
          :class="[
            'btn-sm flex items-center gap-2 transition-all',
            logsService === svc.id ? 'btn-primary' : 'btn-secondary'
          ]"
        >
          <span class="material-symbols-rounded text-lg">{{ svc.icon }}</span>
          {{ svc.label }}
        </button>
      </div>

      <!-- Controls Row -->
      <div class="flex flex-wrap items-center gap-4">
        <!-- PHP Version Selector (only for PHP) -->
        <div v-if="logsService === 'php' && phpVersions.length > 0" class="flex items-center gap-2">
          <label class="text-sm text-surface-500">PHP Version:</label>
          <select v-model="logsPhpVersion" @change="fetchLogs" class="input w-auto">
            <option v-for="v in phpVersions" :key="v.version" :value="v.version">
              PHP {{ v.version }}
            </option>
          </select>
        </div>

        <!-- Log Type Selector -->
        <div class="flex items-center gap-2">
          <label class="text-sm text-surface-500">Log Type:</label>
          <select v-model="logsType" @change="fetchLogs" class="input w-auto">
            <option 
              v-for="t in logsAvailableTypes" 
              :key="t.id" 
              :value="t.id"
              :disabled="!t.exists"
            >
              {{ t.label }} {{ t.exists ? `(${t.size_human})` : '(N/A)' }}
            </option>
          </select>
        </div>

        <!-- Search -->
        <div class="flex items-center gap-2 flex-1 min-w-[200px]">
          <input
            v-model="logsSearch"
            type="text"
            class="input w-full"
            placeholder="Search logs..."
            @keyup.enter="fetchLogs"
          />
          <button @click="fetchLogs" class="btn-secondary btn-sm">
            <span class="material-symbols-rounded">search</span>
          </button>
        </div>

        <!-- Refresh -->
        <button 
          @click="fetchLogs" 
          class="btn-secondary btn-sm"
          :disabled="logsLoading"
        >
          <span class="material-symbols-rounded" :class="{ 'animate-spin': logsLoading }">refresh</span>
          Refresh
        </button>
      </div>

      <!-- Filter Buttons -->
      <div v-if="Object.keys(logsAvailableFilters).length > 0" class="flex flex-wrap gap-2">
        <span class="text-sm text-surface-500 self-center mr-2">Quick Filters:</span>
        <button
          v-for="(patterns, filterName) in logsAvailableFilters"
          :key="filterName"
          @click="applyLogsFilter(filterName)"
          :class="[
            'px-3 py-1.5 text-xs rounded-full border transition-all font-medium',
            logsFilter === filterName 
              ? 'bg-primary-500 text-white border-primary-500' 
              : 'bg-surface-100 dark:bg-surface-800 border-surface-200 dark:border-surface-700 hover:bg-surface-200 dark:hover:bg-surface-700'
          ]"
        >
          <span :class="[
            'mr-1.5',
            filterName.includes('error') || filterName.includes('failed') || filterName === 'bounced' || filterName === 'spam' || filterName === 'deadlock' ? 'text-red-400' :
            filterName.includes('warning') || filterName === 'deferred' || filterName.includes('403') || filterName.includes('404') ? 'text-amber-400' :
            filterName === 'delivered' || filterName === 'login' || filterName === 'success' || filterName === 'startup' ? 'text-green-400' :
            filterName.includes('ssl') || filterName === 'tls' || filterName === 'connection' ? 'text-cyan-400' :
            filterName === 'access' || filterName === 'redirects' ? 'text-violet-400' :
            filterName === 'modsec' || filterName === 'permission' ? 'text-orange-400' :
            filterName === 'memory' || filterName === 'timeout' || filterName === 'slow_query' ? 'text-pink-400' :
            'text-blue-400'
          ]">●</span>
          {{ filterName.replace(/_/g, ' ') }}
        </button>
        <button
          v-if="logsFilter"
          @click="logsFilter = ''; fetchLogs()"
          class="px-3 py-1.5 text-xs rounded-full border bg-red-100 dark:bg-red-500/20 border-red-200 dark:border-red-500/30 text-red-600 dark:text-red-400 hover:bg-red-200 dark:hover:bg-red-500/30 font-medium"
        >
          <span class="material-symbols-rounded text-sm mr-1">close</span>
          Clear
        </button>
      </div>

      <!-- Log Display -->
      <div class="card">
        <div class="card-header flex items-center justify-between">
          <div class="flex items-center gap-3">
            <span class="material-symbols-rounded text-surface-400">article</span>
            <h3 class="font-medium">
              {{ logsServices.find(s => s.id === logsService)?.label }} Logs
              <span v-if="logsTotal" class="text-sm font-normal text-surface-500">
                ({{ logsTotal }} lines)
              </span>
            </h3>
          </div>
          <div v-if="logsFilter" class="badge badge-info">
            Filtered: {{ logsFilter }}
          </div>
        </div>

        <!-- Loading -->
        <div v-if="logsLoading" class="p-8 text-center">
          <span class="spinner"></span>
          <p class="text-surface-500 mt-2">Loading logs...</p>
        </div>

        <!-- Log Lines -->
        <div v-else-if="logsLines.length" class="bg-surface-900 dark:bg-surface-950 rounded-b-xl overflow-hidden">
          <div class="max-h-[700px] overflow-y-auto">
            <div 
              v-for="(line, index) in logsLines" 
              :key="index"
              class="border-l-4 px-3 py-2 hover:bg-surface-800/50 transition-colors"
              :class="getLogLevelClasses(parseLogLine(line).level)"
            >
              <div class="flex items-start gap-3">
                <!-- Level Badge -->
                <span 
                  class="inline-flex items-center justify-center px-1.5 py-0.5 text-[10px] font-bold rounded shrink-0 w-8"
                  :class="getLogLevelBadge(parseLogLine(line).level).class"
                >
                  {{ getLogLevelBadge(parseLogLine(line).level).text }}
                </span>
                
                <!-- Timestamp -->
                <span 
                  v-if="parseLogLine(line).timestamp" 
                  class="text-[11px] text-surface-500 shrink-0 font-mono"
                >
                  {{ parseLogLine(line).timestamp }}
                </span>
                
                <!-- HTTP Status Badge (for access logs) -->
                <span 
                  v-if="parseLogLine(line).status"
                  class="inline-flex items-center justify-center px-1.5 py-0.5 text-[10px] font-bold rounded shrink-0"
                  :class="getHttpStatusBadge(parseLogLine(line).status).class"
                >
                  {{ parseLogLine(line).status }}
                </span>
                
                <!-- HTTP Method (for access logs) -->
                <span 
                  v-if="parseLogLine(line).method"
                  class="text-[11px] font-bold text-cyan-400 shrink-0"
                >
                  {{ parseLogLine(line).method }}
                </span>
                
                <!-- IP Address -->
                <span 
                  v-if="parseLogLine(line).ip && !parseLogLine(line).method"
                  class="text-[11px] text-violet-400 shrink-0 font-mono"
                >
                  {{ parseLogLine(line).ip }}
                </span>
                
                <!-- Message -->
                <span 
                  class="text-xs break-all flex-1"
                  :class="getLogLevelColor(parseLogLine(line).level)"
                >
                  {{ parseLogLine(line).method ? parseLogLine(line).url : parseLogLine(line).message }}
                </span>
              </div>
            </div>
          </div>
        </div>

        <!-- Empty State -->
        <div v-else class="p-12 text-center text-surface-400">
          <span class="material-symbols-rounded text-4xl mb-2 block">article</span>
          <p>No log entries found</p>
          <p v-if="logsFilter" class="text-sm mt-1">Try clearing the filter or changing the log type</p>
        </div>
      </div>

      <!-- Info Cards -->
      <div class="grid md:grid-cols-2 gap-4">
        <!-- Log Locations Info -->
        <div class="card p-6 bg-blue-50 dark:bg-blue-500/10 border-blue-200 dark:border-blue-500/20">
          <div class="flex gap-4">
            <span class="material-symbols-rounded text-blue-500 text-xl">folder</span>
            <div>
              <h4 class="font-medium text-blue-700 dark:text-blue-400 mb-2">Log Locations</h4>
              <ul class="text-sm text-blue-600 dark:text-blue-300 space-y-1">
                <li><strong>OpenLiteSpeed:</strong> /usr/local/lsws/logs/error.log, access.log</li>
                <li><strong>PHP/LSWS:</strong> /usr/local/lsws/logs/stderr.log, error.log</li>
                <li><strong>MySQL:</strong> /var/log/mysql/error.log or journalctl</li>
                <li><strong>Postfix:</strong> /var/log/mail.log or journalctl</li>
                <li><strong>Dovecot:</strong> /var/log/mail.log or journalctl</li>
              </ul>
            </div>
          </div>
        </div>

        <!-- Filter Tips -->
        <div class="card p-6 bg-amber-50 dark:bg-amber-500/10 border-amber-200 dark:border-amber-500/20">
          <div class="flex gap-4">
            <span class="material-symbols-rounded text-amber-500 text-xl">lightbulb</span>
            <div>
              <h4 class="font-medium text-amber-700 dark:text-amber-400 mb-2">Log Color Codes</h4>
              <div class="grid grid-cols-2 gap-x-6 gap-y-1 text-sm text-amber-600 dark:text-amber-300">
                <div><span class="inline-block w-8 text-center px-1 py-0.5 text-[10px] font-bold rounded bg-red-500/20 text-red-400 mr-2">ERR</span> Errors/Failures</div>
                <div><span class="inline-block w-8 text-center px-1 py-0.5 text-[10px] font-bold rounded bg-amber-500/20 text-amber-400 mr-2">WRN</span> Warnings</div>
                <div><span class="inline-block w-8 text-center px-1 py-0.5 text-[10px] font-bold rounded bg-green-500/20 text-green-400 mr-2">OK</span> Success/Delivered</div>
                <div><span class="inline-block w-8 text-center px-1 py-0.5 text-[10px] font-bold rounded bg-blue-500/20 text-blue-400 mr-2">INF</span> Info/Notice</div>
                <div><span class="inline-block w-8 text-center px-1 py-0.5 text-[10px] font-bold rounded bg-purple-500/20 text-purple-400 mr-2">DBG</span> Debug</div>
                <div><span class="text-violet-400 mr-2">IP</span> IP Addresses highlighted</div>
              </div>
              <p class="text-sm text-amber-600 dark:text-amber-300 mt-2">HTTP status codes: <span class="text-green-400">2xx</span> <span class="text-blue-400">3xx</span> <span class="text-amber-400">4xx</span> <span class="text-red-400">5xx</span></p>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

