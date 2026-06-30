<?php

namespace Webmail\Addons\AutomationHub\Controllers;

use Webmail\Controllers\BaseController;
use Webmail\Core\Request;
use Webmail\Core\Response;

class WorkflowController extends BaseController
{
    private ?\PDO $db = null;

    private function getDb(): \PDO
    {
        if (!$this->db) {
            $dsn = "mysql:host={$this->config['db']['host']};dbname={$this->config['db']['name']};charset=utf8mb4";
            $this->db = new \PDO($dsn, $this->config['db']['user'], $this->config['db']['pass'], [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]);
        }
        return $this->db;
    }

    public function list(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $db = $this->getDb();
        $stmt = $db->prepare("
            SELECT id, user_email, name, description, is_active, category, 
                   run_count, last_run_at, created_at, updated_at
            FROM automation_hub_workflows
            WHERE user_email = ?
            ORDER BY updated_at DESC
        ");
        $stmt->execute([$this->userEmail]);

        return Response::success(['workflows' => $stmt->fetchAll()]);
    }

    public function get(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $id = (int)$request->param('id');
        $db = $this->getDb();

        $stmt = $db->prepare("SELECT * FROM automation_hub_workflows WHERE id = ? AND user_email = ?");
        $stmt->execute([$id, $this->userEmail]);
        $workflow = $stmt->fetch();

        if (!$workflow) {
            return Response::notFound('Workflow not found');
        }

        if ($workflow['canvas_data']) {
            $workflow['canvas_data'] = json_decode($workflow['canvas_data'], true);
        }

        $stmtNodes = $db->prepare("SELECT * FROM automation_hub_nodes WHERE workflow_id = ?");
        $stmtNodes->execute([$id]);
        $nodes = $stmtNodes->fetchAll();
        foreach ($nodes as &$n) {
            if ($n['config']) $n['config'] = json_decode($n['config'], true);
            if ($n['meta']) $n['meta'] = json_decode($n['meta'], true);
        }

        $stmtEdges = $db->prepare("SELECT * FROM automation_hub_edges WHERE workflow_id = ?");
        $stmtEdges->execute([$id]);

        return Response::success([
            'workflow' => $workflow,
            'nodes' => $nodes,
            'edges' => $stmtEdges->fetchAll(),
        ]);
    }

    public function create(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $name = $request->input('name', 'New Workflow');
        $description = $request->input('description', '');
        $category = $request->input('category', 'custom');

        $db = $this->getDb();
        $stmt = $db->prepare("
            INSERT INTO automation_hub_workflows (user_email, name, description, category)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$this->userEmail, $name, $description, $category]);
        $id = (int)$db->lastInsertId();

        $stmt = $db->prepare("SELECT * FROM automation_hub_workflows WHERE id = ?");
        $stmt->execute([$id]);

        return Response::success(['workflow' => $stmt->fetch()], 'Workflow created');
    }

    public function update(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $id = (int)$request->param('id');
        $db = $this->getDb();

        $stmt = $db->prepare("SELECT id FROM automation_hub_workflows WHERE id = ? AND user_email = ?");
        $stmt->execute([$id, $this->userEmail]);
        if (!$stmt->fetch()) {
            return Response::notFound('Workflow not found');
        }

        $name = $request->input('name');
        $description = $request->input('description');
        $category = $request->input('category');
        $canvasData = $request->input('canvas_data');
        $nodes = $request->input('nodes');
        $edges = $request->input('edges');

        $db->beginTransaction();
        try {
            $updates = [];
            $params = [];
            if ($name !== null) { $updates[] = 'name = ?'; $params[] = $name; }
            if ($description !== null) { $updates[] = 'description = ?'; $params[] = $description; }
            if ($category !== null) { $updates[] = 'category = ?'; $params[] = $category; }
            if ($canvasData !== null) { $updates[] = 'canvas_data = ?'; $params[] = json_encode($canvasData); }

            if (!empty($updates)) {
                $params[] = $id;
                $db->prepare("UPDATE automation_hub_workflows SET " . implode(', ', $updates) . " WHERE id = ?")->execute($params);
            }

            if ($nodes !== null) {
                $db->prepare("DELETE FROM automation_hub_nodes WHERE workflow_id = ?")->execute([$id]);
                $stmtNode = $db->prepare("
                    INSERT INTO automation_hub_nodes (workflow_id, node_uid, node_type, node_category, label, config, position_x, position_y, meta)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                foreach ($nodes as $n) {
                    $stmtNode->execute([
                        $id,
                        $n['node_uid'],
                        $n['node_type'],
                        $n['node_category'],
                        $n['label'] ?? null,
                        json_encode($n['config'] ?? []),
                        $n['position_x'] ?? 0,
                        $n['position_y'] ?? 0,
                        json_encode($n['meta'] ?? []),
                    ]);
                }
            }

            if ($edges !== null) {
                $db->prepare("DELETE FROM automation_hub_edges WHERE workflow_id = ?")->execute([$id]);
                $stmtEdge = $db->prepare("
                    INSERT INTO automation_hub_edges (workflow_id, source_node_uid, target_node_uid, source_port, target_port, edge_style)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                foreach ($edges as $e) {
                    $stmtEdge->execute([
                        $id,
                        $e['source_node_uid'],
                        $e['target_node_uid'],
                        $e['source_port'] ?? 'output',
                        $e['target_port'] ?? 'input',
                        $e['edge_style'] ?? 'solid',
                    ]);
                }
            }

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            return Response::error('Failed to update workflow: ' . $e->getMessage(), 500);
        }

        return Response::success([], 'Workflow updated');
    }

    public function delete(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $id = (int)$request->param('id');
        $db = $this->getDb();

        $stmt = $db->prepare("DELETE FROM automation_hub_workflows WHERE id = ? AND user_email = ?");
        $stmt->execute([$id, $this->userEmail]);

        if ($stmt->rowCount() === 0) {
            return Response::notFound('Workflow not found');
        }

        return Response::success([], 'Workflow deleted');
    }

    public function toggle(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $id = (int)$request->param('id');
        $db = $this->getDb();

        $stmt = $db->prepare("SELECT is_active FROM automation_hub_workflows WHERE id = ? AND user_email = ?");
        $stmt->execute([$id, $this->userEmail]);
        $wf = $stmt->fetch();

        if (!$wf) {
            return Response::notFound('Workflow not found');
        }

        $newState = $wf['is_active'] ? 0 : 1;
        $db->prepare("UPDATE automation_hub_workflows SET is_active = ? WHERE id = ?")->execute([$newState, $id]);

        return Response::success(['is_active' => (bool)$newState], $newState ? 'Workflow activated' : 'Workflow deactivated');
    }

    public function duplicate(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $id = (int)$request->param('id');
        $db = $this->getDb();

        $stmt = $db->prepare("SELECT * FROM automation_hub_workflows WHERE id = ? AND user_email = ?");
        $stmt->execute([$id, $this->userEmail]);
        $wf = $stmt->fetch();

        if (!$wf) {
            return Response::notFound('Workflow not found');
        }

        $db->beginTransaction();
        try {
            $stmt = $db->prepare("
                INSERT INTO automation_hub_workflows (user_email, name, description, category, canvas_data, is_active)
                VALUES (?, ?, ?, ?, ?, 0)
            ");
            $stmt->execute([$this->userEmail, $wf['name'] . ' (copy)', $wf['description'], $wf['category'], $wf['canvas_data']]);
            $newId = (int)$db->lastInsertId();

            $stmtNodes = $db->prepare("SELECT * FROM automation_hub_nodes WHERE workflow_id = ?");
            $stmtNodes->execute([$id]);
            $nodes = $stmtNodes->fetchAll();

            $uidMap = [];
            $stmtInsert = $db->prepare("
                INSERT INTO automation_hub_nodes (workflow_id, node_uid, node_type, node_category, label, config, position_x, position_y, meta)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            foreach ($nodes as $n) {
                $newUid = $this->generateUuid();
                $uidMap[$n['node_uid']] = $newUid;
                $stmtInsert->execute([$newId, $newUid, $n['node_type'], $n['node_category'], $n['label'], $n['config'], $n['position_x'], $n['position_y'], $n['meta']]);
            }

            $stmtEdges = $db->prepare("SELECT * FROM automation_hub_edges WHERE workflow_id = ?");
            $stmtEdges->execute([$id]);

            $stmtEdgeInsert = $db->prepare("
                INSERT INTO automation_hub_edges (workflow_id, source_node_uid, target_node_uid, source_port, target_port, edge_style)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            foreach ($stmtEdges->fetchAll() as $e) {
                $srcUid = $uidMap[$e['source_node_uid']] ?? $e['source_node_uid'];
                $tgtUid = $uidMap[$e['target_node_uid']] ?? $e['target_node_uid'];
                $stmtEdgeInsert->execute([$newId, $srcUid, $tgtUid, $e['source_port'], $e['target_port'], $e['edge_style']]);
            }

            $db->commit();

            $stmt = $db->prepare("SELECT * FROM automation_hub_workflows WHERE id = ?");
            $stmt->execute([$newId]);
            return Response::success(['workflow' => $stmt->fetch()], 'Workflow duplicated');
        } catch (\Throwable $e) {
            $db->rollBack();
            return Response::error('Duplicate failed: ' . $e->getMessage(), 500);
        }
    }

    public function downloadExport(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $filename = $request->getParam('filename');
        if (!$filename || !preg_match('/^[a-f0-9]+_[a-zA-Z0-9._-]+\.csv$/i', $filename)) {
            return Response::badRequest('Invalid filename');
        }

        $exportDir = dirname(__DIR__, 4) . '/storage/exports';
        $filePath = $exportDir . '/' . $filename;

        if (!file_exists($filePath)) {
            return Response::notFound('Export file not found');
        }

        $displayName = preg_replace('/^[a-f0-9]+_/', '', $filename);

        header('Content-Type: text/csv; charset=utf-8');
        header($this->safeContentDisposition('attachment', $displayName));
        header('Content-Length: ' . filesize($filePath));
        header('Cache-Control: no-cache');

        readfile($filePath);
        exit;
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
