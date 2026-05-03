export default function Consola({ texto, onRunArm64, salidaEjecucion, salidaRef }) {
    return (
      <div className="seccion">
        <div style={{ display: 'flex', alignItems: 'center', gap: '10px' }}>
            <h3>Consola</h3>
            <button 
                onClick={onRunArm64} 
                style={{ cursor: 'pointer', borderRadius: '5px', padding: '2px 10px' }}
                title="Ejecutar ARM64"
            >
              Ejecutar ►
            </button>
        </div>
        <pre className="consola">{texto}</pre>
        
        {/* Nueva área para la salida de ejecución debajo de la consola */}
        {salidaEjecucion && (
            <div style={{ marginTop: '10px' }}>
                <h4>Salida de Ejecución (ARM64):</h4>
                <pre 
                  className="consola"
                  ref={salidaRef}
                  tabIndex={-1}
                >
                  {salidaEjecucion}
                </pre>
            </div>
        )}
      </div>
    );
}