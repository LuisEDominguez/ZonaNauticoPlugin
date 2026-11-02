// Lanza todas las pruebas del plugin, una detrás de otra y con pausas (el PHP de Local se reinicia si
// recibe muchas peticiones seguidas), y al final dice cuántas han ido bien (R61).
// Uso, con el sitio de Local encendido: node tests/todas.mjs
// WP-CLI: por defecto, el de Local en Windows con el PHP y la base de datos del sitio zonanautico-local.
// Para otro equipo, ZN_WP con la orden completa, p. ej.: ZN_WP="wp --path=/ruta/al/sitio" node tests/todas.mjs
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const carpeta = dirname(fileURLToPath(import.meta.url));
const raiz = dirname(carpeta);

function ordenWp() {
  if (process.env.ZN_WP) return { orden: process.env.ZN_WP, argumentos: [], shell: true };
  const { APPDATA, LOCALAPPDATA, USERPROFILE } = process.env;
  return {
    orden: join(APPDATA, 'Local', 'lightning-services', 'php-8.2.29+0', 'bin', 'win64', 'php.exe'),
    argumentos: [
      '-c', join(APPDATA, 'Local', 'run', 'VPheXlYLr', 'conf', 'php', 'php.ini'),
      '-d', 'mysqli.default_port=10011', '-d', 'mysqli.default_host=127.0.0.1',
      join(LOCALAPPDATA, 'Programs', 'local', 'resources', 'extraResources', 'bin', 'wp-cli', 'wp-cli.phar'),
      `--path=${join(USERPROFILE, 'Local Sites', 'zonanautico-local', 'app', 'public')}`,
    ],
    shell: false,
  };
}

// Primero las de PHP (por dentro) y después las de Node (por HTTP, como la app).
const PHP = ['admin-fichas', 'errores', 'esquema', 'comentarios', 'seguridad', 'roles', 'tokens', 'limite', 'escrituras', 'login', 'registro'];
const NODE = ['contrato-negocios', 'contrato-eventos', 'contrato-sorteos', 'contrato-resenas', 'contrato-preguntas', 'contrato-muestras', 'contrato-errores', 'puertas'];

const esperar = (ms) => Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, ms);
const wp = ordenWp();
const resultados = [];

function lanzar(nombre, orden, argumentos, shell) {
  const r = spawnSync(orden, argumentos, { cwd: raiz, encoding: 'utf8', shell, maxBuffer: 10 * 1024 * 1024 });
  const salida = `${r.stdout ?? ''}${r.stderr ?? ''}`;
  const bien = (salida.match(/^ok /gm) ?? []).length;
  const fallos = salida.split('\n').filter((l) => l.startsWith('FALLO'));
  const termino = /Todo bien/.test(salida);
  resultados.push({ nombre, bien, fallos: fallos.length, termino });
  console.log(`${termino && !fallos.length ? 'ok   ' : 'FALLO'} ${nombre}: ${bien} bien, ${fallos.length} mal`);
  for (const f of fallos) console.log(`       ${f}`);
  if (!termino && !fallos.length) console.log(salida.split('\n').slice(-5).join('\n'));
}

for (const prueba of PHP) {
  lanzar(`${prueba}.php`, wp.orden, [...wp.argumentos, 'eval-file', join('tests', `${prueba}.php`)], wp.shell);
  esperar(3000);
}
for (const prueba of NODE) {
  lanzar(`${prueba}.mjs`, process.execPath, [join('tests', `${prueba}.mjs`)], false);
  esperar(4000);
}

const total = resultados.reduce((s, r) => s + r.bien, 0);
const malos = resultados.filter((r) => r.fallos || !r.termino);
console.log(`\n${total} comprobaciones bien en ${resultados.length} juegos.`);
console.log(malos.length ? `${malos.length} juego(s) con fallos: ${malos.map((r) => r.nombre).join(', ')}` : 'Todo bien');
process.exit(malos.length ? 1 : 0);
