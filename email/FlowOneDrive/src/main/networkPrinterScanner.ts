import * as net from 'net'
import * as os from 'os'
import * as snmp from 'net-snmp'

export interface NetworkPrinter {
  ip: string
  port: number
  protocol: 'jetdirect' | 'ipp' | 'lpr'
  name: string
  model: string
  location: string
  mac: string
  status: 'online' | 'unknown'
}

interface ScanOptions {
  subnet?: string
  timeout?: number
  ports?: number[]
}

const PRINTER_PORTS = [
  { port: 9100, protocol: 'jetdirect' as const },
  { port: 631, protocol: 'ipp' as const },
  { port: 515, protocol: 'lpr' as const },
]

const SNMP_OIDS = {
  sysDescr: '1.3.6.1.2.1.1.1.0',
  sysName: '1.3.6.1.2.1.1.5.0',
  sysLocation: '1.3.6.1.2.1.1.6.0',
  hrDeviceDescr: '1.3.6.1.2.1.25.3.2.1.3.1',
  prtGeneralSerialNumber: '1.3.6.1.2.1.43.5.1.1.17.1',
}

function getLocalSubnets(): string[] {
  const subnets: string[] = []
  const interfaces = os.networkInterfaces()

  for (const name of Object.keys(interfaces)) {
    const addrs = interfaces[name]
    if (!addrs) continue
    for (const addr of addrs) {
      if (addr.family === 'IPv4' && !addr.internal) {
        const parts = addr.address.split('.')
        subnets.push(`${parts[0]}.${parts[1]}.${parts[2]}`)
      }
    }
  }
  return [...new Set(subnets)]
}

function getLocalIPs(): Set<string> {
  const ips = new Set<string>()
  const interfaces = os.networkInterfaces()
  for (const addrs of Object.values(interfaces)) {
    if (!addrs) continue
    for (const a of addrs) {
      if (a.family === 'IPv4') ips.add(a.address)
    }
  }
  return ips
}

function tcpProbe(ip: string, port: number, timeoutMs: number): Promise<boolean> {
  return new Promise((resolve) => {
    const sock = new net.Socket()
    let settled = false

    const done = (result: boolean) => {
      if (settled) return
      settled = true
      sock.destroy()
      resolve(result)
    }

    sock.setTimeout(timeoutMs)
    sock.once('connect', () => done(true))
    sock.once('timeout', () => done(false))
    sock.once('error', () => done(false))
    sock.connect(port, ip)
  })
}

function snmpGet(ip: string, oids: string[], timeoutMs = 3000): Promise<Record<string, string>> {
  return new Promise((resolve) => {
    const result: Record<string, string> = {}
    let session: any

    const timer = setTimeout(() => {
      try { session?.close() } catch { /* ignore */ }
      resolve(result)
    }, timeoutMs)

    try {
      session = snmp.createSession(ip, 'public', { timeout: timeoutMs, retries: 0 })

      session.get(oids, (error: any, varbinds: any[]) => {
        clearTimeout(timer)
        if (!error && varbinds) {
          for (const vb of varbinds) {
            if (snmp.isVarbindError(vb)) continue
            const val = vb.value?.toString?.() || ''
            if (val && val !== 'noSuchObject' && val !== 'noSuchInstance') {
              result[vb.oid] = val
            }
          }
        }
        try { session.close() } catch { /* ignore */ }
        resolve(result)
      })
    } catch {
      clearTimeout(timer)
      resolve(result)
    }
  })
}

async function getArpTable(): Promise<Map<string, string>> {
  const map = new Map<string, string>()
  try {
    const { execSync } = await import('child_process')
    const output = execSync('arp -a', { encoding: 'utf8', timeout: 5000 })
    const lines = output.split('\n')
    for (const line of lines) {
      const match = line.match(/(\d+\.\d+\.\d+\.\d+)\s+([\da-f-]+)/i)
      if (match) {
        const ip = match[1]
        const mac = match[2].replace(/-/g, ':').toLowerCase()
        if (mac !== 'ff:ff:ff:ff:ff:ff') {
          map.set(ip, mac)
        }
      }
    }
  } catch { /* ARP not available */ }
  return map
}

export async function scanNetworkPrinters(
  options: ScanOptions = {},
  onProgress?: (scanned: number, total: number) => void
): Promise<NetworkPrinter[]> {
  const timeoutMs = options.timeout || 800
  const subnets = options.subnet ? [options.subnet] : getLocalSubnets()
  const localIPs = getLocalIPs()

  if (subnets.length === 0) {
    console.log('[NetworkScanner] No local subnets detected')
    return []
  }

  console.log(`[NetworkScanner] Scanning subnets: ${subnets.join(', ')}`)

  const arpTable = await getArpTable()
  console.log(`[NetworkScanner] ARP table has ${arpTable.size} entries`)

  // Build list of IPs to scan -- prioritize ARP-known hosts, then fill the rest
  const ipsToScan: string[] = []
  const knownIPs = new Set<string>()

  for (const [ip] of arpTable) {
    for (const subnet of subnets) {
      if (ip.startsWith(subnet + '.') && !localIPs.has(ip)) {
        ipsToScan.push(ip)
        knownIPs.add(ip)
      }
    }
  }

  // Also scan the full /24 for any hosts not in ARP
  for (const subnet of subnets) {
    for (let i = 1; i <= 254; i++) {
      const ip = `${subnet}.${i}`
      if (!knownIPs.has(ip) && !localIPs.has(ip)) {
        ipsToScan.push(ip)
      }
    }
  }

  const total = ipsToScan.length
  let scanned = 0
  const results: NetworkPrinter[] = []

  // Scan in batches of 50 for performance
  const BATCH_SIZE = 50
  for (let i = 0; i < ipsToScan.length; i += BATCH_SIZE) {
    const batch = ipsToScan.slice(i, i + BATCH_SIZE)
    const batchPromises = batch.map(async (ip) => {
      for (const { port, protocol } of PRINTER_PORTS) {
        const open = await tcpProbe(ip, port, timeoutMs)
        if (open) {
          return { ip, port, protocol }
        }
      }
      return null
    })

    const batchResults = await Promise.all(batchPromises)
    for (const hit of batchResults) {
      if (hit) {
        results.push({
          ip: hit.ip,
          port: hit.port,
          protocol: hit.protocol,
          name: '',
          model: '',
          location: '',
          mac: arpTable.get(hit.ip) || '',
          status: 'online',
        })
      }
    }

    scanned += batch.length
    onProgress?.(Math.min(scanned, total), total)
  }

  console.log(`[NetworkScanner] Found ${results.length} devices with printer ports open`)

  // SNMP enrich: query discovered printers for name/model in parallel
  if (results.length > 0) {
    const enrichPromises = results.map(async (printer) => {
      const oids = [
        SNMP_OIDS.sysName,
        SNMP_OIDS.sysDescr,
        SNMP_OIDS.sysLocation,
        SNMP_OIDS.hrDeviceDescr,
      ]
      const info = await snmpGet(printer.ip, oids, 3000)

      printer.name = info[SNMP_OIDS.hrDeviceDescr]
        || info[SNMP_OIDS.sysName]
        || ''

      printer.model = info[SNMP_OIDS.sysDescr] || ''
      printer.location = info[SNMP_OIDS.sysLocation] || ''

      if (!printer.name && printer.model) {
        printer.name = printer.model.split('\n')[0].substring(0, 80)
      }
      if (!printer.name) {
        printer.name = `Printer @ ${printer.ip}`
      }
    })

    await Promise.all(enrichPromises)
  }

  return results
}
