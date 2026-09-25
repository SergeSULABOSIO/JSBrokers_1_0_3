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
    explicationEtape,
    coulissesEtape,
    dureeEtape,
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

/*
 * LES COULISSES DE KET — ce que la partie DÉPLIÉE du bandeau a le droit de montrer.
 *
 * La ligne repliée reste en langage d'usager ; en dépliant, on vient chercher la
 * cuisine interne : qui a répondu, avec quel modèle, quels outils, et où sont partis
 * les jetons. Ces tests verrouillent la frontière entre les deux registres.
 */

test('chaque verbe d’usager a son explication de coulisse', () => {
    for (const cle of Object.keys(VERBES)) {
        assert.notEqual(
            explicationEtape(cle),
            '',
            `L’étape « ${cle} » s’affiche sans expliquer ce qu’elle fait.`,
        );
    }
});

test('une clé inconnue n’explique rien plutôt que d’inventer', () => {
    assert.equal(explicationEtape('phase-d-un-serveur-plus-recent'), '');
});

test('le modèle affiché est nommé avec son moteur', () => {
    const fragments = coulissesEtape({ moteur: 'gemini', modele: 'gemini-3.5-flash-lite' });

    assert.ok(
        fragments.includes('gemini · gemini-3.5-flash-lite'),
        'Le moteur seul ne dit pas QUEL modèle a répondu : les deux vont ensemble.',
    );
});

test('la ventilation des jetons distingue ce qu’on envoie de ce que le modèle écrit', () => {
    // Les espaces de groupement varient selon la locale (fine insécable, insécable) :
    // on normalise avant de comparer plutôt que de parier sur un caractère précis.
    const fragments = coulissesEtape({ entree: 35000, sortie: 700, cache: 26000 })
        .join(' | ')
        .replace(/\s/gu, ' ');

    assert.ok(fragments.includes('35 000 envoyés'), fragments);
    assert.ok(fragments.includes('700 écrits'), fragments);
    assert.ok(fragments.includes('26 000 relus en cache'), fragments);
});

test('les outils appelés sont nommés', () => {
    const fragments = coulissesEtape({ outils: ['vigie_echeances', 'suivi_impayes'] });

    assert.ok(fragments.some((f) => f === 'outils : vigie_echeances, suivi_impayes'));
});

test('une étape sans coulisse ne produit aucune ligne vide', () => {
    assert.deepEqual(coulissesEtape({ cle: 'outils', jetons: 0 }), []);
    assert.deepEqual(coulissesEtape(null), []);
});

test('sous la seconde, la durée se dit en millisecondes', () => {
    // « 0,0 s » se lirait comme une mesure ratée alors que l’étape a bien duré.
    assert.equal(dureeEtape(240), '240 ms');
    assert.equal(dureeEtape(4000), '4,0 s');
    assert.equal(dureeEtape(0), '');
});

test('le temps passé chez le modèle est dit à part de la durée de l’étape', () => {
    // Huit secondes d'étape dont sept chez le fournisseur ne se lisent pas comme
    // huit secondes dont une : c'est la différence entre « changer de modèle » et
    // « alléger ce qu'on lui envoie ».
    const fragments = coulissesEtape({ msModele: 7400 });

    assert.ok(fragments.some((f) => f === '7,4 s chez le modèle'), fragments.join(' | '));
});

test('sans mesure de temps, aucune ligne n’est inventée', () => {
    assert.deepEqual(coulissesEtape({ msModele: 0 }), []);
});

test('coulissesEtape nomme le modèle abandonné quand un repli a eu lieu', () => {
    const fragments = coulissesEtape({
        moteur: 'gemini',
        modele: 'gemini-flash-lite-latest',
        modeles: ['gemini-3.1-flash-lite', 'gemini-flash-lite-latest'],
        tours: 2,
    });

    assert.equal(fragments[0], 'gemini · gemini-flash-lite-latest');
    assert.equal(
        fragments[1],
        'après repli de gemini-3.1-flash-lite',
        "Le modèle qui a lâché doit rester lisible : c'est lui qui explique le repli."
    );
});

test('coulissesEtape reste muette sur le repli quand il n’y en a pas eu', () => {
    const fragments = coulissesEtape({
        moteur: 'gemini',
        modele: 'gemini-3.1-flash-lite',
        modeles: ['gemini-3.1-flash-lite'],
    });

    assert.ok(
        !fragments.some((f) => f.includes('repli')),
        "Le cas ordinaire ne doit porter aucune mention de repli."
    );
});

test('coulissesEtape survit à un serveur qui ne connaît pas encore la chaîne', () => {
    const fragments = coulissesEtape({ moteur: 'gemini', modele: 'gemini-3.1-flash-lite' });

    assert.equal(fragments[0], 'gemini · gemini-3.1-flash-lite');
    assert.ok(!fragments.some((f) => f.includes('repli')));
});

test('coulissesEtape dit ce qui a remplacé le modèle sur une compréhension locale', () => {
    assert.deepEqual(
        coulissesEtape({ origine: 'repli' }),
        ['le modèle n’a pas répondu — compréhension locale']
    );
    assert.deepEqual(
        coulissesEtape({ origine: 'court-circuit' }),
        ['comprise sans appeler le modèle']
    );
});

test('coulissesEtape ne commente pas une compréhension faite par le modèle', () => {
    const fragments = coulissesEtape({ origine: 'modele', modele: 'gemini-3.1-flash-lite', entree: 11101 });

    assert.equal(fragments[0], 'gemini-3.1-flash-lite');
    assert.ok(
        !fragments.some((f) => f.includes('locale') || f.includes('sans appeler')),
        "Le cas ordinaire n'a rien à expliquer."
    );
});
