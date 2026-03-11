<?php

namespace App\Utils;

use App\Env\Result;

/**
 * Utilidad para formatear valores según su tipo.
 * Replica el comportamiento de Go en la impresión.
 */
class ValueFormatter
{
    public static function toString(Result $res): string
    {
        return match ($res->tipo) {
            Result::NIL     => '<nil>',
            Result::BOOL    => $res->valor ? 'true' : 'false',
            Result::FLOAT32 => self::formatearFloat($res->valor),
            default         => (string)($res->valor ?? '<nil>'),
        };
    }

    /**
     * Formatea un float sin ceros innecesarios.
     */
    private static function formatearFloat(float $f): string
    {
        // Usar la representación más corta sin trailing zeros, igual que Go
        $s = rtrim(sprintf('%.7g', $f), '0');
        if (str_ends_with($s, '.')) {
            $s .= '0';
        }
        return $s;
    }

    /**
     * Serializa un valor para la tabla de símbolos.
     */
    public static function serializarParaTabla(mixed $valor): mixed
    {
        if (is_object($valor))  return '(objeto)';
        if (is_array($valor))   return json_encode($valor);
        if (is_bool($valor))    return $valor ? 'true' : 'false';
        if (is_null($valor))    return null;
        return $valor;
    }

    /**
     * Convierte un valor a un tipo de destino.
     */
    public static function castear(Result $res, string $tipoDestino): mixed
    {
        if ($res->tipo === Result::NIL) {
            return null;
        }

        return match ($tipoDestino) {
            Result::INT32   => intval($res->valor),
            Result::FLOAT32 => floatval($res->valor),
            Result::BOOL    => (bool)$res->valor,
            Result::RUNE    => is_string($res->valor)
                                   ? mb_ord($res->valor)
                                   : intval($res->valor),
            Result::STRING  => self::toString($res),
            default         => $res->valor,
        };
    }
}