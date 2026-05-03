<?php

namespace App\Env;

class Environment
{
    private ?self  $padre;
    /** @var array<string, Symbol> */
    private array  $tabla = [];

    public function __construct(?self $padre = null)
    {
        $this->padre = $padre;
    }

    // ----------------------------------------------------------
    // Declarar un símbolo en el ámbito ACTUAL
    // ----------------------------------------------------------
    public function declarar(string $nombre, Symbol $sym): void
    {
        $this->tabla[$nombre] = $sym;
    }

    // ----------------------------------------------------------
    // Obtener un símbolo buscando hacia arriba en la cadena
    // ----------------------------------------------------------
    public function obtener(string $nombre): Symbol
    {
        if (array_key_exists($nombre, $this->tabla)) {
            return $this->tabla[$nombre];
        }
        if ($this->padre !== null) {
            return $this->padre->obtener($nombre);
        }
        throw new \RuntimeException("Variable '{$nombre}' no declarada en el ámbito actual.");
    }

    // ----------------------------------------------------------
    // Asignar valor a un símbolo ya existente (busca en la cadena)
    // ----------------------------------------------------------
    public function asignar(string $nombre, mixed $valor): void
    {
        if (array_key_exists($nombre, $this->tabla)) {
            if ($this->tabla[$nombre]->esConstante) {
                throw new \RuntimeException("No se puede modificar la constante '{$nombre}'.");
            }
            $this->tabla[$nombre]->valor = $valor;
            return;
        }
        if ($this->padre !== null) {
            $this->padre->asignar($nombre, $valor);
            return;
        }
        throw new \RuntimeException("Variable '{$nombre}' no declarada.");
    }

    // ----------------------------------------------------------
    // ¿Existe en el ámbito LOCAL? (para detectar redeclaraciones)
    // ----------------------------------------------------------
    public function existeLocal(string $nombre): bool
    {
        return array_key_exists($nombre, $this->tabla);
    }

    /** @return array<string, Symbol> */
    public function tablaLocal(): array
    {
        return $this->tabla;
    }

    /**
     * Exporta los símbolos del ámbito LOCAL como filas JSON.
     * No sube a padres (el Interpreter decide qué combinar).
     *
     * Formato de cada fila (lo que espera Simbolos.jsx + columnas extra):
     *   id | tipo | valor | clase | ambito | fila | columna
     *
     * @return array<int, array>
     */
    public function exportarConAmbito(string $nombreAmbito = 'global'): array
    {
        $filas = [];

        foreach ($this->tabla as $nombre => $sym) {
            // Las funciones almacenan un BloqueContext como valor → no serializable
            if ($sym->clase === Symbol::CLASE_FUNCION) {
                // Igual incluimos la función pero con valor legible
                $filas[] = [
                    'id'      => $nombre,
                    'tipo'    => $sym->tipo !== 'nil' ? $sym->tipo : 'void',
                    'valor'   => '— (función)',
                    'clase'   => 'funcion',
                    'ambito'  => $nombreAmbito,
                    'fila'    => $sym->fila,
                    'columna' => $sym->columna,
                ];
                continue;
            }

            $filas[] = [
                'id'      => $nombre,
                'tipo'    => $sym->tipo,
                'valor'   => $this->serializarValor($sym->valor),
                'clase'   => $sym->esConstante ? 'constante' : $sym->clase,
                'ambito'  => $nombreAmbito,
                'fila'    => $sym->fila,
                'columna' => $sym->columna,
            ];
        }

        return $filas;
    }

    /**
     * @deprecated usar exportarConAmbito()
     */
    public function exportar(string $nombreAmbito = 'global', bool $incluirPadre = true): array
    {
        return $this->exportarConAmbito($nombreAmbito);
    }

    private function serializarValor(mixed $valor): mixed
    {
        if (is_object($valor)) {
            return '(objeto)';
        }
        if (is_array($valor)) {
            return array_map([$this, 'serializarValor'], $valor);
        }
        if (is_bool($valor)) {
            return $valor ? 'true' : 'false';
        }
        if (is_null($valor)) {
            return null;
        }
        return $valor;
    }
}