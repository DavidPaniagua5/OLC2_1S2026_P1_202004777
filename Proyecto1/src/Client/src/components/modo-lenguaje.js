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