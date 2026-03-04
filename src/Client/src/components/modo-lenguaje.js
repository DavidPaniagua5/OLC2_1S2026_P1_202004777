ace.define("ace/mode/golampi_highlight_rules", function(require, exports, module) {
    "use strict";
    var oop = require("../lib/oop");
    var TextHighlightRules = require("./text_highlight_rules").TextHighlightRules;

    var GolampiHighlightRules = function() {
        this.$rules = {
            "start": [
                // 1. COMENTARIOS (Línea única // y Multilínea /* */)
                {
                    token: "comment.block",
                    regex: "/\\*",
                    next: "comment_multi"
                },
                {
                    token: "comment.line",
                    regex: "//.*$"
                },
                // 2. CADENAS (Comillas dobles para string) 
                {
                    token: "string",
                    regex: '".*?"'
                },
                // 3. CARACTERES (Comillas simples para rune) 
                {
                    token: "string.character",
                    regex: "'.*?'"
                },
                // 4. PALABRAS CLAVE: Control de Flujo
                {
                    token: "keyword.control",
                    regex: "\\b(if|else|switch|case|default|for|break|continue|return|func)\\b"
                },
                // 5. TIPOS DE DATOS ESTÁTICOS 
                {
                    token: "storage.type",
                    regex: "\\b(int32|float32|bool|rune|string)\\b"
                },
                // 6. CONSTANTES DE LENGUAJE 
                {
                    token: "constant.language",
                    regex: "\\b(true|false|nil)\\b"
                },
                // 7. FUNCIONES EMBEBIDAS (Built-in) 
                {
                    token: "support.function",
                    regex: "\\b(fmt\\.Println|len|now|substr|typeOf)\\b"
                },
                // 8. ASIGNACIÓN CORTA Y OPERADORES
                {
                    token: "keyword.operator",
                    regex: ":=|=|\\+|\\-|\\*|/|%|==|!=|<|>|<=|>=|&&|\\|\\||!|&|\\*"
                },
                // 9. NÚMEROS (Enteros y Decimales) 
                {
                    token: "constant.numeric",
                    regex: "\\b\\d+(\\.\\d+)?\\b"
                },
                // 10. IDENTIFICADORES
                {
                    token: "variable.parameter",
                    regex: "\\b[a-zA-Z_][a-zA-Z0-9_]*\\b"
                },
                {
                    token: "text",
                    regex: "\\s+"
                }
            ],
            "comment_multi": [
                {
                    token: "comment.block",
                    regex: "\\*/",
                    next: "start"
                },
                {
                    defaultToken: "comment.block"
                }
            ]
        };
    };

    oop.inherits(GolampiHighlightRules, TextHighlightRules);
    exports.GolampiHighlightRules = GolampiHighlightRules;
});

ace.define("ace/mode/golampi", function(require, exports, module) {
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
        this.type = "text";
    }).call(Mode.prototype);

    exports.Mode = Mode;
});