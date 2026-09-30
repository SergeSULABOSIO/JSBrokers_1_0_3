/**
 * LE NAVIGATEUR NE DEVINE PLUS LE LIEN VERS LE PARENT — il le reçoit.
 *
 * ── CE QUE CE CONTRAT PROTÈGE ───────────────────────────────────────────────────────
 * Un onglet contextuel a deux chemins de lecture. Le premier affichage passe par le getter
 * Doctrine du parent : il est juste. Tout le reste — page 2, recherche, « Réinitialiser »,
 * rafraîchissement après un enregistrement — repart vers la rubrique ENTIÈRE de l'enfant,
 * et n'est borné que par le nom du champ envoyé dans `parentContext`.
 *
 * Ce nom était cherché dans le canevas de FORMULAIRE du parent, alors que les onglets
 * naissent du canevas d'ENTITÉ. Deux listes indépendantes : pour quatre des six onglets
 * d'un client — pistes, sinistres, notes, partenaires — la fouille rendait `null`, et la
 * liste affichait tout le cabinet sous une pastille au nom du client.
 *
 * Quatre façons de rouvrir la brèche, chacune fermée ici :
 *
 *   1. remettre une fouille de canevas côté navigateur — la divergence renaîtrait ;
 *   2. oublier de porter le lien dans l'état de l'onglet — il n'y aurait rien à renvoyer ;
 *   3. renvoyer le lien sans son code de collection — le serveur ne saurait plus distinguer
 *      « pas de parent » de « parent non résolu », et repasserait en échec OUVERT ;
 *   4. prérenseigner un formulaire depuis un ManyToMany — `set<Champ>()` n'existe pas.
 *
 * Lancement : node --test tests/js/
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const RACINE = join(dirname(fileURLToPath(import.meta.url)), '..', '..', 'assets', 'controllers');
const cerveau = readFileSync(join(RACINE, 'cerveau_controller.js'), 'utf8');
const listManager = readFileSync(join(RACINE, 'list-manager_controller.js'), 'utf8');

test('la fouille du canevas de formulaire a disparu', () => {
    assert.ok(
        !/_findParentFieldName/.test(cerveau),
        'C\'était la cause : elle cherchait le lien dans le canevas de FORMULAIRE du parent, '
        + 'alors que les onglets naissent du canevas d\'ENTITÉ. La rétablir, même en repli, '
        + 'remettrait deux déclarations en présence.',
    );
});

test('l\'onglet porte le lien que le serveur a résolu', () => {
    assert.match(listManager, /parentLien: Object/,
        'Le lien arrive par une valeur Stimulus, comme `serverRootName`.');
    assert.match(
        listManager,
        /parentLien: \(this\.parentLienValue && this\.parentLienValue\.collection\)/,
        'Il entre dans l\'état de l\'onglet, d\'où le cerveau le relit à chaque recherche. '
        + 'Sans cela, il n\'y aurait rien à renvoyer au serveur.',
    );
});

test('la recherche renvoie le lien, AVEC son code de collection', () => {
    const debut = cerveau.indexOf('_getParentContextForSearch() {');
    const fin = cerveau.indexOf('_getParentContextForCreation() {', debut);
    assert.ok(debut >= 0 && fin > debut, 'Les bornes de la méthode doivent être trouvées.');
    const methode = cerveau.slice(debut, fin);

    assert.match(methode, /parentLien/,
        'Le lien vient de l\'état de l\'onglet, plus d\'une fouille.');
    assert.match(methode, /collection:/,
        '⚠ SANS LE CODE DE COLLECTION, le serveur ne peut plus distinguer « cet onglet n\'a '
        + 'pas de parent » de « il en a un, mais je ne sais pas lequel ». Le second cas doit '
        + 'vider la liste ; les confondre ramènerait l\'échec OUVERT, qui montrait tout.');
    assert.match(methode, /champ:/);
    assert.match(methode, /nature:/);
});

test('une création ne se rattache qu\'à une relation SIMPLE', () => {
    const debut = cerveau.indexOf('_getParentContextForCreation() {');
    // La borne est large À DESSEIN : trop courte, elle coupait le `return` final et
    // l'assertion échouait sur du texte absent de la tranche, pas du fichier.
    const methode = cerveau.slice(debut, debut + 1400);

    assert.match(
        methode,
        /contexte\.nature !== 'to_one'/,
        'Le préremplissage appelle `set<Champ>()` sur l\'entité : un ManyToMany — les '
        + 'partenaires associés d\'un client — n\'a pas de setter de ce nom. On le filtre, '
        + 'on ne le prérenseigne pas.',
    );
    assert.match(methode, /fieldName: contexte\.champ/,
        'Le dialogue attend `fieldName` : c\'est lui qui devient `?parent_field_name=`.');
});
