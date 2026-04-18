<?php

namespace App\Visitors;

use Context\{ExpressionVisitor as CtxExprVisitor, SentenciaExprContext,
             VarDeclContext, ConstDeclContext, DeclCortaContext,
             SentenciaIfContext, SentenciaForContext, ForClassicoContext,
             ForWhileContext, ForInfinitoContext, SentenciaSwitchContext,
             SentenciaBreakContext, SentenciaContinueContext,
             SentenciaReturnContext, ExprOrContext, ExprAndContext,
             ExprIgualdadContext, ExprRelacionalContext, ExprAditivaContext,
             ExprMultiplicativaContext, ExprNotContext, ExprNegacionContext,
             ExprAgrupadaContext, ExprFmtPrintlnContext, ExprNilContext,
             ExprIdContext, ExprLiteralContext, ExprArregloLiteralContext, ArregloLiteralContext, ExprReferenciaContext,
             ExprDerefContext, LiteralEnteroContext, LiteralFlotanteContext,
             LiteralBoolContext, LiteralRuneContext, LiteralStringContext,
             AsignacionContext, AsignacionCompuestaContext, IncDecContext,ExprInRangoContext, ExprNotInRangoContext,};

use App\Env\{Result, Symbol, TiposSistema, Environment};
use App\Expressions\BinaryOperator;
use App\Utils\ValueFormatter;
use App\BuiltIn\BuiltInRegistry;

class ExpressionVisitor extends BaseVisitor
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
    // SENTENCIAS
    // ==============================================================

    public function visitSentenciaExpr(SentenciaExprContext $ctx): Result
    {
        return $this->visit($ctx->expr());
    }

    // ==============================================================
    // DECLARACIONES
    // ==============================================================

    public function visitVarDecl(VarDeclContext $ctx): Result
    {
        $tipo = $ctx->tipo() !== null ? $ctx->tipo()->getText() : null;
        $ids   = $ctx->listaIds()->ID();
        $exprs = $ctx->listaExpr() !== null ? $ctx->listaExpr()->expr() : [];

        $valores = [];
        if (!empty($exprs)) {
            if (count($exprs) === 1) {
                $r = $this->visit($exprs[0]);
                if ($r->tipo === '__multi__' && is_array($r->valor)) {
                    $valores = $r->valor; // desempaquetar múltiples retornos
                } else {
                    $valores = [$r];
                }
            } else {
                foreach ($exprs as $e) {
                    $valores[] = $this->visit($e);
                }
            }
        }

        foreach ($ids as $i => $nodoId) {
            $nombre = $nodoId->getText();

            if ($this->env->existeLocal($nombre)) {
                $this->errores->agregar(
                    'Semántico',
                    "Identificador '{$nombre}' ya ha sido declarado en este ámbito.",
                    $nodoId->getSymbol()->getLine(),
                    $nodoId->getSymbol()->getCharPositionInLine()
                );
                continue;
            }

            if (isset($valores[$i])) {
                $res       = $valores[$i];
                $tipoFinal = $tipo ?? $res->tipo;
                $valor     = ValueFormatter::castear($res, $tipoFinal);
            } else {
                $tipoFinal = $tipo ?? Result::NIL;
                $valor     = $this->crearValorDefecto($tipoFinal);
            }

            $sym = new Symbol($tipoFinal, $valor, Symbol::CLASE_VARIABLE, 0, 0);
            $this->env->declarar($nombre, $sym);

            $this->registrarSimbolo(
                $nombre, $tipoFinal, $valor, Symbol::CLASE_VARIABLE,
                $nodoId->getSymbol()->getLine(),
                $nodoId->getSymbol()->getCharPositionInLine()
            );
        }
        return Result::nulo();
    }

    public function visitConstDecl(ConstDeclContext $ctx): Result
    {
        $nombre = $ctx->ID()->getText();
        $tipo   = $ctx->tipo()->getText();

        if ($this->env->existeLocal($nombre)) {
            $this->errores->agregar(
                'Semántico',
                "Constante '{$nombre}' ya declarada en este ámbito.",
                $ctx->ID()->getSymbol()->getLine(),
                $ctx->ID()->getSymbol()->getCharPositionInLine()
            );
            return Result::nulo();
        }

        $res   = $this->visit($ctx->expr());
        $valor = ValueFormatter::castear($res, $tipo);

        $sym              = new Symbol($tipo, $valor, Symbol::CLASE_CONSTANTE, 0, 0);
        $sym->esConstante = true;
        $this->env->declarar($nombre, $sym);

        $this->registrarSimbolo(
            $nombre, $tipo, $valor, Symbol::CLASE_CONSTANTE,
            $ctx->ID()->getSymbol()->getLine(),
            $ctx->ID()->getSymbol()->getCharPositionInLine()
        );

        return Result::nulo();
    }

    public function visitDeclCorta(DeclCortaContext $ctx): Result
    {
        $ids   = $ctx->listaIds()->ID();
        $exprs = $ctx->listaExpr()->expr();

        // Desempaquetar múltiples retornos de función
        $valores = [];
        if (count($exprs) === 1) {
            $r = $this->visit($exprs[0]);
            if ($r->tipo === '__multi__' && is_array($r->valor)) {
                $valores = $r->valor;  // array de Results
            } else {
                $valores = [$r];
            }
        } else {
            foreach ($exprs as $e) {
                $valores[] = $this->visit($e);
            }
        }

        if (count($ids) !== count($valores)) {
            $this->errores->agregar(
                'Semántico',
                'La cantidad de identificadores y expresiones debe ser igual.'
            );
            return Result::nulo();
        }

        foreach ($ids as $i => $nodoId) {
            $nombre = $nodoId->getText();
            $res    = $valores[$i];

            if ($this->env->existeLocal($nombre)) {
                try {
                    $sym = $this->env->obtener($nombre);
                    if ($sym->esConstante) {
                        $this->errores->agregar(
                            'Semántico',
                            "No se puede modificar la constante '{$nombre}'.",
                            $nodoId->getSymbol()->getLine(),
                            $nodoId->getSymbol()->getCharPositionInLine()
                        );
                        continue;
                    }
                    $sym->valor = ValueFormatter::castear($res, $sym->tipo);
                    $this->registrarSimbolo($nombre, $sym->tipo, $sym->valor, $sym->clase);
                } catch (\RuntimeException $e) {
                    $this->errores->agregar('Semántico', $e->getMessage());
                }
            } else {
                $sym = new Symbol($res->tipo, $res->valor, Symbol::CLASE_VARIABLE, 0, 0);
                $this->env->declarar($nombre, $sym);

                $this->registrarSimbolo(
                    $nombre, $res->tipo, $res->valor, Symbol::CLASE_VARIABLE,
                    $nodoId->getSymbol()->getLine(),
                    $nodoId->getSymbol()->getCharPositionInLine()
                );
            }
        }

        return Result::nulo();
    }

    // ==============================================================
    // ASIGNACIONES
    // ==============================================================

    public function visitAsignacion(AsignacionContext $ctx): Result
    {
        $lvalues = $ctx->listaLvalue()->lvalue();
        $exprs   = $ctx->listaExpr()->expr();

        // Evaluar todas las expresiones primero (para múltiples retornos)
        $valores = $this->evaluarListaExprs($exprs);

        foreach ($lvalues as $i => $lv) {
            $esDeref = ($lv->getChildCount() > 0 && $lv->getChild(0)->getText() === '*');
            $nombre = $lv->ID()->getText();
            $indicesCtx = $lv->expr();
            $res        = $valores[$i] ?? Result::nulo();

            try {
                $sym = $this->env->obtener($nombre);
            } catch (\RuntimeException $e) {
                $this->errores->agregar('Semántico', "Variable '{$nombre}' no declarada.");
                continue;
            }

            if ($sym->esConstante) {
                $this->errores->agregar('Semántico', "No se puede asignar a la constante '{$nombre}'.");
                continue;
            }

            if (empty($indicesCtx)) {
                if (!$esDeref && $res->tipo !== Result::NIL && !$this->sonTiposCompatiblesDecl($sym->tipo, $res->tipo)) {
                    $this->errores->agregar('Semántico', "Incompatibilidad de tipos: '{$res->tipo}' → '{$sym->tipo}'.");
                    continue;
                }
                $sym->valor = ValueFormatter::castear($res, $sym->tipo);
            } else {
                $parsed    = $this->parseArrayType($sym->tipo);
                $innerTipo = $parsed['base'];
                $arr       = $sym->valor;

                if (count($indicesCtx) === 1) {
                    $i0 = (int)$this->visit($indicesCtx[0])->valor;
                    $arr[$i0] = ValueFormatter::castear($res, $innerTipo);
                } elseif (count($indicesCtx) === 2) {
                    $i0 = (int)$this->visit($indicesCtx[0])->valor;
                    $i1 = (int)$this->visit($indicesCtx[1])->valor;
                    $arr[$i0][$i1] = ValueFormatter::castear($res, $innerTipo);
                } elseif (count($indicesCtx) === 3) {
                    $i0 = (int)$this->visit($indicesCtx[0])->valor;
                    $i1 = (int)$this->visit($indicesCtx[1])->valor;
                    $i2 = (int)$this->visit($indicesCtx[2])->valor;
                    $arr[$i0][$i1][$i2] = ValueFormatter::castear($res, $innerTipo);
                }

                $sym->valor = $arr;
            }
        }
        return Result::nulo();
    }

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

        $derecha = $this->visit($ctx->expr());
        $izq     = new Result($sym->tipo, $sym->valor);
        $op      = rtrim($ctx->op->getText(), '=');

        $nuevo = $this->binarioOp->aplicar($op, $izq, $derecha);
        if ($nuevo->tipo !== Result::NIL) {
            $sym->valor = $nuevo->valor;
        }

        return Result::nulo();
    }

    public function visitIncDec(IncDecContext $ctx): Result
    {
        $nombre     = $ctx->lvalue()->ID()->getText();
        $indicesCtx = $ctx->lvalue()->expr();

        try {
            $sym = $this->env->obtener($nombre);

            if (empty($indicesCtx)) {
                if ($ctx->op->getText() === '++') {
                    $sym->valor++;
                } else {
                    $sym->valor--;
                }
            } else {
                // incDec sobre elemento de arreglo: arr[i]++
                $current = &$sym->valor;
                for ($j = 0; $j < count($indicesCtx) - 1; $j++) {
                    $idx     = (int)$this->visit($indicesCtx[$j])->valor;
                    $current = &$current[$idx];
                }
                $lastIdx = (int)$this->visit($indicesCtx[count($indicesCtx) - 1])->valor;
                if ($ctx->op->getText() === '++') {
                    $current[$lastIdx]++;
                } else {
                    $current[$lastIdx]--;
                }
            }
        } catch (\RuntimeException $e) {
            $this->errores->agregar('Semántico', $e->getMessage());
        }

        return Result::nulo();
    }

    // ==============================================================
    // CONTROL DE FLUJO
    // ==============================================================

    public function visitSentenciaIf($ctx): Result
    {
        $cond = $this->visit($ctx->expr());

        if ($cond->tipo !== Result::BOOL) {
            $this->errores->agregar(
                'Semántico',
                "La condición del 'if' debe ser bool, se obtuvo '{$cond->tipo}'.",
                $ctx->expr()->getStart()->getLine(),
                $ctx->expr()->getStart()->getCharPositionInLine()
            );
            return Result::nulo();
        }

        if ($cond->valor) {
            return $this->visitBloque($ctx->bloque(0));
        } else {
            // else if
            if ($ctx->sentenciaIf() !== null) {
                return $this->visit($ctx->sentenciaIf());
            }
            // else bloque
            $bloques = $ctx->bloque();
            if (count($bloques) > 1) {
                return $this->visitBloque($bloques[1]);
            }
        }

        return Result::nulo();
    }

    public function visitForClassico($ctx): Result
    {
        $envAnterior = $this->env;
        $this->env   = new Environment($envAnterior);

        if ($ctx->declCorta() !== null) {
            $this->visit($ctx->declCorta());
        }

        $resultado = Result::nulo();

        while (true) {
            $cond = $this->visit($ctx->expr());

            if ($cond->tipo !== Result::BOOL) {
                $this->errores->agregar('Semántico', "Condición del for debe ser bool.");
                break;
            }

            if (!$cond->valor) {
                break;
            }

            $bloqueEnv = new Environment($this->env);
                $envTemp   = $this->env;
                $this->env = $bloqueEnv;

                $resultado = Result::nulo();
                foreach ($ctx->bloque()->sentencia() as $sent) {
                    $sv = new ExpressionVisitor(
                        $this->envGlobal,
                        $this->env,
                        $this->errores,
                        $this->ambitoActual
                    );
                    $resultado = $sv->visit($sent);
                    $this->consola .= $sv->obtenerConsola();
                    if ($resultado !== null && ($resultado->esReturn || $resultado->esBreak || $resultado->esContinue)) {
                        break;
                    }
                }

                $this->env = $envTemp;
            if ($resultado !== null) {
                if ($resultado->esBreak) {
                    $resultado = Result::nulo();
                    break;
                }
                if ($resultado->esContinue) {
                    $resultado = Result::nulo();
                }
                if ($resultado->esReturn) {
                    break;
                }
            }

            if ($ctx->incDec() !== null) {
                $stepVisitor = new ExpressionVisitor(
                    $this->envGlobal,
                    $this->env,
                    $this->errores,
                    $this->ambitoActual
                );
                $stepVisitor->visit($ctx->incDec());
                $this->consola .= $stepVisitor->obtenerConsola();
            } elseif ($ctx->asignacionCompuesta() !== null) {
                $stepVisitor = new ExpressionVisitor(
                    $this->envGlobal,
                    $this->env,
                    $this->errores,
                    $this->ambitoActual
                );
                $stepVisitor->visit($ctx->asignacionCompuesta());
                $this->consola .= $stepVisitor->obtenerConsola();
            }
        }

        $this->env = $envAnterior;
        return $resultado;
    }

    public function visitForWhile($ctx): Result
    {
        $resultado = Result::nulo();

        while (true) {
            $cond = $this->visit($ctx->expr());

            if ($cond->tipo !== Result::BOOL) {
                $this->errores->agregar('Semántico', "Condición del for debe ser bool.");
                break;
            }

            if (!$cond->valor) {
                break;
            }

            $resultado = $this->visitBloque($ctx->bloque());

            if ($resultado !== null) {
                if ($resultado->esBreak) {
                    $resultado = Result::nulo();
                    break;
                }
                if ($resultado->esContinue) {
                    $resultado = Result::nulo();
                }
                if ($resultado->esReturn) {
                    break;
                }
            }
        }

        return $resultado;
    }

    public function visitForInfinito($ctx): Result
    {
        $resultado = Result::nulo();

        while (true) {
            $resultado = $this->visitBloque($ctx->bloque());

            if ($resultado !== null) {
                if ($resultado->esBreak) {
                    $resultado = Result::nulo();
                    break;
                }
                if ($resultado->esContinue) {
                    $resultado = Result::nulo();
                }
                if ($resultado->esReturn) {
                    break;
                }
            }
        }

        return $resultado;
    }

    public function visitSentenciaSwitch($ctx): Result
    {
        $exprSwitch = $this->visit($ctx->expr());
        $casos      = $ctx->casoSwitch();
        $default    = $ctx->defaultSwitch();

        $encontrado    = false;
        $casoEjecutar  = null;

        foreach ($casos as $caso) {
            $listaExprCaso = $caso->listaExpr();
            if ($listaExprCaso !== null) {
                foreach ($listaExprCaso->expr() as $exprCaso) {
                    $valCaso = $this->visit($exprCaso);
                    if ($exprSwitch->valor == $valCaso->valor &&
                        $exprSwitch->tipo  === $valCaso->tipo) {
                        $encontrado   = true;
                        $casoEjecutar = $caso;
                        break 2;
                    }
                }
            }
        }

        if ($encontrado && $casoEjecutar !== null) {
            foreach ($casoEjecutar->sentencia() as $sent) {
                $resultado = $this->visit($sent);
                if ($resultado !== null && ($resultado->esBreak || $resultado->esReturn)) {
                    return $resultado->esReturn ? $resultado : Result::nulo();
                }
            }
        } elseif ($default !== null) {
            foreach ($default->sentencia() as $sent) {
                $resultado = $this->visit($sent);
                if ($resultado !== null && ($resultado->esBreak || $resultado->esReturn)) {
                    return $resultado->esReturn ? $resultado : Result::nulo();
                }
            }
        }

        return Result::nulo();
    }

    // ==============================================================
    // TRANSFERENCIA
    // ==============================================================

    public function visitSentenciaReturn(SentenciaReturnContext $ctx): Result
    {
        if ($ctx->listaExpr() !== null) {
            $exprs  = $ctx->listaExpr()->expr();
            $valores = $this->evaluarListaExprs($exprs);

            if (count($valores) === 1) {
                $res           = $valores[0];
                $res->esReturn = true;
                return $res;
            }

            // Múltiples retornos: empaquetamos como Result con valor = array de Results
            $res           = new Result('__multi__', $valores);
            $res->esReturn = true;
            return $res;
        }

        $res           = Result::nulo();
        $res->esReturn = true;
        return $res;
    }

    public function visitSentenciaBreak($ctx): Result
    {
        $res          = Result::nulo();
        $res->esBreak = true;
        return $res;
    }

    public function visitSentenciaContinue($ctx): Result
    {
        $res             = Result::nulo();
        $res->esContinue = true;
        return $res;
    }

    // ==============================================================
    // BLOQUE
    // ==============================================================

    public function visitBloque($ctx): Result
    {
        $envAnterior = $this->env;
        $this->env   = new Environment($envAnterior);

        $resultado = Result::nulo();

        foreach ($ctx->sentencia() as $sent) {
            $visitor = new ExpressionVisitor(
                $this->envGlobal,
                $this->env,
                $this->errores,
                $this->ambitoActual
            );

            $resultado = $visitor->visit($sent);

            $this->consola .= $visitor->obtenerConsola();

            foreach ($visitor->obtenerRegistroSimbolos() as $sym) {
                $this->registroSimbolos[] = $sym;
            }

            if ($resultado !== null && ($resultado->esReturn || $resultado->esBreak || $resultado->esContinue)) {
                break;
            }
        }

        $this->env = $envAnterior;
        return $resultado;
    }

    // ==============================================================
    // EXPRESIONES – LITERALES
    // ==============================================================

    public function visitLiteralEntero(LiteralEnteroContext $ctx): Result
    {
        return new Result(Result::INT32, intval($ctx->INT_LIT()->getText()));
    }

    public function visitLiteralFlotante(LiteralFlotanteContext $ctx): Result
    {
        return new Result(Result::FLOAT32, floatval($ctx->FLOAT_LIT()->getText()));
    }

    public function visitLiteralBool(LiteralBoolContext $ctx): Result
    {
        return new Result(Result::BOOL, $ctx->BOOL_LIT()->getText() === 'true');
    }

    public function visitLiteralRune(LiteralRuneContext $ctx): Result
    {
        $texto = $ctx->RUNE_LIT()->getText();
        $char  = stripslashes(substr($texto, 1, -1));
        return new Result(Result::RUNE, mb_ord($char));
    }

    public function visitLiteralString(LiteralStringContext $ctx): Result
    {
        $texto = $ctx->STR_LIT()->getText();
        $str   = stripcslashes(substr($texto, 1, -1));
        return new Result(Result::STRING, $str);
    }

    public function visitExprLiteral(ExprLiteralContext $ctx): Result
    {
        return $this->visit($ctx->literal());
    }

    public function visitExprNil(ExprNilContext $ctx): Result
    {
        return Result::nulo();
    }

    // ==============================================================
    // EXPRESIONES – VARIABLES
    // ==============================================================

    public function visitExprId(ExprIdContext $ctx): Result
    {
        $nombre = $ctx->ID()->getText();
        try {
            $sym = $this->env->obtener($nombre);
            return Symbol::aResult($sym);
        } catch (\RuntimeException $e) {
            $this->errores->agregar(
                'Semántico',
                $e->getMessage(),
                $ctx->ID()->getSymbol()->getLine(),
                $ctx->ID()->getSymbol()->getCharPositionInLine()
            );
            return Result::nulo();
        }
    }

    public function visitExprAgrupada(ExprAgrupadaContext $ctx): Result
    {
        return $this->visit($ctx->expr());
    }

    // ==============================================================
    // EXPRESIONES – PUNTEROS
    // ==============================================================

    /*public function visitExprReferencia(ExprReferenciaContext $ctx): Result
    {
        $nombre = $ctx->ID()->getText();
        // Verificar que existe
        try {
            $this->env->obtener($nombre);
        } catch (\RuntimeException $e) {
            $this->errores->agregar('Semántico', "Variable '{$nombre}' no declarada.");
            return Result::nulo();
        }
        return new Result('__ref__', $nombre);
    }*/

    /**
     * *varName  → desreferencia: lee el valor a través del nombre almacenado
     */
/*    public function visitExprDeref(ExprDerefContext $ctx): Result
{
    $nombre = $ctx->ID()->getText();
    try {
        $sym = $this->env->obtener($nombre);
        // Si el símbolo es un puntero (tipo empieza con *),
        // su valor es el Symbol original — devolverlo directamente
        return Symbol::aResult($sym);
    } catch (\RuntimeException $e) {
        $this->errores->agregar('Semántico', "Variable '{$nombre}' no declarada.");
        return Result::nulo();
    }
}
*/
    // ==============================================================
    // EXPRESIONES – OPERADORES
    // ==============================================================

    public function visitExprNegacion(ExprNegacionContext $ctx): Result
    {
        $val = $this->visit($ctx->expr());
        if ($val->tipo === Result::NIL) return Result::nulo();
        return new Result($val->tipo, -$val->valor);
    }

    public function visitExprNot(ExprNotContext $ctx): Result
    {
        $val = $this->visit($ctx->expr());
        if ($val->tipo !== Result::BOOL) {
            $this->errores->agregar('Semántico', "El operador '!' requiere bool.");
            return Result::nulo();
        }
        return new Result(Result::BOOL, !$val->valor);
    }

    public function visitExprMultiplicativa(ExprMultiplicativaContext $ctx): Result
    {
        $izq = $this->visit($ctx->expr(0));
        $der = $this->visit($ctx->expr(1));
        return $this->binarioOp->aplicar($ctx->op->getText(), $izq, $der);
    }

    public function visitExprAditiva(ExprAditivaContext $ctx): Result
    {
        $izq = $this->visit($ctx->expr(0));
        $der = $this->visit($ctx->expr(1));
        return $this->binarioOp->aplicar($ctx->op->getText(), $izq, $der);
    }

    public function visitExprRelacional(ExprRelacionalContext $ctx): Result
    {
        $izq = $this->visit($ctx->expr(0));
        $der = $this->visit($ctx->expr(1));
        return $this->binarioOp->aplicar($ctx->op->getText(), $izq, $der);
    }

    public function visitExprIgualdad(ExprIgualdadContext $ctx): Result
    {
        $izq = $this->visit($ctx->expr(0));
        $der = $this->visit($ctx->expr(1));

        if ($izq->tipo === Result::NIL && $der->tipo === Result::NIL) {
            return new Result(Result::BOOL, true);
        }

        return $this->binarioOp->aplicar($ctx->op->getText(), $izq, $der);
    }

    public function visitExprAnd(ExprAndContext $ctx): Result
    {
        $izq = $this->visit($ctx->expr(0));

        if ($izq->tipo !== Result::BOOL) {
            $this->errores->agregar('Semántico', "El operando izquierdo de '&&' debe ser bool.");
            return Result::nulo();
        }

        // Cortocircuito
        if (!$izq->valor) {
            return new Result(Result::BOOL, false);
        }

        $der = $this->visit($ctx->expr(1));

        if ($der->tipo !== Result::BOOL) {
            $this->errores->agregar('Semántico', "El operando derecho de '&&' debe ser bool.");
            return Result::nulo();
        }

        return new Result(Result::BOOL, $der->valor);
    }

    public function visitExprOr(ExprOrContext $ctx): Result
    {
        $izq = $this->visit($ctx->expr(0));

        if ($izq->tipo !== Result::BOOL) {
            $this->errores->agregar('Semántico', "El operando izquierdo de '||' debe ser bool.");
            return Result::nulo();
        }

        // Cortocircuito
        if ($izq->valor) {
            return new Result(Result::BOOL, true);
        }

        $der = $this->visit($ctx->expr(1));

        if ($der->tipo !== Result::BOOL) {
            $this->errores->agregar('Semántico', "El operando derecho de '||' debe ser bool.");
            return Result::nulo();
        }

        return new Result(Result::BOOL, $der->valor);
    }

    // ==============================================================
    // fmt.Println
    // ==============================================================

    public function visitExprFmtPrintln(ExprFmtPrintlnContext $ctx): Result
    {
        $partes = [];
        if ($ctx->listaExpr() !== null) {
            foreach ($ctx->listaExpr()->expr() as $e) {
                $res = $this->visit($e);
                // Desempaquetar múltiples retornos
                if ($res->tipo === '__multi__' && is_array($res->valor)) {
                    foreach ($res->valor as $r) {
                        $partes[] = $this->resultToString($r);
                    }
                } else {
                    $partes[] = $this->resultToString($res);
                }
            }
        }
        $this->agregarConsola(implode(' ', $partes) . "\n");
        return Result::nulo();
    }

    // ==============================================================
    // LLAMADAS A FUNCIONES
    // ==============================================================

public function visitExprLlamada($ctx): Result
{
    $nombreFuncion = $ctx->ID()->getText();

    // ── Built-ins ──────────────────────────────────────────────────
    $builtins = [
        'len'    => fn($args) => $this->builtinLen($args),
        'now' => function($args) {
                date_default_timezone_set('America/Guatemala');
                return new Result(Result::STRING, date('Y-m-d H:i:s'));
            },
        'substr' => fn($args) => $this->builtinSubstr($args),
        'typeOf' => fn($args) => new Result(Result::STRING, $args[0]->tipo ?? Result::NIL),
    ];

    if (isset($builtins[$nombreFuncion])) {
        $args = [];
        if ($ctx->listaExpr() !== null) {
            foreach ($ctx->listaExpr()->expr() as $expr) {
                $args[] = $this->visit($expr);
            }
        }
        return ($builtins[$nombreFuncion])($args);
    }

    // ── Funciones de usuario ────────────────────────────────────────
    // Evaluar args detectando referencias (&var)
    $args = [];
    if ($ctx->listaExpr() !== null) {
        foreach ($ctx->listaExpr()->expr() as $expr) {
            $args[] = $this->visit($expr);
        }
    }
    foreach ($args as $idx => $arg) {
    if ($arg->tipo === Result::NIL) {
        $this->errores->agregar('Semántico', 
            "Arg {$idx} de '{$nombreFuncion}' es nil — posible problema de scope.");
    }
}
    try {
        $fnSymbol = $this->envGlobal->obtener($nombreFuncion);
    } catch (\RuntimeException $e) {
        $this->errores->agregar(
            'Semántico',
            "Función '{$nombreFuncion}' no declarada.",
            $ctx->ID()->getSymbol()->getLine(),
            $ctx->ID()->getSymbol()->getCharPositionInLine()
        );
        return Result::nulo();
    }

    if ($fnSymbol->clase !== Symbol::CLASE_FUNCION) {
        $this->errores->agregar(
            'Semántico',
            "'{$nombreFuncion}' no es una función.",
            $ctx->ID()->getSymbol()->getLine(),
            $ctx->ID()->getSymbol()->getCharPositionInLine()
        );
        return Result::nulo();
    }

    return $this->ejecutarFuncion($fnSymbol, $args);
}
    // ==============================================================
    // FUNCIONES AUXILIARES
    // ==============================================================
private function builtinLen(array $args): Result
{
    if (count($args) !== 1) {
        $this->errores->agregar('Semántico', 'len() requiere exactamente 1 argumento.');
        return Result::nulo();
    }
    $arg = $args[0];
    if ($arg->tipo === Result::STRING) {
        return new Result(Result::INT32, mb_strlen((string)$arg->valor));
    }
    if (is_array($arg->valor)) {
        return new Result(Result::INT32, count($arg->valor));
    }
    $this->errores->agregar('Semántico', "len() requiere string o arreglo.");
    return Result::nulo();
}



public function ejecutarFuncion(Symbol $fn, array $args): Result
{
    $nuevoEnv    = new Environment($this->envGlobal);
    $referencias = []; // paramId → Symbol original del caller

    if ($fn->params !== null) {
        foreach ($fn->params as $i => $param) {
            $arg       = $args[$i] ?? Result::nulo();
            $tipoParam = $param['tipo'];

            if (str_starts_with($tipoParam, '*')) {
                // Parámetro puntero: &varNombre llega con tipo '__ref__'
                if ($arg->tipo === '__ref__' && is_string($arg->valor)) {
                    try {
                        $refSym = $this->env->obtener($arg->valor);
                        // Alias directo al mismo objeto Symbol
                        $nuevoEnv->declarar($param['id'], $refSym);
                        $referencias[$param['id']] = $refSym;
                    } catch (\RuntimeException $e) {
                        $sym = new Symbol(ltrim($tipoParam, '*'), null, Symbol::CLASE_VARIABLE, 0, 0);
                        $nuevoEnv->declarar($param['id'], $sym);
                    }
                } else {
                    // Pasaron el valor directamente sin &, crear copia
                    $sym = new Symbol(ltrim($tipoParam, '*'), $arg->valor, Symbol::CLASE_VARIABLE, 0, 0);
                    $nuevoEnv->declarar($param['id'], $sym);
                }
            } else {
                $sym = new Symbol($tipoParam, $arg->valor, Symbol::CLASE_VARIABLE, 0, 0);
                $nuevoEnv->declarar($param['id'], $sym);
            }
        }
    }

    $envAnterior    = $this->env;
    $ambitoAnterior = $this->ambitoActual;

    $this->env          = $nuevoEnv;
    $this->ambitoActual = $fn->nombre ?? 'funcion';

    $resultado = $this->visitBloque($fn->valor);

    $this->env          = $envAnterior;
    $this->ambitoActual = $ambitoAnterior;

    if ($resultado === null || !$resultado->esReturn) {
        return Result::nulo();
    }

    return $resultado;
}

public function visitExprReferencia(ExprReferenciaContext $ctx): Result
{
    $nombre = $ctx->ID()->getText();
    // Verificar que existe
    try { $this->env->obtener($nombre); } catch (\RuntimeException $e) {
        $this->errores->agregar('Semántico', "Variable '{$nombre}' no declarada.");
        return Result::nulo();
    }
    // Tipo especial '__ref__' con valor = nombre de la variable
    return new Result('__ref__', $nombre);
}

public function visitExprDeref(ExprDerefContext $ctx): Result
{
    $nombre = $ctx->ID()->getText();
    try {
        $sym = $this->env->obtener($nombre);
        // Si el símbolo es un puntero (tipo empieza con *),
        // su valor es el Symbol original — devolverlo directamente
        return Symbol::aResult($sym);
    } catch (\RuntimeException $e) {
        $this->errores->agregar('Semántico', "Variable '{$nombre}' no declarada.");
        return Result::nulo();
    }
}

private function builtinSubstr(array $args): Result
{
    if (count($args) !== 3) {
        $this->errores->agregar('Semántico', 'substr() requiere exactamente 3 argumentos.');
        return Result::nulo();
    }
    return new Result(Result::STRING, mb_substr((string)$args[0]->valor, (int)$args[1]->valor, (int)$args[2]->valor));
}
    // ==============================================================
    // ARREGLOS
    // ==============================================================

    public function visitExprArregloLiteral(ExprArregloLiteralContext $ctx): Result
    {
        $alCtx         = $ctx->arregloLiteral();
        $sizeStr       = $alCtx->INT_LIT()->getText();
        $innerTipoText = $alCtx->tipo()->getText();
        $fullTipo      = '[' . $sizeStr . ']' . $innerTipoText;

        $literalValueCtx = $alCtx->literalValue();
        $valor           = $this->construirArregloDesdeLiteral($literalValueCtx, $fullTipo);

        return new Result($fullTipo, $valor);
    }

    public function visitExprIndiceArreglo($ctx): Result
    {
        $nombre = $ctx->ID()->getText();
        try {
            $sym = $this->env->obtener($nombre);
        } catch (\RuntimeException $e) {
            $this->errores->agregar('Semántico', $e->getMessage());
            return Result::nulo();
        }
        if ($sym->tipo === Result::STRING) {
            $indicesCtx = $ctx->expr();
            $idx = (int)$this->visit($indicesCtx[0])->valor;
            $str = (string)$sym->valor;
            if ($idx < 0 || $idx >= mb_strlen($str)) {
                $this->errores->agregar('Semántico', "Índice fuera de rango en string.");
                return Result::nulo();
            }
            // Devuelve el valor numérico del byte (compatible con rune)
            return new Result(Result::RUNE, mb_ord(mb_substr($str, $idx, 1)));
        }
        $currentValor = $sym->valor;
        $currentTipo  = $sym->tipo;

        foreach ($ctx->expr() as $indexCtx) {
            $idxRes = $this->visit($indexCtx);
            $i      = (int)$idxRes->valor;

            if (!is_array($currentValor) || !array_key_exists($i, $currentValor)) {
                $this->errores->agregar(
                    'Semántico',
                    "Índice fuera de rango (índice={$i}).",
                    $indexCtx->getStart()->getLine(),
                    $indexCtx->getStart()->getCharPositionInLine()
                );
                return Result::nulo();
            }

            $currentValor = $currentValor[$i];
            $parsed       = $this->parseArrayType($currentTipo);
            if (count($parsed['dims']) > 1) {
                $currentTipo = implode('', array_map(fn($d) => "[$d]", array_slice($parsed['dims'], 1))) . $parsed['base'];
            } else {
                $currentTipo = $parsed['base'];
            }
        }

        return new Result($currentTipo, $currentValor);
    }

    // ==============================================================
    // HELPERS PRIVADOS
    // ==============================================================

    /**
     * Evalúa una lista de expresiones.
     * Si hay UNA expresión y devuelve __multi__ (función con múltiples retornos),
     * desempaqueta los valores.
     */
    private function evaluarListaExprs(array $exprs): array
    {
        if (count($exprs) === 1) {
            $res = $this->visit($exprs[0]);
            if ($res->tipo === '__multi__' && is_array($res->valor)) {
                return $res->valor; // ya es array de Results
            }
            return [$res];
        }

        $valores = [];
        foreach ($exprs as $e) {
            $valores[] = $this->visit($e);
        }
        return $valores;
    }

    /**
     * Ejecuta una función de usuario, manejando punteros (paso por referencia).
     */
    private function ejecutarFuncionConReferencias(Symbol $fn, array $args, array $argsCtx): Result
    {
        $nuevoEnv = new Environment($this->envGlobal);

        // Mapeo de parámetros de referencia: paramNombre → nombreVarOriginal
        $referencias = [];

        if ($fn->params !== null) {
            foreach ($fn->params as $i => $param) {
                $arg      = $args[$i] ?? Result::nulo();
                $tipoParam = $param['tipo'];

                if (str_starts_with($tipoParam, '*')) {
                    // Parámetro es puntero — pasar referencia
                    if ($arg->tipo === '__ref__' && is_string($arg->valor)) {
                        $refNombre = $arg->valor;
                        // Obtener símbolo original
                        try {
                            $refSym = $this->env->obtener($refNombre);
                        } catch (\RuntimeException $e) {
                            $refSym = null;
                        }

                        if ($refSym !== null) {
                            // Crear símbolo alias en nuevo env apuntando al mismo objeto
                            $nuevoEnv->declarar($param['id'], $refSym);
                            $referencias[$param['id']] = $refNombre;
                        } else {
                            $sym = new Symbol($tipoParam, null, Symbol::CLASE_VARIABLE, 0, 0);
                            $nuevoEnv->declarar($param['id'], $sym);
                        }
                    } else {
                        // Pasar valor del arreglo/variable directamente (copia)
                        $tipoBase = ltrim($tipoParam, '*');
                        $sym = new Symbol($tipoBase, $arg->valor, Symbol::CLASE_VARIABLE, 0, 0);
                        $nuevoEnv->declarar($param['id'], $sym);
                    }
                } else {
                    // Parámetro por valor normal
                    $sym = new Symbol($tipoParam, ValueFormatter::castear($arg, $tipoParam), Symbol::CLASE_VARIABLE, 0, 0);
                    $nuevoEnv->declarar($param['id'], $sym);
                }
            }
        }

        $envAnterior    = $this->env;
        $ambitoAnterior = $this->ambitoActual;

        $this->env          = $nuevoEnv;
        $this->ambitoActual = $fn->nombre ?? 'funcion';

        $resultado = $this->visitBloque($fn->valor);

        // Actualizar variables originales que fueron pasadas por referencia
        foreach ($referencias as $paramId => $refNombre) {
            try {
                $paramSym = $nuevoEnv->obtener($paramId);
                $origSym  = $envAnterior->obtener($refNombre);
                $origSym->valor = $paramSym->valor;
            } catch (\RuntimeException $e) {
                // silenciar
            }
        }

        $this->env          = $envAnterior;
        $this->ambitoActual = $ambitoAnterior;

        if ($resultado === null || !$resultado->esReturn) {
            return Result::nulo();
        }

        return $resultado;
    }

    /**
     * Convierte un Result a string para fmt.Println,
     * incluyendo arreglos.
     */
    private function resultToString(Result $res): string
    {
        // Si el valor es un array de Results (múltiples retornos desempaquetados aquí por si acaso)
        if ($res->tipo === '__multi__' && is_array($res->valor)) {
            $partes = [];
            foreach ($res->valor as $r) {
                $partes[] = $this->resultToString($r);
            }
            return implode(' ', $partes);
        }

        if (is_array($res->valor)) {
            return $this->arrayToString($res->valor);
        }

        return ValueFormatter::toString($res);
    }

    private function arrayToString(array $arr): string
    {
        $elementos = array_map(function ($v) {
            if ($v instanceof \App\Env\Result) return $this->resultToString($v);
            if (is_array($v)) return $this->arrayToString($v);
            if (is_bool($v)) return $v ? 'true' : 'false';
            return (string)$v;
        }, $arr);
        return implode(' ', $elementos);
    }

    /**
     * Para len(): evalúa la expresión y devuelve un Result con el arreglo completo
     * (no el elemento en índice), para que len() pueda contar.
     */
    private function visitParaLen($exprCtx): Result
    {
        // Si es un ID simple, devolver el símbolo directamente
        if ($exprCtx instanceof \Context\ExprIdContext) {
            $nombre = $exprCtx->ID()->getText();
            try {
                $sym = $this->env->obtener($nombre);
                return Symbol::aResult($sym);
            } catch (\RuntimeException $e) {
                // continuar con visit normal
            }
        }
        return $this->visit($exprCtx);
    }

    private function sonTiposCompatibles(string $tipoDest, string $tipoOrigen): bool
    {
        if ($tipoDest === $tipoOrigen) return true;
        if ($tipoOrigen === Result::NIL) return true;
        return false;
    }

    private function sonTiposCompatiblesDecl(string $tipoDest, string $tipoOrigen): bool
    {
        if ($tipoDest === $tipoOrigen) return true;
        if ($tipoOrigen === Result::NIL) return true;

        // int32 y rune son compatibles
        $aliasGrupo = [Result::INT32, Result::RUNE];
        if (in_array($tipoDest, $aliasGrupo) && in_array($tipoOrigen, $aliasGrupo)) return true;

        // int y int32 son compatibles (el lenguaje usa 'int' como alias de 'int32' en ejemplos)
        if (($tipoDest === 'int' && $tipoOrigen === Result::INT32) ||
            ($tipoDest === Result::INT32 && $tipoOrigen === 'int')) return true;

        // Tipos de arreglo: [N]tipo
        if (str_starts_with($tipoDest, '[') && str_starts_with($tipoOrigen, '[')) {
            return $tipoDest === $tipoOrigen;
        }

        return false;
    }

    public function visitExprCastRune($ctx): Result
    {
        $val = $this->visit($ctx->expr());
        return new Result(Result::RUNE, (int)$val->valor);
    }

    public function visitExprCastInt32($ctx): Result
    {
        $val = $this->visit($ctx->expr());
        return new Result(Result::INT32, (int)$val->valor);
    }

    public function visitExprCastFloat32($ctx): Result
    {
        $val = $this->visit($ctx->expr());
        return new Result(Result::FLOAT32, (float)$val->valor);
    }

    public function visitExprCastString($ctx): Result
    {
        $val = $this->visit($ctx->expr());
        return new Result(Result::STRING, (string)$val->valor);
    }

    // CALIFICACION
    public function visitExprInRango($ctx): Result {
        $val = $this->visit($ctx->expr(0));
        $desde = $this->visit($ctx->expr(1));
        $hasta = $this->visit($ctx->expr(2));

        $resultado = $val->valor >= $desde->valor && $val->valor <= $hasta->valor;
        return new Result(Result::BOOL, $resultado);
    }

    public function visitExprNotInRango($ctx): Result {
        $val = $this->visit($ctx->expr(0));
        $desde = $this->visit($ctx->expr(1));
        $hasta = $this->visit($ctx->expr(2));
        $resultado = ((float)$val->valor < (float)$desde->valor) || ((float)$val->valor > (float)$hasta->valor);
        return new Result(Result::BOOL, $resultado);
    }
// Grabacion: https://drive.google.com/file/d/1ThKW75G84vDjtlQxLLtAiaeJGFzIoXKL/view?usp=sharing

}