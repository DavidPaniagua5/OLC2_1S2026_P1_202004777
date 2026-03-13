# GRAMÁTICA

---

## Tokens ignorados

Al inicio de la gramática se define el paquete en el que está el archivo, y que es de tipo Grammar, además se ignoran tokens reconocidos por ANTLR4 que no sirven o de tipo comentario, para obviarlos y que interfieran en el flujo del programa.

~~~php
grammar Grammar;

WS          : [ \t\r\n\u000B\u000C\u0000]+ -> channel(HIDDEN) ;
BlockComment: '/*' (BlockComment | .)*? '*/' -> channel(HIDDEN) ;
LineComment : '//' .*? ('\n' | EOF)          -> channel(HIDDEN) ;

~~~

---

## Punto de entrada

Se define el terminal en el que el programa iniciará a realizar el análisis y en donde termina, con el token EOF (EndOfFile).

~~~php
programa
    : declaracionTop* EOF
    ;
~~~

---

## Declaraciones de nivel superior

Define las deckaraciones principales del lenguaje, siendo funciones, variables y constantes.

~~~php
declaracionTop
    : funcDecl
    | varDecl
    | constDecl
    ;
~~~

### Declaración de funciones

Como se explicó con anterioridad, se utiliza esta regla para definir las declaraciones de las funciones, para ello se utilizan los operadores de repetición para defiir en la misma regla funciones con parámetros y sin parámetros, además de si retornan algo o no.

~~~php
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
~~~

#### Bloque dentro de fuciones

Dentro de las funciones se utiliza la regla de Bloque para definir las instrucciones dentro de la función, acá se puede realizar cualquier funcionalidad del lenguaje, como imprimir, declarar variables o llamar a funciones.

~~~php
bloque
    : '{' sentencia* '}'
    ;
~~~

#### Sentencias

Esta instrucción hace referencia a todas las declaraciones que se pueden hacer dentro de las funciones, acá de maneja toda la parte importante del lenguaje.

~~~php
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

~~~

Dentro de las sentencias, se pueden definir varias opciones interesantes:

- Declaración de variables:

Acá se realiza el análisis de los diferentes tipos de declaración de variables que soporta el lenguaje. Para una misma regla se definen el soporte para los diferentes tipos que puede tener la variable, además de los múltiples casos de declaración.

~~~php
// var x tipo = expr  /  var x, y tipo  /  var x, y tipo = e1, e2
varDecl
    : VAR listaIds tipo ('=' listaExpr)?
    ;
~~~

- Declaración de constantes

Al igual que las constantes, se desarrolla la regla para reconocer las constantes, siguindo la misma estructura.

~~~php
// --- const ID tipo = expr ---
constDecl
    : CONST ID tipo '=' expr
    ;
~~~

- Declaración corta: Se agrega el soporte de la declaración corta, en donde se asignan valores a variables ya declaradas.
~~~php
// --- x := expr  /  x, y := e1, e2 ---
declCorta
    : listaIds ASSIGN_CORTO listaExpr
    ;
~~~

- Asignación: Asignación normal a una o múltiples variables.

~~~php
// --- x = expr  /  x, y = e1, e2 ---
asignacion
    : listaLvalue '=' listaExpr
    ;

~~~

- Asignación compuesta: Maneja los diferentes tipos de asignación a números, disponible en múltilpres lenguajes, esta modifica el mismo valor de la variable, difernte para cada tipo, puede aumentar o disminuir el valor.

~~~php
// --- x += expr  x -= expr  x *= expr  x /= expr ---
asignacionCompuesta
    : lvalue op=(PLUS_ASSIGN | MINUS_ASSIGN | STAR_ASSIGN | SLASH_ASSIGN) expr
    ;

// --- x++  x-- ---
incDec
    : lvalue op=(INC | DEC)
    ;

~~~

---

#### Sentencias de control

Acá se definen las diferentes sentencias de control del lenguaje, tales como if, else, for, etc.

- If: Esta sentencia se utiliza para el bloque if, se utiliza una recursividad a `Bloque`, haciendo posible realizar múltiples acciones dentro de la sentencia. Además se añade el manejo del bloque Else como opcional.

~~~php
sentenciaIf
    : IF expr bloque (ELSE (sentenciaIf | bloque))?
    ;
~~~

- Ciclo For: Para el ciclo for se debe de implementar el soporte para la declaración de variables dentro de la declaración del bloque, tal como se maneja en cualquier lenguaje moderno. Además maneja múltiples casos de declaración.

~~~php
sentenciaFor
    : FOR declCorta ';' expr ';' (incDec | asignacionCompuesta) bloque
    | FOR expr bloque                         
    | FOR bloque     
    ;

~~~

- Sentencia Switch: Maneja la declaración de Switch-case-default, estando capacitada para soportar la declaración de case y default.

~~~php
sentenciaSwitch
    : SWITCH expr '{' casoSwitch* defaultSwitch? '}'
    ;

casoSwitch
    : CASE listaExpr ':' sentencia*
    ;

defaultSwitch
    : DEFAULT ':' sentencia*
    ;

~~~

#### Sentencias de transferencia

- Return: Se Utiliza para definir que hay un return desde una sentencia de control de flujo.

~~~php
sentenciaReturn
    : RETURN listaExpr?
    ;
~~~

- Break: Define que acaba una ejecución dentro de una estructura que lo soporta

~~~php
sentenciaBreak
    : BREAK
    ;
~~~

- Continue: Maneja la sentencia continue

~~~php
sentenciaContinue
    : CONTINUE
    ;
~~~

Las sentencias de transferencia únicamente manejan reglas con las palabras reservadas propias para la acción, no requieren reglas estríctas o específicas, pues solo deben reconcer el patrón.

#### Arreglos

Para la definición de arreglos se utilizan múltiples reglas:

- lvalue: Es la definición principal del arreglo, Utiliza `ID` para recurrir a la regla de asignación, además de utilizar `expr` para validar las expresiones válidas dentro de los arreglos.

~~~php
lvalue
    : ID ('[' expr ']')*
    ;

listaLvalue
    : lvalue (',' lvalue)*
    ;

listaIds
    : ID (',' ID)*
    ;

listaExpr
    : expr (',' expr)*
    ;
~~~

#### Literales arreglos

Acá se definen las literlaes que pueden usarse para la declaración y acceso de una posición de un arreglo.

~~~php
arregloLiteral
    : '[' INT_LIT ']' tipo literalValue
    ;

literalValue
    : '{' elementList? ','? '}'
    ;

elementList
    : elemento (',' elemento)*
    ;

elemento
    : expr
    | literalValue 
    ;
~~~

### Expresiones

Acá se manejan las múltiples operaciones que puede realiar el lenguaje, se debe seguir el orden de precedencia (de menor a mayor), con esto se asegura un correcto flujo de las operaciones.

~~~php
expr
    // OR
    : expr OR expr  

    // AND
    | expr AND expr    

    // Igualdad
    | expr op=(EQ | NEQ) expr  

    // Relacional
    | expr op=(LT | LE | GT | GE) expr 

    // Suma / Resta
    | expr op=('+' | '-') expr    
    // Multiplicación / División / Módulo
    | expr op=('*' | '/' | '%') expr

    // Unarios
    | '!' expr    
    | '-' expr     
    | '&' ID                            
    | '*' ID           
    // Primarios
    | '(' expr ')
    | FMT_PRINTLN '(' listaExpr? ')
    | ID '(' listaExpr? ')
    | arregloLiteral                       
    | ID ('[' expr ']')+                                  
    | ID                               
    | literal                        
    | NIL                        

    ;
~~~

#### Literales

Acá se maneja el verdadero valor de las expresiones de los arreglos, se maneja así para evitar errores.

~~~php
literal
    : INT_LIT    # LiteralEntero
    | FLOAT_LIT  # LiteralFlotante
    | BOOL_LIT   # LiteralBool
    | RUNE_LIT   # LiteralRune
    | STR_LIT    # LiteralString
    ;
~~~

### Tipos

Definición de los múltiples tipos que pueden soportar las variables y constantes del lenguaje.

~~~php
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
~~~

### Palabras reservadas

Definición de todas las palabras reservadas del lenguaje, son literales a como se deben escripir para ser reconocidas.

~~~ php
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
~~~

### Operadores

Acá se definen los múltiples operadores del lenguaje, se utilizan todos los definidos en el enunciado.

~~~php
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
~~~

### Tokens literales

- Bool: Palabras reservadas para valores booleanos.
~~~php
BOOL_LIT : 'true' | 'false' ;
~~~
- Rune: Expresión regular de carácter entre comillas simples con soporte de cadenas de escape.

~~~php
RUNE_LIT : '\'' ( ~['\\\r\n] | '\\' . ) '\'' ;
~~~

- String: Expresión regular que reconoce cadenas entre comillas, con soporte para cadenas de escape.

~~~php
STR_LIT  : '"' ( ~["\\\r\n] | '\\' . )* '"' ;
~~~

- Float: Expresión regular para manejar los números decimales de tipo Float.
~~~php
FLOAT_LIT : [0-9]+ '.' [0-9]*
          | '.' [0-9]+
          ;
~~~

- Números: Expresión regular para manejar los números enteros.

~~~php
INT_LIT  : [0-9]+ ;
~~~

- fmt.Println: Reconoce la palabra reservada para imprimir en consola.
~~~php
FMT_PRINTLN : 'fmt.Println' ;
~~~

- Identificadores: Expresión regular para reconocer los ID de las posibles variables, constantes o funciones.

~~~php
ID : [a-zA-Z_][a-zA-Z0-9_]* ;
~~~
