<?php

namespace App\BuiltIn;

use App\Env\{Result, ManejadorErrores};

/**
 * Interfaz base para todas las funciones built-in.
 */
interface IBuiltInFunction
{
    public function execute(array $args, ManejadorErrores $errores): Result;
    public function getNombre(): string;
    public function getMinArgs(): int;
    public function getMaxArgs(): int;
}

/**
 * Registro centralizado de funciones built-in (Singleton).
 */
class BuiltInRegistry
{
    private static ?self $instancia = null;
    /** @var array<string, IBuiltInFunction> */
    private array $funciones = [];

    private function __construct()
    {
        $this->registrarBuiltIns();
    }

    public static function getInstance(): self
    {
        if (self::$instancia === null) {
            self::$instancia = new self();
        }
        return self::$instancia;
    }

    private function registrarBuiltIns(): void
    {
        $this->registrar(new FmtPrintln());
        $this->registrar(new Len());
        $this->registrar(new Now());
        $this->registrar(new Substr());
        $this->registrar(new TypeOf());
    }

    public function registrar(IBuiltInFunction $fn): void
    {
        $this->funciones[$fn->getNombre()] = $fn;
    }

    public function existe(string $nombre): bool
    {
        return isset($this->funciones[$nombre]);
    }

    public function obtener(string $nombre): ?IBuiltInFunction
    {
        return $this->funciones[$nombre] ?? null;
    }

    /** @return array<string, IBuiltInFunction> */
    public function obtenerTodas(): array
    {
        return $this->funciones;
    }
}

// ===================================================================
// IMPLEMENTACIONES
// ===================================================================

class FmtPrintln implements IBuiltInFunction
{
    public function execute(array $args, ManejadorErrores $errores): Result
    {
        return Result::nulo();
    }

    public function getNombre(): string  { return 'fmt.Println'; }
    public function getMinArgs(): int    { return 0; }
    public function getMaxArgs(): int    { return -1; }
}

/**
 * len(s) → int32
 * Acepta: string, arreglo (cualquier dimensión).
 */
class Len implements IBuiltInFunction
{
    public function execute(array $args, ManejadorErrores $errores): Result
    {
        if (count($args) !== 1) {
            $errores->agregar('Semántico', 'len() requiere exactamente 1 argumento.');
            return Result::nulo();
        }

        $arg = $args[0];

        if ($arg->tipo === Result::STRING) {
            return new Result(Result::INT32, mb_strlen((string)$arg->valor));
        }

        if (is_array($arg->valor)) {
            return new Result(Result::INT32, count($arg->valor));
        }

        $errores->agregar('Semántico', "len() requiere string o arreglo, se obtuvo '{$arg->tipo}'.");
        return Result::nulo();
    }

    public function getNombre(): string  { return 'len'; }
    public function getMinArgs(): int    { return 1; }
    public function getMaxArgs(): int    { return 1; }
}

/**
 * now() -> string  (YYYY-MM-DD HH:MM:SS)
 */
class Now implements IBuiltInFunction
{
    public function execute(array $args, ManejadorErrores $errores): Result
    {
        $tz = ini_get('date.timezone');
        date_default_timezone_set($tz);
        return new Result(Result::STRING, date('Y-m-d H:i:s'));
    }

    public function getNombre(): string  { return 'now'; }
    public function getMinArgs(): int    { return 0; }
    public function getMaxArgs(): int    { return 0; }
}

/**
 * substr(cadena, inicio, longitud) → string
 */
class Substr implements IBuiltInFunction
{
    public function execute(array $args, ManejadorErrores $errores): Result
    {
        if (count($args) !== 3) {
            $errores->agregar('Semántico', 'substr() requiere exactamente 3 argumentos.');
            return Result::nulo();
        }

        $cadena = (string)$args[0]->valor;
        $inicio = (int)$args[1]->valor;
        $largo  = (int)$args[2]->valor;

        if ($inicio < 0 || $largo < 0 || $inicio + $largo > mb_strlen($cadena)) {
            $errores->agregar('Semántico', 'substr(): índices inválidos o fuera de rango.');
            return Result::nulo();
        }

        return new Result(Result::STRING, mb_substr($cadena, $inicio, $largo));
    }

    public function getNombre(): string  { return 'substr'; }
    public function getMinArgs(): int    { return 3; }
    public function getMaxArgs(): int    { return 3; }
}

/**
 * typeOf(expr) → string
 * Retorna el nombre del tipo del argumento tal como lo maneja el intérprete.
 */
class TypeOf implements IBuiltInFunction
{
    public function execute(array $args, ManejadorErrores $errores): Result
    {
        if (count($args) !== 1) {
            $errores->agregar('Semántico', 'typeOf() requiere exactamente 1 argumento.');
            return Result::nulo();
        }

        $tipo = $args[0]->tipo;

        // Normalizar tipos de arreglo para presentación
        if (str_starts_with($tipo, '[')) {
            return new Result(Result::STRING, $tipo);
        }

        return new Result(Result::STRING, $tipo);
    }

    public function getNombre(): string  { return 'typeOf'; }
    public function getMinArgs(): int    { return 1; }
    public function getMaxArgs(): int    { return 1; }
}