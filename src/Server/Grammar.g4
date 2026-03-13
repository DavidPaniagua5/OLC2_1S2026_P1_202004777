grammar Grammar;

// ============================================================
// PROGRAMA Y FUNCIONES
// ============================================================

programa : (funcionDef | declaracion)* main EOF;

main : 'func' 'main' '(' ')' bloque;

funcionDef : 'func' ID '(' parametros? ')' tipoRetorno? bloque;

parametros : parametro (',' parametro)*;

parametro : ID tipo;

tipoRetorno : tipo | '(' tipo (',' tipo)* ')';

// ============================================================
// DECLARACIONES
// ============================================================

declaracion : varDecl | constDecl;

varDecl : 'var' listaIds tipo ('=' listaExpr)?;

constDecl : 'const' ID tipo '=' expr;

declCorta : listaIds ':=' listaExpr;

listaIds : ID (',' ID)*;

listaExpr : expr (',' expr)*;

// ============================================================
// TIPOS (MEJORADO PARA MULTIDIMENSIONALIDAD)
// ============================================================

tipo : tipoBase ('[' INT_LIT ']')* 
     | STAR tipo
     ;

tipoBase : 'int32' 
         | 'float32' 
         | 'bool' 
         | 'rune' 
         | 'string' 
         | 'int'
         | ID
         ;

// ============================================================
// SENTENCIAS
// ============================================================

bloque : '{' sentencia* '}';

sentencia : sentenciaExpr ';'
          | varDecl ';'
          | constDecl ';'
          | declCorta ';'
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
// EXPRESIONES (SIMPLIFICADAS PARA EVITAR CONFLICTOS)
// ============================================================

expr : expr '||' expr                                          # ExprOr
     | expr '&&' expr                                          # ExprAnd
     | expr ('==' | '!=') expr                                 # ExprIgualdad
     | expr ('<' | '<=' | '>' | '>=') expr                    # ExprRelacional
     | expr ('+' | '-') expr                                   # ExprAditiva
     | expr ('*' | '/' | '%') expr                             # ExprMultiplicativa
     | '!' expr                                                # ExprNot
     | '-' expr                                                # ExprNegacion
     | '(' expr ')'                                            # ExprAgrupada
     | 'fmt.Println' '(' listaExpr? ')'                        # ExprFmtPrintln
     | 'nil'                                                   # ExprNil
     | ID '[' expr ']' ('[' expr ']')*                         # ExprIndiceArreglo
     | ID '(' listaExpr? ')'                                   # ExprLlamada
     | '&' ID                                                  # ExprReferencia
     | '*' ID                                                  # ExprDeref
     | ID '=' listaExpr                                        # Asignacion
     | ID ('+=' | '-=' | '*=' | '/=') expr                     # AsignacionCompuesta
     | (ID | lvalue) ('++' | '--')                             # IncDecExpr
     | ID                                                      # ExprId
     | literal                                                 # ExprLiteral
     ;

lvalue : ID ('[' expr ']')*;

// ============================================================
// LITERALES
// ============================================================

literal : LiteralEntero   
        | LiteralFlotante 
        | LiteralBool     
        | LiteralRune     
        | LiteralString   
        ;

LiteralEntero : INT_LIT;

LiteralFlotante : FLOAT_LIT;

LiteralBool : BOOL_LIT;

LiteralRune : RUNE_LIT;

LiteralString : STR_LIT;

// ============================================================
// TOKENS (ORDEN IMPORTANTE - MÁS ESPECÍFICO PRIMERO)
// ============================================================

// Literales booleanos
BOOL_LIT : 'true' | 'false' ;

// Literales rune (carácter entre comillas simples)
RUNE_LIT : '\'' ( ~['\\\r\n] | '\\' . ) '\'' ;

// Literales string (entre comillas dobles)
STR_LIT  : '"' ( ~["\\\r\n] | '\\' . )* '"' ;

// Literales numéricos (FLOAT ANTES de INT - CRÍTICO)
FLOAT_LIT : [0-9]+ '.' [0-9]+
          | [0-9]+ '.'
          | '.' [0-9]+
          ;

INT_LIT  : [0-9]+ ;

// Identificadores (incluyendo fmt.Println)
ID : [a-zA-Z_][a-zA-Z0-9_.]* ;

// Operadores simples
STAR : '*' ;

// Delimitadores
LPAREN : '(' ;
RPAREN : ')' ;
LBRACE : '{' ;
RBRACE : '}' ;
LBRACKET : '[' ;
RBRACKET : ']' ;
COMMA : ',' ;
SEMICOLON : ';' ;
COLON : ':' ;
DOT : '.' ;

// Whitespace e ignorar
WS : [ \t\r\n]+ -> skip ;
COMMENT : '//' ~[\r\n]* -> skip ;
BLOCK_COMMENT : '/*' .*? '*/' -> skip ;
