/**
 * Tests des cœurs PURS du mode Live de Ket — aucun micro, aucun DOM, aucun réseau :
 * détection de parole, encodage WAV, machine d'états de la session, intermèdes.
 * Lancement : node --test tests/js/
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

import { creerDetecteur, energie, FIN_MS, PLANCHER } from '../../assets/controllers/ket-live-parole.js';
import { jalon, nouveauTour, resumeDuTour, totalDuTour } from '../../assets/controllers/ket-live-chrono.js';
import { assembler, duree, encoderWav, reechantillonner, TAUX_OREILLE } from '../../assets/controllers/ket-live-wav.js';
import { ENTETE_WAV } from '../../assets/controllers/assistant-voix-pcm.js';
import { ETATS, libelleEtatLive, sessionInitiale, transition } from '../../assets/controllers/ket-live-etat.js';
import { choisir, programmeDesIntermedes, RELANCES_MAX } from '../../assets/controllers/ket-live-intermedes.js';
import { MARGE_PROCHE, nEstQueDesTics, phraseRecevable, retirerLaVoixDeKet } from '../../assets/controllers/ket-live-tri.js';

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
    let t = 0;
    for (; t < 3000; t += 50) d.pousser(0.001, t);
    const seuilAuRepos = d.seuil(false);

    // La barre ne monte qu'une fois la fuite du haut-parleur ÉCOUTÉE : avant de l'avoir
    // entendue, on ne sait pas de combien il faut l'élever.
    for (let i = 0; i < 20; i++, t += 50) d.pousser(0.05, t, true);

    assert.ok(d.seuil(true) > seuilAuRepos, 'la barre monte à la hauteur du haut-parleur');
    assert.equal(d.seuil(false), seuilAuRepos, 'celle de l’écoute ordinaire, elle, ne bouge pas');
});

/**
 * KET SE COUPAIT ELLE-MÊME (signalé le 2026-09-19 : « elle commence juste à parler et
 * coupe une seconde après, elle n'était qu'au début de sa phrase »).
 *
 * Le micro reste ouvert pendant qu'elle parle, pour entendre l'utilisateur la couper.
 * Mais ce qui revient alors dans le micro n'est pas le silence du bureau : c'est SA
 * VOIX. Comparée au bruit de la pièce, elle franchissait le seuil, se prenait pour
 * l'utilisateur, et se taisait au bout d'une seconde — toujours au même moment,
 * puisqu'il faut 150 ms de voix continue pour déclarer une prise de parole.
 */
test('Ket ne se coupe jamais elle-même, si fort que soit son haut-parleur', () => {
    const d = creerDetecteur();
    let t = 0;
    for (; t < 3000; t += 50) d.pousser(0.001, t); // pièce calme

    // Elle parle : son haut-parleur renvoie 0,05 dans le micro — quatre fois le
    // plancher, et bien au-dessus de ce qu'une pièce calme produit.
    let evenements = [];
    for (let i = 0; i < 120; i++, t += 50) evenements.push(d.pousser(0.05, t, true));

    assert.deepEqual([...new Set(evenements)], [null], 'six secondes de sa propre voix, et aucune prise de parole détectée');
    assert.ok(d.fuiteMesuree() >= 0.04, 'la fuite du haut-parleur a bien été mesurée');
    assert.ok(d.seuil(true) > 0.05, 'le seuil d’interruption est passé AU-DESSUS de sa propre voix');
});

/**
 * LE CAS RÉEL, ET CELUI QUI L'A RENDUE TOTALEMENT MUETTE (2026-09-19, seconde alerte :
 * « Ket ne parle PLUS »).
 *
 * Ket est tenue pour « audible » dès sa RÉFLEXION, parce que ses intermèdes y sortent
 * du haut-parleur. Mais quand aucun intermède n'est disponible — quota épuisé —, ce
 * qu'on mesure pendant cette réflexion est le SILENCE de la pièce. La barre retombait
 * au plancher, et sa réponse, bien plus forte, la franchissait dès le premier mot :
 * elle se coupait elle-même en 150 ms. Aucun son ne sortait plus, et l'attente
 * paraissait interminable.
 *
 * D'où la règle : on recalibre au moment où un son COMMENCE, pas au changement d'état.
 */
test('une réflexion silencieuse ne fait pas taire la réponse qui suit', () => {
    const d = creerDetecteur();
    let t = 0;
    for (; t < 3000; t += 50) d.pousser(0.001, t); // pièce calme

    // Réflexion : Ket est « audible », mais aucun intermède ne sort (quota épuisé).
    for (let i = 0; i < 40; i++, t += 50) d.pousser(0.001, t, true);

    // Elle prend la parole : le contrôleur recalibre sur CE son-là.
    d.recalibrer(t);
    const evenements = [];
    for (let i = 0; i < 100; i++, t += 50) evenements.push(d.pousser(0.05, t, true));

    assert.deepEqual([...new Set(evenements)], [null], 'elle dit sa réponse en entier, sans se couper');
});

test('l’utilisateur coupe Ket en parlant par-dessus son haut-parleur', () => {
    const d = creerDetecteur();
    let t = 0;
    for (; t < 3000; t += 50) d.pousser(0.001, t);
    // Calibration de la fuite, puis elle continue de parler.
    for (let i = 0; i < 40; i++, t += 50) d.pousser(0.05, t, true);

    // L'utilisateur parle par-dessus : sa voix couvre le haut-parleur.
    assert.equal(d.pousser(0.15, t, true), null);
    t += 200;
    assert.equal(d.pousser(0.15, t, true), 'debut', 'une voix qui couvre le haut-parleur coupe Ket');
});

test('la première demi-seconde de Ket est protégée, le temps d’écouter sa fuite', () => {
    const d = creerDetecteur();
    let t = 0;
    for (; t < 3000; t += 50) d.pousser(0.001, t);

    // Pendant la calibration, rien n'est interprété — pas même une voix forte.
    for (let i = 0; i < 8; i++, t += 50) {
        assert.equal(d.pousser(0.3, t, true), null, 'aucune décision pendant la calibration');
    }
});

/**
 * LE DÉFAUT QUI RENDAIT L'INTERRUPTION IMPOSSIBLE (signalé le 2026-09-19 : « elle
 * continue à parler pendant que moi aussi je parle »).
 *
 * La fuite du haut-parleur était apprise comme le BRUIT DE LA PIÈCE. Le fond montait
 * donc pendant toute la réponse et ne redescendait que lentement : une fois Ket
 * silencieuse, il fallait encore crier pour être entendu. Les deux mesures sont
 * désormais séparées — la pièce d'un côté, le haut-parleur de l'autre.
 */
test('la voix de Ket n’entre jamais dans la mesure du bruit de la pièce', () => {
    const d = creerDetecteur();
    let t = 0;
    for (; t < 3000; t += 50) d.pousser(0.001, t); // pièce calme
    const seuilDeLaPiece = d.seuil(false);

    for (let i = 0; i < 60; i++, t += 50) d.pousser(0.05, t, true); // Ket parle fort

    assert.equal(d.seuil(false), seuilDeLaPiece, 'la pièce est restée aussi calme qu’avant');

    // Elle se tait : une voix ordinaire est entendue TOUT DE SUITE, sans délai de
    // redescente.
    assert.equal(d.pousser(0.02, t), null);
    t += 200;
    assert.equal(d.pousser(0.02, t), 'debut', 'une voix ordinaire est entendue dès qu’elle se tait');
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

test('un tour complet avec les oreilles du serveur : transcrire, réfléchir, parler, réécouter', () => {
    let s = sessionInitiale('serveur');
    assert.equal(s.etat, ETATS.ARRET);

    s = transition(s, 'demarrer');
    assert.equal(s.etat, ETATS.ECOUTE);
    assert.deepEqual(s.actions, ['ouvrir-micro', 'precharger-intermedes', 'garder-ecran-allume']);

    s = transition(s, 'phrase-terminee');
    assert.deepEqual([s.etat, s.actions], [ETATS.TRANSCRIPTION, ['transcrire', 'programmer-intermedes']]);

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
    const s = transition({ ...sessionInitiale('serveur'), etat: ETATS.TRANSCRIPTION }, 'oreille-indisponible');
    assert.deepEqual(
        [s.etat, s.oreille, s.secours, s.actions],
        [ETATS.ECOUTE, 'navigateur', true, ['oreille-navigateur', 'couper-intermedes']],
    );
    assert.match(libelleEtatLive(s), /secours/);
});

test('par défaut, c’est le navigateur qui écoute — et il écoute dès le démarrage', () => {
    assert.equal(sessionInitiale().oreille, 'navigateur');
    const s = transition(sessionInitiale(), 'demarrer');
    assert.deepEqual(
        s.actions,
        ['ouvrir-micro', 'precharger-intermedes', 'garder-ecran-allume', 'oreille-navigateur'],
        'le micro reste ouvert : il sert à entendre l’interruption',
    );
});

test('le texte du navigateur mène droit à la réflexion, sans étape de transcription', () => {
    const ecoute = transition(sessionInitiale(), 'demarrer');
    const s = transition(ecoute, 'texte-entendu', { texte: 'Quelles garanties pour la RC Pro ?' });
    assert.equal(s.etat, ETATS.REFLEXION);
    assert.deepEqual(s.actions, ['envoyer-question', 'programmer-intermedes']);
    assert.equal(s.derniereParole, 'Quelles garanties pour la RC Pro ?');
});

test('un navigateur sans reconnaissance bascule sur les oreilles du serveur', () => {
    const s = transition(transition(sessionInitiale(), 'demarrer'), 'oreille-serveur');
    assert.deepEqual([s.etat, s.oreille], [ETATS.ECOUTE, 'serveur']);
    assert.equal(transition(sessionInitiale(), 'oreille-serveur').etat, ETATS.ARRET, 'hors session, rien ne bouge');
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
        assert.deepEqual([s.etat, s.actions], [ETATS.ARRET, ['fermer-micro', 'couper-voix', 'liberer-ecran']]);
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

// ── Trier les faux bruits ────────────────────────────────────────────────────

/** Une prise de parole telle que le détecteur la rapporte. */
const prise = (marge, dureeMs = 900, finMs = 1000, enCours = false) => ({ crete: 0.1, marge, dureeMs, finMs, enCours });

test('le détecteur rapporte la crête, la durée et la marge de ce qu’il a entendu', () => {
    const d = creerDetecteur();
    let t = 0;
    for (; t < 3000; t += 50) d.pousser(0.001, t);        // pièce calme
    assert.equal(d.dernierePriseDeParole(), null, 'rien n’a encore été dit');

    const seuil = d.seuil();
    for (let i = 0; i < 20; i++, t += 50) d.pousser(0.2, t); // une phrase, tout près
    const enCours = d.dernierePriseDeParole();
    assert.ok(enCours.enCours, 'la prise en cours est visible');
    assert.ok(Math.abs(enCours.marge - 0.2 / seuil) < 0.01, 'la marge compare la crête au seuil de parole');

    for (let i = 0; i < 20; i++, t += 50) d.pousser(0.0005, t); // on se tait
    const achevee = d.dernierePriseDeParole();
    assert.equal(achevee.enCours, false);
    assert.ok(achevee.dureeMs >= 900 && achevee.dureeMs <= 1100, `durée de la VOIX seule (${achevee.dureeMs} ms)`);
});

/**
 * LE FILTRE QUI COMPTE. Une voix à trente centimètres écrase le bruit de la pièce ; une
 * télévision à trois mètres le frôle. Les deux donnent du texte à la reconnaissance du
 * navigateur, qui ne dit jamais d'où il vient.
 */
test('une phrase dite de près passe, la même venue de loin est ignorée', () => {
    const proche = phraseRecevable({ texte: 'Quel est le taux de la Caution ?', priseDeParole: prise(MARGE_PROCHE + 2), instantMs: 1500 });
    const lointaine = phraseRecevable({ texte: 'Quel est le taux de la Caution ?', priseDeParole: prise(MARGE_PROCHE - 1), instantMs: 1500 });

    assert.deepEqual([proche.recevable, proche.motif], [true, 'recevable']);
    assert.deepEqual([lointaine.recevable, lointaine.motif], [false, 'loin']);
});

/**
 * SANS MICRO, KET N'EST PAS SOURDE (signalé le 2026-09-20 : « sur le smartphone Ket
 * n'écoute plus »).
 *
 * Sur un téléphone, le contexte audio naît SUSPENDU : aucune trame n'atteint le
 * détecteur, qui reste vierge. La règle de proximité rejetait alors TOUT en « muet » —
 * l'absence de preuve prise pour une preuve d'absence. Une Ket qui n'écoute plus est un
 * défaut bien pire qu'un bruit qui passe : quand le micro se tait, on fait confiance à
 * la reconnaissance, comme avant ce filtre.
 */
test('quand le micro ne donne rien, la phrase entendue passe quand même', () => {
    const sansMicro = phraseRecevable({
        texte: 'Quel est le taux de la Caution ?',
        priseDeParole: null,
        instantMs: 1500,
        microFiable: false,
    });

    assert.deepEqual([sansMicro.recevable, sansMicro.motif], [true, 'sans-micro']);
});

test('même sans micro, une hésitation seule ne part pas', () => {
    const tic = phraseRecevable({ texte: 'euh', priseDeParole: null, instantMs: 1500, microFiable: false });

    assert.equal(tic.motif, 'tic', 'le seul filtre qui ne dépend pas du micro reste actif');
});

test('un texte qu’aucune voix n’accompagne n’entre jamais dans la conversation', () => {
    const rien = phraseRecevable({ texte: 'la télévision parle', priseDeParole: null, instantMs: 1500 });
    const vieux = phraseRecevable({ texte: 'la télévision parle', priseDeParole: prise(9, 900, 1000), instantMs: 9000 });

    assert.deepEqual([rien.recevable, rien.motif], [false, 'muet']);
    assert.deepEqual([vieux.recevable, vieux.motif], [false, 'muet'], 'une salve d’il y a huit secondes n’est pas un alibi');
});

test('un bruit bref n’est pas une phrase', () => {
    const bref = phraseRecevable({ texte: 'ça', priseDeParole: prise(9, 120), instantMs: 1500 });
    assert.deepEqual([bref.recevable, bref.motif], [false, 'souffle']);
});

test('les hésitations ne partent pas, les réponses courtes si', () => {
    for (const tic of ['euh', 'Euh...', 'hum hum', 'ah', 'Ben… euh', 'voilà']) {
        assert.ok(nEstQueDesTics(tic), `« ${tic} » est un bruit de parole`);
    }
    for (const vrai of ['oui', 'non', 'arrête', 'continue', 'oui merci', 'ah oui je vois']) {
        assert.ok(!nEstQueDesTics(vrai), `« ${vrai} » est une vraie réponse`);
    }
    assert.equal(phraseRecevable({ texte: 'euh', priseDeParole: prise(9), instantMs: 1500 }).motif, 'tic');
    assert.equal(phraseRecevable({ texte: 'oui', priseDeParole: prise(9), instantMs: 1500 }).motif, 'recevable');
});

test('une confiance basse durcit l’exigence, une confiance absente ne condamne pas', () => {
    const marge = MARGE_PROCHE + 0.5;
    const sansAvis = phraseRecevable({ texte: 'Combien de clients ?', priseDeParole: prise(marge), instantMs: 1500 });
    const hesitante = phraseRecevable({ texte: 'Combien de clients ?', confiance: 0.3, priseDeParole: prise(marge), instantMs: 1500 });
    const sure = phraseRecevable({ texte: 'Combien de clients ?', confiance: 0.95, priseDeParole: prise(marge), instantMs: 1500 });

    assert.equal(sansAvis.recevable, true, 'sans confiance annoncée, la marge seule décide');
    assert.equal(sure.recevable, true);
    assert.equal(hesitante.recevable, false, 'mal reconnu ET à la limite : on se tait');
});

// ── La voix de Ket ne s'attribue pas vos phrases ─────────────────────────────

/**
 * L'INCIDENT (2026-09-19). Dans la bulle de l'utilisateur : « aucun avenant ne
 * répertorié avec une date VA VOIR AUSSI DANS LES PROCHAINS 90 JOURS ». Le début est de
 * Ket, la fin est bien de l'utilisateur — la reconnaissance du navigateur, qui entend
 * aussi le haut-parleur, avait fondu les deux voix en une seule phrase. Le juge de
 * provenance ne pouvait rien : quelqu'un parlait vraiment tout près du micro.
 */
test('l’écho de Ket est retranché, la phrase de l’utilisateur reste', () => {
    const ket = ['Aucun avenant n’est répertorié avec une date d’expiration future se situant dans les 31 à 60 prochains jours.'];
    const entendu = 'aucun avenant ne répertorié avec une date va voir aussi dans les prochains 90 jours';

    assert.equal(retirerLaVoixDeKet(entendu, ket), 'voir aussi dans les prochains 90 jours');
});

test('un intermède entendu en entier ne laisse rien', () => {
    assert.equal(retirerLaVoixDeKet('Hum laissez-moi vérifier', ['Hum… laissez-moi vérifier.']), '');
    // Vidé, il est écarté par le juge — sans jamais atteindre le fil.
    assert.equal(phraseRecevable({ texte: '', priseDeParole: prise(9), instantMs: 1500 }).motif, 'vide');
});

test('une question qui ne doit rien à Ket traverse intacte', () => {
    const ket = ['Aucun avenant n’est répertorié avec une date d’expiration future.'];

    assert.equal(retirerLaVoixDeKet('quel est le taux de la Caution ?', ket), 'quel est le taux de la Caution ?');
    assert.equal(retirerLaVoixDeKet('donne-moi le top 5 des clients', ket), 'donne-moi le top 5 des clients');
});

/** Reprendre les mots de Ket est permis : seule leur RÉPÉTITION de tête s'efface. */
test('l’utilisateur peut reprendre les mots de Ket et garder sa suite', () => {
    const ket = ['Vous avez trente polices échues à anticiper.'];

    assert.equal(
        retirerLaVoixDeKet('vous avez trente polices échues oui je veux les voir', ket),
        'oui je veux les voir',
    );
});

test('trois mots communs ne suffisent pas à retrancher quoi que ce soit', () => {
    const ket = ['Est-ce que vous voulez que je prépare le renouvellement ?'];

    assert.equal(
        retirerLaVoixDeKet('est-ce que vous parlez du client Kibali ?', ket),
        'est-ce que vous parlez du client Kibali ?',
    );
});

// ── Rien de ce que dit l'utilisateur ne se perd ──────────────────────────────

/**
 * « ELLE NE DOIT RIEN JETER NI IGNORER QUI VIENNE DE MOI » (2026-09-19).
 *
 * Une question posée pendant que Ket cherchait était perdue : la machine n'acceptait un
 * texte qu'en écoute ou en transcription. Or on ne parle pas en attendant son tour — on
 * enchaîne, on précise, on corrige. Les questions s'empilent donc, et c'est le serveur
 * qui les traite dans l'ordre : un fil rend « Q1 A1 Q2 A2 ».
 */
test('une question posée pendant qu’elle réfléchit n’est pas perdue', () => {
    const reflexion = transition(
        transition(transition(sessionInitiale(), 'demarrer'), 'texte-entendu', { texte: 'Le top 5 des clients ?' }),
        'texte-entendu',
        { texte: 'Et leur réserve ?' },
    );

    assert.equal(reflexion.etat, ETATS.REFLEXION);
    assert.ok(reflexion.actions.includes('envoyer-question'), 'la seconde question part aussi');
    assert.equal(reflexion.derniereParole, 'Et leur réserve ?');
});

test('parler pendant qu’elle parle la coupe ET pose la question', () => {
    const parole = { ...sessionInitiale(), etat: ETATS.PAROLE };
    const s = transition(parole, 'texte-entendu', { texte: 'Non, je voulais les assureurs.' });

    assert.equal(s.etat, ETATS.REFLEXION);
    assert.deepEqual(s.actions, ['couper-voix', 'envoyer-question', 'programmer-intermedes']);
});

test('deux réponses arrivées coup sur coup sont dites l’une après l’autre', () => {
    let s = transition({ ...sessionInitiale(), etat: ETATS.REFLEXION }, 'reponse-affichee');
    assert.deepEqual([s.etat, s.actions], [ETATS.PAROLE, ['couper-intermedes', 'lire-reponse']]);

    // La seconde arrive pendant qu'elle dit la première : elle attend, elle ne se perd pas.
    s = transition(s, 'reponse-affichee');
    assert.deepEqual([s.etat, s.actions], [ETATS.PAROLE, ['empiler-reponse']]);

    s = transition(s, 'lecture-terminee');
    assert.deepEqual([s.etat, s.actions], [ETATS.ECOUTE, ['lire-la-suivante']]);

    s = transition(s, 'reponse-suivante');
    assert.deepEqual([s.etat, s.actions], [ETATS.PAROLE, ['couper-intermedes', 'lire-reponse']]);
});

// ── Micro à la demande ───────────────────────────────────────────────────────

test('trop de bruit : Ket n’écoute plus qu’à la demande, et le dit', () => {
    const ecoute = transition(sessionInitiale(), 'demarrer');
    assert.equal(ecoute.ecoute, 'continue');

    const surDemande = transition(ecoute, 'ecoute-a-la-demande');
    assert.deepEqual([surDemande.ecoute, surDemande.micDemande], ['demande', false]);
    assert.match(libelleEtatLive(surDemande), /appuyez/i, 'l’état écrit dit quoi faire');

    const enParole = transition(surDemande, 'parler');
    assert.equal(enParole.micDemande, true);
    assert.match(libelleEtatLive(enParole), /Parlez/i);

    // Une phrase entendue referme le micro : un appui vaut UNE phrase.
    const apres = transition(enParole, 'texte-entendu', { texte: 'Combien de clients ?' });
    assert.deepEqual([apres.etat, apres.micDemande], [ETATS.REFLEXION, false]);

    const revenue = transition(apres, 'ecoute-continue');
    assert.equal(revenue.ecoute, 'continue');
});

test('« parler » ne veut rien dire hors du mode à la demande', () => {
    const ecoute = transition(sessionInitiale(), 'demarrer');
    assert.equal(transition(ecoute, 'parler').micDemande, false);
    assert.equal(transition(sessionInitiale(), 'ecoute-a-la-demande').etat, ETATS.ARRET, 'hors session, rien ne bouge');
});

// ── Chrono d'un tour ─────────────────────────────────────────────────────────

test('le silence qui clôt une phrase n’ajoute pas une seconde d’attente', () => {
    assert.ok(FIN_MS <= 700, 'au-delà, le blanc s’entend dans la conversation');
});

test('le chrono répartit l’attente entre les étapes, sans en perdre une milliseconde', () => {
    let tour = nouveauTour(1000);
    tour = jalon(tour, 'transcription', 1200);
    tour = jalon(tour, 'reflexion', 9600);

    assert.deepEqual(tour.etapes, { transcription: 200, reflexion: 8400 });
    assert.equal(totalDuTour(tour), 8600, 'la somme des étapes fait le tour');
    assert.match(resumeDuTour(tour), /^8,6 s|^8\.6 s/);
    assert.match(resumeDuTour(tour), /reflexion 8\.4|reflexion 8,4/);
});

test('une même étape revue s’ajoute, et un tour vide reste lisible', () => {
    const tour = jalon(jalon(nouveauTour(0), 'parole', 500), 'parole', 800);
    assert.deepEqual(tour.etapes, { parole: 800 });
    assert.equal(resumeDuTour(nouveauTour(0)), '0.0 s');
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
