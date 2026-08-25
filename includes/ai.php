<?php
/**
 * SADA One — AI core (Claude API).
 * Raw HTTP on purpose: the panel ships dependency-free for shared hosting
 * (no composer), so we call POST https://api.anthropic.com/v1/messages directly.
 * Configure in Settings → Yapay Zeka: API key + model.
 */

function ai_enabled(): bool {
    return setting('anthropic_api_key') !== '';
}

function ai_model(): string {
    return setting('ai_model') ?: 'claude-opus-5';
}

/**
 * Ask Claude. Returns ['ok' => bool, 'text' => string, 'error' => ?string].
 * $system: role/instructions; $prompt: the task with its data.
 */
function ai_ask(string $system, string $prompt, int $maxTokens = 3000): array {
    if (!ai_enabled()) return ['ok' => false, 'text' => '', 'error' => 'AI anahtarı tanımlı değil (Ayarlar → Yapay Zeka).'];
    $body = json_encode([
        'model' => ai_model(),
        'max_tokens' => $maxTokens,
        'system' => $system,
        'messages' => [['role' => 'user', 'content' => $prompt]],
    ], JSON_UNESCAPED_UNICODE);
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 180,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-key: ' . setting('anthropic_api_key'),
            'anthropic-version: 2023-06-01',
        ],
    ]);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false) return ['ok' => false, 'text' => '', 'error' => 'Claude API bağlantısı kurulamadı.'];
    $j = json_decode($raw, true);
    if ($code !== 200 || !is_array($j)) {
        $message = $j['error']['message'] ?? ('HTTP ' . $code);
        return ['ok' => false, 'text' => '', 'error' => mb_substr((string)$message, 0, 240)];
    }
    // A refusal comes back as HTTP 200 with stop_reason "refusal" — surface it clearly
    if (($j['stop_reason'] ?? '') === 'refusal') {
        return ['ok' => false, 'text' => '', 'error' => 'Model bu isteği güvenlik nedeniyle yanıtlamadı.'];
    }
    // content[] is polymorphic (thinking blocks may precede text) — collect only text blocks
    $text = '';
    foreach (($j['content'] ?? []) as $block) {
        if (($block['type'] ?? '') === 'text') $text .= $block['text'];
    }
    if ($text === '') return ['ok' => false, 'text' => '', 'error' => 'Model boş yanıt döndürdü.'];
    return ['ok' => true, 'text' => trim($text), 'error' => null];
}

/**
 * Ask for strict JSON and decode it. Strips ```json fences if the model adds them.
 * Returns the decoded array or null.
 */
function ai_ask_json(string $system, string $prompt, int $maxTokens = 3000): ?array {
    $r = ai_ask($system . ' Yanıtını YALNIZCA geçerli JSON olarak ver; kod bloğu, açıklama veya ek metin ekleme.', $prompt, $maxTokens);
    if (!$r['ok']) return null;
    $text = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($r['text']));
    $j = json_decode($text, true);
    return is_array($j) ? $j : null;
}
