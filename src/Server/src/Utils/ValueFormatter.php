<?php

namespace App\Utils;

use App\Env\Result;

class ValueFormatter
{
    public static function toString(Result $result): string
    {
        if ($result->tipo === Result::NIL) {
            return '<nil>';
        }

        if ($result->tipo === Result::BOOL) {
            return $result->valor ? 'true' : 'false';
        }

        if ($result->tipo === Result::RUNE) {
            return (string) $result->valor;
        }

        if ($result->tipo === Result::FLOAT32) {
            $valor = $result->valor;
            
            if (is_float($valor) || is_int($valor)) {
                $str = (string)(float)$valor;
                
                if (strpos($str, '.') === false) {
                    $str .= '.0';
                } else {
                    $partes = explode('.', $str);
                    $decimal = $partes[1];
                    
                    if ((int)$decimal === 0) {
                        $str = $partes[0] . '.0';
                    }
                }
                return $str;
            }
            
            return '0.0';
        }

        if ($result->tipo === Result::INT32) {
            return (string)(int)$result->valor;
        }

        if ($result->tipo === Result::STRING) {
            return $result->valor === null ? '' : (string)$result->valor;
        }

        return (string)$result->valor;
    }

    public static function castear(Result $result, string $tipoDestino): mixed
    {
        if ($result->tipo === Result::NIL) {
            return null;
        }

        if ($tipoDestino === Result::INT32) {
            return (int)$result->valor;
        }

        if ($tipoDestino === Result::FLOAT32) {
            return (float)$result->valor;
        }

        if ($tipoDestino === Result::BOOL) {
            return (bool)$result->valor;
        }

        if ($tipoDestino === Result::RUNE) {
            //return (string) $result->valor;
            return (int)$result->valor;
        }

        if ($tipoDestino === Result::STRING) {
            return (string)$result->valor;
        }

        return $result->valor;
    }

    public static function serializarParaTabla(mixed $valor): mixed
    {
        if ($valor === null) {
            return '<nil>';
        }

        if (is_bool($valor)) {
            return $valor ? 'true' : 'false';
        }

        if (is_float($valor)) {
            $str = (string)$valor;
            if (strpos($str, '.') === false) {
                $str .= '.0';
            }
            return $str;
        }

        if (is_string($valor)) {
            return $valor === '' ? '(vacío)' : $valor;
        }

        return $valor;
    }
}