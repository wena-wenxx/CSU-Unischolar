import { useRef, useState } from "react";

export default function FileDrop({ children, hint = "or drag and drop the file here", className = "" }) {
  const box = useRef(null);
  const [over, setOver] = useState(false);
  const depth = useRef(0);

  const input = () => box.current?.querySelector('input[type="file"]');
  const hasFiles = (event) => Array.from(event.dataTransfer?.types || []).includes("Files");

  function onDragEnter(event) {
    if (!hasFiles(event) || input()?.disabled) return;
    event.preventDefault();
    depth.current += 1;
    setOver(true);
  }

  function onDragOver(event) {
    if (!hasFiles(event) || input()?.disabled) return;
    event.preventDefault();
    event.dataTransfer.dropEffect = "copy";
  }

  function onDragLeave() {
    depth.current = Math.max(0, depth.current - 1);
    if (depth.current === 0) setOver(false);
  }

  function onDrop(event) {
    const target = input();
    if (!hasFiles(event) || !target || target.disabled) return;
    event.preventDefault();
    depth.current = 0;
    setOver(false);

    const dropped = Array.from(event.dataTransfer.files || []);
    if (!dropped.length) return;
    const chosen = new DataTransfer();
    (target.multiple ? dropped : dropped.slice(0, 1)).forEach((file) => chosen.items.add(file));
    target.files = chosen.files;
    target.dispatchEvent(new Event("change", { bubbles: true }));
  }

  return (
    <div
      ref={box}
      className={`file-drop${over ? " file-drop-over" : ""} ${className}`.trim()}
      onDragEnter={onDragEnter}
      onDragOver={onDragOver}
      onDragLeave={onDragLeave}
      onDrop={onDrop}
    >
      {children}
      {hint && <span className="file-drop-hint">{over ? "Drop to choose this file" : hint}</span>}
    </div>
  );
}
