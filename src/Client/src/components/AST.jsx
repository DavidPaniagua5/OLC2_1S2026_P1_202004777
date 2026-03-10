import React, { useEffect, useRef, useState, useCallback } from "react";

export default function AST(svg) {
  const wrapperRef = useRef(null);
  const svgRef = useRef(null);

  const [scale, setScale] = useState(1);
  const [pos, setPos] = useState({ x: 0, y: 0 });
  const [isPanning, setIsPanning] = useState(false);

  const drag = useRef({
    active: false,
    startX: 0,
    startY: 0,
    originX: 0,
    originY: 0
  });

  // ─────────────────────────────────────────────
  // Cargar SVG
  // ─────────────────────────────────────────────
  useEffect(() => {

    if (!wrapperRef.current) return;

    if (!svg.dot || svg.dot === "") {
      wrapperRef.current.innerHTML = "";
      svgRef.current = null;
      return;
    }

    wrapperRef.current.innerHTML = svg.dot;

    const svgEl = wrapperRef.current.querySelector("svg");

    if (!svgEl) return;

    svgRef.current = svgEl;

    // eliminar tamaños fijos
    svgEl.removeAttribute("width");
    svgEl.removeAttribute("height");

    svgEl.style.display = "block";

    // mejorar renderizado del texto
    svgEl.style.shapeRendering = "geometricPrecision";
    svgEl.style.textRendering = "geometricPrecision";

    setScale(1);
    setPos({ x: 0, y: 0 });

  }, [svg.dot]);

  // ─────────────────────────────────────────────
  // Aplicar transformación al SVG
  // ─────────────────────────────────────────────
  useEffect(() => {

    if (!svgRef.current) return;

    svgRef.current.style.transform =
      `translate(${pos.x}px, ${pos.y}px) scale(${scale})`;

    svgRef.current.style.transformOrigin = "0 0";

  }, [scale, pos]);

  // ─────────────────────────────────────────────
  // Zoom con rueda
  // ─────────────────────────────────────────────
  const onWheel = useCallback((e) => {

    if (!svgRef.current) return;

    e.preventDefault();

    const factor = e.deltaY < 0 ? 1.15 : 1 / 1.15;

    const rect = wrapperRef.current.getBoundingClientRect();

    const mouseX = e.clientX - rect.left;
    const mouseY = e.clientY - rect.top;

    setScale((prev) => {

      const next = Math.min(Math.max(prev * factor, 0.05), 50);

      const ratio = next / prev;

      setPos((p) => ({
        x: mouseX - (mouseX - p.x) * ratio,
        y: mouseY - (mouseY - p.y) * ratio
      }));

      return next;

    });

  }, []);

  useEffect(() => {

    const el = wrapperRef.current;
    if (!el) return;

    el.addEventListener("wheel", onWheel, { passive: false });

    return () => el.removeEventListener("wheel", onWheel);

  }, [onWheel]);

  // ─────────────────────────────────────────────
  // Pan (arrastrar)
  // ─────────────────────────────────────────────
  const onMouseDown = (e) => {

    if (e.button !== 0) return;

    drag.current = {
      active: true,
      startX: e.clientX,
      startY: e.clientY,
      originX: pos.x,
      originY: pos.y
    };

    setIsPanning(true);
  };

  const onMouseMove = (e) => {

    if (!drag.current.active) return;

    const dx = e.clientX - drag.current.startX;
    const dy = e.clientY - drag.current.startY;

    setPos({
      x: drag.current.originX + dx,
      y: drag.current.originY + dy
    });

  };

  const stopDrag = () => {
    drag.current.active = false;
    setIsPanning(false);
  };

  // ─────────────────────────────────────────────
  // Controles
  // ─────────────────────────────────────────────
  const zoomIn = () => setScale(s => Math.min(s * 2.5, 50));
  const zoomOut = () => setScale(s => Math.max(s / 1.25, 0.05));

  const resetView = () => {
    setScale(1);
    setPos({ x: 0, y: 0 });
  };

  const noContent = !svg || svg === "";

  return (
    <div className="seccion" style={{ display: "flex", flexDirection: "column", gap: 8 }}>

      <div style={{
        display: "flex",
        justifyContent: "space-between",
        alignItems: "center",
        flexWrap: "wrap"
      }}>

        <h3 style={{ margin: 0 }}>Árbol de Sintaxis (AST)</h3>

        {!noContent && (
          <div style={{ display: "flex", gap: 6 }}>

            <button onClick={zoomIn}>＋</button>
            <button onClick={zoomOut}>－</button>
            <button onClick={resetView}>⟳</button>

            <span style={{ fontSize: 11, opacity: 0.6 }}>
              {Math.round(scale * 100)}%
            </span>

          </div>
        )}

      </div>

      <div
        ref={wrapperRef}
        style={{
          width: "100%",
          height: 560,
          border: "1px solid #444",
          borderRadius: 6,
          overflow: "hidden",
          background: "#ffffff",
          cursor: isPanning ? "grabbing" : "grab",
          userSelect: "none",
          position: "relative"
        }}
        onMouseDown={onMouseDown}
        onMouseMove={onMouseMove}
        onMouseUp={stopDrag}
        onMouseLeave={stopDrag}
      >

        {noContent && (
          <div style={msgStyle}>
            Ejecuta código para ver el árbol sintáctico.
          </div>
        )}

      </div>
    </div>
  );
}

const msgStyle = {
  position: "absolute",
  top: "50%",
  left: "50%",
  transform: "translate(-50%, -50%)",
  color: "#777",
  fontSize: 14
};