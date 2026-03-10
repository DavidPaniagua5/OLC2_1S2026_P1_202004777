grammar Grammar;

// ============================================================
// TOKENS IGNORADOS
// ============================================================
WS          : [ \t\r\n\u000B\u000C\u0000]+ -> channel(HIDDEN) ;
BlockComment: '/*' (BlockComment | .)*? '*/' -> channel(HIDDEN) ;
LineComment : '//' .*? ('\n' | EOF)          -> channel(HIDDEN) ;

// ============================================================
// PUNTO DE ENTRADA
// ============================================================
programa
    : declaracionTop* EOF
    ;

// Declaraciones de nivel superior
declaracionTop
    : funcDecl
    | varDecl
    | constDecl
    ;

// ============================================================
// FUNCIONES
// ============================================================
funcDecl
    : FUNC ID '(' listaParams? ')' tipoRetorno? bloque
    ;

tipoRetorno
    : tipo
    | '(' tipo (',' tipo)* ')'
    ;

listaParams
    : param (',' param)*
    ;

param
    : ID tipo
    ;

// ============================================================
// BLOQUE
// ============================================================
bloque
    : '{' sentencia* '}'
    ;

// ============================================================
// SENTENCIAS
// ============================================================
sentencia
    : varDecl
    | constDecl
    | declCorta
    | asignacion
    | asignacionCompuesta
    | incDec
    | sentenciaIf
    | sentenciaFor
    | sentenciaSwitch
    | sentenciaReturn
    | sentenciaBreak
    | sentenciaContinue
    | sentenciaExpr
    ;

// --- var x tipo = expr  /  var x, y tipo  /  var x, y tipo = e1, e2 ---
varDecl
    : VAR listaIds tipo ('=' listaExpr)?
    ;

// --- const ID tipo = expr ---
constDecl
    : CONST ID tipo '=' expr
    ;

// --- x := expr  /  x, y := e1, e2 ---
declCorta
    : listaIds ASSIGN_CORTO listaExpr
    ;

// --- x = expr  /  x, y = e1, e2 ---
asignacion
    : listaLvalue '=' listaExpr
    ;

// --- x += expr  x -= expr  x *= expr  x /= expr ---
asignacionCompuesta
    : lvalue op=(PLUS_ASSIGN | MINUS_ASSIGN | STAR_ASSIGN | SLASH_ASSIGN) expr
    ;

// --- x++  x-- ---
incDec
    : lvalue op=(INC | DEC)
    ;

// ============================================================
// SENTENCIAS DE CONTROL
// ============================================================
sentenciaIf
    : IF expr bloque (ELSE (sentenciaIf | bloque))?
    ;

sentenciaFor
    : FOR declCorta ';' expr ';' (incDec | asignacionCompuesta) bloque  # ForClassico
    | FOR expr bloque                                                     # ForWhile
    | FOR bloque                                                          # ForInfinito
    ;

sentenciaSwitch
    : SWITCH expr '{' casoSwitch* defaultSwitch? '}'
    ;

casoSwitch
    : CASE listaExpr ':' sentencia*
    ;

defaultSwitch
    : DEFAULT ':' sentencia*
    ;

sentenciaReturn
    : RETURN listaExpr?
    ;

sentenciaBreak
    : BREAK
    ;

sentenciaContinue
    : CONTINUE
    ;

sentenciaExpr
    : expr
    ;

// ============================================================
// LVALUES
// ============================================================
lvalue
    : ID ('[' expr ']')*
    ;

listaLvalue
    : lvalue (',' lvalue)*
    ;

// ============================================================
// LISTAS
// ============================================================
listaIds
    : ID (',' ID)*
    ;

listaExpr
    : expr (',' expr)*
    ;

// ============================================================
// EXPRESIONES  (precedencia de menor a mayor, de arriba a abajo)
// ============================================================
expr
    // OR  (menor precedencia)
    : expr OR expr                                              # ExprOr

    // AND
    | expr AND expr                                             # ExprAnd

    // Igualdad
    | expr op=(EQ | NEQ) expr                                   # ExprIgualdad

    // Relacional
    | expr op=(LT | LE | GT | GE) expr                         # ExprRelacional

    // Suma / Resta
    | expr op=('+' | '-') expr                                  # ExprAditiva

    // Multiplicación / División / Módulo
    | expr op=('*' | '/' | '%') expr                           # ExprMultiplicativa

    // Unarios
    | '!' expr                                                  # ExprNot
    | '-' expr                                                  # ExprNegacion
    | '&' ID                                                    # ExprReferencia
    | '*' ID                                                    # ExprDeref

    // Primarios
    | '(' expr ')'                                              # ExprAgrupada
    | FMT_PRINTLN '(' listaExpr? ')'                           # ExprFmtPrintln
    | ID '(' listaExpr? ')'                                    # ExprLlamada
    | ID '[' expr ']'                                          # ExprIndiceArreglo
    | ID                                                        # ExprId
    | literal                                                   # ExprLiteral
    | NIL                                                       # ExprNil
    ;

// ============================================================
// LITERALES
// ============================================================
literal
    : INT_LIT    # LiteralEntero
    | FLOAT_LIT  # LiteralFlotante
    | BOOL_LIT   # LiteralBool
    | RUNE_LIT   # LiteralRune
    | STR_LIT    # LiteralString
    ;

// ============================================================
// TIPOS
// ============================================================
tipo
    : 'int32'
    | 'float32'
    | 'bool'
    | 'rune'
    | 'string'
    | 'int'
    | '[' INT_LIT ']' tipo
    | '*' tipo
    | ID
    ;

// ============================================================
// PALABRAS RESERVADAS  (deben ir antes que ID)
// ============================================================
FUNC     : 'func'     ;
VAR      : 'var'      ;
CONST    : 'const'    ;
IF       : 'if'       ;
ELSE     : 'else'     ;
FOR      : 'for'      ;
SWITCH   : 'switch'   ;
CASE     : 'case'     ;
DEFAULT  : 'default'  ;
RETURN   : 'return'   ;
BREAK    : 'break'    ;
CONTINUE : 'continue' ;
NIL      : 'nil'      ;

// ============================================================
// OPERADORES
// ============================================================
ASSIGN_CORTO : ':=' ;
PLUS_ASSIGN  : '+=' ;
MINUS_ASSIGN : '-=' ;
STAR_ASSIGN  : '*=' ;
SLASH_ASSIGN : '/=' ;
INC          : '++' ;
DEC          : '--' ;
AND          : '&&' ;
OR           : '||' ;
EQ           : '==' ;
NEQ          : '!=' ;
LE           : '<=' ;
GE           : '>=' ;
LT           : '<'  ;
GT           : '>'  ;

// ============================================================
// TOKENS LITERALES
// ============================================================
// BOOL_LIT debe ir ANTES de ID para que 'true'/'false' no sean ID
BOOL_LIT : 'true' | 'false' ;

// Rune: carácter entre comillas simples con soporte de escape
RUNE_LIT : '\'' ( ~['\\\r\n] | '\\' . ) '\'' ;

// String: entre comillas dobles con soporte de escape
STR_LIT  : '"' ( ~["\\\r\n] | '\\' . )* '"' ;

// Float debe ir ANTES que INT para que "45.50" sea un token FLOAT
FLOAT_LIT : [0-9]+ '.' [0-9]*
          | '.' [0-9]+
          ;

INT_LIT  : [0-9]+ ;

// fmt.Println como token único para evitar ambigüedad con el punto
FMT_PRINTLN : 'fmt.Println' ;

// Identificadores (después de todas las palabras reservadas)
ID : [a-zA-Z_][a-zA-Z0-9_]* ;
