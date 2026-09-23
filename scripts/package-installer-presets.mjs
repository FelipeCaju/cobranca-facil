/**
 * Gera install/presets/dist-root e dist-subfolder para o instalador web (hospedagem sem Node).
 * Uso: npm run package:installer
 */
import fs from "fs";
import path from "path";
import { fileURLToPath } from "url";
import { execSync } from "child_process";

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.join(__dirname, "..");

function rimraf(dir) {
  if (fs.existsSync(dir)) {
    fs.rmSync(dir, { recursive: true, force: true });
  }
}

function copyDir(src, dst) {
  fs.mkdirSync(dst, { recursive: true });
  for (const name of fs.readdirSync(src)) {
    const from = path.join(src, name);
    const to = path.join(dst, name);
    if (fs.statSync(from).isDirectory()) {
      copyDir(from, to);
    } else {
      fs.copyFileSync(from, to);
    }
  }
}

console.log("build:root");
execSync("npm run build:root", { cwd: root, stdio: "inherit" });
const presetRoot = path.join(root, "install/presets/dist-root");
rimraf(presetRoot);
copyDir(path.join(root, "dist"), presetRoot);
console.log("ok install/presets/dist-root");

console.log("build:subfolder");
execSync("npm run build:subfolder", { cwd: root, stdio: "inherit" });
const presetSub = path.join(root, "install/presets/dist-subfolder");
rimraf(presetSub);
copyDir(path.join(root, "dist"), presetSub);
console.log("ok install/presets/dist-subfolder");

console.log("\nPresets prontos para o instalador web.");
