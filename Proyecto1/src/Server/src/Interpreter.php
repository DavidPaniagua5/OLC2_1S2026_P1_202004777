<?php

namespace App;

use Context\ProgramaContext;
use Context\FuncDeclContext;
use Context\BloqueContext;
use App\Env\{Environment, Symbol, Result, ManejadorErrores};
use App\Visitors\ProgramVisitor;

/**
 * Coordinador principal del intérprete.
 * Delega la ejecución a visitors especializados.
 */
class Interpreter extends \GrammarBaseVisitor
{
    private ProgramVisitor $programVisitor;
    public ManejadorErrores $errores;

    public function __construct(?ManejadorErrores $errores = null)
    {
        $this->errores = $errores ?? new ManejadorErrores();
        $envGlobal = new Environment();
        $envLocal = new Environment();

        $this->programVisitor = new ProgramVisitor(
            $envGlobal,
            $envLocal, 
            $this->errores
        );
    }

    /**
     * Punto de entrada: procesa el árbol sintáctico.
     */
    public function visit($tree): string
    {
        return $this->programVisitor->visit($tree);
    }

    public function tablaSimbolos(): array
    {
        // Combinar funciones globales + símbolos registrados
        $funciones = $this->programVisitor->obtenerEnv()->exportarConAmbito('global');
        $registrados = $this->programVisitor->obtenerRegistroSimbolos();
        return array_merge($funciones, $registrados);
    }

    public function obtenerProgramVisitor(): ProgramVisitor
    {
        return $this->programVisitor;
    }
}