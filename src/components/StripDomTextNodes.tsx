import { useLayoutEffect } from "react";

/** Remove nós de texto que só têm espaços/quebras (origem: quebras no JSX). */
function removeWhitespaceOnlyTextNodes(node: Node): void {
  if (node.nodeType !== Node.ELEMENT_NODE && node.nodeType !== Node.DOCUMENT_FRAGMENT_NODE) {
    return;
  }
  const parent = node as ParentNode;
  for (const child of [...parent.childNodes]) {
    if (child.nodeType === Node.TEXT_NODE) {
      if ((child.textContent ?? "").trim() === "") {
        parent.removeChild(child);
        continue;
      }
    }
    if (child.nodeType === Node.ELEMENT_NODE) {
      removeWhitespaceOnlyTextNodes(child);
    }
  }
}

export function StripDomTextNodes() {
  useLayoutEffect(() => {
    const clean = () => {
      for (const child of [...document.body.childNodes]) {
        if (child.nodeType === Node.TEXT_NODE && (child.textContent ?? "").trim() === "") {
          document.body.removeChild(child);
        }
      }
      const root = document.getElementById("root");
      if (root) {
        removeWhitespaceOnlyTextNodes(root);
      }
    };
    clean();
    const raf = window.requestAnimationFrame(clean);
    return () => window.cancelAnimationFrame(raf);
  }, []);

  return null;
}
