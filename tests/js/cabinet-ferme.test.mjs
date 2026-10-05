/**
 * LE RETOUR AU CHOIX D'ESPACE QUAND LE CABINET SE FERME — ET SES DEUX GARDE-FOUS.
 *
 * ── CE QUE CE FICHIER PROTÈGE ───────────────────────────────────────────────────────
 * Le gardien serveur `CabinetActif` ferme le cabinet dès que l'`Invite` qui l'autorisait
 * disparaît, et répond **409 + `{ redirect }`** aux requêtes asynchrones. Un 409 plutôt
 * qu'une 302 parce qu'une redirection suivie par `fetch` ferait injecter une page entière
 * dans un panneau. Encore faut-il que le navigateur fasse le voyage : c'est le rôle de
 * `assets/cabinet-ferme.js`, et sans lui l'utilisateur reste devant un écran mort.
 *
 * ── POURQUOI LES DEUX TRANSPORTS ────────────────────────────────────────────────────
 * 44 fichiers de `assets/controllers/` appellent `fetch`, 31 utilisent `XMLHttpRequest`.
 * Ne couvrir que `fetch` laisserait la moitié du produit sur le bord du chemin — dont
 * les listes de collection.
 *
 * ── POURQUOI LES DEUX CONTRÔLES D'ORIGINE ───────────────────────────────────────────
 * Un 409 n'appartient à personne : un service tiers peut en rendre un, avec un corps
 * JSON où figure un champ `redirect`. Et même venant de nous, une destination externe
 * ferait de cette mécanique une redirection ouverte — l'adresse affichée reste la nôtre
 * jusqu'au saut, ce qui est exactement ce que cherche un hameçonnage.
 *
 * Lancement : node --test tests/js/
 */
import { test, beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const SOURCE = new URL('../../assets/cabinet-ferme.js', import.meta.url);
const ORIGINE = 'https://www.joseara.com';

/** Monte un faux navigateur, charge le module, et rend de quoi l'interroger. */
function monterLeNavigateur() {
    const sauts = [];

    const fauxXhr = function () {
        this.status = 0;
        this.responseURL = '';
        this.responseText = '';
        this.responseType = '';
        this.__ecouteurs = [];
    };
    fauxXhr.prototype.addEventListener = function (nom, rappel) {
        this.__ecouteurs.push([nom, rappel]);
    };
    fauxXhr.prototype.send = function () {
        this.__envoye = true;
    };

    globalThis.window = {
        location: {
            origin: ORIGINE,
            assign(adresse) {
                sauts.push(adresse);
            },
        },
    };
    globalThis.XMLHttpRequest = fauxXhr;

    // Le module s'installe une seule fois par page : on le recharge à chaque cas.
    const code = readFileSync(SOURCE, 'utf8');
    // eslint-disable-next-line no-new-func
    new Function('globalThis', `with (globalThis) { ${code} }`)(globalThis);

    return { sauts, fauxXhr };
}

/** Rend une réponse de type `fetch`, avec le minimum dont le module se sert. */
function reponse(status, url, corps) {
    return {
        status,
        url,
        clone: () => ({ text: async () => corps }),
    };
}

beforeEach(() => {
    delete globalThis.window;
    delete globalThis.XMLHttpRequest;
    delete globalThis.fetch;
});

test('fetch : un 409 des nôtres renvoie au choix d’espace', async () => {
    globalThis.window = undefined;
    const { sauts } = monterLeNavigateur();
    window.fetch = async () => reponse(409, `${ORIGINE}/admin/assureur/api/get-form/0`,
        JSON.stringify({ redirect: '/admin/entreprise' }));
    monterLeNavigateur.__ = null;

    // On réinstalle par-dessus le faux fetch, comme le ferait la page.
    delete window.__cabinetFermeInstalle;
    const code = readFileSync(SOURCE, 'utf8');
    new Function('globalThis', `with (globalThis) { ${code} }`)(globalThis);

    await window.fetch('/admin/assureur/api/get-form/0');

    assert.deepEqual(sauts, ['/admin/entreprise'],
        'Un 409 portant une destination interne doit faire naviguer : sans cela, le panneau reste mort.');
});

test('fetch : un 409 VENU D’AILLEURS ne fait rien naviguer', async () => {
    const { sauts } = monterLeNavigateur();
    window.fetch = async () => reponse(409, 'https://tiers.example.com/api',
        JSON.stringify({ redirect: '/admin/entreprise' }));
    delete window.__cabinetFermeInstalle;
    new Function('globalThis', `with (globalThis) { ${readFileSync(SOURCE, 'utf8')} }`)(globalThis);

    await window.fetch('https://tiers.example.com/api');

    assert.deepEqual(sauts, [],
        'Un service tiers peut rendre un 409 avec un champ `redirect` pour ses propres raisons. '
        + 'Le suivre reviendrait à lui laisser décider où va notre utilisateur.');
});

test('fetch : une destination EXTERNE est ignorée, même venant de nous', async () => {
    const { sauts } = monterLeNavigateur();
    window.fetch = async () => reponse(409, `${ORIGINE}/admin/x`,
        JSON.stringify({ redirect: 'https://hameconnage.example.com/login' }));
    delete window.__cabinetFermeInstalle;
    new Function('globalThis', `with (globalThis) { ${readFileSync(SOURCE, 'utf8')} }`)(globalThis);

    await window.fetch('/admin/x');

    assert.deepEqual(sauts, [],
        'Une redirection ouverte : l’adresse affichée reste la nôtre jusqu’au saut. '
        + 'C’est précisément ce que cherche un hameçonnage.');
});

test('fetch : « //ailleurs » est une destination externe, pas un chemin relatif', async () => {
    const { sauts } = monterLeNavigateur();
    window.fetch = async () => reponse(409, `${ORIGINE}/admin/x`,
        JSON.stringify({ redirect: '//hameconnage.example.com/login' }));
    delete window.__cabinetFermeInstalle;
    new Function('globalThis', `with (globalThis) { ${readFileSync(SOURCE, 'utf8')} }`)(globalThis);

    await window.fetch('/admin/x');

    assert.deepEqual(sauts, [],
        'Un chemin « relatif au protocole » commence par // et part ailleurs. Le confondre avec '
        + 'un chemin interne rouvrirait la redirection ouverte par la petite porte.');
});

test('fetch : un 409 sans champ redirect est laissé tranquille', async () => {
    const { sauts } = monterLeNavigateur();
    window.fetch = async () => reponse(409, `${ORIGINE}/admin/x`, JSON.stringify({ message: 'conflit' }));
    delete window.__cabinetFermeInstalle;
    new Function('globalThis', `with (globalThis) { ${readFileSync(SOURCE, 'utf8')} }`)(globalThis);

    await window.fetch('/admin/x');

    assert.deepEqual(sauts, [],
        'Un 409 peut venir d’un conflit d’enregistrement légitime. C’est la présence de '
        + '`redirect` qui tranche, jamais le statut seul.');
});

test('fetch : la réponse reste intacte pour l’appelant', async () => {
    monterLeNavigateur();
    const corps = JSON.stringify({ redirect: '/admin/entreprise' });
    window.fetch = async () => reponse(409, `${ORIGINE}/admin/x`, corps);
    delete window.__cabinetFermeInstalle;
    new Function('globalThis', `with (globalThis) { ${readFileSync(SOURCE, 'utf8')} }`)(globalThis);

    const recue = await window.fetch('/admin/x');

    assert.equal(await recue.clone().text(), corps,
        'Le module clone avant de lire : consommer le corps priverait l’appelant du sien.');
});

test('XMLHttpRequest : un 409 des nôtres renvoie aussi au choix d’espace', () => {
    const { sauts, fauxXhr } = monterLeNavigateur();

    const requete = new fauxXhr();
    requete.send();
    requete.status = 409;
    requete.responseURL = `${ORIGINE}/admin/assureur/api/get-form/0`;
    requete.responseText = JSON.stringify({ redirect: '/admin/entreprise' });
    requete.__ecouteurs.filter(([nom]) => nom === 'loadend').forEach(([, rappel]) => rappel());

    assert.deepEqual(sauts, ['/admin/entreprise'],
        '31 fichiers du projet passent par XMLHttpRequest : ne couvrir que fetch les laisserait '
        + 'tous sans retour au choix d’espace.');
});

test('XMLHttpRequest : une réponse venue d’ailleurs ne fait rien naviguer', () => {
    const { sauts, fauxXhr } = monterLeNavigateur();

    const requete = new fauxXhr();
    requete.send();
    requete.status = 409;
    requete.responseURL = 'https://tiers.example.com/api';
    requete.responseText = JSON.stringify({ redirect: '/admin/entreprise' });
    requete.__ecouteurs.filter(([nom]) => nom === 'loadend').forEach(([, rappel]) => rappel());

    assert.deepEqual(sauts, []);
});

test('XMLHttpRequest : un responseType binaire ne fait RIEN lever', () => {
    const { sauts, fauxXhr } = monterLeNavigateur();

    const requete = new fauxXhr();
    requete.send();
    requete.status = 409;
    requete.responseURL = `${ORIGINE}/admin/x`;
    requete.responseType = 'blob';
    // Un vrai XMLHttpRequest JETTE une InvalidStateError a la simple LECTURE de
    // responseText des que responseType vaut blob, arraybuffer ou document. On
    // reproduit ce comportement : c'est la seule facon que ce test ait un sens.
    Object.defineProperty(requete, 'responseText', {
        get() {
            throw new DOMException('InvalidStateError');
        },
    });

    assert.doesNotThrow(
        () => requete.__ecouteurs.filter(([nom]) => nom === 'loadend').forEach(([, rappel]) => rappel()),
        'Nous sommes dans l’ecouteur d’une requete qui ne nous appartient pas : une exception '
        + 'levee ici casserait le traitement de l’appelant, pour une reponse qui ne nous '
        + 'concernait meme pas.',
    );
    assert.deepEqual(sauts, [], 'Un corps binaire ne porte pas de redirection.');
});

test('XMLHttpRequest : un responseType « json » est lu par response, pas par responseText', () => {
    const { sauts, fauxXhr } = monterLeNavigateur();

    const requete = new fauxXhr();
    requete.send();
    requete.status = 409;
    requete.responseURL = `${ORIGINE}/admin/x`;
    requete.responseType = 'json';
    requete.response = { redirect: '/admin/entreprise' };
    Object.defineProperty(requete, 'responseText', {
        get() {
            throw new DOMException('InvalidStateError');
        },
    });

    requete.__ecouteurs.filter(([nom]) => nom === 'loadend').forEach(([, rappel]) => rappel());

    assert.deepEqual(sauts, ['/admin/entreprise'],
        'En responseType « json », le corps est servi deja decode par `response` et JAMAIS par '
        + '`responseText`. Le chercher au mauvais endroit perdrait la redirection.');
});

test('XMLHttpRequest : l’envoi d’origine est bien appelé', () => {
    const { fauxXhr } = monterLeNavigateur();

    const requete = new fauxXhr();
    requete.send();

    assert.equal(requete.__envoye, true,
        'L’enveloppe ne doit rien empêcher : elle écoute, elle ne remplace pas.');
});
