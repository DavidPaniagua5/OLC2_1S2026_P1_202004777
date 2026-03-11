<?php

namespace App\BuiltIn;

use App\Env\{Result, ManejadorErrores};

/**
 * Interfaz base para todas las funciones built-in.
 */
interface IBuiltInFunction
{
    /**
     * Ejecuta la función built-in.
     * @param array<Result> $args Argumentos evaluados
     * @return Result Resultado de la ejecución
     */
    public function execute(array $args, ManejadorErrores $errores): Result;

    /**
     * Obtiene el nombre de la función.
     */
    public function getNombre(): string;

    /**
     * Obtiene el número mínimo de argumentos requeridos.
     */
    public function getMinArgs(): int;

    /**
     * Obtiene el número máximo de argumentos permitidos.
     * Devuelve -1 si es variable.
     */
    public function getMaxArgs(): int;
}

/**
 * Registro centralizado de funciones built-in.
 * Patrón Singleton para acceso global.
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
// IMPLEMENTACIONES DE FUNCIONES BUILT-IN
// ===================================================================

class FmtPrintln implements IBuiltInFunction
{
    public function execute(array $args, ManejadorErrores $errores): Result
    {
        // Implementación delegada al visitor
        return Result::nulo();
    }

    public function getNombre(): string
    {
        return 'fmt.Println';
    }

    public function getMinArgs(): int
    {
        return 0;
    }

    public function getMaxArgs(): int
    {
        return -1; // Variable
    }
}

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

    public function getNombre(): string
    {
        return 'len';
    }

    public function getMinArgs(): int
    {
        return 1;
    }

    public function getMaxArgs(): int
    {
        return 1;
    }
}

class Now implements IBuiltInFunction
{
    public function execute(array $args, ManejadorErrores $errores): Result
    {
        return new Result(Result::STRING, date('Y-m-d H:i:s'));
    }

    public function getNombre(): string
    {
        return 'now';
    }

    public function getMinArgs(): int
    {
        return 0;
    }

    public function getMaxArgs(): int
    {
        return 0;
    }
}

class Substr implements IBuiltInFunction
{
    public function execute(array $args, ManejadorErrores $errores): Result
    {
        if (count($args) !== 3) {
            $errores->agregar('Semántico', 'substr() requiere exactamente 3 argumentos.');
            return Result::nulo();
        }

        $cadena = $args[0];
        $inicio = $args[1];
        $largo  = $args[2];

        $resultado = mb_substr(
            (string)$cadena->valor,
            (int)$inicio->valor,
            (int)$largo->valor
        );
        return new Result(Result::STRING, $resultado);
    }

    public function getNombre(): string
    {
        return 'substr';
    }

    public function getMinArgs(): int
    {
        return 3;
    }

    public function getMaxArgs(): int
    {
        return 3;
    }
}

class TypeOf implements IBuiltInFunction
{
    public function execute(array $args, ManejadorErrores $errores): Result
    {
        if (count($args) !== 1) {
            $errores->agregar('Semántico', 'typeOf() requiere exactamente 1 argumento.');
            return Result::nulo();
        }

        return new Result(Result::STRING, $args[0]->tipo);
    }

    public function getNombre(): string
    {
        return 'typeOf';
    }

    public function getMinArgs(): int
    {
        return 1;
    }

    public function getMaxArgs(): int
    {
        return 1;
    }
}