<?php

namespace App\Env;

class Result
{
    const INT32   = 'int32';
    const FLOAT32 = 'float32';
    const BOOL    = 'bool';
    const RUNE    = 'rune';
    const STRING  = 'string';
    const NIL     = 'nil';

    public string $tipo;
    public mixed  $valor;
    public bool   $esReturn   = false;
    public bool   $esBreak    = false;
    public bool   $esContinue = false;

    public function __construct(string $tipo, mixed $valor)
    {
        $this->tipo  = $tipo;
        $this->valor = $valor;
    }

    public static function nulo(): self
    {
        return new self(self::NIL, null);
    }
}