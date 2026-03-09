<?php
require __DIR__ . '/vendor/autoload.php';

use Antlr\Antlr4\Runtime\InputStream;
use Antlr\Antlr4\Runtime\CommonTokenStream;
use Antlr\Antlr4\Runtime\Error\BailErrorStrategy;
use Antlr\Antlr4\Runtime\Error\Exceptions\ParseCancellationException;
use Antlr\Antlr4\Runtime\Error\Exceptions\InputMismatchException;

use App\Interpreter;

// CORS (para React)
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, OPTIONS");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

header("Content-Type: application/json");

try {

    $data = json_decode(file_get_contents("php://input"), true);
    $input = $data["expression"] ?? "";

    if (empty($input)) {
        echo json_encode([
            "success" => false,
            "output" => "Por favor ingrese código para parsear."
        ]);
        exit;
    }

    $inputStream = InputStream::fromString($input);

    $lexer  = new GrammarLexer($inputStream);
    $tokens = new CommonTokenStream($lexer);
    $parser = new GrammarParser($tokens);

    $parser->setErrorHandler(new BailErrorStrategy());

    $tree = $parser->p();

    $interpreter = new Interpreter();
    $output = $interpreter->visit($tree);

    $output = str_replace(["\r\n", "\r"], "\n", $output);
    $output = preg_replace('/^[ \t]+/m', '', $output);

    echo json_encode([
        "success" => true,
        "output" => $output
    ]);

} catch (ParseCancellationException $e) {

    $cause = $e->getPrevious();

    if ($cause instanceof InputMismatchException) {

        $offending = $cause->getOffendingToken();
        $expected  = $cause->getExpectedTokens();

        $found = $offending ? $offending->getText() : 'EOF';

        $parserObj = $cause->getRecognizer();
        $vocab = $parserObj->getVocabulary();

        $expectedNames = [];
        foreach ($expected->toArray() as $t) {
            $expectedNames[] = $vocab->getDisplayName($t);
        }

        $error = sprintf(
            "Error sintáctico en línea %d, columna %d: se esperaba %s y se encontró %s %s",
            $offending->getLine(),
            $offending->getCharPositionInLine(),
            implode(" o ", $expectedNames),
            $found,
            $vocab->getDisplayName($offending->getType())
        );

        echo json_encode([
            "success" => false,
            "output" => $error
        ]);

    } else {

        echo json_encode([
            "success" => false,
            "output" => $e->getMessage()
        ]);
    }

} catch (Exception $e) {

    echo json_encode([
        "success" => false,
        "output" => $e->getMessage()
    ]);
}