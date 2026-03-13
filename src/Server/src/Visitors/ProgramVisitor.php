<?php

namespace App\Visitors;

use Context\ProgramaContext;
use Context\FuncDeclContext;
use Context\BloqueContext;
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
        foreach ($ctx->declaracionTop() as $decl) {
            if ($decl->funcDecl() !== null) {
                $this->registrarFuncion($decl->funcDecl());
            }
        }

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

    
    public function visitBloque(BloqueContext $ctx): Result
    {
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

            if ($resultado !== null && ($resultado->esReturn || $resultado->esBreak || $resultado->esContinue)) {
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