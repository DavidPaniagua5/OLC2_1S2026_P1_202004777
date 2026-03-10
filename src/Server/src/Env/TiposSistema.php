<?php

namespace App\Env;

class TiposSistema
{
    // ---- SUMA (+) ------------------------------------------------
    private static array $suma = [
        Result::INT32   => [
            Result::INT32   => Result::INT32,
            Result::FLOAT32 => Result::FLOAT32,
            Result::RUNE    => Result::INT32,
        ],
        Result::FLOAT32 => [
            Result::INT32   => Result::FLOAT32,
            Result::FLOAT32 => Result::FLOAT32,
            Result::RUNE    => Result::FLOAT32,
        ],
        Result::RUNE    => [
            Result::INT32   => Result::INT32,
            Result::FLOAT32 => Result::FLOAT32,
            Result::RUNE    => Result::INT32,
        ],
        Result::STRING  => [
            Result::STRING  => Result::STRING,
        ],
    ];

    // ---- RESTA (-) -----------------------------------------------
    private static array $resta = [
        Result::INT32   => [
            Result::INT32   => Result::INT32,
            Result::FLOAT32 => Result::FLOAT32,
            Result::RUNE    => Result::INT32,
        ],
        Result::FLOAT32 => [
            Result::INT32   => Result::FLOAT32,
            Result::FLOAT32 => Result::FLOAT32,
            Result::RUNE    => Result::FLOAT32,
        ],
        Result::RUNE    => [
            Result::INT32   => Result::INT32,
            Result::FLOAT32 => Result::FLOAT32,
            Result::RUNE    => Result::INT32,
        ],
    ];

    // ---- MULTIPLICACIÓN (*) --------------------------------------
    private static array $mult = [
        Result::INT32   => [
            Result::INT32   => Result::INT32,
            Result::FLOAT32 => Result::FLOAT32,
            Result::RUNE    => Result::INT32,
            Result::STRING  => Result::STRING,
        ],
        Result::FLOAT32 => [
            Result::INT32   => Result::FLOAT32,
            Result::FLOAT32 => Result::FLOAT32,
            Result::RUNE    => Result::FLOAT32,
        ],
        Result::RUNE    => [
            Result::INT32   => Result::INT32,
            Result::FLOAT32 => Result::FLOAT32,
            Result::RUNE    => Result::INT32,
        ],
        Result::STRING  => [
            Result::INT32   => Result::STRING,
        ],
    ];

    // ---- DIVISIÓN (/) --------------------------------------------
    private static array $div = [
        Result::INT32   => [
            Result::INT32   => Result::INT32,
            Result::FLOAT32 => Result::FLOAT32,
            Result::RUNE    => Result::INT32,
        ],
        Result::FLOAT32 => [
            Result::INT32   => Result::FLOAT32,
            Result::FLOAT32 => Result::FLOAT32,
            Result::RUNE    => Result::FLOAT32,
        ],
        Result::RUNE    => [
            Result::INT32   => Result::INT32,
            Result::FLOAT32 => Result::FLOAT32,
            Result::RUNE    => Result::INT32,
        ],
    ];

    // ---- MÓDULO (%) ----------------------------------------------
    private static array $modulo = [
        Result::INT32 => [
            Result::INT32 => Result::INT32,
            Result::RUNE  => Result::INT32,
        ],
        Result::RUNE  => [
            Result::INT32 => Result::INT32,
            Result::RUNE  => Result::INT32,
        ],
    ];

    // ---- IGUALDAD / DESIGUALDAD (== / !=) -----------------------
    private static array $igualdad = [
        Result::INT32   => [
            Result::INT32   => Result::BOOL,
            Result::FLOAT32 => Result::BOOL,
            Result::RUNE    => Result::BOOL,
        ],
        Result::FLOAT32 => [
            Result::INT32   => Result::BOOL,
            Result::FLOAT32 => Result::BOOL,
            Result::RUNE    => Result::BOOL,
        ],
        Result::RUNE    => [
            Result::INT32   => Result::BOOL,
            Result::FLOAT32 => Result::BOOL,
            Result::RUNE    => Result::BOOL,
        ],
        Result::BOOL    => [
            Result::BOOL    => Result::BOOL,
        ],
        Result::STRING  => [
            Result::STRING  => Result::BOOL,
        ],
    ];

    // ---- COMPARACIÓN (< <= > >=) --------------------------------
    private static array $comparacion = [
        Result::INT32   => [
            Result::INT32   => Result::BOOL,
            Result::FLOAT32 => Result::BOOL,
            Result::RUNE    => Result::BOOL,
        ],
        Result::FLOAT32 => [
            Result::INT32   => Result::BOOL,
            Result::FLOAT32 => Result::BOOL,
            Result::RUNE    => Result::BOOL,
        ],
        Result::RUNE    => [
            Result::INT32   => Result::BOOL,
            Result::FLOAT32 => Result::BOOL,
            Result::RUNE    => Result::BOOL,
        ],
        Result::STRING  => [
            Result::STRING  => Result::BOOL,
        ],
    ];

    // ------------------------------------------------------------------
    // Método principal: retorna el tipo resultado o null si es inválido
    // ------------------------------------------------------------------
    public static function resultado(string $op, string $izq, string $der): ?string
    {
        $tabla = match ($op) {
            '+'        => self::$suma,
            '-'        => self::$resta,
            '*'        => self::$mult,
            '/'        => self::$div,
            '%'        => self::$modulo,
            '==', '!=' => self::$igualdad,
            '<', '<=',
            '>', '>='  => self::$comparacion,
            default    => null,
        };

        if ($tabla === null) {
            return null;
        }

        return $tabla[$izq][$der] ?? null;
    }

    /** Valor por defecto de cada tipo */
    public static function valorDefecto(string $tipo): mixed
    {
        return match ($tipo) {
            Result::INT32   => 0,
            Result::FLOAT32 => 0.0,
            Result::BOOL    => false,
            Result::RUNE    => 0,
            Result::STRING  => '',
            default         => null,
        };
    }
}