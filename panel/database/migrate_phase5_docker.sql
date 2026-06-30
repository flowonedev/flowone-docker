-- Phase 5: Docker Management
-- Run: mysql -u root -p < database/migrate_phase5_docker.sql

USE vpsadmin;

-- Docker containers tracked by panel
CREATE TABLE IF NOT EXISTS docker_containers (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    container_id VARCHAR(64) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    image VARCHAR(500) NOT NULL,
    domain VARCHAR(255),
    ports JSON,
    volumes JSON,
    environment JSON,
    labels JSON,
    status ENUM('running', 'stopped', 'paused', 'restarting', 'error', 'removing') DEFAULT 'stopped',
    restart_policy VARCHAR(50) DEFAULT 'unless-stopped',
    network_mode VARCHAR(100) DEFAULT 'bridge',
    cpu_limit DECIMAL(5,2),
    memory_limit VARCHAR(20),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT UNSIGNED,
    last_started_at TIMESTAMP NULL,
    INDEX idx_domain (domain),
    INDEX idx_status (status),
    INDEX idx_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Docker compose stacks
CREATE TABLE IF NOT EXISTS docker_stacks (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL UNIQUE,
    description TEXT,
    compose_file MEDIUMTEXT NOT NULL,
    stack_path VARCHAR(500),
    domain VARCHAR(255),
    status ENUM('running', 'stopped', 'partial', 'error') DEFAULT 'stopped',
    services JSON,
    env_file TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT UNSIGNED,
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Docker stack templates
CREATE TABLE IF NOT EXISTS docker_stack_templates (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    slug VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    category VARCHAR(50),
    icon VARCHAR(255),
    compose_template MEDIUMTEXT NOT NULL,
    env_template TEXT,
    variables JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Docker images cache
CREATE TABLE IF NOT EXISTS docker_images_cache (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    image_id VARCHAR(64) NOT NULL,
    repository VARCHAR(255),
    tag VARCHAR(100),
    size BIGINT,
    created TIMESTAMP,
    cached_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY idx_repo_tag (repository, tag)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default stack templates
INSERT INTO docker_stack_templates (slug, name, description, category, compose_template, variables) VALUES
('wordpress-stack', 'WordPress + MariaDB', 'WordPress with MariaDB database', 'cms',
'version: ''3.8''
services:
  wordpress:
    image: wordpress:latest
    restart: unless-stopped
    ports:
      - "${WP_PORT:-8080}:80"
    environment:
      WORDPRESS_DB_HOST: db
      WORDPRESS_DB_USER: ${DB_USER:-wordpress}
      WORDPRESS_DB_PASSWORD: ${DB_PASSWORD}
      WORDPRESS_DB_NAME: ${DB_NAME:-wordpress}
    volumes:
      - wordpress_data:/var/www/html
    depends_on:
      - db

  db:
    image: mariadb:10.6
    restart: unless-stopped
    environment:
      MYSQL_ROOT_PASSWORD: ${DB_ROOT_PASSWORD}
      MYSQL_DATABASE: ${DB_NAME:-wordpress}
      MYSQL_USER: ${DB_USER:-wordpress}
      MYSQL_PASSWORD: ${DB_PASSWORD}
    volumes:
      - db_data:/var/lib/mysql

volumes:
  wordpress_data:
  db_data:',
'{"WP_PORT": {"label": "WordPress Port", "default": "8080"}, "DB_USER": {"label": "DB User", "default": "wordpress"}, "DB_PASSWORD": {"label": "DB Password", "required": true}, "DB_NAME": {"label": "DB Name", "default": "wordpress"}, "DB_ROOT_PASSWORD": {"label": "DB Root Password", "required": true}}'),

('nginx-php', 'Nginx + PHP-FPM', 'Nginx with PHP-FPM for custom PHP apps', 'webserver',
'version: ''3.8''
services:
  nginx:
    image: nginx:alpine
    restart: unless-stopped
    ports:
      - "${HTTP_PORT:-8080}:80"
    volumes:
      - ./html:/var/www/html
      - ./nginx.conf:/etc/nginx/conf.d/default.conf
    depends_on:
      - php

  php:
    image: php:8.2-fpm-alpine
    restart: unless-stopped
    volumes:
      - ./html:/var/www/html

volumes:
  html:',
'{"HTTP_PORT": {"label": "HTTP Port", "default": "8080"}}'),

('redis', 'Redis', 'Redis in-memory data store', 'database',
'version: ''3.8''
services:
  redis:
    image: redis:alpine
    restart: unless-stopped
    ports:
      - "${REDIS_PORT:-6379}:6379"
    volumes:
      - redis_data:/data
    command: redis-server --appendonly yes

volumes:
  redis_data:',
'{"REDIS_PORT": {"label": "Redis Port", "default": "6379"}}'),

('portainer', 'Portainer', 'Docker management UI', 'management',
'version: ''3.8''
services:
  portainer:
    image: portainer/portainer-ce:latest
    restart: unless-stopped
    ports:
      - "${PORTAINER_PORT:-9443}:9443"
    volumes:
      - /var/run/docker.sock:/var/run/docker.sock
      - portainer_data:/data

volumes:
  portainer_data:',
'{"PORTAINER_PORT": {"label": "Portainer Port", "default": "9443"}}')

ON DUPLICATE KEY UPDATE name = VALUES(name);

SELECT 'Phase 5 migration complete: docker tables created' AS status;

