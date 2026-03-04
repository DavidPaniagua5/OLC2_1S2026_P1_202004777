import { FileUp, FilePlus, Save, Terminal, TriangleAlert, Table, Network, Play} from 'lucide-react';
import { useState, useRef } from 'react';


const items = [
  { id: 'Abrir', icon: <FileUp size={26} /> },
  { id: 'Limpiar', icon: <FilePlus size={26} /> },
  { id: 'Guardar', icon: <Save size={26}/>},
  { id: 'Ejecutar', icon: <Play size={26} /> },
  { id: 'Consola', icon: <Terminal size={26} /> },
  { id: 'Tabla de simbolos', icon: <Table size={26} /> },
  { id: 'Errores', icon: <TriangleAlert size={26} /> },
  { id: 'Arbol de Sintaxis (AST)', icon: <Network size={26} /> },
];
export default function Sidebar({ onFileSelect, onClear, onRun, onLoadFile, onSave}) {
  const [active, setActive] = useState('explorer');
  const [openFiles, setOpenFiles] = useState([]);
  const [currentFile, setCurrentFile] = useState(null);
  const [editor, setEditor] = useState("");         
  const fileInputRef = useRef(null);
  
  return (

    <div className="side">
      <input
                type="file"
                ref={fileInputRef}
                style={{ display: 'none' }}
                onChange={onLoadFile}
              />
      {items.map(({ id, icon }) => (
        
        <button
          key={id}
          className="sidebar-btn"
          id={id}
          onClick={() => {
            if(id === 'Abrir'){
              fileInputRef.current.click();   
            }else if(id === 'Limpiar'){
              onClear(fileInputRef);
            }else if(id === 'Guardar'){
              onSave();  
            }else if(id === 'Ejecutar'){
              onRun()
            }else if(id ==='Consola'){
              window.DiConsola.focus(); 
            }else if(id ==='Tabla de simbolos'){
              window.DiTabla.focus();
            }else if (id === 'Errores'){
              window.DiErrores.focus();
            }else if(id === 'Arbol de Sintaxis (AST)'){
              window.DiArbol.focus();
            }

          }}
          title={id}
        >
          {icon}
        </button>
       
      ))}
    </div>
  );
}