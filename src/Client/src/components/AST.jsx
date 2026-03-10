import React, { useEffect, useRef, useState, useCallback } from "react";

/**
 * Componente AST
 * Recibe `svg` (string HTML de SVG generado por Graphviz en el servidor).
 * No depende de viz.js — el SVG ya viene renderizado.
 *
 * Controles:
 *   - Rueda del ratón  → zoom
 *   - Click + arrastrar → mover
 *   - Botones + / - / reset
 *   - Doble click → resetear vista
 */
export default function AST({ svg }) {
  const wrapperRef  = useRef(null);   // div con overflow hidden
  const contentRef  = useRef(null);   // div transformado

  const [scale, setScale] = useState(1);
  const [pos,   setPos]   = useState({ x: 0, y: 0 });
  const [isPanning, setIsPanning] = useState(false);

  const drag     = useRef({ active: false, startX: 0, startY: 0, originX: 0, originY: 0 });

  // ── Inyectar SVG y ajustar al contenedor ─────────────────────────
  useEffect(() => {
    if (!contentRef.current) return;
    if (!svg || svg.trim() === "") {
      contentRef.current.innerHTML = "";
      return;
    }

    contentRef.current.innerHTML = svg;

    // Hacer el SVG completamente flexible
    const svgEl = contentRef.current.querySelector("svg");
    if (svgEl) {
      svgEl.style.width    = "100%";
      svgEl.style.height   = "100%";
      svgEl.style.display  = "block";
      svgEl.removeAttribute("width");
      svgEl.removeAttribute("height");
    }

    // Resetear vista al cargar nuevo árbol
    setScale(1);
    setPos({ x: 0, y: 0 });
  }, [svg]);

  // ── Zoom centrado en el cursor ────────────────────────────────────
  const onWheel = useCallback((e) => {
    e.preventDefault();
    const factor = e.deltaY < 0 ? 1.15 : 1 / 1.15;
    setScale((s) => {
      const next = Math.min(Math.max(s * factor, 0.05), 20);
      // Ajustar posición para zoom centrado en el cursor
      const rect = wrapperRef.current.getBoundingClientRect();
      const cx   = e.clientX - rect.left;
      const cy   = e.clientY - rect.top;
      const dx   = cx - (rect.width  / 2 + pos.x);
      const dy   = cy - (rect.height / 2 + pos.y);
      const ratio = next / s - 1;
      setPos((p) => ({ x: p.x - dx * ratio, y: p.y - dy * ratio }));
      return next;
    });
  }, [pos]);

  useEffect(() => {
    const el = wrapperRef.current;
    if (!el) return;
    el.addEventListener("wheel", onWheel, { passive: false });
    return () => el.removeEventListener("wheel", onWheel);
  }, [onWheel]);

  // ── Drag ──────────────────────────────────────────────────────────
  const onMouseDown = (e) => {
    if (e.button !== 0) return;
    drag.current = { active: true, startX: e.clientX, startY: e.clientY,
                     originX: pos.x, originY: pos.y };
    setIsPanning(true);
  };

  const onMouseMove = (e) => {
    if (!drag.current.active) return;
    const dx = e.clientX - drag.current.startX;
    const dy = e.clientY - drag.current.startY;
    setPos({ x: drag.current.originX + dx, y: drag.current.originY + dy });
  };

  const stopDrag = () => {
    drag.current.active = false;
    setIsPanning(false);
  };

  // Doble click → resetear
  const onDoubleClick = () => { setScale(1); setPos({ x: 0, y: 0 }); };

  // ── Botones ───────────────────────────────────────────────────────
  const zoomIn    = () => setScale((s) => Math.min(s * 1.25, 20));
  const zoomOut   = () => setScale((s) => Math.max(s / 1.25, 0.05));
  const resetView = () => { setScale(1); setPos({ x: 0, y: 0 }); };

  const noContent = !svg || svg.trim() === "";

  return (
    <div className="seccion" style={{ display: "flex", flexDirection: "column", gap: 8 }}>
      <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", flexWrap: "wrap", gap: 6 }}>
        <h3 style={{ margin: 0 }}>Árbol de Sintaxis (AST)</h3>

        {!noContent && (
          <div style={{ display: "flex", gap: 6, alignItems: "center" }}>
            <button onClick={zoomIn}   title="Acercar">＋</button>
            <button onClick={zoomOut}  title="Alejar">－</button>
            <button onClick={resetView} title="Restablecer">⟳</button>
            <span style={{ fontSize: 11, opacity: 0.55 }}>
              {Math.round(scale * 100)}% · rueda=zoom · arrastrar=mover · doble clic=reset
            </span>
          </div>
        )}
      </div>

      {/* Área de visualización */}
      <div
        ref={wrapperRef}
        style={{
          position:     "relative",
          width:        "100%",
          height:       560,
          overflow:     "hidden",
          border:       "1px solid #444",
          borderRadius: 6,
          background:   "#ffffff",
          cursor:       isPanning ? "grabbing" : "grab",
          userSelect:   "none",
        }}
        onMouseDown={onMouseDown}
        onMouseMove={onMouseMove}
        onMouseUp={stopDrag}
        onMouseLeave={stopDrag}
        onDoubleClick={onDoubleClick}
      >
        {noContent && (
          <div style={msgStyle}>Ejecuta código para ver el árbol sintáctico.</div>
        )}

        {/* Capa transformada: traslación + escala */}
        <div
          style={{
            position:        "absolute",
            top:             0,
            left:            0,
            width:           "100%",
            height:          "100%",
            transform:       `translate(${pos.x}px, ${pos.y}px) scale(${scale})`,
            transformOrigin: "center center",
            willChange:      "transform",
          }}
        >
          <div
            ref={contentRef}
            style={{
              width:           "100%",
              height:          "100%",
              display:         "flex",
              alignItems:      "center",
              justifyContent:  "center",
            }}
          />
        </div>
      </div>
    </div>
  );
}

const msgStyle = {
  position:      "absolute",
  top:           "50%",
  left:          "50%",
  transform:     "translate(-50%, -50%)",
  color:         "#888",
  fontSize:      14,
  pointerEvents: "none",
};