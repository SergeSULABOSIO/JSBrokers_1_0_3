/**
 * Tests du cœur PUR de la lecture à voix haute de Ket
 * (assets/controllers/assistant-lecture-vocale.js) — aucun DOM, aucune voix.
 * Lancement : node --test tests/js/
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
    choisirVoix,
    decouperEnPhrases,
    noteVoix,
    texteAPrononcer,
} from '../../assets/controllers/assistant-lecture-vocale.js';

test('la mise en forme Markdown ne se prononce pas', () => {
    assert.equal(
        texteAPrononcer('## Synthèse\nLe taux est de **15 %** pour la _Caution_. Voir [la fiche](/risque/4) et `code`.'),
        'Synthèse. Le taux est de 15 % pour la Caution. Voir la fiche et code.',
    );
});

test('une liste devient une suite de phrases marquées par des pauses', () => {
    assert.equal(
        texteAPrononcer('Je propose :\n- **Engineering** (17,5 %)\n- RC Générale\n1. Caution'),
        'Je propose : Engineering (17,5 %). RC Générale. Caution.',
    );
});

test('un tableau se lit ligne par ligne, chaque valeur avec son en-tête', () => {
    const md = 'Voici :\n\n| Client | Prime | Statut |\n|---|---:|:---|\n| Kibali | 12 500 $ | Payée |\n| SUNU |  | En attente |\n\nFin.';
    assert.equal(
        texteAPrononcer(md),
        'Voici : Client : Kibali, Prime : 12 500 $, Statut : Payée. Client : SUNU, Statut : En attente. Fin.',
    );
});

test('un graphique est annoncé sans lire son JSON', () => {
    const md = 'Évolution :\n```chart\n{"type":"bar","data":{"labels":["a"]}}\n```\nBonne lecture.';
    const oral = texteAPrononcer(md);
    assert.ok(!oral.includes('{'), oral);
    assert.equal(oral, "Évolution : Graphique affiché à l'écran. Bonne lecture.");
});

test('emojis, séparateurs et citations disparaissent', () => {
    assert.equal(
        texteAPrononcer('💰 Le taux est de 15 %.\n\n---\n📌 **Priorité actuelle** : 30 renouvellements.\n> note'),
        'Le taux est de 15 %. Priorité actuelle : 30 renouvellements. note.',
    );
});

test('un texte vide ne produit rien', () => {
    assert.equal(texteAPrononcer(''), '');
    assert.equal(texteAPrononcer(null), '');
    assert.deepEqual(decouperEnPhrases(''), []);
});

test('le découpage respecte le maximum sans rien perdre', () => {
    const phrase = 'Le cabinet propose une couverture adaptée aux chantiers de construction, au bris de machines et à la responsabilité civile. ';
    const texte = phrase.repeat(6) + 'Une phrase finale sans point';
    const segments = decouperEnPhrases(texte, 220);

    assert.ok(segments.length > 1);
    for (const s of segments) assert.ok(s.length <= 220, `segment trop long (${s.length})`);
    assert.equal(segments.join(' ').replace(/\s+/g, ' '), texte.replace(/\s+/g, ' ').trim());
});

test('le découpage coupe aux fins de phrase quand c’est possible', () => {
    assert.deepEqual(decouperEnPhrases('Bonjour. Comment allez-vous ? Très bien !', 20), [
        'Bonjour.', 'Comment allez-vous ?', 'Très bien !',
    ]);
    assert.deepEqual(decouperEnPhrases('Bonjour. Ça va ?', 220), ['Bonjour. Ça va ?']);
});

test('une phrase démesurée est coupée aux virgules puis aux espaces', () => {
    const longue = `${'mot '.repeat(80)}fin.`;
    const segments = decouperEnPhrases(longue, 50);
    for (const s of segments) assert.ok(s.length <= 50, `segment trop long (${s.length})`);
    assert.equal(segments.join(' '), longue.trim());
});

const VOIX_EDGE = [
    { name: 'Microsoft Paul - French (France)', lang: 'fr-FR' },
    { name: 'Microsoft Hortense - French (France)', lang: 'fr-FR' },
    { name: 'Microsoft Henri Online (Natural) - French (France)', lang: 'fr-FR' },
    { name: 'Microsoft Denise Online (Natural) - French (France)', lang: 'fr-FR' },
    { name: 'Microsoft Aria Online (Natural) - English (United States)', lang: 'en-US' },
];

test('Edge : la voix neuronale féminine française passe devant tout', () => {
    assert.equal(choisirVoix(VOIX_EDGE, 'fr').name, 'Microsoft Denise Online (Natural) - French (France)');
});

test('les voix masculines ne sont jamais retenues, même neuronales', () => {
    assert.equal(noteVoix({ name: 'Microsoft Henri Online (Natural) - French (France)', lang: 'fr-FR' }, 'fr'), 0);
    assert.equal(choisirVoix([{ name: 'Microsoft Paul - French (France)', lang: 'fr-FR' }], 'fr'), null);
    // Relevé sur un Edge réel (2026-09-16) : seules voix d'un poste réglé en fr-CA.
    assert.equal(choisirVoix([
        { name: 'Microsoft Thierry Online (Natural) - French (Canada)', lang: 'fr-CA' },
        { name: 'Microsoft Sylvie Online (Natural) - French (Canada)', lang: 'fr-CA' },
    ], 'fr').name, 'Microsoft Sylvie Online (Natural) - French (Canada)');
});

test('Chrome : « Google français » est préférée aux voix système', () => {
    const chrome = [
        { name: 'Microsoft Julie - French (France)', lang: 'fr-FR' },
        { name: 'Google français', lang: 'fr-FR' },
        { name: 'Google US English', lang: 'en-US' },
    ];
    assert.equal(choisirVoix(chrome, 'fr').name, 'Google français');
});

test('macOS / iOS : Amélie est retenue, une voix fr quelconque à défaut', () => {
    assert.equal(choisirVoix([{ name: 'Thomas', lang: 'fr-FR' }, { name: 'Amélie', lang: 'fr-CA' }], 'fr').name, 'Amélie');
    assert.equal(choisirVoix([{ name: 'Lecteur', lang: 'fr_FR' }], 'fr').name, 'Lecteur');
});

test('interface anglaise : voix anglaise féminine, jamais une voix française', () => {
    assert.equal(choisirVoix(VOIX_EDGE, 'en').name, 'Microsoft Aria Online (Natural) - English (United States)');
});

test('aucune voix de la langue : null', () => {
    assert.equal(choisirVoix([], 'fr'), null);
    assert.equal(choisirVoix([{ name: 'Google Deutsch', lang: 'de-DE' }], 'fr'), null);
});
