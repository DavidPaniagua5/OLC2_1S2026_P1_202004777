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
        $this->varOffset = [];
        $this->frameSize = 0;
    }

    private function reservarVar(string $nombre): int
    {
        $this->frameSize += 8;
        $offset = $this->frameSize;
        $this->varOffset[$nombre] = $offset;
        return $offset;
    }

    private function offsetVar(string $nombre): ?int
    {
        return $this->varOffset[$nombre] ?? null;
    }

    private function newLabel(string $prefix): string
    {
        return $prefix . '_' . ($this->labelCount++);
    }

    public function visitPrograma(ProgramaContext $ctx): string
    {
        $this->data[] = '.section .data';
        $this->data[] = '    newline: .ascii "\\n"';

        $this->text[] = '.section .text';
        $this->text[] = '.align 2';
        $this->text[] = '.global _start';

        foreach ($ctx->declaracionTop() as $decl) {
            if ($decl->funcDecl() !== null) {
                $this->visitFuncDecl($decl->funcDecl());
            }
        }

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

    public function visitFuncDecl(FuncDeclContext $ctx): void
{
    $nombre = $ctx->ID()->getText();
    $label  = $nombre === 'main' ? '_start' : $nombre;

    $this->iniciarFrame();

    // Contar variables para reservar frame
    $numVars = $this->contarVariables($ctx->bloque());
    $frameBytes = max(16, (($numVars * 8 + 16 + 15) & ~15));

    $this->text[] = '';
    $this->text[] = "{$label}:";
    $this->text[] = "    stp x29, x30, [sp, #-{$frameBytes}]!";
    $this->text[] = "    mov x29, sp";

    $this->visitBloqueGen($ctx->bloque());

    $this->text[] = "    ldp x29, x30, [sp], #{$frameBytes}";

    if ($nombre === 'main') {
        $this->text[] = '    mov x0, #0';
        $this->text[] = '    mov x8, #93';
        $this->text[] = '    svc #0';
    } else {
        $this->text[] = '    ret';
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

    if ($offset !== null) {
        $this->text[] = "    ldr x0, [x29, #{$offset}]";
    } else {
        $this->text[] = "    mov x0, #0";
        $this->errores->agregar('Semántico', "Variable '{$nombre}' no declarada.");
    }

    return 'x0';
}

public function visitVarDecl(\Context\VarDeclContext $ctx): mixed
{
    $ids   = $ctx->listaIds()->ID();
    $exprs = $ctx->listaExpr() !== null ? $ctx->listaExpr()->expr() : [];

    foreach ($ids as $i => $nodoId) {
        $nombre = $nodoId->getText();
        $offset = $this->reservarVar($nombre);

        if (isset($exprs[$i])) {
            $reg = $this->visit($exprs[$i]);
            if ($reg !== '__string_printed__') {
                $this->text[] = "    str x0, [x29, #{$offset}]";
            }
        } else {
            $this->text[] = "    str xzr, [x29, #{$offset}]";
        }
    }
    return null;
}

public function visitDeclCorta(\Context\DeclCortaContext $ctx): mixed
{
    $ids   = $ctx->listaIds()->ID();
    $exprs = $ctx->listaExpr()->expr();

    foreach ($ids as $i => $nodoId) {
        $nombre = $nodoId->getText();
        $offset = $this->reservarVar($nombre);

        if (isset($exprs[$i])) {
            $reg = $this->visit($exprs[$i]);
            if ($reg !== '__string_printed__') {
                $this->text[] = "    str x0, [x29, #{$offset}]";
            }
        }
    }
    return null;
}

public function visitAsignacion(\Context\AsignacionContext $ctx): mixed
{
    $lvalues = $ctx->listaLvalue()->lvalue();
    $exprs   = $ctx->listaExpr()->expr();

    foreach ($lvalues as $i => $lv) {
        $nombre = $lv->ID()->getText();
        $offset = $this->offsetVar($nombre);
        $reg    = $this->visit($exprs[$i]);

        if ($offset !== null && $reg !== '__string_printed__') {
            $this->text[] = "    str x0, [x29, #{$offset}]";
        }
    }
    return null;
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
        $reg = $this->visit($expr);

        if ($reg !== '__string_printed__') {
            $this->emitirImprimirEntero();
        }

        if ($i < $total - 1) {
            $this->emitirEspacio();
        }
    }

    $this->emitirNewline();
    return null;
}

    public function visitExprLiteral(ExprLiteralContext $ctx): string
    {
        return $this->visit($ctx->literal());
    }

    public function visitLiteralString(\Context\LiteralStringContext $ctx): string
{
    $raw   = $ctx->STR_LIT()->getText();
    $valor = substr($raw, 1, -1);
    $valor = str_replace('\\n', "\n", $valor);
    $valor = str_replace('\\t', "\t", $valor);

    $label = '__str_' . $this->strCount++;
    $len   = strlen($valor) + 1;

    $escaped = '';
    for ($i = 0; $i < strlen($valor); $i++) {
        $c = $valor[$i];
        if ($c === "\n") {
            $escaped .= '\\n';
        } elseif ($c === "\t") {
            $escaped .= '\\t';
        } elseif ($c === '"') {
            $escaped .= '\\"';
        } elseif ($c === '\\') {
            $escaped .= '\\\\';
        } else {
            $escaped .= $c;
        }
    }

    $this->data[] = "    {$label}: .ascii \"{$escaped}\"";
    $this->data[] = "    {$label}_len = . - {$label}";

    // Emitir instrucciones para imprimir el string directamente
    $this->text[] = "    adrp x0, {$label}";
    $this->text[] = "    add  x0, x0, :lo12:{$label}";
    $this->text[] = "    mov  x1, x0";
    $this->text[] = "    mov  x0, #1";
    $this->text[] = "    mov  x2, {$label}_len";
    $this->text[] = "    mov  x8, #64";
    $this->text[] = "    svc  #0";

    return '__string_printed__';
}

    
public function visitLiteralEntero(\Context\LiteralEnteroContext $ctx): string
{
    $val = $ctx->INT_LIT()->getText();
    $this->text[] = "    mov x0, #{$val}";
    return 'x0';
}

public function visitExprAgrupada(\Context\ExprAgrupadaContext $ctx): string
{
    return $this->visit($ctx->expr());
}

public function visitExprNegacion(\Context\ExprNegacionContext $ctx): string
{
    $this->visit($ctx->expr());
    $this->text[] = "    neg x0, x0";
    return 'x0';
}

public function visitExprAditiva(\Context\ExprAditivaContext $ctx): string
{
    // Evaluar izquierda → x0, guardar en stack
    $this->visit($ctx->expr(0));
    $this->pushReg('x0');

    // Evaluar derecha → x0
    $this->visit($ctx->expr(1));
    $this->text[] = "    mov x1, x0";

    // Recuperar izquierda
    $this->popReg('x0');

    $op = $ctx->op->getText() === '+' ? 'add' : 'sub';
    $this->text[] = "    {$op} x0, x0, x1";
    return 'x0';
}

public function visitExprMultiplicativa(\Context\ExprMultiplicativaContext $ctx): string
{
    $op = $ctx->op->getText();

    // Evaluar izquierda → x0, guardar en stack
    $this->visit($ctx->expr(0));
    $this->pushReg('x0');

    // Evaluar derecha → x0
    $this->visit($ctx->expr(1));
    $this->text[] = "    mov x1, x0";

    // Recuperar izquierda
    $this->popReg('x0');

    if ($op === '*') {
        $this->text[] = "    mul x0, x0, x1";
    } elseif ($op === '/') {
        $this->text[] = "    sdiv x0, x0, x1";
    } elseif ($op === '%') {
        $this->text[] = "    sdiv x2, x0, x1";
        $this->text[] = "    msub x0, x2, x1, x0";
    }

    return 'x0';
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

    private function definirRutinaPrintInt(): void
    {
        if ($this->printIntDef) {
            return;
        }
        $this->printIntDef = true;

        $this->rutinas[] = '__print_int:';
        $this->rutinas[] = '    stp x29, x30, [sp, #-80]!';
        $this->rutinas[] = '    mov x29, sp';
        $this->rutinas[] = '    mov x19, x0';
        $this->rutinas[] = '    mov x20, #0';
        $this->rutinas[] = '    mov x21, #10';
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
        $this->rutinas[] = ' ';
    }

    public function obtenerCodigo(): string
    {
        return $this->construirCodigo();
    }
}