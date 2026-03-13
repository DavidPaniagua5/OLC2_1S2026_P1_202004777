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
