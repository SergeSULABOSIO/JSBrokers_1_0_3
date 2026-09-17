/**
 * Tests des cœurs PURS du mode Live de Ket — aucun micro, aucun DOM, aucun réseau :
 * détection de parole, encodage WAV, machine d'états de la session, intermèdes.
 * Lancement : node --test tests/js/
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

import { creerDetecteur, energie, PLANCHER } from '../../assets/controllers/ket-live-parole.js';
import { assembler, duree, encoderWav, reechantillonner, TAUX_OREILLE } from '../../assets/controllers/ket-live-wav.js';
import { ENTETE_WAV } from '../../assets/controllers/assistant-voix-pcm.js';
import { ETATS, libelleEtatLive, sessionInitiale, transition } from '../../assets/controllers/ket-live-etat.js';
import { choisir, programmeDesIntermedes, RELANCES_MAX } from '../../assets/controllers/ket-live-intermedes.js';

// ── Détection de parole ──────────────────────────────────────────────────────

const trame = (niveau, taille = 128) => Float32Array.from({ length: taille }, () => niveau);

test('l’énergie d’une trame silencieuse est nulle, celle d’une trame forte ne l’est pas', () => {
    assert.equal(energie(new Float32Array(64)), 0);
    assert.ok(energie(trame(0.5)) > 0.4);
    assert.equal(energie(null), 0);
});

test('une phrase commence après une voix continue et finit après le silence', () => {
    const d = creerDetecteur();
    let t = 0;
    // Bruit de fond : rien ne se déclenche.
    for (; t < 1000; t += 50) assert.equal(d.pousser(0.002, t), null);

    // La voix arrive : le début n’est déclaré qu’après le délai de confirmation.
    assert.equal(d.pousser(0.4, t), null, 'une salve trop courte ne déclenche rien');
    t += 200;
    assert.equal(d.pousser(0.4, t), 'debut');

    // On parle, puis on se tait : la fin arrive après le silence complet.
    t += 500;
    assert.equal(d.pousser(0.4, t), null);
    t += 500;
    assert.equal(d.pousser(0.001, t), null, 'un souffle court ne clôt pas la phrase');
    t += 1000;
    assert.equal(d.pousser(0.001, t), 'fin');
});

test('le seuil s’adapte au bruit de fond et ne descend jamais sous le plancher', () => {
    const calme = creerDetecteur();
    const bruyant = creerDetecteur();
    let t = 0;
    for (; t < 4000; t += 50) {
        calme.pousser(0.0005, t);
        bruyant.pousser(0.05, t);
    }
    assert.ok(bruyant.seuil() > calme.seuil(), 'une pièce bruyante exige une voix plus forte');
    assert.ok(calme.seuil() >= PLANCHER);
});

test('pendant que Ket parle, il faut une voix plus forte pour l’interrompre', () => {
    const d = creerDetecteur();
    assert.ok(d.seuil(true) > d.seuil(false));
});

test('une phrase interminable est transcrite sans attendre le silence', () => {
    const d = creerDetecteur({ phraseMaxMs: 2000 });
    let t = 0;
    d.pousser(0.4, t);
    t += 200;
    assert.equal(d.pousser(0.4, t), 'debut');
    t += 2200;
    assert.equal(d.pousser(0.4, t), 'trop-long');
});

// ── WAV ──────────────────────────────────────────────────────────────────────

test('le ré-échantillonnage ramène au taux des oreilles', () => {
    const source = Float32Array.from({ length: 48000 }, (_, i) => Math.sin(i / 100));
    const sortie = reechantillonner(source, 48000, TAUX_OREILLE);
    assert.equal(sortie.length, TAUX_OREILLE);
    assert.equal(reechantillonner(source, 16000, 16000).length, 48000, 'même taux : rien à faire');
});

test('l’encodage produit un WAV mono 16 bits valide', () => {
    const wav = encoderWav(Float32Array.from([0, 0.5, -1, 1]), TAUX_OREILLE);
    const vue = new DataView(wav.buffer);
    const texte = (p) => String.fromCharCode(wav[p], wav[p + 1], wav[p + 2], wav[p + 3]);

    assert.equal(wav.length, ENTETE_WAV + 8);
    assert.equal(texte(0), 'RIFF');
    assert.equal(texte(8), 'WAVE');
    assert.equal(texte(36), 'data');
    assert.equal(vue.getUint16(22, true), 1, 'mono');
    assert.equal(vue.getUint32(24, true), TAUX_OREILLE);
    assert.equal(vue.getUint16(34, true), 16, '16 bits');
    assert.equal(vue.getInt16(ENTETE_WAV, true), 0);
    assert.equal(vue.getInt16(ENTETE_WAV + 2, true), 16384);
    assert.equal(vue.getInt16(ENTETE_WAV + 4, true), -32767);
});

test('les trames d’une phrase se mettent bout à bout, et la durée se déduit', () => {
    const tout = assembler([Float32Array.from([1, 2]), Float32Array.from([3])]);
    assert.deepEqual(Array.from(tout), [1, 2, 3]);
    assert.equal(duree(new Float32Array(TAUX_OREILLE * 2)), 2);
});

// ── Machine d'états ──────────────────────────────────────────────────────────

test('un tour complet : écouter, transcrire, réfléchir, parler, réécouter', () => {
    let s = sessionInitiale();
    assert.equal(s.etat, ETATS.ARRET);

    s = transition(s, 'demarrer');
    assert.equal(s.etat, ETATS.ECOUTE);
    assert.deepEqual(s.actions, ['ouvrir-micro', 'precharger-intermedes']);

    s = transition(s, 'phrase-terminee');
    assert.deepEqual([s.etat, s.actions], [ETATS.TRANSCRIPTION, ['transcrire']]);

    s = transition(s, 'texte-entendu', { texte: 'Quel est le taux de la Caution ?' });
    assert.equal(s.etat, ETATS.REFLEXION);
    assert.deepEqual(s.actions, ['envoyer-question', 'programmer-intermedes']);
    assert.equal(s.derniereParole, 'Quel est le taux de la Caution ?');

    s = transition(s, 'reponse-affichee');
    assert.deepEqual([s.etat, s.actions], [ETATS.PAROLE, ['couper-intermedes', 'lire-reponse']]);

    s = transition(s, 'lecture-terminee');
    assert.equal(s.etat, ETATS.ECOUTE);
});

test('parler pendant que Ket parle l’interrompt et rend la main à l’utilisateur', () => {
    const s = transition({ ...sessionInitiale(), etat: ETATS.PAROLE }, 'voix-detectee');
    assert.deepEqual([s.etat, s.actions], [ETATS.ECOUTE, ['couper-voix']]);
});

test('une transcription vide ou un silence ramènent simplement à l’écoute', () => {
    const depuis = { ...sessionInitiale(), etat: ETATS.TRANSCRIPTION };
    assert.equal(transition(depuis, 'texte-entendu', { texte: '   ' }).etat, ETATS.ECOUTE);
    assert.equal(transition(depuis, 'silence').etat, ETATS.ECOUTE);
});

test('sans oreilles serveur, la session passe en écoute de secours', () => {
    const s = transition({ ...sessionInitiale(), etat: ETATS.TRANSCRIPTION }, 'oreille-indisponible');
    assert.deepEqual([s.etat, s.oreille, s.secours, s.actions], [ETATS.ECOUTE, 'navigateur', true, ['oreille-navigateur']]);
    assert.match(libelleEtatLive(s), /secours/);
});

test('un événement hors de propos ne dérègle pas la session', () => {
    const ecoute = { ...sessionInitiale(), etat: ETATS.ECOUTE };
    assert.equal(transition(ecoute, 'reponse-affichee').etat, ETATS.ECOUTE, 'une réponse tardive est ignorée');
    assert.equal(transition(ecoute, 'lecture-terminee').etat, ETATS.ECOUTE);
    assert.equal(transition(sessionInitiale(), 'phrase-terminee').etat, ETATS.ARRET);
    assert.equal(transition(ecoute, 'inconnu').etat, ETATS.ECOUTE);
});

test('arrêter ferme le micro et coupe la voix, depuis n’importe quel état', () => {
    for (const etat of [ETATS.ECOUTE, ETATS.REFLEXION, ETATS.PAROLE]) {
        const s = transition({ ...sessionInitiale(), etat }, 'arreter');
        assert.deepEqual([s.etat, s.actions], [ETATS.ARRET, ['fermer-micro', 'couper-voix']]);
    }
});

test('une panne ne met pas fin à la session : Ket réécoute', () => {
    const s = transition({ ...sessionInitiale(), etat: ETATS.REFLEXION }, 'erreur');
    assert.deepEqual([s.etat, s.actions], [ETATS.ECOUTE, ['couper-intermedes', 'couper-voix']]);
});

test('les libellés d’état sont du texte lisible, jamais une couleur seule', () => {
    assert.match(libelleEtatLive({ etat: ETATS.REFLEXION }), /réfléchit/);
    assert.match(libelleEtatLive({ etat: ETATS.PAROLE }), /interrompre/);
});

// ── Intermèdes ───────────────────────────────────────────────────────────────

const CATALOGUE = {
    debut: { 'debut-1': 'Hum…', 'debut-2': 'Un instant…', 'debut-3': 'Je vérifie…' },
    relance: { 'relance-1': 'Encore un peu…', 'relance-2': 'Ça vient…' },
};

test('le programme parle après un court délai, puis espace ses relances', () => {
    const etapes = programmeDesIntermedes();
    assert.equal(etapes[0].moment, 'debut');
    assert.ok(etapes[0].delaiMs >= 500 && etapes[0].delaiMs <= 1500, 'rien avant ~0,6 s : une réponse rapide se passe d’intermède');
    assert.equal(etapes.length, RELANCES_MAX + 1);
    for (let i = 1; i < etapes.length; i++) {
        assert.equal(etapes[i].moment, 'relance');
        assert.ok(etapes[i].delaiMs - etapes[i - 1].delaiMs >= 5000, 'les relances ne se bousculent pas');
    }
});

test('on ne répète pas une phrase déjà dite pendant la même attente', () => {
    const premier = choisir(CATALOGUE, 'debut', [], () => 0);
    const second = choisir(CATALOGUE, 'debut', [premier], () => 0);
    const troisieme = choisir(CATALOGUE, 'debut', [premier, second], () => 0);
    assert.equal(new Set([premier, second, troisieme]).size, 3);

    // Catalogue épuisé : on repart du début, en évitant seulement la dernière dite.
    const suivant = choisir(CATALOGUE, 'debut', [premier, second, troisieme], () => 0);
    assert.notEqual(suivant, troisieme);
});

test('le catalogue du serveur, qui n’envoie que des clés, est compris tel quel', () => {
    // C'est la forme réelle : intermedes_de_ket() rend { moment: [clés] }.
    const duServeur = { debut: ['debut-1', 'debut-2'], relance: ['relance-1'] };
    assert.equal(choisir(duServeur, 'relance', [], () => 0), 'relance-1');
    const premier = choisir(duServeur, 'debut', [], () => 0);
    assert.ok(['debut-1', 'debut-2'].includes(premier));
    assert.notEqual(choisir(duServeur, 'debut', [premier], () => 0), premier);
});

test('un moment inconnu ou vide ne fait rien dire', () => {
    assert.equal(choisir(CATALOGUE, 'inconnu'), null);
    assert.equal(choisir({}, 'debut'), null);
});
