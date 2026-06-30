-- Migration: Add 'admin' role to admin_users
-- Run this on existing databases to add the admin role option

ALTER TABLE admin_users 
    MODIFY COLUMN role ENUM('super_admin', 'admin', 'user') DEFAULT 'user';
