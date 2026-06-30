<?php

namespace VpsAdmin\Api\Controllers;

use VpsAdmin\Api\Core\Request;
use VpsAdmin\Api\Core\Response;

class DnsController extends BaseController
{
    public function status(Request $request): Response
    {
        return $this->agentAction('dns.status');
    }

    /**
     * Get DNS server stats (nameservers, zone count, last sync, etc.)
     */
    public function stats(Request $request): Response
    {
        return $this->agentAction('dns.stats');
    }

    /**
     * Sync all DNS zones to slave nameservers
     */
    public function syncAll(Request $request): Response
    {
        $result = $this->agent->execute('dns.syncAll', [], $this->getActor());

        if ($result['success']) {
            $this->cache->delete('dns:zones');
            $this->logAction('dns.sync_all', 'all_zones', 'success', $result['data']);
        }

        return $result['success'] 
            ? Response::success($result['data'], $result['message'] ?? 'All zones synced')
            : Response::error($result['error']);
    }

    public function zones(Request $request): Response
    {
        $forceRefresh = $request->getQuery('refresh') === '1';
        $cacheKey = 'dns:zones';
        
        if (!$forceRefresh) {
            $cached = $this->cache->get($cacheKey);
            if ($cached !== null) {
                return Response::success($cached, 'Success');
            }
        }
        
        $result = $this->agent->execute('dns.zones', [], $this->getActor());
        
        if ($result['success']) {
            $this->cache->set($cacheKey, $result['data']);
            return Response::success($result['data'], $result['message'] ?? 'Success');
        }
        
        return Response::error($result['error'] ?? 'Failed to get zones');
    }

    public function zone(Request $request): Response
    {
        $name = $request->getParam('name');
        $forceRefresh = $request->getQuery('refresh') === '1';
        $cacheKey = "dns:{$name}";
        
        if (!$forceRefresh) {
            $cached = $this->cache->get($cacheKey);
            if ($cached !== null) {
                return Response::success($cached, 'Success');
            }
        }
        
        $result = $this->agent->execute('dns.zone', ['name' => $name], $this->getActor());
        
        if ($result['success']) {
            $this->cache->set($cacheKey, $result['data']);
            return Response::success($result['data'], $result['message'] ?? 'Success');
        }
        
        return Response::error($result['error'] ?? 'Failed to get zone');
    }

    public function createZone(Request $request): Response
    {
        $validation = $this->validateRequired($request, ['name']);
        if ($validation) return $validation;

        $zoneName = $request->input('name');
        
        $result = $this->agent->execute('dns.createZone', [
            'name' => $zoneName,
            'type' => $request->input('type', 'NATIVE'),
            'master' => $request->input('master'),
            'ns1' => $request->input('ns1'),
            'ns2' => $request->input('ns2'),
            'admin' => $request->input('admin'),
            'ip' => $request->input('ip'),
        ], $this->getActor());

        if ($result['success']) {
            // Invalidate zones list cache
            $this->cache->delete('dns:zones');
            $this->logAction('dns.create_zone', $zoneName, 'success');
        }

        return $result['success'] 
            ? Response::success($result['data'], $result['message'] ?? 'Zone created')
            : Response::error($result['error']);
    }

    public function deleteZone(Request $request): Response
    {
        $name = $request->getParam('name');
        
        $result = $this->agent->execute('dns.deleteZone', [
            'name' => $name,
        ], $this->getActor());

        if ($result['success']) {
            // Invalidate caches
            $this->cache->delete('dns:zones');
            $this->cache->invalidateDns($name);
            $this->logAction('dns.delete_zone', $name, 'success');
        }

        return $result['success'] 
            ? Response::success($result['data'], $result['message'] ?? 'Zone deleted')
            : Response::error($result['error']);
    }

    public function records(Request $request): Response
    {
        $zone = $request->getParam('name');
        return $this->agentAction('dns.records', [
            'zone' => $zone,
            'type' => $request->getQuery('type'),
        ]);
    }

    public function addRecord(Request $request): Response
    {
        $validation = $this->validateRequired($request, ['zone', 'name', 'type', 'content']);
        if ($validation) return $validation;

        $zone = $request->input('zone');
        
        $result = $this->agent->execute('dns.addRecord', [
            'zone' => $zone,
            'name' => $request->input('name'),
            'type' => $request->input('type'),
            'content' => $request->input('content'),
            'ttl' => $request->input('ttl', 3600),
            'prio' => $request->input('prio', 0),
        ], $this->getActor());

        $target = $request->input('type') . ':' . $request->input('name');
        
        if ($result['success']) {
            // Invalidate zone cache
            $this->cache->invalidateDns($zone);
            $this->logAction('dns.add_record', $target, 'success', ['zone' => $zone]);
        }

        return $result['success'] 
            ? Response::success($result['data'], $result['message'] ?? 'Record added')
            : Response::error($result['error']);
    }

    public function updateRecord(Request $request): Response
    {
        $id = (int)$request->getParam('id');
        $zone = $request->input('zone');
        
        $result = $this->agent->execute('dns.updateRecord', [
            'id' => $id,
            'content' => $request->input('content'),
            'ttl' => $request->input('ttl'),
            'prio' => $request->input('prio'),
            'disabled' => $request->input('disabled'),
        ], $this->getActor());

        if ($result['success']) {
            // Invalidate zone cache if zone was provided
            if ($zone) {
                $this->cache->invalidateDns($zone);
            }
            $this->logAction('dns.update_record', (string)$id, 'success');
        }

        return $result['success'] 
            ? Response::success($result['data'], $result['message'] ?? 'Record updated')
            : Response::error($result['error']);
    }

    public function deleteRecord(Request $request): Response
    {
        $id = (int)$request->getParam('id');
        $zone = $request->input('zone');
        
        $result = $this->agent->execute('dns.deleteRecord', [
            'id' => $id,
        ], $this->getActor());

        if ($result['success']) {
            // Invalidate zone cache if zone was provided
            if ($zone) {
                $this->cache->invalidateDns($zone);
            }
            $this->logAction('dns.delete_record', (string)$id, 'success');
        }

        return $result['success'] 
            ? Response::success($result['data'], $result['message'] ?? 'Record deleted')
            : Response::error($result['error']);
    }

    /**
     * Sync DNS zone to all nameservers
     * Bumps serial and sends NOTIFY to slaves
     */
    public function syncZone(Request $request): Response
    {
        $zone = $request->getParam('name');
        
        $result = $this->agent->execute('dns.syncZone', [
            'zone' => $zone,
        ], $this->getActor());

        if ($result['success']) {
            $this->cache->invalidateDns($zone);
            $this->logAction('dns.sync_zone', $zone, 'success', $result['data']);
        }

        return $result['success'] 
            ? Response::success($result['data'], $result['message'] ?? 'Zone synced')
            : Response::error($result['error']);
    }

    /**
     * Check and fix DNS issues for a zone
     * mode=check: only show issues, don't fix
     * mode=fix: actually fix the issues
     * Fixes: old _domainkey format, wrong SOA nameserver, weak DMARC policy, orphan records
     */
    public function fixIssues(Request $request): Response
    {
        $zone = $request->getParam('name');
        $mode = $request->input('mode', 'check'); // 'check' or 'fix'
        
        $result = $this->agent->execute('dns.fixIssues', [
            'zone' => $zone,
            'mode' => $mode,
        ], $this->getActor());

        if ($result['success']) {
            if ($mode === 'fix' && !empty($result['data']['fixed'])) {
                $this->cache->invalidateDns($zone);
                $this->logAction('dns.fix_issues', $zone, 'success', $result['data']);
            }
        }

        return $result['success'] 
            ? Response::success($result['data'], $result['message'] ?? 'DNS issues checked')
            : Response::error($result['error']);
    }

    /**
     * Get nameserver configuration
     */
    public function getNsConfig(Request $request): Response
    {
        return $this->agentAction('dns.getNsConfig');
    }

    /**
     * Update nameserver configuration
     */
    public function setNsConfig(Request $request): Response
    {
        $validation = $this->validateRequired($request, ['ns1', 'ns2']);
        if ($validation) return $validation;

        $result = $this->agent->execute('dns.setNsConfig', [
            'ns1' => $request->input('ns1'),
            'ns2' => $request->input('ns2'),
        ], $this->getActor());

        if ($result['success']) {
            $this->logAction('dns.set_ns_config', 'ns_config', 'success', $result['data']);
        }

        return $result['success'] 
            ? Response::success($result['data'], $result['message'] ?? 'Nameserver configuration saved')
            : Response::error($result['error']);
    }
}

