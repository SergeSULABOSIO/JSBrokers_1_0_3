<?php

namespace App\Service\TexteRiche;

use App\Entity\Avenant;
use App\Entity\Chargement;
use App\Entity\Classeur;
use App\Entity\DemandeConge;
use App\Entity\DepenseCourtier;
use App\Entity\Feedback;
use App\Entity\Fournisseur;
use App\Entity\Groupe;
use App\Entity\HistoriqueDemande;
use App\Entity\ModelePieceSinistre;
use App\Entity\Note;
use App\Entity\NotificationSinistre;
use App\Entity\Paiement;
use App\Entity\PaiementPrime;
use App\Entity\Piste;
use App\Entity\ReversementRetroAgent;
use App\Entity\Risque;
use App\Entity\Tache;
use App\Entity\Taxe;

/**
 * LE REGISTRE DES CHAMPS EN TEXTE RICHE — entité => champs.
 *
 * Ce sont les `<textarea>` des dialogues d'entité, que _form_row.html.twig transforme en
 * éditeur Quill : ils peuvent porter du HTML. L'assainissement à l'écriture
 * (AssainissementDuTexteRicheListener) et le contrôle de longueur
 * (ChampsObligatoiresInspector) ne s'appliquent qu'à eux.
 *
 * ⚠ UN CHAMP RICHE NOUVEAU SE DÉCLARE ICI. ChampsEnTexteRicheTest compare ce registre aux
 * TextareaType des formulaires de dialogue : un oubli fait échouer le test.
 * HistoriqueDemande::commentaire n'a pas de dialogue, mais reçoit le motif de la demande.
 */
final class ChampsEnTexteRiche
{
    /** @var array<class-string, list<string>> */
    public const CHAMPS = [
        Avenant::class => ['description', 'nonRenouvelableMotif'],
        Chargement::class => ['description'],
        Classeur::class => ['description'],
        DemandeConge::class => ['motif', 'commentaireDecision', 'controlesContournes'],
        DepenseCourtier::class => ['description'],
        Feedback::class => ['description', 'nextAction'],
        Fournisseur::class => ['description'],
        Groupe::class => ['description'],
        HistoriqueDemande::class => ['commentaire'],
        ModelePieceSinistre::class => ['description'],
        Note::class => ['description'],
        NotificationSinistre::class => ['descriptionDeFait', 'descriptionVictimes'],
        Paiement::class => ['description'],
        PaiementPrime::class => ['description'],
        Piste::class => ['descriptionDuRisque'],
        ReversementRetroAgent::class => ['description'],
        Risque::class => ['description'],
        Tache::class => ['description'],
        Taxe::class => ['description'],
    ];

    /** @return list<string> les champs riches de cette entité (classe exacte ou parente) */
    public static function de(object|string $entite): array
    {
        foreach (self::CHAMPS as $classe => $champs) {
            if (is_a($entite, $classe, true)) {
                return $champs;
            }
        }

        return [];
    }
}
