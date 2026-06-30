/**
 * NAS Direct Access Module
 * 
 * Provides functionality for desktop clients to access NAS directly
 * when on the same network, bypassing the server for faster file operations.
 */

export { NasDiscovery, getNasDiscovery, type NasConfig, type AccessMode } from './NasDiscovery'
export { AccessModeManager, createAccessModeManager, type ConnectionConfig } from './AccessModeManager'

