// Comprueba que GET /review responde con el mismo formato que la API Laravel (ReviewResource).
// Uso, con el sitio de Local encendido y los datos de prueba metidos (wp zona-nautico datos-prueba):
//   node tests/contrato-resenas.mjs [url-base]
import assert from 'node:assert/strict';

const BASE = (process.argv[2] ?? 'http://localhost:10010/wp-json/zonanautico/v1').replace(/\/+$/, '');

const CAMPOS = ['_id', 'title', 'description', 'response', 'score', 'datetime'];
const USUARIO = ['_id', 'name', 'role', 'avatar'];
const META = ['current_page', 'from', 'last_page', 'links', 'path', 'per_page', 'to', 'total'];

async function pedir(ruta, metodo = 'GET') {
  const r = await fetch(`${BASE}${ruta}`, { method: metodo, headers: { Accept: 'application/json' } });
  return { estado: r.status, cuerpo: await r.json() };
}

async function negocio(nombre) {
  const { cuerpo } = await pedir('/business?perPage=50');
  const n = cuerpo.data.find((b) => b.name === nombre);
  assert.ok(n, `falta «${nombre}»: «wp zona-nautico datos-prueba»`);
  return n;
}

const pruebas = {
  'listado: data, links y meta como Laravel': async () => {
    const { estado, cuerpo } = await pedir('/review');
    assert.equal(estado, 200);
    assert.deepEqual(Object.keys(cuerpo.links).sort(), ['first', 'last', 'next', 'prev']);
    assert.deepEqual(Object.keys(cuerpo.meta).sort(), META);
    assert.ok(cuerpo.data.length >= 3, 'faltan reseñas: «wp zona-nautico datos-prueba»');
  },
  'cada reseña trae los mismos campos, en el mismo orden y con sus tipos': async () => {
    const { cuerpo } = await pedir('/review');
    for (const r of cuerpo.data) {
      assert.deepEqual(Object.keys(r), CAMPOS);
      assert.equal(typeof r._id, 'string');
      assert.equal(typeof r.score, 'number');
      assert.match(r.datetime, /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/);
    }
  },
  'como la app: ?business, includesUser y orden por fecha descendente': async () => {
    const taberna = await negocio('Taberna O Peirao (prueba)');
    const { cuerpo } = await pedir(`/review?business=${taberna._id}&includesUser=1&orderby=datetime&order=desc&perPage=50`);
    assert.equal(cuerpo.data.length, 2, 'dos aprobadas');
    const fechas = cuerpo.data.map((r) => r.datetime);
    assert.deepEqual(fechas, [...fechas].sort().reverse());
    for (const r of cuerpo.data) assert.deepEqual(Object.keys(r.user), USUARIO, 'solo los 4 campos públicos (H27)');
    assert.ok(cuerpo.data.some((r) => r.response), 'una tiene respuesta del negocio');
  },
  'las reseñas sin aprobar no salen nunca': async () => {
    const { cuerpo } = await pedir('/review?perPage=50&includesUser=1');
    assert.ok(cuerpo.data.every((r) => r.user.name !== 'Pendiente Prueba'));
  },
  'la puntuación del negocio es la media de sus reseñas aprobadas': async () => {
    assert.equal((await negocio('Taberna O Peirao (prueba)')).score, 4.5);
    assert.equal((await negocio('Náutica Cíes (prueba)')).score, 3);
  },
  'includesBusiness trae el negocio con su formato': async () => {
    const { cuerpo } = await pedir('/review?includesBusiness=1');
    for (const r of cuerpo.data) assert.equal(typeof r.business.name, 'string');
  },
  'orden y filtros raros se rechazan': async () => {
    assert.equal((await pedir('/review?orderby=score')).estado, 422);
    assert.equal((await pedir('/review?business=abc')).estado, 422);
    assert.equal((await pedir('/review?user=99999')).cuerpo.data.length, 0);
  },
  'paginación y tope de 50': async () => {
    const { cuerpo } = await pedir('/review?perPage=1&page=2');
    assert.equal(cuerpo.meta.current_page, 2);
    assert.equal(cuerpo.data.length, 1);
    assert.ok(cuerpo.meta.total >= 3);
    assert.equal((await pedir('/review?perPage=1000')).cuerpo.meta.per_page, 50);
    const vacia = await pedir('/review?page=999');
    assert.equal(vacia.cuerpo.data.length, 0);
    assert.equal(vacia.cuerpo.meta.total, cuerpo.meta.total, 'el total se da también en una página vacía');
  },
  'ficha: { data } y 404 si no existe o es de otro tipo': async () => {
    const { cuerpo: lista } = await pedir('/review');
    const id = lista.data[0]._id;
    const { estado, cuerpo } = await pedir(`/review/${id}`);
    assert.equal(estado, 200);
    assert.equal(cuerpo.data._id, id);
    assert.equal((await pedir('/review/999999')).estado, 404);
    const { cuerpo: preguntas } = await pedir('/faq');
    assert.equal((await pedir(`/review/${preguntas.data[0]._id}`)).estado, 404);
  },
  'escribir pide sesión: POST sin token da 401 «Unauthenticated.»': async () => {
    const r = await pedir('/review', 'POST');
    assert.equal(r.estado, 401);
    assert.deepEqual(r.cuerpo, { message: 'Unauthenticated.' });
  },
  'PUT da 405, como el Laravel': async () => {
    assert.equal((await pedir('/review', 'PUT')).estado, 405);
  },
  'la ruta de comentarios de WordPress está cerrada': async () => {
    const r = await fetch(`${BASE.replace(/zonanautico\/v1$/, '')}wp/v2/comments`);
    assert.equal(r.status, 404);
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
