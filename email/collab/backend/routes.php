<?php
/**
 * Collab Routes
 * 
 * Register these routes in your main application router.
 * Routes are relative (without /api prefix - the server/frontend adds that).
 * 
 * Example integration in backend/routes.php:
 *   require_once __DIR__ . '/../collab/backend/routes.php';
 *   registerCollabRoutes($router, $config);
 */

use Webmail\Core\Request;
use Collab\Controllers\CollabController;

/**
 * Register collab routes
 * 
 * @param object $router - Your application's router instance
 * @param array $config - Application configuration
 */
function registerCollabRoutes($router, array $config): void
{
    $collab = new CollabController($config);
    
    // Document CRUD
    $router->get('/collab/documents', fn(Request $r) => $collab->listDocuments($r));
    $router->get('/collab/documents/{uuid}', fn(Request $r) => $collab->getDocument($r));
    $router->post('/collab/documents', fn(Request $r) => $collab->createDocument($r));
    $router->post('/collab/documents/from-file', fn(Request $r) => $collab->createFromFile($r));
    $router->put('/collab/documents/{uuid}', fn(Request $r) => $collab->updateDocument($r));
    $router->patch('/collab/documents/{uuid}', fn(Request $r) => $collab->updateDocument($r));
    $router->delete('/collab/documents/{uuid}', fn(Request $r) => $collab->deleteDocument($r));
    
    // Document actions
    $router->post('/collab/documents/{uuid}/duplicate', fn(Request $r) => $collab->duplicateDocument($r));
    $router->get('/collab/documents/{uuid}/collab-token', fn(Request $r) => $collab->getCollabToken($r));
    $router->post('/collab/documents/{uuid}/save-to-drive', fn(Request $r) => $collab->saveToDrive($r));
    
    // Permissions
    $router->get('/collab/documents/{uuid}/permissions', fn(Request $r) => $collab->listPermissions($r));
    $router->post('/collab/documents/{uuid}/permissions', fn(Request $r) => $collab->addPermission($r));
    $router->put('/collab/documents/{uuid}/permissions/{email}', fn(Request $r) => $collab->updatePermission($r));
    $router->patch('/collab/documents/{uuid}/permissions/{email}', fn(Request $r) => $collab->updatePermission($r));
    $router->delete('/collab/documents/{uuid}/permissions/{email}', fn(Request $r) => $collab->removePermission($r));
    
    // Version history
    $router->get('/collab/documents/{uuid}/versions', fn(Request $r) => $collab->listVersions($r));
    $router->post('/collab/documents/{uuid}/versions', fn(Request $r) => $collab->createVersion($r));
    $router->post('/collab/documents/{uuid}/versions/{version}/restore', fn(Request $r) => $collab->restoreVersion($r));
    
    // Auth verification (called by Hocuspocus server)
    $router->post('/collab/auth/verify', fn(Request $r) => $collab->verifyAuthToken($r));
}
