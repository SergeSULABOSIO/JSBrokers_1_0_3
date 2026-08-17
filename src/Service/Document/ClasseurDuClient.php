<?php

namespace App\Service\Document;

use App\Entity\Classeur;
use App\Entity\Client;
use App\Entity\Document;
use App\Services\Search\PortefeuilleScope;
use Doctrine\ORM\EntityManagerInterface;

/**
 * TOUT CLIENT A SON CLASSEUR, ET TOUT DOCUMENT DE SON DOSSIER Y ATTERRIT.
 *
 * L'ÉTAT D'AVANT, qui explique tout le reste. `Document.classeur` existait depuis le
 * début, le formulaire l'offrait, l'écran affichait « Classé dans : … » — et aucun code
 * de production ne créait jamais de classeur ni n'en posait un. Vérifié : zéro
 * `new Classeur(` et zéro `setClasseur(` hors de l'entité elle-même. Autrement dit, un
 * rangement entièrement facultatif, que personne ne remplissait : tous les documents
 * affichaient « Non classé », et la rubrique Classeurs restait vide. Une fonction qui
 * existe à moitié est pire qu'une fonction absente — elle promet un classement dont
 * l'utilisateur découvre, dossier en main, qu'il n'a jamais eu lieu.
 *
 * LA RÈGLE EST DONC DEVENUE AUTOMATIQUE, et elle tient en une phrase : un document qui
 * relève d'un client, et qui n'est pas déjà rangé, va dans LE classeur de ce client —
 * créé au besoin, jamais en double.
 *
 * CE QUE CE SERVICE NE FAIT PAS, VOLONTAIREMENT :
 *
 *  - il ne DÉPLACE jamais un document déjà rangé ailleurs. Un classeur choisi à la main
 *    est une décision de l'utilisateur ; la rattraper d'office reviendrait à défaire son
 *    organisation sans le lui dire. Le rattrapage des anciennes données le propose, mais
 *    seulement sur demande explicite ({@see \App\Command\ClasseurAlignerClientsCommand}) ;
 *  - il ne range pas ce qui n'a pas de client. Un document de bordereau, de fournisseur
 *    ou de compte bancaire n'appartient au dossier de personne : lui inventer un classeur
 *    le ferait apparaître dans le dossier d'un client au hasard. Il reste non classé, ce
 *    qui est l'état juste — et ce que {@see PortefeuilleScope::ORPHELINS_TOLERES} admet
 *    déjà pour l'affichage.
 *
 * DEUX CHEMINS D'ÉCRITURE, UNE SEULE RÈGLE. Un document naît soit de l'interface
 * (formulaire, sélecteur de pièces jointes), soit de Ket (plan d'écriture). Poser la
 * règle dans chacun, c'était deux endroits à ne pas oublier, et un troisième le jour où
 * un nouveau chemin apparaît. Elle est donc posée UNE fois, au ras de Doctrine
 * ({@see \App\EventListener\ClasseurAutomatiqueListener}), et ce service en est le
 * cerveau — appelable aussi à la main par le rattrapage.
 */
final class ClasseurDuClient
{
    /** Au-delà, `Classeur.nom` et `Classeur.description` sont tronqués (colonnes de 255). */
    private const LONGUEUR_MAX = 255;

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * LE classeur de ce client — trouvé s'il existe, créé sinon.
     *
     * IDEMPOTENTE, et c'est sa raison d'être : appelée cent fois pour le même client,
     * elle rend cent fois le même classeur. La recherche porte sur la RELATION et non
     * sur le nom, si bien qu'un client renommé garde son classeur (dont l'intitulé est
     * alors rafraîchi) au lieu d'en recevoir un second.
     *
     * Le classeur créé n'est que `persist()`é : c'est l'appelant qui décide du `flush`,
     * parce que ce service est appelé au milieu d'un flush en cours par le listener.
     */
    public function pour(Client $client): Classeur
    {
        // LE CLASSEUR PEUT AVOIR ÉTÉ CRÉÉ IL Y A TROIS LIGNES, dans le même flush, et
        // n'être donc pas encore en base : `findOneBy` ne le verrait pas. Trois pièces du
        // même client enregistrées d'un coup — le cas normal d'un sélecteur multi-fichiers
        // — auraient alors produit trois classeurs, dont deux refusés par la contrainte
        // d'unicité. On regarde donc d'abord ce que l'unité de travail a déjà en attente.
        $enAttente = $this->classeurEnAttentePour($client);
        if ($enAttente instanceof Classeur) {
            return $enAttente;
        }

        $classeur = $this->em->getRepository(Classeur::class)->findOneBy(['client' => $client]);

        if ($classeur instanceof Classeur) {
            // LE NOM SUIT LE CLIENT. Sans cela, un client renommé garderait un classeur
            // à son ancien nom : le lien resterait juste, l'écran mentirait.
            $attendu = $this->nomPour($client);
            if ($classeur->getNom() !== $attendu) {
                $classeur->setNom($attendu);
            }

            return $classeur;
        }

        $classeur = (new Classeur())
            ->setClient($client)
            ->setNom($this->nomPour($client))
            ->setDescription($this->descriptionPour($client));

        // L'entreprise est OBLIGATOIRE en base (AuditableTrait) : elle vient du client,
        // seule origine qui garantisse que le classeur reste dans le même périmètre que
        // les documents qu'il va contenir. L'invité suit, pour que le classeur apparaisse
        // au gestionnaire du dossier.
        $classeur->setEntreprise($client->getEntreprise());
        $classeur->setInvite($client->getInvite());

        $this->em->persist($classeur);

        return $classeur;
    }

    /**
     * Le classeur de ce client déjà créé dans le flush en cours, s'il y en a un.
     *
     * On interroge l'unité de travail plutôt que de tenir un cache dans ce service : un
     * cache survivrait au `clear()` de l'entity manager et rendrait un jour un classeur
     * détaché, que Doctrine refuserait d'associer. L'unité de travail, elle, dit toujours
     * la vérité du moment.
     */
    private function classeurEnAttentePour(Client $client): ?Classeur
    {
        foreach ($this->em->getUnitOfWork()->getScheduledEntityInsertions() as $entite) {
            if ($entite instanceof Classeur && $entite->getClient() === $client) {
                return $entite;
            }
        }

        return null;
    }

    /**
     * Range ce document s'il doit l'être, et rend le classeur posé — null sinon.
     *
     * Les deux cas de non-rangement sont des non-événements, pas des échecs : le document
     * est déjà rangé, ou il ne relève d'aucun client.
     */
    public function ranger(Document $document): ?Classeur
    {
        if ($document->getClasseur() !== null) {
            return null;
        }

        $client = $this->clientDe($document);
        if (!$client instanceof Client) {
            return null;
        }

        // FAIL-CLOSED sur l'entreprise. Elle est NOT NULL sur Classeur : un client qui
        // n'en aurait pas — état qui ne s'obtient pas par l'interface — ferait échouer
        // l'insertion et emporterait avec elle l'enregistrement du document. Ne rien
        // ranger est ici la seule issue qui ne perde pas la pièce de l'utilisateur.
        if ($client->getEntreprise() === null) {
            return null;
        }

        $classeur = $this->pour($client);
        $document->setClasseur($classeur);

        return $classeur;
    }

    /**
     * DE QUEL CLIENT RELÈVE CE DOCUMENT ? — en mémoire, en suivant les relations.
     *
     * Un document n'a qu'un seul parent renseigné parmi une quarantaine, et ce parent
     * n'est pas toujours le client : ce peut être une police, dont la cotation dépend
     * d'une piste, qui appartient au client — quatre relations à remonter. Les chemins ne
     * sont pas écrits ici : ils sont dérivés de ceux du périmètre portefeuille
     * ({@see PortefeuilleScope::cheminsVersLeClient()}), qui gouvernent déjà ce que
     * l'écran montre. Une liste recopiée aurait divergé, et un document serait resté non
     * classé sans que rien ne le signale.
     *
     * Le PREMIER chemin qui aboutit gagne. L'ordre importe peu — un document n'a qu'un
     * parent — mais il rend la fonction déterministe si un jour deux l'étaient.
     */
    public function clientDe(Document $document): ?Client
    {
        foreach (PortefeuilleScope::cheminsVersLeClient('Document') as $chemin) {
            $client = $this->suivre($document, $chemin);
            if ($client instanceof Client) {
                return $client;
            }
        }

        return null;
    }

    /**
     * Suit un chemin pointé (« avenant.cotation.piste.client ») de proche en proche.
     *
     * On avance par les accesseurs plutôt que par réflexion sur les propriétés : c'est
     * ce que fait le reste du projet, et cela laisse les proxys Doctrine s'hydrater
     * d'eux-mêmes. Un maillon absent interrompt la remontée sans bruit — un document
     * rattaché à une police sans cotation n'a pas de client, ce n'est pas une anomalie.
     */
    private function suivre(object $depuis, string $chemin): ?object
    {
        $courant = $depuis;
        foreach (explode('.', $chemin) as $segment) {
            $accesseur = 'get' . ucfirst($segment);
            if (!method_exists($courant, $accesseur)) {
                // Un chemin qui ne correspond plus au modèle : mieux vaut ne rien rendre
                // que rendre le mauvais client. Le test de contrat le fait échouer.
                return null;
            }
            $courant = $courant->{$accesseur}();
            if (!\is_object($courant)) {
                return null;
            }
        }

        return $courant;
    }

    /**
     * L'intitulé du classeur : le nom du client, tel quel.
     *
     * Sans ornement ni préfixe : c'est ainsi qu'il se retrouve dans une liste triée et
     * dans l'autocomplétion, à côté du nom qu'on vient de taper. Deux clients homonymes
     * auront deux classeurs de même intitulé, et c'est sans conséquence — ils sont
     * distingués par leur lien, pas par leur nom.
     */
    public function nomPour(Client $client): string
    {
        $nom = trim((string) $client->getNom());
        if ($nom === '') {
            // Un client sans nom existe le temps d'une saisie. Un classeur sans nom, non :
            // la colonne est NOT NULL et un intitulé vide serait introuvable à l'écran.
            $nom = 'Client #' . ($client->getId() ?? 0);
        }

        return mb_substr($nom, 0, self::LONGUEUR_MAX);
    }

    private function descriptionPour(Client $client): string
    {
        return mb_substr(
            sprintf('Dossier du client %s — les documents de ce client y sont rangés automatiquement.', $this->nomPour($client)),
            0,
            self::LONGUEUR_MAX,
        );
    }
}
