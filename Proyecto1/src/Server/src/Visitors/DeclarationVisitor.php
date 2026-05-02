<?php

namespace App\Visitors;

use Context\{VarDeclContext, ConstDeclContext, DeclCortaContext};
use App\Env\{Symbol, Result, TiposSistema};
use App\Utils\ValueFormatter;

/**
 * Visitor para declaraciones (var, const, declCorta).
 */
class DeclarationVisitor extends BaseVisitor
{
    /**
     * var x int32 = 5
     * var x, y int32
     * var x, y int32 = 1, 2
     */
    public function visitVarDecl(VarDeclContext $ctx): Result
    {
        $tipo  = $ctx->tipo()->getText();
        $ids   = $ctx->listaIds()->ID();
        $exprs = $ctx->listaExpr() !== null ? $ctx->listaExpr()->expr() : [];

        foreach ($ids as $i => $nodoId) {
            $nombre = $nodoId->getText();

            // Verificar redeclaración en ámbito local
            if ($this->env->existeLocal($nombre)) {
                $this->errores->agregar(
                    'Semántico',
                    "Identificador '{$nombre}' ya ha sido declarado en este ámbito.",
                    $nodoId->getSymbol()->getLine(),
                    $nodoId->getSymbol()->getCharPositionInLine()
                );
                continue;
            }

            // Evaluar expresión o usar valor por defecto
            if (isset($exprs[$i])) {
                $exprVisitor = new ExpressionVisitor(
                    $this->envGlobal,
                    $this->env,
                    $this->errores,
                    $this->ambitoActual
                );
                $res   = $exprVisitor->visit($exprs[$i]);
                $valor = ValueFormatter::castear($res, $tipo);
                // Propagar consola
                $this->consola .= $exprVisitor->obtenerConsola();
            } else {
                $valor = $this->crearValorDefecto($tipo);
                //$valor = TiposSistema::valorDefecto($tipo);
            }

            // Crear símbolo y declarar en el entorno
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

    /**
     * const PI float32 = 3.14
     */
    public function visitConstDecl(ConstDeclContext $ctx): Result
    {
        $nombre = $ctx->ID()->getText();
        $tipo   = $ctx->tipo()->getText();

        // Verificar redeclaración en ámbito local
        if ($this->env->existeLocal($nombre)) {
            $this->errores->agregar(
                'Semántico',
                "Constante '{$nombre}' ya declarada en este ámbito.",
                $ctx->ID()->getSymbol()->getLine(),
                $ctx->ID()->getSymbol()->getCharPositionInLine()
            );
            return Result::nulo();
        }

        // Evaluar expresión
        $exprVisitor = new ExpressionVisitor(
            $this->envGlobal,
            $this->env,
            $this->errores,
            $this->ambitoActual
        );
        $res   = $exprVisitor->visit($ctx->expr());
        $valor = ValueFormatter::castear($res, $tipo);
        $this->consola .= $exprVisitor->obtenerConsola();

        // Crear símbolo constante
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

    /**
     * x := 5
     * x, y := 1, 2
     */
    public function visitDeclCorta(DeclCortaContext $ctx): Result
    {
        $ids   = $ctx->listaIds()->ID();
        $exprs = $ctx->listaExpr()->expr();

        // Validar cantidad de expresiones vs identificadores
        if (count($ids) !== count($exprs)) {
            $this->errores->agregar(
                'Semántico',
                'La cantidad de identificadores y expresiones debe ser igual.'
            );
            return Result::nulo();
        }

        $exprVisitor = new ExpressionVisitor(
            $this->envGlobal,
            $this->env,
            $this->errores,
            $this->ambitoActual
        );

        foreach ($ids as $i => $nodoId) {
            $nombre = $nodoId->getText();
            $res    = $exprVisitor->visit($exprs[$i]);

            if ($this->env->existeLocal($nombre)) {
                // Go permite reasignación si al menos una variable es nueva
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
                    // Reasignar con tipo actual
                    $sym->valor = ValueFormatter::castear($res, $sym->tipo);
                    $this->registrarSimbolo($nombre, $sym->tipo, $sym->valor, $sym->clase);
                } catch (\RuntimeException $e) {
                    $this->errores->agregar('Semántico', $e->getMessage());
                }
            } else {
                // Nueva variable — inferir tipo de la expresión
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

        $this->consola .= $exprVisitor->obtenerConsola();
        return Result::nulo();
    }
}