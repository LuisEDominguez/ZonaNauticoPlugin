// Compara los campos que da el plugin con las respuestas reales del Laravel guardadas en la app
// (Zona-Nautico/api-muestras): mismos nombres y en el mismo orden (R13).
// Uso: node tests/contrato-muestras.mjs [url-base] [carpeta-de-muestras]
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { join } from 'node:path';

const BASE = (process.argv[2] ?? (process.env.ZN_URL ?? 'http://localhost:10004').replace(/\/+$/, '') + '/wp-json/zonanautico/v1').replace(/\/+$/, '');
const MUESTRAS = process.argv[3] ?? fileURLToPath(new URL('../../Zona-Nautico/api-muestras', import.meta.url));

const muestra = (fichero) => JSON.parse(readFileSync(join(MUESTRAS, fichero), 'utf8'));
const pedir = async (ruta) => (await fetch(`${BASE}${ruta}`, { headers: { Accept: 'application/json' } })).json();

// Recurso de la API => [muestra del listado, muestra de la ficha]
const RECURSOS = {
  business: ['business.json', 'business-3866.json'],
  event: ['event.json', 'event-94.json'],
  draw: ['draw.json', 'draw-56.json'],
};

const pruebas = {};
for (const [recurso, [listado, ficha]] of Object.entries(RECURSOS)) {
  pruebas[`${recurso}: listado con los mismos campos que ${listado}`] = async () => {
    const real = muestra(listado);
    const nuestro = await pedir(`/${recurso}`);
    assert.deepEqual(Object.keys(nuestro), Object.keys(real));
    assert.deepEqual(Object.keys(nuestro.meta).sort(), Object.keys(real.meta).sort());
    assert.ok(nuestro.data.length > 0, 'faltan datos: «wp zona-nautico datos-prueba»');
    for (const elemento of nuestro.data) assert.deepEqual(Object.keys(elemento), Object.keys(real.data[0]));
  };
  pruebas[`${recurso}: ficha con los mismos campos que ${ficha}`] = async () => {
    const real = muestra(ficha);
    const { data } = await pedir(`/${recurso}`);
    const nuestra = await pedir(`/${recurso}/${data[0]._id}`);
    assert.deepEqual(Object.keys(nuestra), Object.keys(real));
    assert.deepEqual(Object.keys(nuestra.data), Object.keys(real.data));
  };
}

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
