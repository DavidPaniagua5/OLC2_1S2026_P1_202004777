<?php
namespace App\Visitors;

use App\Env\ManejadorErrores;
use Context\ProgramaContext;
use Context\FuncDeclContext;
use Context\BloqueContext;
use Context\SentenciaExprContext;
use Context\ExprFmtPrintlnContext;
use Context\ExprLiteralContext;
use Context\LiteralEnteroContext;
use Context\ExprAditivaContext;
use Context\ExprMultiplicativaContext;
use Context\ExprAgrupadaContext;
use Context\ExprNegacionContext;

class CodeGenerator extends \GrammarBaseVisitor
{
    private ManejadorErrores $errores;
    private array $data       = [];
    private array $text       = [];
    private array $rutinas    = [];
    private int   $labelCount = 0;
    private bool  $printIntDef = false;
    private int  $strCount  = 0;
    private array $stackVars = [];
    private int  $stackSize  = 0;
    private array $varOffset  = []; 
    private int   $frameSize  = 0;
    private int $tempStack = 0;
    private string $lastType = 'int';
    private array $loopInicioStack = [];
    private array $loopFinStack    = [];
    private bool $printFloatDef = false;
    private array $varTypes = [];
    private array $funcParams   = [];
    private array $funcRetTipos = [];
    private string $funcActual  = '';
    private array $multiRetVars = [];
    private int $frameActual = 0;
    private array $arrayInfo = [];
    private string $labelFinFuncion = '';
    private int    $lastRetArrayOffset = 0;
    private int    $lastRetArraySize   = 0;


    public function __construct(ManejadorErrores $errores)
    {
        $this->errores = $errores;
    }
    private function pushReg(string $reg): void
    {
        $this->text[] = "    str {$reg}, [sp, #-16]!";
        $this->tempStack++;
    }

    private function popReg(string $reg): void
    {
        $this->text[] = "    ldr {$reg}, [sp], #16";
        $this->tempStack--;
    }

    private function iniciarFrame(): void
    {
        $this->varOffset  = [];
        $this->varTypes   = [];
        $this->arrayInfo  = [];
        $this->frameSize  = 0;
        $this->frameActual = 0;
    }

private function reservarVar(string $nombre, string $tipo = 'int32'): int
    {
        $this->varTypes[$nombre] = $tipo;
        $this->frameSize += 16;
        $offset = -$this->frameSize;          // negativo desde x29
        $this->varOffset[$nombre] = $offset;
        return $offset;
    }

private function reservarArray(string $nombre, int $size, string $tipoBase): int
{
    $bytes = (int)(ceil(($size * 4) / 16) * 16);
    $this->frameSize += $bytes;
    $offset = -$this->frameSize;   // offset del elemento [0] — el más negativo
    $this->varOffset[$nombre]  = $offset;
    $this->varTypes[$nombre]   = "[{$size}]{$tipoBase}";
    $this->arrayInfo[$nombre]  = [
        'size'   => $size,
        'tipo'   => $tipoBase,
        'offset' => $offset,
    ];
    return $offset;
}

    private function offsetVar(string $nombre): ?int
    {
        return $this->varOffset[$nombre] ?? null;
    }
private function emitStr(string $reg, int $offset): void
{
    if ($offset >= -256 && $offset <= 255) {
        $this->text[] = "    str {$reg}, [x29, #{$offset}]";
    } else {
        // offset fuera de rango: calcular dirección en x9
        $abs = abs($offset);
        $this->text[] = "    sub x9, x29, #{$abs}";
        $this->text[] = "    str {$reg}, [x9]";
    }
}

private function emitLdr(string $reg, int $offset): void
{
    if ($offset >= -256 && $offset <= 255) {
        $this->text[] = "    ldr {$reg}, [x29, #{$offset}]";
    } else {
        $abs = abs($offset);
        $this->text[] = "    sub x9, x29, #{$abs}";
        $this->text[] = "    ldr {$reg}, [x9]";
    }
}

private function emitStrOffset(string $reg, int $offset, int $extra = 0): void
{
    $real = $offset + $extra;
    $this->emitStr($reg, $real);
}

private function emitLdrOffset(string $reg, int $offset, int $extra = 0): void
{
    $real = $offset + $extra;
    $this->emitLdr($reg, $real);
}

    private function newLabel(string $prefix): string
    {
        return $prefix . '_' . ($this->labelCount++);
    }

    public function visitPrograma(ProgramaContext $ctx): string
    {
        $this->data[] = '.section .data';
        $this->data[] = '    newline: .ascii "\\n"';
        $this->data[] = '    str_true:  .ascii "true"';
        $this->data[] = '    str_true_len  = . - str_true';
        $this->data[] = '    str_false: .ascii "false"';
        $this->data[] = '    str_false_len = . - str_false';
        $this->data[] = '    fmt_float: .ascii "%.6g\\n"';
        $this->data[] = '    str_nil: .ascii "<nil>"';
        $this->data[] = '    str_nil_len = 5';


        $this->text[] = '.section .text';
        $this->text[] = '.align 2';
        $this->text[] = '.global _start';

        foreach ($ctx->declaracionTop() as $decl) {
            if ($decl->funcDecl() !== null) {
                $this->registrarFuncion($decl->funcDecl());
            }
        }
        foreach ($ctx->declaracionTop() as $decl) {
            if ($decl->funcDecl() !== null) {
                $this->visitFuncDecl($decl->funcDecl());
            }
        }
        $this->definirRutinaPrintInt();
        return $this->construirCodigo();
    }

    private function construirCodigo(): string
    {
        $lineas = [];

        foreach ($this->data as $l) {
            $lineas[] = $l;
        }

        $lineas[] = '';

        foreach ($this->text as $l) {
            $lineas[] = $l;
        }

        if (!empty($this->rutinas)) {
            $lineas[] = '';
            foreach ($this->rutinas as $l) {
                $lineas[] = $l;
            }
        }

        return implode("\n", $lineas) . "\n";
    }

private function registrarFuncion(\Context\FuncDeclContext $ctx): void
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

    $this->funcParams[$nombre] = $params;

    $tipoRet = 'void';
    if ($ctx->tipoRetorno() !== null) {
        $tipoRet = $ctx->tipoRetorno()->getText();
    }
    $this->funcRetTipos[$nombre] = $tipoRet;
}

/*
-----------------------------------------------------------------------------
CALIFICACION
-----------------------------------------------------------------------------
*/
public function visitExprXor(\Context\ExprXorContext $ctx):string{
    $this->visit($ctx->expr(0));
    $this->pushReg('x0');
    
    $this->visit($ctx->expr(1));
    $this->text[] = '   mov x1,x0';
    $this->popReg('x0');

    $this->text[] = '   eor x0, x0, x1';
    $this->lastType = 'bool';
    return 'x0';
}


public function visitFuncDecl(\Context\FuncDeclContext $ctx): void
{
    $nombre = $ctx->ID()->getText();
    $label  = $nombre === 'main' ? '_start' : $nombre;
    $labelFin = $nombre . '_fin';

    $this->iniciarFrame();
    $this->funcActual = $nombre;
    $this->labelFinFuncion = $labelFin;

    $params = $this->funcParams[$nombre] ?? [];

    foreach ($params as $i => $param) {
        $tipo   = $param['tipo'];
        $offset = $this->reservarVar($param['id'], $tipo);
        // Guardar parámetro del registro al stack
        if ($tipo === 'float32') {

        }
    }
    $tipoRet = $this->funcRetTipos[$nombre] ?? 'void';
    $numVarsLocales = $this->contarVariablesRecursivo($ctx->bloque());
    $bytesLocales   = $numVarsLocales * 16;
    $frameBytes     = (int)(ceil(($this->frameSize + $bytesLocales) / 16) * 16);
    $frameBytes     = max(128, $frameBytes);
    $this->frameActual = $frameBytes;

    $this->text[] = '';
    $this->text[] = "{$label}:";

    // PRÓLOGO — siempre igual, sin importar frameBytes
    $this->text[] = "    stp x29, x30, [sp, #-16]!";
    $this->text[] = "    mov x29, sp";
    $this->text[] = "    sub sp, sp, #{$frameBytes}";

    // Guardar parámetros al stack
    foreach ($params as $i => $param) {
        $offset = $this->varOffset[$param['id']] ?? null;
        if ($offset === null) continue;
        $tipo = $param['tipo'];
        if ($tipo === 'float32') {
            $this->emitStr("s{$i}", $offset);
        } elseif (str_starts_with($tipo, '*')) {
            $this->emitStr("x{$i}", $offset);
        } else {
            $this->emitStr("x{$i}", $offset);
        }
    }

    $this->visitBloqueGen($ctx->bloque());

    // EPÍLOGO — espejo exacto del prólogo
    $this->text[] = "{$labelFin}:";
    $this->text[] = "    add sp, sp, #{$frameBytes}";
    $this->text[] = "    ldp x29, x30, [sp], #16";

    if ($nombre === 'main') {
        $this->text[] = '    mov x0, #0';
        $this->text[] = '    mov x8, #93';
        $this->text[] = '    svc #0';
    } else {
        $this->text[] = '    ret';
    }
}

private function contarVariablesRecursivo(\Context\BloqueContext $ctx): int
{
    $count = 0;
    foreach ($ctx->sentencia() as $sent) {
        // Variables directas
        if ($sent->varDecl() !== null) {
            $count += count($sent->varDecl()->listaIds()->ID());
        } elseif ($sent->declCorta() !== null) {
            $count += count($sent->declCorta()->listaIds()->ID());
        } elseif ($sent->constDecl() !== null) {
            $count++;
        }

        // Recursión en bloques anidados — usar instanceof con las clases reales
        foreach ($sent->children ?? [] as $child) {
            if ($child instanceof \Context\SentenciaIfContext) {
                foreach ($child->bloque() as $b) {
                    $count += $this->contarVariablesRecursivo($b);
                }
                if ($child->sentenciaIf() !== null) {
                    // else if anidado — no tiene bloque propio aquí, se maneja recursivamente
                }
            } elseif ($child instanceof \Context\ForClassicoContext) {
                $count++; // variable del init
                $count += $this->contarVariablesRecursivo($child->bloque());
            } elseif ($child instanceof \Context\ForWhileContext) {
                $count += $this->contarVariablesRecursivo($child->bloque());
            } elseif ($child instanceof \Context\ForInfinitoContext) {
                $count += $this->contarVariablesRecursivo($child->bloque());
            } elseif ($child instanceof \Context\SentenciaSwitchContext) {
                foreach ($child->casoSwitch() as $caso) {
                    foreach ($caso->sentencia() as $s) {
                        // sentencias dentro de cases no tienen bloque propio
                    }
                }
            }
        }
    }
    return $count;
}

public function visitExprArregloLiteral(\Context\ExprArregloLiteralContext $ctx): string
{
    $alCtx = $ctx->arregloLiteral();
    $size  = (int)$alCtx->INT_LIT()->getText();
    $tipo  = $alCtx->tipo()->getText();
    $this->lastType = "[{$size}]{$tipo}";
    return "__array__{$size}__{$tipo}";
}

public function visitExprLlamada(\Context\ExprLlamadaContext $ctx): string
{
    $nombre = $ctx->ID()->getText();
    $args   = $ctx->listaExpr() !== null ? $ctx->listaExpr()->expr() : [];

    // Built-ins
    switch ($nombre) {
        case 'len':
            $this->lastType = 'int';
            $this->visit($args[0]);

            if ($this->lastType === 'string') {
                $this->text[] = '    mov x0, x1';
            } elseif ($this->lastType === '__array__' || str_starts_with($this->lastType, '[')) {
                // Ya está en x0 desde visitExprId que puso el size
                // No hacer nada, x0 tiene el size
            } else {
                $nombreVar = $args[0]->getText();
                $info = $this->arrayInfo[$nombreVar] ?? null;
                if ($info !== null) {
                    $this->text[] = "    mov x0, #{$info['size']}";
                }
            }
            $this->text[] = '    str x0, [sp, #-16]!';
            $this->lastType = 'int';
            return 'x0';

        case 'now':
            $fecha = date('Y-m-d H:i:s');
            $label = '__str_' . $this->strCount++;
            $len   = strlen($fecha);
            $this->data[] = "    {$label}: .ascii \"{$fecha}\"";
            $this->data[] = "    {$label}_len = {$len}";
            $this->text[] = "    adrp x0, {$label}";
            $this->text[] = "    add  x0, x0, :lo12:{$label}";
            $this->text[] = "    mov  x1, #{$len}";
            $this->lastType = 'string';
            return 'x0';

        case 'typeOf':
            $this->lastType = 'int';
            $this->visit($args[0]);
            $tipo  = $this->lastType;
            $label = '__str_' . $this->strCount++;
            $len   = strlen($tipo);
            $this->data[] = "    {$label}: .ascii \"{$tipo}\"";
            $this->data[] = "    {$label}_len = {$len}";
            $this->text[] = "    adrp x0, {$label}";
            $this->text[] = "    add  x0, x0, :lo12:{$label}";
            $this->text[] = "    mov  x1, #{$len}";
            $this->lastType = 'string';
            return 'x0';

        case 'substr':
            // substr(str, inicio, longitud)
            // x0=dir, x1=len del string, x2=inicio, x3=largo
            $this->visit($args[0]);
            $this->pushReg('x0');
            $this->pushReg('x1');
            $this->visit($args[1]);
            $inicio = 'x0';
            $this->pushReg('x0');
            $this->visit($args[2]);
            $largo = 'x0';
            $this->popReg('x2');   // inicio
            $this->popReg('x1');   // longitud original (no usada)
            $this->popReg('x0');   // dirección base
            $this->text[] = '    add x0, x0, x2';  // dir + inicio
            $this->text[] = '    mov x1, x0';
            $this->text[] = "    mov x2, {$largo}"; // no funciona bien, fix:
            $this->popReg('x0');
            // Reimplementar correctamente:
            $this->visit($args[0]);           // x0=dir, x1=len
            $this->text[] = '    mov x9, x0'; // guardar dir
            $this->visit($args[1]);           // x0=inicio
            $this->text[] = '    mov x10, x0';
            $this->visit($args[2]);           // x0=largo
            $this->text[] = '    mov x11, x0';
            $this->text[] = '    add x0, x9, x10'; // dir + inicio
            $this->text[] = '    mov x1, x11';     // longitud
            $this->lastType = 'string';
            return 'x0';
    }
    $params = $this->funcParams[$nombre] ?? [];
    // Evaluar todos los argumentos y apilarlos para no sobreescribir registros
    $tiposArgs = [];
    foreach ($args as $i => $arg) {
    $this->lastType = 'int';
    $this->visit($arg);
    
    $tipoParam = $params[$i]['tipo'] ?? 'int32';
    $esFloat   = ($this->lastType === 'float32' || $tipoParam === 'float32');
    $esArreglo = str_starts_with($this->lastType, '[');
    
    $tiposArgs[] = $esFloat;
    
    if ($esArreglo) {
        // La dirección base quedó en x9, moverla a x0 y pushear UNA sola vez
        $this->text[] = '    mov x0, x9';
        $this->text[] = '    str x0, [sp, #-16]!';
    } elseif ($esFloat) {
        $this->text[] = '    str s0, [sp, #-16]!';
    } else {
        $this->text[] = '    str x0, [sp, #-16]!';
    }
}
    // Recuperar en orden inverso → los registros quedan en orden correcto
    foreach (array_reverse(array_keys($tiposArgs)) as $i) {
        $esFloat = $tiposArgs[$i];
        if ($esFloat) {
            $this->text[] = "    ldr s{$i}, [sp], #16";
        } else {
            $this->text[] = "    ldr x{$i}, [sp], #16";
        }
    }
   
$tipoRet = $this->funcRetTipos[$nombre] ?? 'void';

if (preg_match('/^\[(\d+)\](.+)$/', $tipoRet, $m)) {
    $size      = (int)$m[1];
    $retLabel  = '__ret_' . $nombre . '_' . $this->labelCount++;
    $offsetRet = $this->reservarVar($retLabel, $tipoRet);
    $abs       = abs($offsetRet);
    if ($abs <= 4095) {
        $this->text[] = "    sub x8, x29, #{$abs}";
    } else {
        $this->text[] = "    mov x15, #{$abs}";
        $this->text[] = "    sub x8, x29, x15";
    }
    $this->lastRetArrayOffset = -$abs;
    $this->lastRetArraySize   = $size;
}

$this->text[] = "    bl {$nombre}";

if (preg_match('/^\[(\d+)\](.+)$/', $tipoRet, $m)) {
    // x0 ya tiene la dirección (la función la puso en x0 = x8)
    // Guardar en x9 para acceso indexado posterior
    $this->text[] = '    mov x9, x0';
    $this->lastType = $tipoRet;
    return 'x0';
}

 //   $this->text[] = "    bl {$nombre}";

    $tipoRet = $this->funcRetTipos[$nombre] ?? 'void';
    if ($tipoRet === 'float32') {
        $this->lastType = 'float32';
        return 'float';
    }

    $this->lastType = 'int';
    return 'x0';
}

public function visitSentenciaReturn(\Context\SentenciaReturnContext $ctx): mixed
{
    if ($ctx->listaExpr() === null) {
        $this->text[] = "    b {$this->labelFinFuncion}";
        return null;
    }

    $exprs = $ctx->listaExpr()->expr();

if (count($exprs) === 1) {
    $this->lastType = 'int';
    $this->visit($exprs[0]);

    // Detectar si el retorno es un arreglo
    $tipoRet = $this->funcRetTipos[$this->funcActual] ?? 'void';
    
    if (preg_match('/^\[(\d+)\](.+)$/', $tipoRet, $m)) {
    $size = (int)$m[1];
    $subTipo = $m[2];
    
    // Calcular total de elementos recursivamente
    $totalElementos = $this->calcularTotalElementos($tipoRet);
    
    for ($k = 0; $k < $totalElementos; $k++) {
        $srcOff = $k * 4;
        $this->text[] = "    ldr w0, [x9, #{$srcOff}]";
        $this->text[] = "    str w0, [x8, #{$srcOff}]";
    }
    $this->text[] = '    mov x0, x8';
    $this->text[] = "    b {$this->labelFinFuncion}";
    return null;
}

    // Retorno normal
    $this->text[] = "    b {$this->labelFinFuncion}";
    return null;
}

    // Múltiples retornos
    foreach ($exprs as $i => $expr) {
        $this->lastType = 'int';
        $this->visit($expr);
        $tmpOffset = 200 + ($i * 8);
        //$this->text[] = "    str x0, [x29, #{$tmpOffset}]";
        $this->emitStr('x0', $tmpOffset);

    }
    foreach ($exprs as $i => $_) {
        $tmpOffset = 200 + ($i * 8);
        //$this->text[] = "    ldr x{$i}, [x29, #{$tmpOffset}]";
        $this->emitLdr("x{$i}", $tmpOffset);

    }
    $this->text[] = "    b {$this->labelFinFuncion}";
    return null;
}

private function visitBloqueConControl(
    BloqueContext $ctx,
    string $labelInicio,
    string $labelFin
): void {
    array_push($this->loopInicioStack, $labelInicio);
    array_push($this->loopFinStack, $labelFin);

    foreach ($ctx->sentencia() as $sent) {
        $this->visit($sent);
    }

    array_pop($this->loopInicioStack);
    array_pop($this->loopFinStack);
}

private function calcularTotalElementos(string $tipo): int
{
    if (preg_match('/^\[(\d+)\](.+)$/', $tipo, $m)) {
        $size    = (int)$m[1];
        $subTipo = $m[2];
        return $size * $this->calcularTotalElementos($subTipo);
    }
    // Tipo escalar
    return 1;
}

private function emitArg(string $op, string $dst, string $base, int $offset): void
{
    if ($offset >= 0 && $offset <= 4095) {
        $this->text[] = "    {$op} {$dst}, {$base}, #{$offset}";
    } else {
        $this->text[] = "    mov x15, #{$offset}";
        $this->text[] = "    {$op} {$dst}, {$base}, x15";
    }
}

private function contarVariables(BloqueContext $ctx): int
{
    $count = 0;
    foreach ($ctx->sentencia() as $sent) {
        if ($sent->varDecl() !== null) {
            $count += count($sent->varDecl()->listaIds()->ID());
        } elseif ($sent->declCorta() !== null) {
            $count += count($sent->declCorta()->listaIds()->ID());
        } elseif ($sent->constDecl() !== null) {
            $count++;
        }
    }
    return $count;
}

public function visitExprId(\Context\ExprIdContext $ctx): string
{
    $nombre = $ctx->ID()->getText();
    $offset = $this->offsetVar($nombre);
    $tipo   = $this->varTypes[$nombre] ?? 'int32';

    if ($offset !== null) {
        $this->lastType = match($tipo) {
            'string'  => 'string',
            'bool'    => 'bool',
            'float32' => 'float32',
            'rune'    => 'int',
            default   => 'int',
        };

    // En visitExprId, agrega antes del ldr normal:
       $info = $this->arrayInfo[$nombre] ?? null;
        if ($info !== null) {
            $size       = $info['size'];
            $offset     = $info['offset'];
            $baseOffset = $offset;
            
            // Poner dirección base en x9 (para return y para len)
            $this->emitArg('add', 'x9', 'x29', $baseOffset);
            $this->text[] = "    mov x0, #{$size}";  // size en x0 para len()
            $this->lastType = "[{$size}]{$info['tipo']}";
            return 'x9';   // ← retornar x9 que tiene la dirección
        }            

        if ($tipo === 'float32') {
            $this->emitLdr('s0', $offset);
            $this->lastType = 'float32';
            return 'float';
        }elseif ($tipo === 'string') {
            $this->emitLdr('x0', $offset);
            $this->emitLdr('x1', $offset + 8);
        } else {
            $this->emitLdr('x0', $offset);
        }
    } else {
        $this->text[] = "    mov x0, #0";
        $this->lastType = 'int';
        $this->errores->agregar('Semántico', "Variable '{$nombre}' no declarada.");
    }

    return 'x0';
}

public function visitVarDecl(\Context\VarDeclContext $ctx): mixed
{
    $ids   = $ctx->listaIds()->ID();
    $exprs = $ctx->listaExpr() !== null ? $ctx->listaExpr()->expr() : [];
    $tipo  = $ctx->tipo() !== null ? $ctx->tipo()->getText() : 'int32';

    foreach ($ids as $i => $nodoId) {
        $nombre = $nodoId->getText();

        // Detectar si es arreglo
        if (preg_match('/^\[(\d+)\](.+)$/', $tipo, $m)) {
            $size     = (int)$m[1];
            $tipoBase = $m[2];
            $offset   = $this->reservarArray($nombre, $size, $tipoBase);

            if (isset($exprs[$i])) {
                $this->generarLiteralArreglo($exprs[$i], $nombre, $size, $tipoBase, $offset);
            } else {
                // Inicializar con ceros
                for ($k = 0; $k < $size; $k++) {
                    $elemOffset = $offset + $k * 4;   // offset ya apunta a elemento [0], stride 4 bytes
                    $this->emitStr('xzr', $elemOffset);
                }
            }
            continue;
        }

        $offset = $this->reservarVar($nombre, $tipo);

        if (isset($exprs[$i])) {
            $this->lastType = 'int';
            $this->visit($exprs[$i]);

            if ($this->lastType === 'string') {
                $this->emitStr('x0', $offset);
                $this->emitStr('x1', $offset + 8);
            } elseif ($this->lastType === 'float32') {
                $this->emitStr('s0', $offset);
            } else {
                $this->emitStr('x0', $offset);
            }
        } else {
            $this->emitStr('xzr', $offset);
            if ($tipo === 'string') {
                $this->emitStr('xzr', $offset + 8);
            }
        }
    }
    return null;
}

private function generarLiteralArreglo($exprCtx, string $nombre, int $size, string $tipoBase, int $offset): void
{
    $alCtx    = $exprCtx->arregloLiteral();
    $elemList = $alCtx->literalValue()->elementList();
    $elementos = $elemList !== null ? $elemList->elemento() : [];

    if (preg_match('/^\[(\d+)\](.+)$/', $tipoBase, $m)) {
        $subSize = (int)$m[1];
        $subTipo = $m[2];

        // Calcular cuántos int32 ocupa cada sub-elemento completo
        $subElemSize = $this->calcularTamanoElementos($subTipo, $subSize);

        for ($k = 0; $k < $size; $k++) {
            $subOffset = $offset + $k * $subElemSize * 4;  // ← stride correcto

            if ($k < count($elementos)) {
                $elem = $elementos[$k];
                if ($elem->literalValue() !== null) {
                    $this->generarLiteralArregloDesdeValor(
                        $elem->literalValue(), $subSize, $subTipo, $subOffset
                    );
                }
            } else {
                for ($j = 0; $j < $subElemSize; $j++) {
                    $this->emitStr('xzr', $subOffset + $j * 4);
                }
            }
        }
        return;
    }

    // 1D — igual que antes
    for ($k = 0; $k < $size; $k++) {
        $elemOffset = $offset + $k * 4;
        if ($k < count($elementos)) {
            $this->lastType = 'int';
            $this->visit($elementos[$k]->expr());
            if ($tipoBase === 'float32') {
                $this->emitStr('s0', $elemOffset);
            } else {
                $this->emitStr('x0', $elemOffset);
            }
        } else {
            $this->emitStr('xzr', $elemOffset);
        }
    }
}

private function calcularTamanoElementos(string $tipo, int $size): int
{
    if (preg_match('/^\[(\d+)\](.+)$/', $tipo, $m)) {
        $subSize = (int)$m[1];
        $subTipo = $m[2];
        return $size * $this->calcularTamanoElementos($subTipo, $subSize);
    }
    return $size;
}

private function generarLiteralArregloDesdeValor($literalValueCtx, int $size, string $tipoBase, int $offset): void
{
    $elemList  = $literalValueCtx->elementList();
    $elementos = $elemList !== null ? $elemList->elemento() : [];

    // Si tipoBase es a su vez un arreglo, calcular stride correcto
    if (preg_match('/^\[(\d+)\](.+)$/', $tipoBase, $m)) {
        $subSize = (int)$m[1];
        $subTipo = $m[2];
        $subElemSize = $this->calcularTamanoElementos($subTipo, $subSize);

        for ($k = 0; $k < $size; $k++) {
            $subOffset = $offset + $k * $subElemSize * 4;
            if ($k < count($elementos)) {
                $elem = $elementos[$k];
                if ($elem->literalValue() !== null) {
                    $this->generarLiteralArregloDesdeValor(
                        $elem->literalValue(), $subSize, $subTipo, $subOffset
                    );
                } elseif ($elem->expr() !== null) {
                    $this->visit($elem->expr());
                    $this->emitStr('x0', $subOffset);
                }
            } else {
                for ($j = 0; $j < $subElemSize; $j++) {
                    $this->emitStr('xzr', $subOffset + $j * 4);
                }
            }
        }
        return;
    }

    // 1D — igual que antes
    for ($k = 0; $k < $size; $k++) {
        $elemOffset = $offset + $k * 4;
        if ($k < count($elementos)) {
            $elem = $elementos[$k];
            if ($elem->expr() !== null) {
                $this->lastType = 'int';
                $this->visit($elem->expr());
                $this->emitStr('x0', $elemOffset);
            } elseif ($elem->literalValue() !== null) {
                $this->generarLiteralArregloDesdeValor(
                    $elem->literalValue(), $size, $tipoBase, $elemOffset
                );
            }
        } else {
            $this->emitStr('xzr', $elemOffset);
        }
    }
}

public function visitExprIndiceArreglo(\Context\ExprIndiceArregloContext $ctx): string
{
    $nombre = $ctx->ID()->getText();
    $info   = $this->arrayInfo[$nombre] ?? null;
    $tipo   = $this->varTypes[$nombre] ?? '';
    $offset = $this->varOffset[$nombre] ?? null;

    $esPuntero = str_starts_with($tipo, '*[') || ($info === null && $offset !== null);

    // Detectar si el tipo base es float32
    $esFloat = str_contains($tipo, 'float32');

    if ($esPuntero) {
        $cols = 1;
        if (preg_match('/^\*?\[(\d+)\]\[(\d+)\]/', $tipo, $mc)) {
            $cols = (int)$mc[2];
        }

        $this->emitLdr('x9', $offset);

        if (count($ctx->expr()) === 3) {
            $cols1 = 1; $cols2 = 1;
            if (preg_match('/^\*?\[(\d+)\]\[(\d+)\]\[(\d+)\]/', $tipo, $mc)) {
                $cols1 = (int)$mc[2];
                $cols2 = (int)$mc[3];
            }
            $this->emitLdr('x9', $offset);
            $this->visit($ctx->expr()[0]);
            $this->text[] = '    mov x13, x0';
            $this->text[] = "    mov x14, #{$cols1}";
            $this->text[] = '    mul x13, x13, x14';
            $this->visit($ctx->expr()[1]);
            $this->text[] = '    add x13, x13, x0';
            $this->text[] = "    mov x14, #{$cols2}";
            $this->text[] = '    mul x13, x13, x14';
            $this->visit($ctx->expr()[2]);
            $this->text[] = '    add x13, x13, x0';
            $this->text[] = '    lsl x10, x13, #2';
            $this->text[] = '    add x9, x9, x10';
            if ($esFloat) {
                $this->text[] = '    ldr s0, [x9]';
                $this->lastType = 'float32';
                return 'float';
            }
            $this->text[] = '    ldr w0, [x9]';
            $this->text[] = '    uxtw x0, w0';
            $this->lastType = 'int';
            return 'x0';
        }

        if (count($ctx->expr()) === 2) {
            $this->visit($ctx->expr()[0]);
            $this->text[] = '    mov x13, x0';
            $this->text[] = "    mov x14, #{$cols}";
            $this->text[] = '    mul x13, x13, x14';
            $this->visit($ctx->expr()[1]);
            $this->text[] = '    add x13, x13, x0';
            $this->text[] = '    lsl x10, x13, #2';
        } else {
            $this->visit($ctx->expr()[0]);
            $this->text[] = '    lsl x10, x0, #2';
        }

        $this->text[] = '    add x9, x9, x10';
        if ($esFloat) {
            $this->text[] = '    ldr s0, [x9]';
            $this->lastType = 'float32';
            return 'float';
        }
        $this->text[] = '    ldr w0, [x9]';
        $this->text[] = '    uxtw x0, w0';
        $this->lastType = 'int';
        return 'x0';
    }

    // Arreglo local 2D
    if (count($ctx->expr()) === 2) {
        $cols = 1;
        if (preg_match('/^\[(\d+)\]\[(\d+)\]/', $info['tipo'] ?? '', $mc)) {
            $cols = (int)$mc[2];
        } elseif (preg_match('/^\[(\d+)\]/', $info['tipo'] ?? '', $mc)) {
            $cols = (int)$mc[1];
        }

        $abs = abs($info['offset']);
        $this->visit($ctx->expr()[0]);
        $this->text[] = '    mov x9, x0';
        $this->text[] = "    mov x10, #{$cols}";
        $this->text[] = '    mul x9, x9, x10';
        $this->visit($ctx->expr()[1]);
        $this->text[] = '    add x9, x9, x0';
        $this->text[] = "    sub x10, x29, #{$abs}";
        $this->text[] = '    lsl x11, x9, #2';
        $this->text[] = '    add x10, x10, x11';
        if ($esFloat) {
            $this->text[] = '    ldr s0, [x10]';
            $this->lastType = 'float32';
            return 'float';
        }
        $this->text[] = '    ldr w0, [x10]';
        $this->text[] = '    uxtw x0, w0';
        $this->lastType = 'int';
        return 'x0';
    }

    // Arreglo local 1D
    $abs = abs($info['offset']);
    $this->visit($ctx->expr()[0]);
    $this->text[] = '    mov x9, x0';
    $this->text[] = "    sub x10, x29, #{$abs}";
    $this->text[] = '    lsl x11, x9, #2';
    $this->text[] = '    add x10, x10, x11';
    if ($esFloat || $info['tipo'] === 'float32') {
        $this->text[] = '    ldr s0, [x10]';
        $this->lastType = 'float32';
        return 'float';
    }
    $this->text[] = '    ldr w0, [x10]';
    $this->text[] = '    uxtw x0, w0';
    $this->lastType = 'int';
    return 'x0';
}

public function visitDeclCorta(\Context\DeclCortaContext $ctx): mixed
{
    $ids   = $ctx->listaIds()->ID();
    $exprs = $ctx->listaExpr()->expr();

    // Si hay múltiples IDs pero una sola expresión (multi-retorno de función)
    if (count($ids) > 1 && count($exprs) === 1) {
        $this->lastType = 'int';
        $this->visit($exprs[0]);
        foreach ($ids as $i => $nodoId) {
            $nombre = $nodoId->getText();
            $offset = $this->reservarVar($nombre, 'int32');
            $this->emitStr("x{$i}", $offset);
        }
        return null;
    }

    // Caso normal: una expr por id
    foreach ($ids as $i => $nodoId) {
        $nombre = $nodoId->getText();
        if (isset($exprs[$i])) {
            $this->lastType = 'int';
            $this->visit($exprs[$i]);
            $tipo = $this->lastType;

            if (preg_match('/^\[(\d+)\](.+)$/', $tipo, $m)) {
                $size     = (int)$m[1];
                $tipoBase = $m[2];
                $exprCtx  = $exprs[$i];

                // Verificar si viene de una llamada a función (tiene buffer ya reservado)
                $esLlamada = ($exprCtx instanceof \Context\ExprLlamadaContext);

                if ($esLlamada) {
                    // Reutilizar el buffer reservado por visitExprLlamada
                    // lastRetArrayOffset tiene el offset negativo del buffer
                    $offsetBuffer = $this->lastRetArrayOffset;
                    $this->varOffset[$nombre] = $offsetBuffer;
                    $this->varTypes[$nombre]  = "[{$size}]{$tipoBase}";
                    $this->arrayInfo[$nombre] = [
                        'size'   => $size,
                        'tipo'   => $tipoBase,
                        'offset' => $offsetBuffer,
                    ];
                } else {
                    // Es un literal de arreglo — reservar espacio nuevo
                    $offset = $this->reservarArray($nombre, $size, $tipoBase);
                    $alCtx  = null;
                    if (method_exists($exprCtx, 'arregloLiteral')) {
                        $alCtx = $exprCtx->arregloLiteral();
                    }
                    if ($alCtx !== null) {
                        $this->generarLiteralArreglo($exprCtx, $nombre, $size, $tipoBase, $offset);
                    }
                }
                continue;
            }

            $offset = $this->reservarVar($nombre, $tipo);

            if ($tipo === 'string') {
                $this->emitStr('x0', $offset);
                $this->emitStr('x1', $offset + 8);
            } elseif ($tipo === 'float32') {
                $this->emitStr('s0', $offset);
            } else {
                $this->emitStr('x0', $offset);
            }
        }
    }
    return null;
}

public function visitConstDecl(\Context\ConstDeclContext $ctx): mixed
{
    $nombre = $ctx->ID()->getText();
    $tipo   = $ctx->tipo()->getText();

    $this->lastType = 'int';
    $this->visit($ctx->expr());

    $tipoReal = $this->lastType !== 'int' ? $this->lastType : $tipo;
    $offset   = $this->reservarVar($nombre, $tipoReal);

    if ($tipoReal === 'float32') {
        $this->emitStr('s0', $offset);
    } elseif ($tipoReal === 'string') {
        $this->emitStr('x0', $offset);
        $this->emitStr('x1', $offset + 8);
    } else {
        $this->emitStr('x0', $offset);
    }

    return null;
}

public function visitAsignacion(\Context\AsignacionContext $ctx): mixed
{
    $lvalues = $ctx->listaLvalue()->lvalue();
    $exprs   = $ctx->listaExpr()->expr();

    foreach ($lvalues as $i => $lv) {
        $nombre     = $lv->ID()->getText();
        $indicesCtx = $lv->expr();
        $esDeref    = ($lv->getChildCount() > 0 && $lv->getChild(0)->getText() === '*');

        if (!empty($indicesCtx)) {
            $info   = $this->arrayInfo[$nombre] ?? null;
            $tipo   = $this->varTypes[$nombre] ?? '';
            $offset = $this->varOffset[$nombre] ?? null;

            // Mismo criterio que visitExprIndiceArreglo
            $esPuntero = str_starts_with($tipo, '*[') || ($info === null && $offset !== null);

            if ($esPuntero) {
                $cols = 1;
                if (preg_match('/^\*?\[(\d+)\]\[(\d+)\]/', $tipo, $mc)) {
                    $cols = (int)$mc[2];
                }

                $this->emitLdr('x11', $offset); // dirección base en x11

                if (count($indicesCtx) === 2) {
                    // 2D: ptr[i][j] = expr
                    $this->visit($indicesCtx[0]);
                    $this->text[] = '    mov x13, x0';
                    $this->text[] = "    mov x14, #{$cols}";
                    $this->text[] = '    mul x13, x13, x14';
                    $this->visit($indicesCtx[1]);
                    $this->text[] = '    add x13, x13, x0';
                    $this->text[] = '    str x13, [sp, #-16]!'; // push índice lineal
                    $this->visit($exprs[$i]);
                    if ($this->lastType === 'float32') {
                        $this->text[] = '    fmov w10, s0';
                    } else {
                        $this->text[] = '    mov x10, x0';
                    }
                    $this->text[] = '    ldr x13, [sp], #16';   // pop índice lineal
                    $this->emitLdr('x11', $offset);              // recargar puntero base
                    $this->text[] = '    lsl x12, x13, #2';
                    $this->text[] = '    add x11, x11, x12';
                    $this->text[] = '    str w10, [x11]';
                    continue;
                } else {
                    // 1D: ptr[i] = expr
                    $this->visit($indicesCtx[0]);
                    $this->text[] = '    mov x9, x0';
                    $this->text[] = '    str x9, [sp, #-16]!';  // push índice
                    $this->visit($exprs[$i]);
                    if ($this->lastType === 'float32') {
                        $this->text[] = '    fmov w10, s0';
                    } else {
                        $this->text[] = '    mov x10, x0';
                    }
                    $this->text[] = '    ldr x9, [sp], #16';    // pop índice
                    $this->emitLdr('x11', $offset);              // recargar puntero base
                    $this->text[] = '    lsl x12, x9, #2';
                    $this->text[] = '    add x11, x11, x12';
                    $this->text[] = '    str w10, [x11]';
                    continue;
                }

            } elseif (count($indicesCtx) === 2) {
                // Arreglo local 2D: local[i][j] = expr
                $cols = 1;
                if (preg_match('/^\[(\d+)\]\[(\d+)\]/', $info['tipo'] ?? '', $mc)) {
                    $cols = (int)$mc[2];
                } elseif (preg_match('/^\[(\d+)\]/', $info['tipo'] ?? '', $mc)) {
                    $cols = (int)$mc[1];
                }

                $abs = abs($info['offset']);
                $this->visit($indicesCtx[0]);
                $this->text[] = '    mov x9, x0';
                $this->text[] = "    mov x10, #{$cols}";
                $this->text[] = '    mul x9, x9, x10';
                $this->visit($indicesCtx[1]);
                $this->text[] = '    add x9, x9, x0';
                $this->text[] = '    str x9, [sp, #-16]!';      // push índice lineal
                $this->visit($exprs[$i]);
                if ($this->lastType === 'float32') {
                    $this->text[] = '    fmov w10, s0';
                } else {
                    $this->text[] = '    mov x10, x0';
                }
                $this->text[] = '    ldr x9, [sp], #16';        // pop índice lineal
                $this->text[] = "    sub x11, x29, #{$abs}";
                $this->text[] = '    lsl x12, x9, #2';
                $this->text[] = '    add x11, x11, x12';
                $this->text[] = '    str w10, [x11]';
                continue;

            } else {
                // Arreglo local 1D: local[i] = expr
                $abs = abs($info['offset']);
                $this->visit($indicesCtx[0]);
                $this->text[] = '    mov x9, x0';
                $this->text[] = '    str x9, [sp, #-16]!';
                $this->visit($exprs[$i]);
                if ($this->lastType === 'float32') {
                    $this->text[] = '    fmov w10, s0';
                } else {
                    $this->text[] = '    mov x10, x0';
                }
                $this->text[] = '    ldr x9, [sp], #16';
                $this->text[] = "    sub x11, x29, #{$abs}";
                $this->text[] = '    lsl x12, x9, #2';
                $this->text[] = '    add x11, x11, x12';
                $this->text[] = '    str w10, [x11]';
                continue;
            }
        }
        if ($esDeref) {
            $offset = $this->offsetVar($nombre);
            $this->visit($exprs[$i]);
            $this->text[] = '    mov x10, x0';
            $this->emitLdr('x9', $offset);
            $this->text[] = '    str x10, [x9]';
            continue;
        }

        // Variable escalar normal
        $offset = $this->offsetVar($nombre);
        $tipo   = $this->varTypes[$nombre] ?? 'int32';
        if ($offset === null) continue;

        $this->lastType = 'int';
        $this->visit($exprs[$i]);

        if ($tipo === 'string' || $this->lastType === 'string') {
            $this->emitStr('x0', $offset);
            $this->emitStr('x1', $offset + 8);
        } elseif ($tipo === 'float32' || $this->lastType === 'float32') {
            $this->emitStr('s0', $offset);
        } else {
            $this->emitStr('x0', $offset);
        }
    }

    return null;
}

public function visitExprReferencia(\Context\ExprReferenciaContext $ctx): string
{
    $nombre = $ctx->ID()->getText();
    $offset = $this->offsetVar($nombre);
    $info   = $this->arrayInfo[$nombre] ?? null;

    if ($info !== null) {
        $abs = abs($info['offset']);
        $this->text[] = "    sub x0, x29, #{$abs}";
    } elseif ($offset !== null) {
        $abs = abs($offset);
        $this->text[] = "    sub x0, x29, #{$abs}";
    } else {
        $this->text[] = '    mov x0, #0';
    }

    $this->lastType = '__ref__';
    return 'x0';
}

public function visitExprDeref(\Context\ExprDerefContext $ctx): string
{
    $nombre = $ctx->ID()->getText();
    $offset = $this->offsetVar($nombre);

    if ($offset !== null) {
        $this->emitLdr('x9', $offset);
        $this->text[] = "    ldr x0, [x9]";
    } else {
        $this->text[] = '    mov x0, #0';
    }

    $this->lastType = 'int';
    return 'x0';
}

    private function visitBloqueGen(BloqueContext $ctx): void
    {
        foreach ($ctx->sentencia() as $sent) {
            $this->visit($sent);
        }
    }

    public function visitSentenciaExpr(SentenciaExprContext $ctx): mixed
    {
        $this->visit($ctx->expr());
        return null;
    }

    public function visitExprFmtPrintln(\Context\ExprFmtPrintlnContext $ctx): mixed
{
    if ($ctx->listaExpr() === null) {
        $this->emitirNewline();
        return null;
    }

    $exprs = $ctx->listaExpr()->expr();
    $total = count($exprs);

    foreach ($exprs as $i => $expr) {
    $this->lastType = 'int';
    $reg = $this->visit($expr);

    if ($this->lastType === 'string') {
        // x0 = dirección, x1 = longitud — imprimir directamente
        $this->text[] = '    mov x2, x1';
        $this->text[] = '    mov x1, x0';
        $this->text[] = '    mov x0, #1';
        $this->text[] = '    mov x8, #64';
        $this->text[] = '    svc  #0';
    } elseif ($this->lastType === 'nil') {
        $this->emitirImprimirNil();  
    } elseif ($this->lastType === 'bool') {
        $this->emitirImprimirBool();
    } elseif ($this->lastType === 'rune') {
        $this->emitirImprimirRune();
    } elseif ($this->lastType === 'float32') {
        $this->emitirImprimirFloat();
    } else {
        $this->emitirImprimirEntero();
    }

    if ($i < $total - 1) {
        $this->emitirEspacio();
    }

    }

    $this->emitirNewline();
    return null;
}

public function visitExprNil(\Context\ExprNilContext $ctx): string
{
    $this->lastType = 'nil';
    return 'x0';
}
public function visitExprLiteral(ExprLiteralContext $ctx): string
{
    return $this->visit($ctx->literal());
}

public function visitLiteralString(\Context\LiteralStringContext $ctx): string
{
    $raw   = $ctx->STR_LIT()->getText();
    $valor = substr($raw, 1, -1);
    $valor = stripcslashes($valor);

    $label = '__str_' . $this->strCount++;
    $len   = strlen($valor);

    $escaped = $this->escaparString($valor);

    $this->data[] = "    {$label}: .ascii \"{$escaped}\"";
    $this->data[] = "    {$label}_len = {$len}";

    $this->text[] = "    adrp x0, {$label}";
    $this->text[] = "    add  x0, x0, :lo12:{$label}";
    $this->text[] = "    mov  x1, #{$len}";
    $this->lastType = 'string';
    return 'x0';
}

private function escaparString(string $s): string
{
    $out = '';
    for ($i = 0; $i < strlen($s); $i++) {
        $c = $s[$i];
        if ($c === "\n") $out .= '\\n';
        elseif ($c === "\t") $out .= '\\t';
        elseif ($c === '"') $out .= '\\"';
        elseif ($c === '\\') $out .= '\\\\';
        else $out .= $c;
    }
    return $out;
}

    
public function visitLiteralEntero(\Context\LiteralEnteroContext $ctx): string
{
    $this->lastType = 'int';
    $val = $ctx->INT_LIT()->getText();
    $this->text[] = "    mov x0, #{$val}";
    return 'x0';
}

public function visitLiteralBool(\Context\LiteralBoolContext $ctx): string
{
    $this->lastType = 'int';
    $val = $ctx->BOOL_LIT()->getText() === 'true' ? 1 : 0;
    $this->text[] = "    mov x0, #{$val}";
    $this->lastType = 'bool';
    return 'x0';
}

public function visitLiteralFlotante(\Context\LiteralFlotanteContext $ctx): string
{
    $val    = (float)$ctx->FLOAT_LIT()->getText();
    $packed = unpack('L', pack('f', $val))[1];

    $lo = $packed & 0xFFFF;
    $hi = ($packed >> 16) & 0xFFFF;

    $this->text[] = "    movz w0, #{$lo}";
    if ($hi !== 0) {
        $this->text[] = "    movk w0, #{$hi}, lsl #16";
    }
    $this->text[] = "    fmov s0, w0";
    $this->lastType = 'float32';
    return 'float';
}

public function visitLiteralRune(\Context\LiteralRuneContext $ctx): string
{
    $texto = $ctx->RUNE_LIT()->getText();
    $char  = substr($texto, 1, -1);

    if ($char === '\\n') $val = 10;
    elseif ($char === '\\t') $val = 9;
    elseif ($char === '\\\\') $val = 92;
    elseif ($char === "\\'") $val = 39;
    else $val = ord($char);

    $this->text[] = "    mov x0, #{$val}";
    $this->lastType = 'int';
    return 'x0';
}

public function visitExprRelacional(\Context\ExprRelacionalContext $ctx): string
{
    $this->lastType = 'int';
    $this->visit($ctx->expr(0));
    $tipo0 = $this->lastType;

    if ($tipo0 === 'float32') {
        $this->text[] = '    str s0, [sp, #-16]!';
    } else {
        $this->pushReg('x0');
    }

    $this->lastType = 'int';
    $this->visit($ctx->expr(1));
    $tipo1 = $this->lastType;

    if ($tipo0 === 'float32' || $tipo1 === 'float32') {
        if ($tipo1 !== 'float32') {
            $this->text[] = '    scvtf s1, x0';
        } else {
            $this->text[] = '    fmov s1, s0';
        }
        $this->text[] = '    ldr s0, [sp], #16';
        $this->text[] = '    fcmp s0, s1';
        $op = $ctx->op->getText();
        $set = match($op) {
            '<'  => 'cset x0, lt',
            '<=' => 'cset x0, le',
            '>'  => 'cset x0, gt',
            '>=' => 'cset x0, ge',
        };
        $this->text[] = "    {$set}";
        $this->lastType = 'bool';
        return 'x0';
    }

    // Enteros — igual que antes
    $this->text[] = "    mov x1, x0";
    $this->popReg('x0');
    $this->text[] = "    cmp x0, x1";
    $op = $ctx->op->getText();
    $set = match($op) {
        '<'  => 'cset x0, lt',
        '<=' => 'cset x0, le',
        '>'  => 'cset x0, gt',
        '>=' => 'cset x0, ge',
    };
    $this->text[] = "    {$set}";
    $this->lastType = 'bool';
    return 'x0';
}

public function visitExprIgualdad(\Context\ExprIgualdadContext $ctx): string
{
    // Evaluar izquierda UNA sola vez
    $this->lastType = 'int';
    $this->visit($ctx->expr(0));
    $tipo0 = $this->lastType;

    if ($tipo0 === 'nil') {
        $this->lastType = 'nil';
        return 'x0';
    }

    $this->pushReg('x0');

    // Evaluar derecha UNA sola vez
    $this->lastType = 'int';
    $this->visit($ctx->expr(1));
    $tipo1 = $this->lastType;

    if ($tipo1 === 'nil') {
        $this->popReg('x0');
        $this->lastType = 'nil';
        return 'x0';
    }

    $this->text[] = "    mov x1, x0";
    $this->popReg('x0');
    $this->text[] = "    cmp x0, x1";

    $op = $ctx->op->getText() === '==' ? 'cset x0, eq' : 'cset x0, ne';
    $this->text[] = "    {$op}";
    $this->lastType = 'bool';
    return 'x0';
}

public function visitExprAnd(\Context\ExprAndContext $ctx): string
{
    $this->lastType = 'int';
    $labelFalse = $this->newLabel('and_false');
    $labelEnd   = $this->newLabel('and_end');

    // Cortocircuito: si izquierda es false, saltar
    $this->visit($ctx->expr(0));
    $this->text[] = "    cbz x0, {$labelFalse}";

    $this->visit($ctx->expr(1));
    $this->text[] = "    cbz x0, {$labelFalse}";

    $this->text[] = "    mov x0, #1";
    $this->text[] = "    b   {$labelEnd}";
    $this->text[] = "{$labelFalse}:";
    $this->text[] = "    mov x0, #0";
    $this->text[] = "{$labelEnd}:";
    $this->lastType = 'bool';
    return 'x0';
}

public function visitExprOr(\Context\ExprOrContext $ctx): string
{
    $this->lastType = 'int';
    $labelTrue = $this->newLabel('or_true');
    $labelEnd  = $this->newLabel('or_end');

    // Cortocircuito: si izquierda es true, saltar
    $this->visit($ctx->expr(0));
    $this->text[] = "    cbnz x0, {$labelTrue}";

    $this->visit($ctx->expr(1));
    $this->text[] = "    cbnz x0, {$labelTrue}";

    $this->text[] = "    mov x0, #0";
    $this->text[] = "    b   {$labelEnd}";
    $this->text[] = "{$labelTrue}:";
    $this->text[] = "    mov x0, #1";
    $this->text[] = "{$labelEnd}:";
    $this->lastType = 'bool';
    return 'x0';
}

public function visitExprNot(\Context\ExprNotContext $ctx): string
{
    $this->lastType = 'int';
    $this->visit($ctx->expr());
    $this->text[] = "    eor x0, x0, #1";
    $this->lastType = 'bool';
    return 'x0';
}

public function visitExprAgrupada(\Context\ExprAgrupadaContext $ctx): string
{
    return $this->visit($ctx->expr());
}

public function visitExprNegacion(\Context\ExprNegacionContext $ctx): string
{
    $this->lastType = 'int';
    $this->visit($ctx->expr());
    $this->text[] = "    neg x0, x0";
    return 'x0';
}

public function visitExprAditiva(\Context\ExprAditivaContext $ctx): string
{
    $op = $ctx->op->getText();

    $this->lastType = 'int';
    $this->visit($ctx->expr(0));
    $tipo0 = $this->lastType;

    if ($tipo0 === 'float32') {
        $this->text[] = '    str s0, [sp, #-16]!';
    } else {
        $this->text[] = '    str x20, [sp, #-16]!';  // preservar x20
        $this->text[] = '    mov x20, x0';             // guardar izquierdo en x20
    }

    $this->lastType = 'int';
    $this->visit($ctx->expr(1));
    $tipo1 = $this->lastType;

    if ($tipo0 === 'float32' || $tipo1 === 'float32') {
        if ($tipo1 !== 'float32') {
            $this->text[] = '    scvtf s1, x0';
        } else {
            $this->text[] = '    fmov s1, s0';
        }
        $this->text[] = '    ldr s0, [sp], #16';
        $inst = $op === '+' ? 'fadd' : 'fsub';
        $this->text[] = "    {$inst} s0, s0, s1";
        $this->lastType = 'float32';
        return 'float';
    }

    $this->text[] = '    mov x1, x0';           // derecho → x1
    $this->text[] = '    mov x0, x20';           // izquierdo ← x20
    $this->text[] = '    ldr x20, [sp], #16';    // restaurar x20
    $inst = $op === '+' ? 'add' : 'sub';
    $this->text[] = "    {$inst} x0, x0, x1";
    $this->lastType = 'int';
    return 'x0';
}

   public function visitExprMultiplicativa(\Context\ExprMultiplicativaContext $ctx): string
{
    $op = $ctx->op->getText();

    $this->lastType = 'int';
    $this->visit($ctx->expr(0));
    $tipo0 = $this->lastType;

    if ($tipo0 === 'float32') {
        $this->text[] = '    str s0, [sp, #-16]!';
    } else {
        $this->text[] = '    str x19, [sp, #-16]!';
        $this->text[] = '    mov x19, x0';
    }

    $this->lastType = 'int';
    $this->visit($ctx->expr(1));
    $tipo1 = $this->lastType;

    if ($tipo0 === 'float32' || $tipo1 === 'float32') {
        // Preparar s1 = derecho
        if ($tipo1 !== 'float32') {
            $this->text[] = '    scvtf s1, x0';
        } else {
            $this->text[] = '    fmov s1, s0';
        }
        // Preparar s0 = izquierdo
        if ($tipo0 === 'float32') {
            $this->text[] = '    ldr s0, [sp], #16';
        } else {
            $this->text[] = '    scvtf s0, x19';
            $this->text[] = '    ldr x19, [sp], #16';
        }

        if ($op === '*') {
            $this->text[] = '    fmul s0, s0, s1';
        } elseif ($op === '/') {
            $this->text[] = '    fdiv s0, s0, s1';
        } elseif ($op === '+') {
            $this->text[] = '    fadd s0, s0, s1';
        } elseif ($op === '-') {
            $this->text[] = '    fsub s0, s0, s1';
        }
        $this->lastType = 'float32';
        return 'float';
    }

    $this->text[] = '    mov x1, x0';
    $this->text[] = '    mov x0, x19';
    $this->text[] = '    ldr x19, [sp], #16';

    if ($op === '*') {
        $this->text[] = '    mul x0, x0, x1';
    } elseif ($op === '/') {
        $this->text[] = '    sdiv x0, x0, x1';
    } elseif ($op === '%') {
        $this->text[] = '    sdiv x2, x0, x1';
        $this->text[] = '    msub x0, x2, x1, x0';
    }

    $this->lastType = 'int';
    return 'x0';
}
    
    private function emitirImprimirNil(): void
{
    $this->text[] = '    adrp x1, str_nil';
    $this->text[] = '    add  x1, x1, :lo12:str_nil';
    $this->text[] = '    mov  x0, #1';
    $this->text[] = '    mov  x2, str_nil_len';
    $this->text[] = '    mov  x8, #64';
    $this->text[] = '    svc  #0';
}
    private function emitirImprimirEntero(): void
    {
        $this->text[] = '    bl __print_int';
        $this->definirRutinaPrintInt();
    }

    private function emitirNewline(): void
    {
        $this->text[] = '    adrp x0, newline';
        $this->text[] = '    add  x0, x0, :lo12:newline';
        $this->text[] = '    mov  x1, x0';
        $this->text[] = '    mov  x0, #1';
        $this->text[] = '    mov  x2, #1';
        $this->text[] = '    mov  x8, #64';
        $this->text[] = '    svc  #0';
    }

    private function emitirEspacio(): void
    {
        if (!in_array('    espacio: .ascii " "', $this->data)) {
            $this->data[] = '    espacio: .ascii " "';
        }
        $this->text[] = '    adrp x0, espacio';
        $this->text[] = '    add  x0, x0, :lo12:espacio';
        $this->text[] = '    mov  x1, x0';
        $this->text[] = '    mov  x0, #1';
        $this->text[] = '    mov  x2, #1';
        $this->text[] = '    mov  x8, #64';
        $this->text[] = '    svc  #0';
    }

    private function emitirImprimirBool(): void
{
    $labelTrue = $this->newLabel('bool_true');
    $labelEnd  = $this->newLabel('bool_end');

    $this->text[] = "    cbnz x0, {$labelTrue}";
    $this->text[] = "    adrp x1, str_false";
    $this->text[] = "    add  x1, x1, :lo12:str_false";
    $this->text[] = "    mov  x2, str_false_len";
    $this->text[] = "    b    {$labelEnd}";
    $this->text[] = "{$labelTrue}:";
    $this->text[] = "    adrp x1, str_true";
    $this->text[] = "    add  x1, x1, :lo12:str_true";
    $this->text[] = "    mov  x2, str_true_len";
    $this->text[] = "{$labelEnd}:";
    $this->text[] = "    mov  x0, #1";
    $this->text[] = "    mov  x8, #64";
    $this->text[] = "    svc  #0";
}

private function emitirImprimirRune(): void
{
    $this->text[] = '    sub sp, sp, #16';
    $this->text[] = '    strb w0, [sp]';
    $this->text[] = '    mov x1, sp';
    $this->text[] = '    mov x0, #1';
    $this->text[] = '    mov x2, #1';
    $this->text[] = '    mov x8, #64';
    $this->text[] = '    svc  #0';
    $this->text[] = '    add sp, sp, #16';
}

public function visitSentenciaIf(\Context\SentenciaIfContext $ctx): mixed
{
    $labelElse = $this->newLabel('else');
    $labelEnd  = $this->newLabel('end_if');

    // Evaluar condición → x0
    $this->visit($ctx->expr());

    // Si es false (0), saltar al else
    $this->text[] = "    cbz x0, {$labelElse}";

    // Bloque then
    $this->visitBloqueGen($ctx->bloque(0));

    $tieneElse = count($ctx->bloque()) > 1 || $ctx->sentenciaIf() !== null;

    if ($tieneElse) {
        $this->text[] = "    b {$labelEnd}";
    }

    $this->text[] = "{$labelElse}:";

    // Bloque else (si existe)
    if ($ctx->sentenciaIf() !== null) {
        // else if
        $this->visit($ctx->sentenciaIf());
    } elseif (count($ctx->bloque()) > 1) {
        // else
        $this->visitBloqueGen($ctx->bloque(1));
    }

    if ($tieneElse) {
        $this->text[] = "{$labelEnd}:";
    }

    return null;
}

public function visitForClassico(\Context\ForClassicoContext $ctx): mixed
{
    $labelInicio = $this->newLabel('for_inicio');
    $labelPaso   = $this->newLabel('for_paso');
    $labelFin    = $this->newLabel('for_fin');

    // Inicialización
    $this->visit($ctx->declCorta());

    $this->text[] = "{$labelInicio}:";

    // Condición
    $this->visit($ctx->expr());
    $this->text[] = "    cbz x0, {$labelFin}";

    // Cuerpo — continue debe saltar al PASO, no al inicio
    array_push($this->loopInicioStack, $labelPaso);
    array_push($this->loopFinStack, $labelFin);
    foreach ($ctx->bloque()->sentencia() as $sent) {
        $this->visit($sent);
    }

    array_pop($this->loopInicioStack);
    array_pop($this->loopFinStack);

    // Paso
    $this->text[] = "{$labelPaso}:";
    if ($ctx->incDec() !== null) {
        $this->visit($ctx->incDec());
    } elseif ($ctx->asignacionCompuesta() !== null) {
        $this->visit($ctx->asignacionCompuesta());
    }

    $this->text[] = "    b {$labelInicio}";
    $this->text[] = "{$labelFin}:";

    return null;
}

public function visitForWhile(\Context\ForWhileContext $ctx): mixed
{
    $labelInicio = $this->newLabel('while_inicio');
    $labelFin    = $this->newLabel('while_fin');

    $this->text[] = "{$labelInicio}:";

    $this->visit($ctx->expr());
    $this->text[] = "    cbz x0, {$labelFin}";

    $this->visitBloqueConControl($ctx->bloque(), $labelInicio, $labelFin);

    $this->text[] = "    b {$labelInicio}";
    $this->text[] = "{$labelFin}:";

    return null;
}

public function visitForInfinito(\Context\ForInfinitoContext $ctx): mixed
{
    $labelInicio = $this->newLabel('loop_inicio');
    $labelFin    = $this->newLabel('loop_fin');

    $this->text[] = "{$labelInicio}:";

    $this->visitBloqueConControl($ctx->bloque(), $labelInicio, $labelFin);

    $this->text[] = "    b {$labelInicio}";
    $this->text[] = "{$labelFin}:";

    return null;
}

public function visitSentenciaSwitch(\Context\SentenciaSwitchContext $ctx): mixed
{
    $labelFin    = $this->newLabel('switch_fin');
    $labelDefault = $this->newLabel('switch_default');

    // Evaluar expresión del switch → guardar en stack
    $this->visit($ctx->expr());
    $this->pushReg('x0');

    $casos   = $ctx->casoSwitch();
    $default = $ctx->defaultSwitch();

    // Generar etiquetas para cada caso
    $labelsCasos = [];
    foreach ($casos as $i => $caso) {
        $labelsCasos[$i] = $this->newLabel('case');
    }

    // Generar comparaciones y saltos
    foreach ($casos as $i => $caso) {
        foreach ($caso->listaExpr()->expr() as $exprCaso) {
            // Recuperar valor del switch sin consumirlo
            $this->text[] = '    ldr x0, [sp]';
            $this->pushReg('x0');
            $this->visit($exprCaso);
            $this->text[] = "    mov x1, x0";
            $this->popReg('x0');
            $this->text[] = "    cmp x0, x1";
            $this->text[] = "    b.eq {$labelsCasos[$i]}";
        }
    }

    // Si ningún caso coincide, saltar a default o fin
    if ($default !== null) {
        $this->text[] = "    b {$labelDefault}";
    } else {
        $this->text[] = "    b {$labelFin}";
    }

    // Emitir código de cada caso
    foreach ($casos as $i => $caso) {
        $this->text[] = "{$labelsCasos[$i]}:";
        foreach ($caso->sentencia() as $sent) {
            $this->visit($sent);
        }
        $this->text[] = "    b {$labelFin}";
    }

    // Emitir default
    if ($default !== null) {
        $this->text[] = "{$labelDefault}:";
        foreach ($default->sentencia() as $sent) {
            $this->visit($sent);
        }
    }

    $this->text[] = "{$labelFin}:";

    // Limpiar el valor del switch del stack
    $this->popReg('x0');

    return null;
}

public function visitSentenciaBreak(\Context\SentenciaBreakContext $ctx): mixed
{
    if (!empty($this->loopFinStack)) {
        $label = end($this->loopFinStack);
        $this->text[] = "    b {$label}";
    }
    return null;
}

public function visitSentenciaContinue(\Context\SentenciaContinueContext $ctx): mixed
{
    if (!empty($this->loopInicioStack)) {
        $label = end($this->loopInicioStack);
        $this->text[] = "    b {$label}";
    }
    return null;
}

public function visitIncDec(\Context\IncDecContext $ctx): mixed
{
    $nombre = $ctx->lvalue()->ID()->getText();
    $offset = $this->offsetVar($nombre);

    if ($offset !== null) {
        $this->emitLdr('x0', $offset);
        if ($ctx->op->getText() === '++') {
            $this->text[] = "    add x0, x0, #1";
        } else {
            $this->text[] = "    sub x0, x0, #1";
        }
        $this->emitStr('x0', $offset);
    }

    return null;
}

public function visitAsignacionCompuesta(\Context\AsignacionCompuestaContext $ctx): mixed
{
    $nombre = $ctx->lvalue()->ID()->getText();
    $offset = $this->offsetVar($nombre);

    if ($offset === null) return null;

    $tipo = $this->varTypes[$nombre] ?? 'int32';

    if ($tipo === 'float32') {
        $this->emitLdr('s0', $offset);
        $this->text[] = '    str s0, [sp, #-16]!';   // preservar izquierdo
        $this->visit($ctx->expr());
        // s0 ahora tiene el derecho (lastType = float32)
        $this->text[] = '    fmov s1, s0';
        $this->text[] = '    ldr s0, [sp], #16';
        $op = $ctx->op->getText();
        match($op) {
            '+=' => $this->text[] = '    fadd s0, s0, s1',
            '-=' => $this->text[] = '    fsub s0, s0, s1',
            '*=' => $this->text[] = '    fmul s0, s0, s1',
            '/=' => $this->text[] = '    fdiv s0, s0, s1',
        };
        $this->emitStr('s0', $offset);
        return null;
    }

    // Enteros — igual que antes
    $this->emitLdr('x0', $offset);
    $this->pushReg('x0');
    $this->visit($ctx->expr());
    $this->text[] = "    mov x1, x0";
    $this->popReg('x0');
    $op = $ctx->op->getText();
    match($op) {
        '+=' => $this->text[] = "    add x0, x0, x1",
        '-=' => $this->text[] = "    sub x0, x0, x1",
        '*=' => $this->text[] = "    mul x0, x0, x1",
        '/=' => $this->text[] = "    sdiv x0, x0, x1",
    };
    $this->emitStr('x0', $offset);
    return null;
}

private function emitirImprimirFloat(): void
{
    $this->text[] = '    bl __print_float';
    $this->definirRutinaPrintFloat();
}

private function definirRutinaPrintFloat(): void
{
    if ($this->printFloatDef) return;
    $this->printFloatDef = true;

    $this->rutinas[] = '__print_float:';
    $this->rutinas[] = '    stp x29, x30, [sp, #-96]!';
    $this->rutinas[] = '    mov x29, sp';
    $this->rutinas[] = '    stp d8, d9, [x29, #16]';
    $this->rutinas[] = '    stp x19, x20, [x29, #32]';
    $this->rutinas[] = '    stp x21, x22, [x29, #48]';
    $this->rutinas[] = '    str x23,     [x29, #64]';
    $this->rutinas[] = '    fcvt d8, s0';
    $this->rutinas[] = '    fcmp d8, #0.0';
    $this->rutinas[] = '    b.ge __pf_positivo';
    $this->rutinas[] = '    mov x23, #45';
    $this->rutinas[] = '    strb w23, [x29, #72]';
    $this->rutinas[] = '    mov x0, #1';
    $this->rutinas[] = '    add x1, x29, #72';
    $this->rutinas[] = '    mov x2, #1';
    $this->rutinas[] = '    mov x8, #64';
    $this->rutinas[] = '    svc #0';
    $this->rutinas[] = '    fneg d8, d8';

    $this->rutinas[] = '__pf_positivo:';
    // Imprimir parte entera
    $this->rutinas[] = '    fcvtzs x0, d8';
    $this->rutinas[] = '    str d8, [sp, #80]';   // guardar d8 antes de bl
    $this->rutinas[] = '    bl __print_int';
    $this->rutinas[] = '    ldr d8, [sp, #80]';   // restaurar d8

    // Imprimir '.'
    $this->rutinas[] = '    mov x23, #46';
    $this->rutinas[] = '    strb w23, [sp, #72]';
    $this->rutinas[] = '    mov x0, #1';
    $this->rutinas[] = '    add x1, sp, #72';
    $this->rutinas[] = '    mov x2, #1';
    $this->rutinas[] = '    mov x8, #64';
    $this->rutinas[] = '    svc #0';

    // Calcular parte decimal
    $this->rutinas[] = '    fcvtzs x1, d8';
    $this->rutinas[] = '    scvtf d9, x1';
    $this->rutinas[] = '    fsub d8, d8, d9';
    $this->rutinas[] = '    movz x0, #0x86A0';
    $this->rutinas[] = '    movk x0, #0x0001, lsl #16';
    $this->rutinas[] = '    scvtf d9, x0';
    $this->rutinas[] = '    fmul d8, d8, d9';
    $this->rutinas[] = '    fcvtzs x19, d8';

    // Caso especial: parte decimal es 0
    $this->rutinas[] = '    cbz x19, __pf_cero_dec';

    // Eliminar ceros finales
    $this->rutinas[] = '__pf_trim:';
    $this->rutinas[] = '    mov x20, #10';
    $this->rutinas[] = '    udiv x21, x19, x20';
    $this->rutinas[] = '    msub x22, x21, x20, x19';
    $this->rutinas[] = '    cbnz x22, __pf_print_dec';
    $this->rutinas[] = '    mov x19, x21';
    $this->rutinas[] = '    b __pf_trim';

    $this->rutinas[] = '__pf_cero_dec:';
    $this->rutinas[] = '    mov x19, #0';

    $this->rutinas[] = '__pf_print_dec:';
    $this->rutinas[] = '    mov x0, x19';
    $this->rutinas[] = '    str d8, [sp, #80]';
    $this->rutinas[] = '    bl __print_int';
    $this->rutinas[] = '    ldr d8, [sp, #80]';
    // Restaurar y retornar — TODO a $this->rutinas[], nunca a $this->text[]
    $this->rutinas[] = '    ldr x23,     [x29, #64]';
    $this->rutinas[] = '    ldp x21, x22, [x29, #48]';
    $this->rutinas[] = '    ldp x19, x20, [x29, #32]';
    $this->rutinas[] = '    ldp d8, d9, [x29, #16]';
    $this->rutinas[] = '    ldp x29, x30, [sp], #96';
    $this->rutinas[] = '    ret';

}

    private function definirRutinaPrintInt(): void
{
    if ($this->printIntDef) return;
    $this->printIntDef = true;

    $this->rutinas[] = '__print_int:';
    $this->rutinas[] = '    stp x29, x30, [sp, #-80]!';
    $this->rutinas[] = '    mov x29, sp';
    $this->rutinas[] = '    mov x19, x0';
    $this->rutinas[] = '    mov x20, #0';
    $this->rutinas[] = '    mov x21, #10';
    // Caso especial: x0 == 0
    $this->rutinas[] = '    cbnz x19, __pi_no_cero';
    $this->rutinas[] = '    mov x22, #48';
    $this->rutinas[] = '    strb w22, [x29, #16]';
    $this->rutinas[] = '    mov x0, #1';
    $this->rutinas[] = '    add x1, x29, #16';
    $this->rutinas[] = '    mov x2, #1';
    $this->rutinas[] = '    mov x8, #64';
    $this->rutinas[] = '    svc #0';
    $this->rutinas[] = '    ldp x29, x30, [sp], #80';
    $this->rutinas[] = '    ret';
    $this->rutinas[] = '__pi_no_cero:';
    $this->rutinas[] = '    cmp x19, #0';
    $this->rutinas[] = '    b.ge __pi_positivo';
    $this->rutinas[] = '    mov x22, #45';
    $this->rutinas[] = '    sub sp, sp, #16';
    $this->rutinas[] = '    strb w22, [sp]';
    $this->rutinas[] = '    mov x0, #1';
    $this->rutinas[] = '    mov x1, sp';
    $this->rutinas[] = '    mov x2, #1';
    $this->rutinas[] = '    mov x8, #64';
    $this->rutinas[] = '    svc #0';
    $this->rutinas[] = '    add sp, sp, #16';
    $this->rutinas[] = '    neg x19, x19';
    $this->rutinas[] = '__pi_positivo:';
    $this->rutinas[] = '    add x22, x29, #16';
    $this->rutinas[] = '__pi_loop:';
    $this->rutinas[] = '    udiv x23, x19, x21';
    $this->rutinas[] = '    msub x24, x23, x21, x19';
    $this->rutinas[] = '    add  x24, x24, #48';
    $this->rutinas[] = '    strb w24, [x22, x20]';
    $this->rutinas[] = '    add  x20, x20, #1';
    $this->rutinas[] = '    mov  x19, x23';
    $this->rutinas[] = '    cbnz x19, __pi_loop';
    $this->rutinas[] = '    mov x23, #0';
    $this->rutinas[] = '    sub x24, x20, #1';
    $this->rutinas[] = '__pi_rev:';
    $this->rutinas[] = '    cmp x23, x24';
    $this->rutinas[] = '    b.ge __pi_write';
    $this->rutinas[] = '    ldrb w25, [x22, x23]';
    $this->rutinas[] = '    ldrb w26, [x22, x24]';
    $this->rutinas[] = '    strb w25, [x22, x24]';
    $this->rutinas[] = '    strb w26, [x22, x23]';
    $this->rutinas[] = '    add  x23, x23, #1';
    $this->rutinas[] = '    sub  x24, x24, #1';
    $this->rutinas[] = '    b    __pi_rev';
    $this->rutinas[] = '__pi_write:';
    $this->rutinas[] = '    mov x0, #1';
    $this->rutinas[] = '    mov x1, x22';
    $this->rutinas[] = '    mov x2, x20';
    $this->rutinas[] = '    mov x8, #64';
    $this->rutinas[] = '    svc #0';
    $this->rutinas[] = '    ldp x29, x30, [sp], #80';
    $this->rutinas[] = '    ret';
}

    public function obtenerCodigo(): string
    {
        return $this->construirCodigo();
    }
}