/**
 * Tests du SOCLE DE SÉLECTION DE FICHIERS (assets/controllers/attach-selection.js).
 *
 * Ce socle a été extrait de `documents-attach-picker_controller.js` pour que la carte
 * « Pièce justificative » du picker de reversement dépose ses fichiers exactement comme
 * la boîte « Attacher des pièces » d'une fiche. Deux copies du même choix auraient fini
 * par refuser deux choses différentes — et c'est le fichier écarté d'un seul côté qui
 * manquerait au dossier.
 *
 * ── CE QUI N'EST PAS COUVERT ICI, ET POURQUOI ───────────────────────────────────────
 * Le RENDU de la liste (lignes, icônes, motifs affichés) demande un DOM. Cette suite
 * n'en a aucun, et le projet n'embarque pas jsdom : l'y ajouter pour vérifier des
 * `appendChild` mettrait une dépendance de développement au bilan pour un gain mince.
 * On vérifie donc ce que le socle DÉCIDE — ce qu'il retient, ce qu'il écarte, et la
 * forme du lot qu'il remet à l'appelant — le rendu étant vérifié à l'écran (le test
 * PHP de rendu du picker garantit, lui, que la zone de dépôt HABITUELLE est bien celle
 * qui est posée dans le gabarit).
 *
 * Lancement : node --test tests/js/
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

import { SelectionDeFichiers } from '../../assets/controllers/attach-selection.js';

const LIMITES = { maxSize: 10 * 1024 * 1024, extensions: ['pdf', 'png'] };

/**
 * Une racine SANS DOM : chaque `querySelector` rend null, ce que le socle traverse
 * sans broncher (zone, champ et liste sont tous facultatifs). C'est exactement la
 * situation d'un hôte qui n'aurait pas encore rendu sa liste — le choix des fichiers
 * ne doit pas en dépendre.
 */
function racineNue() {
    return { querySelector: () => null, contains: () => false };
}

function fichier(name, size = 1024) {
    return { name, size };
}

function selection(onChange = null) {
    return new SelectionDeFichiers({ racine: racineNue(), limites: LIMITES, familles: {}, onChange });
}

test('une sélection neuve est vide, et le dit', () => {
    const s = selection();

    assert.equal(s.estVide(), true);
    assert.deepEqual(s.lot(), []);
});

test('les fichiers acceptés entrent dans le lot, dans l’ordre de dépôt', () => {
    const s = selection();
    s.ajouter([fichier('bordereau.pdf'), fichier('recu.png')]);

    assert.equal(s.estVide(), false);
    assert.deepEqual(s.lot().map((f) => f.name), ['bordereau.pdf', 'recu.png']);
});

test('un fichier hors limites est écarté, et son motif est remonté à l’hôte', () => {
    let dernierRefus = null;
    const s = selection((_n, refuses) => { dernierRefus = refuses; });

    s.ajouter([fichier('virus.exe'), fichier('bordereau.pdf')]);

    assert.deepEqual(s.lot().map((f) => f.name), ['bordereau.pdf'], 'Le valide du même lot passe quand même.');
    assert.equal(dernierRefus.length, 1);
    assert.equal(dernierRefus[0].nom, 'virus.exe');
    assert.ok(dernierRefus[0].motif, 'Un fichier écarté doit être NOMMÉ avec son motif.');
});

test('le même fichier déposé deux fois n’est retenu qu’une', () => {
    const s = selection();
    s.ajouter([fichier('bordereau.pdf')]);
    s.ajouter([fichier('bordereau.pdf')]);

    assert.deepEqual(s.lot().map((f) => f.name), ['bordereau.pdf']);
});

test('l’hôte est prévenu du nombre à chaque changement — c’est ce qui arme son bouton', () => {
    const vus = [];
    const s = selection((n) => vus.push(n));

    s.ajouter([fichier('a.pdf')]);
    s.ajouter([fichier('b.png')]);

    // Le premier appel vient du constructeur : un hôte doit pouvoir désactiver son
    // bouton dès l'ouverture, sans attendre un premier dépôt.
    assert.deepEqual(vus, [0, 1, 2]);
});

test('le lot part sous le nom de champ que la route générique attend', () => {
    // Ici il faut de VRAIS Blob : FormData refuse tout le reste, et c'est bien ce
    // contrat-là qu'on vérifie — le nom de champ attendu par la route générique.
    const reel = (nom) => {
        const blob = new Blob(['x']);
        Object.defineProperty(blob, 'name', { value: nom });

        return blob;
    };
    const s = selection();
    s.ajouter([reel('bordereau.pdf'), reel('recu.png')]);

    const formData = s.versFormData();
    const noms = formData.getAll('fichiers[]').map((f) => f.name ?? f);

    assert.equal(noms.length, 2, 'Les deux fichiers doivent partir dans le même envoi.');
});

test('retirer une ligne enlève le bon fichier', () => {
    const s = selection();
    s.ajouter([fichier('a.pdf'), fichier('b.png'), fichier('c.pdf')]);

    const racine = s.racine;
    racine.contains = () => true;
    const consomme = s.onClick({
        target: { closest: () => ({ dataset: { attachRetirer: '1' } }) },
    });

    assert.equal(consomme, true, 'Le clic doit être consommé, pour que l’hôte n’ait pas à le connaître.');
    assert.deepEqual(s.lot().map((f) => f.name), ['a.pdf', 'c.pdf']);
});
