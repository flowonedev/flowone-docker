<?php

namespace VpsAdmin\Api\Controllers;

use VpsAdmin\Api\Core\Request;
use VpsAdmin\Api\Core\Response;

/**
 * Cache management controller
 * 
 * Provides endpoints to monitor and manage the Redis cache
 */
class CacheController extends BaseController
{
    /**
     * Get cache statistics
     */
    public function stats(Request $request): Response
    {
        // Only super_admin can view cache stats
        $roleCheck = $this->requireSuperAdmin();
        if ($roleCheck) return $roleCheck;
        
        $stats = $this->cache->getStats();
        
        return Response::success($stats, 'Cache statistics');
    }

    /**
     * Flush all cache
     */
    public function flush(Request $request): Response
    {
        // Only super_admin can flush cache
        $roleCheck = $this->requireSuperAdmin();
        if ($roleCheck) return $roleCheck;
        
        $deleted = $this->cache->flush();
        
        $this->logAction('cache.flush', 'all', 'success', [
            'keys_deleted' => $deleted,
        ]);
        
        return Response::success([
            'deleted' => $deleted,
        ], "Flushed {$deleted} cache keys");
    }

    /**
     * Invalidate cache for a specific domain
     */
    public function invalidateDomain(Request $request): Response
    {
        // Only super_admin can invalidate cache
        $roleCheck = $this->requireSuperAdmin();
        if ($roleCheck) return $roleCheck;
        
        $domain = $request->getParam('domain');
        
        if (!$domain) {
            return Response::error('Domain is required');
        }
        
        $deleted = $this->cache->invalidateForDomain($domain);
        
        $this->logAction('cache.invalidate', $domain, 'success', [
            'keys_deleted' => $deleted,
        ]);
        
        return Response::success([
            'domain' => $domain,
            'deleted' => $deleted,
        ], "Invalidated cache for {$domain}");
    }

    /**
     * Invalidate specific cache types
     */
    public function invalidateType(Request $request): Response
    {
        // Only super_admin can invalidate cache
        $roleCheck = $this->requireSuperAdmin();
        if ($roleCheck) return $roleCheck;
        
        $type = $request->getParam('type');
        
        $deleted = 0;
        
        switch ($type) {
            case 'sites':
                $deleted = $this->cache->deletePattern('site*') + $this->cache->delete('sites:list');
                break;
            case 'dns':
                $deleted = $this->cache->deletePattern('dns:*');
                break;
            case 'mail':
                $deleted = $this->cache->deletePattern('mail:*');
                break;
            case 'files':
                $deleted = $this->cache->deletePattern('files:*');
                break;
            case 'backups':
                $deleted = $this->cache->deletePattern('backups:*');
                break;
            case 'db':
                $deleted = $this->cache->deletePattern('db:*');
                break;
            default:
                return Response::error("Unknown cache type: {$type}. Valid types: sites, dns, mail, files, backups, db");
        }
        
        $this->logAction('cache.invalidate_type', $type, 'success', [
            'keys_deleted' => $deleted,
        ]);
        
        return Response::success([
            'type' => $type,
            'deleted' => $deleted,
        ], "Invalidated {$type} cache");
    }
}

