// R55 (día de la subida): el paquete del plugin lleva solo lo que hace falta en el servidor y nada de pruebas,
// documentación ni herramientas. Mira lo que git metería (los archivos del repositorio menos los «export-ignore» de
// .gitattributes) y además hace el paquete de verdad desde HEAD en una carpeta temporal y lista lo que lleva.
// No necesita el sitio de Local. Uso: node tests/paquete.mjs
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { existsSync, mkdtempSync, rmSync, statSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

import { contenido, empaquetar, PERMITIDO, PREFIJO, version } from '../herramientas/empaquetar.mjs';

const actual = contenido();

const pruebas = {
  'lleva lo que necesita el servidor': () => {
    for (const archivo of ['zona-nautico.php', 'uninstall.php', '.htaccess', 'assets/admin-fotos.js', 'includes/class-zn-api.php']) {
      assert.ok(actual.includes(archivo), archivo);
    }
    const includes = execFileSync('git', ['ls-files', 'includes'], { encoding: 'utf8' }).split(/\r?\n/).filter(Boolean);
    assert.deepEqual(includes.filter((a) => !actual.includes(a)), [], 'todo includes/');
  },
  'nada de pruebas, documentación ni herramientas': () => {
    assert.deepEqual(actual.filter((a) => /^(tests|herramientas)\//.test(a) || /\.(md|mjs|ps1|sh)$/.test(a) || /^\.git/.test(a)), []);
  },
  'solo lo permitido (un archivo nuevo de desarrollo no se cuela sin querer)': () => {
    assert.deepEqual(actual.filter((a) => !PERMITIDO.some((p) => p.test(a))), []);
  },
  'la versión sale de la cabecera de zona-nautico.php': () => {
    assert.match(version(), /^\d+\.\d+\.\d+$/);
  },
  'el paquete de verdad (desde HEAD): zona-nautico-<versión>.zip, con todo dentro de zona-nautico/ y sin pruebas': () => {
    const carpeta = mkdtempSync(join(tmpdir(), 'zn-paquete-'));
    try {
      const zip = empaquetar({ salida: carpeta, ref: 'HEAD', comprobarLimpio: false });
      assert.equal(zip, join(carpeta, `zona-nautico-${version('HEAD')}.zip`));
      assert.ok(existsSync(zip) && statSync(zip).size > 1000);
      // tar lista zips en Windows 10+ y en Mac (bsdtar).
      const dentro = execFileSync('tar', ['-tf', zip], { encoding: 'utf8' }).split(/\r?\n/).filter((l) => l && !l.endsWith('/'));
      assert.ok(dentro.length > 10);
      assert.deepEqual(dentro.filter((a) => !a.startsWith(PREFIJO)), []);
      assert.ok(dentro.includes(`${PREFIJO}zona-nautico.php`) && dentro.includes(`${PREFIJO}uninstall.php`));
      assert.deepEqual(dentro.filter((a) => /\/(tests|herramientas)\//.test(a) || /\.(md|mjs)$/.test(a)), []);
    } finally {
      rmSync(carpeta, { recursive: true, force: true });
    }
  },
  'no hace el paquete con cambios sin guardar en git (el zip saldría de HEAD, no de lo que hay en disco)': () => {
    const sucio = execFileSync('git', ['status', '--porcelain'], { encoding: 'utf8' }).trim() !== '';
    if (!sucio) return; // Con todo guardado no hay nada que comprobar aquí.
    const carpeta = mkdtempSync(join(tmpdir(), 'zn-paquete-'));
    try {
      assert.throws(() => empaquetar({ salida: carpeta }), /sin guardar/);
    } finally {
      rmSync(carpeta, { recursive: true, force: true });
    }
  },
};

let fallos = 0;
for (const [nombre, prueba] of Object.entries(pruebas)) {
  try {
    await prueba();
    console.log(`ok   ${nombre}`);
  } catch (e) {
    fallos++;
    console.log(`FALLO ${nombre}\n     ${e.message}`);
  }
}
console.log(fallos ? `\n${fallos} fallo(s)` : '\nTodo bien');
process.exit(fallos ? 1 : 0);
