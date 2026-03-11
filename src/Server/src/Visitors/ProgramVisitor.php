<?php

namespace App\Visitors;

use Context\ProgramaContext;
use Context\FuncDeclContext;
use Context\BloqueContext;
use App\Env\{Environment, Symbol, Result, ManejadorErrores};

/**
 * Procesa el programa completo.
 * Responsable de:
 * - Hoisting de funciones
 * - Declaraciones globales
 * - Búsqueda y ejecución de main()
 */
class ProgramVisitor extends BaseVisitor
{
    protected Environment $envGlobalLocal;
    protected array $registroSimbolosLocal = [];

    public function __construct(
        Environment $envGlobal,
        Environment $env,
        ManejadorErrores $errores,
        string $ambitoActual = 'global'
    ) {
        parent::__construct($envGlobal, $env, $errores, $ambitoActual);
        $this->envGlobalLocal = $envGlobal;
    }

    public function visitPrograma(ProgramaContext $ctx): string
    {
        // FASE 1: HOISTING - Registrar todas las funciones
        foreach ($ctx->declaracionTop() as $decl) {
            if ($decl->funcDecl() !== null) {
                $this->registrarFuncion($decl->funcDecl());
            }
        }

        // FASE 2: Ejecutar declaraciones globales (var / const)
        $declVisitor = new DeclarationVisitor(
            $this->envGlobal,
            $this->env,
            $this->errores,
            'global'
        );

        foreach ($ctx->declaracionTop() as $decl) {
            if ($decl->varDecl() !== null) {
                $declVisitor->visit($decl->varDecl());
            } elseif ($decl->constDecl() !== null) {
                $declVisitor->visit($decl->constDecl());
            }
        }

        $this->registroSimbolosLocal = $declVisitor->obtenerRegistroSimbolos();
        $this->consola .= $declVisitor->obtenerConsola();

        // FASE 3: Buscar y ejecutar main()
        try {
            $main = $this->envGlobal->obtener('main');
        } catch (\RuntimeException $e) {
            $this->errores->agregar('Semántico', 'No se encontró la función main.');
            return $this->consola;
        }

        if ($main->clase !== Symbol::CLASE_FUNCION) {
            $this->errores->agregar('Semántico', "'main' no es una función.");
            return $this->consola;
        }

        $this->ejecutarFuncion($main, []);

        return $this->consola;
    }

    private function registrarFuncion(FuncDeclContext $ctx): void
    {
        $nombre = $ctx->ID()->getText();
        $params = [];

        if ($ctx->listaParams() !== null) {
            foreach ($ctx->listaParams()->param() as $p) {
                $params[] = [
                    'id'   => $p->ID()->getText(),
                    'tipo' => $p->tipo()->getText(),
                ];
            }
        }

        $tipoRet = ($ctx->tipoRetorno() !== null)
            ? $ctx->tipoRetorno()->getText()
            : Result::NIL;

        $sym          = new Symbol($tipoRet, $ctx->bloque(), Symbol::CLASE_FUNCION, 0, 0);
        $sym->params  = $params;
        $sym->nombre  = $nombre;
        $this->envGlobal->declarar($nombre, $sym);
    }

    private function ejecutarFuncion(Symbol $fn, array $args): Result
    {
        $nuevoEnv = new Environment($this->envGlobal);

        if ($fn->params !== null) {
            foreach ($fn->params as $i => $param) {
                $arg = $args[$i] ?? Result::nulo();
                $sym = new Symbol($param['tipo'], $arg->valor, Symbol::CLASE_VARIABLE, 0, 0);
                $nuevoEnv->declarar($param['id'], $sym);
            }
        }

        $envAnterior = $this->env;
        $ambitoAnterior = $this->ambitoActual;
        
        $this->env = $nuevoEnv;
        $this->ambitoActual = $fn->nombre ?? 'funcion';

        $resultado = $this->visitBloque($fn->valor);
        
        // Si es null, convertir a Result::nulo()
        if ($resultado === null) {
            $resultado = Result::nulo();
        }

        $this->env = $envAnterior;
        $this->ambitoActual = $ambitoAnterior;
        
        return $resultado;
    }

    public function visitBloque(BloqueContext $ctx): Result
    {
        // Crear nuevo ámbito para el bloque
        $envAnterior = $this->env;
        $this->env = new Environment($envAnterior);

        $resultado = Result::nulo();
        
        foreach ($ctx->sentencia() as $sent) {
            $visitor = $this->crearVisitorParaSentencia($sent);
            
            if ($visitor !== null) {
                $resultado = $visitor->visit($sent);
                
                $this->consola .= $visitor->obtenerConsola();
                
                $simbolos = $visitor->obtenerRegistroSimbolos();
                foreach ($simbolos as $sym) {
                    $this->registroSimbolosLocal[] = $sym;
                }
            }

            // Propagar señales de control hacia afuera
            if ($resultado->esReturn || $resultado->esBreak || $resultado->esContinue) {
                break;
            }
        }

        $this->env = $envAnterior;
        return $resultado;
    }
    private function crearVisitorParaSentencia($sent): ?BaseVisitor
    {
        
        return new ExpressionVisitor(
            $this->envGlobal,
            $this->env,
            $this->errores,
            $this->ambitoActual
        );
    }

    public function obtenerEnv(): Environment
    {
        return $this->envGlobalLocal;
    }

    public function obtenerRegistroSimbolos(): array
    {
        return $this->registroSimbolosLocal;
    }
}