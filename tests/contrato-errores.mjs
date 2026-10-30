// Comprueba que los errores de /zonanautico/v1 tienen el formato del Laravel que espera la app (R12):
// 422 {message, errors: {campo: [...]}}, 404/405 solo con {message}, nunca el {code, message, data} de WordPress.
// Solo lee. Uso, con el sitio de Local encendido: node tests/contrato-errores.mjs [url-base]
// Los 401 (Unauthenticated / sin permiso) se comprueban en tests/errores.php: aún no hay rutas privadas.
import assert from 'node:assert/strict';

const BASE = (process.argv[2] ?? 'http://localhost:10010/wp-json/zonanautico/v1').replace(/\/+$/, '');

async function pedir(ruta, metodo = 'GET') {
  const r = await fetch(`${BASE}${ruta}`, { method: metodo, headers: { Accept: 'application/json' } });
  return { estado: r.status, cuerpo: await r.json() };
}

const sinFormatoWordPress = (cuerpo) => {
  assert.ok(!('code' in cuerpo) && !('data' in cuerpo), `formato de WordPress: ${JSON.stringify(cuerpo)}`);
  assert.equal(typeof cuerpo.message, 'string');
};

const pruebas = {
  '422: parámetro no válido, con el error de su campo': async () => {
    const { estado, cuerpo } = await pedir('/business?orderby=foo');
    assert.equal(estado, 422);
    sinFormatoWordPress(cuerpo);
    assert.deepEqual(Object.keys(cuerpo).sort(), ['errors', 'message']);
    assert.deepEqual(Object.keys(cuerpo.errors), ['orderby']);
    assert.ok(Array.isArray(cuerpo.errors.orderby) && typeof cuerpo.errors.orderby[0] === 'string');
    assert.equal(cuerpo.message, cuerpo.errors.orderby[0], 'con un solo error, el mensaje es ese error');
  },
  '422: varios campos, mensaje «(and N more error)» como Laravel': async () => {
    const { estado, cuerpo } = await pedir('/review?orderby=foo&business=abc');
    assert.equal(estado, 422);
    assert.deepEqual(Object.keys(cuerpo.errors).sort(), ['business', 'orderby']);
    assert.match(cuerpo.message, / \(and 1 more error\)$/);
  },
  '422 en todas las rutas de lectura': async () => {
    for (const ruta of ['/business?perPage=0', '/event?category=abc', '/draw?order=arriba', '/faq?user=abc', '/review?page=-1']) {
      const { estado, cuerpo } = await pedir(ruta);
      assert.equal(estado, 422, ruta);
      assert.ok(cuerpo.errors && typeof cuerpo.errors === 'object', ruta);
    }
  },
  '404: elemento que no existe, solo {message}': async () => {
    const { estado, cuerpo } = await pedir('/business/999999');
    assert.equal(estado, 404);
    assert.deepEqual(Object.keys(cuerpo), ['message']);
  },
  '404: ruta que no existe, mensaje del Laravel': async () => {
    const { estado, cuerpo } = await pedir('/no-existe');
    assert.equal(estado, 404);
    sinFormatoWordPress(cuerpo);
    assert.match(cuerpo.message, /^The route .*no-existe could not be found\.$/);
  },
  '405: método no admitido, con los métodos que sí': async () => {
    const { estado, cuerpo } = await pedir('/business/1', 'DELETE');
    assert.equal(estado, 405);
    sinFormatoWordPress(cuerpo);
    assert.match(cuerpo.message, /^The DELETE method is not supported for route .*business\/1\. Supported methods: GET, HEAD\.$/);
  },
  'con la ruta en otras mayúsculas (/ZonaNautico/V1), el mismo formato (R34a)': async () => {
    const r = await fetch(`${BASE.replace(/zonanautico\/v1$/, 'ZonaNautico/V1')}/business?orderby=foo`);
    assert.equal(r.status, 422);
    const cuerpo = await r.json();
    sinFormatoWordPress(cuerpo);
    assert.ok(cuerpo.errors.orderby);
  },
  'las respuestas buenas no cambian': async () => {
    const { estado, cuerpo } = await pedir('/business');
    assert.equal(estado, 200);
    assert.ok(Array.isArray(cuerpo.data));
  },
  'fuera de /zonanautico/v1 no se toca nada': async () => {
    const r = await fetch(`${BASE.replace(/zonanautico\/v1$/, '')}wp/v2/no-existe`);
    assert.equal(r.status, 404);
    assert.equal((await r.json()).code, 'rest_no_route');
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
