<?php

namespace App\Visitors;

// Importar todos los contextos ANTLR sin namespace Context
use SentenciaExprContext;
use VarDeclContext;
use ConstDeclContext;
use DeclCortaContext;
use SentenciaIfContext;
use SentenciaForContext;
use ForClassicoContext;
use ForWhileContext;
use ForInfinitoContext;
use SentenciaSwitchContext;
use SentenciaBreakContext;
use SentenciaContinueContext;
use SentenciaReturnContext;
use ExprOrContext;
use ExprAndContext;
use ExprIgualdadContext;
use ExprRelacionalContext;
use ExprAditivaContext;
use ExprMultiplicativaContext;
use ExprNotContext;
use ExprNegacionContext;
use ExprAgrupadaContext;
use ExprFmtPrintlnContext;
use ExprNilContext;
use ExprIdContext;
use ExprLiteralContext;
use ExprReferenciaContext;
use ExprDerefContext;
use LiteralEnteroContext;
use LiteralFlotanteContext;
use LiteralBoolContext;
use LiteralRuneContext;
use LiteralStringContext;
use AsignacionContext;
use AsignacionCompuestaContext;
use IncDecContext;
use ExprIndiceArregloContext;
use ExprLlamadaContext;
use BloqueContext;
use CasoSwitchContext;
use DefaultSwitchContext;

use App\Env\{Result, Symbol, TiposSistema, Environment};
use App\Expressions\BinaryOperator;
use App\Utils\ValueFormatter;

/**
 * Visitor para evaluar expresiones en el lenguaje Golampi.
 * Maneja:
 * - Operadores binarios y unarios
 * - Acceso a variables y arreglos (incluyendo multidimensionales)
 * - Llamadas a funciones
 * - Literales y construcciones de control
 */
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

    // ============================================================
    // MÉTODOS AUXILIARES PARA ARREGLOS MULTIDIMENSIONALES (NUEVOS)
    // ============================================================

    /**
     * Extrae el tipo de elemento de un tipo de arreglo.
     */
    private function extraerTipoElemento(string $tipoArreglo): string
    {
        if (preg_match('/^\[(\d+)\](.+)$/', $tipoArreglo, $matches)) {
            return $matches[2];
        }
        return $tipoArreglo;
    }

    /**
     * Extrae todas las dimensiones de un tipo de arreglo.
     */
    private function obtenerDimensiones(string $tipoArreglo): array
    {
        $dimensiones = [];

        while (preg_match('/^\[(\d+)\]/', $tipoArreglo, $matches)) {
            $dimensiones[] = (int)$matches[1];
            $tipoArreglo = substr($tipoArreglo, strlen($matches[0]));
        }

        return [
            'dimensiones' => $dimensiones,
            'tipoBase'    => $tipoArreglo
        ];
    }

    /**
     * Crea un arreglo multidimensional con valores por defecto.
     */
    private function crearArregloMultidimensional(
        array $dimensiones,
        string $tipoBase
    ): mixed {
        if (empty($dimensiones)) {
            return TiposSistema::valorDefecto($tipoBase);
        }

        $tamano = array_shift($dimensiones);
        $valorPorDefecto = empty($dimensiones)
            ? TiposSistema::valorDefecto($tipoBase)
            : $this->crearArregloMultidimensional($dimensiones, $tipoBase);

        return array_fill(0, $tamano, $valorPorDefecto);
    }

    // ============================================================
    // SENTENCIAS DE EXPRESIÓN
    // ============================================================

    public function visitSentenciaExpr($ctx): Result
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

    // ============================================================
    // ASIGNACIONES
    // ============================================================

    public function visitAsignacion($ctx): Result
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

            // Manejo de índices multidimensionales
            if ($lv->expr() !== null && count($lv->expr()) > 0) {
                if (!is_array($sym->valor)) {
                    $this->errores->agregar(
                        'Semántico',
                        "'{$nombre}' no es un arreglo."
                    );
                    continue;
                }

                // Procesar múltiples índices
                $referencia = &$sym->valor;
                $tipoActual = $sym->tipo;
                $indicesCount = count($lv->expr());

                foreach ($lv->expr() as $j => $indiceExpr) {
                    $indiceRes = $this->visit($indiceExpr);

                    if ($indiceRes->tipo !== Result::INT32) {
                        $this->errores->agregar(
                            'Semántico',
                            "Índice debe ser int32, se obtuvo '{$indiceRes->tipo}'."
                        );
                        continue 2;
                    }

                    $indice = (int)$indiceRes->valor;

                    if (!isset($referencia[$indice])) {
                        $this->errores->agregar(
                            'Semántico',
                            "Índice '{$indice}' fuera de rango."
                        );
                        continue 2;
                    }

                    // Si es el último índice, asignar
                    if ($j === $indicesCount - 1) {
                        $tipoElemento = $this->extraerTipoElemento($tipoActual);
                        $referencia[$indice] = ValueFormatter::castear($res, $tipoElemento);
                    } else {
                        // Si no es el último, descender a la siguiente dimensión
                        if (!is_array($referencia[$indice])) {
                            $this->errores->agregar('Semántico', "No se puede indexar más.");
                            continue 2;
                        }
                        $referencia = &$referencia[$indice];
                        $tipoActual = $this->extraerTipoElemento($tipoActual);
                    }
                }
            } else {
                // Asignación simple sin índices
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
        }

        return Result::nulo();
    }

    public function visitAsignacionCompuesta($ctx): Result
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

    public function visitIncDec($ctx): Result
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

    // ============================================================
    // DECLARACIONES
    // ============================================================

    public function visitVarDecl($ctx): Result
    {
        if ($ctx === null) return Result::nulo();
        
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
                $res = $this->visit($exprs[$i]);

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
                $valor = $this->generarValorDefecto($tipo);
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

    public function visitConstDecl($ctx): Result
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

    public function visitDeclCorta($ctx): Result
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

    // ============================================================
    // LITERALES
    // ============================================================

    public function visitLiteralEntero($ctx): Result
    {
        return new Result(Result::INT32, intval($ctx->INT_LIT()->getText()));
    }

    public function visitLiteralFlotante($ctx): Result
    {
        return new Result(Result::FLOAT32, floatval($ctx->FLOAT_LIT()->getText()));
    }

    public function visitLiteralBool($ctx): Result
    {
        return new Result(Result::BOOL, $ctx->BOOL_LIT()->getText() === 'true');
    }

    public function visitLiteralRune($ctx): Result
    {
        $texto = $ctx->RUNE_LIT()->getText();
        $char  = stripslashes(substr($texto, 1, -1));
        return new Result(Result::RUNE, mb_ord($char));
    }

    public function visitLiteralString($ctx): Result
    {
        $texto = $ctx->STR_LIT()->getText();
        $str   = stripcslashes(substr($texto, 1, -1));
        return new Result(Result::STRING, $str);
    }

    public function visitExprLiteral($ctx): Result
    {
        return $this->visit($ctx->literal());
    }

    public function visitExprNil($ctx): Result
    {
        return Result::nulo();
    }

    // ============================================================
    // IDENTIFICADORES Y REFERENCIAS
    // ============================================================

    public function visitExprId($ctx): Result
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

    public function visitExprReferencia($ctx): Result
    {
        return new Result(Result::STRING, '&' . $ctx->ID()->getText());
    }

    public function visitExprDeref($ctx): Result
    {
        $nombre = $ctx->ID()->getText();
        try {
            $sym = $this->env->obtener($nombre);
            return Symbol::aResult($sym);
        } catch (\RuntimeException $e) {
            return Result::nulo();
        }
    }

    // ============================================================
    // OPERADORES UNARIOS
    // ============================================================

    public function visitExprNot($ctx): Result
    {
        $val = $this->visit($ctx->expr());
        if ($val->tipo !== Result::BOOL) {
            $this->errores->agregar('Semántico', "El operador '!' requiere bool.");
            return Result::nulo();
        }
        return new Result(Result::BOOL, !$val->valor);
    }

    public function visitExprNegacion($ctx): Result
    {
        $val = $this->visit($ctx->expr());
        if ($val->tipo === Result::NIL) return Result::nulo();
        return new Result($val->tipo, -$val->valor);
    }

    // ============================================================
    // OPERADORES BINARIOS
    // ============================================================

    public function visitExprMultiplicativa($ctx): Result
    {
        $izq = $this->visit($ctx->expr(0));
        $der = $this->visit($ctx->expr(1));
        return $this->binarioOp->aplicar($ctx->op->getText(), $izq, $der);
    }

    public function visitExprAditiva($ctx): Result
    {
        $izq = $this->visit($ctx->expr(0));
        $der = $this->visit($ctx->expr(1));
        return $this->binarioOp->aplicar($ctx->op->getText(), $izq, $der);
    }

    public function visitExprRelacional($ctx): Result
    {
        $izq = $this->visit($ctx->expr(0));
        $der = $this->visit($ctx->expr(1));
        return $this->binarioOp->aplicar($ctx->op->getText(), $izq, $der);
    }

    public function visitExprIgualdad($ctx): Result
    {
        $izq = $this->visit($ctx->expr(0));
        $der = $this->visit($ctx->expr(1));

        if ($izq->tipo === Result::NIL && $der->tipo === Result::NIL) {
            return Result::nulo();
        }

        return $this->binarioOp->aplicar($ctx->op->getText(), $izq, $der);
    }

    public function visitExprAnd($ctx): Result
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

    public function visitExprOr($ctx): Result
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

    public function visitExprAgrupada($ctx): Result
    {
        return $this->visit($ctx->expr());
    }

    // ============================================================
    // FUNCIONES EMBEBIDAS Y LLAMADAS
    // ============================================================

    public function visitExprFmtPrintln($ctx): Result
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

    // ============================================================
    // ACCESO A ARREGLOS (MEJORADO PARA MULTIDIMENSIONALIDAD)
    // ============================================================

    public function visitExprIndiceArreglo($ctx): Result
    {
        $nombreArreglo = $ctx->ID()->getText();
        $indicesCtx = $ctx->expr();

        try {
            $sym = $this->env->obtener($nombreArreglo);
        } catch (\RuntimeException $e) {
            $this->errores->agregar(
                'Semántico',
                "Arreglo '{$nombreArreglo}' no declarado.",
                $ctx->ID()->getSymbol()->getLine(),
                $ctx->ID()->getSymbol()->getCharPositionInLine()
            );
            return Result::nulo();
        }

        if (!is_array($sym->valor)) {
            $this->errores->agregar(
                'Semántico',
                "'{$nombreArreglo}' no es un arreglo."
            );
            return Result::nulo();
        }

        $valor = $sym->valor;
        $tipoActual = $sym->tipo;

        // Procesar CADA índice secuencialmente
        foreach ($indicesCtx as $indiceCtx) {
            $indiceRes = $this->visit($indiceCtx);

            if ($indiceRes->tipo !== Result::INT32) {
                $this->errores->agregar(
                    'Semántico',
                    "Índice debe ser int32, se obtuvo '{$indiceRes->tipo}'.",
                    $indiceCtx->getStart()->getLine(),
                    $indiceCtx->getStart()->getCharPositionInLine()
                );
                return Result::nulo();
            }

            $indice = (int)$indiceRes->valor;

            if (!isset($valor[$indice])) {
                $this->errores->agregar(
                    'Semántico',
                    "Índice '{$indice}' fuera de rango para arreglo '{$nombreArreglo}'."
                );
                return Result::nulo();
            }

            $valor = $valor[$indice];

            // IMPORTANTE: Actualizar tipo para la siguiente dimensión
            $tipoActual = $this->extraerTipoElemento($tipoActual);
        }

        return new Result($tipoActual, $valor);
    }

    // ============================================================
    // SENTENCIAS DE CONTROL DE FLUJO
    // ============================================================

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

    // ============================================================
    // BLOQUES
    // ============================================================

    public function visitBloque($ctx): Result
    {
        // Permitir null si viene desde BaseVisitor
        if ($ctx === null) {
            return Result::nulo();
        }

        $envAnterior = $this->env;
        $this->env = new Environment($envAnterior);

        $resultado = Result::nulo();

        if (method_exists($ctx, 'sentencia')) {
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
                    $this->registroSimbolos[] = $sym;
                }

                if ($resultado !== null && ($resultado->esReturn || $resultado->esBreak || $resultado->esContinue)) {
                    break;
                }
            }
        }

        $this->env = $envAnterior;
        return $resultado;
    }

    // ============================================================
    // HELPERS
    // ============================================================

    private function generarValorDefecto(string $tipo): mixed
    {
        // Eliminar punteros del análisis
        while (strpos($tipo, '*') === 0) {
            $tipo = substr($tipo, 1);
        }

        // Analizar dimensiones
        $info = $this->obtenerDimensiones($tipo);
        $dimensiones = $info['dimensiones'];
        $tipoBase = $info['tipoBase'];

        // Si hay dimensiones, crear arreglo multidimensional
        if (!empty($dimensiones)) {
            return $this->crearArregloMultidimensional($dimensiones, $tipoBase);
        }

        // Si no hay dimensiones, retornar valor por defecto del tipo base
        return TiposSistema::valorDefecto($tipoBase);
    }

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