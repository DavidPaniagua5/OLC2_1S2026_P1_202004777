import React from "react";

/**
 * Tabla de errores.
 * Recibe `errors`: array de { numero, tipo, descripcion, linea, columna }
 *
 * Tipos posibles: 'Léxico' | 'Sintáctico' | 'Semántico'
 * Cada tipo tiene color distinto para identificarlos de un vistazo.
 */
export default function Errores(errors = []) {
  if (!Array.isArray(errors.data)) return null;
  const colores = {
    "Léxico":     { bg: "#fff3cd", text: "#856404", border: "#ffc107" },
    "Sintáctico": { bg: "#f8d7da", text: "#721c24", border: "#f5c6cb" },
    "Semántico":  { bg: "#d1ecf1", text: "#0c5460", border: "#bee5eb" },
  };

  const totalPorTipo = errors.data.reduce((acc, e) => {
    acc[e.tipo] = (acc[e.tipo] || 0) + 1;
    return acc;
  }, {});

  return (
    <div className="seccion">
      <div style={{ display: "flex", alignItems: "center", gap: 12, marginBottom: 8, flexWrap: "wrap" }}>
        <h3 style={{ margin: 0 }}>Errores</h3>
        {/* Resumen por tipo */}
        {Object.entries(totalPorTipo).map(([tipo, n]) => {
          const c = colores[tipo] ?? { bg: "#eee", text: "#333", border: "#ccc" };
          return (
            <span
              key={tipo}
              style={{
                padding:      "2px 10px",
                borderRadius: 12,
                fontSize:     12,
                fontWeight:   600,
                background:   c.bg,
                color:        c.text,
                border:       `1px solid ${c.border}`,
              }}
            >
              {n} {tipo}{n !== 1 ? "s" : ""}
            </span>
          );
        })}
        {errors.data.length === 0 && (
          <span style={{ fontSize: 13, color: "#28a745" }}> Sin errores</span>
        )}
      </div>
      {errors.data.length > 0 && (
        <div style={{ overflowX: "auto" }}>
          <table style={{ width: "100%", borderCollapse: "collapse", fontSize: 13 }}>
            <thead>
              <tr style={{ borderBottom: "2px solid #555" }}>
                <th style={th}>#</th>
                <th style={th}>Tipo</th>
                <th style={{ ...th, textAlign: "left" }}>Descripción</th>
                <th style={th}>Línea</th>
                <th style={th}>Columna</th>
              </tr>
            </thead>
            <tbody>
              {errors.data.map((err, i) => {
                const c = colores[err.tipo] ?? { bg: "transparent", text: "inherit", border: "#555" };
                return (
                  <tr
                    key={i}
                    style={{
                      borderBottom:    `1px solid #444`,
                      backgroundColor: i % 2 === 0 ? "rgba(255,255,255,0.02)" : "transparent",
                    }}
                  >
                    <td style={td}>{err.numero}</td>
                    <td style={{ ...td, textAlign: "center" }}>
                      <span style={{
                        padding:      "2px 8px",
                        borderRadius: 4,
                        fontSize:     11,
                        fontWeight:   600,
                        background:   c.bg,
                        color:        c.text,
                        border:       `1px solid ${c.border}`,
                        whiteSpace:   "nowrap",
                      }}>
                        {err.tipo}
                      </span>
                    </td>
                    <td style={{ ...td, textAlign: "left" }}>{err.descripcion}</td>
                    <td style={td}>{err.linea > 0 ? err.linea : "—"}</td>
                    <td style={td}>{err.columna > 0 ? err.columna + 1 : "—"}</td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}

const th = {
  padding:    "6px 12px",
  textAlign:  "center",
  fontWeight: 700,
  fontSize:   12,
  opacity:    0.8,
};

const td = {
  padding:   "6px 12px",
  textAlign: "center",
  verticalAlign: "middle",
};