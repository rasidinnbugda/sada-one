<?php
/**
 * SADA One — AI core. Two providers behind one ai_ask() interface:
 *   claude  → POST https://api.anthropic.com/v1/messages
 *   gemini  → POST https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent
 * Raw HTTP on purpose: the panel ships dependency-free for shared hosting.
 * Configure in Settings → Yapay Zeka: provider + key + model.
 */

function ai_provider(): string {
    return setting('ai_provider') === 'gemini' ? 'gemini' : 'claude';
}

function ai_enabled(): bool {
    return ai_provider() === 'gemini' ? setting('gemini_api_key') !== '' : setting('anthropic_api_key') !== '';
}

function ai_model(): string {
    if (ai_provider() === 'gemini') {
        $m = setting('gemini_model') ?: 'gemini-3.6-flash';
        // Google retired the 2.5 series for new API users; stored old values self-heal
        if (str_starts_with($m, 'gemini-2.5')) $m = 'gemini-3.6-flash';
        return $m;
    }
    return setting('ai_model') ?: 'claude-opus-5';
}

/** Small JSON POST helper shared by both providers. */
function ai_http(string $url, array $headers, array $body, ?string &$transportError = null): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 180,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers),
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);
    if ($raw === false) { $transportError = 'API bağlantısı kurulamadı.'; return null; }
    $j = json_decode($raw, true);
    if (!is_array($j)) { $transportError = 'API geçersiz yanıt döndürdü.'; return null; }
    return $j;
}

/**
 * Ask the configured model. Returns ['ok' => bool, 'text' => string, 'error' => ?string].
 * $system: role/instructions; $prompt: the task with its data.
 */
function ai_ask(string $system, string $prompt, int $maxTokens = 3000): array {
    if (!ai_enabled()) return ['ok' => false, 'text' => '', 'error' => 'AI anahtarı tanımlı değil (Ayarlar → Yapay Zeka).'];
    return ai_provider() === 'gemini' ? ai_ask_gemini($system, $prompt, $maxTokens) : ai_ask_claude($system, $prompt, $maxTokens);
}

function ai_ask_claude(string $system, string $prompt, int $maxTokens): array {
    $j = ai_http('https://api.anthropic.com/v1/messages', [
        'x-api-key: ' . setting('anthropic_api_key'),
        'anthropic-version: 2023-06-01',
    ], [
        'model' => ai_model(),
        'max_tokens' => $maxTokens,
        'system' => $system,
        'messages' => [['role' => 'user', 'content' => $prompt]],
    ], $terr);
    if (!$j) return ['ok' => false, 'text' => '', 'error' => 'Claude ' . $terr];
    if (isset($j['error'])) return ['ok' => false, 'text' => '', 'error' => mb_substr((string)($j['error']['message'] ?? 'bilinmeyen hata'), 0, 240)];
    // A refusal comes back as stop_reason "refusal" — surface it clearly
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

function ai_ask_gemini(string $system, string $prompt, int $maxTokens): array {
    $model = rawurlencode(ai_model());
    $j = ai_http("https://generativelanguage.googleapis.com/v1beta/models/$model:generateContent", [
        'x-goog-api-key: ' . setting('gemini_api_key'),
    ], [
        'system_instruction' => ['parts' => [['text' => $system]]],
        'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
        'generationConfig' => ['maxOutputTokens' => $maxTokens],
    ], $terr);
    if (!$j) return ['ok' => false, 'text' => '', 'error' => 'Gemini ' . $terr];
    if (isset($j['error'])) return ['ok' => false, 'text' => '', 'error' => mb_substr((string)($j['error']['message'] ?? 'bilinmeyen hata'), 0, 240)];
    $aday = $j['candidates'][0] ?? null;
    if (($aday['finishReason'] ?? '') === 'SAFETY' || ($j['promptFeedback']['blockReason'] ?? '') !== '') {
        return ['ok' => false, 'text' => '', 'error' => 'Model bu isteği güvenlik nedeniyle yanıtlamadı.'];
    }
    $text = '';
    foreach (($aday['content']['parts'] ?? []) as $part) $text .= $part['text'] ?? '';
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
