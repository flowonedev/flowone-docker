import { EventEmitter } from 'events';
import { getActiveWindow as getActiveWindowNative } from './perf/activeWindow';

interface UrlMapping {
  domain: string;
  boardId?: number;
  board_id?: number;
  clientId?: number;
  client_id?: number;
  cardId?: number | null;
  card_id?: number | null;
  boardName?: string;
  board_name?: string;
  clientName?: string;
  client_name?: string;
  displayName?: string;
  display_name?: string;
  titleMatch?: string;
  title_match?: string;
}

interface BrowserWindow {
  title: string;
  processName: string;
  url?: string;
}

export class BrowserMonitor extends EventEmitter {
  private browserProcesses = ['chrome', 'msedge', 'firefox', 'brave', 'opera', 'iexplore'];
  private urlMappings: Map<string, UrlMapping> = new Map();
  private currentUrl: string | null = null;
  private currentDomain: string | null = null;
  private currentMapping: UrlMapping | null = null;
  private monitorInterval: NodeJS.Timeout | null = null;
  private isMonitoring = false;
  
  constructor() {
    super();
  }
  
  /**
   * Start monitoring browser windows
   */
  start() {
    if (this.isMonitoring) {
      return;
    }
    
    this.isMonitoring = true;
    console.log('[BrowserMonitor] Started monitoring browser windows');
    
    // Check every 2 seconds
    this.monitorInterval = setInterval(() => {
      this.checkActiveBrowser();
    }, 2000);
  }
  
  /**
   * Stop monitoring
   */
  stop() {
    if (!this.isMonitoring) {
      return;
    }
    
    this.isMonitoring = false;
    
    if (this.monitorInterval) {
      clearInterval(this.monitorInterval);
      this.monitorInterval = null;
    }
    
    // Emit blur event if there was an active URL
    if (this.currentUrl && this.currentMapping) {
      this.emit('urlBlur', {
        url: this.currentUrl,
        domain: this.currentDomain,
        mapping: this.currentMapping
      });
      
      this.currentUrl = null;
      this.currentDomain = null;
      this.currentMapping = null;
    }
    
    console.log('[BrowserMonitor] Stopped monitoring');
  }
  
  /**
   * Check if monitoring is active
   */
  isRunning(): boolean {
    return this.isMonitoring;
  }
  
  /**
   * Update URL mappings from backend
   */
  updateMappings(mappings: UrlMapping[]) {
    this.urlMappings.clear();
    
    console.log(`[BrowserMonitor] Received ${mappings.length} mappings from API`);
    
    for (const mapping of mappings) {
      // Normalize: support both snake_case and camelCase from API
      const normalized: UrlMapping = {
        domain: mapping.domain,
        boardId: mapping.boardId || mapping.board_id,
        clientId: mapping.clientId || mapping.client_id,
        cardId: mapping.cardId || mapping.card_id || null,
        boardName: mapping.boardName || mapping.board_name,
        clientName: mapping.clientName || mapping.client_name,
        displayName: mapping.displayName || mapping.display_name,
        titleMatch: mapping.titleMatch || mapping.title_match
      };
      
      // Store by domain (lowercase for case-insensitive matching)
      this.urlMappings.set(normalized.domain.toLowerCase(), normalized);
      const titleMatchInfo = normalized.titleMatch ? `, titleMatch="${normalized.titleMatch}"` : '';
      console.log(`[BrowserMonitor] Added mapping: "${normalized.domain}" -> client="${normalized.clientName}" (boardId=${normalized.boardId}, clientId=${normalized.clientId}${titleMatchInfo})`);
    }
    
    console.log(`[BrowserMonitor] Updated URL mappings: ${this.urlMappings.size} domains tracked`);
    if (this.urlMappings.size > 0) {
      console.log(`[BrowserMonitor] Tracking domains: ${Array.from(this.urlMappings.keys()).join(', ')}`);
    }
  }
  
  /**
   * Check if the active window is a browser with a tracked URL
   */
  private async checkActiveBrowser() {
    try {
      const browserWindow = await this.getActiveBrowserWindow();
      
      if (!browserWindow) {
        // No browser window active
        if (this.currentUrl) {
          // Previous URL is no longer active
          console.log('[BrowserMonitor] Browser no longer active, blurring');
          this.emit('urlBlur', {
            url: this.currentUrl,
            domain: this.currentDomain,
            mapping: this.currentMapping
          });
          
          this.currentUrl = null;
          this.currentDomain = null;
          this.currentMapping = null;
        }
        return;
      }
      
      // Log raw browser window info
      // Wave C.4: sampled — fires every poll while a browser is focused.
      const { logger } = require('./log/Logger')
      logger.tagged('BrowserMonitor').debug(
        `Active window: "${browserWindow.title}" (${browserWindow.processName})` +
        (browserWindow.url ? ` url="${browserWindow.url}"` : '')
      );

      // Wave A.3: get-windows can hand us the real URL on Chromium-family
      // browsers and Safari. Use it directly when present — far more reliable
      // than title scraping (which we keep as a fallback).
      const url = browserWindow.url || this.extractUrlFromTitle(browserWindow.title, browserWindow.processName);
      
      // Log what we detected
      if (url) {
        console.log(`[BrowserMonitor] Extracted URL/domain: ${url}`);
      } else {
        console.log(`[BrowserMonitor] Could not extract URL from title, trying title match...`);
      }
      
      let domain: string | null = null;
      let matchedMapping: UrlMapping | null = null;
      
      if (url) {
        // Extract domain from URL
        domain = this.extractDomain(url);
      }
      
      // If no domain found, try matching title against tracked domains
      if (!domain) {
        const titleMatch = this.findTrackedDomainInTitle(browserWindow.title);
        if (titleMatch) {
          domain = titleMatch.domain;
          matchedMapping = titleMatch.mapping;
          console.log(`[BrowserMonitor] Title contains tracked domain: ${domain}`);
        }
      }
      
      if (!domain) {
        // Could not find any tracked domain
        if (this.currentUrl) {
          this.emit('urlBlur', {
            url: this.currentUrl,
            domain: this.currentDomain,
            mapping: this.currentMapping
          });
          
          this.currentUrl = null;
          this.currentDomain = null;
          this.currentMapping = null;
        }
        return;
      }
      
      // Check if domain matches any mapping (use matchedMapping if already found via title)
      const mapping = matchedMapping || this.urlMappings.get(domain.toLowerCase());
      
      if (!mapping) {
        // Domain not tracked
        if (this.currentUrl) {
          this.emit('urlBlur', {
            url: this.currentUrl,
            domain: this.currentDomain,
            mapping: this.currentMapping
          });
          
          this.currentUrl = null;
          this.currentDomain = null;
          this.currentMapping = null;
        }
        return;
      }
      
      // URL is tracked - use domain as identifier (url might be null for title matches)
      const identifier = url || domain;
      
      if (identifier !== this.currentUrl) {
        // URL/domain changed
        if (this.currentUrl && this.currentMapping) {
          // Emit blur for previous URL
          this.emit('urlBlur', {
            url: this.currentUrl,
            domain: this.currentDomain,
            mapping: this.currentMapping
          });
        }
        
        // Emit focus for new URL/domain
        this.currentUrl = identifier;
        this.currentDomain = domain;
        this.currentMapping = mapping;
        
        console.log(`[BrowserMonitor] TRACKED URL FOCUS: ${domain} (Client: ${mapping.clientName})`);
        
        this.emit('urlFocus', {
          url: identifier,
          domain,
          mapping
        });
      }
      // else: same URL/domain, still focused
      
    } catch (error) {
      console.error('[BrowserMonitor] Error checking active browser:', error);
    }
  }
  
  /**
   * Get the active browser window via the cross-platform `get-windows` adapter.
   *
   * Wave A.3: replaced the per-2s PowerShell spawn with a native binding call.
   * On macOS this also enables browser tracking that previously required
   * a manually written AppleScript path; the native module already handles
   * accessibility-permission gating.
   *
   * If `get-windows` returns a `url` field (Chrome/Brave/Edge/Safari support),
   * we pass it through so the URL extractor can short-circuit title parsing.
   */
  private async getActiveBrowserWindow(): Promise<BrowserWindow | null> {
    const win = await getActiveWindowNative({ failSilently: true });
    if (!win) return null;
    const processName = (win.processName || '').toLowerCase();
    if (!this.browserProcesses.some(browser => processName.includes(browser))) {
      return null;
    }
    return {
      title: win.title || '',
      processName,
      url: win.url,
    };
  }
  
  /**
   * Extract URL from browser window title
   * Browser titles typically show: "Page Title - Browser Name"
   * We need to match against known tracked domains
   */
  private extractUrlFromTitle(title: string, processName: string): string | null {
    if (!title) {
      return null;
    }
    
    const titleLower = title.toLowerCase();
    
    // PRIORITY 1: Check for EXACT full domain match (most specific)
    // This ensures mercedes-benz.ro matches .ro specifically, not .hu
    for (const [domain] of this.urlMappings) {
      const domainLower = domain.toLowerCase();
      if (titleLower.includes(domainLower)) {
        console.log(`[BrowserMonitor] EXACT domain match: "${domain}" in title`);
        return domain;
      }
    }
    
    // PRIORITY 2: Pattern match - Look for URLs in the title (e.g., "https://example.com - Chrome")
    const urlMatch = title.match(/https?:\/\/([^\s]+?)(?:\s|$|-)/);
    if (urlMatch) {
      const extractedDomain = this.extractDomain(urlMatch[1]);
      if (extractedDomain) {
        // Check if this domain is tracked
        const trackedDomain = this.urlMappings.get(extractedDomain.toLowerCase());
        if (trackedDomain) {
          console.log(`[BrowserMonitor] URL pattern match: "${extractedDomain}"`);
          return extractedDomain;
        }
      }
      return urlMatch[1];
    }
    
    // PRIORITY 3: Pattern match - Look for domains in title (e.g., "example.com - Chrome")
    const domainMatch = title.match(/([a-zA-Z0-9][a-zA-Z0-9-_.]+\.[a-zA-Z]{2,})/g);
    if (domainMatch) {
      // Check each found domain against our tracked list
      for (const found of domainMatch) {
        const foundLower = found.toLowerCase().replace(/^www\./, '');
        if (this.urlMappings.has(foundLower)) {
          console.log(`[BrowserMonitor] Domain pattern match: "${foundLower}"`);
          return foundLower;
        }
      }
      // Return first domain found even if not tracked (will be filtered later)
      return domainMatch[0];
    }
    
    // PRIORITY 4 (LAST RESORT): Fuzzy match by domain name without TLD
    // Only if no exact match found - be careful with similar domains
    for (const [domain] of this.urlMappings) {
      // Get base domain name (e.g., "mercedes-benz" from "mercedes-benz.hu")
      const parts = domain.split('.');
      if (parts.length < 2) continue;
      
      // For domains like mercedes-benz.hu, get "mercedes-benz"
      const baseName = parts.slice(0, -1).join('.').toLowerCase();
      
      // Only fuzzy match if base name is reasonably long and unique
      if (baseName.length > 6 && titleLower.includes(baseName)) {
        // IMPORTANT: Check if there are multiple TLDs for this base name
        const sameBases = Array.from(this.urlMappings.keys()).filter(d => 
          d.toLowerCase().startsWith(baseName)
        );
        
        if (sameBases.length === 1) {
          // Only one domain with this base - safe to match
          console.log(`[BrowserMonitor] FUZZY match (single): "${domain}" via "${baseName}"`);
          return domain;
        } else {
          // Multiple domains with same base (e.g., .hu and .ro) - skip fuzzy, need exact
          console.log(`[BrowserMonitor] FUZZY match SKIPPED for "${baseName}" - multiple TLDs exist: ${sameBases.join(', ')}`);
        }
      }
    }
    
    return null;
  }
  
  /**
   * Extract domain from URL
   */
  private extractDomain(urlOrDomain: string): string | null {
    try {
      // Remove protocol if present
      let domain = urlOrDomain.replace(/^https?:\/\//, '');
      
      // Remove path, query, hash
      domain = domain.split('/')[0].split('?')[0].split('#')[0];
      
      // Remove port
      domain = domain.split(':')[0];
      
      // Remove www. prefix for matching
      domain = domain.replace(/^www\./, '');
      
      return domain;
    } catch (error) {
      return null;
    }
  }
  
  /**
   * Get all URL mappings (for debugging)
   */
  getUrlMappings(): Array<{ domain: string; clientName?: string; boardName?: string; clientId?: number; boardId?: number }> {
    const mappings: Array<{ domain: string; clientName?: string; boardName?: string; clientId?: number; boardId?: number }> = [];
    
    for (const [domain, mapping] of this.urlMappings) {
      mappings.push({
        domain,
        clientName: mapping.clientName,
        boardName: mapping.boardName,
        clientId: mapping.clientId,
        boardId: mapping.boardId
      });
    }
    
    return mappings;
  }
  
  /**
   * Find a tracked domain by searching the title text
   * This handles cases like "Messenger | Facebook" where the domain name appears
   * but not as a URL pattern
   */
  private findTrackedDomainInTitle(title: string): { domain: string; mapping: UrlMapping } | null {
    if (!title || this.urlMappings.size === 0) {
      return null;
    }
    
    const titleLower = title.toLowerCase();
    
    // Normalize title for matching (remove diacritics)
    const normalizedTitle = titleLower
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '') // Remove diacritics
      .replace(/ă/g, 'a')
      .replace(/â/g, 'a')
      .replace(/î/g, 'i')
      .replace(/ș/g, 's')
      .replace(/ț/g, 't');
    
    // PRIORITY 0 (HIGHEST): Check title_match keywords (user-specified, comma-separated)
    // This is the most reliable method - user explicitly tells us what to look for
    for (const [domain, mapping] of this.urlMappings) {
      const titleMatch = mapping.titleMatch || mapping.title_match;
      if (titleMatch) {
        // Split by comma and check each keyword
        const keywords = titleMatch.split(',').map(k => k.trim().toLowerCase()).filter(k => k.length > 0);
        for (const keyword of keywords) {
          // Normalize keyword too (remove diacritics)
          const normalizedKeyword = keyword
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .replace(/ă/g, 'a')
            .replace(/â/g, 'a')
            .replace(/î/g, 'i')
            .replace(/ș/g, 's')
            .replace(/ț/g, 't');
          
          // Check both original and normalized title against both original and normalized keyword
          if (titleLower.includes(keyword) || normalizedTitle.includes(keyword) || 
              titleLower.includes(normalizedKeyword) || normalizedTitle.includes(normalizedKeyword)) {
            console.log(`[BrowserMonitor] TITLE_MATCH keyword "${keyword}" (normalized: "${normalizedKeyword}") matched for domain: ${domain}`);
            return { domain, mapping };
          }
        }
      }
    }
    
    // PRIORITY 1: Check display_name matches (user-defined)
    // e.g., display_name "Catherine's Cottages" matches title "Catherine's Cottages - Chrome"
    for (const [domain, mapping] of this.urlMappings) {
      const displayName = mapping.displayName || mapping.display_name;
      if (displayName && displayName.length > 3) {
        const displayLower = displayName.toLowerCase();
        if (titleLower.includes(displayLower)) {
          console.log(`[BrowserMonitor] DISPLAY NAME match: "${displayName}" -> ${domain}`);
          return { domain, mapping };
        }
      }
    }
    
    // PRIORITY 2: For domains with same base but different TLDs (e.g., mercedes-benz.hu vs .ro)
    // Check for country/language indicators in title
    const countryIndicators: Record<string, string[]> = {
      '.hu': ['hungary', 'hungaria', 'magyar', 'magyarorszag', 'magyarország', 'ungarn'],
      '.ro': ['romania', 'românia', 'roumanie', 'romanian', 'rumänien', 'rumania', 'romnia', 'rom nia'],
      '.de': ['germany', 'deutschland', 'german', 'deutsch', 'allemagne'],
      '.fr': ['france', 'français', 'francais', 'french'],
      '.es': ['spain', 'españa', 'espana', 'spanish', 'español', 'espanol'],
      '.it': ['italy', 'italia', 'italian', 'italiano'],
      '.pl': ['poland', 'polska', 'polish', 'pologne'],
      '.cz': ['czech', 'česká', 'ceska', 'česko', 'cesko'],
      '.sk': ['slovakia', 'slovensko', 'slovak'],
      '.at': ['austria', 'österreich', 'osterreich'],
      '.ch': ['switzerland', 'schweiz', 'suisse', 'svizzera'],
      '.nl': ['netherlands', 'nederland', 'dutch', 'pays-bas'],
      '.be': ['belgium', 'belgique', 'belgië', 'belgie'],
      '.uk': ['united kingdom', 'britain', 'british'],
      '.com': ['global', 'international', 'worldwide'],
    };
    
    // Detect country from title
    let detectedTld: string | null = null;
    for (const [tld, indicators] of Object.entries(countryIndicators)) {
      for (const indicator of indicators) {
        // Check both original title and normalized
        if (titleLower.includes(indicator) || normalizedTitle.includes(indicator)) {
          detectedTld = tld;
          console.log(`[BrowserMonitor] Country indicator "${indicator}" suggests TLD: ${tld}`);
          break;
        }
      }
      if (detectedTld) break;
    }
    
    // PRIORITY 3: Check each tracked domain to see if its name appears in the title
    const matches: Array<{ domain: string; mapping: UrlMapping }> = [];
    
    for (const [domain, mapping] of this.urlMappings) {
      // Get the domain name without TLD (e.g., "facebook" from "facebook.com")
      const domainParts = domain.split('.');
      const domainName = domainParts[0].toLowerCase();
      const tld = '.' + domainParts[domainParts.length - 1].toLowerCase();
      
      // Skip very short domain names (less than 4 chars) to avoid false positives
      if (domainName.length < 4) {
        continue;
      }
      
      // Check if the domain name appears in the title
      if (titleLower.includes(domainName)) {
        matches.push({ domain, mapping });
      }
    }
    
    if (matches.length === 0) {
      return null;
    }
    
    if (matches.length === 1) {
      console.log(`[BrowserMonitor] Found "${matches[0].domain}" in title "${title}"`);
      return matches[0];
    }
    
    // Multiple matches - try to pick the right one based on country
    if (detectedTld) {
      const countryMatch = matches.find(m => m.domain.endsWith(detectedTld));
      if (countryMatch) {
        console.log(`[BrowserMonitor] Multiple matches, using country-based: ${countryMatch.domain} (detected TLD: ${detectedTld})`);
        return countryMatch;
      }
    }
    
    // Still multiple matches with no country hint - log and return first (not ideal but better than nothing)
    console.log(`[BrowserMonitor] Multiple matches found: ${matches.map(m => m.domain).join(', ')} - using first`);
    return matches[0];
  }
}

