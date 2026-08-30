/**
 * Tests de L'UNICITÉ D'UNE RUBRIQUE DANS LA BARRE D'ONGLETS
 * (assets/controllers/onglets-uniques.js) — logique pure, ni DOM ni localStorage.
 *
 * Ce qui est protégé ici : qu'une rubrique n'existe qu'une fois. Chaque clic de menu,
 * chaque bouton « Voir la production », chaque `ouvrir_rubrique` de Ket créait un onglet
 * NEUF — la barre finissait encombrée d'instances mortes de la même rubrique, et chaque
 * ouverture repayait le chargement complet du composant.
 *
 * Et les deux subtilités qui coûtent cher si on les rate :
 *
 *   — un onglet SANS composant (aperçu de note, SOA, rapport rétro) n'est pas une
 *     rubrique. Lui donner une clé les rendrait tous identiques ENTRE EUX, et ouvrir
 *     l'aperçu d'une seconde note remplacerait la première ;
 *   — l'onglet ACTIF doit survivre au nettoyage. S'il faisait partie des doublons
 *     écartés, il est remplacé par le survivant de sa rubrique — sinon l'utilisateur
 *     perd, au premier F5, l'écran qu'il regardait.
 *
 * Lancement : node --test tests/js/
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
    cleDeRubrique,
    dedoublonnerOnglets,
    ongletExistantPourRubrique,
} from '../../assets/controllers/onglets-uniques.js';

const AVENANTS = { componentName: '_view_manager_production.html.twig', entityName: 'Avenant' };
const PISTES = { componentName: '_view_manager_production.html.twig', entityName: 'Piste' };
const TABLEAU_DE_BORD = { componentName: '_tableau_de_bord_component.html.twig', entityName: '' };

// ---------------------------------------------------------------------------
// La clé d'identité
// ---------------------------------------------------------------------------

test('deux rubriques du même composant restent distinctes par leur entité', () => {
    // Toutes les rubriques d'un groupe partagent le même composant Twig : sans l'entité,
    // ouvrir « Pistes » réactiverait l'onglet « Avenants ».
    assert.notEqual(cleDeRubrique(AVENANTS), cleDeRubrique(PISTES));
});

test('le tableau de bord s’identifie par son seul composant', () => {
    // C'est la seule entrée de menu.yaml sans entity_name.
    assert.equal(typeof cleDeRubrique(TABLEAU_DE_BORD), 'string');
    assert.equal(cleDeRubrique(TABLEAU_DE_BORD), cleDeRubrique({ componentName: TABLEAU_DE_BORD.componentName }));
});

test('un onglet sans composant n’a AUCUNE clé de rubrique', () => {
    // Aperçu de note, SOA client, rapport rétro : ils se dédoublonnent par leur `tabKey`,
    // jamais par cette clé-ci. Une clé vide les confondrait tous.
    assert.equal(cleDeRubrique({ tabKey: 'note-preview-12', title: 'Note 12' }), null);
    assert.equal(cleDeRubrique({ entityName: 'Avenant' }), null);
    assert.equal(cleDeRubrique(null), null);
    assert.equal(cleDeRubrique('Avenant'), null);
});

// ---------------------------------------------------------------------------
// Retrouver l'onglet déjà ouvert
// ---------------------------------------------------------------------------

test('la rubrique déjà ouverte est retrouvée, et c’est la sienne', () => {
    const onglets = [
        { id: 'a', ...PISTES },
        { id: 'b', ...AVENANTS },
    ];

    assert.equal(ongletExistantPourRubrique(onglets, AVENANTS).id, 'b');
});

test('une rubrique jamais ouverte ne retrouve rien', () => {
    assert.equal(ongletExistantPourRubrique([{ id: 'a', ...PISTES }], AVENANTS), null);
});

test('un onglet sans clé n’est jamais rendu comme rubrique existante', () => {
    // Sinon, demander « Avenants » réutiliserait l'aperçu d'une note et en écraserait
    // le contenu — la réponse la plus déroutante qui soit.
    const onglets = [{ id: 'a', tabKey: 'note-preview-12' }];

    assert.equal(ongletExistantPourRubrique(onglets, { componentName: '', entityName: '' }), null);
    assert.equal(ongletExistantPourRubrique(onglets, AVENANTS), null);
});

// ---------------------------------------------------------------------------
// Le nettoyage d'un stockage hérité
// ---------------------------------------------------------------------------

test('les doublons hérités sont écartés, et c’est le DERNIER qui reste', () => {
    // Le plus récemment ouvert porte l'état — filtre et sélection — que l'utilisateur a
    // le plus de chances de reconnaître.
    const { onglets } = dedoublonnerOnglets([
        { id: 'a', ...AVENANTS, criteres: { agent: 'vieux' } },
        { id: 'b', ...PISTES },
        { id: 'c', ...AVENANTS, criteres: { agent: 'recent' } },
    ], null);

    assert.deepEqual(onglets.map((o) => o.id), ['b', 'c']);
    assert.deepEqual(onglets[1].criteres, { agent: 'recent' });
});

test('l’ordre relatif des survivants est préservé', () => {
    // La barre se raccourcit ; elle ne se réorganise pas sous les yeux de l'utilisateur.
    const { onglets } = dedoublonnerOnglets([
        { id: 'a', ...PISTES },
        { id: 'b', ...TABLEAU_DE_BORD },
        { id: 'c', ...AVENANTS },
        { id: 'd', ...PISTES },
    ], null);

    assert.deepEqual(onglets.map((o) => o.id), ['b', 'c', 'd']);
});

test('l’onglet actif écarté est remplacé par le survivant de SA rubrique', () => {
    const { idActif } = dedoublonnerOnglets([
        { id: 'a', ...AVENANTS },
        { id: 'b', ...PISTES },
        { id: 'c', ...AVENANTS },
    ], 'a');

    assert.equal(idActif, 'c', 'L’utilisateur doit retrouver la rubrique qu’il regardait.');
});

test('un onglet actif qui survit n’est pas déplacé', () => {
    const { idActif } = dedoublonnerOnglets([
        { id: 'a', ...AVENANTS },
        { id: 'b', ...PISTES },
    ], 'b');

    assert.equal(idActif, 'b');
});

test('un actif inconnu ne ressuscite pas un onglet au hasard', () => {
    const { idActif } = dedoublonnerOnglets([{ id: 'a', ...AVENANTS }], 'fantome');

    assert.equal(idActif, null);
});

test('les onglets sans clé traversent TOUS, sans jamais se confondre', () => {
    const { onglets } = dedoublonnerOnglets([
        { id: 'a', tabKey: 'note-preview-12' },
        { id: 'b', tabKey: 'note-preview-13' },
        { id: 'c', tabKey: 'soa-client-7' },
    ], null);

    assert.deepEqual(onglets.map((o) => o.id), ['a', 'b', 'c']);
});

test('une entrée sans identifiant est écartée, sans faire tomber le reste', () => {
    // Le stockage est écrit par des versions successives de l'application : il faut le
    // lire avec méfiance, pas le croire.
    const { onglets } = dedoublonnerOnglets([null, { ...AVENANTS }, { id: 'b', ...PISTES }], null);

    assert.deepEqual(onglets.map((o) => o.id), ['b']);
});

test('un stockage illisible ne fait pas tomber la restauration', () => {
    assert.deepEqual(dedoublonnerOnglets(undefined, 'a'), { onglets: [], idActif: 'a' });
});
