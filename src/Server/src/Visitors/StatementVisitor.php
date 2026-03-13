<?php

namespace App\Visitors;

use Context\{AsignacionContext, AsignacionCompuestaContext, IncDecContext,
             SentenciaIfContext, ForClassicoContext, ForWhileContext,
             ForInfinitoContext, SentenciaSwitchContext, CasoSwitchContext,
             DefaultSwitchContext, SentenciaReturnContext, SentenciaBreakContext,
             SentenciaContinueContext};

use App\Env\{Result, Symbol, Environment};
use App\Expressions\BinaryOperator;
use App\Utils\ValueFormatter;

/**
 * Visitor para sentencias de control de flujo y asignaciones.
 */
class StatementVisitor extends BaseVisitor
{
    private BinaryOperator $binarioOp;

    public function __construct(
        \App\Env\Environment $envGlobal,
        \App\Env\Environment $env,
        \App\Env\ManejadorErrores $errores,
        string $ambitoActual = 'global'
    ) {
        parent::__construct($envGlobal, $env, $errores, $ambitoActual);
        $this->binarioOp = new BinaryOperator($errores);
    }

    // ==============================================================
    // ASIGNACIONES
    // ==============================================================

    /**
     * x = 5  /  x, y = 1, 2
     */
    public function visitAsignacion(AsignacionContext $ctx): Result
    {
        $lvalues = $ctx->listaLvalue()->lvalue();
        $exprs   = $ctx->listaExpr()->expr();

        $exprVisitor = new ExpressionVisitor(
            $this->envGlobal,
            $this->env,
            $this->errores,
            $this->ambitoActual
        );

        foreach ($lvalues as $i => $lv) {
            $nombre = $lv->ID()->getText();

            try {
                $sym = $this->env->obtener($nombre);
            } catch (\RuntimeException $e) {
                $this->errores->agregar(
                    'Semántico',
                    "Variable '{$nombre}' no declarada. No se puede asignar valor.",
                    $lv->ID()->getSymbol()->getLine(),
                    $lv->ID()->getSymbol()->getCharPositionInLine()
                );
                continue;
            }

            if ($sym->esConstante) {
                $this->errores->agregar(
                    'Semántico',
                    "No se puede asignar a la constante '{$nombre}'.",
                    $lv->ID()->getSymbol()->getLine(),
                    $lv->ID()->getSymbol()->getCharPositionInLine()
                );
                continue;
            }

            // Evaluar expresión
            $res = $exprVisitor->visit($exprs[$i]);

            $tipoCompatible = $this->sonTiposCompatibles($sym->tipo, $res->tipo);
            if (!$tipoCompatible) {
                $this->errores->agregar(
                    'Semántico',
                    "Incompatibilidad de tipos: no se puede asignar '{$res->tipo}' a '{$sym->tipo}'.",
                    $exprs[$i]->getStart()->getLine(),
                    $exprs[$i]->getStart()->getCharPositionInLine()
                );
                continue;
            }

            // Asignar valor
            $sym->valor = ValueFormatter::castear($res, $sym->tipo);
        }

        $this->consola .= $exprVisitor->obtenerConsola();
        return Result::nulo();
    }

    /**
     * x += expr  x -= expr  x *= expr  x /= expr
     */
    public function visitAsignacionCompuesta(AsignacionCompuestaContext $ctx): Result
    {
        $nombre = $ctx->lvalue()->ID()->getText();

        try {
            $sym = $this->env->obtener($nombre);
        } catch (\RuntimeException $e) {
            $this->errores->agregar(
                'Semántico',
                "Variable '{$nombre}' no declarada.",
                $ctx->lvalue()->ID()->getSymbol()->getLine(),
                $ctx->lvalue()->ID()->getSymbol()->getCharPositionInLine()
            );
            return Result::nulo();
        }

        // Crear ExpressionVisitor
        $exprVisitor = new ExpressionVisitor(
            $this->envGlobal,
            $this->env,
            $this->errores,
            $this->ambitoActual
        );

        $derecha = $exprVisitor->visit($ctx->expr());
        $izq     = new Result($sym->tipo, $sym->valor);
        $op      = rtrim($ctx->op->getText(), '=');

        $nuevo = $this->binarioOp->aplicar($op, $izq, $derecha);
        if ($nuevo->tipo !== Result::NIL) {
            $sym->valor = $nuevo->valor;
        }

        $this->consola .= $exprVisitor->obtenerConsola();
        return Result::nulo();
    }

    /**
     * x++  x--
     */
    public function visitIncDec(IncDecContext $ctx): Result
    {
        $nombre = $ctx->lvalue()->ID()->getText();
        try {
            $sym = $this->env->obtener($nombre);
            if ($ctx->op->getText() === '++') {
                $sym->valor++;
            } else {
                $sym->valor--;
            }
        } catch (\RuntimeException $e) {
            $this->errores->agregar('Semántico', $e->getMessage());
        }
        return Result::nulo();
    }

    // ==============================================================
    // IF / ELSE
    // ==============================================================

    public function visitSentenciaIf(SentenciaIfContext $ctx): Result
    {
        $exprVisitor = new ExpressionVisitor(
            $this->envGlobal,
            $this->env,
            $this->errores,
            $this->ambitoActual
        );

        $cond = $exprVisitor->visit($ctx->expr());

        if ($cond->tipo !== Result::BOOL) {
            $this->errores->agregar(
                'Semántico',
                "La condición del 'if' debe ser bool, se obtuvo '{$cond->tipo}'."
            );
            return Result::nulo();
        }

        $this->consola .= $exprVisitor->obtenerConsola();
        return Result::nulo();
    }

    // ==============================================================
    // FOR (3 variantes)
    // ==============================================================

    public function visitForClassico(ForClassicoContext $ctx): Result
    {
        // Crear nuevo ámbito para el for
        $envAnterior = $this->env;
        $this->env = new Environment($envAnterior);

        $exprVisitor = new ExpressionVisitor(
            $this->envGlobal,
            $this->env,
            $this->errores,
            $this->ambitoActual
        );

        // Ejecutar inicialización
        if ($ctx->declCorta() !== null) {
            $exprVisitor->visit($ctx->declCorta());
        }

        // Validar condición
        $cond = $exprVisitor->visit($ctx->expr());
        if ($cond->tipo !== Result::BOOL) {
            $this->errores->agregar('Semántico', "Condición del for debe ser bool.");
        }

        $this->consola .= $exprVisitor->obtenerConsola();
        $this->env = $envAnterior;
        return Result::nulo();
    }

    public function visitForWhile(ForWhileContext $ctx): Result
    {
        $exprVisitor = new ExpressionVisitor(
            $this->envGlobal,
            $this->env,
            $this->errores,
            $this->ambitoActual
        );

        $cond = $exprVisitor->visit($ctx->expr());
        if ($cond->tipo !== Result::BOOL) {
            $this->errores->agregar('Semántico', "Condición del for debe ser bool.");
        }

        $this->consola .= $exprVisitor->obtenerConsola();
        return Result::nulo();
    }

    public function visitForInfinito(ForInfinitoContext $ctx): Result
    {
        return Result::nulo();
    }

    // ==============================================================
    // SWITCH / CASE
    // ==============================================================

    public function visitSentenciaSwitch(SentenciaSwitchContext $ctx): Result
    {
        return Result::nulo();
    }

    // ==============================================================
    // RETURN / BREAK / CONTINUE
    // ==============================================================

        public function visitSentenciaReturn(SentenciaReturnContext $ctx): Result
    {
        if ($ctx->listaExpr() !== null && count($ctx->listaExpr()->expr()) > 0) {
            // Tomamos el primer (y único) valor retornado
            $res = $this->visit($ctx->listaExpr()->expr()[0]);
            $res->esReturn = true;
            return $res;               // ← AQUÍ LLEVAMOS EL ARRAY
        }

        // Caso sin return explícito
        $res = Result::nulo();
        $res->esReturn = true;
        return $res;
    }

    public function visitSentenciaBreak(SentenciaBreakContext $ctx): Result
    {
        $res = Result::nulo();
        $res->esBreak = true;
        return $res;
    }

    public function visitSentenciaContinue(SentenciaContinueContext $ctx): Result
    {
        $res = Result::nulo();
        $res->esContinue = true;
        return $res;
    }

    // ==============================================================
    // HELPER: Validación de Tipos
    // ==============================================================

    
    private function sonTiposCompatibles(string $tipoDest, string $tipoOrigen): bool
    {
        // Mismo tipo
        if ($tipoDest === $tipoOrigen) {
            return true;
        }

        // nil con cualquier tipo
        if ($tipoOrigen === Result::NIL) {
            return true;
        }

        // No compatible
        return false;
    }
}
