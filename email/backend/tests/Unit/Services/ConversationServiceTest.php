<?php

namespace Webmail\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use Webmail\Services\ConversationService;

class ConversationServiceTest extends TestCase
{
    private array $config;
    private ?ConversationService $service = null;

    protected function setUp(): void
    {
        $this->config = require __DIR__ . '/../../../src/config.php';

        if (!$this->canConnectToDb()) {
            $this->markTestSkipped('Database not available');
        }

        $this->service = new ConversationService($this->config);
    }

    private function canConnectToDb(): bool
    {
        try {
            new \PDO(
                "mysql:host=" . getenv('DB_HOST') . ";dbname=" . getenv('DB_NAME'),
                getenv('DB_USER'),
                getenv('DB_PASS')
            );
            return true;
        } catch (\PDOException) {
            return false;
        }
    }

    #[Test]
    public function normalizeSubject_strips_re_fw_prefixes(): void
    {
        $service = $this->service;

        $this->assertEquals('Hello World', $service->normalizeSubject('Re: Hello World'));
        $this->assertEquals('Hello World', $service->normalizeSubject('RE: Hello World'));
        $this->assertEquals('Hello World', $service->normalizeSubject('Fw: Hello World'));
        $this->assertEquals('Hello World', $service->normalizeSubject('FW: Hello World'));
        $this->assertEquals('Hello World', $service->normalizeSubject('Fwd: Hello World'));
        $this->assertEquals('Hello World', $service->normalizeSubject('Re: Re: Hello World'));
        $this->assertEquals('Hello World', $service->normalizeSubject('Re: Fw: Hello World'));
    }

    #[Test]
    public function normalizeSubject_handles_empty_and_whitespace(): void
    {
        $service = $this->service;

        $this->assertEquals('', $service->normalizeSubject(''));
        $this->assertEquals('', $service->normalizeSubject('   '));
        $this->assertEquals('', $service->normalizeSubject('Re:'));
        $this->assertEquals('', $service->normalizeSubject('Re: '));
    }

    #[Test]
    public function normalizeSubject_preserves_normal_subjects(): void
    {
        $service = $this->service;

        $this->assertEquals('Meeting Tomorrow', $service->normalizeSubject('Meeting Tomorrow'));
        $this->assertEquals('Report Q4 2025', $service->normalizeSubject('Report Q4 2025'));
    }

    #[Test]
    public function computeConversationId_returns_consistent_hash(): void
    {
        $service = $this->service;

        $message = [
            'message_id' => '<abc123@example.com>',
            'references' => '<ref1@example.com> <ref2@example.com>',
            'in_reply_to' => '<ref2@example.com>',
            'subject' => 'Test Subject',
        ];

        $id1 = $service->computeConversationId($message, 'INBOX');
        $id2 = $service->computeConversationId($message, 'INBOX');

        $this->assertNotEmpty($id1);
        $this->assertEquals($id1, $id2, 'Same message should produce same conversation ID');
    }

    #[Test]
    public function computeConversationId_groups_replies_together(): void
    {
        $service = $this->service;

        $original = [
            'message_id' => '<original@example.com>',
            'references' => '',
            'in_reply_to' => '',
            'subject' => 'Project Update',
        ];

        $reply = [
            'message_id' => '<reply@example.com>',
            'references' => '<original@example.com>',
            'in_reply_to' => '<original@example.com>',
            'subject' => 'Re: Project Update',
        ];

        $idOriginal = $service->computeConversationId($original, 'INBOX');
        $idReply = $service->computeConversationId($reply, 'INBOX');

        $this->assertEquals($idOriginal, $idReply, 'Reply should be in the same conversation as original');
    }

    #[Test]
    public function computeConversationId_separates_unrelated_messages(): void
    {
        $service = $this->service;

        $msg1 = [
            'message_id' => '<msg1@example.com>',
            'references' => '',
            'in_reply_to' => '',
            'subject' => 'Topic A',
        ];

        $msg2 = [
            'message_id' => '<msg2@example.com>',
            'references' => '',
            'in_reply_to' => '',
            'subject' => 'Topic B',
        ];

        $id1 = $service->computeConversationId($msg1, 'INBOX');
        $id2 = $service->computeConversationId($msg2, 'INBOX');

        $this->assertNotEquals($id1, $id2, 'Unrelated messages should have different conversation IDs');
    }

    #[Test]
    public function assignMessageToConversation_returns_conversation_id(): void
    {
        $service = $this->service;
        $testEmail = 'phpunit-test@flowone.pro';

        $message = [
            'uid' => 99999,
            'message_id' => '<phpunit-' . uniqid() . '@test.com>',
            'references' => '',
            'in_reply_to' => '',
            'subject' => 'PHPUnit Test ' . time(),
            'from' => 'sender@test.com',
            'date' => date('Y-m-d H:i:s'),
            'seen' => false,
        ];

        $conversationId = $service->assignMessageToConversation($testEmail, 'INBOX', $message);

        $this->assertNotEmpty($conversationId);
        $this->assertIsString($conversationId);

        $service->clearUserConversations($testEmail);
    }

    #[Test]
    public function assignMessagesToConversations_returns_mapping(): void
    {
        $service = $this->service;
        $testEmail = 'phpunit-batch@flowone.pro';
        $uniqueId = uniqid();

        $messages = [
            [
                'uid' => 10001,
                'message_id' => "<batch1-{$uniqueId}@test.com>",
                'references' => '',
                'in_reply_to' => '',
                'subject' => 'Batch Test A',
                'from' => 'a@test.com',
                'date' => date('Y-m-d H:i:s'),
                'seen' => false,
            ],
            [
                'uid' => 10002,
                'message_id' => "<batch2-{$uniqueId}@test.com>",
                'references' => '',
                'in_reply_to' => '',
                'subject' => 'Batch Test B',
                'from' => 'b@test.com',
                'date' => date('Y-m-d H:i:s'),
                'seen' => true,
            ],
        ];

        $mapping = $service->assignMessagesToConversations($testEmail, 'INBOX', $messages);

        $this->assertIsArray($mapping);
        $this->assertCount(2, $mapping);

        $service->clearUserConversations($testEmail);
    }

    #[Test]
    public function getConversationsForFolder_returns_array(): void
    {
        $service = $this->service;
        $testEmail = 'phpunit-list@flowone.pro';

        $conversations = $service->getConversationsForFolder($testEmail, 'INBOX');

        $this->assertIsArray($conversations);
    }

    #[Test]
    public function folder_index_lifecycle(): void
    {
        $service = $this->service;
        $testEmail = 'phpunit-index@flowone.pro';
        $folder = 'INBOX';

        $service->invalidateFolderIndex($testEmail, $folder);
        $this->assertFalse($service->isFolderIndexed($testEmail, $folder));

        $service->markFolderIndexed($testEmail, $folder, 500, 50, 12345);
        $this->assertTrue($service->isFolderIndexed($testEmail, $folder));

        $status = $service->getFolderIndexStatus($testEmail, $folder);
        $this->assertIsArray($status);
        $this->assertEquals(500, $service->getLastIndexedUid($testEmail, $folder));

        $service->updateLastIndexedUid($testEmail, $folder, 600, 10);
        $this->assertEquals(600, $service->getLastIndexedUid($testEmail, $folder));

        $service->invalidateFolderIndex($testEmail, $folder);
        $this->assertFalse($service->isFolderIndexed($testEmail, $folder));
    }

    #[Test]
    public function clearUserConversations_removes_all_data(): void
    {
        $service = $this->service;
        $testEmail = 'phpunit-clear@flowone.pro';

        $message = [
            'uid' => 77777,
            'message_id' => '<clear-test-' . uniqid() . '@test.com>',
            'references' => '',
            'in_reply_to' => '',
            'subject' => 'Clear Test',
            'from' => 'clear@test.com',
            'date' => date('Y-m-d H:i:s'),
            'seen' => false,
        ];

        $service->assignMessageToConversation($testEmail, 'INBOX', $message);

        $before = $service->getConversationsForFolder($testEmail, 'INBOX');
        $this->assertNotEmpty($before);

        $service->clearUserConversations($testEmail);

        $after = $service->getConversationsForFolder($testEmail, 'INBOX');
        $this->assertEmpty($after);
    }
}
