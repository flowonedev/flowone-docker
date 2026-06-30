<?php

namespace VpsAdmin\Api\Controllers;

use VpsAdmin\Api\Core\Request;
use VpsAdmin\Api\Core\Response;

/**
 * User Management Controller
 * 
 * Handles CRUD operations for panel users.
 * super_admin: full access to all users
 * admin: can manage admin + user roles, cannot see/manage super_admin accounts
 */
class UserController extends BaseController
{
    /**
     * List all users
     */
    public function index(Request $request): Response
    {
        $roleCheck = $this->requireAdmin();
        if ($roleCheck) return $roleCheck;
        
        $db = $this->container->getDatabase();
        
        $status = $request->getQuery('status');
        $role = $request->getQuery('role');
        $search = $request->getQuery('search');
        
        $sql = "SELECT id, username, email, role, status, created_at, last_login FROM admin_users WHERE 1=1";
        $params = [];
        
        if (!$this->isSuperAdmin()) {
            $sql .= " AND role != 'super_admin'";
        }
        
        if ($status) {
            $sql .= " AND status = ?";
            $params[] = $status;
        }
        
        if ($role) {
            $sql .= " AND role = ?";
            $params[] = $role;
        }
        
        if ($search) {
            $sql .= " AND (username LIKE ? OR email LIKE ?)";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }
        
        $sql .= " ORDER BY created_at DESC";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $users = $stmt->fetchAll();
        
        foreach ($users as &$user) {
            $stmt = $db->prepare("SELECT COUNT(*) FROM user_sites WHERE user_id = ?");
            $stmt->execute([$user['id']]);
            $user['site_count'] = (int)$stmt->fetchColumn();
        }
        
        return Response::success([
            'users' => $users,
            'count' => count($users),
        ]);
    }

    /**
     * Get single user details
     */
    public function show(Request $request): Response
    {
        $roleCheck = $this->requireAdmin();
        if ($roleCheck) return $roleCheck;
        
        $id = (int)$request->getParam('id');
        $db = $this->container->getDatabase();
        
        $stmt = $db->prepare("SELECT id, username, email, role, status, created_at, last_login FROM admin_users WHERE id = ?");
        $stmt->execute([$id]);
        $user = $stmt->fetch();
        
        if (!$user) {
            return Response::notFound('User not found');
        }
        
        if (!$this->isSuperAdmin() && $user['role'] === 'super_admin') {
            return Response::notFound('User not found');
        }
        
        $stmt = $db->prepare("SELECT domain, created_at FROM user_sites WHERE user_id = ? ORDER BY domain");
        $stmt->execute([$id]);
        $user['sites'] = $stmt->fetchAll();
        
        return Response::success(['user' => $user]);
    }

    /**
     * Create a new user
     */
    public function create(Request $request): Response
    {
        $roleCheck = $this->requireAdmin();
        if ($roleCheck) return $roleCheck;
        
        $validation = $this->validateRequired($request, ['username', 'password']);
        if ($validation) return $validation;
        
        $username = trim($request->input('username'));
        $password = $request->input('password');
        $email = $request->input('email');
        $role = $request->input('role', 'user');
        $status = $request->input('status', 'active');
        
        if (!preg_match('/^[a-zA-Z0-9_]{3,50}$/', $username)) {
            return Response::validationError([
                'username' => 'Username must be 3-50 characters, alphanumeric and underscores only'
            ]);
        }
        
        if (strlen($password) < 12) {
            return Response::validationError([
                'password' => 'Password must be at least 12 characters'
            ]);
        }
        
        $allowedRoles = $this->isSuperAdmin()
            ? ['super_admin', 'admin', 'user']
            : ['admin', 'user'];
        
        if (!in_array($role, $allowedRoles)) {
            return Response::validationError(['role' => 'Invalid role']);
        }
        
        if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return Response::validationError(['email' => 'Invalid email format']);
        }
        
        $db = $this->container->getDatabase();
        
        $stmt = $db->prepare("SELECT id FROM admin_users WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            return Response::error('Username already exists', 409);
        }
        
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        
        $stmt = $db->prepare("
            INSERT INTO admin_users (username, password_hash, email, role, status)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$username, $passwordHash, $email, $role, $status]);
        
        $userId = $db->lastInsertId();
        
        $sites = $request->input('sites', []);
        if (!empty($sites) && is_array($sites)) {
            $stmt = $db->prepare("INSERT INTO user_sites (user_id, domain) VALUES (?, ?)");
            foreach ($sites as $domain) {
                $stmt->execute([$userId, $domain]);
            }
        }
        
        $this->logAction('user.create', $username, 'success', [
            'role' => $role,
            'sites_assigned' => count($sites),
        ]);
        
        return Response::success([
            'id' => $userId,
            'username' => $username,
        ], 'User created successfully', 201);
    }

    /**
     * Update a user
     */
    public function update(Request $request): Response
    {
        $roleCheck = $this->requireAdmin();
        if ($roleCheck) return $roleCheck;
        
        $id = (int)$request->getParam('id');
        $db = $this->container->getDatabase();
        
        $stmt = $db->prepare("SELECT * FROM admin_users WHERE id = ?");
        $stmt->execute([$id]);
        $user = $stmt->fetch();
        
        if (!$user) {
            return Response::notFound('User not found');
        }
        
        if (!$this->isSuperAdmin() && $user['role'] === 'super_admin') {
            return Response::notFound('User not found');
        }
        
        $currentUser = $this->getCurrentUser();
        if ($currentUser && $currentUser->sub == $id) {
            $newRole = $request->input('role');
            if ($newRole && $newRole !== $user['role']) {
                return Response::error('Cannot change your own role');
            }
        }
        
        $updates = [];
        $params = [];
        
        if ($request->has('email')) {
            $email = $request->input('email');
            if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return Response::validationError(['email' => 'Invalid email format']);
            }
            $updates[] = 'email = ?';
            $params[] = $email;
        }
        
        if ($request->has('role')) {
            $role = $request->input('role');
            $allowedRoles = $this->isSuperAdmin()
                ? ['super_admin', 'admin', 'user']
                : ['admin', 'user'];
            
            if (!in_array($role, $allowedRoles)) {
                return Response::validationError(['role' => 'Invalid role']);
            }
            $updates[] = 'role = ?';
            $params[] = $role;
        }
        
        if ($request->has('status')) {
            $status = $request->input('status');
            if (!in_array($status, ['active', 'suspended'])) {
                return Response::validationError(['status' => 'Invalid status']);
            }
            $updates[] = 'status = ?';
            $params[] = $status;
        }
        
        if ($request->has('password')) {
            $password = $request->input('password');
            if (strlen($password) < 12) {
                return Response::validationError(['password' => 'Password must be at least 12 characters']);
            }
            $updates[] = 'password_hash = ?';
            $params[] = password_hash($password, PASSWORD_DEFAULT);
        }
        
        if (!empty($updates)) {
            $params[] = $id;
            $sql = "UPDATE admin_users SET " . implode(', ', $updates) . " WHERE id = ?";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
        }
        
        if ($request->has('sites')) {
            $sites = $request->input('sites', []);
            
            $stmt = $db->prepare("DELETE FROM user_sites WHERE user_id = ?");
            $stmt->execute([$id]);
            
            if (!empty($sites) && is_array($sites)) {
                $stmt = $db->prepare("INSERT INTO user_sites (user_id, domain) VALUES (?, ?)");
                foreach ($sites as $domain) {
                    $stmt->execute([$id, $domain]);
                }
            }
        }
        
        $this->logAction('user.update', $user['username'], 'success', [
            'fields_updated' => array_keys($request->all()),
        ]);
        
        return Response::success(null, 'User updated successfully');
    }

    /**
     * Delete a user
     */
    public function delete(Request $request): Response
    {
        $roleCheck = $this->requireAdmin();
        if ($roleCheck) return $roleCheck;
        
        $id = (int)$request->getParam('id');
        $db = $this->container->getDatabase();
        
        $stmt = $db->prepare("SELECT username, role FROM admin_users WHERE id = ?");
        $stmt->execute([$id]);
        $user = $stmt->fetch();
        
        if (!$user) {
            return Response::notFound('User not found');
        }
        
        if (!$this->isSuperAdmin() && $user['role'] === 'super_admin') {
            return Response::notFound('User not found');
        }
        
        if (!$this->isSuperAdmin() && $user['role'] === 'admin') {
            $currentUser = $this->getCurrentUser();
            if ($currentUser && $currentUser->sub != $id) {
                return Response::error('Admins cannot delete other admin accounts');
            }
        }
        
        $currentUser = $this->getCurrentUser();
        if ($currentUser && $currentUser->sub == $id) {
            return Response::error('Cannot delete your own account');
        }
        
        if ($user['role'] === 'super_admin') {
            $stmt = $db->prepare("SELECT COUNT(*) FROM admin_users WHERE role = 'super_admin'");
            $stmt->execute();
            $adminCount = $stmt->fetchColumn();
            if ($adminCount <= 1) {
                return Response::error('Cannot delete the last super_admin');
            }
        }
        
        $stmt = $db->prepare("DELETE FROM admin_users WHERE id = ?");
        $stmt->execute([$id]);
        
        $this->logAction('user.delete', $user['username'], 'success');
        
        return Response::success(null, 'User deleted successfully');
    }

    /**
     * Get sites assigned to a user
     */
    public function getSites(Request $request): Response
    {
        $roleCheck = $this->requireAdmin();
        if ($roleCheck) return $roleCheck;
        
        $id = (int)$request->getParam('id');
        $db = $this->container->getDatabase();
        
        $stmt = $db->prepare("SELECT role FROM admin_users WHERE id = ?");
        $stmt->execute([$id]);
        $targetUser = $stmt->fetch();
        
        if (!$targetUser) {
            return Response::notFound('User not found');
        }
        
        if (!$this->isSuperAdmin() && $targetUser['role'] === 'super_admin') {
            return Response::notFound('User not found');
        }
        
        $stmt = $db->prepare("SELECT domain, created_at FROM user_sites WHERE user_id = ? ORDER BY domain");
        $stmt->execute([$id]);
        $sites = $stmt->fetchAll();
        
        return Response::success(['sites' => $sites]);
    }

    /**
     * Update sites assigned to a user
     */
    public function updateSites(Request $request): Response
    {
        $roleCheck = $this->requireAdmin();
        if ($roleCheck) return $roleCheck;
        
        $id = (int)$request->getParam('id');
        $db = $this->container->getDatabase();
        
        $stmt = $db->prepare("SELECT username, role FROM admin_users WHERE id = ?");
        $stmt->execute([$id]);
        $user = $stmt->fetch();
        
        if (!$user) {
            return Response::notFound('User not found');
        }
        
        if (!$this->isSuperAdmin() && $user['role'] === 'super_admin') {
            return Response::notFound('User not found');
        }
        
        $sites = $request->input('sites', []);
        
        $stmt = $db->prepare("DELETE FROM user_sites WHERE user_id = ?");
        $stmt->execute([$id]);
        
        if (!empty($sites) && is_array($sites)) {
            $stmt = $db->prepare("INSERT INTO user_sites (user_id, domain) VALUES (?, ?)");
            foreach ($sites as $domain) {
                $stmt->execute([$id, $domain]);
            }
        }
        
        $this->logAction('user.sites.update', $user['username'], 'success', [
            'sites_count' => count($sites),
        ]);
        
        return Response::success([
            'sites_count' => count($sites),
        ], 'Sites updated successfully');
    }
}
