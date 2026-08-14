/**
 * Tests du cœur PUR du fil d'activité du chat
 * (assets/controllers/assistant-etapes.js) — aucun DOM, aucun Stimulus.
 * Lancement : node --test tests/js/
 *
 * CE QUI EST EN JEU. Le chat affiche déjà un solde de « tokens » : celui que le
 * cabinet achète et consomme (budget d'un plan, message de quota épuisé). Le fil
 * d'activité, lui, montre les jetons du MOTEUR, qui n'ont aucun rapport et que
 * l'utilisateur ne paie pas à la pièce. Laisser les deux porter le même mot ferait
 * croire à chaque question qu'un solde se vide sous ses yeux. D'où le garde-fou
 * ci-dessous, qui interdit littéralement le mot.
 *
 * Le reste verrouille la tolérance du flux : une clé inconnue, une ligne coupée en
 * deux morceaux réseau ou un fragment illisible ne doivent jamais faire échouer un
 * envoi — le fil informe, il ne commande rien.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
    VERBES,
    verbeEtape,
    compteurEtape,
    decouperFlux,
    resumeActivite,
} from '../../assets/controllers/assistant-etapes.js';

test('chaque étape du serveur a son verbe d’usager', () => {
    assert.equal(verbeEtape('comprehension'), 'réfléchit…');
    assert.equal(verbeEtape('clarification'), 'demande une précision…');
    assert.equal(verbeEtape('planification'), 'prépare le travail…');
    assert.equal(verbeEtape('outils'), 'consulte vos données…');
    assert.equal(verbeEtape('redaction'), 'rédige la réponse…');
    assert.equal(verbeEtape('ecriture'), 'écrit…');
});

test('aucun verbe ne montre nos rouages', () => {
    const jargon = ['planification', 'trousse', 'gemini', 'llm', 'token', 'phase'];
    for (const verbe of Object.values(VERBES)) {
        for (const mot of jargon) {
            assert.ok(
                !verbe.toLowerCase().includes(mot),
                `Le verbe « ${verbe} » laisse filtrer le mot technique « ${mot} ».`,
            );
        }
    }
});

test('une clé inconnue retombe sur l’attente générique, jamais sur la clé brute', () => {
    // Cas réel : page ouverte avant un déploiement qui ajoute une étape.
    assert.equal(verbeEtape('etape_du_futur'), 'réfléchit…');
    assert.equal(verbeEtape(undefined), 'réfléchit…');
    assert.equal(verbeEtape(''), 'réfléchit…');
});

test('rien de consommé, rien d’affiché', () => {
    assert.equal(compteurEtape({ tokensEtape: 0, tokensCumul: 0 }, 'fr'), '');
    assert.equal(compteurEtape({}, 'fr'), '');
    assert.equal(compteurEtape(undefined, 'fr'), '');
});

test('le premier appel affiche un total simple, sans delta redondant', () => {
    assert.equal(compteurEtape({ tokensEtape: 512, tokensCumul: 512 }, 'fr'), '512 jetons IA');
    // Étape locale (les outils ne coûtent rien) : le cumul suffit.
    assert.equal(compteurEtape({ tokensEtape: 0, tokensCumul: 24324 }, 'fr'), '24 324 jetons IA');
});

test('un appel supplémentaire montre ce qu’il ajoute ET le total', () => {
    assert.equal(
        compteurEtape({ tokensEtape: 23812, tokensCumul: 24324 }, 'fr'),
        '+23 812 jetons IA (24 324 au total)',
    );
});

test('on écrit « jetons IA », jamais « tokens » — le mot est pris par la facturation', () => {
    const affiche = [
        compteurEtape({ tokensEtape: 23812, tokensCumul: 24324 }, 'fr'),
        compteurEtape({ tokensEtape: 512, tokensCumul: 512 }, 'fr'),
        resumeActivite({ appels: 3, jetonsIa: 38400, secondes: 6.2 }, 'fr'),
    ];

    for (const texte of affiche) {
        assert.ok(texte.includes('jetons IA'), `« ${texte} » doit nommer les jetons IA.`);
        assert.ok(
            !texte.toLowerCase().includes('token'),
            `« ${texte} » confondrait les jetons du moteur avec le solde du cabinet.`,
        );
    }
});

test('le flux se lit ligne par ligne', () => {
    const [evenements, reste] = decouperFlux('', 'data: {"type":"etape","cle":"redaction"}\n\n');

    assert.deepEqual(evenements, [{ type: 'etape', cle: 'redaction' }]);
    assert.equal(reste, '');
});

test('une ligne coupée entre deux morceaux réseau est recollée', () => {
    // Le cas qui casse tout si on ignore le reste : le morceau s'arrête au milieu.
    const [rien, tampon] = decouperFlux('', 'data: {"type":"etape","cle":"plani');
    assert.deepEqual(rien, []);

    const [evenements] = decouperFlux(tampon, 'fication","tokensEtape":512}\n');
    assert.deepEqual(evenements, [{ type: 'etape', cle: 'planification', tokensEtape: 512 }]);
});

test('plusieurs événements dans un seul morceau sont tous rendus', () => {
    const [evenements] = decouperFlux(
        '',
        'data: {"cle":"planification"}\n\ndata: {"cle":"outils"}\n\n',
    );

    assert.deepEqual(evenements.map((e) => e.cle), ['planification', 'outils']);
});

test('une ligne illisible est ignorée, sans faire échouer l’envoi', () => {
    const [evenements] = decouperFlux('', 'data: {ceci n’est pas du json\ndata: {"cle":"redaction"}\n');

    assert.deepEqual(evenements, [{ cle: 'redaction' }]);
});

test('le récapitulatif tient en une ligne', () => {
    assert.equal(
        resumeActivite({ appels: 3, jetonsIa: 38400, secondes: 6.2 }, 'fr'),
        '3 appels · 38 400 jetons IA · 6,2 s',
    );
    assert.equal(
        resumeActivite({ appels: 1, jetonsIa: 512, secondes: 1.4 }, 'fr'),
        '1 appel · 512 jetons IA · 1,4 s',
    );
});

test('un moteur sans télémétrie n’affiche aucun récapitulatif', () => {
    // Mieux vaut ne rien montrer que montrer des zéros (moteur simulé, Anthropic).
    assert.equal(resumeActivite(null, 'fr'), '');
    assert.equal(resumeActivite({ appels: 0, jetonsIa: 0 }, 'fr'), '');
});
