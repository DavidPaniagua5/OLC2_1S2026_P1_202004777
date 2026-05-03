<?php

namespace App\Expressions;

use App\Env\{Result, TiposSistema, ManejadorErrores};

/**
 * Evaluador de operaciones binarias.
 * Encapsula toda la lógica de operadores binarios.
 */
class BinaryOperator
{
    private ManejadorErrores $errores;

    public function __construct(ManejadorErrores $errores)
    {
        $this->errores = $errores;
    }

    /**
     * Aplica un operador binario respetando las tablas de tipos.
     */
    public function aplicar(string $op, Result $izq, Result $der): Result
    {
        // Operación con nil → siempre error y nil
        if ($izq->tipo === Result::NIL || $der->tipo === Result::NIL) {
            $this->errores->agregar('Semántico', "No se puede operar con nil.");
            return Result::nulo();
        }

        $tipoResultado = TiposSistema::resultado($op, $izq->tipo, $der->tipo);

        if ($tipoResultado === null) {
            $this->errores->agregar(
                'Semántico',
                "Operación '{$op}' no válida entre '{$izq->tipo}' y '{$der->tipo}'."
            );
            return Result::nulo();
        }

        $l = $izq->valor;
        $r = $der->valor;

        try {
            $valor = $this->calcular($op, $l, $r, $izq->tipo, $tipoResultado);
        } catch (\RuntimeException $e) {
            $this->errores->agregar('Semántico', $e->getMessage());
            return Result::nulo();
        }

        if ($valor === null) {
            return Result::nulo();
        }

        return new Result($tipoResultado, $valor);
    }

    /**
     * Realiza el cálculo basado en el operador.
     */
    private function calcular(
        string $op,
        mixed  $l,
        mixed  $r,
        string $tipoIzq,
        string $tipoResultado
    ): mixed {
        return match ($op) {
            '+'  => $l + $r,
            '-'  => $l - $r,
            // String * int  → str_repeat
            '*'  => ($tipoIzq === Result::STRING)
                        ? str_repeat((string)$l, (int)$r)
                        : $l * $r,
            '/'  => $this->dividir($tipoResultado, $l, $r),
            '%'  => $this->modulo($l, $r),
            '==' => $l == $r,
            '!=' => $l != $r,
            '<'  => $l < $r,
            '<=' => $l <= $r,
            '>'  => $l > $r,
            '>=' => $l >= $r,
            default => null,
        };
    }

    private function dividir(string $tipoRes, mixed $l, mixed $r): mixed
    {
        if ($r == 0) {
            throw new \RuntimeException('División por cero.');
        }
        // División entera si el resultado es int32 (igual que Go)
        if ($tipoRes === Result::INT32) {
            return intdiv((int)$l, (int)$r);
        }
        return $l / $r;
    }

    private function modulo(mixed $l, mixed $r): int
    {
        if ((int)$r === 0) {
            throw new \RuntimeException('Módulo por cero.');
        }
        return (int)$l % (int)$r;
    }
}