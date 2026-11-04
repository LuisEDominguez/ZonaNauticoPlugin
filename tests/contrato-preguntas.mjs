// Comprueba que GET /faq responde con el mismo formato que la API Laravel (FaqResource) y que
// nunca enseña preguntas sin publicar (H20).
// Uso, con el sitio de Local encendido y los datos de prueba metidos (wp zona-nautico datos-prueba):
//   node tests/contrato-preguntas.mjs [url-base]
import assert from 'node:assert/strict';

const BASE = (process.argv[2] ?? (process.env.ZN_URL ?? 'http://localhost:10004').replace(/\/+$/, '') + '/wp-json/zonanautico/v1').replace(/\/+$/, '');

const CAMPOS = ['_id', 'question', 'answer', 'published'];
const USUARIO = ['_id', 'name', 'role', 'avatar'];
const META = ['current_page', 'from', 'last_page', 'links', 'path', 'per_page', 'to', 'total'];

async function pedir(ruta, metodo = 'GET') {
  const r = await fetch(`${BASE}${ruta}`, { method: metodo, headers: { Accept: 'application/json' } });
  return { estado: r.status, cuerpo: await r.json() };
}

/** Lo mismo que hace la app: ficha del negocio con includesUser para saber el dueño. */
async function duenoDeLaTaberna() {
  const { cuerpo } = await pedir('/business?perPage=50');
  const taberna = cuerpo.data.find((b) => b.name === 'Taberna O Peirao (prueba)');
  assert.ok(taberna, 'falta la Taberna: «wp zona-nautico datos-prueba»');
  const { cuerpo: ficha } = await pedir(`/business/${taberna._id}?includesUser=1`);
  assert.ok(ficha.data.user?._id, 'la Taberna no tiene dueño: «wp zona-nautico datos-prueba»');
  return ficha.data.user;
}

const pruebas = {
  'listado: data, links y meta como Laravel': async () => {
    const { estado, cuerpo } = await pedir('/faq');
    assert.equal(estado, 200);
    assert.deepEqual(Object.keys(cuerpo.links).sort(), ['first', 'last', 'next', 'prev']);
    assert.deepEqual(Object.keys(cuerpo.meta).sort(), META);
    assert.ok(cuerpo.data.length >= 2, 'faltan preguntas: «wp zona-nautico datos-prueba»');
  },
  'cada pregunta trae los mismos campos, en el mismo orden y con sus tipos': async () => {
    const { cuerpo } = await pedir('/faq');
    for (const p of cuerpo.data) {
      assert.deepEqual(Object.keys(p), CAMPOS);
      assert.equal(typeof p._id, 'string');
      assert.equal(p.published, 1);
    }
  },
  'el dueño del negocio sale solo con sus 4 campos públicos (H27)': async () => {
    assert.deepEqual(Object.keys(await duenoDeLaTaberna()), USUARIO);
  },
  'como la app: ?user=<dueño>&published=1 trae las preguntas del comercio': async () => {
    const dueno = await duenoDeLaTaberna();
    const { cuerpo } = await pedir(`/faq?user=${dueno._id}&published=1&includesUser=1`);
    assert.equal(cuerpo.data.length, 2);
    for (const p of cuerpo.data) {
      assert.equal(p.user._id, dueno._id);
      assert.deepEqual(Object.keys(p.publisher), USUARIO);
    }
    assert.ok(cuerpo.data.some((p) => p.answer) && cuerpo.data.some((p) => !p.answer), 'una respondida y otra no');
  },
  'las no publicadas no salen nunca (H20), se pida lo que se pida': async () => {
    const todas = await pedir('/faq?perPage=50');
    assert.ok(todas.cuerpo.data.every((p) => !p.question.includes('sin publicar')));
    const noPublicadas = await pedir('/faq?published=0');
    assert.equal(noPublicadas.estado, 200);
    assert.equal(noPublicadas.cuerpo.data.length, 0);
    assert.equal(noPublicadas.cuerpo.meta.total, 0);
  },
  'un usuario sin negocios no tiene preguntas': async () => {
    assert.equal((await pedir('/faq?user=99999')).cuerpo.data.length, 0);
    assert.equal((await pedir('/faq?user=abc')).estado, 422);
  },
  'paginación y tope de 50': async () => {
    const { cuerpo } = await pedir('/faq?perPage=1&page=2');
    assert.equal(cuerpo.meta.current_page, 2);
    assert.equal(cuerpo.data.length, 1);
    assert.equal((await pedir('/faq?perPage=1000')).cuerpo.meta.per_page, 50);
  },
  'ficha: { data } y 404 si no existe, no está publicada o es de otro tipo': async () => {
    const { cuerpo: lista } = await pedir('/faq');
    const id = lista.data[0]._id;
    const { estado, cuerpo } = await pedir(`/faq/${id}`);
    assert.equal(estado, 200);
    assert.equal(cuerpo.data._id, id);
    assert.equal((await pedir('/faq/999999')).estado, 404);
    const { cuerpo: resenas } = await pedir('/review');
    assert.equal((await pedir(`/faq/${resenas.data[0]._id}`)).estado, 404);
    // La ficha de una no publicada se comprueba en tests/comentarios.php (aquí no sabemos su id).
  },
  'escribir pide sesión: POST sin token da 401 «Unauthenticated.»': async () => {
    const r = await pedir('/faq', 'POST');
    assert.equal(r.estado, 401);
    assert.deepEqual(r.cuerpo, { message: 'Unauthenticated.' });
  },
  'PUT da 405, como el Laravel': async () => {
    assert.equal((await pedir('/faq', 'PUT')).estado, 405);
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
