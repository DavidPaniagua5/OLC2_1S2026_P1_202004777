import Editor from "./components/Editor";
import Sidebar from "./components/Sidebar";
import Simbolos from "./components/Simbolos"
import AST from "./components/AST"
import Consola from "./components/Consola"
import Errores from "./components/Errores"

import { useState } from 'react';
import "./App.css";

export default function App() {
  const [panelActivo, setPanelActivo] = useState('explorer');
  const [astDot, setAstDot] = useState("");
  const [code, setCode] = useState("");
  const [consola, setConsola] = useState("");
  const [errores, setErrores] = useState("");
  const [simbolos, setSimbolos] = useState([]);



// Función para manejar la carga de un archivo
  const handleLoadFile = (event) => {
    const file = event.target.files[0];
    if (file) {

      window.sessionStorage.setItem('fileName', file.name);
      const reader = new FileReader();
      reader.onload = (e) => {
        setCode(e.target.result);
      };
      reader.readAsText(file);
    }
  };

const handleSave = () => {
    const content = code; 

    let fileName = window.sessionStorage.getItem('fileName') || 'Codigo.go';
    if (!fileName.toLowerCase().endsWith('.go')) {
    fileName += '.go';
    }
    const blob = new Blob([content], { type: 'text/plain' });

    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = fileName;

    document.body.appendChild(link);
    link.click();

    document.body.removeChild(link);
    URL.revokeObjectURL(url);
    
    console.log(`Guardando archivo como: ${fileName}`);
};

  // Función para limpiar el editor y la consola
  const handleClear = (fileInputRef) => {
    setCode('');
    setConsola([]);
    setErrores([]);
    setSimbolos([]);
    setAstDot('');

    if (fileInputRef && fileInputRef.current) {
        fileInputRef.current.value = '';
    }

  };

  const handleOnRun = async () => {
    if (!code.trim()) {
      const nuevoError = {
        tipo: 'Error', // Este valor se mapea a <strong>{e.tipo}</strong>
        descripcion: 'No hay código para ejecutar' // Este valor se mapea a {e.descripcion}
    };
      setErrores([nuevoError]);
      return
    }

    

    setConsola("Ejecutando...");
    try {
      //const res = await axios.post("http://localhost:5000/interpretar", { code });
       const response = await fetch('http://localhost:8000/index.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({ expression : code }),
      })
      const p = JSON.stringify({ expression : code });

      const data = await response.json()
      const now = new Date();
      const timeStamp = now.toLocaleTimeString('es-ES', { 
        hour: '2-digit', 
        minute: '2-digit', 
        second: '2-digit' 
    });
    console.log(data)
      if (data.success){
        const lineas = data.output
        .split("\n")
        .filter(line => line.trim() !== "");
      const newOutput = [
      //...consola, 
      `[${timeStamp}]--- RESULTADO DEL ANÁLISIS ---`
      ];

      lineas.forEach(linea => {
        newOutput.push(`  ${linea}`);
      });
    
      setConsola(newOutput.join("\n"));
      
        //setSimbolos(data.simbolos);
        setAstDot(data.ast);
      } else{
        const lineas = data.output
        .split("\n")
        .filter(line => line.trim() !== "");
        const newOutput = [
      //...consola, 
      `[${timeStamp}]--- ERRORES DURANTE EL ANÁLISIS ---`
      ];
        lineas.forEach(linea => {
        newOutput.push(`  ${linea}`);
      });
    
      setErrores(newOutput.join("\n"));
      }

    } catch (error) {
      setErrores("Error al interpretar.");
    }
  };

  return (
    
      <div className="app">
      
      <div className="side">
      <Sidebar
        onClear={handleClear} 
        onRun={handleOnRun}
        onLoadFile={handleLoadFile} 
        onSave={handleSave}
      />
    </div>
    <div className="contenedor-Titulo">
      <div className="Titulo">
      <img src="go.png" alt="" className="logo-inline"/>
      <span>LAMPI</span>
      </div>
      </div>
      <div className="seccion" id = "Editor">
        <Editor code={code} setCode={setCode} />
      </div>

      <div className="seccion" id ="DiConsola" tabIndex="-1">
        <Consola texto={consola} />
      </div>

      <div className="seccion" id ="DiTabla" tabIndex="-1">
        <Simbolos data={simbolos} />
      </div>

      <div className="seccion" id ="DiErrores" tabIndex="0">
        <Errores data={errores} />
      </div>

      <div className="seccion" id ="DiArbol" tabIndex="0">
        <AST dot={astDot} />
      </div>
    </div>

  );
}