<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * UNE SEULE MONNAIE DE TRAVAIL PAR CABINET (décisions du 2026-10-14 et du 2026-10-15).
 *
 * DONNÉES SEULEMENT, ET AUCUN MONTANT NE BOUGE. Un montant ne porte pas sa monnaie, et
 * l'écran affichait la monnaie d'affichage pendant la saisie : l'existant y est déjà. On
 * fait donc de cette monnaie d'affichage la monnaie de TRAVAIL du cabinet (saisie =
 * affichage, fonction 0), et toute autre monnaie active devient une RÉFÉRENCE (fonction
 * -1) — la monnaie du pays garde son drapeau local et son taux, pour le capital social.
 *
 * Quelle monnaie d'affichage ? EXACTEMENT celle que ServiceMonnaies retenait : la première
 * (plus petit id) de fonction « saisie et affichage » OU « affichage uniquement », sans
 * préférence entre les deux — c'est l'étiquette que portaient les montants à leur saisie.
 * Sans aucune : l'USD du cabinet s'il existe, sinon sa première monnaie « saisie
 * uniquement ». Un cabinet sans rien de tout cela ne reçoit rien : il est LISTÉ, à régler à
 * la main. CHAQUE CHANGEMENT EST LISTÉ dans la sortie. Rejouée, la migration ne change rien.
 */
final class Version20261015090000 extends AbstractMigration
{
    private const SAISIE_ET_AFFICHAGE = 0;
    private const SAISIE_UNIQUEMENT = 1;
    private const AFFICHAGE_UNIQUEMENT = 2;
    private const AUCUNE = -1;

    public function getDescription(): string
    {
        return 'Monnaies : une seule monnaie de travail par cabinet (la monnaie d’affichage), les autres en référence. Aucun montant converti.';
    }

    public function up(Schema $schema): void
    {
        $monnaies = $this->connection->fetchAllAssociative('SELECT id, entreprise_id, code, fonction FROM monnaie WHERE entreprise_id IS NOT NULL ORDER BY entreprise_id, id');

        $parCabinet = [];
        foreach ($monnaies as $monnaie) {
            $parCabinet[(int) $monnaie['entreprise_id']][] = $monnaie;
        }

        foreach ($parCabinet as $cabinet => $liste) {
            $travail = $this->premiereParmi($liste, [self::SAISIE_ET_AFFICHAGE, self::AFFICHAGE_UNIQUEMENT])
                ?? $this->parCode($liste, 'USD')
                ?? $this->premiereParmi($liste, [self::SAISIE_UNIQUEMENT]);

            if ($travail === null) {
                $this->write(sprintf('  Cabinet #%d : AUCUNE monnaie d’affichage ni USD — aucune monnaie de travail posée, à régler à la main.', $cabinet));
            } elseif ((int) $travail['fonction'] !== self::SAISIE_ET_AFFICHAGE) {
                $this->write(sprintf('  Cabinet #%d : %s devient la monnaie de travail (fonction %d → 0).', $cabinet, $travail['code'], $travail['fonction']));
                $this->addSql('UPDATE monnaie SET fonction = ? WHERE id = ?', [self::SAISIE_ET_AFFICHAGE, (int) $travail['id']]);
            }

            foreach ($liste as $monnaie) {
                $active = in_array((int) $monnaie['fonction'], [self::SAISIE_ET_AFFICHAGE, self::SAISIE_UNIQUEMENT, self::AFFICHAGE_UNIQUEMENT], true);
                if ($active && ($travail === null || (int) $monnaie['id'] !== (int) $travail['id'])) {
                    $this->write(sprintf('  Cabinet #%d : %s passe en référence (fonction %d → -1).', $cabinet, $monnaie['code'], $monnaie['fonction']));
                    $this->addSql('UPDATE monnaie SET fonction = ? WHERE id = ?', [self::AUCUNE, (int) $monnaie['id']]);
                }
            }
        }
    }

    /**
     * La première (plus petit id) dont la fonction est parmi celles-ci.
     *
     * @param list<array<string, mixed>> $liste
     * @param list<int>                  $fonctions
     */
    private function premiereParmi(array $liste, array $fonctions): ?array
    {
        foreach ($liste as $monnaie) {
            if (in_array((int) $monnaie['fonction'], $fonctions, true)) {
                return $monnaie;
            }
        }

        return null;
    }

    /** @param list<array<string, mixed>> $liste */
    private function parCode(array $liste, string $code): ?array
    {
        foreach ($liste as $monnaie) {
            if (strcasecmp((string) $monnaie['code'], $code) === 0) {
                return $monnaie;
            }
        }

        return null;
    }

    /** Retour arrière : sans objet — les anciennes fonctions séparées ne sont plus proposées. */
    public function down(Schema $schema): void
    {
        $this->write('  Aucun retour arrière : les fonctions « saisie uniquement » et « affichage uniquement » ont disparu.');
    }
}
