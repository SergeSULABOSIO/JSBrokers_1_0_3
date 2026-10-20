/**
 * UNE LECTURE COUPÉE PAR LE RÉSEAU SE RETENTE UNE FOIS ; UNE ÉCRITURE, JAMAIS.
 *
 * Production, 2026-10-19 : des requêtes coupées en plein vol (`ERR_CONNECTION_RESET`,
 * `ERR_CONNECTION_CLOSED`) laissaient un onglet vide. Une lecture se rejoue sans risque ;
 * un enregistrement rejoué serait fait deux fois.
 *
 * Lancement : node --test tests/js/
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { doitReprendre, methodeDe, adresseDe, signalDe } from '../../assets/reprise-reseau-regles.js';

const ORIGINE = 'https://www.joseara.com';
const coupure = () => new TypeError('Failed to fetch');
const appel = (surcharges = {}) => ({ methode: 'GET', erreur: coupure(), signal: null, adresse: '/espacedetravail/api/load-component/1/1', origine: ORIGINE, ...surcharges });

test('une lecture coupée par le réseau se retente', () => {
    assert.equal(doitReprendre(appel()), true);
    assert.equal(doitReprendre(appel({ methode: 'HEAD' })), true);
    assert.equal(doitReprendre(appel({ adresse: `${ORIGINE}/admin/x` })), true);
});

test('une écriture ne se retente JAMAIS (elle serait faite deux fois)', () => {
    for (const methode of ['POST', 'PUT', 'PATCH', 'DELETE']) {
        assert.equal(doitReprendre(appel({ methode })), false, methode);
    }
});

test('une annulation de l\'appelant ne se retente pas', () => {
    const abort = new DOMException('The user aborted a request.', 'AbortError');
    assert.equal(doitReprendre(appel({ erreur: abort })), false);
    const controleur = new AbortController();
    controleur.abort();
    assert.equal(doitReprendre(appel({ signal: controleur.signal })), false);
});

test('seule une coupure réseau (TypeError) se retente, pas une autre erreur', () => {
    assert.equal(doitReprendre(appel({ erreur: new Error('autre') })), false);
    assert.equal(doitReprendre(appel({ erreur: 'chaîne' })), false);
});

test('une autre origine ne se retente pas', () => {
    assert.equal(doitReprendre(appel({ adresse: 'https://ailleurs.example/x' })), false);
    assert.equal(doitReprendre(appel({ adresse: '//ailleurs.example/x' })), false);
});

test('méthode, adresse et signal se lisent sur chaîne, URL ou Request', () => {
    assert.equal(methodeDe('/x'), 'GET');
    assert.equal(methodeDe('/x', { method: 'post' }), 'POST');
    assert.equal(methodeDe({ url: '/x', method: 'DELETE' }), 'DELETE');
    assert.equal(adresseDe('/x'), '/x');
    assert.equal(adresseDe(new URL('https://www.joseara.com/y')), 'https://www.joseara.com/y');
    assert.equal(adresseDe({ url: '/z' }), '/z');
    const controleur = new AbortController();
    assert.equal(signalDe('/x', { signal: controleur.signal }), controleur.signal);
    assert.equal(signalDe('/x'), null);
});

/** L'installateur réel, sur un `window` simulé : une reprise, une seule, et seulement quand il faut. */
async function installer(reponses) {
    const appels = [];
    globalThis.window = {
        location: { origin: ORIGINE },
        fetch: async (entree, options) => {
            appels.push({ entree, options });
            const suivante = reponses.shift();
            if (suivante instanceof Error) throw suivante;
            return suivante;
        },
    };
    const avertissement = console.warn;
    console.warn = () => {};
    await import(`../../assets/reprise-reseau.js?essai=${Math.random()}`);
    console.warn = avertissement;

    return appels;
}

test('installé : une coupure puis un succès rend le succès, en deux appels', async () => {
    const ok = { status: 200 };
    const appels = await installer([coupure(), ok]);
    const avertissement = console.warn;
    console.warn = () => {};
    const reponse = await window.fetch('/espacedetravail/api/load-component/1/1');
    console.warn = avertissement;
    assert.equal(reponse, ok);
    assert.equal(appels.length, 2);
});

test('installé : deux coupures — l\'erreur remonte, sans troisième essai', async () => {
    const appels = await installer([coupure(), coupure()]);
    const avertissement = console.warn;
    console.warn = () => {};
    await assert.rejects(() => window.fetch('/x'), TypeError);
    console.warn = avertissement;
    assert.equal(appels.length, 2);
});

test('installé : un POST coupé n\'est PAS rejoué', async () => {
    const appels = await installer([coupure(), { status: 200 }]);
    await assert.rejects(() => window.fetch('/admin/enregistrer', { method: 'POST', body: '{}' }), TypeError);
    assert.equal(appels.length, 1);
});
