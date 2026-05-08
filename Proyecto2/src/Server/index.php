<?php
require __DIR__ . '/vendor/autoload.php';

use Antlr\Antlr4\Runtime\InputStream;
use Antlr\Antlr4\Runtime\CommonTokenStream;
use Antlr\Antlr4\Runtime\Error\DefaultErrorStrategy;

use App\Interpreter;
use App\GeneradorDot;
use App\ErrorListener;
use App\Env\ManejadorErrores;
use App\Visitors\CodeGenerator;

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }
header('Content-Type: application/json');

$respuesta = [
    'success'  => false,
    'output'   => '',
    'arm64'     => '',
    'ejecucion' => '',
    'svg'      => '',
    'simbolos' => [],
    'errors'   => [],
];

try {
$data = json_decode(file_get_contents('php://input'), true);
$action = $data['action'] ?? 'compile'; // Por defecto compilar

if ($action === 'execute_arm64') {
    $armCode = $data['armCode'] ?? '';
    $path = "/home/paniagua/Documentos/Andres/2026/Compi/Lab/OLC2_1S2026_P1_202004777/Proyecto2/Salidas/";
    $fileName = "programa_arm64.s";
    
    // 1. Guardar el archivo físicamente
    file_put_contents($path . $fileName, $armCode);
    
    // 2. Ejecutar el script .sh
    // Cambiamos al directorio y ejecutamos el script pasando el nombre del archivo
    $command = "cd $path && ./run_arm64.sh $fileName 2>&1";
    $output = shell_exec($command);
    
    echo json_encode([
        'success' => true,
        'ejecucion' => $output ?: "Programa ejecutado (sin salida de texto)."
    ]);
    exit;
}

    $data  = json_decode(file_get_contents('php://input'), true);
    $input = trim($data['expression'] ?? '');

    if ($input === '') {
        $respuesta['errors'][] = [
            'numero' => 1, 'tipo' => 'Error',
            'descripcion' => 'No se recibió código.', 'linea' => 0, 'columna' => 0,
        ];
        echo json_encode($respuesta);
        exit;
    }

    // ── Manejador de errores compartido entre lexer, parser e intérprete ──
    $errores = new ManejadorErrores();

    // ── ANÁLISIS LÉXICO ───────────────────────────────────────────────────
    $inputStream = InputStream::fromString($input);
    $lexer       = new GrammarLexer($inputStream);

    // Quitar el listener de consola por defecto de ANTLR y poner el mio
    $lexer->removeErrorListeners();
    $lexer->addErrorListener(new ErrorListener($errores, 'Léxico'));

    // ── ANÁLISIS SINTÁCTICO ───────────────────────────────────────────────
    $tokens = new CommonTokenStream($lexer);
    $parser = new GrammarParser($tokens);

    // DefaultErrorStrategy: intenta recuperarse y continuar (reporta todos los errores)
    $parser->setErrorHandler(new DefaultErrorStrategy());
    $parser->removeErrorListeners();
    $parser->addErrorListener(new ErrorListener($errores, 'Sintáctico'));

    $tree = $parser->programa();

    // ── Generar SVG del AST (siempre, aunque haya errores sintácticos) ────
    try {
        $respuesta['svg'] = GeneradorDot::generarSVG($tree, $parser);
    } catch (\Throwable $e) {
        // El AST puede estar incompleto si hay errores — no es fatal
        $respuesta['svg'] = '';
    }

    // ── EJECUCIÓN (solo si no hay errores sintácticos ni léxicos) ─────────
    if (!$errores->tieneErrores()) {

    // 1. Análisis semántico (tabla de símbolos, validación de tipos)
    $interprete = new Interpreter($errores);
    $interprete->visit($tree);
    $respuesta['simbolos'] = $interprete->tablaSimbolos();

    // 2. Generación de código ARM64
    // if (!$errores->tieneErrores()) {
        $codegen   = new CodeGenerator($errores);
        $codigoArm = $codegen->visitPrograma($tree);
        $respuesta['arm64'] = $codigoArm;
        $respuesta['success']   = true;    
    } else {
        $respuesta['success'] = false;
    }
// }
    $respuesta['errors'] = $errores->comoArreglo();
    echo json_encode($respuesta);


    
} catch (\Throwable $e) {
    $respuesta['errors'][] = [
        'numero'      => 1,
        'tipo'        => 'Error interno',
        'descripcion' => $e->getMessage() 
                       . ' | Clase: ' . get_class($e)
                       . ' | Archivo: ' . basename($e->getFile())
                       . ':' . $e->getLine()
                       . ' | Trace: ' . str_replace("\n", " >> ", 
                           implode("\n", array_slice(
                               explode("\n", $e->getTraceAsString()), 0, 5
                           ))),
        'linea'       => 0,
        'columna'     => 0,
    ];
    echo json_encode($respuesta);
}