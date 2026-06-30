<?php

declare(strict_types=1);

namespace Webmail\Addons\ProjectHub\Services;

/**
 * Normalises @email in comment HTML and structured mention payloads to JSON-ready rows.
 */
final class CardCommentMentionParser
{
    /**
     * @param array<mixed>|null $structured From client: [{email, name}, ...]
     * @return list<array{email: string, name: string}>
     */
    public static function mergeMentions(string $htmlContent, ?array $structured): array
    {
        $text = html_entity_decode(strip_tags($htmlContent), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $fromAt = self::parseEmailsFromText($text);
        $fromStruct = self::normalizeStructured($structured);
        $byEmail = [];
        foreach (array_merge($fromStruct, $fromAt) as $row) {
            $e = strtolower(trim((string) ($row['email'] ?? '')));
            if ($e === '') {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                $name = explode('@', $e)[0];
            }
            if (!isset($byEmail[$e])) {
                $byEmail[$e] = ['email' => $e, 'name' => $name];
            }
        }

        return array_values($byEmail);
    }

    /**
     * @return list<array{email: string, name: string}>
     */
    private static function parseEmailsFromText(string $text): array
    {
        if ($text === '') {
            return [];
        }
        if (!preg_match_all('/@([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/', $text, $m)) {
            return [];
        }
        $out = [];
        foreach ($m[1] as $email) {
            $e = strtolower(trim($email));
            if ($e !== '') {
                $out[] = ['email' => $e, 'name' => explode('@', $e)[0]];
            }
        }

        return $out;
    }

    /**
     * @param mixed $structured
     * @return list<array{email: string, name: string}>
     */
    private static function normalizeStructured($structured): array
    {
        if (!is_array($structured)) {
            return [];
        }
        $out = [];
        foreach ($structured as $row) {
            if (!is_array($row)) {
                continue;
            }
            $email = strtolower(trim((string) ($row['email'] ?? '')));
            if ($email === '') {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            $out[] = ['email' => $email, 'name' => $name];
        }

        return $out;
    }
}
