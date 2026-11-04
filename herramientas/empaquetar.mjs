// R55: hace el paquete del plugin para subirlo al servidor, con git archive (sin pruebas, documentación ni
// herramientas: lo quita .gitattributes con export-ignore). Sale de lo guardado en git (HEAD), así que con cambios
// sin guardar se para. No sube nada a ningún sitio: solo deja el .zip en una carpeta de este equipo.
// Uso: node herramientas/empaquetar.mjs [--salida <carpeta>]   (por defecto, la carpeta Descargas)
import { execFileSync } from 'node:child_process';
import { mkdirSync, readFileSync } from 'node:fs';
import { homedir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const RAIZ = resolve(dirname(fileURLToPath(import.meta.url)), '..');
export const PREFIJO = 'zona-nautico/';
/** Lo único que puede ir en el paquete. Si aparece otro archivo, la prueba (tests/paquete.mjs) lo dice. */
export const PERMITIDO = [/^zona-nautico\.php$/, /^uninstall\.php$/, /^\.htaccess$/, /^includes\/[^/]+\.php$/, /^assets\/[^/]+\.(js|css|png|svg)$/];

const git = (args, entrada) => execFileSync('git', args, { cwd: RAIZ, encoding: 'utf8', input: entrada });

/** Archivos que llevaría el paquete: los del repositorio (o de `ref`) menos los export-ignore de .gitattributes. */
export function contenido(ref) {
  const todos = (ref ? git(['ls-tree', '-r', '--name-only', ref]) : git(['ls-files'])).split(/\r?\n/).filter(Boolean);
  const fuera = new Set(
    git(['check-attr', '--stdin', 'export-ignore'], todos.join('\n') + '\n')
      .split(/\r?\n/)
      .filter((l) => l.endsWith(': export-ignore: set'))
      .map((l) => l.slice(0, -': export-ignore: set'.length)),
  );
  return todos.filter((a) => !fuera.has(a));
}

/** Versión de la cabecera de zona-nautico.php (en disco o en `ref`). */
export function version(ref) {
  const cabecera = ref ? git(['show', `${ref}:zona-nautico.php`]) : readFileSync(join(RAIZ, 'zona-nautico.php'), 'utf8');
  const m = cabecera.match(/^\s*\*\s*Version:\s*(\S+)/m);
  if (!m) throw new Error('No encuentro «Version:» en la cabecera de zona-nautico.php');
  return m[1];
}

/** Hace zona-nautico-<versión>.zip en `salida` y devuelve su ruta. */
export function empaquetar({ salida = join(homedir(), 'Downloads'), ref = 'HEAD', comprobarLimpio = true } = {}) {
  if (comprobarLimpio && git(['status', '--porcelain']).trim() !== '') {
    throw new Error('Hay cambios sin guardar en git: el paquete sale de HEAD y no los llevaría. Haz commit (o descártalos) y vuelve a probar.');
  }
  const lista = contenido(ref);
  const raros = lista.filter((a) => !PERMITIDO.some((p) => p.test(a)));
  if (raros.length) throw new Error(`El paquete llevaría archivos que no deberían ir:\n  ${raros.join('\n  ')}`);
  mkdirSync(salida, { recursive: true });
  const zip = join(salida, `zona-nautico-${version(ref)}.zip`);
  git(['archive', '--format=zip', `--prefix=${PREFIJO}`, '-o', zip, ref]);
  return zip;
}

if (process.argv[1] && import.meta.url === pathToFileURL(resolve(process.argv[1])).href) {
  const i = process.argv.indexOf('--salida');
  try {
    const zip = empaquetar(i > 0 ? { salida: resolve(process.argv[i + 1] ?? '.') } : {});
    console.log(`Paquete hecho: ${zip}\nLleva ${contenido('HEAD').length} archivos dentro de ${PREFIJO} (sin pruebas ni documentación).`);
  } catch (e) {
    console.error(e.message);
    process.exit(1);
  }
}
