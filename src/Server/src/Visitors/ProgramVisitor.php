<?php

namespace App\Visitors;

use Context\ProgramaContext;
use Context\FuncDeclContext;
use Context\BloqueContext;
// Importamos los contextos específicos para el despacho de visitantes
use Context\VarDeclContext;
use Context\ConstDeclContext;
use Context\DeclCortaContext;
use Context\SentenciaContext;

use App\Env\{Environment, Symbol, Result, ManejadorErrores};

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
        // 1. Registro de funciones (Primera pasada)
        foreach ($ctx->topDecl() as $top) {
            if ($top->funcDecl() !== null) {
                $this->registrarFuncion($top->funcDecl());
            }
        }

        // 2. Procesar declaraciones globales (Variables y Constantes)
        $declVisitor = new DeclarationVisitor(
            $this->envGlobal,
            $this->env,
            $this->errores,
            'global'
        );

        foreach ($ctx->topDecl() as $top) {
            if ($top->varDecl() !== null) {
                $declVisitor->visit($top->varDecl());
            } elseif ($top->constDecl() !== null) {
                $declVisitor->visit($top->constDecl());
            }
        }

        // Recuperar consola y símbolos de las declaraciones globales
        $this->registroSimbolosLocal = $declVisitor->obtenerRegistroSimbolos();
        $this->consola .= $declVisitor->obtenerConsola();

        // 3. Ejecutar el punto de entrada (main)
        try {
            // Buscamos 'main' en el entorno global
            $main = $this->envGlobal->obtener('main');
        } catch (\RuntimeException $e) {
            $this->errores->agregar('Semántico', 'No se encontró la función main.');
            return $this->consola;
        }

        if ($main->clase !== Symbol::CLASE_FUNCION) {
            $this->errores->agregar('Semántico', "'main' no es una función válida.");
            return $this->consola;
        }

        // Ejecutamos el bloque de main
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
            : 'nil';

        // Guardamos el nodo del bloque como valor del símbolo para ejecutarlo luego
        $sym          = new Symbol($tipoRet, $ctx->bloque(), Symbol::CLASE_FUNCION, 0, 0);
        $sym->params  = $params;
        $sym->nombre  = $nombre;
        
        $this->envGlobal->declarar($nombre, $sym);
    }

    private function ejecutarFuncion(Symbol $fn, array $args): Result
    {
        // Nueva tabla de símbolos para el ámbito de la función
        $nuevoEnv = new Environment($this->envGlobal);

        // Mapeo de argumentos a parámetros
        if (isset($fn->params)) {
            foreach ($fn->params as $i => $param) {
                $arg = $args[$i] ?? Result::nulo();
                $sym = new Symbol($param['tipo'], $arg->valor, Symbol::CLASE_VARIABLE, 0, 0);
                $nuevoEnv->declarar($param['id'], $sym);
            }
        }

        // Guardar estado actual
        $envAnterior = $this->env;
        $ambitoAnterior = $this->ambitoActual;
        
        // Cambiar contexto al de la función
        $this->env = $nuevoEnv;
        $this->ambitoActual = $fn->nombre ?? 'funcion';

        // Visitar el bloque de la función (el nodo guardado en $fn->valor)
        $resultado = $this->visit($fn->valor);
        
        // Restaurar contexto
        $this->env = $envAnterior;
        $this->ambitoActual = $ambitoAnterior;
        
        return $resultado ?? Result::nulo();
    }

    public function visitBloque(BloqueContext $ctx): Result
    {
        // Ámbito local del bloque
        $envAnterior = $this->env;
        $this->env = new Environment($envAnterior);

        $resultado = Result::nulo();
        
        foreach ($ctx->sentencia() as $sent) {
            // Usamos un despacho dinámico de visitantes
            $visitor = $this->crearVisitorParaSentencia($sent);
            
            if ($visitor !== null) {
                $resultado = $visitor->visit($sent);
                
                // Sincronizar consola
                $this->consola .= $visitor->obtenerConsola();
                
                // Sincronizar tabla de símbolos para la UI
                foreach ($visitor->obtenerRegistroSimbolos() as $sym) {
                    $this->registroSimbolosLocal[] = $sym;
                }
            }

            // Manejo de control de flujo
            if ($resultado !== null && ($resultado->esReturn || $resultado->esBreak || $resultado->esContinue)) {
                break;
            }
        }

        $this->env = $envAnterior;
        return $resultado;
    }

    /**
     * Determina qué visitor debe manejar cada sentencia.
     */
    private function crearVisitorParaSentencia(SentenciaContext $ctx): ?BaseVisitor
    {
        // Si la sentencia contiene una declaración, usamos DeclarationVisitor
        if ($ctx->declaracion() !== null) {
            return new DeclarationVisitor($this->envGlobal, $this->env, $this->errores, $this->ambitoActual);
        }

        // Para el resto (if, for, return, asignaciones, println), usamos StatementVisitor o ExpressionVisitor
        // Dado que ExpressionVisitor en tu proyecto maneja casi todo:
        return new ExpressionVisitor($this->envGlobal, $this->env, $this->errores, $this->ambitoActual);
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