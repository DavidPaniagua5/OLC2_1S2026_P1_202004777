<?php

namespace App;

// ---- Contextos ANTLR generados (nombres según Grammar.g4) ----

// Programa y funciones
use Context\ProgramaContext;
use Context\FuncDeclContext;
use Context\BloqueContext;

// Declaraciones
use Context\VarDeclContext;
use Context\ConstDeclContext;
use Context\DeclCortaContext;

// Sentencias
use Context\AsignacionContext;
use Context\AsignacionCompuestaContext;
use Context\IncDecContext;
use Context\SentenciaIfContext;
use Context\ForClassicoContext;
use Context\ForWhileContext;
use Context\ForInfinitoContext;
use Context\SentenciaSwitchContext;
use Context\SentenciaReturnContext;
use Context\SentenciaBreakContext;
use Context\SentenciaContinueContext;
use Context\SentenciaExprContext;

// Expresiones
use Context\ExprOrContext;
use Context\ExprAndContext;
use Context\ExprIgualdadContext;
use Context\ExprRelacionalContext;
use Context\ExprAditivaContext;
use Context\ExprMultiplicativaContext;
use Context\ExprNotContext;
use Context\ExprNegacionContext;
use Context\ExprReferenciaContext;
use Context\ExprDerefContext;
use Context\ExprAgrupadaContext;
use Context\ExprFmtPrintlnContext;
use Context\ExprLlamadaContext;
use Context\ExprIndiceArregloContext;
use Context\ExprIdContext;
use Context\ExprLiteralContext;
use Context\ExprNilContext;

// Literales
use Context\LiteralEnteroContext;
use Context\LiteralFlotanteContext;
use Context\LiteralBoolContext;
use Context\LiteralRuneContext;
use Context\LiteralStringContext;

// Entorno
use App\Env\{Environment, Symbol, Result, TiposSistema, ManejadorErrores};

class Interpreter extends \GrammarBaseVisitor
{
    // Salida de consola acumulada
    private string $consola = '';

    // Entorno global (persiste durante toda la ejecución)
    private Environment $envGlobal;

    // Entorno activo en cada momento
    private Environment $env;

    // Registro de errores semánticos
    public ManejadorErrores $errores;

    /**
     * Registro central de todos los símbolos declarados durante la ejecución.
     * Se llena en visitVarDecl, visitConstDecl y visitDeclCorta.
     * Cada entrada: { id, tipo, valor, clase, ambito, fila, columna }
     * @var array<int, array>
     */
    public array $registroSimbolos = [];

    // Nombre del ámbito activo (función o 'global')
    private string $ambitoActual = 'global';

    public function __construct()
    {
        $this->errores    = new ManejadorErrores();
        $this->envGlobal  = new Environment();
        $this->env        = $this->envGlobal;
    }

    // ==============================================================
    // PROGRAMA — punto de entrada del visitor
    // ==============================================================
    public function visitPrograma(ProgramaContext $ctx): string
    {
        // HOISTING: registrar todas las funciones antes de ejecutar cualquier cosa
        foreach ($ctx->declaracionTop() as $decl) {
            if ($decl->funcDecl() !== null) {
                $this->registrarFuncion($decl->funcDecl());
            }
        }

        // Ejecutar declaraciones globales (var / const)
        foreach ($ctx->declaracionTop() as $decl) {
            if ($decl->varDecl() !== null) {
                $this->visit($decl->varDecl());
            } elseif ($decl->constDecl() !== null) {
                $this->visit($decl->constDecl());
            }
        }

        // Buscar y ejecutar main()
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

    // ==============================================================
    // REGISTRO DE FUNCIONES (hoisting)
    // ==============================================================
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
        $sym->nombre  = $nombre;  // guardamos el nombre para el snapshot
        $this->envGlobal->declarar($nombre, $sym);
    }

    public function visitFuncDecl(FuncDeclContext $ctx): Result
    {
        // Ya registrada en hoisting — nada que hacer aquí
        return Result::nulo();
    }

    // ==============================================================
    // EJECUCIÓN DE FUNCIONES
    // ==============================================================
    private function ejecutarFuncion(Symbol $fn, array $args): Result
    {
        // Nuevo entorno hijo del GLOBAL (no del actual), igual que Go
        $nuevoEnv = new Environment($this->envGlobal);

        // Bind de parámetros
        if ($fn->params !== null) {
            foreach ($fn->params as $i => $param) {
                $arg = $args[$i] ?? Result::nulo();
                $sym = new Symbol($param['tipo'], $arg->valor, Symbol::CLASE_VARIABLE, 0, 0);
                $nuevoEnv->declarar($param['id'], $sym);
            }
        }

        $envAnterior       = $this->env;
        $ambitoAnterior    = $this->ambitoActual;
        $this->env         = $nuevoEnv;
        $this->ambitoActual = $fn->nombre ?? 'funcion';

        $resultado = $this->visit($fn->valor); // fn->valor es el BloqueContext

        $this->env          = $envAnterior;
        $this->ambitoActual = $ambitoAnterior;

        return $resultado;
    }

    // ==============================================================
    // BLOQUE
    // ==============================================================
    public function visitBloque(BloqueContext $ctx): Result
    {
        $envAnterior  = $this->env;
        $this->env    = new Environment($envAnterior);

        $resultado = Result::nulo();
        foreach ($ctx->sentencia() as $sent) {
            $resultado = $this->visit($sent);
            // Propagar señales de control hacia afuera
            if ($resultado->esReturn || $resultado->esBreak || $resultado->esContinue) {
                break;
            }
        }

        $this->env = $envAnterior;
        return $resultado;
    }

    // ==============================================================
    // DECLARACIONES
    // ==============================================================

    /** var x int32 = 5  /  var x, y int32  /  var x, y int32 = 1, 2 */
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
                $valor = $this->castear($res, $tipo);
            } else {
                $valor = TiposSistema::valorDefecto($tipo);
            }

            $sym = new Symbol($tipo, $valor, Symbol::CLASE_VARIABLE, 0, 0);
            $this->env->declarar($nombre, $sym);

            // Registrar en la tabla central de símbolos
            $this->registrarSimbolo(
                $nombre, $tipo, $valor, Symbol::CLASE_VARIABLE,
                $nodoId->getSymbol()->getLine(),
                $nodoId->getSymbol()->getCharPositionInLine()
            );
        }

        return Result::nulo();
    }

    /** const PI float32 = 3.14 */
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
        $valor = $this->castear($res, $tipo);

        $sym              = new Symbol($tipo, $valor, Symbol::CLASE_CONSTANTE, 0, 0);
        $sym->esConstante = true;
        $this->env->declarar($nombre, $sym);

        // Registrar en la tabla central de símbolos
        $this->registrarSimbolo(
            $nombre, $tipo, $valor, Symbol::CLASE_CONSTANTE,
            $ctx->ID()->getSymbol()->getLine(),
            $ctx->ID()->getSymbol()->getCharPositionInLine()
        );

        return Result::nulo();
    }

    /** x := 5  /  x, y := 1, 2 */
    public function visitDeclCorta(DeclCortaContext $ctx): Result
    {
        $ids   = $ctx->listaIds()->ID();
        $exprs = $ctx->listaExpr()->expr();

        if (count($ids) !== count($exprs)) {
            $this->errores->agregar('Semántico', 'La cantidad de identificadores y expresiones debe ser igual.');
            return Result::nulo();
        }

        foreach ($ids as $i => $nodoId) {
            $nombre = $nodoId->getText();
            $res    = $this->visit($exprs[$i]);

            if ($this->env->existeLocal($nombre)) {
                // Si ya existe localmente, reasignamos (Go lo permite si al menos una es nueva)
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
                    $sym->valor = $this->castear($res, $sym->tipo);
                } catch (\RuntimeException $e) {
                    $this->errores->agregar('Semántico', $e->getMessage());
                }
            } else {
                // Nueva variable — el tipo se infiere de la expresión
                $sym = new Symbol($res->tipo, $res->valor, Symbol::CLASE_VARIABLE, 0, 0);
                $this->env->declarar($nombre, $sym);

                // Registrar en la tabla central de símbolos
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

    /** x = 5  /  x, y = 1, 2 */
    public function visitAsignacion(AsignacionContext $ctx): Result
    {
        $lvalues = $ctx->listaLvalue()->lvalue();
        $exprs   = $ctx->listaExpr()->expr();

        foreach ($lvalues as $i => $lv) {
            $res = $this->visit($exprs[$i]);
            $this->asignarLvalue($lv, $res);
        }

        return Result::nulo();
    }

    /** x += expr  x -= expr  x *= expr  x /= expr */
    public function visitAsignacionCompuesta(AsignacionCompuestaContext $ctx): Result
    {
        $nombre = $ctx->lvalue()->ID()->getText();

        try {
            $sym = $this->env->obtener($nombre);
        } catch (\RuntimeException $e) {
            $this->errores->agregar('Semántico', $e->getMessage());
            return Result::nulo();
        }

        $derecha = $this->visit($ctx->expr());
        $izq     = new Result($sym->tipo, $sym->valor);
        // El operador compuesto llega como "+=" → extraemos "+"
        $op      = rtrim($ctx->op->getText(), '=');

        $nuevo = $this->aplicarBinario($op, $izq, $derecha);
        if ($nuevo->tipo !== Result::NIL) {
            $sym->valor = $nuevo->valor;
        }

        return Result::nulo();
    }

    private function asignarLvalue($lv, Result $res): void
    {
        $nombre = $lv->ID()->getText();
        try {
            $sym = $this->env->obtener($nombre);
            if ($sym->esConstante) {
                $this->errores->agregar(
                    'Semántico',
                    "No se puede asignar a la constante '{$nombre}'."
                );
                return;
            }
            $sym->valor = $this->castear($res, $sym->tipo);

            // Actualizar valor en la tabla central de símbolos
            $this->registrarSimbolo($nombre, $sym->tipo, $sym->valor, $sym->clase);
        } catch (\RuntimeException $e) {
            $this->errores->agregar('Semántico', $e->getMessage());
        }
    }

    // ==============================================================
    // INC / DEC  (x++  x--)
    // ==============================================================
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
    // IF
    // ==============================================================
    public function visitSentenciaIf(SentenciaIfContext $ctx): Result
    {
        $cond = $this->visit($ctx->expr());

        if ($cond->tipo !== Result::BOOL) {
            $this->errores->agregar(
                'Semántico',
                "La condición del 'if' debe ser bool, se obtuvo '{$cond->tipo}'."
            );
            return Result::nulo();
        }

        if ($cond->valor) {
            return $this->visit($ctx->bloque(0));
        }

        // else if
        if ($ctx->sentenciaIf() !== null) {
            return $this->visit($ctx->sentenciaIf());
        }

        // else { }
        if (count($ctx->bloque()) > 1) {
            return $this->visit($ctx->bloque(1));
        }

        return Result::nulo();
    }

    // ==============================================================
    // FOR
    // ==============================================================

    /** for i := 0; i < 5; i++ { } */
    public function visitForClassico(ForClassicoContext $ctx): Result
    {
        // El init vive en un ámbito nuevo
        $envAnterior = $this->env;
        $this->env   = new Environment($envAnterior);

        $this->visit($ctx->declCorta());

        while (true) {
            $cond = $this->visit($ctx->expr());
            if ($cond->tipo !== Result::BOOL || !$cond->valor) {
                break;
            }

            $res = $this->visit($ctx->bloque());
            if ($res->esReturn) {
                $this->env = $envAnterior;
                return $res;
            }
            if ($res->esBreak) {
                break;
            }
            // esContinue → simplemente continúa con el update

            // Actualización: puede ser incDec o asignacionCompuesta
            if ($ctx->incDec() !== null) {
                $this->visit($ctx->incDec());
            } else {
                $this->visit($ctx->asignacionCompuesta());
            }
        }

        $this->env = $envAnterior;
        return Result::nulo();
    }

    /** for x > 0 { } */
    public function visitForWhile(ForWhileContext $ctx): Result
    {
        while (true) {
            $cond = $this->visit($ctx->expr());
            if ($cond->tipo !== Result::BOOL || !$cond->valor) {
                break;
            }
            $res = $this->visit($ctx->bloque());
            if ($res->esReturn) return $res;
            if ($res->esBreak)  break;
        }
        return Result::nulo();
    }

    /** for { } */
    public function visitForInfinito(ForInfinitoContext $ctx): Result
    {
        while (true) {
            $res = $this->visit($ctx->bloque());
            if ($res->esReturn) return $res;
            if ($res->esBreak)  break;
        }
        return Result::nulo();
    }

    // ==============================================================
    // SWITCH
    // ==============================================================
    public function visitSentenciaSwitch(SentenciaSwitchContext $ctx): Result
    {
        $valSwitch = $this->visit($ctx->expr());

        foreach ($ctx->casoSwitch() as $caso) {
            foreach ($caso->listaExpr()->expr() as $exprCaso) {
                $valCaso = $this->visit($exprCaso);
                if ($valCaso->valor == $valSwitch->valor) {
                    foreach ($caso->sentencia() as $s) {
                        $res = $this->visit($s);
                        if ($res->esBreak || $res->esReturn) {
                            return Result::nulo();
                        }
                    }
                    return Result::nulo(); // sin fallthrough
                }
            }
        }

        if ($ctx->defaultSwitch() !== null) {
            foreach ($ctx->defaultSwitch()->sentencia() as $s) {
                $res = $this->visit($s);
                if ($res->esBreak || $res->esReturn) {
                    return Result::nulo();
                }
            }
        }

        return Result::nulo();
    }

    // ==============================================================
    // SENTENCIAS DE TRANSFERENCIA
    // ==============================================================
    public function visitSentenciaReturn(SentenciaReturnContext $ctx): Result
    {
        if ($ctx->listaExpr() !== null) {
            $exprs = $ctx->listaExpr()->expr();
            if (count($exprs) === 1) {
                $res = $this->visit($exprs[0]);
                $res->esReturn = true;
                return $res;
            }
            // Múltiples valores de retorno
            $vals = array_map(fn($e) => $this->visit($e), $exprs);
            $res  = new Result('tupla', $vals);
            $res->esReturn = true;
            return $res;
        }

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

    public function visitSentenciaExpr(SentenciaExprContext $ctx): Result
    {
        return $this->visit($ctx->expr());
    }

    // ==============================================================
    // EXPRESIONES
    // ==============================================================

    /** nil */
    public function visitExprNil(ExprNilContext $ctx): Result
    {
        return Result::nulo();
    }

    /** ID */
    public function visitExprId(ExprIdContext $ctx): Result
    {
        $nombre = $ctx->ID()->getText();
        try {
            return Symbol::aResult($this->env->obtener($nombre));
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

    /** ( expr ) */
    public function visitExprAgrupada(ExprAgrupadaContext $ctx): Result
    {
        return $this->visit($ctx->expr());
    }

    /** -expr */
    public function visitExprNegacion(ExprNegacionContext $ctx): Result
    {
        $val = $this->visit($ctx->expr());
        if ($val->tipo === Result::NIL) return Result::nulo();
        return new Result($val->tipo, -$val->valor);
    }

    /** !expr */
    public function visitExprNot(ExprNotContext $ctx): Result
    {
        $val = $this->visit($ctx->expr());
        if ($val->tipo !== Result::BOOL) {
            $this->errores->agregar('Semántico', "El operador '!' requiere bool, se obtuvo '{$val->tipo}'.");
            return Result::nulo();
        }
        return new Result(Result::BOOL, !$val->valor);
    }

    // ---- Multiplicativa  * / % ----
    public function visitExprMultiplicativa(ExprMultiplicativaContext $ctx): Result
    {
        $izq = $this->visit($ctx->expr(0));
        $der = $this->visit($ctx->expr(1));
        return $this->aplicarBinario($ctx->op->getText(), $izq, $der);
    }

    // ---- Aditiva  + - ----
    public function visitExprAditiva(ExprAditivaContext $ctx): Result
    {
        $izq = $this->visit($ctx->expr(0));
        $der = $this->visit($ctx->expr(1));
        return $this->aplicarBinario($ctx->op->getText(), $izq, $der);
    }

    // ---- Relacional  < <= > >= ----
    public function visitExprRelacional(ExprRelacionalContext $ctx): Result
    {
        $izq = $this->visit($ctx->expr(0));
        $der = $this->visit($ctx->expr(1));
        return $this->aplicarBinario($ctx->op->getText(), $izq, $der);
    }

    // ---- Igualdad  == != ----
    public function visitExprIgualdad(ExprIgualdadContext $ctx): Result
    {
        $izq = $this->visit($ctx->expr(0));
        $der = $this->visit($ctx->expr(1));

        // nil == nil es válido y devuelve nil (según el enunciado)
        if ($izq->tipo === Result::NIL && $der->tipo === Result::NIL) {
            return Result::nulo();
        }

        return $this->aplicarBinario($ctx->op->getText(), $izq, $der);
    }

    // ---- && con corto circuito ----
    public function visitExprAnd(ExprAndContext $ctx): Result
    {
        $izq = $this->visit($ctx->expr(0));

        if ($izq->tipo !== Result::BOOL) {
            $this->errores->agregar('Semántico', "El operando izquierdo de '&&' debe ser bool.");
            return Result::nulo();
        }

        // CORTO CIRCUITO: si izquierda es false, no evaluamos derecha
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

    // ---- || con corto circuito ----
    public function visitExprOr(ExprOrContext $ctx): Result
    {
        $izq = $this->visit($ctx->expr(0));

        if ($izq->tipo !== Result::BOOL) {
            $this->errores->agregar('Semántico', "El operando izquierdo de '||' debe ser bool.");
            return Result::nulo();
        }

        // CORTO CIRCUITO: si izquierda es true, no evaluamos derecha
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
    // fmt.Println(e1, e2, ...)
    // ==============================================================
    public function visitExprFmtPrintln(ExprFmtPrintlnContext $ctx): Result
    {
        $partes = [];
        if ($ctx->listaExpr() !== null) {
            foreach ($ctx->listaExpr()->expr() as $e) {
                $res      = $this->visit($e);
                $partes[] = $this->aTexto($res);
            }
        }
        $this->consola .= implode(' ', $partes) . "\n";
        return Result::nulo();
    }

    // ==============================================================
    // Llamadas a funciones (built-in y definidas por el usuario)
    // ==============================================================
    public function visitExprLlamada(ExprLlamadaContext $ctx): Result
    {
        $nombre = $ctx->ID()->getText();

        // --- Funciones embebidas ---
        switch ($nombre) {
            case 'println':
                // Alias sin prefijo fmt.
                $partes = [];
                if ($ctx->listaExpr() !== null) {
                    foreach ($ctx->listaExpr()->expr() as $e) {
                        $partes[] = $this->aTexto($this->visit($e));
                    }
                }
                $this->consola .= implode(' ', $partes) . "\n";
                return Result::nulo();

            case 'len':
                return $this->builtinLen($ctx);

            case 'now':
                return new Result(Result::STRING, date('Y-m-d H:i:s'));

            case 'substr':
                return $this->builtinSubstr($ctx);

            case 'typeOf':
                $arg = $this->visit($ctx->listaExpr()->expr()[0]);
                return new Result(Result::STRING, $arg->tipo);
        }

        // --- Función definida por el usuario ---
        try {
            $sym = $this->env->obtener($nombre);
        } catch (\RuntimeException $e) {
            $this->errores->agregar(
                'Semántico',
                "Función '{$nombre}' no declarada.",
                $ctx->ID()->getSymbol()->getLine(),
                $ctx->ID()->getSymbol()->getCharPositionInLine()
            );
            return Result::nulo();
        }

        if ($sym->clase !== Symbol::CLASE_FUNCION) {
            $this->errores->agregar('Semántico', "'{$nombre}' no es una función.");
            return Result::nulo();
        }

        $args = [];
        if ($ctx->listaExpr() !== null) {
            foreach ($ctx->listaExpr()->expr() as $e) {
                $args[] = $this->visit($e);
            }
        }

        return $this->ejecutarFuncion($sym, $args);
    }

    // ==============================================================
    // LITERALES
    // ==============================================================
    public function visitExprLiteral(ExprLiteralContext $ctx): Result
    {
        return $this->visit($ctx->literal());
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
        // 'A'  →  quitar comillas simples y convertir a codepoint
        $texto = $ctx->RUNE_LIT()->getText();
        $char  = stripslashes(substr($texto, 1, -1));
        return new Result(Result::RUNE, mb_ord($char));
    }

    public function visitLiteralString(LiteralStringContext $ctx): Result
    {
        $texto = $ctx->STR_LIT()->getText();
        // Quitar comillas dobles y procesar secuencias de escape
        $str   = stripcslashes(substr($texto, 1, -1));
        return new Result(Result::STRING, $str);
    }

    // ==============================================================
    // HELPERS INTERNOS
    // ==============================================================

    /**
     * Aplica un operador binario respetando las tablas de tipos del enunciado.
     */
    private function aplicarBinario(string $op, Result $izq, Result $der): Result
    {
        // Operación con nil → siempre error y nil
        if ($izq->tipo === Result::NIL || $der->tipo === Result::NIL) {
            $this->errores->agregar('Semántico', "No se puede operar con nil.");
            return Result::nulo();
        }

        $tipoResultado = TiposSistema::resultado($op, $izq->tipo, $der->tipo);

        if ($tipoResultado === null) {
            $this->errores->agregar(
                'Semántico',
                "Operación '{$op}' no válida entre '{$izq->tipo}' y '{$der->tipo}'."
            );
            return Result::nulo();
        }

        $l = $izq->valor;
        $r = $der->valor;

        try {
            $valor = match ($op) {
                '+'  => $l + $r,
                '-'  => $l - $r,
                // String * int  → str_repeat
                '*'  => ($izq->tipo === Result::STRING)
                            ? str_repeat((string)$l, (int)$r)
                            : $l * $r,
                '/'  => $this->dividir($tipoResultado, $l, $r),
                '%'  => $this->modulo($l, $r),
                '==' => $l == $r,
                '!=' => $l != $r,
                '<'  => $l < $r,
                '<=' => $l <= $r,
                '>'  => $l > $r,
                '>=' => $l >= $r,
                default => null,
            };
        } catch (\RuntimeException $e) {
            $this->errores->agregar('Semántico', $e->getMessage());
            return Result::nulo();
        }

        if ($valor === null) {
            return Result::nulo();
        }

        return new Result($tipoResultado, $valor);
    }

    private function dividir(string $tipoRes, mixed $l, mixed $r): mixed
    {
        if ($r == 0) {
            throw new \RuntimeException('División por cero.');
        }
        // División entera si el resultado es int32
        if ($tipoRes === Result::INT32) {
            return intdiv((int)$l, (int)$r);
        }
        return $l / $r;
    }

    private function modulo(mixed $l, mixed $r): int
    {
        if ((int)$r === 0) {
            throw new \RuntimeException('Módulo por cero.');
        }
        return (int)$l % (int)$r;
    }

    /**
     * Intenta ajustar el valor de un Result al tipo declarado.
     * Si los tipos son compatibles hace la conversión; si no, reporta error.
     */
    private function castear(Result $res, string $tipoDestino): mixed
    {
        if ($res->tipo === Result::NIL) {
            return null;
        }

        return match ($tipoDestino) {
            Result::INT32   => intval($res->valor),
            Result::FLOAT32 => floatval($res->valor),
            Result::BOOL    => (bool)$res->valor,
            Result::RUNE    => is_string($res->valor)
                                   ? mb_ord($res->valor)
                                   : intval($res->valor),
            Result::STRING  => $this->aTexto($res),
            default         => $res->valor,
        };
    }

    private function aTexto(Result $res): string
    {
        return match ($res->tipo) {
            Result::NIL     => '<nil>',
            Result::BOOL    => $res->valor ? 'true' : 'false',
            Result::FLOAT32 => $this->formatearFloat($res->valor),
            default         => (string)($res->valor ?? '<nil>'),
        };
    }

    private function formatearFloat(float $f): string
    {
        // Usar la representación más corta sin trailing zeros, igual que Go
        $s = rtrim(sprintf('%.7g', $f), '0');
        if (str_ends_with($s, '.')) {
            $s .= '0';
        }
        return $s;
    }

    // ==============================================================
    // BUILT-INS
    // ==============================================================
    private function builtinLen(ExprLlamadaContext $ctx): Result
    {
        if ($ctx->listaExpr() === null) {
            $this->errores->agregar('Semántico', 'len() requiere un argumento.');
            return Result::nulo();
        }
        $arg = $this->visit($ctx->listaExpr()->expr()[0]);

        if ($arg->tipo === Result::STRING) {
            return new Result(Result::INT32, mb_strlen((string)$arg->valor));
        }
        if (is_array($arg->valor)) {
            return new Result(Result::INT32, count($arg->valor));
        }

        $this->errores->agregar('Semántico', "len() requiere string o arreglo, se obtuvo '{$arg->tipo}'.");
        return Result::nulo();
    }

    private function builtinSubstr(ExprLlamadaContext $ctx): Result
    {
        if ($ctx->listaExpr() === null || count($ctx->listaExpr()->expr()) < 3) {
            $this->errores->agregar('Semántico', 'substr() requiere 3 argumentos: (cadena, inicio, longitud).');
            return Result::nulo();
        }
        $exprs  = $ctx->listaExpr()->expr();
        $cadena = $this->visit($exprs[0]);
        $inicio = $this->visit($exprs[1]);
        $largo  = $this->visit($exprs[2]);

        $resultado = mb_substr((string)$cadena->valor, (int)$inicio->valor, (int)$largo->valor);
        return new Result(Result::STRING, $resultado);
    }

    // ==============================================================
    // EXPORTAR TABLA DE SÍMBOLOS
    // Devuelve todos los símbolos declarados durante la ejecución.
    // Formato: [{ id, tipo, valor, clase, ambito, fila, columna }, ...]
    // ==============================================================
    public function tablaSimbolos(): array
    {
        // Funciones registradas en el entorno global (para mostrarlas también)
        $funciones = $this->envGlobal->exportarConAmbito('global');

        // Combinar funciones globales + variables/constantes registradas en ejecución
        return array_merge($funciones, $this->registroSimbolos);
    }

    /**
     * Registra un símbolo en la tabla central. Llamado desde los visit de declaración.
     * Actualiza el valor si el símbolo ya existe (para reflejar el valor final).
     */
    public function registrarSimbolo(
        string $id,
        string $tipo,
        mixed  $valor,
        string $clase,
        int    $fila    = 0,
        int    $columna = 0
    ): void {
        // Buscar si ya existe para actualizar en lugar de duplicar
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

    private function serializarParaTabla(mixed $valor): mixed
    {
        if (is_object($valor))  return '(objeto)';
        if (is_array($valor))   return json_encode($valor);
        if (is_bool($valor))    return $valor ? 'true' : 'false';
        if (is_null($valor))    return null;
        return $valor;
    }
}