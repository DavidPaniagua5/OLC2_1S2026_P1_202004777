<?php

namespace App;

use Antlr\Antlr4\Runtime\Tree\ParseTree;
use Antlr\Antlr4\Runtime\Tree\TerminalNode;
use Antlr\Antlr4\Runtime\Tree\ErrorNode;
use Antlr\Antlr4\Runtime\ParserRuleContext;

/**
 * Genera el AST completo como SVG usando Graphviz (dot) en el servidor.
 * No usa viz.js — el SVG se renderiza aquí y se devuelve al frontend listo.
 *
 * Instalación requerida (una sola vez):
 *   sudo apt install graphviz
 */
class GeneradorDot
{
    // Tokens de puntuación que no aportan información visual
    private const PUNTUACION = ['{', '}', '(', ')', '[', ']', ',', ';', ':'];

    // Reglas de paso que se puentean si tienen un solo hijo
    private const REGLAS_PUENTEABLES = [
        'declaracionTop', 'sentencia', 'listaExpr', 'listaIds',
        'listaLvalue', 'listaParams', 'param', 'sentenciaExpr',
        'tipoRetorno', 'lvalue',
    ];

    private int   $contador  = 0;
    private array $nodos     = [];
    private array $aristas   = [];
    private array $ruleNames = [];

    private function __construct(array $ruleNames)
    {
        $this->ruleNames = $ruleNames;
    }

    // ------------------------------------------------------------------
    // Punto de entrada: devuelve SVG como string
    // ------------------------------------------------------------------
    public static function generarSVG(ParseTree $tree, \GrammarParser $parser): string
    {
        $gen = new self($parser->getRuleNames());
        $gen->recorrer($tree, null);
        $dot = $gen->construirDot();
        return self::dotASvg($dot);
    }

    // ------------------------------------------------------------------
    // Convierte DOT → SVG usando el binario `dot` de Graphviz
    // ------------------------------------------------------------------
    private static function dotASvg(string $dot): string
    {
        // Verificar que Graphviz esté instalado
        $binario = trim(shell_exec('which dot 2>/dev/null') ?? '');
        if (empty($binario)) {
            return '<p style="color:red">Error: Graphviz no está instalado. '
                 . 'Ejecuta: sudo apt install graphviz</p>';
        }

        // Escribir el DOT a un archivo temporal
        $tmpDot = tempnam(sys_get_temp_dir(), 'golampi_ast_') . '.dot';
        $tmpSvg = tempnam(sys_get_temp_dir(), 'golampi_ast_') . '.svg';

        file_put_contents($tmpDot, $dot);

        // Ejecutar Graphviz
        $cmd    = escapeshellcmd($binario)
                . ' -Tsvg '
                . escapeshellarg($tmpDot)
                . ' -o '
                . escapeshellarg($tmpSvg)
                . ' 2>&1';

        $output = shell_exec($cmd);

        if (!file_exists($tmpSvg) || filesize($tmpSvg) === 0) {
            @unlink($tmpDot);
            return '<p style="color:red">Error al generar SVG: ' . htmlspecialchars($output ?? '') . '</p>';
        }

        $svg = file_get_contents($tmpSvg);

        // Limpiar temporales
        @unlink($tmpDot);
        @unlink($tmpSvg);

        // Quitar el encabezado XML y DOCTYPE para poder embeber el SVG en HTML
        $svg = preg_replace('/<\?xml[^?]*\?>/', '', $svg);
        $svg = preg_replace('/<!DOCTYPE[^>]*>/', '', $svg);
        $svg = trim($svg);

        // Quitar width/height fijos para que el SVG sea flexible
        $svg = preg_replace('/(<svg[^>]*)\swidth="[^"]*"/',  '$1', $svg);
        $svg = preg_replace('/(<svg[^>]*)\sheight="[^"]*"/', '$1', $svg);

        return $svg;
    }

    // ------------------------------------------------------------------
    // Recorrido recursivo del árbol (SIN límite de nodos)
    // ------------------------------------------------------------------
    private function recorrer(ParseTree $nodo, ?int $idPadre): int
    {
        // Token hoja
        if ($nodo instanceof TerminalNode) {
            $texto = $nodo->getText();
            if ($texto === '<EOF>' || in_array($texto, self::PUNTUACION, true)) {
                return -1;
            }
            $id = $this->contador++;
            $this->nodos[] = sprintf(
                'n%d [label="%s" shape=ellipse style=filled fillcolor="#d4edda" fontcolor="#155724"]',
                $id, $this->esc($texto)
            );
            if ($idPadre !== null) {
                $this->aristas[] = "n{$idPadre} -> n{$id}";
            }
            return $id;
        }

        // Error node
        if ($nodo instanceof ErrorNode) {
            $id = $this->contador++;
            $this->nodos[] = sprintf(
                'n%d [label="ERROR\\n%s" shape=diamond style=filled fillcolor="#f8d7da" fontcolor="#721c24"]',
                $id, $this->esc($nodo->getText())
            );
            if ($idPadre !== null) {
                $this->aristas[] = "n{$idPadre} -> n{$id}";
            }
            return $id;
        }

        if (!($nodo instanceof ParserRuleContext)) {
            return -1;
        }

        // Hijos reales (sin puntuación)
        $hijosReales = $this->hijosFiltrados($nodo);

        // Puentear reglas de paso con un solo hijo real
        $indiceRegla = $nodo->getRuleIndex();
        $nombreRegla = $this->ruleNames[$indiceRegla] ?? '';
        if (
            in_array($nombreRegla, self::REGLAS_PUENTEABLES, true)
            && count($hijosReales) === 1
        ) {
            return $this->recorrer($hijosReales[0], $idPadre);
        }

        // Label del nodo
        $clase      = get_class($nodo);
        $nombreClase = basename(str_replace('\\', '/', $clase));
        $label       = str_replace('Context', '', $nombreClase);
        if ($label === '' || $label === 'ParserRule') {
            $label = $nombreRegla;
        }

        $id = $this->contador++;
        $this->nodos[] = sprintf(
            'n%d [label="%s" shape=box style=filled fillcolor="#cce5ff" fontcolor="#004085"]',
            $id, $this->esc($label)
        );
        if ($idPadre !== null) {
            $this->aristas[] = "n{$idPadre} -> n{$id}";
        }

        foreach ($hijosReales as $hijo) {
            $this->recorrer($hijo, $id);
        }

        return $id;
    }

    // ------------------------------------------------------------------
    private function hijosFiltrados(ParserRuleContext $nodo): array
    {
        $resultado = [];
        for ($i = 0; $i < $nodo->getChildCount(); $i++) {
            $hijo = $nodo->getChild($i);
            if ($hijo instanceof TerminalNode) {
                $txt = $hijo->getText();
                if ($txt === '<EOF>' || in_array($txt, self::PUNTUACION, true)) {
                    continue;
                }
            }
            $resultado[] = $hijo;
        }
        return $resultado;
    }

    // ------------------------------------------------------------------
    private function construirDot(): string
    {
        $nStr = implode("\n  ", $this->nodos);
        $aStr = implode("\n  ", $this->aristas);
        return <<<DOT
digraph AST {
  graph [rankdir=TB bgcolor="#ffffff" fontname="Helvetica" pad="0.5"]
  node  [fontname="Helvetica" fontsize=11]
  edge  [color="#555555"]
  {$nStr}
  {$aStr}
}
DOT;
    }

    // ------------------------------------------------------------------
    private function esc(string $s): string
    {
        $s = str_replace('\\', '\\\\', $s);
        $s = str_replace('"',  '\\"',  $s);
        $s = str_replace("\n", '\\n',  $s);
        $s = str_replace("\r", '',     $s);
        return $s;
    }
}