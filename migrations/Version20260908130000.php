<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * LE DÉMARRAGE DU CABINET SE MESURE — et l'assurance Voyage se retrouve.
 *
 * Deux changements sans rapport de sujet, mais qui partent ensemble parce qu'ils touchent
 * tous deux ce qu'un cabinet possède le jour de sa création.
 *
 * ── 1. entreprise.onboarding_score_notifie ──────────────────────────────────────────
 * La synthèse de configuration part par e-mail QUAND UNE ÉTAPE BASCULE, et pas à chaque
 * objet créé : sans mémoire du dernier score annoncé, un courtier qui saisit dix assureurs
 * recevrait dix e-mails. Cette colonne ne sert qu'à répondre « a-t-on déjà dit ce
 * chiffre-là ? » — le score qui fait foi, lui, se recalcule à la demande.
 *
 * Nullable, sans valeur par défaut : `null` signifie « aucune synthèse encore envoyée »,
 * ce qu'un 0 ne dirait pas (0 % est un score légitime).
 *
 * ── 2. IARD-ASS devient IARD-ASS-VOY, « Assistance - Voyage » ───────────────────────
 * Le risque semé s'appelait « Assistance ». C'est l'assurance Voyage, et sans le mot ni
 * la recherche du workspace ni le filtrage par mot-clé ne la trouvaient.
 *
 * ⚠ LE RENOMMAGE DOIT PASSER ICI, ET PAS SEULEMENT DANS LE JSON DU SEMIS. Le semis est
 * idempotent PAR LE CODE (`ServiceInitialisationEntreprise::poser()` cherche par `code` et
 * ne met jamais à jour l'existant). Un code neuf dans le seul fichier de semis aurait donc
 * laissé l'ancienne ligne en place et créé une SECONDE ligne au prochain passage — le
 * mécanisme exact qui avait produit 228 risques pour 56 codes.
 *
 * La clause `nom_complet = 'Assistance'` est le garde-fou : on ne touche que les lignes
 * portant encore le nom du semis. Un cabinet qui a renommé son risque a pris une décision,
 * et on ne réécrit jamais par-dessus une décision (même doctrine que ConditionDOffice).
 *
 * Et l'on écarte d'abord les cabinets qui auraient déjà, à la main, un risque de code
 * `IARD-ASS-VOY` : l'UPDATE y heurterait `uniq_risque_entreprise_cle` et ferait échouer
 * la migration pour tout le monde. Mieux vaut laisser ces lignes-là en l'état.
 */
final class Version20260908130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Score de configuration notifié + risque IARD-ASS renommé en IARD-ASS-VOY (Assistance - Voyage).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE entreprise ADD onboarding_score_notifie INT DEFAULT NULL');

        // Le renommage ne s'applique qu'aux cabinets où le nouveau code est libre.
        $this->addSql(<<<'SQL'
            UPDATE risque r
               SET r.code = 'IARD-ASS-VOY',
                   r.nom_complet = 'Assistance - Voyage'
             WHERE r.code = 'IARD-ASS'
               AND r.nom_complet = 'Assistance'
               AND NOT EXISTS (
                   SELECT 1 FROM (SELECT * FROM risque) AS d
                    WHERE d.entreprise_id = r.entreprise_id
                      AND d.code = 'IARD-ASS-VOY'
               )
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE risque r
               SET r.code = 'IARD-ASS',
                   r.nom_complet = 'Assistance'
             WHERE r.code = 'IARD-ASS-VOY'
               AND r.nom_complet = 'Assistance - Voyage'
               AND NOT EXISTS (
                   SELECT 1 FROM (SELECT * FROM risque) AS d
                    WHERE d.entreprise_id = r.entreprise_id
                      AND d.code = 'IARD-ASS'
               )
        SQL);

        $this->addSql('ALTER TABLE entreprise DROP onboarding_score_notifie');
    }
}
