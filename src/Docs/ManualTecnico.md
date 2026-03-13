# MANUAL TÉCNICO

---

Se utiliza una arquitectura cliente-servidor, con esto se mantienen separadas las lógicas del frontend (Client) y el backend (Server), logrando independencia de los datos.

---

## Cliente

Desarrollado en **Vite+React**, para su instalación se abre una terminal, se ejecuta el comando

~~~cmd
npm run vite@latest
~~~

Al desarollarse en React, se selecciona esta opción y el lenguaje Javascript.

Se puede obviar este paso, únicamente clonando el repositorio, con el comando:

~~~git
git clone https://github.com/DavidPaniagua5/OLC2_1S2026_P1_202004777
~~~

luego de clonarlo, se accede a la carpeta de Client

~~~cmd
cd .\src\Client\
~~~

Se ejecuta el comando

~~~cmd
npm install -y
~~~

Al eejcutar el comando, se creará la carpeta `node_modules`, la cuál tendrás las dependencias necesarias para levantar el frontend y que sea capaz de renderizar los componentes.

En el Cliente se utiliza un modo especial para poder visualizar de mejor manera el editor de texto. Se utiliza la librería `react-ace`, para devolver el componente se utiliza:

~~~react
return (
    <AceEditor
            mode="custom"
            theme="monokai"
            onChange={setCode}
            name="code_editor"
            editorProps={{ $blockScrolling: true }}
            value={code}
            setOptions={{
                // Habilitar autocompletado
                enableBasicAutocompletion: true,
                enableLiveAutocompletion: true, // Clave para las sugerencias en tiempo real
                enableSnippets: true,
                showLineNumbers: true,
                tabSize: 4,
            }}
            style={{ width: '100%', height: '400px', border: '1px solid #ddd' }}
        />
);
~~~

Acá se utiliza un modo customizado al lenguaje, para que soporte las palabras reservadas y tipos de datos del lenguaje. Este modo se desarrolla en `modo-lenguaje`

~~~javascript
ace.define("ace/mode/golampi_highlight_rules", function(require, exports, module) {
    "use strict";
    var oop = require("../lib/oop");
    var TextHighlightRules = require("./text_highlight_rules").TextHighlightRules;

    var GolampiHighlightRules = function() {
        this.$rules = {
            "start": [
                // 1. COMENTARIOS
                { token: "comment.block", regex: "/\\*", next: "comment_multi" },
                { token: "comment.line", regex: "//.*$" },

                // 2. CADENAS Y CARACTERES
                { token: "string", regex: '".*?"' },
                { token: "string.character", regex: "'.*?'" },

                // 3. PALABRAS CLAVE (Control de Flujo y Declaración)
                {
                    token: "keyword.control",
                    regex: "\\b(if|else|switch|case|default|for|break|continue|return|func|var|const)\\b"
                },

                // 4. TIPOS DE DATOS ESTÁTICOS
                {
                    token: "storage.type",
                    regex: "\\b(int32|float32|bool|rune|string)\\b"
                },

                // 5. CONSTANTES DE LENGUAJE
                { token: "constant.language", regex: "\\b(true|false|nil)\\b" },

                // 6. FUNCIONES EMBEBIDAS
                {
                    token: "support.function",
                    regex: "\\b(fmt\\.Println|len|now|substr|typeOf)\\b"
                },

                // 7. OPERADORES (Incluyendo asignación corta := y punteros)
                {
                    token: "keyword.operator",
                    regex: ":=|\\+=|-=|\\*=|/=|==|!=|<=|>=|&&|\\|\\||[=+*/%<>!&]"
                },

                // 8. NÚMEROS
                { token: "constant.numeric", regex: "\\b\\d+(\\.\\d+)?\\b" },

                // 9. IDENTIFICADORES 
                { token: "variable.other", regex: "\\b[a-zA-Z_][a-zA-Z0-9_]*\\b" },

                { token: "text", regex: "\\s+" }
            ],
            "comment_multi": [
                { token: "comment.block", regex: "\\*/", next: "start" },
                { defaultToken: "comment.block" }
            ]
        };
    };

    oop.inherits(GolampiHighlightRules, TextHighlightRules);
    exports.GolampiHighlightRules = GolampiHighlightRules;
});

ace.define("ace/mode/custom", function(require, exports, module) {
    "use strict";
    var oop = require("../lib/oop");
    var TextMode = require("./text").Mode;
    var GolampiHighlightRules = require("./golampi_highlight_rules").GolampiHighlightRules;

    var Mode = function() {
        this.HighlightRules = GolampiHighlightRules;
        this.$behaviour = this.$defaultBehaviour;
    };
    oop.inherits(Mode, TextMode);

    (function() {
        this.$id = "ace/mode/custom";
        this.type = "text";
    }).call(Mode.prototype);

    exports.Mode = Mode;
});
~~~

## Servidor

En el servidor se utiliza un `patrón Visitor`, este patrón de diseño se utiliza para desarrollar la solución al compilador del lenguaje.
El servidor está desarrollado en `PHP` y los analizadores se desarrollan con la ayuda de `ANTLR`. Para la instalación de Antlr se siguen los siguiente pasos:

1. El proyecto se trabaja en Debian12, por lo que el primer paso es ejecutar el siguiente comando en consola:

~~~cmd
sudo apt update && sudo apt upgrade -y
~~~

2. Se instalan las dependencias necesarias, tales como el lenguaje u otras dependencias para que la computadora puede ejecutar el código en `php`

~~~cmd
 sudo apt install php-cli php-mbstring php-xml unzip curl -y
~~~

3. Se descarga el composer, necesario para levantar el servidor, se utiliza el comando:

~~~cmd
curl -sS https://getcomposer.org/installer | php
~~~

4. Se instala java, necesario para ANTLR:

~~~cmd
sudo apt install default-jdk -y
~~~

5. Se instalan las librerias que necesita php en el proyecto, acá se debe verificar que estpe en el mismo nivel que `composer.json`:

~~~cmd
composer install
~~~

6. Se ejecuta antrl para tener los visitors necesarios para el análisis

~~~cmd
 antlr4 -Dlanguage=PHP Grammar.g4 -visitor -o ANTLRv4/
~~~

7. Con todo instalado, se levanta el servidor_

~~~cmd
php -S localhost:8000
~~~

### Index

El servidor se levanta con el comando

~~~cmd
php -S localhost:8000
~~~

Al ejecutar el comando se despliega la lógica de `index.php`, este es el orquestador del compilador, aca se tiene la lógica principal del lenguaje, se define la estructura que tendrá la respuesta al hacer una solicitud al servidor:

~~~php
$respuesta = [
    'success'  => false,
    'output'   => '',
    'svg'      => '',
    'simbolos' => [],
    'errors'   => [],
];
~~~

Dede el frontend se manda un json con el código a ejecutar, se debe limpiar para solo tener el texto a ejecutar, para ello se utiliza

~~~php
$data  = json_decode(file_get_contents('php://input'), true);
$input = trim($data['expression'] ?? '');
~~~

Para utilizar el manejador de errores compartido entre los analizadores, se utiliza

~~~php
$errores = new ManejadorErrores();
~~~

Se deben definir los analizadores, y que se le envía a los mismos, primero se hace el análisis léxico, luego el análisis sintáctico. Acá es importante definir que se utilisará el ErrorListener propio, definido en la clase `ErrorListener`, siguiendo con la lógica del patrón `visitor`.

~~~php
    $inputStream = InputStream::fromString($input);
    $lexer       = new GrammarLexer($inputStream);

    // Quitar el listener de consola por defecto de ANTLR y poner el mio
    $lexer->removeErrorListeners();
    $lexer->addErrorListener(new ErrorListener($errores, 'Léxico'));

    // ── ANÁLISIS SINTÁCTICO ───────────────────────────────────────────────
    $tokens = new CommonTokenStream($lexer);
    $parser = new GrammarParser($tokens);
~~~

El intérprete tratará de seguir con el análisis a pesar de los errores, para ello se habilita la opción `DefaultErrorStrategy()`, esto hace un manejo de errores continuo, no se cierra al encontrar el primer error, si no que toma todos los errores posibles.

~~~php
$parser->setErrorHandler(new DefaultErrorStrategy());
~~~

A pesar de existir errores, se podrá seguir ejecutando el código, por ello se debe de crear el AST sin importar si hay errores o no

~~~php
try {
        $respuesta['svg'] = GeneradorDot::generarSVG($tree, $parser);
    } catch (\Throwable $e) {
        $respuesta['svg'] = '';
    }
~~~

Al no existir errores se realiza el análisis utilizando el AST

~~~php
if (!$errores->tieneErrores()) {
        $interprete = new Interpreter($errores);
        $salida     = $interprete->visit($tree);
        if (!$errores->tieneErrores()){
            $respuesta['success']  = true;
            $respuesta['output']   = $salida;
            $respuesta['simbolos'] = $interprete->tablaSimbolos();
        }
    } else {
        // Hay errores de análisis — devolver el árbol parcial pero no ejecutar
        $respuesta['success'] = false;
    }
~~~

### Interpreter

Esta clase utiliza el `Patrón Visitor` pues es el encargado de llevar toda la lógica principal del intérprete, tiene la responsabilidad de delegar la ejecución a los visitors especializados.

Esta clase debe tener la capacidad de generar y acceder a diferentes entornos, por lo que acá se muestra como crea el entorno global, este es en donde se manejarán cada una de las funciones del programa, recordando que la puerta de entrada es la función `main`

~~~php
  public function __construct(?ManejadorErrores $errores = null)
    {
        $this->errores = $errores ?? new ManejadorErrores();
        $envGlobal = new Environment();
        $envLocal = new Environment();

        $this->programVisitor = new ProgramVisitor(
            $envGlobal,
            $envLocal, 
            $this->errores
        );
    }
~~~

La función más importante de `Interpreter` es `visit($tree)`, esta función realiza el análisis del árbol de análisis sintáctico, genera la tabla de símbolo y los entornos necesarios para el desarrollo del programa.

~~~php
public function visit($tree): string
    {
        return $this->programVisitor->visit($tree);
    }

    public function tablaSimbolos(): array
    {
        // Combinar funciones globales + símbolos registrados
        $funciones = $this->programVisitor->obtenerEnv()->exportarConAmbito('global');
        $registrados = $this->programVisitor->obtenerRegistroSimbolos();
        return array_merge($funciones, $registrados);
    }

    public function obtenerProgramVisitor(): ProgramVisitor
    {
        return $this->programVisitor;
    }
~~~

### ProgramVisitor

El `ProgramVisitor` es el viitor encargado de manejar los visitors para cada una de las funcionalidades del lenguaje. Su constructor tiene los `enviroment` del lenguaje, para mantener las declaraciones independientes en cada función.

~~~php
public function __construct(
        Environment $envGlobal,
        Environment $env,
        ManejadorErrores $errores,
        string $ambitoActual = 'global'
    ) {
        parent::__construct($envGlobal, $env, $errores, $ambitoActual);
        $this->envGlobalLocal = $envGlobal;
    }
~~~


Una de las funciones principales de este Visitor, es que, es el encargado de buscar el punto de entrada del lenguaje, que es la función main

~~~php
 try {
            $main = $this->envGlobal->obtener('main');
        } catch (\RuntimeException $e) {
            $this->errores->agregar('Semántico', 'No se encontró la función main.');
            return $this->consola;
        }
~~~

Como busca el punto de entrada, también es el encargado de registrar las funciones, para validar que no existan duplicado, o que una función exista fuera de la función main, valida que en caso de existir retonos los devuelva con el tipo y valor correspondientes.

~~~php
private function registrarFuncion(FuncDeclContext $ctx): void
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

        $tipoRet = ($ctx->tipoRetorno() !== null)
            ? $ctx->tipoRetorno()->getText()
            : Result::NIL;

        $sym         = new Symbol($tipoRet, $ctx->bloque(), Symbol::CLASE_FUNCION, 0, 0);
        $sym->params = $params;
        $sym->nombre = $nombre;
        $this->envGlobal->declarar($nombre, $sym);
    }
~~~

La función ´visitBloque´ tiene una funcionalidad importante, ya que acá se llama a la clase ´ExpressionVisitor´, esta es la encargada de manejar la lógica del lenguaje, de las declaraciones y de los ciclos como el for, if, etc.

Con esta simple declaración se declara un nuevo bloque, que puede ser llamado también un nuevo entorno.

~~~php
$visitor = new ExpressionVisitor(
                $this->envGlobal,
                $this->env,
                $this->errores,
                $this->ambitoActual
            );
~~~

### ExpresionVisitor

ExpressionVisitor es el encargado de toda la lógica del lenguaje, acá se manejan los entornos, las declaraciones, las sentencias y demás funciones del lenguaje.

Para la declaración de variables, se uriliza una función robusta, que maneja la declaración dentro de entornos o dentro de la funciones, 

~~~php
public function visitVarDecl(VarDeclContext $ctx): Result
    {
        $tipo = $ctx->tipo() !== null ? $ctx->tipo()->getText() : null;
        $ids   = $ctx->listaIds()->ID();
        $exprs = $ctx->listaExpr() !== null ? $ctx->listaExpr()->expr() : [];

        $valores = [];
        if (!empty($exprs)) {
            if (count($exprs) === 1) {
                $r = $this->visit($exprs[0]);
                if ($r->tipo === '__multi__' && is_array($r->valor)) {
                    $valores = $r->valor; // desempaquetar múltiples retornos
                } else {
                    $valores = [$r];
                }
            } else {
                foreach ($exprs as $e) {
                    $valores[] = $this->visit($e);
                }
            }
        }

        foreach ($ids as $i => $nodoId) {
            $nombre = $nodoId->getText();
~~~

Maneja los errores semánticos de declaración duplicada en un mismo entorno, esto asegura un código limpio y un manejo eficiente de la memoria y aplicación de leyes estrictas del lenguaje. 

~~~php
            if ($this->env->existeLocal($nombre)) {
                $this->errores->agregar(
                    'Semántico',
                    "Identificador '{$nombre}' ya ha sido declarado en este ámbito.",
                    $nodoId->getSymbol()->getLine(),
                    $nodoId->getSymbol()->getCharPositionInLine()
                );
                continue;
            }
~~~

Se valida que la asignación tenga un valor en la declaración, dependiendo del tipo asignado. En caso de no exisitr, se agrega una funcionalidad para agregar valores por defecto, dependiendo del tipo de variable declarada.

~~~php
            if (isset($valores[$i])) {
                $res       = $valores[$i];
                $tipoFinal = $tipo ?? $res->tipo;
                $valor     = ValueFormatter::castear($res, $tipoFinal);
            } else {
                $tipoFinal = $tipo ?? Result::NIL;
                $valor     = $this->crearValorDefecto($tipoFinal);
            }

            $sym = new Symbol($tipoFinal, $valor, Symbol::CLASE_VARIABLE, 0, 0);
            $this->env->declarar($nombre, $sym);

            $this->registrarSimbolo(
                $nombre, $tipoFinal, $valor, Symbol::CLASE_VARIABLE,
                $nodoId->getSymbol()->getLine(),
                $nodoId->getSymbol()->getCharPositionInLine()
            );
        }
        return Result::nulo();
    }
~~~

El lenguaje permite declaraciones compuestas, para poder manejar diversidad de valores en una misma línea. Esta rsive el contexto generado por Antlr y utiliza el nombre para validar que no se haya declarado con anterioridad.

~~~php
public function visitAsignacionCompuesta(AsignacionCompuestaContext $ctx): Result
    {
        $nombre = $ctx->lvalue()->ID()->getText();

        try {
            $sym = $this->env->obtener($nombre);
~~~

El manejo de errores sigue presente, se debe declarar con anterioridad para poder asignarle un valor, por lo que debe existir en el entorno actual o global para poder cambiar o asignarle un valor, si no se asigna el error.

~~~php
        } catch (\RuntimeException $e) {
            $this->errores->agregar(
                'Semántico',
                "Variable '{$nombre}' no declarada.",
                $ctx->lvalue()->ID()->getSymbol()->getLine(),
                $ctx->lvalue()->ID()->getSymbol()->getCharPositionInLine()
            );
            return Result::nulo();
        }
~~~

Se utiliza el lado derecho de la expresión como el valor que se asignará y el lado izquierdo se refiere a la variable que tomará la declaración, se agrega una validación adicional para asegurarce que exista el símbolo '='.

~~~php
        $derecha = $this->visit($ctx->expr());
        $izq     = new Result($sym->tipo, $sym->valor);
        $op      = rtrim($ctx->op->getText(), '=');

        $nuevo = $this->binarioOp->aplicar($op, $izq, $derecha);
        if ($nuevo->tipo !== Result::NIL) {
            $sym->valor = $nuevo->valor;
        }

        return Result::nulo();
    }
~~~

Se definen tambien estructuras de control, como el ´IF´. Este inicia como los demás, tomando su respectivo contexto como parámetro y evaluando que la condición sea de tipo booleano.

~~~php
public function visitSentenciaIf($ctx): Result
    {
        $cond = $this->visit($ctx->expr());

        if ($cond->tipo !== Result::BOOL) {
~~~

En caso de no ser de tipo booleano, se maneja el error semántico, pues el if exige que la condición a evaluar sea booelana para funcionar.

~~~php
            $this->errores->agregar(
                'Semántico',
                "La condición del 'if' debe ser bool, se obtuvo '{$cond->tipo}'.",
                $ctx->expr()->getStart()->getLine(),
                $ctx->expr()->getStart()->getCharPositionInLine()
            );
            return Result::nulo();
        }
~~~

Acá ocurre la lógica real del ciclo if, se evalua la condición, en caso de cumplirse, se ejecutará ´visitBloque´, el cual ejecutará las instrucciones contenidas dentro del if, en caso de cumplir la condición. También se tiene soporte para evaluar ´else if´ y ´else´, agregando el soporte completo de condiciones al lenguaje.

~~~php

        if ($cond->valor) {
            return $this->visitBloque($ctx->bloque(0));
        } else {
            // else if
            if ($ctx->sentenciaIf() !== null) {
                return $this->visit($ctx->sentenciaIf());
            }
            // else bloque
            $bloques = $ctx->bloque();
            if (count($bloques) > 1) {
                return $this->visitBloque($bloques[1]);
            }
        }

        return Result::nulo();
    }
~~~

Se reconocen 2 estructuras para el for, un for tradicional y su variante, el ciclo while. El ciclo while, inicia evaluando la condición de tipo booleaa, con su manejo de error en caso no cumplir, y terminando la ejecución al tener un false.


~~~php
public function visitForWhile($ctx): Result
    {
        $resultado = Result::nulo();

        while (true) {
            $cond = $this->visit($ctx->expr());

            if ($cond->tipo !== Result::BOOL) {
                $this->errores->agregar('Semántico', "Condición del for debe ser bool.");
                break;
            }

            if (!$cond->valor) {
                break;
            }
~~~

Al igual que cualquier lenguaje, el ciclo while tiene soporte para los casos de continue y break, los cuales son elementos de control del ciclo, estos poseen la particuralidad de terminar o mantener el flujo del ciclo, pueden hacer que estructuras cambien su comportamiento. 

~~~php
            $resultado = $this->visitBloque($ctx->bloque());

            if ($resultado !== null) {
                if ($resultado->esBreak) {
                    $resultado = Result::nulo();
                    break;
                }
                if ($resultado->esContinue) {
                    $resultado = Result::nulo();
                }
                if ($resultado->esReturn) {
                    break;
                }
            }
        }

        return $resultado;
    }
~~~

El ciclo for tradicional, también posee 2 variables, el for clasico maneja la lógica para un ciclo for que posee declaración corta y evaluación de la condición booleana

~~~php
public function visitForClassico($ctx): Result
    {
        $envAnterior = $this->env;
        $this->env   = new Environment($envAnterior);

        if ($ctx->declCorta() !== null) {
            $this->visit($ctx->declCorta());
        }

        $resultado = Result::nulo();
~~~

Valida que la condición se cumpla en cada iteración, y que el tipo de la condición sea booleana, esto hace que registre el error, en caso de no cumplirse.

~~~php
        while (true) {
            $cond = $this->visit($ctx->expr());

            if ($cond->tipo !== Result::BOOL) {
                $this->errores->agregar('Semántico', "Condición del for debe ser bool.");
                break;
            }

            if (!$cond->valor) {
                break;
            }
~~~

Se declara un nuevo ambiente, esto es escencial para poder manejar los iteradores y variables únicas dentro de este ciclo.

~~~php
            $bloqueEnv = new Environment($this->env);
                $envTemp   = $this->env;
                $this->env = $bloqueEnv;

                $resultado = Result::nulo();
                foreach ($ctx->bloque()->sentencia() as $sent) {
                    $sv = new ExpressionVisitor(
                        $this->envGlobal,
                        $this->env,
                        $this->errores,
                        $this->ambitoActual
                    );
                    $resultado = $sv->visit($sent);
                    $this->consola .= $sv->obtenerConsola();
~~~

Se valida que no sea ninguna de las estrucutras de control de flujo, como lo es continue o break, los cuales alteran las iteraciones del ciclo.

~~~php

if ($resultado !== null && ($resultado->esReturn || $resultado->esBreak || $resultado->esContinue)) {
                        break;
                    }
                }

                $this->env = $envTemp;
            if ($resultado !== null) {
                if ($resultado->esBreak) {
                    $resultado = Result::nulo();
                    break;
                }
                if ($resultado->esContinue) {
                    $resultado = Result::nulo();
                }
                if ($resultado->esReturn) {
                    break;
                }
            }

            if ($ctx->incDec() !== null) {
                $stepVisitor = new ExpressionVisitor(
                    $this->envGlobal,
                    $this->env,
                    $this->errores,
                    $this->ambitoActual
                );
                $stepVisitor->visit($ctx->incDec());
                $this->consola .= $stepVisitor->obtenerConsola();
            } elseif ($ctx->asignacionCompuesta() !== null) {
                $stepVisitor = new ExpressionVisitor(
                    $this->envGlobal,
                    $this->env,
                    $this->errores,
                    $this->ambitoActual
                );
                $stepVisitor->visit($ctx->asignacionCompuesta());
                $this->consola .= $stepVisitor->obtenerConsola();
            }
        }

        $this->env = $envAnterior;
        return $resultado;
    }
~~~

El ciclo for infinito es más sencillo, este únicamente valida que las visitas a ´visitBloque´ no devuelvan los condicionantes del flujo, en caso de hacerlo se ejecutan las instrucciones correspondientes.

~~~php
    public function visitForInfinito($ctx): Result
    {
        $resultado = Result::nulo();

        while (true) {
            $resultado = $this->visitBloque($ctx->bloque());

            if ($resultado !== null) {
                if ($resultado->esBreak) {
                    $resultado = Result::nulo();
                    break;
                }
                if ($resultado->esContinue) {
                    $resultado = Result::nulo();
                }
                if ($resultado->esReturn) {
                    break;
                }
            }
        }

        return $resultado;
    }
~~~

El ciclo Switch-Case se maneja de manera diferente, s ejecua un ciclo foreach, para conocer los casos y el deafault

~~~php
public function visitSentenciaSwitch($ctx): Result
    {
        $exprSwitch = $this->visit($ctx->expr());
        $casos      = $ctx->casoSwitch();
        $default    = $ctx->defaultSwitch();

        $encontrado    = false;
        $casoEjecutar  = null;

        foreach ($casos as $caso) {
            $listaExprCaso = $caso->listaExpr();
~~~

En caso de existir casos en el switch, se hará un foreach, acá se validará la opción del switch que se desea realizar

~~~php
            if ($listaExprCaso !== null) {
                foreach ($listaExprCaso->expr() as $exprCaso) {
                    $valCaso = $this->visit($exprCaso);
                    if ($exprSwitch->valor == $valCaso->valor &&
                        $exprSwitch->tipo  === $valCaso->tipo) {
                        $encontrado   = true;
                        $casoEjecutar = $caso;
                        break 2;
                    }
                }
            }
        }
~~~

Si se encuentra el caso a ejecutar, se visitan los casos, en caso de no existir break, se seguirán ejecutando las demás instrucciones hasta que se encuentre el break.

~~~php
        if ($encontrado && $casoEjecutar !== null) {
            foreach ($casoEjecutar->sentencia() as $sent) {
                $resultado = $this->visit($sent);
                if ($resultado !== null && ($resultado->esBreak || $resultado->esReturn)) {
                    return $resultado->esReturn ? $resultado : Result::nulo();
                }
            }
~~~

Si el caso no pertenece a ninguno de los definidos, o no existe un break en los casos anteriores, se ejecutará el bloque default, el cual es el caso que siempre se ejecutará si no existe un break.

~~~php            
        } elseif ($default !== null) {
            foreach ($default->sentencia() as $sent) {
                $resultado = $this->visit($sent);
                if ($resultado !== null && ($resultado->esBreak || $resultado->esReturn)) {
                    return $resultado->esReturn ? $resultado : Result::nulo();
                }
            }
        }

        return Result::nulo();
    }
~~~

Dentro de las funciones, la parte más importante es la definición del return, pues acá se devuelve el valor de la ejecución de procedimientos dentro de las mismas


Acá se valida  la existencia o no de una lista de valores, para manjera múltiples retornos. Si no existe, se evalua que almenos se tenga un return

~~~php
public function visitSentenciaReturn(SentenciaReturnContext $ctx): Result
    {
        if ($ctx->listaExpr() !== null) {
            $exprs  = $ctx->listaExpr()->expr();
            $valores = $this->evaluarListaExprs($exprs);

            if (count($valores) === 1) {
                $res           = $valores[0];
                $res->esReturn = true;
                return $res;
            }
~~~

Si existen múltiples retornos, se empquetan como un array de resultados, para poder devolverlos de manera correcta

~~~php
            $res           = new Result('__multi__', $valores);
            $res->esReturn = true;
            return $res;
        }

        $res           = Result::nulo();
        $res->esReturn = true;
        return $res;
    }
~~~

La función encargada de realizar procedimientos dentro de funciones o estructuras de control, como el if o el for es la función ´visitBloque´

Esta funcipon, se encarga de validar los entornos, si existe o si debe crear uno nuevo para la ejecución limpia.

~~~php
public function visitBloque($ctx): Result
    {
        $envAnterior = $this->env;
        $this->env   = new Environment($envAnterior);

        $resultado = Result::nulo();
~~~

Valida que existan sentencias a ejecutar, las cuales serán visitadas, creando una nueva instancia con las instrucciones a ejecutar.

~~~php
        foreach ($ctx->sentencia() as $sent) {
            $visitor = new ExpressionVisitor(
                $this->envGlobal,
                $this->env,
                $this->errores,
                $this->ambitoActual
            );

            $resultado = $visitor->visit($sent);

            $this->consola .= $visitor->obtenerConsola();

            foreach ($visitor->obtenerRegistroSimbolos() as $sym) {
                $this->registroSimbolos[] = $sym;
            }
~~~

Se agrega soporte para que las estrucuturas de control sean manejadas correctamente.

~~~php
            if ($resultado !== null && ($resultado->esReturn || $resultado->esBreak || $resultado->esContinue)) {
                break;
            }
        }
~~~

La parte más importante es que devuelve el entorno a su anterior estado, para que si está dentro de un ciclo o función no exista una sobreescritura del mismo. Se hace un retun de resultado, el cual ya devuelve toda la lógica aplicada por las instrucciones definidas en el bloque.

~~~php
        $this->env = $envAnterior;
        return $resultado;
    }
~~~

La función ´fmt.print´ es quien le dice al sistema que debe devolver impresiones de consola. Acá se agrega el soporte para impresión de valor de variables, valor dependiendo de la posición en un arreglo, valor devuelto por funciones, entre otros.

~~~php
    public function visitExprFmtPrintln(ExprFmtPrintlnContext $ctx): Result
    {
        $partes = [];
        if ($ctx->listaExpr() !== null) {
            foreach ($ctx->listaExpr()->expr() as $e) {
                $res = $this->visit($e);
~~~

En caso que las funciones devuelvan múltiples valores, se agrega el soporte para la impresión de estos

~~~php
                if ($res->tipo === '__multi__' && is_array($res->valor)) {
                    foreach ($res->valor as $r) {
                        $partes[] = $this->resultToString($r);
                    }
                } else {
                    $partes[] = $this->resultToString($res);
                }
            }
        }
        $this->agregarConsola(implode(' ', $partes) . "\n");
        return Result::nulo();
    }
~~~


### Errores

Los errores se manejan gracias a una  clase que se llama cada vez que alguno de los analizadores encuentra un error, la clase base maneja ambos tipos de errores, léxicos o sintácticos. Al utilizar un `patrón Visitor` se define la clase ErrorListener, la cuál es la base de los errores.

#### ErrorListener

~~~php
class ErrorListener extends BaseErrorListener
{
    private ManejadorErrores $errores;
    private string           $fase;   // 'Léxico' | 'Sintáctico'

    public function __construct(ManejadorErrores $errores, string $fase)
    {
        $this->errores = $errores;
        $this->fase    = $fase;
    }

~~~

La clase posee funciones que humanizan las descripciones de errores, gracias a ellas se logra una mejora en la experiencia del usuario, ya que muestra una descripción precisa e intuitiva de donde se ubican los errores

~~~php
private function traducirToken(string $token): string
    {
        $token = trim($token, "{} ");
        $mapa  = [
            "';'"       => "';'",
            "'}'"       => "llave de cierre '}'",
            "'{'"       => "llave de apertura '{'",
            "')'"       => "paréntesis de cierre ')'",
            "'('"       => "paréntesis de apertura '('",
            "':='"      => "operador de asignación ':='",
            "'='"       => "operador de asignación '='",
            'ID'        => 'un identificador',
            'INT_LIT'   => 'un número entero',
            'FLOAT_LIT' => 'un número flotante',
            'STR_LIT'   => 'una cadena de texto',
            'BOOL_LIT'  => 'true o false',
            'EOF'       => 'fin de archivo',
        ];

        return $mapa[$token] ?? "'{$token}'";
    }
~~~

La función anterior ayuda a mostrar carácteres especiales del lenguaje, esto da soporte a la función `humanizar`, la cual traduce salidas de ANTLR a español, para una mejor comprensión

~~~php
private function humanizar(
        string     $msg,
        mixed      $token,
        Recognizer $recognizer,
        ?RecognitionException $e
    ): string {
        // "extraneous input 'X' expecting Y"
        if (str_contains($msg, 'extraneous input')) {
            $texto = $token?->getText() ?? '?';
            return "Token inesperado '{$texto}'. Verifique la sintaxis en esta posición.";
        }

        // "missing X at Y"
        if (str_contains($msg, 'missing')) {
            preg_match("/missing (.+?) at '(.+?)'/", $msg, $m);
            if (count($m) === 3) {
                $esperado  = $this->traducirToken($m[1]);
                $encontrado = $m[2];
                return "Falta {$esperado} antes de '{$encontrado}'.";
            }
        }
}
~~~

#### ManejadorErrores

Esta es la clase que lleva toda la lógica de los errores, es capaz de recibir el número de error, el tipo y una pequeña descripción en la que se muestra información necesaria para poder corregir el error.

~~~php
<?php

namespace App\Env;

class EntradaError
{
    public int    $numero;
    public string $tipo;        // 'Léxico' | 'Sintáctico' | 'Semántico'
    public string $descripcion;
    public int    $linea;
    public int    $columna;

    public function __construct(
        int    $numero,
        string $tipo,
        string $descripcion,
        int    $linea,
        int    $columna
    ) {
        $this->numero      = $numero;
        $this->tipo        = $tipo;
        $this->descripcion = $descripcion;
        $this->linea       = $linea;
        $this->columna     = $columna;
    }
}
~~~

Esta clase traaja en conjunto con `ErrorListener`, para poder manejar los errores de manera correcta y óptima. Tiene varias funciones importantes, en donde se puede resaltar una función para serializar a `json`, para que el frontend pueda consumir la información.

~~~php
public function comoArreglo(): array
    {
        return array_map(fn(EntradaError $e) => [
            'numero'      => $e->numero,
            'tipo'        => $e->tipo,
            'descripcion' => $e->descripcion,
            'linea'       => $e->linea,
            'columna'     => $e->columna,
        ], $this->errores);
    }
~~~

### Gramática

La gramática se explica mejor en el archivo [Gramatica.md](./Gramatica.md)
