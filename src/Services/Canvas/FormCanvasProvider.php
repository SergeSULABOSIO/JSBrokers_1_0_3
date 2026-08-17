<?php

namespace App\Services\Canvas;
use App\Entity\Utilisateur;
use App\Repository\EntrepriseRepository;
use App\Entity\Invite;
use App\Service\Document\DocumentFichier;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Services\Canvas\Provider\Form\FormCanvasProviderInterface;
use App\Token\TokenAccountService;
use Doctrine\Persistence\Proxy;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

class FormCanvasProvider
{
    /**
     * @var FormCanvasProviderInterface[]
     */
    private iterable $providers;

    /** Mémoïsation par entreprise (le service vit le temps d'une requête). */
    private array $assistantDispo = [];

    public function __construct(
        #[TaggedIterator('app.form_canvas_provider')] iterable $providers,
        private readonly Security $security,
        private readonly WorkspaceAccessResolver $accessResolver,
        private readonly TokenAccountService $tokenAccountService,
        private readonly EntrepriseRepository $entrepriseRepository,
        // Source unique des rubriques capables de porter des pièces : la carte est lue
        // dans les métadonnées Doctrine, jamais écrite à la main.
        private readonly DocumentFichier $documentFichier,
    ) {
        $this->providers = $providers;
    }

    public function getCanvas(object $object, ?int $idEntreprise): array
    {
        // Proxy-safe : une association lazy (ex. Avenant::pisteDeRenouvellement) est un
        // proxy Doctrine dont get_class() renvoie « Proxies\__CG__\… », qui ne matche
        // aucun supports(). On remonte à la classe réelle (le proxy étend l'entité).
        $entityClassName = $object instanceof Proxy ? get_parent_class($object) : get_class($object);

        foreach ($this->providers as $provider) {
            if ($provider->supports($entityClassName)) {
                $canvas = $provider->getCanvas($object, $idEntreprise);
                $this->injecterActionAssistant($canvas, $entityClassName, $idEntreprise);
                $this->injecterActionsDocuments($canvas, $entityClassName, $idEntreprise);
                $this->injecterActionTelechargerDocuments($canvas, $entityClassName, $idEntreprise);

                return $canvas;
            }
        }

        // If no specific provider is found, return an empty array.
        return [];
    }

    /**
     * Action transverse « Ajouter au chat avec l'assistant IA » : injectée
     * centralement pour TOUTES les entités des listes du workspace (une seule
     * déclaration → toolbar + menu contextuel, drapeau multi = multi-sélection).
     * Ce gating n'est que cosmétique : les endpoints contexte re-valident tout
     * (fail-closed). L'URL reste vide, le cerveau construit la charge depuis la
     * sélection.
     */
    private function injecterActionAssistant(array &$canvas, string $fqcn, ?int $idEntreprise): void
    {
        $shortName = substr($fqcn, (int) strrpos($fqcn, '\\') + 1);
        if ($idEntreprise === null
            || !isset($this->accessResolver->libellesEntites()[$shortName])
            || !$this->assistantDisponible($idEntreprise)) {
            return;
        }

        $canvas['parametres']['attribute_actions'][] = [
            'label' => "Ajouter au chat avec l'assistant IA",
            'icon'  => 'assistant-ia',
            'event' => 'ui:assistant.add-to-chat',
            'url'   => '',
            'multi' => true,
        ];
    }

    /**
     * PIÈCES JOINTES : « Attacher des pièces » et « Voir les documents », injectées
     * centralement pour toute rubrique capable d'en porter.
     *
     * POURQUOI ICI, ET NON DANS TRENTE-SIX PROVIDERS. La liste des rubriques qui peuvent
     * recevoir un fichier est déjà connue du code — c'est la carte des relations de
     * Document. La recopier entité par entité, ce serait accepter qu'elle diverge dès la
     * prochaine entité ouverte : l'une des deux listes serait à jour, l'autre non, et
     * l'utilisateur découvrirait au clic que la rubrique promise n'accepte rien.
     *
     * UNE SEULE DÉCLARATION, DEUX SURFACES. La barre d'outils et le menu contextuel
     * consomment le même `attribute_actions` — l'action apparaît donc aux deux endroits
     * sans qu'on ait à le demander, et avec le même comportement.
     *
     * PAS DE CLÉ `multi` : c'est elle, et elle seule, qui impose la sélection d'UN SEUL
     * élément (toolbar_controller::organizeButtons). L'utilisateur doit avoir désigné la
     * fiche avant de pouvoir y déposer quoi que ce soit — sans quoi « attacher » n'aurait
     * pas de destinataire.
     *
     * LES DEUX SONT REPLIÉES SOUS UNE FAMILLE. La barre n'affiche que quatre entrées en
     * ligne et renvoie le surplus dans « Autres actions » : deux actions transverses de
     * plus auraient repoussé les actions MÉTIER des rubriques déjà chargées (les
     * mouvements d'une police, par exemple) dans un menu de débordement. Une entrée
     * « Pièces jointes » qui déploie les deux coûte une place au lieu de deux.
     */
    private function injecterActionsDocuments(array &$canvas, string $fqcn, ?int $idEntreprise): void
    {
        $shortName = substr($fqcn, (int) strrpos($fqcn, '\\') + 1);
        $champ = $this->documentFichier->parentsPossibles()[$shortName] ?? null;
        if ($champ === null || $idEntreprise === null) {
            return;
        }

        $invite = $this->inviteConnecte($idEntreprise);
        if ($invite === null || !$this->accessResolver->canRead($invite, 'Document')) {
            return;
        }

        $famille = ['groupe' => 'Pièces jointes', 'groupe_icone' => 'classeur'];

        // Attacher est une ÉCRITURE : sans le droit, l'entrée n'apparaît pas. Le gating
        // reste cosmétique — la route re-valide, fail-closed.
        if ($this->accessResolver->can($invite, 'Document', Invite::ACCESS_ECRITURE)) {
            $canvas['parametres']['attribute_actions'][] = $famille + [
                'label' => 'Attacher des pièces',
                'icon'  => 'action:upload',
                'event' => 'ui:documents.attach-request',
                'url'   => sprintf('/admin/document/api/attacher/%s/%%id%%', $champ),
            ];
        }

        // « Voir les documents » existe DÉJÀ sur Client, Piste, Cotation et Avenant, où
        // elle ouvre le dossier COMPLET (ascendants et descendants, cf.
        // SoaPoliceDocumentsCollector) — bien plus que les pièces de la ligne. En
        // injecter une seconde du même nom ferait cohabiter deux entrées identiques
        // disant deux choses différentes : on ne pose la nôtre que là où il n'y en a pas.
        if (!$this->porteDejaUneVueDocuments($canvas)) {
            $canvas['parametres']['attribute_actions'][] = $famille + [
                'label' => 'Voir les documents',
                'icon'  => 'classeur',
                'event' => 'ui:documents.liste-request',
                'url'   => sprintf('/admin/document/api/de/%s/%%id%%', $champ),
            ];
        }
    }

    /**
     * TÉLÉCHARGER LA SÉLECTION, sur la rubrique Documents elle-même.
     *
     * POURQUOI ELLE EST INJECTÉE ICI et non déclarée dans le canevas de Document. Le
     * gating vit ici : c'est ce provider qui sait résoudre l'invité connecté au workspace
     * demandé et lui demander son droit de lecture. Un provider d'entité, lui, ne reçoit
     * ni l'invité ni l'entreprise — il déclarerait l'action pour tout le monde, et le
     * bouton apparaîtrait à qui n'a pas le droit de s'en servir. Le gating reste
     * cosmétique : la route re-valide, fail-closed.
     *
     * `multi` : c'est la seule action de la rubrique qui ait un sens sur plusieurs lignes.
     * Sans cette clé, les deux surfaces n'affichent l'entrée que pour une sélection
     * d'exactement un élément (toolbar_controller L.162, context-menu_controller L.168) —
     * et il n'y aurait jamais d'archive.
     *
     * L'`url` est laissée vide : elle ne saurait porter qu'un seul identifiant
     * (`%id%` est résolu sur `selection[0]`), alors que c'est justement la SÉLECTION
     * ENTIÈRE qui compte. Le Cerveau la reconstruit depuis `payload.selection`, comme le
     * fait déjà « Ajouter au chat ».
     */
    private function injecterActionTelechargerDocuments(array &$canvas, string $fqcn, ?int $idEntreprise): void
    {
        if (substr($fqcn, (int) strrpos($fqcn, '\\') + 1) !== 'Document' || $idEntreprise === null) {
            return;
        }

        $invite = $this->inviteConnecte($idEntreprise);
        if ($invite === null || !$this->accessResolver->canRead($invite, 'Document')) {
            return;
        }

        $canvas['parametres']['attribute_actions'][] = [
            'label' => 'Télécharger',
            'icon'  => 'action:download',
            'event' => 'ui:documents.download-request',
            'url'   => '',
            'multi' => true,
        ];
    }

    /** Le canevas déclare-t-il déjà une consultation de documents (dossier SOA) ? */
    private function porteDejaUneVueDocuments(array $canvas): bool
    {
        foreach ($canvas['parametres']['attribute_actions'] ?? [] as $action) {
            if (($action['event'] ?? null) === 'ui:soa.docs-picker-request') {
                return true;
            }
        }

        return false;
    }

    /**
     * L'invité connecté DANS ce workspace, ou null. Fail-closed hors HTTP (ligne de
     * commande, worker) et hors de l'entreprise demandée — un canevas construit pour un
     * autre cabinet ne doit proposer aucune action.
     */
    private function inviteConnecte(int $idEntreprise): ?Invite
    {
        $user = $this->security->getUser();
        if (!$user instanceof Utilisateur) {
            return null;
        }
        $invite = $this->accessResolver->resolveConnectedInvite($user);

        return $invite?->getEntreprise()?->getId() === $idEntreprise ? $invite : null;
    }

    /**
     * L'assistant IA est-il disponible pour l'utilisateur courant dans cette
     * entreprise ? Compte payant + accès au MODULE (pseudo-entité AssistantIa).
     * Fail-closed sans utilisateur (CLI, worker) ou hors du workspace demandé.
     */
    private function assistantDisponible(int $idEntreprise): bool
    {
        return $this->assistantDispo[$idEntreprise] ??= (function () use ($idEntreprise): bool {
            $user = $this->security->getUser();
            if (!$user instanceof Utilisateur) {
                return false;
            }
            $invite = $this->accessResolver->resolveConnectedInvite($user);
            if ($invite === null || $invite->getEntreprise()?->getId() !== $idEntreprise) {
                return false;
            }
            if (!$this->accessResolver->canRead($invite, 'AssistantIa')) {
                return false;
            }
            $entreprise = $this->entrepriseRepository->find($idEntreprise);

            return $entreprise !== null && $this->tokenAccountService->estComptePayant($entreprise);
        })();
    }
}
