// Comprueba desde fuera, como un visitante cualquiera, que las puertas estándar de WordPress están cerradas (R2).
// Uso, con el sitio de Local encendido: node tests/puertas.mjs [url-del-sitio]
import assert from 'node:assert/strict';

const SITIO = (process.argv[2] ?? 'http://localhost:10010').replace(/\/+$/, '');

const pedir = (ruta, opciones = {}) => fetch(`${SITIO}${ruta}`, { redirect: 'manual', ...opciones });

const pruebas = {
  '/wp-json/wp/v2/users no existe': async () => {
    assert.equal((await pedir('/wp-json/wp/v2/users')).status, 404);
    assert.equal((await pedir('/wp-json/wp/v2/users/1')).status, 404);
  },
  '/wp-json/wp/v2/media no existe': async () => {
    assert.equal((await pedir('/wp-json/wp/v2/media')).status, 404);
  },
  '/wp-json/wp/v2/comments no existe': async () => {
    assert.equal((await pedir('/wp-json/wp/v2/comments')).status, 404);
  },
  '?author=1 manda a la portada sin decir el nombre de acceso': async () => {
    const r = await pedir('/?author=1');
    assert.ok([301, 302].includes(r.status), `HTTP ${r.status}`);
    const destino = r.headers.get('location') ?? '';
    assert.ok(!destino.includes('/author/'), destino);
    assert.equal(new URL(destino, SITIO).pathname, '/');
  },
  'XML-RPC rechazado (también system.listMethods)': async () => {
    const cuerpo = '<?xml version="1.0"?><methodCall><methodName>system.listMethods</methodName></methodCall>';
    const r = await pedir('/xmlrpc.php', { method: 'POST', body: cuerpo, headers: { 'Content-Type': 'text/xml' } });
    assert.equal(r.status, 403);
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
