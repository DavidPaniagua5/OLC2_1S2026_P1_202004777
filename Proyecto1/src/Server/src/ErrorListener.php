<?php

namespace App;

use Antlr\Antlr4\Runtime\Error\Listeners\BaseErrorListener;
use Antlr\Antlr4\Runtime\Recognizer;
use Antlr\Antlr4\Runtime\Error\Exceptions\RecognitionException;

use App\Env\ManejadorErrores;

/**
 * Listener que reemplaza a BailErrorStrategy.
 * Se registra tanto en el Lexer como en el Parser.
 *
 * - Lexer  → errores léxicos  (carácter inválido, string sin cerrar, etc.)
 * - Parser → errores sintácticos (token inesperado, falta }, etc.)
 *
 * Todos los errores se acumulan en ManejadorErrores sin detener el análisis,
 * lo que permite reportar TODOS los errores en una sola pasada.
 */
class ErrorListener extends BaseErrorListener
{
    private ManejadorErrores $errores;
    private string           $fase;   // 'Léxico' | 'Sintáctico'

    public function __construct(ManejadorErrores $errores, string $fase)
    {
        $this->errores = $errores;
        $this->fase    = $fase;
    }

    /**
     * ANTLR llama este método cada vez que encuentra un error.
     *
     * @param Recognizer            $recognizer  Lexer o Parser
     * @param mixed|null            $offendingSymbol  Token ofensivo (null en Lexer)
     * @param int                   $line
     * @param int                   $charPositionInLine
     * @param string                $msg         Mensaje generado por ANTLR
     * @param RecognitionException|null $e
     */
    public function syntaxError(
        Recognizer $recognizer,
        $offendingSymbol,
        int $line,
        int $charPositionInLine,
        string $msg,
        RecognitionException $e = null
): void {

    $descripcion = $this->humanizar($msg, $offendingSymbol, $recognizer, $e);

    $this->errores->agregar(
        $this->fase,
        $descripcion,
        $line,
        $charPositionInLine
    );
}

    // ------------------------------------------------------------------
    // Convierte el mensaje crudo de ANTLR a algo legible en español
    // ------------------------------------------------------------------
    private function humanizar(
        string     $msg,
        mixed      $token,
        Recognizer $recognizer,
        ?RecognitionException $e
    ): string {
        // "extraneous input 'X' expecting Y"
        if (str_contains($msg, 'extraneous input')) {
            $texto = $token?->getText() ?? '?';
            return "Token inesperado '{$texto}'. Verifique la sintaxis en esta posición.";
        }

        // "missing X at Y"
        if (str_contains($msg, 'missing')) {
            preg_match("/missing (.+?) at '(.+?)'/", $msg, $m);
            if (count($m) === 3) {
                $esperado  = $this->traducirToken($m[1]);
                $encontrado = $m[2];
                return "Falta {$esperado} antes de '{$encontrado}'.";
            }
        }

        // "no viable alternative at input 'X'"
        if (str_contains($msg, 'no viable alternative')) {
            $texto = $token?->getText() ?? '?';
            return "No se reconoce '{$texto}' en esta posición.";
        }

        // "mismatched input 'X' expecting Y"
        if (str_contains($msg, 'mismatched input')) {
            preg_match("/mismatched input '(.+?)' expecting (.+)/", $msg, $m);
            if (count($m) === 3) {
                $encontrado = $m[1];
                $esperado   = $this->traducirToken($m[2]);
                return "Se encontró '{$encontrado}' pero se esperaba {$esperado}.";
            }
        }

        // "token recognition error at: 'X'"  (léxico)
        if (str_contains($msg, 'token recognition error')) {
            preg_match("/at: '(.+?)'/", $msg, $m);
            $char = $m[1] ?? '?';
            return "Carácter no reconocido: '{$char}'.";
        }

        // Fallback: devolver el mensaje original sin modificar
        return $msg;
    }

    // ------------------------------------------------------------------
    // Traduce nombres de tokens de ANTLR al español
    // ------------------------------------------------------------------
    private function traducirToken(string $token): string
    {
        $token = trim($token, "{} ");
        $mapa  = [
            "';'"       => "';'",
            "'}'"       => "llave de cierre '}'",
            "'{'"       => "llave de apertura '{'",
            "')'"       => "paréntesis de cierre ')'",
            "'('"       => "paréntesis de apertura '('",
            "':='"      => "operador de asignación ':='",
            "'='"       => "operador de asignación '='",
            'ID'        => 'un identificador',
            'INT_LIT'   => 'un número entero',
            'FLOAT_LIT' => 'un número flotante',
            'STR_LIT'   => 'una cadena de texto',
            'BOOL_LIT'  => 'true o false',
            'EOF'       => 'fin de archivo',
        ];

        return $mapa[$token] ?? "'{$token}'";
    }
}