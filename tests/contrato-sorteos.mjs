// Comprueba que GET /draw responde con el mismo formato que la API Laravel (api-muestras/draw.json de la app).
// Uso, con el sitio de Local encendido y los datos de prueba metidos (wp zona-nautico datos-prueba):
//   node tests/contrato-sorteos.mjs [url-base]
import assert from 'node:assert/strict';

const BASE = (process.argv[2] ?? (process.env.ZN_URL ?? 'http://localhost:10010').replace(/\/+$/, '') + '/wp-json/zonanautico/v1').replace(/\/+$/, '');

const CAMPOS = [
  '_id', 'title', 'description', 'startDate', 'endDate', 'winners', 'photo_0', 'photo_1', 'photo_2',
  'video_0', 'video_1', 'video_2', 'legalUrl', 'controlParticipar', 'controlVisibilidad',
];
const META = ['current_page', 'from', 'last_page', 'links', 'path', 'per_page', 'to', 'total'];

async function pedir(ruta, metodo = 'GET') {
  const r = await fetch(`${BASE}${ruta}`, { method: metodo, headers: { Accept: 'application/json' } });
  return { estado: r.status, cuerpo: await r.json() };
}

const porTitulo = (lista, inicio) => lista.find((s) => s.title.startsWith(inicio));

const pruebas = {
  'listado: data, links y meta como Laravel': async () => {
    const { estado, cuerpo } = await pedir('/draw');
    assert.equal(estado, 200);
    assert.deepEqual(Object.keys(cuerpo.links).sort(), ['first', 'last', 'next', 'prev']);
    assert.deepEqual(Object.keys(cuerpo.meta).sort(), META);
    assert.ok(cuerpo.data.length >= 3, 'faltan sorteos: «wp zona-nautico datos-prueba»');
  },
  'cada sorteo trae los mismos campos, en el mismo orden y con sus tipos': async () => {
    const { cuerpo } = await pedir('/draw');
    for (const s of cuerpo.data) {
      assert.deepEqual(Object.keys(s), CAMPOS);
      assert.equal(typeof s._id, 'string');
      assert.equal(typeof s.winners, 'number');
      assert.ok([0, 1].includes(s.controlParticipar) && [0, 1].includes(s.controlVisibilidad));
      if (s.startDate) assert.match(s.startDate, /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/);
    }
  },
  'banderas calculadas con las fechas': async () => {
    const { cuerpo } = await pedir('/draw?perPage=50');
    const abierto = porTitulo(cuerpo.data, 'Sorteo abierto');
    const futuro = porTitulo(cuerpo.data, 'Sorteo de prueba que aún no empieza');
    const terminado = porTitulo(cuerpo.data, 'Sorteo de prueba terminado');
    assert.deepEqual([abierto.controlParticipar, abierto.controlVisibilidad], [1, 1], 'abierto');
    assert.deepEqual([futuro.controlParticipar, futuro.controlVisibilidad], [0, 1], 'futuro');
    assert.deepEqual([terminado.controlParticipar, terminado.controlVisibilidad], [0, 0], 'terminado');
  },
  '?controlParticipar=1 solo devuelve los abiertos': async () => {
    const { cuerpo } = await pedir('/draw?controlParticipar=1&perPage=50');
    assert.ok(cuerpo.data.length > 0);
    assert.ok(cuerpo.data.every((s) => s.controlParticipar === 1));
  },
  'orden por fecha de inicio': async () => {
    const { cuerpo } = await pedir('/draw?orderby=start_date&order=desc&perPage=50');
    const fechas = cuerpo.data.map((s) => s.startDate).filter(Boolean);
    assert.deepEqual(fechas, [...fechas].sort().reverse());
    assert.equal((await pedir('/draw?orderby=id;drop')).estado, 422);
  },
  'paginación y tope de 50': async () => {
    const { cuerpo } = await pedir('/draw?perPage=1&page=2');
    assert.equal(cuerpo.meta.current_page, 2);
    assert.equal(cuerpo.data.length, 1);
    assert.equal((await pedir('/draw?perPage=1000')).cuerpo.meta.per_page, 50);
  },
  'ficha: { data } y 404 si no existe o es de otro tipo': async () => {
    const { cuerpo: lista } = await pedir('/draw');
    const id = lista.data[0]._id;
    const { estado, cuerpo } = await pedir(`/draw/${id}`);
    assert.equal(estado, 200);
    assert.equal(cuerpo.data._id, id);
    assert.equal((await pedir('/draw/999999')).estado, 404);
    const { cuerpo: eventos } = await pedir('/event');
    assert.equal((await pedir(`/draw/${eventos.data[0]._id}`)).estado, 404);
  },
  'escribir pide sesión: POST sin token da 401 «Unauthenticated.»': async () => {
    const r = await pedir('/draw', 'POST');
    assert.equal(r.estado, 401);
    assert.deepEqual(r.cuerpo, { message: 'Unauthenticated.' });
  },
  'PUT en la lista da 405, como el Laravel': async () => {
    assert.equal((await pedir('/draw', 'PUT')).estado, 405);
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
