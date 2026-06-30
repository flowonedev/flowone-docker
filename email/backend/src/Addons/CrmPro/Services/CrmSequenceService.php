<?php

namespace Webmail\Addons\CrmPro\Services;

use PDO;

/**
 * CrmSequenceService
 * 
 * Multi-step email sequences (drip campaigns): CRUD sequences,
 * enrollment management, step advancement, email dispatch.
 */
class CrmSequenceService
{
    private PDO $db;
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->db = \Webmail\Core\Database::getConnection($config);
        \Webmail\Core\SchemaGuard::run(fn() => $this->ensureTables());
    }

    public function getDb(): PDO
    {
        return $this->db;
    }

    // =========================================================================
    // Table Bootstrap
    // =========================================================================

    private function ensureTables(): void
    {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS crm_sequences (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_email VARCHAR(255) NOT NULL,
                name VARCHAR(255) NOT NULL,
                description TEXT DEFAULT NULL,
                trigger_stage VARCHAR(50) DEFAULT NULL,
                is_active TINYINT(1) DEFAULT 1,
                steps JSON NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_user (user_email),
                INDEX idx_trigger_stage (trigger_stage)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS crm_sequence_enrollments (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                sequence_id INT UNSIGNED NOT NULL,
                deal_id INT UNSIGNED DEFAULT NULL,
                client_id INT UNSIGNED NOT NULL,
                user_email VARCHAR(255) NOT NULL,
                current_step INT DEFAULT 0,
                status ENUM('active','completed','cancelled','paused') DEFAULT 'active',
                next_run_at DATETIME DEFAULT NULL,
                started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                completed_at DATETIME DEFAULT NULL,
                INDEX idx_sequence (sequence_id),
                INDEX idx_status_next (status, next_run_at),
                INDEX idx_deal (deal_id),
                INDEX idx_user (user_email),
                FOREIGN KEY (sequence_id) REFERENCES crm_sequences(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    // =========================================================================
    // Sequence CRUD
    // =========================================================================

    public function listSequences(string $userEmail): array
    {
        $stmt = $this->db->prepare("
            SELECT s.*,
                (SELECT COUNT(*) FROM crm_sequence_enrollments e WHERE e.sequence_id = s.id AND e.status = 'active') as active_enrollments,
                (SELECT COUNT(*) FROM crm_sequence_enrollments e WHERE e.sequence_id = s.id) as total_enrollments
            FROM crm_sequences s
            WHERE s.user_email = ?
            ORDER BY s.created_at DESC
        ");
        $stmt->execute([$userEmail]);
        $sequences = $stmt->fetchAll();

        foreach ($sequences as &$seq) {
            $seq['steps'] = json_decode($seq['steps'], true) ?: [];
        }

        return $sequences;
    }

    public function getSequence(int $id, string $userEmail): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM crm_sequences WHERE id = ? AND user_email = ?");
        $stmt->execute([$id, $userEmail]);
        $seq = $stmt->fetch();

        if ($seq) {
            $seq['steps'] = json_decode($seq['steps'], true) ?: [];
        }

        return $seq ?: null;
    }

    public function createSequence(string $userEmail, array $data): array
    {
        $stmt = $this->db->prepare("
            INSERT INTO crm_sequences (user_email, name, description, trigger_stage, is_active, steps)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $userEmail,
            $data['name'],
            $data['description'] ?? null,
            $data['trigger_stage'] ?? null,
            $data['is_active'] ?? 1,
            json_encode($data['steps'] ?? []),
        ]);

        return $this->getSequence((int)$this->db->lastInsertId(), $userEmail);
    }

    public function updateSequence(int $id, string $userEmail, array $data): ?array
    {
        $seq = $this->getSequence($id, $userEmail);
        if (!$seq) return null;

        $fields = [];
        $params = [];

        foreach (['name', 'description', 'trigger_stage', 'is_active'] as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "$field = ?";
                $params[] = $data[$field];
            }
        }
        if (isset($data['steps'])) {
            $fields[] = "steps = ?";
            $params[] = json_encode($data['steps']);
        }

        if (empty($fields)) return $seq;

        $params[] = $id;
        $params[] = $userEmail;
        $stmt = $this->db->prepare("UPDATE crm_sequences SET " . implode(', ', $fields) . " WHERE id = ? AND user_email = ?");
        $stmt->execute($params);

        return $this->getSequence($id, $userEmail);
    }

    public function deleteSequence(int $id, string $userEmail): bool
    {
        $stmt = $this->db->prepare("DELETE FROM crm_sequences WHERE id = ? AND user_email = ?");
        $stmt->execute([$id, $userEmail]);
        return $stmt->rowCount() > 0;
    }

    // =========================================================================
    // Enrollment
    // =========================================================================

    public function enrollInSequence(int $sequenceId, int $clientId, string $userEmail, ?int $dealId = null): ?array
    {
        $sequence = $this->getSequence($sequenceId, $userEmail);
        if (!$sequence || !$sequence['is_active']) return null;

        // Check if already enrolled and active
        $stmt = $this->db->prepare("
            SELECT id FROM crm_sequence_enrollments
            WHERE sequence_id = ? AND client_id = ? AND user_email = ? AND status = 'active'
        ");
        $stmt->execute([$sequenceId, $clientId, $userEmail]);
        if ($stmt->fetch()) return null; // Already enrolled

        // Calculate first step run time
        $steps = $sequence['steps'];
        $firstStepDelay = ($steps[0]['delay_days'] ?? 0);
        $nextRunAt = date('Y-m-d H:i:s', strtotime("+{$firstStepDelay} days"));

        $stmt = $this->db->prepare("
            INSERT INTO crm_sequence_enrollments (sequence_id, deal_id, client_id, user_email, current_step, status, next_run_at)
            VALUES (?, ?, ?, ?, 0, 'active', ?)
        ");
        $stmt->execute([$sequenceId, $dealId, $clientId, $userEmail, $nextRunAt]);

        return $this->getEnrollment((int)$this->db->lastInsertId());
    }

    public function getEnrollment(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM crm_sequence_enrollments WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function getEnrollments(int $sequenceId, string $userEmail): array
    {
        $stmt = $this->db->prepare("
            SELECT e.*, c.name as client_name, c.domain as client_domain,
                   d.title as deal_title
            FROM crm_sequence_enrollments e
            LEFT JOIN clients c ON c.id = e.client_id
            LEFT JOIN crm_deals d ON d.id = e.deal_id
            WHERE e.sequence_id = ? AND e.user_email = ?
            ORDER BY e.started_at DESC
        ");
        $stmt->execute([$sequenceId, $userEmail]);
        return $stmt->fetchAll();
    }

    public function cancelEnrollment(int $enrollmentId, string $userEmail): bool
    {
        $stmt = $this->db->prepare("
            UPDATE crm_sequence_enrollments SET status = 'cancelled', completed_at = NOW()
            WHERE id = ? AND user_email = ? AND status = 'active'
        ");
        $stmt->execute([$enrollmentId, $userEmail]);
        return $stmt->rowCount() > 0;
    }

    public function pauseEnrollment(int $enrollmentId, string $userEmail): bool
    {
        $stmt = $this->db->prepare("
            UPDATE crm_sequence_enrollments SET status = 'paused'
            WHERE id = ? AND user_email = ? AND status = 'active'
        ");
        $stmt->execute([$enrollmentId, $userEmail]);
        return $stmt->rowCount() > 0;
    }

    public function resumeEnrollment(int $enrollmentId, string $userEmail): bool
    {
        $enrollment = $this->getEnrollment($enrollmentId);
        if (!$enrollment || $enrollment['user_email'] !== $userEmail || $enrollment['status'] !== 'paused') return false;

        $stmt = $this->db->prepare("
            UPDATE crm_sequence_enrollments SET status = 'active', next_run_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$enrollmentId]);
        return true;
    }

    // =========================================================================
    // Step Processing (called by cron worker)
    // =========================================================================

    /**
     * Process all due sequence steps across all users.
     * Returns count of emails sent.
     */
    public function processDueSteps(): int
    {
        $stmt = $this->db->prepare("
            SELECT e.*, s.steps as sequence_steps, s.name as sequence_name
            FROM crm_sequence_enrollments e
            INNER JOIN crm_sequences s ON s.id = e.sequence_id
            WHERE e.status = 'active'
              AND e.next_run_at IS NOT NULL
              AND e.next_run_at <= NOW()
            LIMIT 100
        ");
        $stmt->execute();
        $dueEnrollments = $stmt->fetchAll();

        $processed = 0;

        foreach ($dueEnrollments as $enrollment) {
            try {
                $this->processEnrollmentStep($enrollment);
                $processed++;
            } catch (\Throwable $e) {
                error_log("CrmSequence step processing error [enrollment:{$enrollment['id']}]: " . $e->getMessage());
            }
        }

        return $processed;
    }

    /**
     * Process a single step of an enrollment
     */
    private function processEnrollmentStep(array $enrollment): void
    {
        $steps = json_decode($enrollment['sequence_steps'], true) ?: [];
        $currentStep = (int)$enrollment['current_step'];

        if ($currentStep >= count($steps)) {
            // Sequence complete
            $this->markCompleted($enrollment['id']);
            return;
        }

        $step = $steps[$currentStep];

        // Send the step email
        $this->sendStepEmail($enrollment, $step);

        // Advance to next step
        $nextStep = $currentStep + 1;

        if ($nextStep >= count($steps)) {
            // Was the last step
            $this->markCompleted($enrollment['id']);
        } else {
            // Schedule next step
            $nextDelay = $steps[$nextStep]['delay_days'] ?? 1;
            $nextRunAt = date('Y-m-d H:i:s', strtotime("+{$nextDelay} days"));

            $stmt = $this->db->prepare("
                UPDATE crm_sequence_enrollments 
                SET current_step = ?, next_run_at = ?
                WHERE id = ?
            ");
            $stmt->execute([$nextStep, $nextRunAt, $enrollment['id']]);
        }
    }

    private function sendStepEmail(array $enrollment, array $step): void
    {
        $userEmail = $enrollment['user_email'];
        $clientId = $enrollment['client_id'];

        // Get recipient email from client contacts
        $stmt = $this->db->prepare("SELECT email FROM crm_contacts WHERE client_id = ? LIMIT 1");
        $stmt->execute([$clientId]);
        $contact = $stmt->fetch();

        if (!$contact || empty($contact['email'])) {
            error_log("CrmSequence: No contact email for client #{$clientId}, skipping step");
            return;
        }

        $recipientEmail = $contact['email'];
        $subject = $step['subject'] ?? 'Follow-up';
        $body = $step['body'] ?? '';

        // If template_id specified, load template
        if (!empty($step['template_id'])) {
            try {
                $templateService = new \Webmail\Services\EmailTemplateService($this->config);
                $template = $templateService->get($step['template_id'], $userEmail);
                if ($template) {
                    $subject = $template['subject'] ?? $subject;
                    $body = $template['body'] ?? $body;
                }
            } catch (\Throwable $e) {
                // Use fallback subject/body
            }
        }

        // Queue email
        try {
            $emailQueue = new \Webmail\Addons\EmailMarketing\Services\EmailQueueService($this->config);
            $emailQueue->queueEmail([
                'user_email' => $userEmail,
                'to' => $recipientEmail,
                'subject' => $subject,
                'body' => $body,
                'source' => 'crm_sequence',
                'source_id' => $enrollment['sequence_id'],
            ]);
        } catch (\Throwable $e) {
            error_log("CrmSequence email queue error: " . $e->getMessage());
        }
    }

    private function markCompleted(int $enrollmentId): void
    {
        $stmt = $this->db->prepare("
            UPDATE crm_sequence_enrollments 
            SET status = 'completed', completed_at = NOW(), next_run_at = NULL
            WHERE id = ?
        ");
        $stmt->execute([$enrollmentId]);
    }

    // =========================================================================
    // Stage Trigger Check
    // =========================================================================

    /**
     * Check if any sequences should auto-start when a deal enters a stage.
     * Called from integration hook in CrmDealService::updateStage().
     */
    public function checkStageTriggers(string $newStage, int $dealId, int $clientId, string $userEmail): void
    {
        $stmt = $this->db->prepare("
            SELECT * FROM crm_sequences
            WHERE user_email = ? AND is_active = 1 AND trigger_stage = ?
        ");
        $stmt->execute([$userEmail, $newStage]);
        $sequences = $stmt->fetchAll();

        foreach ($sequences as $seq) {
            $this->enrollInSequence($seq['id'], $clientId, $userEmail, $dealId);
        }
    }
}

