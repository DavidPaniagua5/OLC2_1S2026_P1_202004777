# Gramática

~~~php
grammar Grammar;

// ============================================================
// PROGRAMA Y FUNCIONES
// ============================================================

programa : (funcDecl | topDecl)* main EOF;

topDecl : varDecl | constDecl;

main : 'func' 'main' '(' ')' bloque;

funcDecl : 'func' ID '(' listaParams? ')' tipoRetorno? bloque;

listaParams : param (',' param)*;

param : ID tipo;

tipoRetorno : tipo | '(' tipo (',' tipo)* ')';

// ============================================================
// TIPOS (SOPORTA ARREGLOS MULTIDIMENSIONALES Y PUNTEROS)
// ============================================================

tipo : tipoBase ('[' INT_LIT ']')* STAR*;

tipoBase : 'int32' 
         | 'float32' 
         | 'bool' 
         | 'rune' 
         | 'string' 
         | 'int'
         | ID
         ;

// ============================================================
// DECLARACIONES (A NIVEL GLOBAL Y LOCAL)
// ============================================================

declaracion : varDecl | constDecl | declCorta;

varDecl : 'var' listaIds tipo initVal?;

initVal : '=' initExpr;

initExpr : listaExpr | arregloInit;

constDecl : 'const' ID tipo '=' expr;

declCorta : listaIds ':=' listaExpr;

listaIds : ID (',' ID)*;

listaExpr : expr (',' expr)*;

// ============================================================
// INICIALIZACIÓN DE ARREGLOS
// ============================================================

arregloInit : '[' INT_LIT ']' tipoBase '{' initListaExpr? '}' 
            | '[' INT_LIT ']' tipoBase '{' inicioMatriz '}';

inicioMatriz : '{' initListaExpr? '}' (',' '{' initListaExpr? '}')*;

initListaExpr : expr (',' expr)* ','?;

// ============================================================
// BLOQUES Y SENTENCIAS
// ============================================================

bloque : '{' sentencia* '}';

sentencia : declaracion ';'
          | sentenciaExpr ';'
          | sentenciaIf
          | sentenciaFor
          | sentenciaSwitch
          | sentenciaReturn ';'
          | sentenciaBreak ';'
          | sentenciaContinue ';'
          | bloque
          ;

sentenciaExpr : expr;

sentenciaIf : 'if' expr bloque ('else' bloque)?;

sentenciaFor : 'for' (forClassico | forWhile | forInfinito);

forClassico : declCorta ';' expr ';' (incDec | asignacionCompuesta) bloque;

forWhile : expr bloque;

forInfinito : bloque;

sentenciaSwitch : 'switch' expr '{' casoSwitch* defaultSwitch? '}';

casoSwitch : 'case' listaExpr ':' sentencia*;

defaultSwitch : 'default' ':' sentencia*;

sentenciaReturn : 'return' listaExpr?;

sentenciaBreak : 'break';

sentenciaContinue : 'continue';

// ============================================================
// EXPRESIONES (ORDEN DE PRECEDENCIA CORRECTO)
// ============================================================

expr : expr '||' expr                                          # ExprOr
     | expr '&&' expr                                          # ExprAnd
     | expr ('==' | '!=') expr                                 # ExprIgualdad
     | expr ('<' | '<=' | '>' | '>=') expr                    # ExprRelacional
     | expr ('+' | '-') expr                                   # ExprAditiva
     | expr ('*' | '/' | '%') expr                             # ExprMultiplicativa
     | '!' expr                                                # ExprNot
     | '-' expr                                                # ExprNegacion
     | '+' expr                                                # ExprPos
     | '(' expr ')'                                            # ExprAgrupada
     | 'fmt.Println' '(' listaExpr? ')'                        # ExprFmtPrintln
     | 'nil'                                                   # ExprNil
     | ID '[' expr ']' ('[' expr ']')*                         # ExprIndiceArreglo
     | ID '(' listaExpr? ')'                                   # ExprLlamada
     | '&' ID                                                  # ExprReferencia
     | '*' ID                                                  # ExprDeref
     | ID ('+=' | '-=' | '*=' | '/=') expr                     # ExprAsignacionCompuesta
     | ID incDecOp                                             # ExprIncDec
     | ID '=' listaExpr                                        # ExprAsignacion
     | ID                                                      # ExprId
     | literal                                                 # ExprLiteral
     ;

incDecOp : '++' | '--';

// ============================================================
// LITERALES
// ============================================================

literal : INT_LIT                # LiteralEntero
        | FLOAT_LIT              # LiteralFlotante
        | BOOL_LIT               # LiteralBool
        | RUNE_LIT               # LiteralRune
        | STR_LIT                # LiteralString
        ;

// ============================================================
// TOKENS (ORDEN IMPORTANTE - MÁS ESPECÍFICO PRIMERO)
// ============================================================

// Literales booleanos
BOOL_LIT : 'true' | 'false';

// Literales rune (carácter entre comillas simples)
RUNE_LIT : '\'' ( ~['\\\r\n] | '\\' . ) '\'';

// Literales string (entre comillas dobles)
STR_LIT : '"' ( ~["\\\r\n] | '\\' . )* '"';

// Literales numéricos (FLOAT ANTES de INT - CRÍTICO)
FLOAT_LIT : [0-9]+ '.' [0-9]+
          | [0-9]+ '.'
          | '.' [0-9]+
          ;

INT_LIT : [0-9]+;

// Identificadores
ID : [a-zA-Z_][a-zA-Z0-9_.]*;

// Puntero
STAR : '*';

// Whitespace e ignorar
WS : [ \t\r\n]+ -> skip;
COMMENT : '//' ~[\r\n]* -> skip;
BLOCK_COMMENT : '/*' .*? '*/' -> skip;

~~~
