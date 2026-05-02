<?php

namespace App\Env;

class Symbol
{
    const CLASE_VARIABLE  = 'variable';
    const CLASE_CONSTANTE = 'constante';
    const CLASE_FUNCION   = 'funcion';

    public string $tipo;
    public mixed  $valor;
    public string $clase;
    public bool   $esConstante = false;
    public ?array $params      = null;
    public ?string $nombre     = null;
    public int    $fila;
    public int    $columna;

    public function __construct(
        string $tipo,
        mixed  $valor,
        string $clase,
        int    $fila    = 0,
        int    $columna = 0
    ) {
        $this->tipo    = $tipo;
        $this->valor   = $valor;
        $this->clase   = $clase;
        $this->fila    = $fila;
        $this->columna = $columna;
    }

    /** Convierte este símbolo en un Result para usarlo en expresiones */
    public static function aResult(self $sym): Result
    {
        return new Result($sym->tipo, $sym->valor);
    }
}