import React, { useEffect } from 'react';
import AceEditor from 'react-ace';
import ace from 'ace-builds';

// Importa los archivos necesarios para que se incluyan en el bundle
import 'ace-builds/src-noconflict/mode-javascript'; // Un modo base por si falla el tuyo
import 'ace-builds/src-noconflict/theme-monokai';
import 'ace-builds/src-noconflict/ext-language_tools';

import aceWorkerUrl from 'ace-builds/src-noconflict/worker-base?url';
ace.config.setModuleUrl('ace/mode/base_worker', aceWorkerUrl);

// Configura el basePath apuntando a la carpeta de ace-builds en node_modules
ace.config.set('basePath', `https://cdn.jsdelivr.net/npm/ace-builds@${ace.version}/src-noconflict/`);
import './modo-lenguaje';

// Lista de palabras clave para el autocompletado
const customKeywords = [
    "int32", "float32", "bool", "rune", "string",
    "if", "else", "switch", "case", "default", "for", "break", "continue", "return",
    "func", "true", "false", "nil", "var",
    "fmt.Println", "len", "now", "substr", "typeOf"
];

const Editor = ({ code, setCode }) => {
    
    useEffect(() => {
        const customWordCompleter = {
            getCompletions: function(editor, session, pos, prefix, callback) {
                if (prefix.length === 0) {
                    callback(null, []);
                    return;
                }
                
                const completions = customKeywords
                    .filter(word => word.startsWith(prefix))
                    .map(word => ({
                        caption: word,  
                        value: word,    
                        meta: "keyword"
                    }));

                callback(null, completions);
            }
        };

        ace.require("ace/ext/language_tools").addCompleter(customWordCompleter);

        return () => {
            //ace.require("ace/ext/language_tools").removeCompleter(customWordCompleter);
        };
    }, []); // El array vacío asegura que esto solo se ejecute una vez al montar

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
                tabSize: 4, // El valor por defecto suele ser 4 para código
            }}
            style={{ width: '100%', height: '400px', border: '1px solid #ddd' }}
        />
    );
};

export default Editor;