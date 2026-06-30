<script setup>
import { ref, computed, watch } from 'vue'
import api from '@/services/api'
import { useToastStore } from '@/stores/toast'
import Modal from '@/components/Modal.vue'

const props = defineProps({
  show: Boolean,
  vpnConnections: { type: Array, default: () => [] },
})

const emit = defineEmits(['close', 'completed'])

const toast = useToastStore()

const wizardSteps = [
  { num: 1, title: 'VPN Tunnel', icon: 'vpn_lock' },
  { num: 2, title: 'NAS Details', icon: 'dns' },
  { num: 3, title: 'Connect', icon: 'rocket_launch' },
]

const step = ref(1)
const connecting = ref(false)
const provisionSteps = ref([])
const provisionOk = ref(null) // null = not run yet
const createdConnectionId = ref(null)
const createdVpn = ref(false)

const vpn = ref({
  choice: 'none', // 'none' | 'existing' | 'import'
  existingName: '',
  importName: '',
  importContent: '',
  importFileName: '',
})

const nas = ref({
  name: '',
  nfs_server: '',
  nfs_path: '',
  mount_point: '/mnt/nas-drive',
  // Runtime mount options. Soft mounts so a dead NAS can never hang the
  // server; nas.persist adds _netdev/nofail/automount for the fstab entry.
  nfs_options: 'rw,soft,timeo=10,retrans=3',
  set_default: true,
  notes: '',
})

const selectedVpnStatus = computed(() => {
  const found = props.vpnConnections.find(v => v.name === vpn.value.existingName)
  return found ? found.status : null
})

const effectiveVpnName = computed(() => {
  if (vpn.value.choice === 'existing') return vpn.value.existingName
  if (vpn.value.choice === 'import') {
    return vpn.value.importName.replace(/[^a-zA-Z0-9_-]/g, '')
  }
  return null
})

const canGoNext = computed(() => {
  if (step.value === 1) {
    if (vpn.value.choice === 'existing') return !!vpn.value.existingName
    if (vpn.value.choice === 'import') return !!effectiveVpnName.value && !!vpn.value.importContent
    return true
  }
  if (step.value === 2) {
    const n = nas.value
    return !!(n.name.trim() && n.nfs_server.trim() && n.nfs_path.trim() && n.mount_point.trim())
  }
  return false
})

const handleOvpnFile = (event) => {
  const file = event.target.files[0]
  if (!file) return
  const reader = new FileReader()
  reader.onload = (e) => {
    vpn.value.importContent = e.target.result
    vpn.value.importFileName = file.name
    if (!vpn.value.importName) {
      vpn.value.importName = file.name.replace(/\.(ovpn|conf)$/i, '').replace(/[^a-zA-Z0-9_-]/g, '')
    }
  }
  reader.readAsText(file)
  event.target.value = ''
}

const goNext = () => {
  if (!canGoNext.value) return
  step.value++
  if (step.value === 3) connect()
}

const goBack = () => {
  if (connecting.value) return
  if (step.value > 1) step.value--
}

const connect = async () => {
  connecting.value = true
  provisionOk.value = null
  provisionSteps.value = []
  try {
    const vpnName = effectiveVpnName.value

    // 1. Create the VPN config when importing (idempotent across retries).
    if (vpn.value.choice === 'import' && !createdVpn.value) {
      try {
        const res = await api.post('/vpn', {
          name: vpnName,
          config_content: vpn.value.importContent,
        })
        if (!res.data.success) throw new Error(res.data.error || 'Failed to create VPN')
        createdVpn.value = true
      } catch (e) {
        if ((e.message || '').toLowerCase().includes('already exists')) {
          createdVpn.value = true
        } else {
          throw e
        }
      }
    }

    // 2. Create the NAS connection record (reused on retry).
    if (!createdConnectionId.value) {
      const res = await api.post('/nas', {
        name: nas.value.name.trim(),
        driver: 'nfs',
        mount_point: nas.value.mount_point.trim(),
        nfs_server: nas.value.nfs_server.trim(),
        nfs_path: nas.value.nfs_path.trim(),
        nfs_options: nas.value.nfs_options.trim(),
        vpn_enabled: !!vpnName,
        vpn_config_path: vpnName ? `/etc/openvpn/client/${vpnName}.conf` : null,
        // The default switch happens inside provision, only after success.
        is_default: false,
        notes: nas.value.notes,
      })
      if (!res.data.success) throw new Error(res.data.error || 'Failed to create connection')
      createdConnectionId.value = res.data.data.id
    }

    // 3. Run the server-side provisioning sequence. Can take minutes when
    //    packages need installing, so give it a generous timeout.
    const res = await api.post(
      `/nas/${createdConnectionId.value}/provision`,
      { set_default: nas.value.set_default },
      { timeout: 600000 }
    )
    provisionSteps.value = res.data.data?.steps || []
    provisionOk.value = !!res.data.data?.ok

    if (provisionOk.value) {
      toast.success('NAS connected and ready to use')
      emit('completed')
    }
  } catch (e) {
    provisionOk.value = false
    if (provisionSteps.value.length === 0) {
      provisionSteps.value = [{
        step: 'setup',
        label: 'Setup',
        status: 'error',
        message: e.message || 'Setup failed',
      }]
    }
    toast.error(e.message || 'NAS setup failed')
  } finally {
    connecting.value = false
  }
}

const stepIcon = (status) => {
  switch (status) {
    case 'ok': return 'check_circle'
    case 'error': return 'cancel'
    case 'skipped': return 'remove_circle'
    default: return 'pending'
  }
}

const stepIconClass = (status) => {
  switch (status) {
    case 'ok': return 'text-green-600 dark:text-green-400'
    case 'error': return 'text-red-600 dark:text-red-400'
    case 'skipped': return 'text-surface-400'
    default: return 'text-surface-400'
  }
}

const reset = () => {
  step.value = 1
  connecting.value = false
  provisionSteps.value = []
  provisionOk.value = null
  createdConnectionId.value = null
  createdVpn.value = false
  vpn.value = { choice: 'none', existingName: '', importName: '', importContent: '', importFileName: '' }
  nas.value = {
    name: '',
    nfs_server: '',
    nfs_path: '',
    mount_point: '/mnt/nas-drive',
    nfs_options: 'rw,soft,timeo=10,retrans=3',
    set_default: true,
    notes: '',
  }
}

const close = () => {
  if (connecting.value) return
  // A created-but-failed connection stays in the list so the operator can
  // retry from the card; just refresh the parent.
  if (createdConnectionId.value && !provisionOk.value) emit('completed')
  reset()
  emit('close')
}

watch(() => props.show, (show) => {
  if (show) reset()
})
</script>

<template>
  <Modal :show="show" title="Connect NAS" size="lg" @close="close">
    <!-- Progress indicator -->
    <div class="flex items-center gap-2 mb-6">
      <template v-for="(s, i) in wizardSteps" :key="s.num">
        <div class="flex items-center gap-2">
          <div
            :class="[
              'w-8 h-8 rounded-full flex items-center justify-center text-sm font-semibold transition-colors',
              step > s.num ? 'bg-green-500 text-white'
                : step === s.num ? 'bg-primary-500 text-white'
                : 'bg-surface-200 dark:bg-surface-700 text-surface-500'
            ]"
          >
            <span v-if="step > s.num" class="material-symbols-rounded text-lg">check</span>
            <span v-else>{{ s.num }}</span>
          </div>
          <span :class="['text-sm font-medium', step === s.num ? '' : 'text-surface-500']">{{ s.title }}</span>
        </div>
        <div v-if="i < wizardSteps.length - 1" class="flex-1 h-px bg-surface-200 dark:bg-surface-700"></div>
      </template>
    </div>

    <!-- Step 1: VPN -->
    <div v-if="step === 1" class="space-y-3">
      <p class="text-sm text-surface-500">
        How does this server reach the NAS? If the NAS is on a remote network (e.g. a Synology at
        home/office), a VPN tunnel is required. The wizard starts it automatically.
      </p>

      <label
        v-for="opt in [
          { value: 'none', icon: 'lan', title: 'No VPN needed', desc: 'The NAS is directly reachable from this server' },
          { value: 'existing', icon: 'vpn_key', title: 'Use existing VPN', desc: 'Reuse a tunnel already configured on this server' },
          { value: 'import', icon: 'upload_file', title: 'Import .ovpn file', desc: 'Set up a new OpenVPN tunnel from a config file' },
        ]"
        :key="opt.value"
        :class="[
          'flex items-start gap-3 p-4 rounded-xl border cursor-pointer transition-colors',
          vpn.choice === opt.value
            ? 'border-primary-500 bg-primary-50 dark:bg-primary-500/10'
            : 'border-surface-200 dark:border-surface-700 hover:border-surface-300 dark:hover:border-surface-600'
        ]"
      >
        <input type="radio" v-model="vpn.choice" :value="opt.value" class="sr-only" />
        <span class="material-symbols-rounded text-primary-500 mt-0.5">{{ opt.icon }}</span>
        <div>
          <span class="font-medium">{{ opt.title }}</span>
          <p class="text-xs text-surface-500">{{ opt.desc }}</p>
        </div>
      </label>

      <div v-if="vpn.choice === 'existing'" class="p-4 bg-surface-50 dark:bg-surface-800 rounded-xl">
        <label class="block text-sm font-medium mb-2">VPN Connection</label>
        <select v-model="vpn.existingName" class="input">
          <option value="">Select VPN...</option>
          <option v-for="v in vpnConnections" :key="v.name" :value="v.name">
            {{ v.name }} ({{ v.status }})
          </option>
        </select>
        <p v-if="vpnConnections.length === 0" class="text-xs text-amber-600 mt-2">
          No VPN connections exist yet - pick "Import .ovpn file" instead.
        </p>
        <p v-else-if="vpn.existingName && selectedVpnStatus !== 'connected'" class="text-xs text-surface-500 mt-2">
          This tunnel is currently {{ selectedVpnStatus || 'unknown' }} - the wizard will start it for you.
        </p>
      </div>

      <div v-if="vpn.choice === 'import'" class="p-4 bg-surface-50 dark:bg-surface-800 rounded-xl space-y-4">
        <div>
          <label class="block text-sm font-medium mb-2">VPN Name</label>
          <input v-model="vpn.importName" type="text" class="input" placeholder="synology" />
          <p class="text-xs text-surface-500 mt-1">Letters, numbers, dash and underscore only</p>
        </div>
        <div>
          <label class="block text-sm font-medium mb-2">OpenVPN Config (.ovpn)</label>
          <label class="btn-secondary cursor-pointer inline-flex items-center gap-2">
            <span class="material-symbols-rounded">upload_file</span>
            {{ vpn.importFileName || 'Choose file...' }}
            <input type="file" accept=".ovpn,.conf,.txt" class="hidden" @change="handleOvpnFile" />
          </label>
          <p v-if="vpn.importContent" class="text-xs text-green-600 mt-2 flex items-center gap-1">
            <span class="material-symbols-rounded text-sm">check_circle</span>
            Config loaded ({{ vpn.importContent.length }} chars, certificates included inline)
          </p>
        </div>
      </div>
    </div>

    <!-- Step 2: NAS details -->
    <div v-if="step === 2" class="space-y-4">
      <div class="grid grid-cols-2 gap-4">
        <div class="col-span-2 sm:col-span-1">
          <label class="block text-sm font-medium mb-2">Name</label>
          <input v-model="nas.name" type="text" class="input" placeholder="Synology NAS" required />
        </div>
        <div class="col-span-2 sm:col-span-1">
          <label class="block text-sm font-medium mb-2">Mount Point</label>
          <input v-model="nas.mount_point" type="text" class="input" placeholder="/mnt/nas-drive" required />
        </div>
      </div>

      <div class="grid grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-medium mb-2">NAS IP Address</label>
          <input v-model="nas.nfs_server" type="text" class="input" placeholder="192.168.1.106" required />
          <p class="text-xs text-surface-500 mt-1">{{ effectiveVpnName ? 'The LAN IP reachable through the VPN tunnel' : 'IP or hostname of the NAS' }}</p>
        </div>
        <div>
          <label class="block text-sm font-medium mb-2">NFS Export Path</label>
          <input v-model="nas.nfs_path" type="text" class="input" placeholder="/volume1/flowone-drive" required />
          <p class="text-xs text-surface-500 mt-1">The shared folder path exported by the NAS</p>
        </div>
      </div>

      <div>
        <label class="block text-sm font-medium mb-2">Mount Options</label>
        <input v-model="nas.nfs_options" type="text" class="input" />
        <p class="text-xs text-surface-500 mt-1">
          Soft-mount defaults: a NAS outage can never freeze the server. Boot-safety options are added to fstab automatically.
        </p>
      </div>

      <div class="p-4 bg-surface-50 dark:bg-surface-800 rounded-xl">
        <label class="flex items-center gap-3 cursor-pointer">
          <div class="relative">
            <input type="checkbox" v-model="nas.set_default" class="sr-only peer" />
            <div class="w-11 h-6 bg-surface-300 dark:bg-surface-600 rounded-full peer peer-checked:bg-primary-500 transition-colors"></div>
            <div class="absolute left-0.5 top-0.5 w-5 h-5 bg-white rounded-full shadow peer-checked:translate-x-5 transition-transform"></div>
          </div>
          <div>
            <span class="font-medium">Set as default storage</span>
            <p class="text-xs text-surface-500">New Drive files go to this NAS. Apps pick the change up within ~5 minutes. Only applied if every step succeeds.</p>
          </div>
        </label>
      </div>
    </div>

    <!-- Step 3: Connect (live checklist) -->
    <div v-if="step === 3" class="space-y-3">
      <div v-if="connecting" class="flex items-center gap-3 p-4 bg-primary-50 dark:bg-primary-500/10 rounded-xl">
        <span class="spinner"></span>
        <div>
          <span class="font-medium">Connecting your NAS...</span>
          <p class="text-xs text-surface-500">Running preflight, VPN, mount, write test and persistence. This can take a few minutes if packages need installing.</p>
        </div>
      </div>

      <div
        v-else-if="provisionOk === true"
        class="flex items-center gap-3 p-4 bg-green-50 dark:bg-green-500/10 rounded-xl"
      >
        <span class="material-symbols-rounded text-green-600 dark:text-green-400 text-3xl">task_alt</span>
        <div>
          <span class="font-medium text-green-700 dark:text-green-300">NAS connected and ready</span>
          <p class="text-xs text-green-600 dark:text-green-400">Mounted, verified writable, and persisted across reboots.</p>
        </div>
      </div>

      <div
        v-else-if="provisionOk === false"
        class="flex items-center gap-3 p-4 bg-red-50 dark:bg-red-500/10 rounded-xl"
      >
        <span class="material-symbols-rounded text-red-600 dark:text-red-400 text-3xl">error</span>
        <div>
          <span class="font-medium text-red-700 dark:text-red-300">Setup did not complete</span>
          <p class="text-xs text-red-600 dark:text-red-400">Fix the failed step below and hit Retry - completed steps are skipped automatically.</p>
        </div>
      </div>

      <div class="space-y-2">
        <div
          v-for="s in provisionSteps"
          :key="s.step"
          class="flex items-start gap-3 p-3 rounded-xl border border-surface-200 dark:border-surface-700"
        >
          <span :class="['material-symbols-rounded mt-0.5', stepIconClass(s.status)]">{{ stepIcon(s.status) }}</span>
          <div class="min-w-0 flex-1">
            <div class="flex items-center justify-between gap-2">
              <span class="font-medium text-sm">{{ s.label }}</span>
              <span :class="['text-xs uppercase font-semibold', stepIconClass(s.status)]">{{ s.status }}</span>
            </div>
            <p class="text-xs text-surface-500 mt-0.5">{{ s.message }}</p>
            <p v-if="s.fix_hint && s.status === 'error'" class="text-xs text-amber-600 dark:text-amber-400 mt-1">
              <span class="material-symbols-rounded text-sm align-middle mr-1">lightbulb</span>{{ s.fix_hint }}
            </p>
            <pre
              v-if="s.recent_logs && s.status === 'error'"
              class="text-xs bg-surface-900 text-surface-200 rounded-lg p-2 mt-2 overflow-x-auto max-h-32"
            >{{ s.recent_logs }}</pre>
          </div>
        </div>
      </div>
    </div>

    <!-- Footer buttons -->
    <div class="flex justify-between gap-3 pt-6">
      <button
        v-if="step > 1 && provisionOk !== true"
        type="button"
        class="btn-secondary"
        :disabled="connecting"
        @click="goBack"
      >
        Back
      </button>
      <div v-else></div>

      <div class="flex gap-3">
        <button v-if="step < 3" type="button" class="btn-primary" :disabled="!canGoNext" @click="goNext">
          {{ step === 2 ? 'Connect NAS' : 'Next' }}
          <span class="material-symbols-rounded">{{ step === 2 ? 'rocket_launch' : 'arrow_forward' }}</span>
        </button>
        <button
          v-if="step === 3 && provisionOk === false"
          type="button"
          class="btn-primary"
          :disabled="connecting"
          @click="connect"
        >
          <span class="material-symbols-rounded">refresh</span>
          Retry
        </button>
        <button
          v-if="step === 3 && provisionOk === true"
          type="button"
          class="btn-primary"
          @click="close"
        >
          Done
        </button>
      </div>
    </div>
  </Modal>
</template>
