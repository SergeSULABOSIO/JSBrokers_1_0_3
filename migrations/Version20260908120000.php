<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * UN ASSUREUR S'INSCRIT SOUS SON NOM.
 *
 * ── CE QU'ON A CONSTATÉ (2026-09-08) ────────────────────────────────────────────────
 * « Crée-moi les assureurs suivants : ACTIVA, ACTIVA LIFE, SUNU, RAWSUR, RAWSUR LIFE,
 * MAYFAIR et SFA. » Une demande de trois secondes, sept enregistrements sans la moindre
 * subtilité. L'assistant a réclamé, pour chacun, une adresse e-mail, un numéro d'impôt,
 * une identification nationale et un RCCM — vingt-huit références que le courtier n'avait
 * pas, et n'avait aucune raison d'avoir. Trois messages plus tard, aucun des sept
 * assureurs n'existait.
 *
 * ── D'OÙ CELA VENAIT ────────────────────────────────────────────────────────────────
 * De ces quatre colonnes, et d'elles seules. `Client` et `Partenaire` portent exactement
 * les mêmes champs, tous nullables depuis toujours ; `assureur` était le seul à les
 * exiger. Rien dans le métier ne le justifie : le NIF et le RCCM d'une compagnie se
 * relèvent sur une pièce, plus tard, quand elle arrive. Et aucun code ne les lit —
 * `getNumimpot()`, `getIdnat()` et `getRccm()` d'Assureur n'ont pas un seul appelant.
 *
 * L'assistant ne faisait donc que rapporter fidèlement ce que la base exigeait :
 * `ChampsObligatoiresInspector` dérive l'obligation de la NULLABILITÉ DE LA COLONNE, et
 * le formulaire de saisie opposait le même refus à qui l'ouvrait à la main. Le blocage
 * n'était pas dans Ket, il était ici.
 *
 * ── CE QUE CETTE MIGRATION FAIT ─────────────────────────────────────────────────────
 * Elle aligne `assureur` sur `client` et `partenaire`. Seul `nom` reste obligatoire :
 * c'est ce qui fait qu'un assureur EST cet assureur.
 *
 * Aucune donnée n'est touchée — passer une colonne de NOT NULL à NULL ne perd rien, et
 * les lignes existantes gardent leurs valeurs.
 */
final class Version20260908120000 extends AbstractMigration
{
    /**
     * Les colonnes rendues facultatives. Toutes en VARCHAR(255), comme leurs jumelles de
     * `client` et `partenaire` : la définition est reprise à l'identique, seule la
     * nullabilité change.
     */
    private const COLONNES = ['email', 'numimpot', 'idnat', 'rccm'];

    public function getDescription(): string
    {
        return 'Assureur : e-mail, NIF, IDNAT et RCCM deviennent facultatifs, comme sur Client et Partenaire.';
    }

    public function up(Schema $schema): void
    {
        foreach (self::COLONNES as $colonne) {
            $this->addSql(sprintf(
                'ALTER TABLE assureur CHANGE %1$s %1$s VARCHAR(255) DEFAULT NULL',
                $colonne,
            ));
        }
    }

    public function down(Schema $schema): void
    {
        // ⚠ LE RETOUR EN ARRIÈRE N'EST PAS GRATUIT, et il ne peut pas l'être : entre-temps,
        // des assureurs auront été créés sous leur seul nom. Redevenir NOT NULL ferait
        // échouer l'ALTER sur ces lignes-là. On leur pose donc une chaîne vide — la seule
        // valeur qui ne prétende rien — avant de restaurer la contrainte. Inventer un
        // numéro d'impôt pour satisfaire un rollback serait exactement la faute que ce
        // projet refuse partout ailleurs.
        foreach (self::COLONNES as $colonne) {
            $this->addSql(sprintf(
                'UPDATE assureur SET %1$s = \'\' WHERE %1$s IS NULL',
                $colonne,
            ));
            $this->addSql(sprintf(
                'ALTER TABLE assureur CHANGE %1$s %1$s VARCHAR(255) NOT NULL',
                $colonne,
            ));
        }
    }
}
