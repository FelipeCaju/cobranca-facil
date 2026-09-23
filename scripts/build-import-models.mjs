import fs from "node:fs/promises";
import { SpreadsheetFile, Workbook } from "@oai/artifact-tool";

const outputDir = new URL("../public/modelos/", import.meta.url).pathname.replace(/^\/(.:)/, "$1");
await fs.mkdir(outputDir, { recursive: true });

async function save(name, headers, values, widths) {
  const wb = Workbook.create(); const ws = wb.worksheets.add("Importação"); ws.showGridLines = false;
  ws.getRangeByIndexes(0, 0, 2, headers.length).values = [headers, values];
  const head = ws.getRangeByIndexes(0, 0, 1, headers.length);
  head.format = { fill: "#1F4E78", font: { name: "Arial", size: 10, bold: true, color: "#FFFFFF" }, horizontalAlignment: "center", verticalAlignment: "center" };
  ws.getRangeByIndexes(1, 0, 1, headers.length).format.font = { name: "Arial", size: 10 };
  widths.forEach((width, i) => { ws.getRangeByIndexes(0, i, 2, 1).format.columnWidth = width; });
  ws.freezePanes.freezeRows(1); wb.recalculate();
  const inspected = await wb.inspect({ kind: "table", sheetId: "Importação", range: `A1:${String.fromCharCode(64 + headers.length)}2`, include: "values,formulas", tableMaxRows: 3, tableMaxCols: 8 });
  if (!inspected.ndjson.includes(headers[0])) throw new Error(`Falha ao verificar ${name}`);
  const preview = await wb.render({ sheetName: "Importação", autoCrop: "all", scale: 1, format: "png" });
  await fs.writeFile(`${outputDir}/${name}.png`, new Uint8Array(await preview.arrayBuffer()));
  const file = await SpreadsheetFile.exportXlsx(wb); await file.save(`${outputDir}/${name}.xlsx`);
}

await save("clientes", ["nome","email","telefone","documento","cidade","estado"], ["Maria Silva","maria@exemplo.com","5511999999999","12345678909","São Paulo","SP"], [24,28,18,18,20,10]);
await save("cobrancas", ["cliente_email","produto","conta","metodo","vencimento"], ["maria@exemplo.com","Mensalidade","Asaas principal","boleto",new Date("2026-10-10T00:00:00Z")], [28,22,22,14,16]);
