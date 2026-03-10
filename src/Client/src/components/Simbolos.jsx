export default function Simbolos({ data }) {
    return (
      <div className="seccion">
        <h3>Tabla de Símbolos</h3>
        <table>
          <thead>
            <tr>
              <th>ID</th>
              <th>Tipo</th>
              <th>Ámbito</th>
              <th>Valor</th>
              <th>Línea</th>
              <th>Columna</th>
            </tr>
          </thead>
          <tbody>
            {data.map((s, i) => (
              <tr key={i}>
                <td>{s.id}</td>
                <td>{s.tipo}</td>
                <td>{s.ambito}</td>
                <td>{JSON.stringify(s.valor)}</td>
                <td>{s.fila}</td>
                <td>{s.columna}</td>  
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    );
  }
   