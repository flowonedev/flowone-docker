<?php

namespace Webmail\Addons\AutomationHub\Services;

class TelegramBotService
{
    private const API_BASE = 'https://api.telegram.org/bot';

    public function setWebhook(string $botToken, string $webhookUrl): bool
    {
        $response = $this->apiCall($botToken, 'setWebhook', ['url' => $webhookUrl]);
        return $response['ok'] ?? false;
    }

    public function deleteWebhook(string $botToken): bool
    {
        $response = $this->apiCall($botToken, 'deleteWebhook');
        return $response['ok'] ?? false;
    }

    public function getMe(string $botToken): array
    {
        $response = $this->apiCall($botToken, 'getMe');
        return $response['result'] ?? [];
    }

    public function sendMessage(string $botToken, string $chatId, string $text, string $parseMode = 'Markdown', ?array $replyMarkup = null): array
    {
        $params = [
            'chat_id' => $chatId,
            'text' => $text,
        ];

        if (!empty($parseMode)) {
            $params['parse_mode'] = $parseMode;
        }

        if ($replyMarkup) {
            $params['reply_markup'] = json_encode($replyMarkup);
        }

        $response = $this->apiCall($botToken, 'sendMessage', $params);

        if (!($response['ok'] ?? false)) {
            throw new \RuntimeException('Telegram API error: ' . ($response['description'] ?? 'Unknown error'));
        }

        return $response['result'] ?? [];
    }

    public function sendPhoto(string $botToken, string $chatId, string $photoUrl, ?string $caption = null): array
    {
        $params = [
            'chat_id' => $chatId,
            'photo' => $photoUrl,
        ];
        if ($caption) $params['caption'] = $caption;

        $response = $this->apiCall($botToken, 'sendPhoto', $params);
        return $response['result'] ?? [];
    }

    public function editMessageText(string $botToken, string $chatId, int $messageId, string $text, string $parseMode = 'Markdown'): array
    {
        $params = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
        ];
        if (!empty($parseMode)) $params['parse_mode'] = $parseMode;

        $response = $this->apiCall($botToken, 'editMessageText', $params);
        return $response['result'] ?? [];
    }

    public function parseUpdate(array $update): array
    {
        $result = [
            'type' => 'unknown',
            'chat_id' => null,
            'text' => null,
            'from' => null,
            'message_id' => null,
        ];

        if (isset($update['message'])) {
            $msg = $update['message'];
            $result['type'] = 'message';
            $result['chat_id'] = (string)($msg['chat']['id'] ?? '');
            $result['text'] = $msg['text'] ?? '';
            $result['from'] = $msg['from'] ?? null;
            $result['message_id'] = $msg['message_id'] ?? null;

            if (str_starts_with($result['text'], '/')) {
                $result['type'] = 'command';
                $parts = explode(' ', $result['text'], 2);
                $result['command'] = $parts[0];
                $result['command_args'] = $parts[1] ?? '';
            }
        } elseif (isset($update['callback_query'])) {
            $cb = $update['callback_query'];
            $result['type'] = 'callback_query';
            $result['chat_id'] = (string)($cb['message']['chat']['id'] ?? '');
            $result['text'] = $cb['data'] ?? '';
            $result['from'] = $cb['from'] ?? null;
            $result['callback_query_id'] = $cb['id'] ?? null;
        }

        return $result;
    }

    private function apiCall(string $botToken, string $method, array $params = []): array
    {
        $url = self::API_BASE . $botToken . '/' . $method;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($params),
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \RuntimeException("Telegram API request failed: {$error}");
        }

        return json_decode($response, true) ?? [];
    }
}
