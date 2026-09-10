<?php

namespace App\Services;

use App\Comptabilite\CourtierEcritureComptableService;
use App\Echange\Etat\ExerciceDesTranches;
use App\Entity\Entreprise;
use Doctrine\ORM\EntityManagerInterface;

/**
 * LES EXERCICES QU'UN CABINET A RÉELLEMENT VÉCUS — source unique des pastilles du
 * tableau de bord.
 *
 * ⚠ DEUX AXES DE TEMPS COHABITENT, ET AUCUN NE SUFFIT SEUL. La comptabilité du courtier
 * est une comptabilité de TRÉSORERIE : son fait générateur est le paiement. Le
 * portefeuille, lui, se compte en dates d'EFFET de police. Une police prenant effet en
 * décembre dont la prime est encaissée en janvier appartient donc à deux exercices
 * différents selon ce qu'on regarde — et le tableau de bord montre les deux à la fois.
 *
 * S'en tenir à la trésorerie ferait disparaître un exercice de souscription pas encore
 * encaissé ; s'en tenir aux polices ferait disparaître une année de dépenses, de
 * rétrocommissions ou d'encaissements sur des polices plus anciennes — qui sont le cas le
 * plus fréquent. On prend donc l'union, ce que fait déjà `SuiviFiscalService`.
 *
 * ⚠ ON N'ÉNUMÈRE JAMAIS UNE PLAGE D'ANNÉES. La règle est écrite ailleurs dans le projet et
 * vaut ici mot pour mot : « proposer 2019 à un cabinet ouvert en 2024 donnerait un chip qui
 * ne rend jamais rien, et l'utilisateur croirait à une panne ». Les exercices se DÉDUISENT
 * des données.
 */
final class ExercicesDuCabinet
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CourtierEcritureComptableService $comptabilite,
    ) {
    }

    /**
     * Les exercices à proposer, du plus récent au plus ancien.
     *
     * ⚠ L'ANNÉE COURANTE Y FIGURE TOUJOURS, même vide : c'est l'exercice qu'on ouvre, et
     * un cabinet doit pouvoir y revenir pour constater qu'il n'a encore rien produit. Ne
     * pas l'offrir enfermerait l'utilisateur dans son passé.
     *
     * @return int[]
     */
    public function disponibles(Entreprise $entreprise): array
    {
        $annees = [(int) date('Y') => true];

        foreach ($this->comptabilite->exercicesDisponibles($entreprise) as $annee) {
            $annees[(int) $annee] = true;
        }

        foreach (ExerciceDesTranches::annees($this->em, $entreprise) as $annee) {
            $annees[(int) $annee] = true;
        }

        $liste = array_keys($annees);
        rsort($liste);

        return $liste;
    }

    /**
     * L'exercice à montrer quand l'utilisateur n'a rien choisi.
     *
     * ⚠ CE N'EST PAS L'ANNÉE COURANTE, et c'est tout l'objet de cette méthode. Un cabinet
     * qui vient de reprendre trois ans d'historique ouvrait son tableau de bord sur
     * l'exercice en cours — vide — et lisait 0,00 partout : ses données existaient, elles
     * étaient justes, et rien ne le lui disait. Il concluait à une panne.
     *
     * On ouvre donc sur le dernier exercice qui porte quelque chose, exactement comme
     * l'écran des Documents comptables le fait déjà. L'année courante reste à un clic.
     */
    public function defaut(Entreprise $entreprise): int
    {
        $comptables = $this->comptabilite->exercicesDisponibles($entreprise);
        if ($comptables !== []) {
            return (int) $comptables[0];
        }

        $souscriptions = ExerciceDesTranches::annees($this->em, $entreprise);

        return $souscriptions === [] ? (int) date('Y') : (int) $souscriptions[0];
    }

    /**
     * L'exercice demandé, ramené à ce qui existe.
     *
     * ⚠ UNE ANNÉE VENUE DE L'URL N'EST PAS UNE ANNÉE. Elle peut être absente, valoir zéro,
     * ou désigner un exercice que ce cabinet n'a jamais connu — un lien partagé entre deux
     * cabinets suffit. On retombe alors sur le défaut plutôt que d'interroger la base sur
     * une plage qui ne rendra rien.
     */
    public function retenir(Entreprise $entreprise, ?int $demande): int
    {
        if ($demande === null || $demande <= 0) {
            return $this->defaut($entreprise);
        }

        return in_array($demande, $this->disponibles($entreprise), true)
            ? $demande
            : $this->defaut($entreprise);
    }
}
