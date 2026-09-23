#!/usr/bin/env python3
"""Recupera ficheiros do projeto a partir do transcript Cursor (Write/StrReplace)."""
import json
import re
import sys
from pathlib import Path

TRANSCRIPT = Path(
    r"C:\Users\wever\.cursor\projects\c-laragon-www-cobx\agent-transcripts"
    r"\530b0638-2bfa-4415-8ca7-ff86233be683\530b0638-2bfa-4415-8ca7-ff86233be683.jsonl"
)
ROOT = Path(r"C:\laragon\www\cobx")

# path -> content (última versão vence)
files: dict[str, str] = {}


def norm_path(p: str) -> str | None:
    p = p.replace("\\", "/")
    for prefix in (
        "C:/laragon/www/cobx/",
        "c:/laragon/www/cobx/",
        "C:\\laragon\\www\\cobx\\",
    ):
        if p.lower().startswith(prefix.lower().replace("\\", "/")):
            rel = p[len(prefix) :].lstrip("/\\")
            return rel.replace("\\", "/")
    if not p.startswith("/") and ":" not in p[:3]:
        return p.replace("\\", "/")
    return None


def apply_strreplace(content: str, old: str, new: str) -> str:
    if old not in content:
        return content
    return content.replace(old, new, 1)


def process_tool(tool: dict) -> None:
    if not isinstance(tool, dict):
        return
    name = tool.get("name") or tool.get("toolName")
    inp = tool.get("input") or tool.get("arguments") or {}
    if not isinstance(inp, dict):
        return
    path = inp.get("path") or inp.get("target_notebook")
    if not path:
        return
    rel = norm_path(str(path))
    if not rel:
        return
    # ignorar fora do projeto web react
    skip_prefixes = ("app/", "api/", "database/", "scripts/recover")
    skip_files = {"index.php", ".htaccess", "router-dev.php", "README.md"}
    if rel in skip_files:
        return
    if any(rel.startswith(s) for s in skip_prefixes):
        return

    if name == "Write":
        contents = inp.get("contents") or inp.get("new_string")
        if contents is not None:
            files[rel] = contents
    elif name == "StrReplace":
        old = inp.get("old_string")
        new = inp.get("new_string")
        if old is None or new is None:
            return
        if rel in files:
            files[rel] = apply_strreplace(files[rel], old, new)
        # StrReplace sem Write prévio: ignorar (incompleto)


def walk(obj) -> None:
    if isinstance(obj, dict):
        if "name" in obj and ("input" in obj or "arguments" in obj):
            process_tool(obj)
        for v in obj.values():
            walk(v)
    elif isinstance(obj, list):
        for item in obj:
            walk(item)


def main() -> int:
    if not TRANSCRIPT.is_file():
        print(f"Transcript não encontrado: {TRANSCRIPT}", file=sys.stderr)
        return 1

    with TRANSCRIPT.open(encoding="utf-8") as f:
        for line in f:
            line = line.strip()
            if not line:
                continue
            try:
                row = json.loads(line)
            except json.JSONDecodeError:
                continue
            walk(row)

    if not files:
        print("Nenhum ficheiro recuperado do transcript.")
        return 1

    written = 0
    for rel, content in sorted(files.items()):
        dest = ROOT / rel.replace("/", "\\")
        dest.parent.mkdir(parents=True, exist_ok=True)
        dest.write_text(content, encoding="utf-8", newline="\n")
        written += 1
        print(f"  {rel}")

    print(f"\nRecuperados {written} ficheiros em {ROOT}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
