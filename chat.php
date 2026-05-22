<?php
// chat.php  -  PASO 3: agente conversacional AGÉNTICO con OpenAI (function calling).
// Flujo: el paciente escribe un síntoma -> el modelo deduce la especialidad y,
// cuando conoce el plan, DECIDE llamar a la herramienta buscar_copago().
// La PLATA siempre sale del SQL; el modelo nunca inventa montos.
//
// Probar en el navegador (la sesión recuerda el contexto entre llamadas):
//   http://localhost/proyectoaiworks/chat.php?mensaje=me duele el pecho
//   http://localhost/proyectoaiworks/chat.php?mensaje=tengo el Plan Total de Salud S.A.
//   http://localhost/proyectoaiworks/chat.php?reset=1   (reinicia la conversación)

session_start();
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/db.php';
require __DIR__ . '/llm.php';

if (isset($_GET['reset'])) {
    session_destroy();
    echo json_encode(['ok' => true, 'msg' => 'Conversación reiniciada']);
    exit;
}

// Seleccionar plan desde la pantalla de bienvenida. Reinicia la conversación
// y deja el plan persistido en la sesión PHP para todas las llamadas siguientes.
if (isset($_GET['set_plan'])) {
    try {
        $stmt = getDB()->prepare("SELECT id, aseguradora, nombre FROM planes WHERE id = ?");
        $stmt->execute([(int) $_GET['set_plan']]);
        $plan = $stmt->fetch();
        if (!$plan) {
            throw new RuntimeException('Plan no encontrado.');
        }
        $_SESSION = [];   // empezar limpio con el nuevo plan
        $_SESSION['plan_id']       = (int) $plan['id'];
        $_SESSION['plan_etiqueta'] = $plan['aseguradora'] . ' - ' . $plan['nombre'];
        echo json_encode([
            'ok'   => true,
            'plan' => [
                'id'       => $_SESSION['plan_id'],
                'etiqueta' => $_SESSION['plan_etiqueta'],
            ],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

try {
    $db = getDB();

    // 1) Mensaje del paciente (POST JSON desde el front, o ?mensaje= para probar)
    $body = json_decode(file_get_contents('php://input'), true);
    $mensaje = $body['mensaje'] ?? ($_GET['mensaje'] ?? null);
    if (!$mensaje) {
        throw new RuntimeException('Falta el parámetro "mensaje".');
    }

    // 2) Catálogos desde la BD (no hardcode: si agregas filas, el agente se entera)
    $especialidades = $db->query("SELECT nombre FROM especialidades ORDER BY nombre")
                         ->fetchAll(PDO::FETCH_COLUMN);
    $planes = $db->query("SELECT id, aseguradora, nombre FROM planes ORDER BY id")
                 ->fetchAll();
    $planesEtiquetas = array_map(fn($p) => $p['aseguradora'] . ' - ' . $p['nombre'], $planes);

    // 3) La herramienta: esto es lo "agéntico". El modelo decide cuándo llamarla.
    $tools = [[
        'type' => 'function',
        'function' => [
            'name' => 'buscar_copago',
            'description' => 'Calcula el copago del paciente y devuelve los hospitales de la red ordenados del más económico al más caro, según la especialidad médica y el plan de seguro.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'especialidad' => [
                        'type' => 'string',
                        'enum' => $especialidades,
                        'description' => 'Especialidad médica adecuada para el síntoma.',
                    ],
                    'plan' => [
                        'type' => 'string',
                        'enum' => $planesEtiquetas,
                        'description' => 'Plan de seguro del paciente.',
                    ],
                ],
                'required' => ['especialidad', 'plan'],
            ],
        ],
    ]];

    // 4) Validar que el paciente eligió su plan en la pantalla de bienvenida
    if (empty($_SESSION['plan_etiqueta'])) {
        throw new RuntimeException('Debes seleccionar tu plan antes de iniciar la conversación.');
    }

    // 5) Historial en sesión (primera vez: instrucciones del sistema con el plan YA conocido)
    if (empty($_SESSION['messages'])) {
        $listaEsp   = implode(', ', $especialidades);
        $planActual = $_SESSION['plan_etiqueta'];
        $_SESSION['messages'] = [[
            'role' => 'system',
            'content' =>
                "Eres Clara, la asistente de salud de SaludClara. Hablas con calidez, en español, " .
                "y MUY breve: máximo 2 frases por respuesta. Nunca uses listas ni suenes a formulario.\n" .
                "Ayudas al paciente, ANTES de atenderse, a saber qué especialidad necesita, cuánto " .
                "pagará de copago y qué hospital de su red le conviene más.\n" .
                "EL PLAN YA ESTÁ IDENTIFICADO: \"$planActual\". NUNCA preguntes por el plan.\n" .
                "Especialidades disponibles: $listaEsp.\n" .
                "FLUJO OBLIGATORIO (síguelo al pie de la letra):\n" .
                "1) PRIMER mensaje del paciente con un síntoma: NO llames la herramienta NUNCA en este " .
                "turno, aunque el síntoma te parezca clarísimo. Responde con una frase corta de empatía " .
                "y UNA sola pregunta de seguimiento, la más útil (desde cuándo, qué tan intenso, o si " .
                "hubo un golpe). Siempre exactamente una pregunta, nunca cero, nunca dos.\n" .
                "2) SEGUNDO mensaje (el paciente ya respondió tu pregunta): NO hagas más preguntas. " .
                "Deduce la especialidad y llama INMEDIATAMENTE a la herramienta buscar_copago con esa " .
                "especialidad y plan=\"$planActual\". Aunque la info sea incompleta, igual llámala.\n" .
                "3) Al presentar el resultado: UNA frase corta indicando la especialidad sugerida. " .
                "NO repitas montos ni hospitales en el texto (se muestran en una tarjeta aparte).\n" .
                "4) Para los montos SIEMPRE usa la herramienta buscar_copago; nunca inventes precios.\n" .
                "5) Urgencias: si el síntoma es claramente grave (dolor de pecho intenso, dificultad " .
                "para respirar), dilo en una frase y recomienda emergencias, pero IGUAL llama la " .
                "herramienta para mostrar el copago.",
        ]];
    }
    $_SESSION['messages'][] = ['role' => 'user', 'content' => $mensaje];

    // 5) Control del flujo según el turno (cuántos mensajes ha enviado el paciente):
    //    Turno 1  -> SIN herramientas: el modelo se ve obligado a hacer UNA pregunta.
    //    Turno 2  -> herramienta FORZADA: muestra el copago de inmediato.
    //    Turno 3+ -> herramienta automática: conversación libre.
    $userTurns = 0;
    foreach ($_SESSION['messages'] as $m) {
        if (($m['role'] ?? '') === 'user') {
            $userTurns++;
        }
    }
    if ($userTurns <= 1) {
        $toolsArg  = [];
        $choiceArg = 'auto';
    } elseif ($userTurns === 2) {
        $toolsArg  = $tools;
        $choiceArg = ['type' => 'function', 'function' => ['name' => 'buscar_copago']];
    } else {
        $toolsArg  = $tools;
        $choiceArg = 'auto';
    }

    // 6) Primera llamada al modelo
    $resp   = openaiChat($_SESSION['messages'], $toolsArg, $choiceArg);
    $choice = $resp['choices'][0]['message'];
    $_SESSION['messages'][] = $choice;

    $datos = null;

    // 7) ¿El modelo usó la herramienta? (forzada en el turno 2)
    if (!empty($choice['tool_calls'])) {
        foreach ($choice['tool_calls'] as $tc) {
            $args      = json_decode($tc['function']['arguments'], true);
            $resultado = buscarCopago($db, $args['especialidad'] ?? '', $args['plan'] ?? '', $planes);
            $datos     = $resultado;

            $_SESSION['messages'][] = [
                'role'         => 'tool',
                'tool_call_id' => $tc['id'],
                'content'      => json_encode($resultado, JSON_UNESCAPED_UNICODE),
            ];
        }
        // 8) Segunda llamada: el modelo redacta la respuesta final con los datos reales
        $resp2  = openaiChat($_SESSION['messages'], $tools);
        $choice = $resp2['choices'][0]['message'];
        $_SESSION['messages'][] = $choice;
    }

    echo json_encode([
        'ok'        => true,
        'respuesta' => $choice['content'] ?? '',
        'datos'     => $datos,   // null si el agente solo preguntó el plan
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

// =====================================================================
//  Ejecuta la herramienta. AQUÍ la plata sale del SQL, no del modelo.
// =====================================================================
function buscarCopago(PDO $db, string $especialidad, string $planEtiqueta, array $planes): array {
    // Resolver plan_id desde la etiqueta "Aseguradora - Plan"
    $plan_id = null;
    foreach ($planes as $p) {
        if (($p['aseguradora'] . ' - ' . $p['nombre']) === $planEtiqueta) {
            $plan_id = (int) $p['id'];
            break;
        }
    }

    // Resolver especialidad_id
    $stmt = $db->prepare("SELECT id FROM especialidades WHERE nombre = ?");
    $stmt->execute([$especialidad]);
    $especialidad_id = $stmt->fetchColumn();

    if (!$plan_id || !$especialidad_id) {
        return ['error' => 'No se encontró el plan o la especialidad indicada.'];
    }

    // Filtro de la demo: solo hospitales de Guayaquil. Se aplica en la consulta
    // (no borrando filas) para que sobreviva si se reimporta la base de datos.
    $sql = "SELECT h.nombre, h.red, c.copago, c.porcentaje_cobertura
            FROM coberturas c
            JOIN hospitales h ON h.id = c.hospital_id
            WHERE c.plan_id = ? AND c.especialidad_id = ? AND h.ciudad = 'Guayaquil'
            ORDER BY c.copago ASC";
    $stmt = $db->prepare($sql);
    $stmt->execute([$plan_id, $especialidad_id]);
    $hospitales = $stmt->fetchAll();

    foreach ($hospitales as &$h) {
        $h['copago'] = (float) $h['copago'];
    }
    unset($h);

    return [
        'especialidad' => $especialidad,
        'plan'         => $planEtiqueta,
        'recomendado'  => $hospitales[0] ?? null,
        'opciones'     => $hospitales,
    ];
}
