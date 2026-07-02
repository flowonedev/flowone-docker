<script setup>
/**
 * Per-server provisioning policy: authoritative nameservers + NAS opt-in.
 *
 * Empty nameservers = the client has none: zones are seeded WITHOUT NS
 * records and the operator's own nameservers are never pushed to the box.
 * NAS is opt-in per server; when off, the box's storage monitors report
 * "not configured" instead of probing the operator's NAS.
 *
 * Save writes the server row (used on the next provision). Save & Apply
 * additionally SSHes to the box and rewrites the two policy files
 * (.dns_ns_config.json + /etc/flowone/storage.local.php) immediately.
 */
import { ref, watch, computed } from 'vue'
import api from '../services/api'
import { useToastStore } from '../stores/toast'

const props = defineProps({
  server: { type: Object, required: true },
})
const emit = defineEmits(['saved'])

const toast = useToastStore()

const form = ref({
  ns1_domain: '',
  ns2_domain: '',
  nas_enabled: false,
  nas_ip: '',
  nas_path: '',
  nas_mount: '/mnt/nas-drive',
  vpn_enabled: false,
})

const saving = ref(false)
const applying = ref(false)

const syncFromServer = () => {
  form.value = {
    ns1_domain: props.server?.ns1_domain || '',
    ns2_domain: props.server?.ns2_domain || '',
    nas_enabled: !!Number(props.server?.nas_enabled ?? 0),
    nas_ip: props.server?.nas_ip || '',
    nas_path: props.server?.nas_path || '',
    nas_mount: props.server?.nas_mount || '/mnt/nas-drive',
    vpn_enabled: !!Number(props.server?.vpn_enabled ?? 0),
  }
}
watch(() => props.server, syncFromServer, { immediate: true })

const dirty = computed(() => {
  const s = props.server || {}
  return form.value.ns1_domain !== (s.ns1_domain || '')
    || form.value.ns2_domain !== (s.ns2_domain || '')
    || form.value.nas_enabled !== !!Number(s.nas_enabled ?? 0)
    || form.value.nas_ip !== (s.nas_ip || '')
    || form.value.nas_path !== (s.nas_path || '')
    || form.value.nas_mount !== (s.nas_mount || '/mnt/nas-drive')
    || form.value.vpn_enabled !== !!Number(s.vpn_enabled ?? 0)
})

const validate = () => {
  const host = /^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/i
  if (form.value.ns1_domain && !host.test(form.value.ns1_domain.trim())) {
    toast.error('Primary nameserver is not a valid hostname')
    return false
  }
  if (form.value.ns2_domain && !host.test(form.value.ns2_domain.trim())) {
    toast.error('Secondary nameserver is not a valid hostname')
    return false
  }
  if (form.value.ns2_domain && !form.value.ns1_domain) {
    toast.error('Set the primary nameserver first (NS2 without NS1 is invalid)')
    return false
  }
  if (form.value.nas_enabled && !form.value.nas_ip.trim()) {
    toast.error('NAS is enabled but no NAS IP is set')
    return false
  }
  return true
}

const save = async () => {
  if (!validate()) return false
  saving.value = true
  try {
    await api.put(`/api/servers/${props.server.id}`, {
      ns1_domain: form.value.ns1_domain.trim(),
      ns2_domain: form.value.ns2_domain.trim(),
      nas_enabled: form.value.nas_enabled ? 1 : 0,
      nas_ip: form.value.nas_ip.trim(),
      nas_path: form.value.nas_path.trim(),
      nas_mount: form.value.nas_mount.trim() || '/mnt/nas-drive',
      vpn_enabled: form.value.vpn_enabled ? 1 : 0,
    })
    toast.success('Provisioning settings saved')
    emit('saved')
    return true
  } catch (e) {
    toast.error(e.response?.data?.message || 'Failed to save settings')
    return false
  } finally {
    saving.value = false
  }
}

const saveAndApply = async () => {
  const ok = await save()
  if (!ok) return
  applying.value = true
  try {
    await api.post(`/api/servers/${props.server.id}/apply-settings`)
    toast.success('Settings applied on the server (no redeploy needed)')
  } catch (e) {
    toast.error(e.response?.data?.message || 'Saved, but applying on the server failed - is it reachable over SSH?')
  } finally {
    applying.value = false
  }
}
</script>

<template>
  <div class="card">
    <div class="card-header flex items-center justify-between">
      <h2 class="font-semibold text-surface-900 dark:text-surface-100 flex items-center gap-2">
        <span class="material-symbols-rounded text-teal-500">dns</span>
        Nameservers &amp; NAS
        <span
          v-if="dirty"
          class="inline-flex items-center px-1.5 py-0.5 rounded text-[11px] font-semibold bg-amber-500/10 text-amber-500"
        >Unsaved</span>
      </h2>
    </div>

    <div class="card-body space-y-4">
      <!-- Nameservers -->
      <div class="space-y-2">
        <p class="text-xs text-surface-500 dark:text-surface-400">
          The client's own authoritative nameservers. Leave empty if they have none:
          DNS zones are then created <span class="font-medium">without NS records</span>
          and no operator nameservers are ever pushed to this box.
        </p>
        <div>
          <label class="text-xs font-medium text-surface-600 dark:text-surface-400">Primary (NS1)</label>
          <input
            v-model="form.ns1_domain"
            type="text"
            class="input w-full text-sm font-mono mt-1"
            placeholder="ns1.client-domain.com (empty = none)"
            autocomplete="off"
          />
        </div>
        <div>
          <label class="text-xs font-medium text-surface-600 dark:text-surface-400">Secondary (NS2)</label>
          <input
            v-model="form.ns2_domain"
            type="text"
            class="input w-full text-sm font-mono mt-1"
            placeholder="ns2.client-domain.com"
            autocomplete="off"
          />
        </div>
      </div>

      <!-- NAS opt-in -->
      <div class="space-y-2 pt-3 border-t border-surface-200 dark:border-surface-700">
        <div class="flex items-center justify-between">
          <span class="text-sm text-surface-700 dark:text-surface-300 flex items-center gap-1.5">
            <span class="material-symbols-rounded text-base text-surface-400">hard_drive</span>
            NAS storage on this server
          </span>
          <label class="toggle-switch">
            <input v-model="form.nas_enabled" type="checkbox" />
            <span class="toggle-slider"></span>
          </label>
        </div>
        <p v-if="!form.nas_enabled" class="text-xs text-surface-500 dark:text-surface-400">
          Off by default - the box reports "no NAS configured" instead of probing anything.
          Enable only when this client has their own NAS.
        </p>
        <template v-if="form.nas_enabled">
          <div>
            <label class="text-xs font-medium text-surface-600 dark:text-surface-400">NAS IP (LAN / VPN tunnel)</label>
            <input v-model="form.nas_ip" type="text" class="input w-full text-sm font-mono mt-1" placeholder="10.8.0.1" />
          </div>
          <div>
            <label class="text-xs font-medium text-surface-600 dark:text-surface-400">NFS export path</label>
            <input v-model="form.nas_path" type="text" class="input w-full text-sm font-mono mt-1" placeholder="/volume1/storage" />
          </div>
          <div>
            <label class="text-xs font-medium text-surface-600 dark:text-surface-400">Mount point on server</label>
            <input v-model="form.nas_mount" type="text" class="input w-full text-sm font-mono mt-1" placeholder="/mnt/nas-drive" />
          </div>
          <div class="flex items-center justify-between">
            <span class="text-sm text-surface-700 dark:text-surface-300">Connect via OpenVPN tunnel</span>
            <label class="toggle-switch">
              <input v-model="form.vpn_enabled" type="checkbox" />
              <span class="toggle-slider"></span>
            </label>
          </div>
        </template>
      </div>

      <!-- Actions -->
      <div class="flex gap-2 pt-3 border-t border-surface-200 dark:border-surface-700">
        <button @click="save" :disabled="saving || applying || !dirty" class="btn btn-secondary btn-sm flex-1">
          <span v-if="saving" class="spinner w-4 h-4"></span>
          <span v-else class="material-symbols-rounded text-base">save</span>
          Save
        </button>
        <button @click="saveAndApply" :disabled="saving || applying" class="btn btn-primary btn-sm flex-1" title="Save and push the policy files to the box over SSH - no redeploy">
          <span v-if="applying" class="spinner w-4 h-4"></span>
          <span v-else class="material-symbols-rounded text-base">publish</span>
          {{ applying ? 'Applying...' : 'Save & Apply' }}
        </button>
      </div>
      <p class="text-[11px] text-surface-400 dark:text-surface-500">
        Save stores the settings for future provisions. Save &amp; Apply also rewrites the
        policy files on the live box immediately (nameserver config + NAS opt-in).
      </p>
    </div>
  </div>
</template>
