<?php

namespace App\Env;

class EntradaError
{
    public int    $numero;
    public string $tipo;        // 'Léxico' | 'Sintáctico' | 'Semántico'
    public string $descripcion;
    public int    $linea;
    public int    $columna;

    public function __construct(
        int    $numero,
        string $tipo,
        string $descripcion,
        int    $linea,
        int    $columna
    ) {
        $this->numero      = $numero;
        $this->tipo        = $tipo;
        $this->descripcion = $descripcion;
        $this->linea       = $linea;
        $this->columna     = $columna;
    }
}

class ManejadorErrores
{
    /** @var EntradaError[] */
    private array $errores = [];

    public function agregar(
        string $tipo,
        string $descripcion,
        int    $linea   = 0,
        int    $columna = 0
    ): void {
        $this->errores[] = new EntradaError(
            count($this->errores) + 1,
            $tipo,
            $descripcion,
            $linea,
            $columna
        );
    }

    public function tieneErrores(): bool
    {
        return !empty($this->errores);
    }

    /** @return EntradaError[] */
    public function obtenerTodos(): array
    {
        return $this->errores;
    }

    /** Serializable para JSON */
    public function comoArreglo(): array
    {
        return array_map(fn(EntradaError $e) => [
            'numero'      => $e->numero,
            'tipo'        => $e->tipo,
            'descripcion' => $e->descripcion,
            'linea'       => $e->linea,
            'columna'     => $e->columna,
        ], $this->errores);
    }
}