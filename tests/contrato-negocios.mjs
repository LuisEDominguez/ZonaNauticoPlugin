// Comprueba que GET /business responde con el mismo formato que la API Laravel (api-muestras/business.json de la app).
// Uso, con el sitio de Local encendido y los datos de prueba metidos (wp zona-nautico datos-prueba):
//   node tests/contrato-negocios.mjs [url-base]
import assert from 'node:assert/strict';

const BASE = (process.argv[2] ?? (process.env.ZN_URL ?? 'http://localhost:10010').replace(/\/+$/, '') + '/wp-json/zonanautico/v1').replace(/\/+$/, '');

const CAMPOS = [
  '_id', 'name', 'description', 'latitude', 'longitude', 'address', 'postcode', 'state', 'city', 'country',
  'phone', 'email', 'web', 'schedule', 'photo_0', 'photo_1', 'photo_2', 'video_0', 'video_1', 'video_2', 'score',
];
const META = ['current_page', 'from', 'last_page', 'links', 'path', 'per_page', 'to', 'total'];

async function pedir(ruta, metodo = 'GET') {
  const r = await fetch(`${BASE}${ruta}`, { method: metodo, headers: { Accept: 'application/json' } });
  return { estado: r.status, cuerpo: await r.json() };
}

const pruebas = {
  'listado: data, links y meta como Laravel': async () => {
    const { estado, cuerpo } = await pedir('/business');
    assert.equal(estado, 200);
    assert.deepEqual(Object.keys(cuerpo.links).sort(), ['first', 'last', 'next', 'prev']);
    assert.deepEqual(Object.keys(cuerpo.meta).sort(), META);
    assert.equal(cuerpo.meta.per_page, 15);
    assert.ok(cuerpo.data.length > 0, 'sin datos: falta «wp zona-nautico datos-prueba»');
  },
  'cada negocio trae los mismos campos y tipos': async () => {
    const { cuerpo } = await pedir('/business');
    for (const n of cuerpo.data) {
      assert.deepEqual(Object.keys(n), CAMPOS);
      assert.equal(typeof n._id, 'string');
      assert.equal(typeof n.score, 'number');
      for (const c of ['latitude', 'longitude']) assert.ok(n[c] === null || typeof n[c] === 'number', c);
    }
  },
  'paginación de 2 en 2': async () => {
    const { cuerpo } = await pedir('/business?perPage=2&page=2');
    assert.equal(cuerpo.meta.current_page, 2);
    assert.equal(cuerpo.meta.from, 3);
    assert.equal(cuerpo.meta.last_page, Math.ceil(cuerpo.meta.total / 2));
    assert.ok(cuerpo.links.prev.endsWith('page=1'));
  },
  'perPage nunca pasa de 50 (H32)': async () => {
    const { cuerpo } = await pedir('/business?perPage=1000');
    assert.equal(cuerpo.meta.per_page, 50);
  },
  'filtro por categoría': async () => {
    const { cuerpo: todos } = await pedir('/business?perPage=50');
    const { cuerpo } = await pedir('/business?category=2&perPage=50');
    assert.ok(cuerpo.meta.total < todos.meta.total);
  },
  'orden solo por campos permitidos': async () => {
    const { cuerpo } = await pedir('/business?orderby=score&order=desc&perPage=50');
    const puntos = cuerpo.data.map((n) => n.score);
    assert.deepEqual(puntos, [...puntos].sort((a, b) => b - a));
    assert.equal((await pedir('/business?orderby=id;drop')).estado, 422);
  },
  'ficha: { data } y 404 si no existe': async () => {
    const { cuerpo: lista } = await pedir('/business');
    const id = lista.data[0]._id;
    const { estado, cuerpo } = await pedir(`/business/${id}?includesUser=1`);
    assert.equal(estado, 200);
    assert.equal(cuerpo.data._id, id);
    assert.ok('user' in cuerpo.data);
    assert.equal((await pedir('/business/999999')).estado, 404);
  },
  'solo lectura: POST da 405, como el Laravel': async () => {
    assert.equal((await pedir('/business', 'POST')).estado, 405);
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
