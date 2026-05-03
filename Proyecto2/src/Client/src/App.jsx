import Editor from "./components/Editor";
import Sidebar from "./components/Sidebar";
import Simbolos from "./components/Simbolos"
import AST from "./components/AST"
import Consola from "./components/Consola"
import Errores from "./components/Errores"
import Swal from 'sweetalert2';
import jsPDF from "jspdf";
import autoTable from "jspdf-autotable";

import { useState } from 'react';
import "./App.css";

export default function App() {
  const [panelActivo, setPanelActivo] = useState('explorer');
  const [astDot, setAstDot] = useState("");
  const [code, setCode] = useState("");
  const [consola, setConsola] = useState("");
  const [errores, setErrores] = useState([]);
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

const handleSave = async () => {
    const content = code;
    if(!code.trim()){
      const result = await Swal.fire({
      title: '¡Error!',
      text: "No hay código para guardar",
      icon: 'warning',
      showCancelButton: true,
      confirmButtonText: 'Ok',
      });
    }
    if (code.trim()){
      const result = await Swal.fire({
      title: '¿Deseas guardar?',
      text: "Seleccione si desea guardar el código",
      icon: 'warning',
      showCancelButton: true,
      confirmButtonText: 'Sí, guardar',
      //cancelButtonText: 'Cancelar'
      });

      if (result.isConfirmed) {
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
        //Swal.fire('¡Guardado!', 'Tu archivo ha sido guardado.', 'success');
        
      }

    } 
    
};

 // Función para limpiar el editor y la consola
  const handleClear = async (fileInputRef) => {
    const content = code;
    if (code.trim()){
      const result = await Swal.fire({
      title: '¿Deseas guardar?',
      text: "Seleccione si desea guardar el código",
      icon: 'warning',
      showCancelButton: true,
      confirmButtonText: 'Sí',
      cancelButtonText: 'No'
      });
      if (result.isConfirmed) {
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

        setCode('');
        setConsola([]);
        setErrores([]);
        setSimbolos([]);
        setAstDot("");
      
    }else{
      setCode('');
      setConsola([]);
      setErrores([]);
      setSimbolos([]);
      setAstDot("");
    }
  }else{
      setCode('');
      setConsola([]);
      setErrores([]);
      setSimbolos([]);
      setAstDot("");
    }
    if (fileInputRef && fileInputRef.current) {
        fileInputRef.current.value = '';
    }

  };

  const handleClearConsola = async () =>{
      setConsola([]);
      setErrores([]);
      setSimbolos([]);
      setAstDot("");
      const result = await Swal.fire({
        title: 'Éxito',
        text: "Limpieza realizada con éxito.",
        icon: 'success',
        timer:1500
        });
  }

  const descargarErrores = () => {
  const doc = new jsPDF();

  // 🔹 Título
  doc.setFontSize(16);
  doc.text("Errores durante la ejecución", 14, 15);

  // 🔹 Subtítulo opcional
  doc.setFontSize(10);
  doc.text(`Total de errores: ${errores.length}`, 14, 22);

  if (errores.length === 0) {
    doc.text("No se encontraron errores.", 14, 30);
  } else {
    // 🔹 Preparar datos para la tabla
    const columnas = ["#", "Tipo", "Descripción", "Línea", "Columna"];

    const filas = errores.map((err, i) => [
      i + 1,
      err.tipo,
      err.descripcion,
      err.linea || "-",
      err.columna || "-"
    ]);

    // 🔹 Tabla bonita
    autoTable(doc, {
      startY: 30,
      head: [columnas],
      body: filas,
      styles: {
        fontSize: 9
      },
      headStyles: {
        fillColor: [22, 160, 133] // verde bonito
      },
      alternateRowStyles: {
        fillColor: [240, 240, 240]
      }
    });
  }

  doc.save("reporte_errores.pdf");
};

const descargarSimbolos = () => {
  const doc = new jsPDF();

  // 🔹 Título
  doc.setFontSize(16);
  doc.text("Tabla de Símbolos", 14, 15);

  doc.setFontSize(10);
  doc.text(`Total de símbolos: ${simbolos.length}`, 14, 22);

  if (simbolos.length === 0) {
    doc.text("No se encontraron símbolos.", 14, 30);
  } else {

    // ⚠️ Ajusta estos campos según tu backend
    const columnas = ["#", "Identificador", "Tipo", "Valor", "Ámbito"];

    const filas = simbolos.map((sim, i) => [
      i + 1,
      sim.id || sim.identificador || "-",
      sim.tipo || "-",
      sim.valor || "-",
      sim.ambito || "-"
    ]);

    autoTable(doc, {
      startY: 30,
      head: [columnas],
      body: filas,
      styles: { fontSize: 9 },
      headStyles: { fillColor: [52, 152, 219] }, // azul
      alternateRowStyles: { fillColor: [245, 245, 245] }
    });
  }

  doc.save("tabla_simbolos.pdf");
};

const descargarConsola = () => {
  if (!consola || !consola.trim()) return;

  let contenido = consola
    .replace(/\[\d{2}:\d{2}:\d{2}\]--- El código ensamblador ARM64 completo generado ---/g, "")
    .trim();

  const blob = new Blob([contenido], { type: "text/plain" });

  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");

  link.href = url;
  link.download = "programa_arm64.s";

  document.body.appendChild(link);
  link.click();

  document.body.removeChild(link);
  URL.revokeObjectURL(url);
};

  const handleOnRun = async () => {
    if (!code.trim()) {
      const nuevoError = {
        tipo: 'Error',
        descripcion: 'No hay código para ejecutar'
    };
      setErrores([nuevoError]);
      return
    }

    setConsola("Ejecutando...");
    try {
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
    console.log(data);
      if (data.success){
        const lineas = data.arm64
        .split("\n")
        .filter(line => line.trim() !== "");
      const newOutput = [
      //...consola, 
      `[${timeStamp}]--- El código ensamblador ARM64 completo generado ---`
      ];

      lineas.forEach(linea => {
        newOutput.push(`  ${linea}`);
      });
        setErrores([]);
        setConsola(newOutput.join("\n"));
        setSimbolos(data.simbolos);
        //console.log(data.svg);
        setAstDot(data.svg);
      }
      else{
        setErrores([]);
        setSimbolos([]);
        setAstDot("");

        setConsola(`[${timeStamp}]--- RESULTADO DEL ANÁLISIS --- \n Errores detactados, ver apartado de errores.`);
        setErrores(data.errors);
      }
    } catch (error) {
      setConsola("Error al interpretar.");
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
        onClearConsola={handleClearConsola}
        onDownloadErrores={descargarErrores}
        onDownloadSimbolos={descargarSimbolos}
        onDownloadConsola={descargarConsola}
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