<?php
// llm.php  -  Llamada a la API de Groq (formato compatible con OpenAI Chat Completions)
require_once __DIR__ . '/config.php';

function openaiChat(array $messages, array $tools = [], $toolChoice = 'auto'): array {
    if (!OPENAI_API_KEY) {
        throw new RuntimeException('Falta la API key. Edita config.local.php.');
    }

    $payload = ['model' => OPENAI_MODEL, 'messages' => $messages];
    if ($tools) {
        $payload['tools'] = $tools;
        $payload['tool_choice'] = $toolChoice;
    }

    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . OPENAI_API_KEY,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT    => 40,
    ]);

    $res  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err) {
        throw new RuntimeException("Error de conexión con Groq: $err");
    }
    $data = json_decode($res, true);
    if ($code !== 200) {
        $msg = $data['error']['message'] ?? $res;
        throw new RuntimeException("Groq respondió $code: $msg");
    }
    return $data;
}
