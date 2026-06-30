<?php

use Webmail\Core\Router;
use Webmail\Core\Request;
use Webmail\Controllers\AuthController;
use Webmail\Controllers\MailboxController;
use Webmail\Controllers\MessageController;
use Webmail\Controllers\SettingsController;
use Webmail\Controllers\TwoFactorController;
use Webmail\Controllers\LabelController;
use Webmail\Controllers\SmartViewsController;
use Webmail\Controllers\MentionsController;
use Webmail\Controllers\FilterController;
use Webmail\Addons\Tasks\Controllers\TodoController;
use Webmail\Addons\Tasks\Controllers\MyWorkController;
use Webmail\Controllers\AccountController;
use Webmail\Controllers\DriveController;
use Webmail\Controllers\DriveVersionsController;
use Webmail\Controllers\OfficeEditorController;
use Webmail\Controllers\StorageController;
use Webmail\Addons\Calendar\Controllers\CalendarController;
use Webmail\Addons\EmailTracking\Controllers\TrackingController;
use Webmail\Controllers\SystemController;
use Webmail\Controllers\ContactsController;
use Webmail\Addons\Contacts\Controllers\AddressBookController;
use Webmail\Addons\Reactions\Controllers\ReactionController;
use Webmail\Addons\Calendar\Controllers\CalendarConnectionController;
use Webmail\Addons\AIAssistant\Controllers\AIController;
use Webmail\Addons\NewsReader\Controllers\NewsReaderController;
use Webmail\Addons\NewsReader\Markets\MarketsController;
use Webmail\Addons\KanbanBoards\Controllers\BoardController;
use Webmail\Controllers\SyncController;
use Webmail\Controllers\ConversationController;
use Webmail\Addons\Team\Controllers\ColleagueController;
use Webmail\Controllers\CallController;
use Webmail\Controllers\GuestCallController;
use Webmail\Addons\Chat\Controllers\ChatController;
use Webmail\Addons\Chat\Controllers\ChannelController;
use Webmail\Addons\Chat\Controllers\CategoryController;
use Webmail\Addons\Chat\Controllers\WebhookController;
use Webmail\Controllers\HuddleController;
use Webmail\Addons\EmailMarketing\Controllers\MailingListController;
use Webmail\Addons\EmailMarketing\Controllers\EmailQueueController;
use Webmail\Addons\EmailMarketing\Controllers\UnsubscribeController;
use Webmail\Controllers\EmailTemplateController;
use Webmail\Controllers\PushNotificationController;
use Webmail\Controllers\DeviceController;
use Webmail\Addons\Moodboards\Controllers\MoodBoardController;
use Webmail\Controllers\SharingController;
use Webmail\Addons\CrmPro\Controllers\PortalController;
use Webmail\Addons\CrmPro\Controllers\CrmInvoiceController;
use Webmail\Addons\CrmPro\Controllers\CrmDealController;
use Webmail\Addons\CrmPro\Controllers\CrmAutomationController;
use Webmail\Addons\CrmPro\Controllers\CrmSharingController;
use Webmail\Controllers\BillingController;
use Webmail\Controllers\OnboardingController;
use Webmail\Controllers\TestSimulationController;
use Webmail\Controllers\WeatherController;

return function (Router $router, array $config) {
    
    // Security: log a critical warning if debug mode is on in production
    if (getenv('APP_DEBUG') === 'true') {
        $hostname = gethostname();
        // If this doesn't look like a dev machine, warn loudly
        if (!in_array($hostname, ['localhost', 'dev', 'DESKTOP'], true) && 
            strpos($hostname ?: '', 'local') === false) {
            error_log("SECURITY WARNING: APP_DEBUG=true on production host '{$hostname}'. Debug endpoints are exposed!");
        }
    }
    
    // Performance: Parse request path once for all addon route checks (used throughout)
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';

    // Extract user email from JWT once for per-user addon resolution in route gating.
    // Without this, per-user addon overrides are ignored and routes use global status only.
    $routeGatingEmail = null;
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $authMatches)) {
        try {
            $session = new \Webmail\Services\SessionService($config['jwt'], $config['imap_encryption_key'] ?? '');
            $payload = $session->validateToken($authMatches[1]);
            if ($payload && ($payload['type'] ?? '') === 'access') {
                $routeGatingEmail = $payload['sub'] ?? $payload['email'] ?? null;
            }
        } catch (\Throwable $e) {
            // Token invalid/expired — fall back to global addon statuses
        }
    }

    // CSP violation reports (public, no auth — browser sends these automatically)
    // Rate limited: max 10 reports per minute per IP to prevent log flooding
    $router->post('/csp-report', function (Request $r) {
        $raw = file_get_contents('php://input');
        if ($raw) {
            // Basic size limit to prevent abuse (max 10KB per report)
            if (strlen($raw) > 10240) {
                return \Webmail\Core\Response::json(['status' => 'rejected'], 413);
            }
            
            $logFile = __DIR__ . '/storage/logs/csp-violations.log';
            $dir = dirname($logFile);
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            
            // Cap log file size at 10MB to prevent disk exhaustion
            if (file_exists($logFile) && filesize($logFile) > 10 * 1024 * 1024) {
                // Rotate: keep last 5MB
                $contents = file_get_contents($logFile);
                file_put_contents($logFile, substr($contents, -5 * 1024 * 1024));
            }
            
            $parsed = json_decode($raw, true);
            $entry = date('Y-m-d H:i:s') . ' ' . json_encode($parsed) . "\n";
            @file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
        }
        return \Webmail\Core\Response::json(['status' => 'ok'], 204);
    });
    
    // Public webhook receiver (no auth - token-validated, gated by chat addon)
    if (str_contains($requestPath, '/webhook/') && (new \Webmail\Services\AddonService($config, $routeGatingEmail))->isChatEnabled()) {
        $webhookPublic = new WebhookController($config);
        $router->post('/webhook/{token}', fn(Request $r) => $webhookPublic->receiveWebhook($r));
    }

    // Phase 3.6: Google Calendar push notifications. Google posts to this
    // endpoint whenever a watched calendar changes. The handler does not
    // require a Bearer token — it authenticates via the HMAC token set
    // during channels.watch (verified against calendar_push_channels).
    if (str_contains($requestPath, '/calendar/google/webhook')) {
        $calendarWebhookController = new \Webmail\Addons\Calendar\Controllers\CalendarController($config);
        $router->post('/calendar/google/webhook', fn(Request $r) => $calendarWebhookController->googleWebhook($r));
    }

    // Mail client auto-configuration (PUBLIC — clients hit these before
    // authenticating). Outlook autodiscover + Thunderbird/ISPDB autoconfig.
    // The autodiscover.<domain> / autoconfig.<domain> subdomains CNAME here.
    $autodiscover = new \Webmail\Controllers\AutodiscoverController($config);
    $router->post('/autodiscover/autodiscover.xml', fn(Request $r) => $autodiscover->outlook($r));
    $router->get('/autodiscover/autodiscover.xml', fn(Request $r) => $autodiscover->outlook($r));
    $router->post('/Autodiscover/Autodiscover.xml', fn(Request $r) => $autodiscover->outlook($r));
    $router->get('/Autodiscover/Autodiscover.xml', fn(Request $r) => $autodiscover->outlook($r));
    $router->get('/mail/config-v1.1.xml', fn(Request $r) => $autodiscover->thunderbird($r));
    $router->get('/autoconfig/mail/config-v1.1.xml', fn(Request $r) => $autodiscover->thunderbird($r));
    $router->get('/.well-known/autoconfig/mail/config-v1.1.xml', fn(Request $r) => $autodiscover->thunderbird($r));

    // Native app server discovery (PUBLIC — apps hit this before login to learn
    // which backend hosts a given email domain). Shared tenants resolve to this
    // server; unknown domains fall back to the email.<domain> convention.
    $serverDiscovery = new \Webmail\Controllers\ServerDiscoveryController($config);
    $router->get('/server-discovery', fn(Request $r) => $serverDiscovery->discover($r));

    // Bootstrap (combined initial load -- replaces 12+ individual calls)
    $bootstrap = new \Webmail\Controllers\BootstrapController($config);
    $router->get('/bootstrap', fn(Request $r) => $bootstrap->bootstrap($r));

    // Auth routes
    $auth = new AuthController($config);
    $router->post('/auth/login', fn(Request $r) => $auth->login($r));
    $router->post('/auth/logout', fn(Request $r) => $auth->logout($r));
    $router->post('/auth/refresh', fn(Request $r) => $auth->refresh($r));
    $router->get('/auth/me', fn(Request $r) => $auth->me($r));
    
    // Google OAuth login routes (public - no auth required)
    $router->get('/auth/google/enabled', fn(Request $r) => $auth->googleEnabled($r));
    $router->get('/auth/google/login', fn(Request $r) => $auth->googleLoginUrl($r));
    $router->get('/auth/google/login/callback', fn(Request $r) => $auth->googleLoginCallback($r));

    // Phase 2.2: one-time handoff endpoint. Exchanges the short-lived code
    // delivered to the frontend in the post-OAuth redirect URL for the
    // actual access/refresh/session tokens. Single-use (Redis key is
    // deleted on read). Public because the user has no session yet at this
    // point in the login flow.
    $router->post('/auth/oauth/handoff', fn(Request $r) => $auth->oauthHandoff($r));

    // SSO routes (desktop app cross-authentication)
    $sso = new \Webmail\Controllers\SSOController($config);
    $router->post('/sso/create-seed',   fn(Request $r) => $sso->createSeed($r));
    $router->post('/sso/clone-session', fn(Request $r) => $sso->cloneSession($r));
    $router->post('/sso/revoke-seed',   fn(Request $r) => $sso->revokeSeed($r));
    $router->post('/sso/exchange',      fn(Request $r) => $sso->exchange($r));

    // Device authorization ("scan to sign in"): a desktop app starts an
    // anonymous request and shows a QR + match number; an already-signed-in web
    // session approves it; the device polls for the resulting one-time code.
    $deviceAuth = new \Webmail\Controllers\DeviceAuthController($config);
    $router->post('/sso/device/start',   fn(Request $r) => $deviceAuth->deviceStart($r));   // public (TLS)
    $router->get('/sso/device/info',     fn(Request $r) => $deviceAuth->deviceInfo($r));     // auth (approver)
    $router->get('/sso/device/pending',  fn(Request $r) => $deviceAuth->devicePending($r));  // auth (approver, auto-modal)
    $router->post('/sso/device/approve', fn(Request $r) => $deviceAuth->deviceApprove($r));  // auth (approver)
    $router->post('/sso/device/deny',    fn(Request $r) => $deviceAuth->deviceDeny($r));     // auth (approver)
    $router->post('/sso/device/block',   fn(Request $r) => $deviceAuth->deviceBlock($r));    // auth (approver, deny + block IP)
    $router->post('/sso/device/poll',    fn(Request $r) => $deviceAuth->devicePoll($r));     // public (TLS)

    // Two-Factor Authentication routes
    $twoFactor = new TwoFactorController($config);
    $router->get('/2fa/status', fn(Request $r) => $twoFactor->status($r));
    $router->post('/2fa/setup', fn(Request $r) => $twoFactor->setup($r));
    $router->post('/2fa/verify', fn(Request $r) => $twoFactor->verify($r));
    $router->post('/2fa/disable', fn(Request $r) => $twoFactor->disable($r));
    $router->post('/2fa/backup-codes', fn(Request $r) => $twoFactor->regenerateBackupCodes($r));
    $router->post('/2fa/login', fn(Request $r) => $twoFactor->loginVerify($r));
    // Trusted devices
    $router->get('/2fa/trusted-devices', fn(Request $r) => $twoFactor->getTrustedDevices($r));
    $router->delete('/2fa/trusted-devices/{id}', fn(Request $r) => $twoFactor->revokeTrustedDevice($r));
    $router->delete('/2fa/trusted-devices', fn(Request $r) => $twoFactor->revokeAllTrustedDevices($r));
    
    // Session management routes
    $sessions = new \Webmail\Controllers\SessionController($config);
    $router->get('/sessions', fn(Request $r) => $sessions->list($r));
    $router->delete('/sessions/{id}', fn(Request $r) => $sessions->revoke($r));
    $router->post('/sessions/revoke-others', fn(Request $r) => $sessions->revokeOthers($r));
    $router->post('/sessions/revoke-all', fn(Request $r) => $sessions->revokeAll($r));
    $router->post('/sessions/heartbeat', fn(Request $r) => $sessions->heartbeat($r));

    // Device management routes (security: device registry, remote wipe, blocking)
    $devices = new DeviceController($config);
    $router->get('/devices', fn(Request $r) => $devices->list($r));
    $router->post('/devices/register', fn(Request $r) => $devices->register($r));
    $router->get('/devices/check', fn(Request $r) => $devices->check($r));
    $router->post('/devices/{id}/block', fn(Request $r) => $devices->block($r));
    $router->post('/devices/{id}/unblock', fn(Request $r) => $devices->unblock($r));
    $router->post('/devices/{id}/wipe', fn(Request $r) => $devices->wipe($r));
    $router->post('/devices/wipe-confirm', fn(Request $r) => $devices->wipeConfirm($r));

    // Mailbox routes
    $mailbox = new MailboxController($config);
    $router->get('/mailbox/init', fn(Request $r) => $mailbox->init($r));  // Combined initial load
    $router->get('/mailbox/folders', fn(Request $r) => $mailbox->folders($r));
    $router->get('/mailbox/quota', fn(Request $r) => $mailbox->quota($r));  // Mailbox storage usage card
    $router->get('/mailbox/folders/identity-version', fn(Request $r) => $mailbox->foldersIdentityVersion($r));
    $router->get('/mailbox/sync-issues', fn(Request $r) => $mailbox->outboxStats($r));
    // Phase 4: per-user folder sync health (mirror status, lagging folders).
    $router->get('/mailbox/sync-stats', fn(Request $r) => $mailbox->syncStats($r));
    $router->post('/mailbox/folders/status', fn(Request $r) => $mailbox->foldersStatus($r));

    // ===== Canonical folder_id-shaped routes =====
    //
    // All folder-scoped routes are keyed by UUIDv7 folder_id. The
    // controller resolves {folder_id} -> path via
    // BaseController::getResolvedFolder() which still runs a sampled
    // compare-mode resolve as a regression guard against identity drift.
    //
    // The {folder_id} URL regex is intentionally lax (`[0-9a-f-]+`)
    // because Router's parameter parser uses `[^}]+` and would choke on
    // the strict UUIDv7 `{N}` quantifiers. The controller-side resolver
    // (FolderInputResolver::looksLikeFolderId) applies the strict
    // UUIDv7 regex; anything that fails it returns 404.
    $router->get('/folders/{folder_id:[0-9a-f-]+}/messages', fn(Request $r) => $mailbox->messages($r));
    $router->get('/folders/{folder_id:[0-9a-f-]+}/messages/{uid}', fn(Request $r) => $mailbox->message($r));
    $router->post('/folders/{folder_id:[0-9a-f-]+}/messages/{uid}/flag', fn(Request $r) => $mailbox->setFlag($r));
    $router->post('/folders/{folder_id:[0-9a-f-]+}/messages/{uid}/move', fn(Request $r) => $mailbox->move($r));
    $router->delete('/folders/{folder_id:[0-9a-f-]+}/messages/{uid}', fn(Request $r) => $mailbox->delete($r));
    $router->put('/folders/{folder_id:[0-9a-f-]+}', fn(Request $r) => $mailbox->renameFolder($r));
    $router->delete('/folders/{folder_id:[0-9a-f-]+}', fn(Request $r) => $mailbox->deleteFolder($r));
    // Wave 2 P2 (round 2): the rest of folder-scoped read/write/sync ops.
    $router->post('/folders/{folder_id:[0-9a-f-]+}/messages/batch', fn(Request $r) => $mailbox->batchMessages($r));
    $router->get('/folders/{folder_id:[0-9a-f-]+}/messages/since', fn(Request $r) => $mailbox->messagesSince($r));
    $router->get('/folders/{folder_id:[0-9a-f-]+}/delta', fn(Request $r) => $mailbox->delta($r));
    $router->get('/folders/{folder_id:[0-9a-f-]+}/sync-state', fn(Request $r) => $mailbox->syncState($r));
    $router->get('/folders/{folder_id:[0-9a-f-]+}/flag-changes', fn(Request $r) => $mailbox->flagChanges($r));
    $router->get('/folders/{folder_id:[0-9a-f-]+}/messages/{uid}/raw', fn(Request $r) => $mailbox->rawMessage($r));
    $router->get('/folders/{folder_id:[0-9a-f-]+}/messages/{uid}/debug-structure', fn(Request $r) => $mailbox->debugMimeStructure($r));
    $router->get('/folders/{folder_id:[0-9a-f-]+}/messages/{uid}/download', fn(Request $r) => $mailbox->downloadRawMessage($r));
    $router->post('/folders/{folder_id:[0-9a-f-]+}/messages/{uid}/pin', fn(Request $r) => $mailbox->pinEmail($r));
    $router->delete('/folders/{folder_id:[0-9a-f-]+}/messages/{uid}/pin', fn(Request $r) => $mailbox->unpinEmail($r));
    $router->get('/folders/{folder_id:[0-9a-f-]+}/messages/{uid}/pin', fn(Request $r) => $mailbox->isPinned($r));
    $router->post('/folders/{folder_id:[0-9a-f-]+}/messages/{uid}/rsvp', fn(Request $r) => $mailbox->rsvp($r));
    $router->get('/folders/{folder_id:[0-9a-f-]+}/messages/{uid}/attachments/{part}/thumbnail', fn(Request $r) => $mailbox->attachmentThumbnail($r));
    $router->get('/folders/{folder_id:[0-9a-f-]+}/messages/{uid}/attachments/{part}', fn(Request $r) => $mailbox->attachment($r));
    $router->post('/folders/{folder_id:[0-9a-f-]+}/empty', fn(Request $r) => $mailbox->emptyFolder($r));
    $router->post('/folders/{folder_id:[0-9a-f-]+}/messages/{uid}/restore', fn(Request $r) => $mailbox->restoreMessage($r));
    $router->post('/folders/{folder_id:[0-9a-f-]+}/restore-all', fn(Request $r) => $mailbox->restoreAllFromTrash($r));
    // ===== End folder-scoped routes =====

    $router->get('/mailbox/cache/stats', fn(Request $r) => $mailbox->cacheStats($r));
    $router->post('/mailbox/cache/clear', fn(Request $r) => $mailbox->clearCache($r));
    $router->post('/mailbox/folders', fn(Request $r) => $mailbox->createFolder($r));
    $router->get('/mailbox/search', fn(Request $r) => $mailbox->search($r));
    $router->get('/mailbox/thread', fn(Request $r) => $mailbox->getThread($r));
    $router->post('/mailbox/messages/batch-multi', fn(Request $r) => $mailbox->batchMessagesMultiFolder($r));
    $router->post('/mailbox/batch-move', fn(Request $r) => $mailbox->batchMove($r));
    $router->post('/mailbox/batch-delete', fn(Request $r) => $mailbox->batchDelete($r));
    // Phase 2 of the DB-as-truth refactor: a single endpoint that flips
    // many messages' flags in one DB transaction + outbox batch, replacing
    // N round trips (one per message) with one. Required for the bulk
    // "select all -> mark read" workflow on large folders.
    $router->post('/mailbox/batch-flag', fn(Request $r) => $mailbox->batchSetFlag($r));
    $router->post('/mailbox/clean-folder', fn(Request $r) => $mailbox->cleanFolder($r));
    $router->post('/mailbox/unsubscribe', fn(Request $r) => $mailbox->unsubscribe($r));
    $router->get('/mailbox/image-proxy', fn(Request $r) => $mailbox->imageProxy($r));
    $router->post('/mailbox/save-attachments-to-drive', fn(Request $r) => $mailbox->saveAttachmentsToDrive($r));

    // Pinned emails (list-all). Per-message pin/unpin/isPinned routes
    // are folder-scoped and live under the canonical /folders/{folder_id}
    // tree above.
    $router->get('/mailbox/pinned', fn(Request $r) => $mailbox->getPinnedEmails($r));

    // Spam management routes
    $spam = new \Webmail\Controllers\SpamController($config);
    $router->get('/spam/blocked-senders', fn(Request $r) => $spam->getBlockedSenders($r));
    $router->post('/spam/block-sender', fn(Request $r) => $spam->blockSender($r));
    $router->delete('/spam/blocked-sender/{id}', fn(Request $r) => $spam->unblockSender($r));
    $router->get('/spam/safe-senders', fn(Request $r) => $spam->getSafeSenders($r));
    $router->post('/spam/safe-sender', fn(Request $r) => $spam->addSafeSender($r));
    $router->delete('/spam/safe-sender/{id}', fn(Request $r) => $spam->removeSafeSender($r));
    $router->post('/spam/report', fn(Request $r) => $spam->reportSpam($r));
    $router->post('/spam/report-batch', fn(Request $r) => $spam->reportSpamBatch($r));
    $router->post('/spam/not-spam', fn(Request $r) => $spam->notSpam($r));
    $router->post('/spam/not-spam-batch', fn(Request $r) => $spam->notSpamBatch($r));
    $router->get('/spam/emails', fn(Request $r) => $spam->getSpamEmails($r));
    $router->get('/spam/settings', fn(Request $r) => $spam->getSettings($r));
    $router->put('/spam/settings', fn(Request $r) => $spam->updateSettings($r));
    $router->get('/spam/stats', fn(Request $r) => $spam->getStats($r));

    // Statistics routes
    $statistics = new \Webmail\Controllers\StatisticsController($config);
    $router->get('/statistics/overview', fn(Request $r) => $statistics->getOverview($r));
    $router->get('/statistics/emails', fn(Request $r) => $statistics->getEmailStats($r));
    $router->get('/statistics/conversations', fn(Request $r) => $statistics->getConversations($r));
    $router->get('/statistics/contacts', fn(Request $r) => $statistics->getContacts($r));
    $router->get('/statistics/folders', fn(Request $r) => $statistics->getFolderStats($r));
    // Note: /statistics/tasks is gated inside the Tasks addon block above
    // Note: /statistics/calendar is gated inside the Calendar addon block below
    $router->get('/statistics/drive', fn(Request $r) => $statistics->getDriveStats($r));
    $router->get('/statistics/boards', fn(Request $r) => $statistics->getBoardStats($r));
    $router->get('/statistics/clients', fn(Request $r) => $statistics->getClientStats($r));
    // AI stats (gated by ai_assistant addon)
    if ((new \Webmail\Services\AddonService($config, $routeGatingEmail))->isAIAssistantEnabled()) {
        $router->get('/statistics/ai', fn(Request $r) => $statistics->getAIStats($r));
    }
    // Note: /statistics/time and track-time routes gated inside Time Tracker addon block below
    $router->get('/statistics/preferences', fn(Request $r) => $statistics->getPreferenceStats($r));
    $router->get('/statistics/events', fn(Request $r) => $statistics->getRecentEvents($r));
    $router->post('/statistics/log-event', fn(Request $r) => $statistics->logEvent($r));

    // Universal Search routes (gated by universal_search addon)
    if (str_contains($requestPath, '/search/') && (new \Webmail\Services\AddonService($config, $routeGatingEmail))->isUniversalSearchEnabled()) {
        $universalSearch = new \Webmail\Controllers\UniversalSearchController($config);
        $router->get('/search/universal', fn(Request $r) => $universalSearch->search($r));
        $router->get('/search/quick', fn(Request $r) => $universalSearch->quickSearch($r));
        $router->post('/search/index/rebuild', fn(Request $r) => $universalSearch->rebuildIndex($r));
        $router->post('/search/index/attachments', fn(Request $r) => $universalSearch->indexAttachments($r));
        $router->post('/search/index/bodies', fn(Request $r) => $universalSearch->indexBodies($r));
        $router->get('/search/index/stats', fn(Request $r) => $universalSearch->indexStats($r));
        $router->post('/search/index/item', fn(Request $r) => $universalSearch->indexItem($r));
        $router->delete('/search/index/item', fn(Request $r) => $universalSearch->removeIndexItem($r));
    }

    // Feedback route
    $feedback = new \Webmail\Controllers\FeedbackController($config);
    $router->post('/feedback', fn(Request $r) => $feedback->submit($r));

    // Message routes
    $message = new MessageController($config);
    $router->post('/messages/send', fn(Request $r) => $message->send($r));
    $router->post('/messages/schedule', fn(Request $r) => $message->scheduleSend($r));
    $router->get('/messages/scheduled', fn(Request $r) => $message->getScheduled($r));
    $router->get('/messages/schedule/{scheduleId}', fn(Request $r) => $message->getScheduledById($r));
    $router->delete('/messages/schedule/{scheduleId}', fn(Request $r) => $message->cancelScheduled($r));
    $router->post('/messages/draft', fn(Request $r) => $message->saveDraft($r));
    $router->post('/messages/{uid}/reply', fn(Request $r) => $message->reply($r));
    $router->post('/messages/{uid}/forward', fn(Request $r) => $message->forward($r));
    $router->post('/attachments/upload', fn(Request $r) => $message->uploadAttachment($r));
    $router->post('/message/upload-inline-image', fn(Request $r) => $message->uploadInlineImage($r));
    $router->get('/inline-image/{filename}', fn(Request $r) => $message->serveInlineImage($r));

    // Label routes
    $labels = new LabelController($config);
    $router->get('/labels', fn(Request $r) => $labels->list($r));
    $router->post('/labels', fn(Request $r) => $labels->create($r));
    $router->put('/labels/{id}', fn(Request $r) => $labels->update($r));
    $router->delete('/labels/{id}', fn(Request $r) => $labels->delete($r));
    $router->get('/labels/message', fn(Request $r) => $labels->getMessageLabels($r));
    $router->post('/labels/message', fn(Request $r) => $labels->addToMessage($r));
    $router->post('/labels/message/remove', fn(Request $r) => $labels->removeFromMessage($r));

    // Smart Views (saved searches) — full CRUD + reorder
    $smartViews = new SmartViewsController($config);
    $router->get('/smart-views',             fn(Request $r) => $smartViews->list($r));
    $router->post('/smart-views',            fn(Request $r) => $smartViews->create($r));
    $router->patch('/smart-views/reorder',   fn(Request $r) => $smartViews->reorder($r));
    $router->put('/smart-views/{id}',        fn(Request $r) => $smartViews->update($r));
    $router->delete('/smart-views/{id}',     fn(Request $r) => $smartViews->delete($r));

    // Mentions — compose @-suggest + per-message mention chips
    $mentions = new MentionsController($config);
    $router->get('/mentions/for-message', fn(Request $r) => $mentions->forMessage($r));
    $router->get('/mentions/suggest',     fn(Request $r) => $mentions->suggest($r));

    // Settings routes
    $settings = new SettingsController($config);
    $router->get('/settings', fn(Request $r) => $settings->get($r));
    $router->put('/settings', fn(Request $r) => $settings->update($r));
    $router->put('/settings/password', fn(Request $r) => $settings->changePassword($r));

    $testSim = new TestSimulationController($config);
    $router->get('/test-simulation/preflight', fn(Request $r) => $testSim->preflight($r));
    $router->post('/test-simulation/generate', fn(Request $r) => $testSim->generate($r));
    $router->get('/test-simulation/runs', fn(Request $r) => $testSim->listRuns($r));
    $router->delete('/test-simulation/runs/{runId}', fn(Request $r) => $testSim->deleteRun($r));
    $router->delete('/test-simulation/runs', fn(Request $r) => $testSim->deleteAll($r));
    // AI settings (gated by ai_assistant addon)
    if ((new \Webmail\Services\AddonService($config, $routeGatingEmail))->isAIAssistantEnabled()) {
        $router->get('/settings/ai', fn(Request $r) => $settings->getAISettings($r));
        $router->put('/settings/ai', fn(Request $r) => $settings->updateAISettings($r));
    }
    
    // Trusted senders routes
    $router->get('/settings/trusted-senders', fn(Request $r) => $settings->getTrustedSenders($r));
    $router->post('/settings/trusted-senders', fn(Request $r) => $settings->addTrustedSender($r));
    $router->post('/settings/trusted-senders/import', fn(Request $r) => $settings->importTrustedSenders($r));
    $router->delete('/settings/trusted-senders', fn(Request $r) => $settings->removeTrustedSender($r));
    
    // Storage settings routes (read-only - config comes from Panel)
    $router->get('/settings/storage', fn(Request $r) => $settings->getStorageConfig($r));
    $router->get('/settings/storage/stats', fn(Request $r) => $settings->getStorageStats($r));

    // Conversation management routes (database-backed - single source of truth)
    $conversations = new ConversationController($config);
    $router->get('/conversations', fn(Request $r) => $conversations->getConversations($r));
    $router->get('/conversations/status', fn(Request $r) => $conversations->getIndexStatus($r));
    $router->get('/conversations/for-message', fn(Request $r) => $conversations->getConversationForMessage($r));
    $router->get('/conversations/{conversation_id}/messages', fn(Request $r) => $conversations->getConversationMessages($r));
    $router->get('/conversations/{conversation_id}/messages/global', fn(Request $r) => $conversations->getConversationMessagesGlobal($r));
    $router->get('/conversations/global', fn(Request $r) => $conversations->getConversationsGlobal($r));
    $router->post('/conversations/assign', fn(Request $r) => $conversations->assignMessages($r));
    $router->post('/conversations/sync', fn(Request $r) => $conversations->syncFolder($r));
    $router->post('/conversations/index', fn(Request $r) => $conversations->indexFolder($r));
    $router->put('/conversations/move', fn(Request $r) => $conversations->moveMessage($r));
    $router->post('/conversations/split', fn(Request $r) => $conversations->splitMessage($r));
    $router->post('/conversations/merge', fn(Request $r) => $conversations->mergeMessages($r));
    $router->delete('/conversations/override', fn(Request $r) => $conversations->resetOverride($r));
    $router->post('/conversations/migrate-splits', fn(Request $r) => $conversations->migrateSplits($r));

    // AI routes (gated by ai_assistant addon)
    if (str_contains($requestPath, '/ai/') && (new \Webmail\Services\AddonService($config, $routeGatingEmail))->isAIAssistantEnabled()) {
        $ai = new AIController($config);
        $router->get('/ai/config', fn(Request $r) => $ai->getConfig($r));
        $router->post('/ai/summarize', fn(Request $r) => $ai->summarize($r));
        $router->post('/ai/rewrite', fn(Request $r) => $ai->rewrite($r));
        $router->post('/ai/draft-reply', fn(Request $r) => $ai->draftReply($r));
    }

    // News Reader addon (RSS / Flipboard-style). The path gate also
    // matches `/markets/` because the markets endpoints live under the
    // News Reader addon umbrella (only used by the News dashboard panel).
    if ((str_contains($requestPath, '/news/') || str_contains($requestPath, '/markets/'))
        && (new \Webmail\Services\AddonService($config, $routeGatingEmail))->isNewsReaderEnabled()) {
        $news = new NewsReaderController($config);
        $router->get('/news/catalog', fn(Request $r) => $news->catalog($r));
        $router->get('/news/feeds', fn(Request $r) => $news->feeds($r));
        $router->get('/news/items', fn(Request $r) => $news->items($r));
        $router->get('/news/items/by-ids', fn(Request $r) => $news->itemsByIds($r));
        $router->post('/news/subscriptions', fn(Request $r) => $news->subscribe($r));
        $router->patch('/news/subscriptions/{id}', fn(Request $r) => $news->patchSubscription($r));
        $router->delete('/news/subscriptions/{id}', fn(Request $r) => $news->deleteSubscription($r));
        $router->post('/news/items/read-all', fn(Request $r) => $news->readAll($r));
        $router->post('/news/items/{id}/read', fn(Request $r) => $news->markRead($r));
        $router->delete('/news/items/{id}/read', fn(Request $r) => $news->markUnread($r));
        $router->post('/news/refresh', fn(Request $r) => $news->refresh($r));
        $router->get('/news/items/{id}/full', fn(Request $r) => $news->fullArticle($r));
        $router->get('/news/proxy-url', fn(Request $r) => $news->proxyUrl($r));
        $router->get('/news/proxy', fn(Request $r) => $news->proxyArticle($r));

        // Markets panel (stocks + crypto) — same gating as News reader,
        // since the panel only renders inside the news dashboard.
        $markets = new MarketsController($config);
        $router->get('/markets/overview', fn(Request $r) => $markets->overview($r));
        $router->get('/markets/available', fn(Request $r) => $markets->available($r));
    }

    // Filter routes
    $filters = new FilterController($config);
    $router->get('/filters', fn(Request $r) => $filters->list($r));
    $router->get('/filters/{id}', fn(Request $r) => $filters->get($r));
    $router->post('/filters', fn(Request $r) => $filters->create($r));
    $router->put('/filters/{id}', fn(Request $r) => $filters->update($r));
    $router->delete('/filters/{id}', fn(Request $r) => $filters->delete($r));
    $router->post('/filters/apply', fn(Request $r) => $filters->apply($r));
    $router->post('/filters/bulk-toggle', fn(Request $r) => $filters->bulkToggle($r));
    $router->post('/filters/sieve/sync', fn(Request $r) => $filters->syncSieve($r));
    $router->get('/filters/sieve/status', fn(Request $r) => $filters->sieveStatus($r));

    // =========================================================================
    // Tasks Addon Routes (gated - only registered when addon is enabled)
    // =========================================================================

    $mayTodoRoute = str_contains($requestPath, '/todos')
        || str_contains($requestPath, '/statistics/tasks')
        || str_contains($requestPath, '/my-work');

    if ($mayTodoRoute && (new \Webmail\Services\AddonService($config, $routeGatingEmail))->isTasksEnabled()) {
        // My Work aggregate endpoint
        $myWork = new MyWorkController($config);
        $router->get('/my-work', fn(Request $r) => $myWork->getMyWork($r));
        $todos = new TodoController($config);
        $router->get('/todos', fn(Request $r) => $todos->list($r));
        $router->get('/todos/{id}', fn(Request $r) => $todos->get($r));
        $router->post('/todos', fn(Request $r) => $todos->create($r));
        $router->post('/todos/from-email', fn(Request $r) => $todos->createFromEmail($r));
        $router->put('/todos/{id}', fn(Request $r) => $todos->update($r));
        $router->post('/todos/{id}/toggle', fn(Request $r) => $todos->toggle($r));
        $router->delete('/todos/completed', fn(Request $r) => $todos->deleteCompleted($r));
        $router->delete('/todos/{id}', fn(Request $r) => $todos->delete($r));
        $router->post('/todos/reorder', fn(Request $r) => $todos->reorder($r));

        // Statistics for Tasks (moved inside gate)
        $statistics = new \Webmail\Controllers\StatisticsController($config);
        $router->get('/statistics/tasks', fn(Request $r) => $statistics->getTaskStats($r));
    }

    // =========================================================================
    // Kanban Boards Addon Routes (gated - only registered when addon is enabled)
    // =========================================================================

    $mayBoardRoute = str_contains($requestPath, '/boards')
        || str_contains($requestPath, '/lists/')
        || str_contains($requestPath, '/financials')
        || str_contains($requestPath, '/clients/board-mapping');

    if ($mayBoardRoute && (new \Webmail\Services\AddonService($config, $routeGatingEmail))->isKanbanBoardsEnabled()) {
    // Board routes (Trello-like project management)
    $boards = new BoardController($config);
    // Boards
    $router->get('/boards', fn(Request $r) => $boards->listBoards($r));
    $router->post('/boards', fn(Request $r) => $boards->createBoard($r));
    $router->get('/boards/search', fn(Request $r) => $boards->searchCards($r));
    $router->post('/boards/batch-fetch', fn(Request $r) => $boards->batchFetch($r));
    $router->get('/boards/cards/due', fn(Request $r) => $boards->getCardsByDueDate($r));
    $router->get('/boards/cards/assigned', fn(Request $r) => $boards->getAssignedCards($r));
    // Email-Board linking - MUST be before /boards/{id} to avoid route collision
    $router->get('/boards/email-link', fn(Request $r) => $boards->getEmailBoard($r));
    $router->post('/boards/email-links-batch', fn(Request $r) => $boards->getEmailBoardsBatch($r));
    $router->get('/boards/by-thread', fn(Request $r) => $boards->getBoardsByThread($r));
    // Company users for sharing - MUST be before /boards/{id}
    $router->get('/boards/company-users', fn(Request $r) => $boards->getCompanyUsers($r));
    // URL mappings for FlowOneDrive - MUST be before /boards/{id}
    $router->get('/boards/url-mappings', fn(Request $r) => $boards->getUrlMappings($r));
    // Trello import - MUST be before /boards/{id}
    $router->post('/boards/import-trello/preview', fn(Request $r) => $boards->previewTrelloImport($r));
    $router->post('/boards/import-trello', fn(Request $r) => $boards->importTrello($r));
    $router->get('/boards/{id}', fn(Request $r) => $boards->getBoard($r));
    $router->put('/boards/{id}', fn(Request $r) => $boards->updateBoard($r));
    $router->delete('/boards/{id}', fn(Request $r) => $boards->deleteBoard($r));
    // Board close/reopen
    $router->post('/boards/{id}/close', fn(Request $r) => $boards->closeBoard($r));
    $router->post('/boards/{id}/reopen', fn(Request $r) => $boards->reopenBoard($r));
    // Board members
    $router->get('/boards/{id}/members', fn(Request $r) => $boards->getMembers($r));
    $router->post('/boards/{id}/members', fn(Request $r) => $boards->addMember($r));
    $router->post('/boards/{id}/members/batch', fn(Request $r) => $boards->addMembersBatch($r));
    $router->post('/boards/{id}/members/permissions', fn(Request $r) => $boards->updateMemberPermissions($r));
    $router->put('/boards/{id}/members/{email}', fn(Request $r) => $boards->updateMember($r));
    $router->delete('/boards/{id}/members/{email}', fn(Request $r) => $boards->removeMember($r));
    // Board Drive integration
    $router->get('/boards/{id}/drive-folder', fn(Request $r) => $boards->getDriveFolder($r));
    $router->post('/boards/{id}/drive-folder', fn(Request $r) => $boards->getOrCreateDriveFolder($r));
    $router->get('/boards/{id}/drive-members', fn(Request $r) => $boards->getDriveMembers($r));
    $router->post('/boards/{id}/members/{email}/drive-access', fn(Request $r) => $boards->setMemberDriveAccess($r));
    $router->delete('/boards/{id}/members/{email}/drive-access', fn(Request $r) => $boards->revokeMemberDriveAccess($r));
    
    // Tracked URLs (Website Time Tracking)
    $router->get('/boards/{id}/tracked-urls', fn(Request $r) => $boards->getTrackedUrls($r));
    $router->post('/boards/{id}/tracked-urls', fn(Request $r) => $boards->addTrackedUrl($r));
    $router->put('/boards/{id}/tracked-urls/{urlId}', fn(Request $r) => $boards->updateTrackedUrl($r));
    $router->delete('/boards/{id}/tracked-urls/{urlId}', fn(Request $r) => $boards->deleteTrackedUrl($r));
    // Board activity
    $router->get('/boards/{id}/activity', fn(Request $r) => $boards->getBoardActivityLog($r));
    // Board check (debug/admin tool - only available when APP_DEBUG=true)
    if (getenv('APP_DEBUG') === 'true') {
        $router->get('/boards/{id}/check', fn(Request $r) => $boards->checkBoard($r));
    }
    // Board lists
    $router->get('/boards/{id}/lists', fn(Request $r) => $boards->getLists($r));
    $router->post('/boards/{id}/lists', fn(Request $r) => $boards->createList($r));
    $router->put('/boards/lists/{id}', fn(Request $r) => $boards->updateList($r));
    $router->delete('/boards/lists/{id}', fn(Request $r) => $boards->deleteList($r));
    $router->post('/boards/lists/reorder', fn(Request $r) => $boards->reorderLists($r));
    // Board cards
    $router->get('/boards/lists/{list_id}/cards', fn(Request $r) => $boards->getCards($r));
    $router->post('/boards/lists/{list_id}/cards', fn(Request $r) => $boards->createCard($r));
    $router->get('/boards/cards/{id}', fn(Request $r) => $boards->getCard($r));
    $router->put('/boards/cards/{id}', fn(Request $r) => $boards->updateCard($r));
    $router->delete('/boards/cards/{id}', fn(Request $r) => $boards->deleteCard($r));
    $router->post('/boards/cards/{id}/move', fn(Request $r) => $boards->moveCard($r));
    $router->post('/boards/cards/reorder', fn(Request $r) => $boards->reorderCards($r));
    // Subtasks (cards with parent_card_id)
    $router->get('/boards/cards/{id}/subtasks', fn(Request $r) => $boards->getSubtasks($r));
    $router->post('/boards/cards/{id}/subtasks', fn(Request $r) => $boards->createSubtask($r));
    $router->post('/boards/cards/{id}/subtasks/batch', fn(Request $r) => $boards->createSubtasksBatch($r));
    // Card labels
    $router->get('/boards/{board_id}/labels', fn(Request $r) => $boards->getLabels($r));
    $router->post('/boards/{board_id}/labels', fn(Request $r) => $boards->createLabel($r));
    $router->put('/boards/labels/{id}', fn(Request $r) => $boards->updateLabel($r));
    $router->delete('/boards/labels/{id}', fn(Request $r) => $boards->deleteLabel($r));
    $router->post('/boards/cards/{card_id}/labels', fn(Request $r) => $boards->addCardLabel($r));
    $router->delete('/boards/cards/{card_id}/labels/{label_id}', fn(Request $r) => $boards->removeCardLabel($r));
    // Card checklists
    $router->get('/boards/cards/{card_id}/checklists', fn(Request $r) => $boards->getChecklists($r));
    $router->post('/boards/cards/{card_id}/checklists', fn(Request $r) => $boards->createChecklist($r));
    $router->put('/boards/checklists/{id}', fn(Request $r) => $boards->updateChecklist($r));
    $router->delete('/boards/checklists/{id}', fn(Request $r) => $boards->deleteChecklist($r));
    $router->post('/boards/checklists/{checklist_id}/items', fn(Request $r) => $boards->addChecklistItem($r));
    $router->put('/boards/checklist-items/{id}', fn(Request $r) => $boards->updateChecklistItem($r));
    $router->delete('/boards/checklist-items/{id}', fn(Request $r) => $boards->deleteChecklistItem($r));
    // Card attachments
    $router->get('/boards/cards/{card_id}/attachments', fn(Request $r) => $boards->getAttachments($r));
    $router->post('/boards/cards/{card_id}/attachments', fn(Request $r) => $boards->uploadAttachment($r));
    $router->post('/boards/cards/{card_id}/attachments/url', fn(Request $r) => $boards->addUrlAttachment($r));
    $router->post('/boards/cards/{card_id}/attachments/drive', fn(Request $r) => $boards->addDriveAttachment($r));
    $router->delete('/boards/attachments/{id}', fn(Request $r) => $boards->deleteAttachment($r));
    $router->post('/boards/cards/{card_id}/cover', fn(Request $r) => $boards->setAttachmentCover($r));
    // Card asset folders
    $router->get('/boards/cards/{card_id}/asset-folders', fn(Request $r) => $boards->getAssetFolders($r));
    $router->post('/boards/cards/{card_id}/asset-folders', fn(Request $r) => $boards->createAssetFolder($r));
    $router->put('/boards/asset-folders/{id}', fn(Request $r) => $boards->renameAssetFolder($r));
    $router->delete('/boards/asset-folders/{id}', fn(Request $r) => $boards->deleteAssetFolder($r));
    $router->put('/boards/attachments/{id}/move', fn(Request $r) => $boards->moveAttachmentToFolder($r));
    // Card comments
    $router->get('/boards/cards/{card_id}/comments', fn(Request $r) => $boards->getComments($r));
    $router->post('/boards/cards/{card_id}/comments', fn(Request $r) => $boards->addComment($r));
    $router->put('/boards/comments/{id}', fn(Request $r) => $boards->updateComment($r));
    $router->delete('/boards/comments/{id}', fn(Request $r) => $boards->deleteComment($r));
    // Card activity
    $router->get('/boards/cards/{card_id}/activity', fn(Request $r) => $boards->getActivity($r));
    
    // Email-Board linking
    $router->post('/boards/{board_id}/emails', fn(Request $r) => $boards->linkEmail($r));
    $router->get('/boards/{board_id}/emails', fn(Request $r) => $boards->getBoardEmails($r));
    $router->put('/boards/{board_id}/email-link', fn(Request $r) => $boards->updateEmailLinkLocation($r));
    $router->delete('/boards/emails/{link_id}', fn(Request $r) => $boards->unlinkEmail($r));
    
    // Progress reports
    $router->get('/boards/{board_id}/progress', fn(Request $r) => $boards->getProgress($r));
    $router->get('/boards/{board_id}/progress-report', fn(Request $r) => $boards->generateProgressReport($r));
    $router->post('/boards/{board_id}/progress-report/send', fn(Request $r) => $boards->sendProgressReport($r));
    $router->get('/boards/{board_id}/progress-report/history', fn(Request $r) => $boards->getProgressReportHistory($r));
    // Board financials (milestones with amounts)
    $router->get('/boards/{board_id}/financials', fn(Request $r) => $boards->getFinancials($r));
    // Global financials (all boards)
    $router->get('/financials', fn(Request $r) => $boards->getAllFinancials($r));
    // Member financial permissions
    $router->post('/boards/{id}/members/financial-permission', fn(Request $r) => $boards->updateMemberFinancialPermission($r));
    $router->get('/boards/{id}/can-view-financials', fn(Request $r) => $boards->canViewFinancials($r));
    // Milestone progress
    $router->get('/lists/{list_id}/progress', fn(Request $r) => $boards->getMilestoneProgress($r));
    } // end kanban_boards addon gate

    // =========================================================================
    // Board Pro Addon Routes (requires kanban_boards AND board_pro)
    // =========================================================================
    $mayBoardProRoute = str_contains($requestPath, '/board-pro');
    if ($mayBoardProRoute) {
        $addonSvc = new \Webmail\Services\AddonService($config, $routeGatingEmail);
        if ($addonSvc->isKanbanBoardsEnabled() && $addonSvc->isBoardProEnabled()) {
            $boardPro = new \Webmail\Addons\BoardPro\Controllers\BoardProController($config);
            $boardProAuto = new \Webmail\Addons\BoardPro\Controllers\BoardProAutomationController($config);

            // Card-Email Linking
            $router->post('/board-pro/cards/{card_id}/emails', fn(Request $r) => $boardPro->linkEmailToCard($r));
            $router->get('/board-pro/cards/{card_id}/emails', fn(Request $r) => $boardPro->getCardEmails($r));
            $router->delete('/board-pro/cards/{card_id}/emails/{id}', fn(Request $r) => $boardPro->unlinkEmail($r));
            $router->put('/board-pro/card-emails/{id}/reply-status', fn(Request $r) => $boardPro->updateReplyStatus($r));
            $router->get('/board-pro/boards/{id}/awaiting-replies', fn(Request $r) => $boardPro->getAwaitingReplies($r));
            $router->post('/board-pro/boards/{id}/convert-email', fn(Request $r) => $boardPro->convertEmailToCard($r));

            // Email Auto-Link Rules
            $router->get('/board-pro/boards/{id}/email-rules', fn(Request $r) => $boardPro->getEmailRules($r));
            $router->post('/board-pro/boards/{id}/email-rules', fn(Request $r) => $boardPro->createEmailRule($r));
            $router->put('/board-pro/email-rules/{id}', fn(Request $r) => $boardPro->updateEmailRule($r));
            $router->delete('/board-pro/email-rules/{id}', fn(Request $r) => $boardPro->deleteEmailRule($r));
            $router->post('/board-pro/email-rules/{id}/run', fn(Request $r) => $boardPro->runEmailRule($r));
            $router->post('/board-pro/evaluate-email-rules', fn(Request $r) => $boardPro->evaluateEmailRules($r));
            $router->post('/board-pro/evaluate-email-rules-catchup', fn(Request $r) => $boardPro->evaluateEmailRulesCatchup($r));

            // Card Financials
            $router->get('/board-pro/cards/{card_id}/financials', fn(Request $r) => $boardPro->getCardFinancials($r));
            $router->put('/board-pro/cards/{card_id}/financials', fn(Request $r) => $boardPro->updateCardFinancials($r));
            $router->get('/board-pro/boards/{id}/financial-summary', fn(Request $r) => $boardPro->getBoardFinancialSummary($r));
            $router->get('/board-pro/financials/global', fn(Request $r) => $boardPro->getGlobalFinancials($r));

            // Client Health
            $router->get('/board-pro/boards/{id}/client-health', fn(Request $r) => $boardPro->getBoardClientHealth($r));

            // Board Automations
            $router->get('/board-pro/boards/{id}/automations', fn(Request $r) => $boardProAuto->getRules($r));
            $router->post('/board-pro/boards/{id}/automations', fn(Request $r) => $boardProAuto->createRule($r));
            $router->put('/board-pro/automations/{id}', fn(Request $r) => $boardProAuto->updateRule($r));
            $router->delete('/board-pro/automations/{id}', fn(Request $r) => $boardProAuto->deleteRule($r));
            $router->get('/board-pro/automations/{id}/log', fn(Request $r) => $boardProAuto->getRuleLog($r));
            $router->get('/board-pro/boards/{id}/automations/log', fn(Request $r) => $boardProAuto->getBoardLog($r));

            // Unified Card Timeline
            $router->get('/board-pro/cards/{card_id}/timeline', fn(Request $r) => $boardPro->getCardTimeline($r));

            // Multi-Lens Views
            $router->get('/board-pro/boards/{id}/revenue-view', fn(Request $r) => $boardPro->getRevenueView($r));
            $router->get('/board-pro/boards/{id}/time-view', fn(Request $r) => $boardPro->getTimeView($r));
            $router->get('/board-pro/boards/{id}/client-view', fn(Request $r) => $boardPro->getClientView($r));

            // MoodBoard Hybrid
            $router->post('/board-pro/boards/{id}/import-moodboard', fn(Request $r) => $boardPro->importMoodBoardAsCards($r));
            $router->post('/board-pro/cards/{card_id}/moodboard-link', fn(Request $r) => $boardPro->linkMoodBoardFrame($r));
            $router->get('/board-pro/cards/{card_id}/moodboard-links', fn(Request $r) => $boardPro->getCardMoodBoardLinks($r));
            $router->delete('/board-pro/cards/{card_id}/moodboard-link/{id}', fn(Request $r) => $boardPro->unlinkMoodBoardFrame($r));

            // Advanced Permissions
            // AI Intelligence
            $router->post('/board-pro/boards/{id}/ai/summarize', fn(Request $r) => $boardPro->aiSummarize($r));
            $router->post('/board-pro/boards/{id}/ai/risk-report', fn(Request $r) => $boardPro->aiRiskReport($r));
            $router->post('/board-pro/boards/{id}/ai/estimate', fn(Request $r) => $boardPro->aiEstimate($r));
            $router->post('/board-pro/cards/{card_id}/ai/draft-update', fn(Request $r) => $boardPro->aiDraftUpdate($r));

            // Executive Mode
            $router->get('/board-pro/boards/{id}/executive-report', fn(Request $r) => $boardPro->getExecutiveReport($r));
            $router->get('/board-pro/boards/{id}/revenue-projection', fn(Request $r) => $boardPro->getRevenueProjection($r));
            $router->get('/board-pro/boards/{id}/workload-analytics', fn(Request $r) => $boardPro->getWorkloadAnalytics($r));

            // Scope Radar
            $router->get('/board-pro/boards/{id}/scope-radar', fn(Request $r) => $boardPro->getScopeRadar($r));
        }
    } // end board_pro addon gate

    // =========================================================================
    // Project Hub Addon Routes (requires kanban_boards AND project_hub)
    // =========================================================================
    $mayProjectHubRoute = str_contains($requestPath, '/project-hub');
    if ($mayProjectHubRoute) {
        $addonSvc2 = new \Webmail\Services\AddonService($config, $routeGatingEmail);
        if ($addonSvc2->isKanbanBoardsEnabled() && $addonSvc2->isEnabled('project_hub')) {
            $projectHub = new \Webmail\Addons\ProjectHub\Controllers\ProjectHubController($config);
            $phWorkload = new \Webmail\Addons\ProjectHub\Controllers\ProjectHubWorkloadController($config);
            $phRoles = new \Webmail\Addons\ProjectHub\Controllers\ProjectHubRoleController($config);
            $phFiles = new \Webmail\Addons\ProjectHub\Controllers\ProjectHubFileController($config);
            $phShare = new \Webmail\Addons\ProjectHub\Controllers\ProjectHubShareController($config);

            // Full hierarchy (for sidebar tree)
            $router->get('/project-hub/hierarchy', fn(Request $r) => $projectHub->getHierarchy($r));

            // Spaces CRUD
            $router->get('/project-hub/spaces', fn(Request $r) => $projectHub->getSpaces($r));
            $router->post('/project-hub/spaces', fn(Request $r) => $projectHub->createSpace($r));
            $router->put('/project-hub/spaces/{id}', fn(Request $r) => $projectHub->updateSpace($r));
            $router->delete('/project-hub/spaces/{id}', fn(Request $r) => $projectHub->deleteSpace($r));
            $router->post('/project-hub/spaces/reorder', fn(Request $r) => $projectHub->reorderSpaces($r));
            $router->get('/project-hub/spaces/{id}/overview', fn(Request $r) => $projectHub->getSpaceOverview($r));

            // Folders CRUD
            $router->get('/project-hub/spaces/{id}/folders', fn(Request $r) => $projectHub->getFolders($r));
            $router->post('/project-hub/spaces/{id}/folders', fn(Request $r) => $projectHub->createFolder($r));
            $router->put('/project-hub/folders/{id}', fn(Request $r) => $projectHub->updateFolder($r));
            $router->delete('/project-hub/folders/{id}', fn(Request $r) => $projectHub->deleteFolder($r));
            $router->post('/project-hub/folders/{id}/duplicate', fn(Request $r) => $projectHub->duplicateFolder($r));
            $router->post('/project-hub/folders/reorder', fn(Request $r) => $projectHub->reorderFolders($r));

            // Folder <-> Board links
            $router->get('/project-hub/folders/{id}/boards', fn(Request $r) => $projectHub->getFolderBoards($r));
            $router->post('/project-hub/folders/{id}/boards', fn(Request $r) => $projectHub->linkBoard($r));
            $router->delete('/project-hub/folders/{fid}/boards/{bid}', fn(Request $r) => $projectHub->unlinkBoard($r));
            $router->post('/project-hub/folders/{id}/boards/reorder', fn(Request $r) => $projectHub->reorderFolderBoards($r));
            $router->get('/project-hub/folders/{id}/overview', fn(Request $r) => $projectHub->getFolderOverview($r));
            $router->get('/project-hub/folders/{id}/board-attachments', fn(Request $r) => $projectHub->getFolderBoardAttachments($r));
            $router->get('/project-hub/folders/{id}/tracked-urls', fn(Request $r) => $projectHub->getFolderTrackedUrls($r));

            // Bookmarks
            $router->get('/project-hub/folders/{id}/bookmarks', fn(Request $r) => $projectHub->getBookmarks($r));
            $router->post('/project-hub/folders/{id}/bookmarks', fn(Request $r) => $projectHub->createBookmark($r));
            $router->delete('/project-hub/bookmarks/{id}', fn(Request $r) => $projectHub->deleteBookmark($r));

            // Multi-Assignee Management
            $router->post('/project-hub/cards/assignees/batch-fetch', fn(Request $r) => $projectHub->getAssigneesBatch($r));
            $router->delete('/project-hub/card-assignees/batch', fn(Request $r) => $projectHub->removeAssigneesBatch($r));
            $router->get('/project-hub/cards/{id}/assignees', fn(Request $r) => $projectHub->getAssignees($r));
            $router->post('/project-hub/cards/{id}/assignees', fn(Request $r) => $projectHub->addAssignee($r));
            $router->post('/project-hub/cards/{id}/assignees/batch', fn(Request $r) => $projectHub->addAssigneesBatch($r));
            $router->put('/project-hub/card-assignees/{id}', fn(Request $r) => $projectHub->updateAssignee($r));
            $router->delete('/project-hub/card-assignees/{id}', fn(Request $r) => $projectHub->removeAssignee($r));
            $router->post('/project-hub/card-assignees/{id}/status', fn(Request $r) => $projectHub->changeAssigneeStatus($r));

            // Time Breakdown (admin overview)
            $router->get('/project-hub/time-breakdown', fn(Request $r) => $projectHub->getTimeBreakdown($r));

            // Work Sessions / Time Tracking
            $router->get('/project-hub/work-sessions/summary', fn(Request $r) => $projectHub->getWorkSessionsSummary($r));
            $router->get('/project-hub/cards/{id}/work-sessions', fn(Request $r) => $projectHub->getWorkSessions($r));
            $router->post('/project-hub/work-sessions', fn(Request $r) => $projectHub->logWorkSession($r));
            $router->get('/project-hub/card-assignees/{id}/time', fn(Request $r) => $projectHub->getAssigneeTime($r));
            $router->post('/project-hub/work-sessions/drive-bridge', fn(Request $r) => $projectHub->driveBridge($r));

            // Dependencies
            $router->get('/project-hub/cards/{id}/dependencies', fn(Request $r) => $projectHub->getDependencies($r));
            $router->post('/project-hub/cards/{id}/dependencies', fn(Request $r) => $projectHub->createDependency($r));
            $router->delete('/project-hub/dependencies/{id}', fn(Request $r) => $projectHub->deleteDependency($r));
            $router->get('/project-hub/cards/{id}/subtask-card-links', fn(Request $r) => $projectHub->getSubtaskCardLinks($r));
            $router->get('/project-hub/cards/{id}/origin-link', fn(Request $r) => $projectHub->getCardOriginLink($r));
            $router->post('/project-hub/cards/{id}/subtasks/{subtaskId}/linked-card', fn(Request $r) => $projectHub->createSubtaskCardLink($r));

            // Comment Reactions & Read Tracking
            $router->post('/project-hub/comments/{id}/reactions', fn(Request $r) => $projectHub->toggleReaction($r));
            $router->get('/project-hub/comments/{id}/reactions', fn(Request $r) => $projectHub->getReactions($r));
            $router->post('/project-hub/comments/reactions/batch', fn(Request $r) => $projectHub->getReactionsBatch($r));
            $router->post('/project-hub/comments/{id}/attachments', fn(Request $r) => $projectHub->addCommentAttachment($r));
            $router->post('/project-hub/cards/{id}/mark-read', fn(Request $r) => $projectHub->markRead($r));
            $router->get('/project-hub/cards/{id}/unread-count', fn(Request $r) => $projectHub->getUnreadCount($r));

            // Workload Planner (admin-only gating done inside controller)
            $router->get('/project-hub/workload/timeline', fn(Request $r) => $projectHub->getWorkloadTimeline($r));
            $router->get('/project-hub/workload/labels', fn(Request $r) => $projectHub->getWorkloadLabels($r));
            $router->get('/project-hub/workload/live', fn(Request $r) => $projectHub->getWorkloadLive($r));
            $router->get('/project-hub/workload/member/{email}', fn(Request $r) => $projectHub->getMemberWorkload($r));

            // PH Card Proxy (wraps board APIs + fires notifications)
            $router->put('/project-hub/cards/{id}', fn(Request $r) => $projectHub->proxyUpdateCard($r));
            $router->post('/project-hub/cards/{id}/comments', fn(Request $r) => $projectHub->proxyAddComment($r));
            $router->post('/project-hub/cards/{id}/shares', fn(Request $r) => $phShare->createShare($r));
            $router->get('/project-hub/cards/{id}/shares', fn(Request $r) => $phShare->listShares($r));
            $router->delete('/project-hub/shares/{id}', fn(Request $r) => $phShare->deleteShare($r));

            // Watchers
            $router->get('/project-hub/cards/{id}/watchers', fn(Request $r) => $projectHub->getWatchers($r));
            $router->post('/project-hub/cards/{id}/watchers', fn(Request $r) => $projectHub->addWatcher($r));
            $router->delete('/project-hub/cards/{id}/watchers', fn(Request $r) => $projectHub->removeWatcher($r));

            // Activity Timeline
            $router->get('/project-hub/cards/{id}/activity', fn(Request $r) => $projectHub->getCardActivity($r));

            // Subtask -> Card Promotion (carry assignees + time)
            $router->post('/project-hub/cards/{id}/promote-from-subtask', fn(Request $r) => $projectHub->promoteFromSubtask($r));

            // My Work / Director / Traffic / Notification Prefs
            $router->get('/project-hub/my-work', fn(Request $r) => $phWorkload->getMyWork($r));
            $router->get('/project-hub/my-created', fn(Request $r) => $phWorkload->getMyCreated($r));
            $router->get('/project-hub/director-summary', fn(Request $r) => $phWorkload->getDirectorSummary($r));
            $router->get('/project-hub/workload/traffic', fn(Request $r) => $phWorkload->getWorkloadTraffic($r));
            $router->get('/project-hub/workload/team-schedule', fn(Request $r) => $phWorkload->getTeamSchedule($r));
            $router->get('/project-hub/workload/completions', fn(Request $r) => $phWorkload->getWorkloadCompletions($r));
            $router->get('/project-hub/notification-prefs', fn(Request $r) => $phWorkload->getNotificationPrefs($r));
            $router->put('/project-hub/notification-prefs', fn(Request $r) => $phWorkload->updateNotificationPrefs($r));

            // Card-linked Drive files
            $router->get('/project-hub/cards/{id}/drive-files', fn(Request $r) => $phWorkload->getCardDriveFiles($r));
            $router->get('/project-hub/cards/{id}/client-files', fn(Request $r) => $phWorkload->getCardClientFiles($r));

            // Calendar sync
            $router->get('/project-hub/cards/{id}/calendar-sync', fn(Request $r) => $phWorkload->getCardCalendarSync($r));
            $router->post('/project-hub/cards/{id}/calendar-sync', fn(Request $r) => $phWorkload->enableCardCalendarSync($r));
            $router->delete('/project-hub/cards/{id}/calendar-sync', fn(Request $r) => $phWorkload->disableCardCalendarSync($r));

            // Card Tracked URLs
            $router->get('/project-hub/cards/{id}/tracked-urls', fn(Request $r) => $projectHub->getCardTrackedUrls($r));
            $router->post('/project-hub/cards/{id}/tracked-urls', fn(Request $r) => $projectHub->addCardTrackedUrl($r));
            $router->delete('/project-hub/card-tracked-urls/{id}', fn(Request $r) => $projectHub->deleteCardTrackedUrl($r));
            $router->put('/project-hub/card-tracked-urls/{id}/toggle', fn(Request $r) => $projectHub->toggleCardTrackedUrl($r));

            // Roles & statuses
            $router->get('/project-hub/roles', fn(Request $r) => $phRoles->getRoles($r));
            $router->post('/project-hub/roles', fn(Request $r) => $phRoles->createRole($r));
            $router->put('/project-hub/roles/{id}', fn(Request $r) => $phRoles->updateRole($r));
            $router->delete('/project-hub/roles/{id}', fn(Request $r) => $phRoles->deleteRole($r));
            $router->post('/project-hub/roles/reorder', fn(Request $r) => $phRoles->reorderRoles($r));
            $router->get('/project-hub/roles/{id}/statuses', fn(Request $r) => $phRoles->getRoleStatuses($r));
            $router->post('/project-hub/roles/{id}/statuses', fn(Request $r) => $phRoles->createRoleStatus($r));
            $router->put('/project-hub/role-statuses/{id}', fn(Request $r) => $phRoles->updateRoleStatus($r));
            $router->delete('/project-hub/role-statuses/{id}', fn(Request $r) => $phRoles->deleteRoleStatus($r));
            $router->post('/project-hub/roles/{id}/statuses/reorder', fn(Request $r) => $phRoles->reorderRoleStatuses($r));
            $router->get('/project-hub/users/{email}/roles', fn(Request $r) => $phRoles->getUserRoles($r));
            $router->post('/project-hub/users/{email}/roles', fn(Request $r) => $phRoles->assignUserRole($r));
            $router->delete('/project-hub/users/{email}/roles/{roleId}', fn(Request $r) => $phRoles->removeUserRole($r));

            // Folder Files (Drive-backed)
            $router->get('/project-hub/folders/{id}/files', fn(Request $r) => $phFiles->listFiles($r));
            $router->post('/project-hub/folders/{id}/files', fn(Request $r) => $phFiles->addFile($r));
            $router->put('/project-hub/folder-files/{id}/group', fn(Request $r) => $phFiles->updateGroup($r));
            $router->put('/project-hub/folder-files/batch-group', fn(Request $r) => $phFiles->batchGroup($r));
            $router->delete('/project-hub/folder-files/{id}', fn(Request $r) => $phFiles->removeFile($r));
            $router->post('/project-hub/folders/{id}/files/mark-seen', fn(Request $r) => $phFiles->markSeen($r));
            $router->get('/project-hub/folders/{id}/files/unseen-count', fn(Request $r) => $phFiles->unseenCount($r));
            $router->get('/project-hub/folders/unseen-counts', fn(Request $r) => $phFiles->unseenCountsBatch($r));
            $router->get('/project-hub/folders/{id}/files/export', fn(Request $r) => $phFiles->exportZip($r));
            $router->get('/project-hub/folders/{id}/files/groups', fn(Request $r) => $phFiles->fileGroups($r));

            // Folder Links
            $router->get('/project-hub/folders/{id}/links', fn(Request $r) => $phFiles->listLinks($r));
            $router->post('/project-hub/folders/{id}/links', fn(Request $r) => $phFiles->addLink($r));
            $router->put('/project-hub/folder-links/{id}', fn(Request $r) => $phFiles->updateLink($r));
            $router->delete('/project-hub/folder-links/{id}', fn(Request $r) => $phFiles->deleteLink($r));
        }
    } // end project_hub addon gate

    // =========================================================================
    // Automation Hub Addon Routes (gated)
    // =========================================================================
    $mayAutomationHubRoute = str_contains($requestPath, '/automation-hub');
    if ($mayAutomationHubRoute && (new \Webmail\Services\AddonService($config, $routeGatingEmail))->isAutomationHubEnabled()) {
        $automationHub = new \Webmail\Addons\AutomationHub\Controllers\WorkflowController($config);
        $automationExec = new \Webmail\Addons\AutomationHub\Controllers\WorkflowExecutionController($config);
        $automationRegistry = new \Webmail\Addons\AutomationHub\Controllers\NodeRegistryController($config);

        // Workflow CRUD
        $router->get('/automation-hub/workflows', fn(Request $r) => $automationHub->list($r));
        $router->post('/automation-hub/workflows', fn(Request $r) => $automationHub->create($r));
        $router->get('/automation-hub/workflows/{id}', fn(Request $r) => $automationHub->get($r));
        $router->put('/automation-hub/workflows/{id}', fn(Request $r) => $automationHub->update($r));
        $router->delete('/automation-hub/workflows/{id}', fn(Request $r) => $automationHub->delete($r));
        $router->post('/automation-hub/workflows/{id}/toggle', fn(Request $r) => $automationHub->toggle($r));
        $router->post('/automation-hub/workflows/{id}/duplicate', fn(Request $r) => $automationHub->duplicate($r));

        // Workflow execution
        $router->post('/automation-hub/workflows/{id}/execute', fn(Request $r) => $automationExec->execute($r));
        $router->post('/automation-hub/workflows/{id}/test', fn(Request $r) => $automationExec->test($r));
        $router->get('/automation-hub/workflows/{id}/executions', fn(Request $r) => $automationExec->listExecutions($r));
        $router->get('/automation-hub/executions/{id}', fn(Request $r) => $automationExec->getExecution($r));
        $router->get('/automation-hub/executions/{id}/nodes', fn(Request $r) => $automationExec->getNodeExecutions($r));

        // Node registry
        $router->get('/automation-hub/node-registry', fn(Request $r) => $automationRegistry->list($r));

        // Telegram webhook (no auth — validated by webhook secret)
        $router->post('/automation-hub/telegram/webhook/{token}', fn(Request $r) => $automationExec->telegramWebhook($r));

        // Generic webhook trigger
        $router->post('/automation-hub/webhook/{token}', fn(Request $r) => $automationExec->webhookTrigger($r));

        // CSV export downloads
        $router->get('/automation-hub/exports/{filename}', fn(Request $r) => $automationHub->downloadExport($r));

        // Connections management
        $automationConn = new \Webmail\Addons\AutomationHub\Controllers\ConnectionController($config);
        $router->get('/automation-hub/connections', fn(Request $r) => $automationConn->list($r));
        $router->post('/automation-hub/connections', fn(Request $r) => $automationConn->save($r));
        $router->post('/automation-hub/connections/disconnect', fn(Request $r) => $automationConn->disconnect($r));

        // Trello OAuth
        $trelloConn = new \Webmail\Addons\AutomationHub\Controllers\TrelloConnectionController($config);
        $router->get('/automation-hub/trello/auth-url', fn(Request $r) => $trelloConn->getAuthUrl($r));
        $router->post('/automation-hub/trello/save-token', fn(Request $r) => $trelloConn->saveToken($r));

        // Desktop task queue (for printer and other local hardware tasks)
        $desktopTask = new \Webmail\Addons\AutomationHub\Controllers\DesktopTaskController($config);
        $router->get('/automation-hub/desktop-tasks/pending', fn(Request $r) => $desktopTask->pending($r));
        $router->post('/automation-hub/desktop-tasks/{id}/result', fn(Request $r) => $desktopTask->reportResult($r));
    } // end automation_hub addon gate

    // Client Overview routes
    $clients = new \Webmail\Controllers\ClientController($config);
    $router->get('/clients/init', fn(Request $r) => $clients->init($r));
    $router->get('/clients', fn(Request $r) => $clients->list($r));
    $router->post('/clients/sync', fn(Request $r) => $clients->sync($r));
    $router->post('/clients/merge', fn(Request $r) => $clients->merge($r));
    $router->post('/clients/backfill-aliases', fn(Request $r) => $clients->backfillAliases($r));
    // Note: /clients/time-totals and /clients/time-debug are gated inside Time Tracker addon block below
    // Export and Overview MUST be before {id} wildcard
    $router->get('/clients/export', fn(Request $r) => $clients->export($r));
    $router->post('/clients/import', fn(Request $r) => $clients->import($r));
    $router->post('/clients/manual', fn(Request $r) => $clients->createManual($r));
    $router->get('/clients/overview', fn(Request $r) => $clients->overview($r));
    $router->get('/clients/all-contacts', fn(Request $r) => $clients->allContacts($r));
    // Board mapping for time tracking (gated by kanban_boards addon)
    if ((new \Webmail\Services\AddonService($config, $routeGatingEmail))->isKanbanBoardsEnabled()) {
        $router->get('/clients/board-mapping', fn(Request $r) => $clients->getBoardMapping($r));
    }
    // Folder mapping for time tracking — MUST be before {id} wildcard (gated by time_tracker addon)
    if (str_contains($requestPath, '/folder-mapping') && (new \Webmail\Services\AddonService($config, $routeGatingEmail))->isTimeTrackerEnabled()) {
        $router->get('/clients/folder-mapping', fn(Request $r) => $clients->getFolderMapping($r));
    }
    // Mood board mapping for time tracking (gated by moodboards addon — see route check above)
    // Note: This route is matched by the $mayMoodRoute check and addon gate below
    $router->get('/clients/mood-board-mapping', fn(Request $r) => $clients->getMoodBoardMapping($r));
    // Mind map by email (MUST be before {id} wildcard)
    $router->get('/clients/by-email/{email}/mindmap', fn(Request $r) => $clients->getMindMapByEmail($r));
    $router->get('/clients/{id}', fn(Request $r) => $clients->get($r));
    $router->put('/clients/{id}', fn(Request $r) => $clients->update($r));
    $router->delete('/clients/{id}', fn(Request $r) => $clients->delete($r));
    $router->get('/clients/{id}/threads', fn(Request $r) => $clients->getThreads($r));
    $router->get('/clients/{id}/mindmap', fn(Request $r) => $clients->getMindMap($r));
    $router->get('/clients/{id}/tasks', fn(Request $r) => $clients->getTasks($r));
    $router->get('/clients/{id}/files', fn(Request $r) => $clients->getFiles($r));
    $router->get('/clients/{id}/email-stats', fn(Request $r) => $clients->getEmailStats($r));
    $router->post('/clients/{id}/boards', fn(Request $r) => $clients->linkBoard($r));
    $router->delete('/clients/{id}/boards/{board_id}', fn(Request $r) => $clients->unlinkBoard($r));
    $router->post('/clients/{id}/drive-folder', fn(Request $r) => $clients->linkDriveFolder($r));
    $router->delete('/clients/{id}/drive-folder', fn(Request $r) => $clients->unlinkDriveFolder($r));
    $router->post('/clients/{id}/sync-drive-folder', fn(Request $r) => $clients->syncDriveFolder($r));
    $router->post('/clients/{id}/recalculate', fn(Request $r) => $clients->recalculate($r));
    // Domain aliases (merge tracking)
    $router->get('/clients/{id}/aliases', fn(Request $r) => $clients->getAliases($r));
    $router->delete('/clients/{id}/aliases/{aliasId}', fn(Request $r) => $clients->removeAlias($r));
    // Associated accounts
    $router->get('/clients/{id}/associated', fn(Request $r) => $clients->getAssociated($r));
    $router->post('/clients/{id}/promote', fn(Request $r) => $clients->promote($r));
    $router->post('/clients/{id}/mark-associated', fn(Request $r) => $clients->markAssociated($r));
    // Signature extraction
    $router->post('/clients/{id}/extract-signature', fn(Request $r) => $clients->extractSignature($r));
    $router->post('/clients/{id}/apply-signature', fn(Request $r) => $clients->applySignature($r));
    $router->post('/clients/{id}/extract-contacts', fn(Request $r) => $clients->extractContacts($r));
    // Client financials
    $router->get('/clients/{id}/financials', fn(Request $r) => $clients->getFinancials($r));
    // Client activity log
    $router->get('/clients/{id}/activity', fn(Request $r) => $clients->getActivity($r));
    // Contact management
    $router->post('/clients/{id}/contacts', fn(Request $r) => $clients->addContact($r));
    $router->put('/clients/{id}/contacts/{contactId}', fn(Request $r) => $clients->updateContact($r));
    $router->delete('/clients/{id}/contacts/{contactId}', fn(Request $r) => $clients->deleteContact($r));
    // Team membership
    $router->get('/clients/{id}/members', fn(Request $r) => $clients->getMembers($r));
    $router->post('/clients/{id}/members', fn(Request $r) => $clients->addMember($r));
    $router->put('/clients/{id}/members/{memberEmail}', fn(Request $r) => $clients->updateMember($r));
    $router->delete('/clients/{id}/members/{memberEmail}', fn(Request $r) => $clients->removeMember($r));
    // Note: /clients/{id}/time, time-stats, time-breakdown are gated inside Time Tracker addon block below
    // Drive index (files/folders linked to client)
    $router->get('/clients/{id}/drive-index', fn(Request $r) => $clients->getDriveIndex($r));
    // Client debug/overview endpoint (auth-protected, used by frontend components)
    $router->get('/clients/{id}/debug', fn(Request $r) => $clients->getDebugInfo($r));

    // =========================================================================
    // Time Tracker Addon Routes (gated)
    // =========================================================================
    $mayTimeRoute = str_contains($requestPath, '/time')
        || str_contains($requestPath, '/statistics/track-time');

    if ($mayTimeRoute && (new \Webmail\Services\AddonService($config, $routeGatingEmail))->isTimeTrackerEnabled()) {
    // Statistics time routes
    $router->get('/statistics/time', fn(Request $r) => $statistics->getTimeStats($r));
    $router->post('/statistics/track-time', fn(Request $r) => $statistics->trackTime($r));
    $router->post('/statistics/track-time-batch', fn(Request $r) => $statistics->trackTimeBatch($r));

    // Client time routes (folder-mapping moved before {id} wildcard above)
    $router->get('/clients/time-totals', fn(Request $r) => $clients->getTimeTotals($r));
    $router->get('/clients/time-debug', fn(Request $r) => $clients->timeDebug($r));
    $router->post('/clients/{id}/time', fn(Request $r) => $clients->trackTime($r));
    $router->get('/clients/{id}/time-stats', fn(Request $r) => $clients->getTimeStats($r));
    $router->get('/clients/{id}/time-breakdown', fn(Request $r) => $clients->getTimeBreakdown($r));

    // Time Tracker page
    $time = new \Webmail\Addons\TimeTracker\Controllers\TimeController($config);
    $router->get('/time/my-stats', fn(Request $r) => $time->getMyStats($r));
    $router->get('/time/team-stats', fn(Request $r) => $time->getTeamStats($r));
    $router->get('/time/entity/{type}/{id}', fn(Request $r) => $time->getEntityTime($r));
    } // end Time Tracker addon gate

    // Account routes (multi-account support)
    $accounts = new AccountController($config);
    $router->get('/accounts', fn(Request $r) => $accounts->list($r));
    // Specific routes BEFORE {id} wildcard routes
    $router->get('/accounts/send-addresses', fn(Request $r) => $accounts->getSendAddresses($r));
    $router->get('/accounts/unread-counts', fn(Request $r) => $accounts->getUnreadCounts($r));
    $router->get('/accounts/sync/status', fn(Request $r) => $accounts->syncStatus($r));
    $router->post('/accounts/test', fn(Request $r) => $accounts->test($r));
    $router->post('/accounts/detect', fn(Request $r) => $accounts->detectSettings($r));
    $router->post('/accounts/sync/process-queue', fn(Request $r) => $accounts->processQueue($r));
    $router->post('/accounts/sync/trigger-all', fn(Request $r) => $accounts->triggerSyncAll($r));
    // Account history routes (must be before {id} wildcard)
    $calendarConn = new CalendarConnectionController($config);
    $router->get('/accounts/history', fn(Request $r) => $calendarConn->getHistory($r));
    $router->delete('/accounts/history/{id}', fn(Request $r) => $calendarConn->deleteHistory($r));
    // Routes with {id} parameter
    $router->get('/accounts/{id}', fn(Request $r) => $accounts->get($r));
    $router->post('/accounts', fn(Request $r) => $accounts->create($r));
    $router->put('/accounts/{id}', fn(Request $r) => $accounts->update($r));
    $router->delete('/accounts/{id}', fn(Request $r) => $accounts->delete($r));
    $router->post('/accounts/{id}/default', fn(Request $r) => $accounts->setDefault($r));
    $router->post('/accounts/{id}/sync', fn(Request $r) => $accounts->triggerSync($r));
    
    // Google OAuth routes
    // NOTE: /auth/google/connect was removed - it backed the silent re-consent popup
    // which has been replaced by a hard-redirect to /login on oauth_reauth_required.
    $router->get('/auth/google', fn(Request $r) => $accounts->googleAuthUrl($r));
    $router->get('/auth/google/callback', fn(Request $r) => $accounts->googleCallback($r));
    // Phase 3 (orphan cleanup): POST /auth/google/callback removed (no frontend caller).
    $router->delete('/accounts/oauth/{id}', fn(Request $r) => $accounts->deleteOAuthAccount($r));
    
    // Microsoft OAuth routes (for adding Outlook accounts)
    // NOTE: /auth/microsoft/connect was removed alongside /auth/google/connect.
    $router->get('/auth/microsoft/enabled', fn(Request $r) => $auth->microsoftEnabled($r));
    $router->get('/auth/microsoft/login', fn(Request $r) => $auth->microsoftLoginUrl($r));
    $router->get('/auth/microsoft/login/callback', fn(Request $r) => $auth->microsoftLoginCallback($r));
    $router->get('/auth/microsoft', fn(Request $r) => $accounts->microsoftAuthUrl($r));
    $router->get('/auth/microsoft/callback', fn(Request $r) => $accounts->microsoftCallback($r));
    // Phase 3 (orphan cleanup): POST /auth/microsoft/callback removed (no frontend caller).

    // Drive routes (file storage)
    $drive = new DriveController($config);
    $router->get('/drive', fn(Request $r) => $drive->list($r));
    $router->get('/drive/quota', fn(Request $r) => $drive->quota($r));
    $router->get('/drive/search', fn(Request $r) => $drive->search($r));

    // Phase 8: Storage signals (budget + reclaim daemon + backup state).
    // Read-only window over FlowOne\Storage state files; no business
    // logic lives here.
    $storage = new StorageController($config);
    $router->get('/storage/status',              fn(Request $r) => $storage->status($r));
    $router->get('/storage/files/{id}/tier',     fn(Request $r) => $storage->fileTier($r));
    $router->get('/admin/storage/dashboard',     fn(Request $r) => $storage->adminDashboard($r));
    $router->get('/admin/storage/infra',         fn(Request $r) => $storage->adminInfra($r));

    // Storage operator control plane (admin gate inside the methods).
    // Flag-file ops are synchronous; trigger ops queue into state.dir/requests
    // for the dispatcher cron to execute as flowone-storage.
    $router->post('/admin/storage/reclaim/pause',  fn(Request $r) => $storage->reclaimPause($r));
    $router->post('/admin/storage/reclaim/resume', fn(Request $r) => $storage->reclaimResume($r));
    $router->post('/admin/storage/reclaim/cycle',  fn(Request $r) => $storage->triggerCycle($r));
    $router->post('/admin/storage/backup/pause',   fn(Request $r) => $storage->backupPause($r));
    $router->post('/admin/storage/backup/resume',  fn(Request $r) => $storage->backupResume($r));
    $router->post('/admin/storage/backup/snapshot',fn(Request $r) => $storage->triggerSnapshot($r));
    $router->post('/admin/storage/backup/verify',  fn(Request $r) => $storage->triggerVerify($r));
    $router->post('/admin/storage/backup/drill',   fn(Request $r) => $storage->triggerDrill($r));
    $router->post('/admin/storage/freeze',         fn(Request $r) => $storage->freeze($r));
    $router->post('/admin/storage/unfreeze',       fn(Request $r) => $storage->unfreeze($r));
    $router->get('/drive/folders/all', fn(Request $r) => $drive->allFolders($r));
    $router->get('/drive/find-client', fn(Request $r) => $drive->findClientByEmail($r));
    $router->post('/drive/recalculate-sizes', fn(Request $r) => $drive->recalculateFolderSizes($r));
    $router->post('/drive/save-attachment', fn(Request $r) => $drive->saveAttachment($r));
    $router->get('/drive/email-attachments-status', fn(Request $r) => $drive->emailAttachmentsStatus($r));
    $router->post('/drive/email-attachments-status', fn(Request $r) => $drive->emailAttachmentsStatus($r));
    $router->post('/drive/folders', fn(Request $r) => $drive->createFolder($r));
    $router->post('/drive/board-folder', fn(Request $r) => $drive->getBoardFolder($r));
    $router->put('/drive/folders/{id}', fn(Request $r) => $drive->renameFolder($r));
    $router->put('/drive/folders/{id}/color', fn(Request $r) => $drive->updateFolderColor($r));
    $router->post('/drive/folders/{id}/move', fn(Request $r) => $drive->moveFolder($r));
    $router->post('/drive/folders/{id}/copy', fn(Request $r) => $drive->copyFolder($r));
    $router->delete('/drive/folders/{id}', fn(Request $r) => $drive->deleteFolder($r));
    $router->post('/drive/batch-delete', fn(Request $r) => $drive->batchDelete($r));
    $router->post('/drive/batch-move', fn(Request $r) => $drive->batchMove($r));
    $router->post('/drive/batch-trash', fn(Request $r) => $drive->batchTrash($r));
    $router->post('/drive/batch-restore', fn(Request $r) => $drive->batchRestore($r));
    $router->post('/drive/upload', fn(Request $r) => $drive->upload($r));
    $router->get('/drive/files/{id}', fn(Request $r) => $drive->getFile($r));
    $router->get('/drive/files/{id}/download', fn(Request $r) => $drive->download($r));
    $router->get('/drive/files/{id}/download-token', fn(Request $r) => $drive->downloadToken($r));
    $router->get('/drive/files/{id}/preview', fn(Request $r) => $drive->preview($r));
    $router->get('/drive/files/{id}/thumbnail', fn(Request $r) => $drive->thumbnail($r));
    $router->get('/drive/download-zip', fn(Request $r) => $drive->downloadZip($r));
    $router->post('/drive/create-archive', fn(Request $r) => $drive->createArchive($r));  // Create ZIP stored in Drive with 1GB splitting
    $router->get('/drive/download-files-zip', fn(Request $r) => $drive->downloadFilesZip($r));
    $router->post('/drive/download-files-zip', fn(Request $r) => $drive->downloadFilesZip($r));
    $router->get('/drive/download-selection-zip', fn(Request $r) => $drive->downloadSelectionZip($r));
    // Debug/test endpoints (only available when APP_DEBUG=true)
    if (getenv('APP_DEBUG') === 'true') {
        $router->get('/drive/test-zip-download', fn(Request $r) => $drive->testZipDownload($r));
        $router->get('/drive/debug-folder', fn(Request $r) => $drive->debugFolderDownload($r));
        $router->get('/drive/zip-debug', fn(Request $r) => $drive->getZipDebug($r));
    }
    $router->put('/drive/files/{id}', fn(Request $r) => $drive->renameFile($r));
    $router->post('/drive/files/{id}/move', fn(Request $r) => $drive->moveFile($r));
    $router->post('/drive/files/{id}/copy', fn(Request $r) => $drive->copyFile($r));
    $router->delete('/drive/files/{id}', fn(Request $r) => $drive->deleteFile($r));
    $router->post('/drive/files/{id}/share', fn(Request $r) => $drive->share($r));
    $router->get('/drive/files/{id}/share', fn(Request $r) => $drive->getShare($r));
    $router->delete('/drive/files/{id}/share', fn(Request $r) => $drive->unshare($r));
    $router->post('/drive/files/{id}/share/notify', fn(Request $r) => $drive->notifyShare($r));
    $router->get('/drive/share/{token}', fn(Request $r) => $drive->publicDownload($r));
    
    // Folder sharing routes
    $router->post('/drive/folders/{id}/share', fn(Request $r) => $drive->shareFolder($r));
    $router->get('/drive/folders/{id}/share', fn(Request $r) => $drive->getFolderShare($r));
    $router->delete('/drive/folders/{id}/share', fn(Request $r) => $drive->unshareFolder($r));
    $router->post('/drive/folders/{id}/share/notify', fn(Request $r) => $drive->notifyFolderShare($r));
    $router->get('/drive/folder-share/{token}', fn(Request $r) => $drive->publicFolderView($r));
    $router->get('/drive/folder-share/{token}/subfolder/{subfolder_id}', fn(Request $r) => $drive->publicSubfolderView($r));
    $router->get('/drive/folder-share/{token}/file/{file_id}', fn(Request $r) => $drive->publicFolderFileDownload($r));
    $router->get('/drive/folder-share/{token}/zip', fn(Request $r) => $drive->publicFolderZipDownload($r));
    $router->post('/drive/folder-share/{token}/zip', fn(Request $r) => $drive->publicFolderZipDownload($r));
    
    // Trash routes
    $router->get('/drive/trash', fn(Request $r) => $drive->listTrash($r));
    $router->delete('/drive/trash', fn(Request $r) => $drive->emptyTrash($r));
    $router->delete('/drive/trash/{type}/{id}', fn(Request $r) => $drive->permanentlyDelete($r));
    $router->post('/drive/files/{id}/trash', fn(Request $r) => $drive->trashFile($r));
    $router->post('/drive/folders/{id}/trash', fn(Request $r) => $drive->trashFolder($r));
    $router->post('/drive/files/{id}/restore', fn(Request $r) => $drive->restoreFile($r));
    $router->post('/drive/folders/{id}/restore', fn(Request $r) => $drive->restoreFolder($r));
    
    // File versioning routes
    $router->post('/drive/upload-versioned', fn(Request $r) => $drive->uploadVersioned($r));
    // Chunked/resumable upload (for files larger than the ~2GB LSAPI body limit)
    $router->post('/drive/upload-chunk', fn(Request $r) => $drive->uploadChunk($r));
    $router->get('/drive/upload-chunk/status', fn(Request $r) => $drive->uploadChunkStatus($r));

    // Version history (DriveVersionsController owns the version lifecycle)
    $driveVersions = new DriveVersionsController($config);
    $router->get('/drive/versions/usage', fn(Request $r) => $driveVersions->versionsUsage($r));
    $router->post('/drive/versions/cleanup', fn(Request $r) => $driveVersions->cleanupAllVersions($r));
    $router->get('/drive/files/{id}/versions', fn(Request $r) => $driveVersions->getVersions($r));
    $router->post('/drive/files/{id}/versions/cleanup', fn(Request $r) => $driveVersions->cleanupFileVersions($r));
    $router->post('/drive/files/{id}/versions/{versionId}/restore', fn(Request $r) => $driveVersions->restoreVersion($r));
    $router->delete('/drive/files/{id}/versions/{versionId}', fn(Request $r) => $driveVersions->deleteVersion($r));
    $router->patch('/drive/files/{id}/versions/{versionId}', fn(Request $r) => $driveVersions->updateVersion($r));
    $router->post('/drive/files/{id}/versions/{versionId}/pin', fn(Request $r) => $driveVersions->pinVersion($r));
    $router->get('/drive/files/{id}/versions/{versionId}/download', fn(Request $r) => $driveVersions->downloadVersion($r));
    $router->get('/drive/files/{id}/versions/{versionId}/preview', fn(Request $r) => $driveVersions->previewVersion($r));
    // Desktop pre-overwrite snapshot (direct NAS write keeps history real)
    $router->post('/drive/files/{id}/versions/snapshot', fn(Request $r) => $driveVersions->snapshotVersion($r));
    
    // Activity tracking routes
    $router->post('/drive/files/{id}/access', fn(Request $r) => $drive->recordAccess($r));
    $router->post('/drive/folders/{id}/access', fn(Request $r) => $drive->recordFolderAccess($r));
    $router->get('/drive/files/{id}/details', fn(Request $r) => $drive->getFileDetails($r));

    // View-only restrictions (no download / no print) + open history
    $router->get('/drive/files/{id}/restrictions', fn(Request $r) => $drive->getRestrictions($r));
    $router->patch('/drive/files/{id}/restrictions', fn(Request $r) => $drive->updateRestrictions($r));
    $router->get('/drive/files/{id}/access-log', fn(Request $r) => $drive->getAccessLog($r));

    // Starred + Recent
    $router->post('/drive/{type}/{id}/star', fn(Request $r) => $drive->toggleStar($r));
    $router->get('/drive/starred', fn(Request $r) => $drive->listStarred($r));
    $router->get('/drive/recent', fn(Request $r) => $drive->listRecent($r));
    
    // Enhanced sharing routes
    $router->put('/drive/files/{id}/share', fn(Request $r) => $drive->updateShare($r));
    $router->get('/drive/share/{token}/info', fn(Request $r) => $drive->getShareInfo($r));
    $router->post('/drive/share/{token}/validate', fn(Request $r) => $drive->validateSharePassword($r));

    $phSharePublic = new \Webmail\Addons\ProjectHub\Controllers\ProjectHubShareController($config);
    $router->get('/project-hub/share/{token}/info', fn(Request $r) => $phSharePublic->publicShareInfo($r));
    $router->post('/project-hub/share/{token}/validate', fn(Request $r) => $phSharePublic->publicShareValidate($r));
    $router->get('/project-hub/share/{token}/download/{fid}', fn(Request $r) => $phSharePublic->publicShareDownload($r));
    
    // Folder collaborators routes
    $router->post('/drive/folders/{id}/collaborators', fn(Request $r) => $drive->addCollaborator($r));
    $router->delete('/drive/folders/{id}/collaborators/{email}', fn(Request $r) => $drive->removeCollaborator($r));
    $router->put('/drive/folders/{id}/collaborators/{email}', fn(Request $r) => $drive->updateCollaborator($r));
    $router->get('/drive/folders/{id}/collaborators', fn(Request $r) => $drive->getCollaborators($r));
    
    // Folder group access
    $router->get('/drive/folders/{id}/group-access', fn(Request $r) => $drive->getGroupAccess($r));
    $router->delete('/drive/folders/{id}/group-access/{groupId}', fn(Request $r) => $drive->removeGroupAccess($r));

    // File-level sharing (people + groups)
    $fileShare = new \Webmail\Controllers\DriveFileShareController($config);
    $router->get('/drive/files/{id}/collaborators', fn(Request $r) => $fileShare->getCollaborators($r));
    $router->post('/drive/files/{id}/collaborators', fn(Request $r) => $fileShare->addCollaborator($r));
    $router->put('/drive/files/{id}/collaborators/{email}', fn(Request $r) => $fileShare->updateCollaborator($r));
    $router->delete('/drive/files/{id}/collaborators/{email}', fn(Request $r) => $fileShare->removeCollaborator($r));
    $router->get('/drive/files/{id}/group-access', fn(Request $r) => $fileShare->getGroupAccess($r));
    $router->post('/drive/files/{id}/group-access', fn(Request $r) => $fileShare->addGroupAccess($r));
    $router->delete('/drive/files/{id}/group-access/{groupId}', fn(Request $r) => $fileShare->removeGroupAccess($r));
    // Download/preview for files shared directly with me (person or group share)
    $router->get('/drive/shared-files/{id}/download', fn(Request $r) => $fileShare->download($r));
    $router->get('/drive/shared-files/{id}/preview', fn(Request $r) => $fileShare->preview($r));

    // Office editor (OnlyOffice Document Server integration)
    $officeEditor = new OfficeEditorController($config);
    $router->get('/office/status', fn(Request $r) => $officeEditor->status($r));
    $router->get('/office/files/{id}/config', fn(Request $r) => $officeEditor->getConfig($r));
    $router->get('/office/files/{id}/presence-token', fn(Request $r) => $officeEditor->presenceToken($r));
    $router->post('/office/files/new', fn(Request $r) => $officeEditor->createFile($r));
    $router->put('/office/files/{id}/name', fn(Request $r) => $officeEditor->renameFile($r));
    $router->get('/office/files/{id}/guest-links', fn(Request $r) => $officeEditor->listGuestLinks($r));
    $router->post('/office/files/{id}/guest-links', fn(Request $r) => $officeEditor->createGuestLink($r));
    $router->delete('/office/guest-links/{token}', fn(Request $r) => $officeEditor->revokeGuestLink($r));
    // Document Server server-to-server endpoints (signed file tokens, no user JWT)
    $router->get('/office/files/{id}/content', fn(Request $r) => $officeEditor->content($r));
    $router->post('/office/files/{id}/callback', fn(Request $r) => $officeEditor->callback($r));
    // Guest office access (public - token IS the auth, like guest calls)
    $router->get('/guest/office/{token}/config', fn(Request $r) => $officeEditor->guestConfig($r));
    $router->get('/guest/office/{token}/presence-token', fn(Request $r) => $officeEditor->guestPresenceToken($r));

    
    // Shared with me routes
    $router->get('/drive/shared-with-me', fn(Request $r) => $drive->getSharedWithMe($r));
    $router->get('/drive/shared/{folderId}', fn(Request $r) => $drive->getSharedFolderContents($r));
    $router->get('/drive/shared/{folderId}/subfolder/{subfolderId}', fn(Request $r) => $drive->getSharedSubfolderContents($r));
    $router->get('/drive/shared/{folderId}/file/{fileId}/download', fn(Request $r) => $drive->downloadSharedFile($r));
    $router->get('/drive/shared/{folderId}/file/{fileId}/preview', fn(Request $r) => $drive->previewSharedFile($r));
    $router->post('/drive/shared/{folderId}/folders', fn(Request $r) => $drive->createFolderInSharedFolder($r));
    $router->post('/drive/shared/{folderId}/upload', fn(Request $r) => $drive->uploadToSharedFolder($r));
    $router->delete('/drive/shared/files/{id}', fn(Request $r) => $drive->deleteFromSharedFolder($r));
    
    // Cleanup expired email attachments (can be called by cron)
    $router->post('/drive/cleanup', fn(Request $r) => $drive->cleanup($r));
    
    // Sync events for real-time notifications and activity log
    $router->get('/drive/sync-events', fn(Request $r) => $drive->getSyncEvents($r));
    $router->post('/drive/sync-events', fn(Request $r) => $drive->recordSyncEvent($r));
    $router->delete('/drive/sync-events/{id}', fn(Request $r) => $drive->deleteSyncEvent($r));
    $router->delete('/drive/sync-events', fn(Request $r) => $drive->clearSyncEvents($r));
    
    // File editing status (who is currently editing a file)
    $router->get('/drive/editing-status', fn(Request $r) => $drive->getEditingStatus($r));
    $router->post('/drive/editing-status', fn(Request $r) => $drive->setEditingStatus($r));
    $router->delete('/drive/editing-status', fn(Request $r) => $drive->clearEditingStatus($r));
    $router->get('/drive/editing-status/shared', fn(Request $r) => $drive->getSharedEditingStatus($r));
    $router->post('/drive/editing-status/heartbeat', fn(Request $r) => $drive->heartbeatEditingStatus($r));
    
    // NAS Direct Access (for desktop clients to access NAS directly when on same network)
    $router->get('/drive/connection-config', fn(Request $r) => $drive->getConnectionConfig($r));
    $router->post('/drive/files/register', fn(Request $r) => $drive->registerFile($r));
    $router->put('/drive/files/{id}/metadata', fn(Request $r) => $drive->updateFileMetadata($r));

    // Sharing & Access overview (centralized sharing dashboard)
    $sharing = new SharingController($config);
    $router->get('/sharing/overview', fn(Request $r) => $sharing->overview($r));
    $router->delete('/sharing/revoke', fn(Request $r) => $sharing->revoke($r));
    $router->put('/sharing/update-role', fn(Request $r) => $sharing->updateRole($r));

    // Desktop Sync routes (for FlowOneDrive desktop app)
    $sync = new SyncController($config);
    $router->get('/sync/changes', fn(Request $r) => $sync->getChanges($r));
    $router->get('/sync/status', fn(Request $r) => $sync->getStatus($r));
    $router->post('/sync/upload', fn(Request $r) => $sync->upload($r));
    $router->post('/sync/conflicts', fn(Request $r) => $sync->getConflicts($r));
    $router->post('/sync/checksums', fn(Request $r) => $sync->updateChecksums($r));
    $router->get('/sync/shared-activity', fn(Request $r) => $sync->getSharedActivity($r));

    // =========================================================================
    // Calendar Addon Routes (gated - only registered when addon is enabled)
    // =========================================================================
    $mayCalendarRoute = str_contains($requestPath, '/calendars')
        || str_contains($requestPath, '/events')
        || str_contains($requestPath, '/calendar/')
        || str_contains($requestPath, '/meetings')
        || str_contains($requestPath, '/statistics/calendar');

    if ($mayCalendarRoute && (new \Webmail\Services\AddonService($config, $routeGatingEmail))->isCalendarEnabled()) {
    $calendar = new CalendarController($config);
    $router->get('/calendars', fn(Request $r) => $calendar->listCalendars($r));
    $router->get('/calendars/{id}', fn(Request $r) => $calendar->getCalendar($r));
    $router->post('/calendars', fn(Request $r) => $calendar->createCalendar($r));
    $router->put('/calendars/{id}', fn(Request $r) => $calendar->updateCalendar($r));
    $router->delete('/calendars/{id}', fn(Request $r) => $calendar->deleteCalendar($r));
    $router->get('/calendars/{id}/export', fn(Request $r) => $calendar->exportICS($r));
    $router->post('/calendar/import', fn(Request $r) => $calendar->importICS($r));
    $router->get('/calendars/{id}/subscription', fn(Request $r) => $calendar->getSubscription($r));
    $router->post('/calendars/{id}/subscription/regenerate', fn(Request $r) => $calendar->regenerateSubscription($r));
    // Calendar sharing
    $router->get('/calendars/{id}/shares', fn(Request $r) => $calendar->getCalendarShares($r));
    $router->post('/calendars/{id}/share', fn(Request $r) => $calendar->shareCalendar($r));
    $router->delete('/calendars/{id}/share', fn(Request $r) => $calendar->unshareCalendar($r));
    // Public subscription endpoint (no auth - token IS the auth)
    $router->get('/calendar/subscribe/{token}', fn(Request $r) => $calendar->subscribeICS($r));
    $router->get('/events', fn(Request $r) => $calendar->listEvents($r));
    $router->get('/events/invitations', fn(Request $r) => $calendar->getMyInvitations($r));
    $router->get('/events/{id}', fn(Request $r) => $calendar->getEvent($r));
    $router->post('/events', fn(Request $r) => $calendar->createEvent($r));
    $router->post('/events/quick', fn(Request $r) => $calendar->quickAdd($r));
    $router->put('/events/{id}', fn(Request $r) => $calendar->updateEvent($r));
    $router->delete('/events/all', fn(Request $r) => $calendar->deleteAllEvents($r));
    $router->delete('/events/{id}', fn(Request $r) => $calendar->deleteEvent($r));
    
    // Event participants/invitations
    $router->get('/events/{id}/participants', fn(Request $r) => $calendar->getParticipants($r));
    $router->post('/events/{id}/invite', fn(Request $r) => $calendar->inviteParticipants($r));
    $router->delete('/events/{id}/participants/{email}', fn(Request $r) => $calendar->removeParticipant($r));
    $router->post('/events/invitations/{token}/respond', fn(Request $r) => $calendar->respondToInvitationApi($r));
    // Public invitation response (from email links - no auth required)
    $router->get('/calendar/invite/{token}/{response}', fn(Request $r) => $calendar->respondToInvitation($r));
    
    // Meetings (scheduled calls with chat + meeting link)
    $router->post('/meetings', fn(Request $r) => $calendar->createMeeting($r));
    $router->get('/meetings/{token}', fn(Request $r) => $calendar->getMeetingByToken($r));
    // Upgrade an EXISTING calendar event into a meeting (generate
    // meeting token + guest/admin links). Idempotent: if the event
    // already has a meeting, returns the existing links. Pass
    // { force: true } to revoke and recreate the links.
    $router->post('/events/{id}/add-meeting', fn(Request $r) => $calendar->addMeetingToEvent($r));
    // Read the meeting links (guest + host/admin) + waiting-room state
    // for an event that is already a meeting. Read-only / idempotent.
    $router->get('/events/{id}/meeting', fn(Request $r) => $calendar->getEventMeetingLinks($r));
    
    // Google Calendar sync routes (OAuth accounts)
    $router->get('/calendar/google/calendars', fn(Request $r) => $calendar->getGoogleCalendars($r));
    $router->get('/calendar/google/sync', fn(Request $r) => $calendar->getGoogleSyncConfigs($r));
    $router->post('/calendar/google/sync', fn(Request $r) => $calendar->setupGoogleSync($r));
    $router->post('/calendar/google/sync-batch', fn(Request $r) => $calendar->setupGoogleSyncBatch($r));
    $router->delete('/calendar/google/sync', fn(Request $r) => $calendar->disableGoogleSync($r));
    $router->post('/calendar/google/sync/pull', fn(Request $r) => $calendar->syncFromGoogle($r));
    $router->post('/calendar/google/sync-pull-batch', fn(Request $r) => $calendar->syncFromGoogleBatchEndpoint($r));
    $router->post('/calendar/google/sync/push', fn(Request $r) => $calendar->syncToGoogle($r));
    
    // Desync with options (keep or delete events)
    $router->post('/calendar/google/desync', fn(Request $r) => $calendar->desyncWithOptions($r));
    $router->get('/calendar/google/sync/events-count', fn(Request $r) => $calendar->getSyncedEventsCount($r));
    
    // Microsoft Calendar Sync
    $router->get('/calendar/microsoft/calendars', fn(Request $r) => $calendar->getMicrosoftCalendars($r));
    $router->get('/calendar/microsoft/sync', fn(Request $r) => $calendar->getMicrosoftSyncConfigs($r));
    $router->post('/calendar/microsoft/sync', fn(Request $r) => $calendar->setupMicrosoftSync($r));
    $router->post('/calendar/microsoft/sync-batch', fn(Request $r) => $calendar->setupMicrosoftSyncBatch($r));
    $router->post('/calendar/microsoft/sync/pull', fn(Request $r) => $calendar->pullFromMicrosoftCalendar($r));
    $router->post('/calendar/microsoft/sync-pull-batch', fn(Request $r) => $calendar->pullFromMicrosoftCalendarBatch($r));
    $router->post('/calendar/microsoft/desync', fn(Request $r) => $calendar->desyncMicrosoft($r));
    $router->get('/calendar/microsoft/sync/events-count', fn(Request $r) => $calendar->getMicrosoftSyncedEventsCount($r));
    
    // Calendar-only connections (no email access) - uses $calendarConn from above
    $router->get('/calendar/connections', fn(Request $r) => $calendarConn->list($r));
    $router->get('/calendar/connections/auth', fn(Request $r) => $calendarConn->getAuthUrl($r));
    // Phase 2.6: dead GET /calendar/connections/callback removed. The Google
    // OAuth redirect_uri points to /api/auth/google/callback which routes
    // through AccountController::googleCallback; that method dispatches the
    // calendar-only flow via handleCalendarOnlyCallback(). No production
    // configuration referenced this endpoint.
    // Phase 3 (orphan cleanup): POST /calendar/connections/callback removed
    // alongside CalendarConnectionController::callbackPost (no frontend caller).
    $router->get('/calendar/connections/calendars', fn(Request $r) => $calendarConn->getCalendars($r));
    $router->post('/calendar/connections/sync', fn(Request $r) => $calendarConn->setupSync($r));
    $router->post('/calendar/connections/sync-batch', fn(Request $r) => $calendarConn->setupSyncBatch($r));
    $router->post('/calendar/connections/sync/pull', fn(Request $r) => $calendarConn->syncFromGoogle($r));
    $router->post('/calendar/connections/sync-pull-batch', fn(Request $r) => $calendarConn->syncFromGoogleBatch($r));
    $router->post('/calendar/connections/desync', fn(Request $r) => $calendarConn->disableSync($r));
    $router->get('/calendar/connections/sync/events-count', fn(Request $r) => $calendarConn->getSyncedEventsCount($r));
    $router->delete('/calendar/connections/{id}', fn(Request $r) => $calendarConn->delete($r));

    // Statistics: calendar
    $router->get('/statistics/calendar', fn(Request $r) => $statistics->getCalendarStats($r));
    } // end Calendar addon gate

    // Tracking pixel always available (must not break already-sent emails)
    $tracking = new TrackingController($config);
    $router->get('/track/{id}', fn(Request $r) => $tracking->pixel($r));

    // Click tracking redirect (public, no auth -- must not break already-sent links)
    $router->get('/click/{linkToken}/{recipientToken}', fn(Request $r) => $tracking->clickRedirect($r));

    // Unsubscribe endpoints (public, no auth -- must not break already-sent emails)
    $unsub = new UnsubscribeController($config);
    $router->get('/unsubscribe/{token}', fn(Request $r) => $unsub->showPage($r));
    $router->post('/unsubscribe/{token}', fn(Request $r) => $unsub->handleUnsubscribe($r));

    // Guest Call (public, no auth -- token IS the auth, like magic links)
    $guestCall = new GuestCallController($config);
    $router->get('/guest/call/{token}/info', fn(Request $r) => $guestCall->getInfo($r));
    $router->head('/guest/call/{token}/info', fn(Request $r) => $guestCall->getInfo($r));
    $router->post('/guest/call/{token}/join', fn(Request $r) => $guestCall->join($r));
    $router->post('/guest/call/{token}/transcript', fn(Request $r) => $guestCall->saveTranscript($r));
    $router->post('/guest/call/{token}/attachments', fn(Request $r) => $guestCall->uploadAttachment($r));
    $router->get('/guest/call/{token}/attachments/{id}', fn(Request $r) => $guestCall->downloadAttachment($r));
    $router->post('/guest/call/{token}/kick', fn(Request $r) => $guestCall->kickParticipant($r));
    $router->post('/guest/call/{token}/revoke-room', fn(Request $r) => $guestCall->revokeRoom($r));
    $router->get('/guest/call/{token}/attendees', fn(Request $r) => $guestCall->listAttendees($r));
    $router->get('/guest/call/{token}/admission/{id}', fn(Request $r) => $guestCall->getAdmissionStatus($r));
    $router->post('/guest/call/admission/{id}/approve', fn(Request $r) => $guestCall->approveAdmission($r));
    $router->post('/guest/call/admission/{id}/deny', fn(Request $r) => $guestCall->denyAdmission($r));
    $router->get('/guest/call/lobby', fn(Request $r) => $guestCall->listAdmissionLobby($r));

    // Notifications routes always available (serve all notification types, not just read receipts)
    $router->get('/notifications', fn(Request $r) => $tracking->listNotifications($r));

    // =========================================================================
    // Email Tracking Addon Routes (gated - listing tracked emails)
    // =========================================================================
    $mayTrackingRoute = str_contains($requestPath, '/tracking');

    if ($mayTrackingRoute && (new \Webmail\Services\AddonService($config, $routeGatingEmail))->isEmailTrackingEnabled()) {
    $router->get('/tracking', fn(Request $r) => $tracking->listTracked($r));
    $router->get('/tracking/links', fn(Request $r) => $tracking->getTrackedLinks($r));
    $router->get('/tracking/{id}', fn(Request $r) => $tracking->getTracking($r));
    $router->get('/tracking/{id}/clicks', fn(Request $r) => $tracking->getClickStats($r));
    $router->get('/tracking/{id}/locate', fn(Request $r) => $tracking->locate($r));
    } // end Email Tracking addon gate
    $router->get('/notifications/count', fn(Request $r) => $tracking->unreadCount($r));
    $router->post('/notifications', fn(Request $r) => $tracking->createNotification($r));
    $router->post('/notifications/consolidate', fn(Request $r) => $tracking->consolidateNotifications($r));
    $router->post('/notifications/{id}/read', fn(Request $r) => $tracking->markRead($r));
    $router->post('/notifications/{id}/pin', fn(Request $r) => $tracking->togglePin($r));
    $router->put('/notifications/{id}/pin', fn(Request $r) => $tracking->setPinned($r));
    $router->post('/notifications/read-all', fn(Request $r) => $tracking->markAllRead($r));
    $router->delete('/notifications/{id}', fn(Request $r) => $tracking->deleteNotification($r));
    $router->delete('/notifications', fn(Request $r) => $tracking->clearAll($r));

    // Contacts routes (autocomplete)
    $contacts = new ContactsController($config);
    $router->get('/contacts/search', fn(Request $r) => $contacts->search($r));
    $router->get('/contacts/recent', fn(Request $r) => $contacts->recent($r));
    $router->post('/contacts/import', fn(Request $r) => $contacts->import($r));
    $router->post('/contacts/save', fn(Request $r) => $contacts->save($r));
    $router->delete('/contacts/{email}', fn(Request $r) => $contacts->delete($r));

    // Full address book (real CardDAV-ready contacts store + VCF/CSV import).
    // Distinct from the /contacts/* autocomplete cache above.
    $addressBook = new AddressBookController($config);
    $router->get('/address-books', fn(Request $r) => $addressBook->listBooks($r));
    $router->post('/address-books', fn(Request $r) => $addressBook->createBook($r));
    $router->put('/address-books/{id}', fn(Request $r) => $addressBook->updateBook($r));
    $router->delete('/address-books/{id}', fn(Request $r) => $addressBook->deleteBook($r));
    $router->get('/address-book/contacts', fn(Request $r) => $addressBook->listContacts($r));
    $router->post('/address-book/contacts', fn(Request $r) => $addressBook->createContact($r));
    $router->get('/address-book/contacts/{id}', fn(Request $r) => $addressBook->getContact($r));
    $router->put('/address-book/contacts/{id}', fn(Request $r) => $addressBook->updateContact($r));
    $router->delete('/address-book/contacts/{id}', fn(Request $r) => $addressBook->deleteContact($r));
    $router->post('/address-book/import', fn(Request $r) => $addressBook->import($r));
    $router->get('/address-book/export', fn(Request $r) => $addressBook->export($r));

    // =========================================================================
    // Colleagues routes (read-only + personal always available; management gated by Team addon)
    // =========================================================================
    $colleagues = new ColleagueController($config);

    // --- Always available: read-only routes + personal profile management ---
    $router->get('/colleagues', fn(Request $r) => $colleagues->list($r));
    $router->get('/colleagues/me', fn(Request $r) => $colleagues->getMe($r));
    $router->put('/colleagues/me', fn(Request $r) => $colleagues->updateMe($r));
    $router->post('/colleagues/me/avatar', fn(Request $r) => $colleagues->uploadAvatar($r));
    $router->delete('/colleagues/me/avatar', fn(Request $r) => $colleagues->deleteAvatar($r));
    $router->get('/colleagues/avatar/{filename}', fn(Request $r) => $colleagues->serveAvatar($r));
    $router->put('/colleagues/me/status', fn(Request $r) => $colleagues->updateMyStatus($r));
    $router->get('/colleagues/me/permissions', fn(Request $r) => $colleagues->getMyPermissions($r));
    $router->get('/colleagues/folder-access', fn(Request $r) => $colleagues->getGroupFolderAccess($r));
    // Read-only group routes (used by sharing modals, compose, etc.)
    $router->get('/colleagues/groups', fn(Request $r) => $colleagues->listGroups($r));
    $router->get('/colleagues/groups/{id}', fn(Request $r) => $colleagues->getGroup($r));
    $router->get('/colleagues/groups/{id}/members', fn(Request $r) => $colleagues->listGroupMembers($r));
    // Read single colleague (used by other features for display)
    $router->get('/colleagues/{id}', fn(Request $r) => $colleagues->get($r));

    // --- Team management routes (gated by Team addon) ---
    $mayTeamManage = str_contains($requestPath, '/colleagues')
        && !str_contains($requestPath, '/colleagues/me')
        && !str_contains($requestPath, '/colleagues/avatar');

    if ($mayTeamManage && (new \Webmail\Services\AddonService($config, $routeGatingEmail))->isTeamEnabled()) {
        // Create/sync colleagues
        $router->post('/colleagues', fn(Request $r) => $colleagues->create($r));
        $router->post('/colleagues/sync', fn(Request $r) => $colleagues->sync($r));
        // Group management
        $router->post('/colleagues/groups', fn(Request $r) => $colleagues->createGroup($r));
        $router->put('/colleagues/groups/{id}', fn(Request $r) => $colleagues->updateGroup($r));
        $router->delete('/colleagues/groups/{id}', fn(Request $r) => $colleagues->deleteGroup($r));
        $router->post('/colleagues/groups/{id}/members', fn(Request $r) => $colleagues->addGroupMembers($r));
        $router->delete('/colleagues/groups/{groupId}/members/{colleagueId}', fn(Request $r) => $colleagues->removeGroupMember($r));
        // Group sharing permissions
        $router->post('/colleagues/groups/{id}/share/folder', fn(Request $r) => $colleagues->shareFolderWithGroup($r));
        $router->post('/colleagues/groups/{id}/share/board', fn(Request $r) => $colleagues->shareBoardWithGroup($r));
        $router->post('/colleagues/groups/{id}/share/calendar', fn(Request $r) => $colleagues->shareCalendarWithGroup($r));
        // Individual colleague management
        $router->put('/colleagues/{id}', fn(Request $r) => $colleagues->update($r));
        $router->delete('/colleagues/{id}', fn(Request $r) => $colleagues->delete($r));
        $router->put('/colleagues/{id}/groups', fn(Request $r) => $colleagues->setGroups($r));
    }

    // =========================================================================
    // Chat & Calls Addon Routes (gated - only registered when addon is enabled)
    // =========================================================================
    $mayChatRoute = str_contains($requestPath, '/chat/')
        || str_contains($requestPath, '/chat')
        || str_contains($requestPath, '/call/')
        || str_contains($requestPath, '/webhook/');

    if ($mayChatRoute && (new \Webmail\Services\AddonService($config, $routeGatingEmail))->isChatEnabled()) {
        // Chat routes (Direct Messaging)
        $chat = new ChatController($config);
        // Combined init (unread + invitations + huddles)
        $router->get('/chat/init', fn(Request $r) => $chat->init($r));
        // Conversations
        $router->get('/chat/conversations', fn(Request $r) => $chat->listConversations($r));
        $router->get('/chat/unread', fn(Request $r) => $chat->getUnreadCounts($r));
        $router->get('/chat/search', fn(Request $r) => $chat->searchMessages($r));
        $router->post('/chat/dm/{colleagueId}', fn(Request $r) => $chat->getOrCreateDM($r));
        $router->post('/chat/invite', fn(Request $r) => $chat->inviteToChat($r));
        // Invitation management (accept/decline/list)
        $router->get('/chat/invitations', fn(Request $r) => $chat->getPendingInvitations($r));
        $router->get('/chat/invitations/token/{token}', fn(Request $r) => $chat->getInvitationByToken($r));
        $router->post('/chat/invitations/{id}/accept', fn(Request $r) => $chat->acceptInvitation($r));
        $router->post('/chat/invitations/{id}/decline', fn(Request $r) => $chat->declineInvitation($r));
        $router->get('/chat/conversations/{id}', fn(Request $r) => $chat->getConversation($r));
        $router->get('/chat/conversations/{id}/meeting', fn(Request $r) => $chat->getConversationMeeting($r));
        $router->get('/chat/conversations/{id}/messages', fn(Request $r) => $chat->getMessages($r));
        $router->post('/chat/conversations/{id}/messages', fn(Request $r) => $chat->sendMessage($r));
        $router->get('/chat/conversations/{id}/pinned', fn(Request $r) => $chat->getPinnedMessages($r));
        $router->post('/chat/conversations/{id}/read', fn(Request $r) => $chat->markAsRead($r));
        $router->post('/chat/conversations/{id}/typing', fn(Request $r) => $chat->updateTyping($r));
        $router->post('/chat/conversations/{id}/pin', fn(Request $r) => $chat->togglePin($r));
        $router->post('/chat/conversations/{id}/mute', fn(Request $r) => $chat->toggleMute($r));
        $router->post('/chat/conversations/{id}/archive', fn(Request $r) => $chat->archiveConversation($r));
        $router->post('/chat/conversations/{id}/unarchive', fn(Request $r) => $chat->unarchiveConversation($r));
        $router->delete('/chat/conversations/{id}', fn(Request $r) => $chat->deleteConversation($r));
        // Attachments
        $router->post('/chat/conversations/{id}/attachments', fn(Request $r) => $chat->uploadAttachments($r));
        $router->get('/chat/conversations/{id}/attachments', fn(Request $r) => $chat->getAttachments($r));
        $router->post('/chat/conversations/{id}/attachments/save-to-drive', fn(Request $r) => $chat->saveAttachmentsToDrive($r));
        // Serve attachment files (requires auth — filename can contain dots)
        $router->get('/chat/attachments/{conversationId}/{filename:.+}', fn(Request $r) => $chat->serveAttachment($r));
        // Note: This endpoint was previously unauthenticated. It now requires auth
        // and verifies conversation membership in the controller.
        // Conversation settings (background, etc.)
        $router->get('/chat/conversations/{id}/settings', fn(Request $r) => $chat->getSettings($r));
        $router->put('/chat/conversations/{id}/settings', fn(Request $r) => $chat->updateSettings($r));
        // View Together (collaborative viewing)
        $router->post('/chat/conversations/{id}/view-session', fn(Request $r) => $chat->startViewSession($r));
        $router->delete('/chat/conversations/{id}/view-session', fn(Request $r) => $chat->endViewSession($r));
        $router->put('/chat/conversations/{id}/view-session/sync', fn(Request $r) => $chat->syncViewPosition($r));
        // Messages
        $router->patch('/chat/messages/{id}', fn(Request $r) => $chat->editMessage($r));
        $router->delete('/chat/messages/{id}', fn(Request $r) => $chat->deleteMessage($r));
        $router->delete('/chat/messages/{id}/thread', fn(Request $r) => $chat->deleteThread($r));
        // Reactions
        $router->post('/chat/messages/{id}/pin', fn(Request $r) => $chat->togglePinMessage($r));
        $router->post('/chat/messages/{id}/reactions', fn(Request $r) => $chat->addReaction($r));
        $router->delete('/chat/messages/{id}/reactions/{emoji}', fn(Request $r) => $chat->removeReaction($r));
        // Embed resolution (shared content cards in chat)
        $router->get('/chat/embed/resolve', fn(Request $r) => $chat->resolveEmbed($r));
        // Drive sharing lookup (which files/folders were shared in chat)
        $router->get('/chat/shared-drive-ids', fn(Request $r) => $chat->getSharedDriveIds($r));
        
        // Channels
        $channel = new ChannelController($config);
        $router->get('/chat/channels', fn(Request $r) => $channel->browseChannels($r));
        $router->post('/chat/channels', fn(Request $r) => $channel->createChannel($r));
        $router->post('/chat/channels/{id}/join', fn(Request $r) => $channel->joinChannel($r));
        $router->post('/chat/channels/{id}/leave', fn(Request $r) => $channel->leaveChannel($r));
        $router->patch('/chat/channels/{id}/topic', fn(Request $r) => $channel->setTopic($r));
        $router->patch('/chat/channels/{id}/purpose', fn(Request $r) => $channel->setPurpose($r));
        $router->post('/chat/channels/{id}/set-default', fn(Request $r) => $channel->setDefault($r));
        $router->get('/chat/channels/{id}/members', fn(Request $r) => $channel->getMembers($r));

        // Channel Categories
        $category = new CategoryController($config);
        $router->get('/chat/categories', fn(Request $r) => $category->listCategories($r));
        $router->post('/chat/categories', fn(Request $r) => $category->createCategory($r));
        $router->patch('/chat/categories/{id}', fn(Request $r) => $category->updateCategory($r));
        $router->delete('/chat/categories/{id}', fn(Request $r) => $category->deleteCategory($r));
        $router->post('/chat/categories/reorder', fn(Request $r) => $category->reorder($r));
        $router->post('/chat/channels/{id}/category', fn(Request $r) => $category->assignChannel($r));

        // Bookmarks (Saved Messages)
        $router->post('/chat/messages/{id}/bookmark', fn(Request $r) => $chat->toggleBookmark($r));
        $router->get('/chat/bookmarks', fn(Request $r) => $chat->getBookmarks($r));
        $router->delete('/chat/bookmarks/{id}', fn(Request $r) => $chat->deleteBookmark($r));

        // Thread endpoints
        $router->get('/chat/threads', fn(Request $r) => $chat->getActiveThreads($r));
        $router->get('/chat/messages/{id}/thread', fn(Request $r) => $chat->getThread($r));

        // Mentions
        $router->get('/chat/mentions', fn(Request $r) => $chat->getMentions($r));
        $router->get('/chat/mentions/unread', fn(Request $r) => $chat->getUnreadMentions($r));

        // Scheduled messages
        $router->get('/chat/scheduled', fn(Request $r) => $chat->getScheduledMessages($r));
        $router->post('/chat/conversations/{id}/schedule', fn(Request $r) => $chat->scheduleMessage($r));
        $router->patch('/chat/scheduled/{id}', fn(Request $r) => $chat->updateScheduledMessage($r));
        $router->delete('/chat/scheduled/{id}', fn(Request $r) => $chat->deleteScheduledMessage($r));

        // Magic call links (reuses GuestCallController for token logic)
        $guestCallForChat = new GuestCallController($config);
        $router->post('/chat/guest-call-link', fn(Request $r) => $guestCallForChat->createChatGuestLink($r));
        $router->get('/chat/guest-call-links', fn(Request $r) => $guestCallForChat->listChatGuestLinks($r));
        $router->delete('/chat/guest-call-links/{token}', fn(Request $r) => $guestCallForChat->revokeChatGuestLink($r));

        // Link preview
        $router->get('/chat/link-preview', fn(Request $r) => $chat->getLinkPreview($r));

        // Webhooks management
        $webhook = new WebhookController($config);
        $router->post('/chat/webhooks', fn(Request $r) => $webhook->createWebhook($r));
        $router->get('/chat/webhooks', fn(Request $r) => $webhook->listWebhooks($r));
        $router->delete('/chat/webhooks/{id}', fn(Request $r) => $webhook->deleteWebhook($r));

        // Group Chat
        $router->post('/chat/groups', fn(Request $r) => $chat->createGroup($r));
        $router->post('/chat/groups/from-colleague-group', fn(Request $r) => $chat->createGroupFromColleagueGroup($r));
        $router->get('/chat/groups/{id}/members', fn(Request $r) => $chat->getGroupMembers($r));
        $router->post('/chat/groups/{id}/members', fn(Request $r) => $chat->addGroupMembers($r));
        $router->delete('/chat/groups/{id}/members/{memberId}', fn(Request $r) => $chat->removeGroupMember($r));
        $router->delete('/chat/groups/{id}/members', fn(Request $r) => $chat->removeGroupMembersBatch($r));
        $router->patch('/chat/groups/{id}', fn(Request $r) => $chat->updateGroup($r));
        $router->post('/chat/groups/{id}/admins', fn(Request $r) => $chat->setGroupAdmin($r));
        $router->post('/chat/groups/{id}/invite', fn(Request $r) => $chat->inviteToGroup($r));

        // Huddles (persistent audio rooms)
        $huddle = new HuddleController($config);
        $router->post('/chat/huddles/start', fn(Request $r) => $huddle->start($r));
        $router->post('/chat/huddles/{id}/join', fn(Request $r) => $huddle->join($r));
        $router->post('/chat/huddles/{id}/leave', fn(Request $r) => $huddle->leave($r));
        $router->get('/chat/huddles/active-all', fn(Request $r) => $huddle->getAllActive($r));
        $router->get('/chat/huddles/active/{id}', fn(Request $r) => $huddle->getActive($r));

        // Call routes (voice/video)
        $call = new CallController($config);
        $router->post('/call/livekit-token', fn(Request $r) => $call->getLiveKitToken($r));
        $router->get('/call/ice-servers', fn(Request $r) => $call->getIceServers($r));
        $router->get('/call/history/{id}', fn(Request $r) => $call->getCallHistory($r));
        $router->post('/call/history', fn(Request $r) => $call->saveCallRecord($r));
    }

    // Mailing lists & Email Queue routes (gated by email_marketing addon)
    $mayMarketingRoute = str_contains($requestPath, '/mailing-lists')
        || str_contains($requestPath, '/email-queue')
        || str_contains($requestPath, '/email-marketing');

    if ($mayMarketingRoute) {
        $emailMarketingEnabled = (new \Webmail\Services\AddonService($config, $routeGatingEmail))->isEmailMarketingEnabled();
    }

    if ($mayMarketingRoute && !($emailMarketingEnabled ?? false)) {
        $disabledMsg = fn() => \Webmail\Core\Response::error('Email Marketing addon is not enabled. Enable it in the Admin Panel.');
        $router->get('/mailing-lists', $disabledMsg);
        $router->get('/mailing-lists/{id}', $disabledMsg);
        $router->get('/mailing-lists/{id}/contacts', $disabledMsg);
        $router->get('/email-queue/campaigns', $disabledMsg);
    }

    if ($mayMarketingRoute && ($emailMarketingEnabled ?? false)) {
        // Mailing lists routes (external contact lists)
        $mailingLists = new MailingListController($config);
        $router->get('/mailing-lists', fn(Request $r) => $mailingLists->list($r));
        $router->post('/mailing-lists', fn(Request $r) => $mailingLists->create($r));
        // Contact bulk actions (before {id} routes)
        $router->post('/mailing-lists/contacts/bulk-delete', fn(Request $r) => $mailingLists->bulkDeleteContacts($r));
        // List-specific routes
        $router->get('/mailing-lists/{id}', fn(Request $r) => $mailingLists->get($r));
        $router->put('/mailing-lists/{id}', fn(Request $r) => $mailingLists->update($r));
        $router->delete('/mailing-lists/{id}', fn(Request $r) => $mailingLists->delete($r));
        $router->get('/mailing-lists/{id}/contacts', fn(Request $r) => $mailingLists->getContacts($r));
        $router->post('/mailing-lists/{id}/contacts', fn(Request $r) => $mailingLists->addContact($r));
        $router->post('/mailing-lists/{id}/import', fn(Request $r) => $mailingLists->import($r));
        $router->get('/mailing-lists/{id}/emails', fn(Request $r) => $mailingLists->getEmails($r));
        // Single contact routes
        $router->put('/mailing-lists/contacts/{contactId}', fn(Request $r) => $mailingLists->updateContact($r));
        $router->delete('/mailing-lists/contacts/{contactId}', fn(Request $r) => $mailingLists->deleteContact($r));
        // Custom fields
        $router->get('/mailing-lists/{id}/custom-fields', fn(Request $r) => $mailingLists->getCustomFields($r));
        $router->post('/mailing-lists/{id}/custom-fields', fn(Request $r) => $mailingLists->createCustomField($r));
        $router->put('/mailing-lists/custom-fields/{fieldId}', fn(Request $r) => $mailingLists->updateCustomField($r));
        $router->delete('/mailing-lists/custom-fields/{fieldId}', fn(Request $r) => $mailingLists->deleteCustomField($r));

        // Email Queue routes (bulk email campaigns with rate limiting)
        $emailQueue = new EmailQueueController($config);
        $router->post('/email-queue/send', fn(Request $r) => $emailQueue->send($r));
        $router->get('/email-queue/campaigns', fn(Request $r) => $emailQueue->listCampaigns($r));
        $router->get('/email-queue/rate-limits', fn(Request $r) => $emailQueue->getRateLimits($r));
        $router->get('/email-queue/campaigns/{id}', fn(Request $r) => $emailQueue->getCampaign($r));
        $router->post('/email-queue/campaigns/{id}/pause', fn(Request $r) => $emailQueue->pauseCampaign($r));
        $router->post('/email-queue/campaigns/{id}/resume', fn(Request $r) => $emailQueue->resumeCampaign($r));
        $router->delete('/email-queue/campaigns/{id}', fn(Request $r) => $emailQueue->cancelCampaign($r));
        $router->get('/email-queue/campaigns/{id}/analytics', fn(Request $r) => $emailQueue->getCampaignAnalytics($r));
        $router->get('/email-queue/campaigns/{id}/failed', fn(Request $r) => $emailQueue->getFailedRecipients($r));
        $router->post('/email-queue/campaigns/{id}/retry', fn(Request $r) => $emailQueue->retryFailed($r));
        $router->post('/email-queue/campaigns/{id}/delete', fn(Request $r) => $emailQueue->destroyCampaign($r));
        $router->post('/email-queue/campaigns/draft', fn(Request $r) => $emailQueue->createDraft($r));
        $router->put('/email-queue/campaigns/{id}/draft', fn(Request $r) => $emailQueue->updateDraft($r));
        $router->post('/email-queue/campaigns/{id}/draft', fn(Request $r) => $emailQueue->updateDraft($r));
        $router->post('/email-queue/campaigns/{id}/finalize', fn(Request $r) => $emailQueue->finalizeDraft($r));

        // Unsubscribe management (authenticated)
        $router->get('/email-marketing/unsubscribes', fn(Request $r) => $unsub->listUnsubscribes($r));
        $router->delete('/email-marketing/unsubscribes/{email}', fn(Request $r) => $unsub->resubscribe($r));
    }

    // Email Template (Content Blocks) routes
    $emailTemplates = new EmailTemplateController($config);
    $router->get('/email-templates', fn(Request $r) => $emailTemplates->list($r));
    $router->post('/email-templates', fn(Request $r) => $emailTemplates->create($r));
    $router->post('/email-templates/reorder', fn(Request $r) => $emailTemplates->reorder($r));
    $router->get('/email-templates/{id}', fn(Request $r) => $emailTemplates->get($r));
    $router->put('/email-templates/{id}', fn(Request $r) => $emailTemplates->update($r));
    $router->delete('/email-templates/{id}', fn(Request $r) => $emailTemplates->delete($r));

    // Reaction routes (email reactions like Outlook) - gated by reactions addon
    if (str_contains($requestPath, '/reactions') && (new \Webmail\Services\AddonService($config, $routeGatingEmail))->isReactionsEnabled()) {
        $reactions = new ReactionController($config);
        $router->get('/reactions/emojis', fn(Request $r) => $reactions->emojis($r));
        $router->get('/reactions/message', fn(Request $r) => $reactions->getMessage($r));
        $router->post('/reactions/batch', fn(Request $r) => $reactions->batch($r));
        $router->post('/reactions', fn(Request $r) => $reactions->add($r));
        $router->delete('/reactions', fn(Request $r) => $reactions->remove($r));
    }

    // System routes (admin/diagnostics)
    $system = new SystemController($config);
    $router->get('/system/permissions', fn(Request $r) => $system->checkPermissions($r));
    $router->post('/system/permissions/fix', fn(Request $r) => $system->fixPermissions($r));

    // Weather chip in the app header (shared cache + IP geolocation)
    $weather = new WeatherController($config);
    $router->get('/weather/current', fn(Request $r) => $weather->current($r));
    
    // Push Notification routes (Web Push for PWA + native FCM for Capacitor apps)
    $push = new PushNotificationController($config);
    $router->get('/push/vapid-key', fn(Request $r) => $push->getVapidKey($r));
    $router->post('/push/subscribe', fn(Request $r) => $push->subscribe($r));
    $router->post('/push/unsubscribe', fn(Request $r) => $push->unsubscribe($r));
    // Native (FCM) device token registration for iOS/Android Capacitor apps
    $router->post('/push/native-register', fn(Request $r) => $push->nativeRegister($r));
    $router->post('/push/native-unregister', fn(Request $r) => $push->nativeUnregister($r));
    // User-wide push notification preferences (per-type gating)
    $router->get('/push/preferences', fn(Request $r) => $push->getPreferences($r));
    $router->put('/push/preferences', fn(Request $r) => $push->updatePreferences($r));
    // Client-reported unread badge total (seeds the native app-icon badge on push)
    $router->post('/push/badge', fn(Request $r) => $push->setBadge($r));

    // =========================================================================
    // Watch Folders + Path Overrides (ungated - core feature)
    // =========================================================================
    if (str_contains($requestPath, '/watch-folders') || str_contains($requestPath, '/path-overrides')) {
        $watchFolders = new \Webmail\Controllers\WatchFolderController($config);
        $router->get('/watch-folders', fn(Request $r) => $watchFolders->list($r));
        $router->get('/watch-folders/resolved', fn(Request $r) => $watchFolders->resolvedList($r));
        $router->post('/watch-folders', fn(Request $r) => $watchFolders->create($r));
        $router->put('/watch-folders/{id}', fn(Request $r) => $watchFolders->update($r));
        $router->delete('/watch-folders/{id}', fn(Request $r) => $watchFolders->delete($r));
        $router->post('/watch-folders/file-activity', fn(Request $r) => $watchFolders->logFileActivity($r));
        $router->get('/watch-folders/file-activity/card/{cardId}', fn(Request $r) => $watchFolders->cardFileActivity($r));
        $router->get('/watch-folders/file-activity/board/{boardId}', fn(Request $r) => $watchFolders->boardFileActivity($r));

        $router->get('/path-overrides', fn(Request $r) => $watchFolders->listOverrides($r));
        $router->post('/path-overrides', fn(Request $r) => $watchFolders->upsertOverride($r));
        $router->delete('/path-overrides/{id}', fn(Request $r) => $watchFolders->deleteOverride($r));
        $router->get('/path-overrides/team-status', fn(Request $r) => $watchFolders->teamOverrideStatus($r));
    }

    // =========================================================================
    // Mood Board Addon Routes (gated - only registered when addon is enabled)
    // =========================================================================
    // Performance: Only instantiate AddonService when request might match a
    // mood board route. Avoids Redis hit on every unrelated request.
    $mayMoodRoute = str_contains($requestPath, '/mood-boards')
        || str_contains($requestPath, '/mood/share/')
        || preg_match('#/clients/\d+/mood-boards#', $requestPath)
        || str_contains($requestPath, '/clients/mood-board-mapping')
        || preg_match('#/boards/\d+/mood-boards#', $requestPath);

    if ($mayMoodRoute && (new \Webmail\Services\AddonService($config, $routeGatingEmail))->isMoodboardsEnabled()) {
        $moodBoards = new MoodBoardController($config);
        
        // Public share routes (NO AUTH - token validates access) — must be before {id} routes
        $router->get('/mood-boards/share/{token}', fn(Request $r) => $moodBoards->publicView($r));
        $router->get('/mood-boards/share/{token}/uploads/thumbs/{filename}', fn(Request $r) => $moodBoards->publicServeThumb($r));
        $router->get('/mood-boards/share/{token}/uploads/{filename}', fn(Request $r) => $moodBoards->publicServeUpload($r));
        $router->post('/mood-boards/share/{token}/ws-token', fn(Request $r) => $moodBoards->publicRequestWsToken($r));
        $router->post('/mood-boards/share/{token}/track', fn(Request $r) => $moodBoards->publicTrackView($r));
        $router->put('/mood-boards/share/{token}/heartbeat', fn(Request $r) => $moodBoards->publicHeartbeat($r));
        $router->post('/mood-boards/share/{token}/validate-password', fn(Request $r) => $moodBoards->publicValidateSharePassword($r));
        // Public comments (NO AUTH)
        $router->get('/mood-boards/share/{token}/comments', fn(Request $r) => $moodBoards->publicListComments($r));
        $router->post('/mood-boards/share/{token}/comments', fn(Request $r) => $moodBoards->publicAddComment($r));
        $router->post('/mood-boards/share/{token}/comments/threads/{threadId}/resolve', fn(Request $r) => $moodBoards->publicResolveThread($r));
        $router->post('/mood-boards/share/{token}/comments/threads/{threadId}/unresolve', fn(Request $r) => $moodBoards->publicUnresolveThread($r));
        $router->delete('/mood-boards/share/{token}/comments/threads/{threadId}', fn(Request $r) => $moodBoards->publicDeleteCommentThread($r));
        $router->put('/mood-boards/share/{token}/comments/{commentId}', fn(Request $r) => $moodBoards->publicUpdateComment($r));
        $router->delete('/mood-boards/share/{token}/comments/{commentId}', fn(Request $r) => $moodBoards->publicDeleteComment($r));
        // Public item editing (NO AUTH - token + edit mode validates access) — batch routes before {itemId}
        $router->put('/mood-boards/share/{token}/items/batch', fn(Request $r) => $moodBoards->publicBatchUpdateItems($r));
        $router->post('/mood-boards/share/{token}/items/batch-add', fn(Request $r) => $moodBoards->publicBatchAddItems($r));
        $router->post('/mood-boards/share/{token}/items/batch-delete', fn(Request $r) => $moodBoards->publicBatchDeleteItems($r));
        $router->post('/mood-boards/share/{token}/items', fn(Request $r) => $moodBoards->publicAddItem($r));
        $router->put('/mood-boards/share/{token}/items/{itemId}', fn(Request $r) => $moodBoards->publicUpdateItem($r));
        $router->delete('/mood-boards/share/{token}/items/{itemId}', fn(Request $r) => $moodBoards->publicDeleteItem($r));
        
        // Shared boards overview (authenticated)
        $router->get('/mood-boards/shared', fn(Request $r) => $moodBoards->getSharedBoards($r));

        // User palettes (shareable across boards — must be before {id} route)
        $router->get('/mood-boards/palettes', fn(Request $r) => $moodBoards->listPalettes($r));
        $router->post('/mood-boards/palettes', fn(Request $r) => $moodBoards->createPalette($r));
        $router->post('/mood-boards/palettes/from-board/{boardId}', fn(Request $r) => $moodBoards->saveBoardPalette($r));
        $router->put('/mood-boards/palettes/{id}', fn(Request $r) => $moodBoards->updatePalette($r));
        $router->delete('/mood-boards/palettes/{id}', fn(Request $r) => $moodBoards->deletePalette($r));
        $router->post('/mood-boards/palettes/{id}/apply/{boardId}', fn(Request $r) => $moodBoards->applyPaletteToBoard($r));

        // Component blocks (before {id} route)
        $router->get('/mood-boards/components', fn(Request $r) => $moodBoards->listComponents($r));
        $router->post('/mood-boards/components', fn(Request $r) => $moodBoards->saveComponent($r));
        $router->put('/mood-boards/components/{id}', fn(Request $r) => $moodBoards->updateComponent($r));
        $router->post('/mood-boards/components/{id}/push', fn(Request $r) => $moodBoards->pushComponentChanges($r));
        $router->post('/mood-boards/components/{id}/push-from-item', fn(Request $r) => $moodBoards->pushFromItem($r));
        $router->delete('/mood-boards/components/{id}', fn(Request $r) => $moodBoards->deleteComponent($r));
        
        // Folders (before {id} route to avoid conflicts)
        $router->get('/mood-boards/folders', fn(Request $r) => $moodBoards->listFolders($r));
        $router->post('/mood-boards/folders', fn(Request $r) => $moodBoards->createFolder($r));
        $router->put('/mood-boards/folders/reorder', fn(Request $r) => $moodBoards->reorderFolders($r));
        $router->put('/mood-boards/folders/{id}', fn(Request $r) => $moodBoards->updateFolder($r));
        $router->delete('/mood-boards/folders/{id}', fn(Request $r) => $moodBoards->deleteFolder($r));
        
        // Boards
        $router->get('/mood-boards', fn(Request $r) => $moodBoards->listBoards($r));
        $router->post('/mood-boards', fn(Request $r) => $moodBoards->createBoard($r));
        $router->get('/mood-boards/{id}', fn(Request $r) => $moodBoards->getBoard($r));
        $router->put('/mood-boards/{id}', fn(Request $r) => $moodBoards->updateBoard($r));
        $router->delete('/mood-boards/{id}', fn(Request $r) => $moodBoards->deleteBoard($r));
        $router->post('/mood-boards/{id}/duplicate', fn(Request $r) => $moodBoards->duplicateBoard($r));
        $router->put('/mood-boards/{id}/move', fn(Request $r) => $moodBoards->moveBoardToFolder($r));
        // Text CSV export/import
        $router->get('/mood-boards/{id}/export-texts', fn(Request $r) => $moodBoards->exportTexts($r));
        $router->get('/mood-boards/{id}/export-presentation', fn(Request $r) => $moodBoards->exportPresentation($r));
        $router->get('/mood-boards/{id}/export-pptx', fn(Request $r) => $moodBoards->exportPptx($r));
        $router->get('/mood-boards/{id}/export-pdf', fn(Request $r) => $moodBoards->exportPdf($r));
        $router->post('/mood-boards/{id}/import-texts', fn(Request $r) => $moodBoards->importTexts($r));
        // Ready state toggle
        $router->post('/mood-boards/{id}/ready', fn(Request $r) => $moodBoards->toggleReady($r));
        // Activity
        $router->get('/mood-boards/{id}/activity', fn(Request $r) => $moodBoards->getActivity($r));
        // File uploads + thumbnails
        $router->post('/mood-boards/{id}/upload', fn(Request $r) => $moodBoards->uploadFiles($r));
        $router->get('/mood-boards/{id}/uploads/thumbs/{filename}', fn(Request $r) => $moodBoards->serveThumb($r));
        $router->get('/mood-boards/{id}/uploads/{filename}', fn(Request $r) => $moodBoards->serveUpload($r));
        $router->post('/mood-boards/{id}/import-drive-file', fn(Request $r) => $moodBoards->importDriveFile($r));
        $router->post('/mood-boards/{id}/generate-thumbnails', fn(Request $r) => $moodBoards->generateThumbnails($r));
        // AI generation, modification & variations
        $router->post('/mood-boards/{id}/ai/generate', fn(Request $r) => $moodBoards->aiGenerate($r));
        $router->post('/mood-boards/{id}/ai/modify', fn(Request $r) => $moodBoards->aiModify($r));
        $router->post('/mood-boards/{id}/ai/variations', fn(Request $r) => $moodBoards->aiVariations($r));
        // Items
        $router->post('/mood-boards/{id}/items', fn(Request $r) => $moodBoards->addItem($r));
        $router->put('/mood-boards/{id}/items/batch', fn(Request $r) => $moodBoards->batchUpdateItems($r));
        $router->post('/mood-boards/{id}/items/batch-delete', fn(Request $r) => $moodBoards->batchDeleteItems($r));
        $router->post('/mood-boards/{id}/items/batch-add', fn(Request $r) => $moodBoards->batchAddItems($r));
        $router->post('/mood-boards/{id}/items/restore-batch', fn(Request $r) => $moodBoards->restoreItems($r));
        $router->post('/mood-boards/{id}/restore-all', fn(Request $r) => $moodBoards->restoreAllItems($r));
        $router->get('/mood-boards/{id}/trash', fn(Request $r) => $moodBoards->getTrash($r));
        $router->get('/mood-boards/{id}/snapshots', fn(Request $r) => $moodBoards->getSnapshots($r));
        $router->post('/mood-boards/{id}/snapshots', fn(Request $r) => $moodBoards->createSnapshot($r));
        $router->post('/mood-boards/{id}/snapshots/{snapshotId}/restore', fn(Request $r) => $moodBoards->restoreSnapshot($r));
        $router->post('/mood-boards/{id}/items/detach-component', fn(Request $r) => $moodBoards->detachComponentInstance($r));

        // Design tokens (global variables)
        $router->get('/mood-boards/{id}/design-tokens', fn(Request $r) => $moodBoards->getDesignTokens($r));
        $router->put('/mood-boards/{id}/design-tokens', fn(Request $r) => $moodBoards->saveDesignTokens($r));
        $router->post('/mood-boards/{id}/design-tokens/update-color', fn(Request $r) => $moodBoards->updateDesignTokenColor($r));

        // Global text styles
        $router->get('/mood-boards/{id}/global-text-styles', fn(Request $r) => $moodBoards->getGlobalTextStyles($r));
        $router->put('/mood-boards/{id}/global-text-styles', fn(Request $r) => $moodBoards->saveGlobalTextStyles($r));

        // Global CSS classes
        $router->get('/mood-boards/{id}/global-css-classes', fn(Request $r) => $moodBoards->getGlobalCssClasses($r));
        $router->put('/mood-boards/{id}/global-css-classes', fn(Request $r) => $moodBoards->saveGlobalCssClasses($r));

        // Global style propagation (semantic / ID-based)
        $router->post('/mood-boards/{id}/globals/propagate-color', fn(Request $r) => $moodBoards->propagateGlobalColor($r));
        $router->post('/mood-boards/{id}/globals/propagate-text-style', fn(Request $r) => $moodBoards->propagateGlobalTextStyle($r));
        $router->put('/mood-boards/{id}/items/{itemId}', fn(Request $r) => $moodBoards->updateItem($r));
        $router->delete('/mood-boards/{id}/items/{itemId}', fn(Request $r) => $moodBoards->deleteItem($r));
        $router->post('/mood-boards/{id}/items/{itemId}/restore', fn(Request $r) => $moodBoards->restoreItem($r));
        // Image set images (within image_set items)
        $router->post('/mood-boards/{id}/items/{itemId}/images', fn(Request $r) => $moodBoards->addImageToSet($r));
        $router->post('/mood-boards/{id}/items/{itemId}/images/batch', fn(Request $r) => $moodBoards->addImagesToSetBatch($r));
        $router->delete('/mood-boards/{id}/images/{imageId}', fn(Request $r) => $moodBoards->removeImageFromSet($r));
        // Todos (within todo_list items)
        $router->post('/mood-boards/{id}/items/{itemId}/todos', fn(Request $r) => $moodBoards->addTodo($r));
        $router->put('/mood-boards/{id}/todos/{todoId}', fn(Request $r) => $moodBoards->updateTodo($r));
        $router->delete('/mood-boards/{id}/todos/{todoId}', fn(Request $r) => $moodBoards->deleteTodo($r));
        // Connections (arrows between items)
        $router->post('/mood-boards/{id}/connections/batch', fn(Request $r) => $moodBoards->batchAddConnections($r));
        $router->post('/mood-boards/{id}/connections/purge-orphans', fn(Request $r) => $moodBoards->purgeOrphanConnections($r));
        $router->post('/mood-boards/{id}/connections', fn(Request $r) => $moodBoards->addConnection($r));
        $router->put('/mood-boards/{id}/connections/{connId}', fn(Request $r) => $moodBoards->updateConnection($r));
        $router->delete('/mood-boards/{id}/connections/{connId}', fn(Request $r) => $moodBoards->deleteConnection($r));
        // Measurements
        $router->post('/mood-boards/{id}/measurements', fn(Request $r) => $moodBoards->addMeasurement($r));
        $router->post('/mood-boards/{id}/measurements/clear', fn(Request $r) => $moodBoards->clearMeasurements($r));
        $router->delete('/mood-boards/{id}/measurements/{measureId}', fn(Request $r) => $moodBoards->deleteMeasurement($r));
        $router->put('/mood-boards/{id}/measure-settings', fn(Request $r) => $moodBoards->updateMeasureSettings($r));
        // Members
        $router->get('/mood-boards/{id}/members', fn(Request $r) => $moodBoards->getMembers($r));
        $router->post('/mood-boards/{id}/members', fn(Request $r) => $moodBoards->addMember($r));
        $router->put('/mood-boards/{id}/members/{email}', fn(Request $r) => $moodBoards->updateMember($r));
        $router->delete('/mood-boards/{id}/members/{email}', fn(Request $r) => $moodBoards->removeMember($r));
        // Group access
        $router->get('/mood-boards/{id}/groups', fn(Request $r) => $moodBoards->getGroupAccess($r));
        $router->post('/mood-boards/{id}/groups', fn(Request $r) => $moodBoards->addGroupAccess($r));
        $router->delete('/mood-boards/{id}/groups/{groupId}', fn(Request $r) => $moodBoards->removeGroupAccess($r));
        // Board-to-board linking (Mood <-> Kanban)
        $router->get('/mood-boards/{id}/board-links', fn(Request $r) => $moodBoards->getLinkedBoards($r));
        $router->post('/mood-boards/{id}/board-links', fn(Request $r) => $moodBoards->linkToBoard($r));
        $router->delete('/mood-boards/{id}/board-links/{kanbanBoardId}', fn(Request $r) => $moodBoards->unlinkFromBoard($r));
        // Reverse lookup: mood boards for a kanban board
        $router->get('/boards/{kanbanBoardId}/mood-boards', fn(Request $r) => $moodBoards->getMoodBoardsForKanban($r));
        // Share link management (authenticated)
        $router->post('/mood-boards/{id}/share', fn(Request $r) => $moodBoards->createShareLink($r));
        $router->put('/mood-boards/{id}/share', fn(Request $r) => $moodBoards->updateShareLink($r));
        $router->delete('/mood-boards/{id}/share', fn(Request $r) => $moodBoards->removeShareLink($r));
        $router->get('/mood-boards/{id}/share/stats', fn(Request $r) => $moodBoards->getShareStats($r));
        // Comments (authenticated)
        $router->get('/mood-boards/{id}/comments', fn(Request $r) => $moodBoards->listComments($r));
        $router->post('/mood-boards/{id}/comments', fn(Request $r) => $moodBoards->addComment($r));
        $router->put('/mood-boards/{id}/comments/{commentId}', fn(Request $r) => $moodBoards->updateComment($r));
        $router->delete('/mood-boards/{id}/comments/{commentId}', fn(Request $r) => $moodBoards->deleteComment($r));
        $router->delete('/mood-boards/{id}/comments/threads/{threadId}', fn(Request $r) => $moodBoards->deleteCommentThread($r));
        $router->post('/mood-boards/{id}/comments/threads/{threadId}/resolve', fn(Request $r) => $moodBoards->resolveCommentThread($r));
        $router->post('/mood-boards/{id}/comments/threads/{threadId}/unresolve', fn(Request $r) => $moodBoards->unresolveCommentThread($r));
        // Client linking
        $router->get('/clients/{clientId}/mood-boards', fn(Request $r) => $moodBoards->getClientBoards($r));
        $router->post('/clients/{clientId}/mood-boards', fn(Request $r) => $moodBoards->linkToClient($r));
        $router->delete('/clients/{clientId}/mood-boards/{boardId}', fn(Request $r) => $moodBoards->unlinkFromClient($r));
    }

    // =========================================================================
    // Addon Status (per-user resolution: passes logged-in email to Panel)
    // =========================================================================
    $router->get('/addons', function (Request $r) use ($config) {
        // Try to extract user email from JWT for per-user addon resolution
        $userEmail = null;
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            try {
                $session = new \Webmail\Services\SessionService($config['jwt'], $config['imap_encryption_key'] ?? '');
                $payload = $session->validateToken($matches[1]);
                if ($payload && ($payload['type'] ?? '') === 'access') {
                    $userEmail = $payload['sub'] ?? $payload['email'] ?? null;
                }
            } catch (\Exception $e) {
                // Token invalid — fall back to global statuses
            }
        }

        $addonService = new \Webmail\Services\AddonService($config, $userEmail);
        return \Webmail\Core\Response::success($addonService->getAll());
    });

    // Force refresh addon cache (requires valid JWT, per-user resolution)
    $router->post('/addons/refresh', function (Request $r) use ($config) {
        // Verify JWT Bearer token
        $session = new \Webmail\Services\SessionService($config['jwt'], $config['imap_encryption_key'] ?? '');
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            return \Webmail\Core\Response::unauthorized('Authentication required');
        }
        try {
            $payload = $session->validateToken($matches[1]);
            if (!$payload || ($payload['type'] ?? '') !== 'access') {
                return \Webmail\Core\Response::unauthorized('Invalid token');
            }
        } catch (\Exception $e) {
            return \Webmail\Core\Response::unauthorized('Invalid token');
        }

        $userEmail = $payload['sub'] ?? $payload['email'] ?? null;
        $addonService = new \Webmail\Services\AddonService($config, $userEmail);
        return \Webmail\Core\Response::success($addonService->refreshStatus());
    });

    // Panel webhook: invalidate addon cache after toggle
    // Auth: same API key the Email App uses to call the Panel (shared secret)
    // Clears ALL addon caches (global + per-user) so changes take effect immediately
    $router->post('/addons/invalidate', function (Request $r) use ($config) {
        $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
        $validKey = $config['panel']['api_key'] ?? '';

        if (empty($apiKey) || empty($validKey) || !hash_equals($validKey, $apiKey)) {
            return \Webmail\Core\Response::unauthorized('Invalid or missing API key');
        }

        // Clear all addon caches (global + per-user)
        try {
            if (extension_loaded('redis')) {
                $redis = new \Redis();
                $host = $config['redis']['host'] ?? '127.0.0.1';
                $port = $config['redis']['port'] ?? 6379;
                $redis->connect($host, $port, 2.0);
                $password = $config['redis']['password'] ?? null;
                if ($password) $redis->auth($password);
                $database = $config['redis']['database'] ?? 0;
                if ($database > 0) $redis->select($database);

                $prefix = ($config['redis']['prefix'] ?? 'webmail:') . 'addon_status';
                $keys = $redis->keys($prefix . '*');
                if (!empty($keys)) {
                    $redis->del($keys);
                }
            }
        } catch (\Throwable $e) {
            error_log("Addon invalidate: Redis clear failed: " . $e->getMessage());
        }

        // Re-fetch global statuses
        $addonService = new \Webmail\Services\AddonService($config);
        $statuses = $addonService->refreshStatus();
        return \Webmail\Core\Response::success($statuses, 'Addon cache invalidated');
    });

    // Panel-driven migration import: push contacts (VCF/CSV) or calendar
    // (ICS) data for ANY user during a migration, authenticated by the
    // shared Panel API key (no end-user JWT — the user logs in later).
    // Body: { user_email, type: 'contacts'|'calendar', format?, data,
    //         book_id?, calendar_id? }. Idempotent (UID-based upsert).
    $router->post('/internal/dav-import', function (Request $r) use ($config) {
        $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
        $validKey = $config['panel']['api_key'] ?? '';
        if (empty($apiKey) || empty($validKey) || !hash_equals($validKey, $apiKey)) {
            return \Webmail\Core\Response::unauthorized('Invalid or missing API key');
        }

        $userEmail = strtolower(trim((string) $r->input('user_email', '')));
        $type = strtolower((string) $r->input('type', ''));
        $data = (string) $r->input('data', '');
        if ($userEmail === '' || !filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
            return \Webmail\Core\Response::error('Valid user_email is required', 400);
        }
        if (trim($data) === '') {
            return \Webmail\Core\Response::error('No data provided', 400);
        }

        try {
            if ($type === 'contacts') {
                $svc = new \Webmail\Addons\Contacts\Services\AddressBookService($config);
                $bookId = (int) ($r->input('book_id', 0)
                    ?: $svc->getOrCreateDefaultAddressBook($userEmail)['id']);
                $format = strtolower((string) $r->input('format', ''));
                if ($format === '') {
                    $format = stripos($data, 'BEGIN:VCARD') !== false ? 'vcf' : 'csv';
                }
                $result = $format === 'csv'
                    ? $svc->importCsv($userEmail, $bookId, $data)
                    : $svc->importVcf($userEmail, $bookId, $data);
                return \Webmail\Core\Response::success($result, 'Contacts imported');
            }

            if ($type === 'calendar') {
                $importer = new \Webmail\Addons\Calendar\Services\IcsImportService($config);
                $calId = $importer->resolveCalendarId(
                    $userEmail,
                    $r->input('calendar_id') ? (int) $r->input('calendar_id') : null
                );
                $result = $importer->importIcs($userEmail, $calId, $data);
                return \Webmail\Core\Response::success($result, 'Calendar imported');
            }

            return \Webmail\Core\Response::error("Unknown import type '{$type}' (expected contacts|calendar)", 400);
        } catch (\Throwable $e) {
            error_log('dav-import error: ' . $e->getMessage());
            return \Webmail\Core\Response::serverError('Import failed: ' . $e->getMessage());
        }
    });

    // Panel-driven migration export: pull a user's CONTACTS (.vcf) or
    // CALENDAR (.ics) out of FlowOne during a migration/handover,
    // authenticated by the shared Panel API key (no end-user JWT).
    // Body: { user_email, type: 'contacts'|'calendar' }. Returns the raw
    // file payload as JSON so the Panel can stream it as a download.
    $router->post('/internal/dav-export', function (Request $r) use ($config) {
        $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
        $validKey = $config['panel']['api_key'] ?? '';
        if (empty($apiKey) || empty($validKey) || !hash_equals($validKey, $apiKey)) {
            return \Webmail\Core\Response::unauthorized('Invalid or missing API key');
        }

        $userEmail = strtolower(trim((string) $r->input('user_email', '')));
        $type = strtolower((string) $r->input('type', ''));
        if ($userEmail === '' || !filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
            return \Webmail\Core\Response::error('Valid user_email is required', 400);
        }
        if (!in_array($type, ['contacts', 'calendar'], true)) {
            return \Webmail\Core\Response::error("type must be 'contacts' or 'calendar'", 400);
        }

        try {
            $localPart = preg_replace('/[^a-z0-9_.-]+/i', '_', strstr($userEmail, '@', true) ?: $userEmail);

            if ($type === 'contacts') {
                $svc = new \Webmail\Addons\Contacts\Services\AddressBookService($config);
                $data = $svc->exportVcf($userEmail, null);
                $count = substr_count(strtoupper($data), 'BEGIN:VCARD');
                return \Webmail\Core\Response::success([
                    'data' => $data,
                    'filename' => $localPart . '-contacts.vcf',
                    'mime' => 'text/vcard',
                    'count' => $count,
                ], 'Contacts exported');
            }

            $calSvc = new \Webmail\Addons\Calendar\Services\CalendarService($config);
            $data = $calSvc->exportAllICS($userEmail);
            $count = substr_count(strtoupper($data), 'BEGIN:VEVENT');
            return \Webmail\Core\Response::success([
                'data' => $data,
                'filename' => $localPart . '-calendar.ics',
                'mime' => 'text/calendar',
                'count' => $count,
            ], 'Calendar exported');
        } catch (\Throwable $e) {
            error_log('dav-export error: ' . $e->getMessage());
            return \Webmail\Core\Response::serverError('Export failed: ' . $e->getMessage());
        }
    });

    // =========================================================================
    // CRM Pro Addon Routes (gated - only registered when addon is enabled)
    // =========================================================================
    // Performance: Only instantiate AddonService when the request URL might
    // match a CRM Pro route. This avoids a Redis connection on every request
    // (mail, calendar, drive, etc.) when the user is just using core features.
    $mayCrmRoute = str_contains($requestPath, '/portal/')
        || str_contains($requestPath, '/crm/')
        || str_contains($requestPath, '/billing/')
        || preg_match('#/clients/\d+/(tags|custom-fields|fields|call-log|meeting-notes|timeline)#', $requestPath);

    if ($mayCrmRoute && (new \Webmail\Services\AddonService($config, $routeGatingEmail))->isCrmProEnabled()) {
        // Portal routes (client-facing auth)
        $portal = new PortalController($config);

        // Portal auth (public - magic link consumption)
        $router->get('/portal/auth/{token}', fn(Request $r) => $portal->auth($r));
        $router->post('/portal/request-link', fn(Request $r) => $portal->requestLink($r));

        // Portal authenticated endpoints
        $router->get('/portal/me', fn(Request $r) => $portal->me($r));
        $router->post('/portal/logout', fn(Request $r) => $portal->logout($r));
        $router->get('/portal/updates', fn(Request $r) => $portal->getUpdates($r));
        $router->get('/portal/updates/{id}', fn(Request $r) => $portal->getUpdate($r));
        $router->post('/portal/updates/{id}/read', fn(Request $r) => $portal->markUpdateRead($r));
        $router->post('/portal/updates/{id}/comments', fn(Request $r) => $portal->addComment($r));
        $router->get('/portal/updates/{id}/files/{fileId}', fn(Request $r) => $portal->downloadUpdateFile($r));
        $router->get('/portal/documents', fn(Request $r) => $portal->getDocuments($r));
        $router->get('/portal/documents/{docId}', fn(Request $r) => $portal->getDocument($r));
        $router->get('/portal/documents/{docId}/download', fn(Request $r) => $portal->downloadDocument($r));
        $router->post('/portal/documents/{docId}/sign/upload', fn(Request $r) => $portal->signUpload($r));
        $router->post('/portal/documents/{docId}/sign/pad', fn(Request $r) => $portal->signPad($r));
        $router->post('/portal/documents/{docId}/reject', fn(Request $r) => $portal->rejectDocument($r));
        $router->get('/portal/documents/{docId}/zones', fn(Request $r) => $portal->getPortalZones($r));
        $router->get('/portal/documents/{docId}/annotations', fn(Request $r) => $portal->getPortalAnnotations($r));
        $router->post('/portal/documents/{docId}/annotations', fn(Request $r) => $portal->createPortalAnnotation($r));
        $router->post('/portal/documents/{docId}/annotations/{annotationId}/comments', fn(Request $r) => $portal->createPortalAnnotationComment($r));
        $router->get('/portal/calls', fn(Request $r) => $portal->getCalls($r));
        $router->post('/portal/calls/{callId}/join', fn(Request $r) => $portal->joinCall($r));
        $router->post('/portal/calls/{callId}/end', fn(Request $r) => $portal->endPortalCall($r));

        // Internal CRM endpoints (portal management)
        $router->post('/clients/{id}/portal/grant', fn(Request $r) => $portal->grantAccess($r));
        $router->delete('/clients/{id}/portal/revoke/{accessId}', fn(Request $r) => $portal->revokeAccess($r));
        $router->get('/clients/{id}/portal/access', fn(Request $r) => $portal->listAccess($r));
        $router->post('/clients/{id}/portal/send-link', fn(Request $r) => $portal->sendMagicLink($r));
        $router->post('/clients/{id}/portal/generate-link', fn(Request $r) => $portal->generateLink($r));

        // Internal CRM: Updates
        $router->post('/clients/{id}/portal/updates', fn(Request $r) => $portal->createUpdate($r));
        $router->get('/clients/{id}/portal/updates', fn(Request $r) => $portal->listClientUpdates($r));
        $router->post('/clients/{id}/portal/updates/{updateId}/comments', fn(Request $r) => $portal->addInternalComment($r));
        $router->post('/clients/{id}/portal/updates/{updateId}/files', fn(Request $r) => $portal->attachUpdateFile($r));

        // Internal CRM: Document board check + documents
        $router->get('/clients/{id}/portal/check-board', fn(Request $r) => $portal->checkClientBoard($r));
        $router->post('/clients/{id}/portal/documents', fn(Request $r) => $portal->createDocument($r));
        $router->put('/clients/{id}/portal/documents/{docId}', fn(Request $r) => $portal->updateDocument($r));
        $router->post('/clients/{id}/portal/documents/{docId}/send', fn(Request $r) => $portal->sendDocument($r));
        $router->post('/clients/{id}/portal/documents/{docId}/remind', fn(Request $r) => $portal->remindDocument($r));
        $router->get('/clients/{id}/portal/documents', fn(Request $r) => $portal->listClientDocuments($r));
        $router->get('/clients/{id}/portal/documents/{docId}/audit', fn(Request $r) => $portal->getDocumentAudit($r));
        $router->get('/clients/{id}/portal/documents/{docId}/signed-file/{signerId}', fn(Request $r) => $portal->downloadSignedFile($r));
        $router->post('/clients/{id}/portal/documents/{docId}/zones', fn(Request $r) => $portal->saveZones($r));
        $router->get('/clients/{id}/portal/documents/{docId}/zones', fn(Request $r) => $portal->getZones($r));
        $router->get('/clients/{id}/portal/documents/{docId}/download-internal', fn(Request $r) => $portal->downloadDocumentInternal($r));
        $router->get('/clients/{id}/portal/documents/{docId}/signed-pdf', fn(Request $r) => $portal->downloadSignedPdf($r));

        // Internal CRM: Document View Together
        $router->post('/clients/{id}/portal/documents/{docId}/view-session', fn(Request $r) => $portal->startDocViewSession($r));
        $router->delete('/clients/{id}/portal/documents/{docId}/view-session', fn(Request $r) => $portal->endDocViewSession($r));
        $router->put('/clients/{id}/portal/documents/{docId}/view-session/sync', fn(Request $r) => $portal->syncDocViewPosition($r));

        // Internal CRM: Document annotations
        $router->get('/clients/{id}/portal/documents/{docId}/annotations', fn(Request $r) => $portal->getAnnotations($r));
        $router->post('/clients/{id}/portal/documents/{docId}/annotations', fn(Request $r) => $portal->createAnnotation($r));
        $router->put('/clients/{id}/portal/documents/{docId}/annotations/{annotationId}', fn(Request $r) => $portal->updateAnnotation($r));
        $router->delete('/clients/{id}/portal/documents/{docId}/annotations/{annotationId}', fn(Request $r) => $portal->deleteAnnotation($r));
        $router->post('/clients/{id}/portal/documents/{docId}/annotations/{annotationId}/comments', fn(Request $r) => $portal->createAnnotationComment($r));
        $router->delete('/clients/{id}/portal/documents/{docId}/annotations/{annotationId}/comments/{commentId}', fn(Request $r) => $portal->deleteAnnotationComment($r));

        // Internal CRM: Calls
        $router->get('/clients/{id}/portal/calls', fn(Request $r) => $portal->listClientCalls($r));
        $router->post('/clients/{id}/portal/calls', fn(Request $r) => $portal->createCall($r));
        $router->post('/clients/{id}/portal/calls/{callId}/end', fn(Request $r) => $portal->endClientCall($r));
        $router->post('/clients/{id}/portal/calls/{callId}/cancel', fn(Request $r) => $portal->cancelCall($r));

        // Guest Call: internal endpoints (create guest link, resend transcript)
        $guestCall = new GuestCallController($config);
        $router->post('/clients/{id}/portal/calls/{callId}/guest-link', fn(Request $r) => $guestCall->createGuestLink($r));
        $router->post('/clients/{id}/portal/calls/{callId}/transcript', fn(Request $r) => $guestCall->resendTranscript($r));

        // CRM Invoicing
        $crmInvoice = new CrmInvoiceController($config);
        $router->get('/crm/invoices', fn(Request $r) => $crmInvoice->list($r));
        $router->get('/crm/invoices/{id}', fn(Request $r) => $crmInvoice->get($r));
        $router->post('/crm/invoices', fn(Request $r) => $crmInvoice->create($r));
        $router->put('/crm/invoices/{id}', fn(Request $r) => $crmInvoice->update($r));
        $router->delete('/crm/invoices/{id}', fn(Request $r) => $crmInvoice->delete($r));
        $router->post('/crm/invoices/{id}/send', fn(Request $r) => $crmInvoice->send($r));
        $router->post('/crm/invoices/{id}/payment', fn(Request $r) => $crmInvoice->recordPayment($r));
        $router->get('/crm/invoices/{id}/pdf', fn(Request $r) => $crmInvoice->generatePdf($r));

        // Billing Provider Integration
        $billing = new BillingController($config);
        $router->get('/billing/settings', fn(Request $r) => $billing->getSettings($r));
        $router->put('/billing/settings', fn(Request $r) => $billing->saveSettings($r));
        $router->post('/billing/test-connection', fn(Request $r) => $billing->testConnection($r));
        $router->get('/billing/invoice-blocks', fn(Request $r) => $billing->getInvoiceBlocks($r));
        $router->post('/crm/invoices/{id}/push', fn(Request $r) => $billing->pushToProvider($r));
        $router->get('/crm/invoices/{id}/download-pdf', fn(Request $r) => $billing->downloadPdf($r));
        $router->post('/crm/invoices/{id}/sync-status', fn(Request $r) => $billing->syncStatus($r));
        $router->post('/crm/invoices/{id}/cancel-external', fn(Request $r) => $billing->cancelExternal($r));
        $router->post('/crm/invoices/{id}/send-email', fn(Request $r) => $billing->sendEmail($r));

        $router->get('/crm/expenses', fn(Request $r) => $crmInvoice->listExpenses($r));
        $router->post('/crm/expenses', fn(Request $r) => $crmInvoice->createExpense($r));
        $router->put('/crm/expenses/{id}', fn(Request $r) => $crmInvoice->updateExpense($r));
        $router->delete('/crm/expenses/{id}', fn(Request $r) => $crmInvoice->deleteExpense($r));

        // CRM Deals / Pipeline
        $crmDeal = new CrmDealController($config);
        $router->get('/crm/deals', fn(Request $r) => $crmDeal->list($r));
        $router->get('/crm/deals/pipeline', fn(Request $r) => $crmDeal->pipeline($r));
        $router->get('/crm/deals/{id}', fn(Request $r) => $crmDeal->get($r));
        $router->post('/crm/deals', fn(Request $r) => $crmDeal->create($r));
        $router->put('/crm/deals/{id}', fn(Request $r) => $crmDeal->update($r));
        $router->delete('/crm/deals/{id}', fn(Request $r) => $crmDeal->delete($r));
        $router->put('/crm/deals/{id}/stage', fn(Request $r) => $crmDeal->updateStage($r));

        // CRM Tags & Custom Fields
        $router->get('/crm/tags', fn(Request $r) => $crmDeal->listTags($r));
        $router->post('/crm/tags', fn(Request $r) => $crmDeal->createTag($r));
        $router->put('/crm/tags/{id}', fn(Request $r) => $crmDeal->updateTag($r));
        $router->delete('/crm/tags/{id}', fn(Request $r) => $crmDeal->deleteTag($r));
        $router->post('/clients/{id}/tags', fn(Request $r) => $crmDeal->assignTag($r));
        $router->delete('/clients/{id}/tags/{tagId}', fn(Request $r) => $crmDeal->removeTag($r));
        $router->get('/clients/{id}/tags', fn(Request $r) => $crmDeal->getClientTags($r));

        // CRM Custom Fields
        $router->get('/crm/custom-fields', fn(Request $r) => $crmDeal->listFieldDefinitions($r));
        $router->post('/crm/custom-fields', fn(Request $r) => $crmDeal->createFieldDefinition($r));
        $router->put('/crm/custom-fields/{id}', fn(Request $r) => $crmDeal->updateFieldDefinition($r));
        $router->delete('/crm/custom-fields/{id}', fn(Request $r) => $crmDeal->deleteFieldDefinition($r));
        $router->get('/clients/{id}/custom-fields', fn(Request $r) => $crmDeal->getClientFieldValues($r));
        $router->put('/clients/{id}/custom-fields', fn(Request $r) => $crmDeal->setClientFieldValues($r));

        // CRM Custom Fields
        $router->get('/crm/fields', fn(Request $r) => $crmDeal->listFields($r));
        $router->post('/crm/fields', fn(Request $r) => $crmDeal->createField($r));
        $router->put('/crm/fields/{id}', fn(Request $r) => $crmDeal->updateField($r));
        $router->delete('/crm/fields/{id}', fn(Request $r) => $crmDeal->deleteField($r));
        $router->put('/clients/{id}/fields', fn(Request $r) => $crmDeal->saveFieldValues($r));

        // CRM Reminders
        $router->get('/crm/reminders', fn(Request $r) => $crmDeal->listReminders($r));
        $router->post('/crm/reminders', fn(Request $r) => $crmDeal->createReminder($r));
        $router->put('/crm/reminders/{id}', fn(Request $r) => $crmDeal->updateReminder($r));
        $router->delete('/crm/reminders/{id}', fn(Request $r) => $crmDeal->deleteReminder($r));
        $router->post('/crm/reminders/{id}/complete', fn(Request $r) => $crmDeal->completeReminder($r));

        // CRM Call Log
        $router->get('/clients/{id}/call-log', fn(Request $r) => $crmDeal->getCallLog($r));
        $router->post('/clients/{id}/call-log', fn(Request $r) => $crmDeal->createCallLog($r));

        // CRM Meeting Notes
        $router->get('/clients/{id}/meeting-notes', fn(Request $r) => $crmDeal->getMeetingNotes($r));
        $router->post('/clients/{id}/meeting-notes', fn(Request $r) => $crmDeal->createMeetingNote($r));
        $router->put('/clients/{id}/meeting-notes/{noteId}', fn(Request $r) => $crmDeal->updateMeetingNote($r));

        // CRM Timeline
        $router->get('/clients/{id}/timeline', fn(Request $r) => $crmDeal->getTimeline($r));

        // CRM Reporting
        $router->get('/crm/dashboard', fn(Request $r) => $crmDeal->getDashboard($r));
        $router->get('/crm/reports/revenue', fn(Request $r) => $crmDeal->getRevenueReport($r));
        $router->get('/crm/reports/pipeline', fn(Request $r) => $crmDeal->getPipelineReport($r));
        $router->get('/crm/reports/health', fn(Request $r) => $crmDeal->getHealthReport($r));

        // CRM Deal Activity
        $router->get('/crm/deals/{id}/activity', fn(Request $r) => $crmDeal->getDealActivity($r));

        // CRM Advanced Reporting
        $router->get('/crm/reports/aging', fn(Request $r) => $crmDeal->getAgingReport($r));
        $router->get('/crm/reports/client-ranking', fn(Request $r) => $crmDeal->getClientRanking($r));
        $router->get('/crm/reports/profitability', fn(Request $r) => $crmDeal->getProfitabilityReport($r));
        $router->get('/crm/reports/forecast', fn(Request $r) => $crmDeal->getForecastReport($r));
        $router->get('/crm/reports/funnel', fn(Request $r) => $crmDeal->getFunnelReport($r));

        // CRM Internal Sharing
        $crmSharing = new CrmSharingController($config);
        $router->get('/crm/sharing', fn(Request $r) => $crmSharing->index($r));
        $router->post('/crm/sharing/colleague', fn(Request $r) => $crmSharing->shareWithColleague($r));
        $router->post('/crm/sharing/group', fn(Request $r) => $crmSharing->shareWithGroup($r));
        $router->get('/crm/sharing/check', fn(Request $r) => $crmSharing->checkAccess($r));
        $router->get('/crm/sharing/activity', fn(Request $r) => $crmSharing->getActivity($r));
        $router->put('/crm/sharing/{id}', fn(Request $r) => $crmSharing->updatePermission($r));
        $router->delete('/crm/sharing/{id}', fn(Request $r) => $crmSharing->revoke($r));

        // CRM Automation Engine
        $crmAutomation = new CrmAutomationController($config);
        $router->get('/crm/automation/rules', fn(Request $r) => $crmAutomation->listRules($r));
        $router->post('/crm/automation/rules', fn(Request $r) => $crmAutomation->createRule($r));
        $router->put('/crm/automation/rules/{id}', fn(Request $r) => $crmAutomation->updateRule($r));
        $router->delete('/crm/automation/rules/{id}', fn(Request $r) => $crmAutomation->deleteRule($r));
        $router->post('/crm/automation/rules/{id}/toggle', fn(Request $r) => $crmAutomation->toggleRule($r));
        $router->post('/crm/automation/rules/{id}/test', fn(Request $r) => $crmAutomation->testRule($r));
        $router->get('/crm/automation/rules/{id}/shares', fn(Request $r) => $crmAutomation->getRuleShares($r));
        $router->post('/crm/automation/rules/{id}/duplicate', fn(Request $r) => $crmAutomation->duplicateRule($r));
        $router->get('/crm/automation/log', fn(Request $r) => $crmAutomation->getLog($r));

        // CRM Email Sequences
        $router->get('/crm/sequences', fn(Request $r) => $crmAutomation->listSequences($r));
        $router->post('/crm/sequences', fn(Request $r) => $crmAutomation->createSequence($r));
        $router->put('/crm/sequences/{id}', fn(Request $r) => $crmAutomation->updateSequence($r));
        $router->delete('/crm/sequences/{id}', fn(Request $r) => $crmAutomation->deleteSequence($r));
        $router->post('/crm/sequences/{id}/enroll', fn(Request $r) => $crmAutomation->enrollInSequence($r));
        $router->get('/crm/sequences/{id}/enrollments', fn(Request $r) => $crmAutomation->getEnrollments($r));
        $router->post('/crm/sequences/enrollments/{id}/cancel', fn(Request $r) => $crmAutomation->cancelEnrollment($r));
    }

    // Onboarding Quiz
    $onboarding = new OnboardingController($config);
    $router->post('/onboarding/quiz-score', fn(Request $r) => $onboarding->saveQuizScore($r));
    $router->get('/onboarding/quiz-score', fn(Request $r) => $onboarding->getQuizScore($r));

    // Onboarding Profile (addon preferences from setup wizard)
    $onboardingProfile = new \Webmail\Controllers\OnboardingProfileController($config);
    $router->post('/onboarding/addon-profile', fn(Request $r) => $onboardingProfile->save($r));
    $router->get('/onboarding/addon-profile', fn(Request $r) => $onboardingProfile->get($r));

    // Collaborative Editing routes (Documents & Presentations with TipTap)
    $collabRoutesFile = __DIR__ . '/../collab/backend/routes.php';
    if (file_exists($collabRoutesFile)) {
        try {
            require_once $collabRoutesFile;
            registerCollabRoutes($router, $config);
        } catch (\Throwable $e) {
            error_log('[Routes] Failed to register collab routes: ' . $e->getMessage());
        }
    } else {
        error_log('[Routes] Collab routes file not found at: ' . $collabRoutesFile);
    }
};
