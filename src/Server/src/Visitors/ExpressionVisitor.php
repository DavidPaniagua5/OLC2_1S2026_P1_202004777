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
             ExprIdContext, ExprLiteralContext, ExprReferenciaContext,
             ExprDerefContext, LiteralEnteroContext, LiteralFlotanteContext,
             LiteralBoolContext, LiteralRuneContext, LiteralStringContext,
             AsignacionContext, AsignacionCompuestaContext, IncDecContext};

use App\Env\{Result, Symbol, TiposSistema, Environment};
use App\Expressions\BinaryOperator;
use App\Utils\ValueFormatter;

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

    public function visitSentenciaExpr(SentenciaExprContext $ctx): Result
    {
        $expr = $ctx->expr();
        
        if ($expr instanceof AsignacionContext) {
            return $this->visitAsignacion($expr);
        }
        
        if ($expr instanceof AsignacionCompuestaContext) {
            return $this->visitAsignacionCompuesta($expr);
        }
        
        return $this->visit($expr);
    }

    public function visitAsignacion(AsignacionContext $ctx): Result
    {
        $lvalues = $ctx->listaLvalue()->lvalue();
        $exprs   = $ctx->listaExpr()->expr();

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

            $res = $this->visit($exprs[$i]);

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

            $sym->valor = ValueFormatter::castear($res, $sym->tipo);
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

    public function visitVarDecl(VarDeclContext $ctx): Result
    {
        $tipo  = $ctx->tipo()->getText();
        $ids   = $ctx->listaIds()->ID();
        $exprs = $ctx->listaExpr() !== null ? $ctx->listaExpr()->expr() : [];

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

            if (isset($exprs[$i])) {
                $res   = $this->visit($exprs[$i]);
                
                if ($res->tipo !== Result::NIL && $res->tipo !== $tipo) {
                    $this->errores->agregar(
                        'Semántico',
                        "Incompatibilidad de tipos: no se puede asignar '{$res->tipo}' a '{$tipo}'.",
                        $exprs[$i]->getStart()->getLine(),
                        $exprs[$i]->getStart()->getCharPositionInLine()
                    );
                    continue;
                }
                
                $valor = ValueFormatter::castear($res, $tipo);
            } else {
                $valor = TiposSistema::valorDefecto($tipo);
            }

            $sym = new Symbol($tipo, $valor, Symbol::CLASE_VARIABLE, 0, 0);
            $this->env->declarar($nombre, $sym);

            $this->registrarSimbolo(
                $nombre, $tipo, $valor, Symbol::CLASE_VARIABLE,
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

        if (count($ids) !== count($exprs)) {
            $this->errores->agregar(
                'Semántico',
                'La cantidad de identificadores y expresiones debe ser igual.'
            );
            return Result::nulo();
        }

        foreach ($ids as $i => $nodoId) {
            $nombre = $nodoId->getText();
            $res    = $this->visit($exprs[$i]);

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

    public function visitExprReferencia(ExprReferenciaContext $ctx): Result
    {
        return new Result(Result::STRING, '&' . $ctx->ID()->getText());
    }

    public function visitExprDeref(ExprDerefContext $ctx): Result
    {
        $nombre = $ctx->ID()->getText();
        try {
            $sym = $this->env->obtener($nombre);
            return Symbol::aResult($sym);
        } catch (\RuntimeException $e) {
            return Result::nulo();
        }
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
            return Result::nulo();
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

    public function visitExprFmtPrintln(ExprFmtPrintlnContext $ctx): Result
    {
        $partes = [];
        if ($ctx->listaExpr() !== null) {
            foreach ($ctx->listaExpr()->expr() as $e) {
                $res      = $this->visit($e);
                $partes[] = ValueFormatter::toString($res);
            }
        }
        $this->agregarConsola(implode(' ', $partes) . "\n");
        return Result::nulo();
    }

    public function visitExprLlamada($ctx): Result
    {
        $nombreFuncion = $ctx->ID()->getText();
        $args = [];
        
        if ($ctx->listaExpr() !== null) {
            foreach ($ctx->listaExpr()->expr() as $expr) {
                $args[] = $this->visit($expr);
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
        
        return Result::nulo();
    }

    public function visitSentenciaSwitch($ctx): Result
    {
        $exprSwitch = $this->visit($ctx->expr());
        
        $casos = $ctx->casoSwitch();
        $default = $ctx->defaultSwitch();
        
        $encontrado = false;
        $casoEjecutar = null;
        
        foreach ($casos as $caso) {
            $listaExprCaso = $caso->listaExpr();
            
            if ($listaExprCaso !== null) {
                foreach ($listaExprCaso->expr() as $exprCaso) {
                    $valCaso = $this->visit($exprCaso);
                    
                    if ($exprSwitch->valor == $valCaso->valor && 
                        $exprSwitch->tipo === $valCaso->tipo) {
                        $encontrado = true;
                        $casoEjecutar = $caso;
                        break 2;
                    }
                }
            }
        }
        
        if ($encontrado && $casoEjecutar !== null) {
            foreach ($casoEjecutar->sentencia() as $sent) {
                $resultado = $this->visit($sent);
                
                if ($resultado !== null && $resultado->esBreak) {
                    break;
                }
            }
        } elseif ($default !== null) {
            foreach ($default->sentencia() as $sent) {
                $resultado = $this->visit($sent);
                
                if ($resultado !== null && $resultado->esBreak) {
                    break;
                }
            }
        }
        
        return Result::nulo();
    }

    public function visitExprIndiceArreglo($ctx): Result
    {
        return Result::nulo();
    }

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
            $bloque = $ctx->bloque(0);
            return $this->visitBloque($bloque);
        } else {
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
        $this->env = new Environment($envAnterior);
        
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
            
            if ($ctx->incDec() !== null) {
                $this->visit($ctx->incDec());
            } elseif ($ctx->asignacionCompuesta() !== null) {
                $this->visit($ctx->asignacionCompuesta());
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

    public function visitSentenciaReturn($ctx): Result
    {
        $res = Result::nulo();
        $res->esReturn = true;
        return $res;
    }

    public function visitSentenciaBreak($ctx): Result
    {
        $res = Result::nulo();
        $res->esBreak = true;
        return $res;
    }

    public function visitSentenciaContinue($ctx): Result
    {
        $res = Result::nulo();
        $res->esContinue = true;
        return $res;
    }

    public function visitBloque($ctx): Result
    {
        $envAnterior = $this->env;
        $this->env = new Environment($envAnterior);

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
            
            $simbolos = $visitor->obtenerRegistroSimbolos();
            foreach ($simbolos as $sym) {
                // Registrar símbolos si es necesario
            }

            if ($resultado !== null && ($resultado->esReturn || $resultado->esBreak || $resultado->esContinue)) {
                break;
            }
        }

        $this->env = $envAnterior;
        return $resultado;
    }

    private function sonTiposCompatibles(string $tipoDest, string $tipoOrigen): bool
    {
        if ($tipoDest === $tipoOrigen) {
            return true;
        }

        if ($tipoOrigen === Result::NIL) {
            return true;
        }

        return false;
    }
}