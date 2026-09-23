import { Children, type ReactNode } from "react";

/**
 * Remove nós de texto criados por quebras de linha entre elementos irmãos no JSX
 * (aparecem como "\\n" no topo da página no inspetor).
 */
export function NoTextNodes({ children }: { children: ReactNode }) {
  const items = Children.toArray(children).filter(
    (child) => typeof child !== "string" || child.trim() !== "",
  );
  if (items.length === 1) {
    return items[0];
  }
  return <>{items}</>;
}
