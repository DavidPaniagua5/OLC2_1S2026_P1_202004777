<?php

namespace App\Visitors;

use App\Env\{Environment, ManejadorErrores, Result, TiposSistema, Symbol};
use App\Utils\ValueFormatter;

/**
 * Clase base para todos los visitors.
 * Proporciona funcionalidad común y acceso al contexto compartido.
 */
abstract class BaseVisitor extends \GrammarBaseVisitor
{
    protected string $consola = '';
    protected Environment $envGlobal;
    protected Environment $env;
    protected ManejadorErrores $errores;
    protected string $ambitoActual = 'global';
    protected array $registroSimbolos = [];

    public function __construct(
        Environment &$envGlobal,
        Environment &$env,
        ManejadorErrores $errores,
        string $ambitoActual = 'global'
    ) {
        $this->envGlobal    = $envGlobal;
        $this->env          = $env;
        $this->errores      = $errores;
        $this->ambitoActual = $ambitoActual;
    }

    // ==============================================================
    // ACCESO A SÍMBOLOS
    // ==============================================================

    protected function obtenerSimbolo(string $nombre)
    {
        try {
            return $this->env->obtener($nombre);
        } catch (\RuntimeException $e) {
            $this->errores->agregar('Semántico', $e->getMessage());
            return null;
        }
    }

    // ==============================================================
    // CONSOLA
    // ==============================================================

    public function agregarConsola(string $texto): void
    {
        $this->consola .= $texto;
    }

    public function obtenerConsola(): string
    {
        return $this->consola;
    }

    public function limpiarConsola(): void
    {
        $this->consola = '';
    }

    // ==============================================================
    // TABLA DE SÍMBOLOS
    // ==============================================================

    public function registrarSimbolo(
        string $id,
        string $tipo,
        mixed  $valor,
        string $clase,
        int    $fila    = 0,
        int    $columna = 0
    ): void {
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

    protected function serializarParaTabla(mixed $valor): mixed
    {
        if (is_object($valor))  return '(objeto)';
        if (is_array($valor))   return json_encode($valor);
        if (is_bool($valor))    return $valor ? 'true' : 'false';
        if (is_null($valor))    return null;
        return $valor;
    }

    public function obtenerRegistroSimbolos(): array
    {
        return $this->registroSimbolos;
    }

    // ==============================================================
    // HELPERS PARA ARREGLOS
    // ==============================================================

    protected function parseArrayType(string $tipo): array
    {
        $dims = [];
        $t    = $tipo;
        while (preg_match('/^\[(\d+)\](.*)$/', $t, $m)) {
            $dims[] = (int)$m[1];
            $t      = $m[2];
        }
        return ['dims' => $dims, 'base' => $t];
    }

    protected function crearValorDefecto(string $tipo): mixed
    {
        if (!str_starts_with($tipo, '[')) {
            return TiposSistema::valorDefecto($tipo);
        }
        $parsed = $this->parseArrayType($tipo);
        return $this->crearArregloDefectoRec($parsed['dims'], $parsed['base']);
    }

    private function crearArregloDefectoRec(array $dims, string $base): mixed
    {
        if (empty($dims)) {
            return TiposSistema::valorDefecto($base);
        }
        $size = array_shift($dims);
        $arr  = [];
        for ($i = 0; $i < $size; $i++) {
            $arr[] = $this->crearArregloDefectoRec($dims, $base);
        }
        return $arr;
    }

    protected function construirArregloDesdeLiteral($literalValueCtx, string $fullTipo): array
    {
        $parsed     = $this->parseArrayType($fullTipo);
        $dims       = $parsed['dims'];
        $base       = $parsed['base'];

        if (empty($dims)) return [];

        $currentDim = array_shift($dims);
        $subTipo    = !empty($dims)
            ? implode('', array_map(fn($d) => "[$d]", $dims)) . $base
            : $base;

        $valor         = [];
        $elementListCtx = $literalValueCtx->elementList();
        $elementosCtx  = $elementListCtx !== null ? $elementListCtx->elemento() : [];

        for ($i = 0; $i < $currentDim; $i++) {
            if ($i < count($elementosCtx)) {
                $elemCtx = $elementosCtx[$i];
                if ($elemCtx->expr() !== null) {
                    $res        = $this->visit($elemCtx->expr());
                    $valor[$i]  = ValueFormatter::castear($res, $subTipo);
                } elseif ($elemCtx->literalValue() !== null) {
                    $valor[$i]  = $this->construirArregloDesdeLiteral($elemCtx->literalValue(), $subTipo);
                }
            } else {
                $valor[$i] = $this->crearValorDefecto($subTipo);
            }
        }
        return $valor;
    }

    // ==============================================================
    // EJECUCIÓN DE FUNCIONES
    // ==============================================================

    protected function ejecutarFuncion(Symbol $fn, array $args): Result
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
}