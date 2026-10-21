/**
 * SUIVI DU CHANTIER 2 (décisions de Serge, 2026-10-21).
 *   1. Un seul gestionnaire de dialogues : celui du bordereau ne s'installe pas si un autre
 *      existe déjà (deux dialogues identiques = risque de double enregistrement).
 *   2. Une saisie SANS GESTE dans la page (gestionnaire de mots de passe, dictée, glisser-déposer
 *      depuis une autre application, extension) n'a pas d'empreinte : un `input`/`change` dans
 *      le dialogue suffit alors à poser « Abandonner ? » — hors événements émis par NOTRE code.
 *   3. La fermeture automatique d'un congé (2 s après l'enregistrement) est annulée si une
 *      saisie a repris ou si le formulaire a changé.
 * Lancement : node --test tests/js/
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

import { evenementDeSaisie, fermetureADemander, fermetureAutomatiqueAutorisee } from '../../assets/controllers/echap-dialogue.js';
import { reserverLInstallation, libererLInstallation } from '../../assets/controllers/gestionnaire-dialogues.js';
import { evenementInterne, estInterne } from '../../assets/controllers/evenement-interne.js';

const RACINE = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const source = (chemin) => readFileSync(join(RACINE, chemin), 'utf8');

// ── 1. Un seul gestionnaire ─────────────────────────────────────────────────────

test('le gestionnaire du bordereau ne s’installe pas si un autre existe déjà', () => {
    const corps = { isConnected: true };
    const bordereau = { isConnected: true };
    assert.equal(reserverLInstallation(corps), true);
    assert.equal(reserverLInstallation(bordereau), false);
    // Le second, resté inerte, ne libère rien en partant.
    libererLInstallation(bordereau);
    assert.equal(reserverLInstallation({ isConnected: true }), false);
    libererLInstallation(corps);
});

test('page autonome du bordereau (aucun autre gestionnaire) : il s’installe', () => {
    const seul = { isConnected: true };
    assert.equal(reserverLInstallation(seul), true);
    libererLInstallation(seul);
    // Un gestionnaire retiré du DOM sans déconnexion propre ne bloque pas le suivant.
    const fantome = { isConnected: true };
    assert.equal(reserverLInstallation(fantome), true);
    fantome.isConnected = false;
    const suivant = { isConnected: true };
    assert.equal(reserverLInstallation(suivant), true);
    libererLInstallation(suivant);
});

test('dialog-manager passe par la réservation, et reste inerte s’il n’a pas la main', () => {
    const code = source('assets/controllers/dialog-manager_controller.js');
    assert.match(code, /reserverLInstallation\(this\.element\)/);
    assert.match(code, /this\.inerte = true;/);
});

// ── 2. Saisie sans geste ────────────────────────────────────────────────────────

test('un input SIMULÉ (sans keydown ni pointerdown), puis fermeture : la question est posée', () => {
    // Valeur posée par du code étranger (extension, gestionnaire de mots de passe), input émis.
    const evenement = { type: 'input', isTrusted: false, interne: false, surSelectTomSelect: false };
    assert.equal(evenementDeSaisie(evenement), true);
    assert.equal(fermetureADemander({ empreinteInitiale: null, empreinteActuelle: '[["nom","X"]]', saisieSansGeste: true }), true);
});

test('les événements émis par NOTRE code ne comptent pas (formulaire intact = aucune question)', () => {
    assert.equal(evenementDeSaisie({ type: 'change', isTrusted: false, interne: true }), false);
    // La recopie interne de Tom Select vers son <select> caché.
    assert.equal(evenementDeSaisie({ type: 'change', isTrusted: false, surSelectTomSelect: true }), false);
    assert.equal(evenementDeSaisie({ type: 'focus', isTrusted: true }), false);
    // Dictée, glisser-déposer, gestionnaire de mots de passe : événements de confiance.
    assert.equal(evenementDeSaisie({ type: 'input', isTrusted: true }), true);
    assert.equal(fermetureADemander({ empreinteInitiale: null, saisieSansGeste: false }), false);
});

test('avec une empreinte, la comparaison seule décide (traverser au Tab ne demande rien)', () => {
    assert.equal(fermetureADemander({ empreinteInitiale: 'A', empreinteActuelle: 'A', saisieSansGeste: true }), false);
    assert.equal(fermetureADemander({ empreinteInitiale: 'A', empreinteActuelle: 'B', saisieSansGeste: false }), true);
});

test('un événement interne se marque à l’émission, et se reconnaît', () => {
    const e = evenementInterne('change');
    assert.equal(e.type, 'change');
    assert.equal(e.bubbles, true);
    assert.equal(estInterne(e), true);
    assert.equal(estInterne(new Event('change')), false);
});

test('le code du projet émet ses input/change de dialogue par evenementInterne', () => {
    for (const fichier of ['dialog-instance_controller.js', 'rich-text_controller.js', 'conge-periode_controller.js']) {
        assert.doesNotMatch(source(`assets/controllers/${fichier}`), /dispatchEvent\(new Event\('(input|change)'/, fichier);
    }
});

// ── 3. Fermeture automatique du congé ──────────────────────────────────────────

test('la fermeture automatique est annulée si une saisie a repris ou si le formulaire a changé', () => {
    assert.equal(fermetureAutomatiqueAutorisee({ gesteDepuisEnregistrement: false, saisieSansGeste: false, modifie: false }), true);
    assert.equal(fermetureAutomatiqueAutorisee({ gesteDepuisEnregistrement: true, saisieSansGeste: false, modifie: false }), false);
    assert.equal(fermetureAutomatiqueAutorisee({ gesteDepuisEnregistrement: false, saisieSansGeste: true, modifie: false }), false);
    assert.equal(fermetureAutomatiqueAutorisee({ gesteDepuisEnregistrement: false, saisieSansGeste: false, modifie: true }), false);
});

test('le cerveau demande une fermeture « si inactif », et le dialogue la refuse s’il y a saisie', () => {
    const cerveau = source('assets/controllers/cerveau_controller.js');
    assert.match(cerveau, /'app:dialog\.do-close', \{ dialogId, siInactif: true, siFerme:/);
    // La décision du congé ne se rouvre QUE si le dialogue s'est fermé.
    assert.doesNotMatch(cerveau, /'app:dialog\.do-close', \{ dialogId \}\);\s*this\._reouvrirLaDecisionSiAttendue\(\);/);
    const instance = source('assets/controllers/dialog-instance_controller.js');
    assert.match(instance, /event\.detail\.siInactif && !fermetureAutomatiqueAutorisee\(/);
});

// ── Zones d'annonce du pied du dialogue ─────────────────────────────────────────

test('le pied du dialogue porte DEUX zones dès l’ouverture : status et alert (pas d’aria-live changeant)', () => {
    const manager = source('assets/controllers/dialog-manager_controller.js');
    assert.match(manager, /role="status"[^>]*data-dialog-instance-target="feedbackStatut"/);
    assert.match(manager, /role="alert"[^>]*data-dialog-instance-target="feedbackAlerte"/);
    assert.doesNotMatch(source('assets/controllers/dialog-instance_controller.js'), /setAttribute\('aria-live'/);
});

test('CONTRE-ÉPREUVE : un événement MARQUÉ (émis par notre code), sans geste, puis fermeture : aucune question', () => {
    const marque = evenementInterne('input');
    const compte = evenementDeSaisie({ type: marque.type, isTrusted: false, interne: estInterne(marque), surSelectTomSelect: false });
    assert.equal(compte, false);
    assert.equal(fermetureADemander({ empreinteInitiale: null, saisieSansGeste: compte }), false);
});

// ── Garde-fou statique : aucun input/change émis sans la marque ──────────────────

test('aucun input/change n’est fabriqué dans assets/ sans passer par evenementInterne', async () => {
    const { readdirSync, statSync } = await import('node:fs');
    const fautifs = [];
    const parcourir = (dossier) => {
        for (const nom of readdirSync(dossier)) {
            const chemin = join(dossier, nom);
            if (statSync(chemin).isDirectory()) {
                if (nom !== 'vendor') parcourir(chemin);
                continue;
            }
            if (!nom.endsWith('.js') || nom === 'evenement-interne.js') continue;
            const code = readFileSync(chemin, 'utf8');
            const motif = /new\s+(?:Custom)?Event\(\s*['"`](input|change)['"`]/g;
            let m;
            while ((m = motif.exec(code)) !== null) {
                fautifs.push(`${chemin.slice(RACINE.length + 1)}:${code.slice(0, m.index).split('\n').length}`);
            }
        }
    };
    parcourir(join(RACINE, 'assets'));
    assert.deepEqual(fautifs, [], 'Émettre input/change par evenementInterne() (assets/controllers/evenement-interne.js) : sinon le dialogue y voit une saisie de l’utilisateur.');
});

// ── Revue du suivi ──────────────────────────────────────────────────────────────

test('seul un champ qui COMPTE dans le formulaire (ou l’éditeur riche) fait une saisie sans geste', () => {
    // Dictée dans la recherche interne de Tom Select, remplissage d'un champ anonyme : rien.
    assert.equal(evenementDeSaisie({ type: 'input', isTrusted: true, champDuFormulaire: false }), false);
    assert.equal(evenementDeSaisie({ type: 'input', isTrusted: false, champDuFormulaire: false }), false);
    assert.equal(evenementDeSaisie({ type: 'input', isTrusted: true, champDuFormulaire: true }), true);
});

test('un enregistrement réussi remet la garde à zéro (la fermeture automatique ne dépend pas du rechargement)', () => {
    const code = source('assets/controllers/dialog-instance_controller.js');
    const succes = code.slice(code.indexOf('\n    handleSuccessfulSubmit('), code.indexOf('\n    handleSuccessfulSubmit(') + 1500);
    assert.match(succes, /this\._empreinteInitiale = null;/);
    assert.match(succes, /this\._saisieSansGeste = false;/);
});

test('dialog-manager : l’état inerte est recalculé à chaque connexion', () => {
    assert.match(source('assets/controllers/dialog-manager_controller.js'), /this\.inerte = false;\s*\n\s*if \(!reserverLInstallation\(this\.element\)\)/);
});
