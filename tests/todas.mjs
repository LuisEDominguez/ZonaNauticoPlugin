// Lanza todas las pruebas del plugin, una detrás de otra y con pausas (el PHP de Local se reinicia si
// recibe muchas peticiones seguidas), y al final dice cuántas han ido bien (R61).
// Uso, con el sitio de Local encendido: node tests/todas.mjs
// WP-CLI: por defecto, el de Local en Windows con el PHP y la base de datos del sitio zonanautico-local. Las rutas,
// el PHP y el puerto de MySQL se leen de la configuración de Local (sites.json), así que vale en cualquier equipo.
// Otro nombre de sitio: ZN_SITIO_LOCAL=nombre. Otro WP-CLI: ZN_WP con la orden completa, p. ej.:
//   ZN_WP="wp --path=/ruta/al/sitio" node tests/todas.mjs
// La dirección del WordPress para las pruebas por HTTP se pregunta al propio WordPress (opción home);
// ZN_URL la cambia, p. ej. ZN_URL=http://localhost:10004
import { spawnSync } from 'node:child_process';
import { existsSync, readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const carpeta = dirname(fileURLToPath(import.meta.url));
const raiz = dirname(carpeta);

function ordenWp() {
  if (process.env.ZN_WP) return { orden: process.env.ZN_WP, argumentos: [], shell: true };
  const { APPDATA, LOCALAPPDATA, USERPROFILE } = process.env;
  const nombre = process.env.ZN_SITIO_LOCAL ?? 'zonanautico-local';
  const sitios = JSON.parse(readFileSync(join(APPDATA, 'Local', 'sites.json'), 'utf8'));
  const sitio = Object.values(sitios).find((s) => s.name === nombre);
  if (!sitio) throw new Error(`Local no tiene ningún sitio llamado «${nombre}» (usa ZN_SITIO_LOCAL o ZN_WP).`);
  const ruta = sitio.path.replace(/^~/, USERPROFILE);
  const phar = [
    join(LOCALAPPDATA, 'Programs', 'local', 'resources', 'extraResources', 'bin', 'wp-cli', 'wp-cli.phar'),
    join(process.env['ProgramFiles(x86)'] ?? 'C:\\Program Files (x86)', 'Local', 'resources', 'extraResources', 'bin', 'wp-cli', 'wp-cli.phar'),
    join(process.env.ProgramFiles ?? 'C:\\Program Files', 'Local', 'resources', 'extraResources', 'bin', 'wp-cli', 'wp-cli.phar'),
  ].find(existsSync);
  if (!phar) throw new Error('No encuentro el wp-cli.phar de Local (usa ZN_WP).');
  return {
    orden: join(APPDATA, 'Local', 'lightning-services', `php-${sitio.services.php.version}+0`, 'bin', 'win64', 'php.exe'),
    argumentos: [
      '-c', join(APPDATA, 'Local', 'run', sitio.id, 'conf', 'php', 'php.ini'),
      '-d', `mysqli.default_port=${sitio.services.mysql.ports.MYSQL[0]}`, '-d', 'mysqli.default_host=127.0.0.1',
      phar,
      `--path=${join(ruta, 'app', 'public')}`,
    ],
    shell: false,
  };
}

// Primero las de PHP (por dentro) y después las de Node (por HTTP, como la app).
const PHP = ['admin-fichas', 'errores', 'esquema', 'comentarios', 'seguridad', 'roles', 'tokens', 'limite', 'escrituras', 'login', 'registro', 'mis-datos', 'opiniones', 'novedades-sorteos', 'privacidad', 'panel'];
const NODE = ['contrato-negocios', 'contrato-eventos', 'contrato-sorteos', 'contrato-resenas', 'contrato-preguntas', 'contrato-muestras', 'contrato-errores', 'puertas'];

const esperar = (ms) => Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, ms);
const wp = ordenWp();
const resultados = [];

function direccion() {
  if (process.env.ZN_URL) return process.env.ZN_URL.replace(/\/+$/, '');
  const r = spawnSync(wp.orden, [...wp.argumentos, 'option', 'get', 'home'], { cwd: raiz, encoding: 'utf8', shell: wp.shell });
  const home = (r.stdout ?? '').trim().split('\n').pop()?.trim();
  if (!/^https?:\/\//.test(home ?? '')) throw new Error(`No sé la dirección del WordPress (usa ZN_URL). WP-CLI dijo: ${r.stdout}${r.stderr}`);
  return home.replace(/\/+$/, '');
}
const sitio = direccion();
console.log(`WordPress: ${sitio}\n`);

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
  // puertas.mjs quiere la portada; las de contrato, la raíz de la API.
  const url = prueba === 'puertas' ? sitio : `${sitio}/wp-json/zonanautico/v1`;
  lanzar(`${prueba}.mjs`, process.execPath, [join('tests', `${prueba}.mjs`), url], false);
  esperar(4000);
}

const total = resultados.reduce((s, r) => s + r.bien, 0);
const malos = resultados.filter((r) => r.fallos || !r.termino);
console.log(`\n${total} comprobaciones bien en ${resultados.length} juegos.`);
console.log(malos.length ? `${malos.length} juego(s) con fallos: ${malos.map((r) => r.nombre).join(', ')}` : 'Todo bien');
process.exit(malos.length ? 1 : 0);
