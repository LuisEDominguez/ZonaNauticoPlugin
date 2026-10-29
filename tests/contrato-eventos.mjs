// Comprueba que GET /event responde con el mismo formato que la API Laravel (api-muestras/event.json de la app).
// Uso, con el sitio de Local encendido y los datos de prueba metidos (wp zona-nautico datos-prueba):
//   node tests/contrato-eventos.mjs [url-base]
import assert from 'node:assert/strict';

const BASE = (process.argv[2] ?? 'http://localhost:10010/wp-json/zonanautico/v1').replace(/\/+$/, '');

const CAMPOS = [
  '_id', 'name', 'description', 'latitude', 'longitude', 'photo_0', 'photo_1', 'photo_2', 'video_0', 'video_1', 'video_2',
  'startDate', 'startDateLongFormat', 'startDateShortFormat', 'endDate', 'endDateLongFormat', 'endDateShortFormat',
];
const META = ['current_page', 'from', 'last_page', 'links', 'path', 'per_page', 'to', 'total'];

async function pedir(ruta, metodo = 'GET') {
  const r = await fetch(`${BASE}${ruta}`, { method: metodo, headers: { Accept: 'application/json' } });
  return { estado: r.status, cuerpo: await r.json() };
}

const pruebas = {
  'listado: data, links y meta como Laravel': async () => {
    const { estado, cuerpo } = await pedir('/event');
    assert.equal(estado, 200);
    assert.deepEqual(Object.keys(cuerpo.links).sort(), ['first', 'last', 'next', 'prev']);
    assert.deepEqual(Object.keys(cuerpo.meta).sort(), META);
    assert.ok(cuerpo.data.length > 0, 'sin datos: falta «wp zona-nautico datos-prueba»');
  },
  'cada evento trae los mismos campos, en el mismo orden': async () => {
    const { cuerpo } = await pedir('/event');
    for (const e of cuerpo.data) {
      assert.deepEqual(Object.keys(e), CAMPOS);
      assert.equal(typeof e._id, 'string');
    }
  },
  'fechas en los tres formatos del Laravel': async () => {
    const { cuerpo } = await pedir('/event?perPage=50');
    const conFecha = cuerpo.data.find((e) => e.startDate);
    assert.match(conFecha.startDate, /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/);
    const [a, m, d, h, min] = conFecha.startDate.split(/[- :]/);
    assert.equal(conFecha.startDateLongFormat, `${d}-${m}-${a} ${h}:${min}`);
    assert.equal(conFecha.startDateShortFormat, `${d}-${m}-${a}`);
    const sinFecha = cuerpo.data.find((e) => !e.startDate);
    assert.equal(sinFecha.startDateLongFormat, null);
    assert.equal(sinFecha.endDateShortFormat, null);
  },
  'includesCategory: { _id, name } en singular': async () => {
    const { cuerpo } = await pedir('/event?includesCategory=1&perPage=50');
    const nombres = new Set(cuerpo.data.map((e) => e.category?.name));
    for (const n of nombres) assert.ok(['Evento', 'Promoción', 'Noticia'].includes(n), n);
    for (const e of cuerpo.data) assert.deepEqual(Object.keys(e.category), ['_id', 'name']);
  },
  'filtro por categoría': async () => {
    const { cuerpo } = await pedir('/event?category=2&includesCategory=1&perPage=50');
    assert.ok(cuerpo.data.length > 0);
    assert.ok(cuerpo.data.every((e) => e.category._id === '2'));
  },
  'filtro por negocio e includesBusiness': async () => {
    const { cuerpo: todos } = await pedir('/event?includesBusiness=1&perPage=50');
    const conNegocio = todos.data.find((e) => e.business);
    assert.ok(conNegocio, 'ningún evento de prueba tiene negocio');
    assert.equal(typeof conNegocio.business.name, 'string');
    const { cuerpo } = await pedir(`/event?business=${conNegocio.business._id}&perPage=50`);
    assert.ok(cuerpo.data.length > 0 && cuerpo.data.length < todos.data.length);
    assert.equal((await pedir('/event?business=999999')).cuerpo.meta.total, 0);
  },
  'orden por fecha de inicio (Adalo: start_date DESC)': async () => {
    const { cuerpo } = await pedir('/event?orderby=start_date&order=desc&perPage=50');
    const fechas = cuerpo.data.map((e) => e.startDate).filter(Boolean);
    assert.deepEqual(fechas, [...fechas].sort().reverse());
    assert.equal((await pedir('/event?orderby=id;drop')).estado, 400);
  },
  'paginación y tope de 50': async () => {
    const { cuerpo } = await pedir('/event?perPage=1&page=2');
    assert.equal(cuerpo.meta.current_page, 2);
    assert.equal(cuerpo.data.length, 1);
    assert.equal((await pedir('/event?perPage=1000')).cuerpo.meta.per_page, 50);
  },
  'ficha: { data } y 404 si no existe o es de otro tipo': async () => {
    const { cuerpo: lista } = await pedir('/event');
    const id = lista.data[0]._id;
    const { estado, cuerpo } = await pedir(`/event/${id}?includesCategory=1`);
    assert.equal(estado, 200);
    assert.equal(cuerpo.data._id, id);
    assert.ok('category' in cuerpo.data);
    assert.equal((await pedir('/event/999999')).estado, 404);
    const { cuerpo: negocios } = await pedir('/business');
    assert.equal((await pedir(`/event/${negocios.data[0]._id}`)).estado, 404);
  },
  'solo lectura: no acepta POST': async () => {
    assert.equal((await pedir('/event', 'POST')).estado, 404);
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
