<?php

namespace App\Visitors;

use App\Env\{Environment, ManejadorErrores, Result};

/**
 * Clase base para todos los visitors.
 * Proporciona funcionalidad común y acceso al contexto compartido.
 */
abstract class BaseVisitor extends \GrammarBaseVisitor
{
    protected string $consola = '';
    protected Environment $envGlobal;
    protected Environment $env;
    protected ManejadorErrores $errores;
    protected string $ambitoActual = 'global';
    protected array $registroSimbolos = [];

    public function __construct(
        Environment &$envGlobal,
        Environment &$env,
        ManejadorErrores $errores,
        string $ambitoActual = 'global'
    ) {
        $this->envGlobal     = $envGlobal;
        $this->env           = $env;
        $this->errores       = $errores;
        $this->ambitoActual  = $ambitoActual;
    }

    /**
     * Obtener símbolo del entorno actual, buscando hacia arriba.
     */
    protected function obtenerSimbolo(string $nombre)
    {
        try {
            return $this->env->obtener($nombre);
        } catch (\RuntimeException $e) {
            $this->errores->agregar('Semántico', $e->getMessage());
            return null;
        }
    }

    /**
     * Agrega texto a la consola.
     */
    public function agregarConsola(string $texto): void
    {
        $this->consola .= $texto;
    }

    public function obtenerConsola(): string
    {
        return $this->consola;
    }

    public function limpiarConsola(): void
    {
        $this->consola = '';
    }

    /**
     * Registra un símbolo en la tabla central.
     */
    public function registrarSimbolo(
        string $id,
        string $tipo,
        mixed  $valor,
        string $clase,
        int    $fila    = 0,
        int    $columna = 0
    ): void {
        foreach ($this->registroSimbolos as &$entrada) {
            if ($entrada['id'] === $id && $entrada['ambito'] === $this->ambitoActual) {
                $entrada['valor'] = $this->serializarParaTabla($valor);
                return;
            }
        }
        unset($entrada);

        $this->registroSimbolos[] = [
            'id'      => $id,
            'tipo'    => $tipo,
            'valor'   => $this->serializarParaTabla($valor),
            'clase'   => $clase,
            'ambito'  => $this->ambitoActual,
            'fila'    => $fila,
            'columna' => $columna,
        ];
    }

    protected function serializarParaTabla(mixed $valor): mixed
    {
        if (is_object($valor))  return '(objeto)';
        if (is_array($valor))   return json_encode($valor);
        if (is_bool($valor))    return $valor ? 'true' : 'false';
        if (is_null($valor))    return null;
        return $valor;
    }

    public function obtenerRegistroSimbolos(): array
    {
        return $this->registroSimbolos;
    }
}