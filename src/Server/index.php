<?php
require __DIR__ . '/vendor/autoload.php';

use Antlr\Antlr4\Runtime\InputStream;
use Antlr\Antlr4\Runtime\CommonTokenStream;
use Antlr\Antlr4\Runtime\Error\BailErrorStrategy;
use Antlr\Antlr4\Runtime\Error\Exceptions\ParseCancellationException;
use Antlr\Antlr4\Runtime\Error\Exceptions\InputMismatchException;

use App\Interpreter;
use App\GeneradorDot;

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

    $inputStream = InputStream::fromString($input);
    $lexer       = new GrammarLexer($inputStream);
    $tokens      = new CommonTokenStream($lexer);
    $parser      = new GrammarParser($tokens);
    $parser->setErrorHandler(new BailErrorStrategy());
    $tree        = $parser->programa();

    // SVG se genera del árbol sintáctico ANTES de interpretar (Graphviz en servidor)
    $respuesta['svg'] = GeneradorDot::generarSVG($tree, $parser);

    $interprete = new Interpreter();
    $salida     = $interprete->visit($tree);

    $respuesta['success']  = true;
    $respuesta['output']   = $salida;
    $respuesta['simbolos'] = $interprete->tablaSimbolos();
    $respuesta['errors']   = $interprete->errores->comoArreglo();

    echo json_encode($respuesta);

} catch (ParseCancellationException $e) {
    $causa = $e->getPrevious();
    if ($causa instanceof InputMismatchException) {
        $token = $causa->getOffendingToken();
        $vocab = $causa->getRecognizer()->getVocabulary();
        $esperados = array_map(
            fn($t) => $vocab->getDisplayName($t),
            $causa->getExpectedTokens()->toArray()
        );
        $respuesta['errors'][] = [
            'numero'      => 1,
            'tipo'        => 'Sintáctico',
            'descripcion' => sprintf(
                "Se esperaba %s pero se encontró '%s'",
                implode(' o ', $esperados),
                $token?->getText() ?? 'EOF'
            ),
            'linea'   => $token?->getLine() ?? 0,
            'columna' => $token?->getCharPositionInLine() ?? 0,
        ];
    } else {
        $respuesta['errors'][] = [
            'numero' => 1, 'tipo' => 'Sintáctico',
            'descripcion' => $e->getMessage(), 'linea' => 0, 'columna' => 0,
        ];
    }
    echo json_encode($respuesta);

} catch (\Exception $e) {
    $respuesta['errors'][] = [
        'numero' => 1, 'tipo' => 'Error interno',
        'descripcion' => $e->getMessage(), 'linea' => 0, 'columna' => 0,
    ];
    echo json_encode($respuesta);
}