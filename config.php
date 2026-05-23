<?php
// config.php  -  Configuración del LLM (seguro para subir a Git)
// Usamos Groq (API compatible con OpenAI). La key se lee desde:
//   1) variable de entorno GROQ_API_KEY    (producción / despliegue)
//   2) config.local.php                     (desarrollo en XAMPP, va en .gitignore)

$key = getenv('GROQ_API_KEY');
if (!$key && file_exists(__DIR__ . '/config.local.php')) {
    $key = require __DIR__ . '/config.local.php';
}

define('OPENAI_API_KEY', $key ?: '');                  // nombre histórico — guarda la key de Groq
define('OPENAI_MODEL', 'llama-3.3-70b-versatile');     // modelo de Groq con soporte de tool calling
