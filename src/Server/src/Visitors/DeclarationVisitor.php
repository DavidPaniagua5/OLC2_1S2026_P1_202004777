<?php

namespace App\Visitors;

use VarDeclContext;
use ConstDeclContext;
use DeclCortaContext;

use App\Env\{Result, Symbol, TiposSistema, Environment};
use App\Utils\ValueFormatter;

/**
 * Visitor para procesar declaraciones de variables y constantes.
 * Maneja:
 * - Declaraciones de variables con inicialización
 * - Declaraciones de constantes
 * - Declaraciones cortas
 * - Generación de valores por defecto para tipos
 * - Soporte para arreglos multidimensionales
 */
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
    // DECLARACIONES DE VARIABLES
    // ============================================================

    /**
     * Procesa declaraciones de variables: var x tipo = expr
     */
    public function visitVarDecl($ctx): Result
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

    // ============================================================
    // DECLARACIONES DE CONSTANTES
    // ============================================================

    /**
     * Procesa declaraciones de constantes: const ID tipo = expr
     */
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

    // ============================================================
    // DECLARACIONES CORTAS
    // ============================================================

    /**
     * Procesa declaraciones cortas: x := expr
     */
    public function visitDeclCorta($ctx): Result
    {
        if ($ctx === null) {
            return Result::nulo();
        }

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

    // ============================================================
    // GENERACIÓN DE VALORES POR DEFECTO
    // ============================================================

    /**
     * Genera el valor por defecto para un tipo.
     * MEJORADO: Soporta arreglos multidimensionales.
     */
    private function generarValorDefecto(string $tipo): mixed
    {
        // Eliminar punteros del análisis
        while (strpos($tipo, '*') === 0) {
            $tipo = substr($tipo, 1);
        }

        // Analizar dimensiones del tipo
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
}