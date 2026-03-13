<?php

namespace App\Visitors;

use Context\{VarDeclContext, ConstDeclContext, DeclCortaContext};
use App\Env\{Result, Symbol, TiposSistema};
use App\Utils\ValueFormatter;

class DeclarationVisitor extends BaseVisitor
{
    public function __construct(
        \App\Env\Environment $envGlobal,
        \App\Env\Environment $env,
        \App\Env\ManejadorErrores $errores,
        string $ambitoActual = 'global'
    ) {
        parent::__construct($envGlobal, $env, $errores, $ambitoActual);
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
                $exprVisitor = new ExpressionVisitor(
                    $this->envGlobal,
                    $this->env,
                    $this->errores,
                    $this->ambitoActual
                );
                
                $res   = $exprVisitor->visit($exprs[$i]);
                
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

        $exprVisitor = new ExpressionVisitor(
            $this->envGlobal,
            $this->env,
            $this->errores,
            $this->ambitoActual
        );

        $res   = $exprVisitor->visit($ctx->expr());
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

    private function generarValorDefecto(string $tipo): mixed
    {
        if (strpos($tipo, '[') !== false) {
            preg_match('/\[(\d+)\]/', $tipo, $matches);
            if (isset($matches[1])) {
                $tamaño = (int)$matches[1];
                $tipoElemento = str_replace($matches[0], '', $tipo);
                
                $valoresDefecto = array_fill(0, $tamaño, TiposSistema::valorDefecto($tipoElemento));
                
                if (strpos($tipoElemento, '[') !== false) {
                    foreach ($valoresDefecto as $i => $val) {
                        $valoresDefecto[$i] = $this->generarValorDefecto($tipoElemento);
                    }
                }
                
                return $valoresDefecto;
            }
        }
        
        return TiposSistema::valorDefecto($tipo);
    }
}