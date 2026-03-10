<?php
require __DIR__ . '/vendor/autoload.php';

use Antlr\Antlr4\Runtime\InputStream;
use Antlr\Antlr4\Runtime\CommonTokenStream;
use Antlr\Antlr4\Runtime\Error\DefaultErrorStrategy;

use App\Interpreter;
use App\GeneradorDot;
use App\ErrorListener;
use App\Env\ManejadorErrores;

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }
header('Content-Type: application/json');

$respuesta = [
    'success'  => false,
    'output'   => '',
    'svg'      => '',
    'simbolos' => [],
    'errors'   => [],
];

try {
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
        $interprete = new Interpreter($errores);   // comparte el mismo manejador
        $salida     = $interprete->visit($tree);
        if (!$errores->tieneErrores()){
            $respuesta['success']  = true;
            $respuesta['output']   = $salida;
            $respuesta['simbolos'] = $interprete->tablaSimbolos();
        }
    } else {
        // Hay errores de análisis — devolver el árbol parcial pero no ejecutar
        $respuesta['success'] = false;
    }

    $respuesta['errors'] = $errores->comoArreglo();
    echo json_encode($respuesta);

} catch (\Throwable $e) {
    $respuesta['errors'][] = [
        'numero'      => 1,
        'tipo'        => 'Error interno',
        'descripcion' => $e->getMessage(),
        'linea'       => 0,
        'columna'     => 0,
    ];
    echo json_encode($respuesta);
}