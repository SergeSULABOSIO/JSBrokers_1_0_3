<?php

namespace App\Tests\Ai;

use App\Ai\Presentation\Colonnes;
use App\Ai\Resolution\CheminsDeRelation;
use App\Ai\Resolution\ResolveurDeReferences;
use App\Ai\Scope\AiScope;
use App\Ai\Tool\AiToolResult;
use App\Ai\Tool\ChronologieTool;
use App\Ai\Tool\EntiteLibelle;
use App\Entity\Avenant;
use App\Entity\Client;
use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\PaiementPrime;
use App\Services\JSBDynamicSearchService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * L'outil « chronologie » — né d'une affirmation FAUSSE.
 *
 * Un courtier demande « mets les dates aussi ». Ket répond que « la date exacte de
 * création du compte et des avenants n'est pas renseignée dans le système ». Or
 * createdAt est NOT NULL et posé au PrePersist sur les 42 entités portant
 * AuditableTrait : la donnée existait, elle n'était simplement jamais servie à
 * l'assistant. Une information invisible se raconte comme une information absente —
 * exactement le mécanisme qui, la veille, avait fait inventer une colonne
 * « Assureur Partenaire ».
 *
 * Ce que ces tests verrouillent, dans l'ordre d'importance : que la date de création
 * revienne, que la date MÉTIER ne soit jamais confondue avec la date de SAISIE, et
 * qu'une chronologie amputée par les droits le DISE.
 */
class ChronologieToolTest extends KernelTestCase
{
    private function id(object $entite, int $id): object
    {
        $reflexion = new \ReflectionProperty($entite::class, 'id');
        $reflexion->setValue($entite, $id);

        return $entite;
    }

    private function reponse(array $data): array
    {
        return [
            'status' => ['error' => null, 'code' => 200, 'message' => 'OK'],
            'data' => $data,
            'totalItems' => \count($data),
            'currentPage' => 1,
            'totalPages' => 1,
            'itemsPerPage' => 25,
        ];
    }

    /**
     * CheminsDeRelation et EntiteLibelle sont `final` : on ne les double pas, on prend
     * les VRAIS services. C'est d'ailleurs préférable — le graphe de relations et la
     * détection du champ de libellé sont exactement ce dont la justesse dépend, et un
     * double les rendrait toujours conformes à ce qu'on croit d'eux.
     *
     * @param list<string> $lisibles noms courts que l'invité a le droit de lire
     */
    private function makeTool(
        JSBDynamicSearchService $search,
        array $lisibles = ['Client', 'Avenant', 'PaiementPrime'],
    ): ChronologieTool {
        $resolver = $this->createMock(\App\Service\Workspace\WorkspaceAccessResolver::class);
        $resolver->method('libellesEntites')->willReturn([
            'Client' => 'Clients',
            'Avenant' => 'Polices',
            'PaiementPrime' => 'Paiements de prime',
            'Piste' => 'Opportunités',
            'Tache' => 'Tâches',
        ]);
        $resolver->method('canRead')->willReturnCallback(
            static fn (Invite $i, string $shortName) => \in_array($shortName, $lisibles, true),
        );

        $conteneur = static::getContainer();

        return new ChronologieTool(
            $resolver,
            $search,
            $conteneur->get(CheminsDeRelation::class),
            $conteneur->get(EntiteLibelle::class),
            // Le VRAI résolveur, monté sur le MÊME double de recherche : c'est ce qui
            // rend testable « le nom dicté l'emporte », sans quoi la résolution
            // interrogerait la base pendant que le reste du test lit le double.
            new ResolveurDeReferences($search, $resolver, $conteneur->get(EntiteLibelle::class)),
        );
    }

    protected function setUp(): void
    {
        static::bootKernel();
    }

    private function scope(): AiScope
    {
        return new AiScope(new Entreprise(), new Invite());
    }

    /** Un client réel, avec son horodatage d'audit. */
    private function client(): Client
    {
        $client = (new Client())->setNom('MIC-RC')->setExonere(false);
        $client->setCreatedAt(new \DateTimeImmutable('2026-01-12 09:30:00'));

        return $this->id($client, 42);
    }

    /**
     * LE CAS DE L'INCIDENT. « Quand ce compte a-t-il été créé ? » doit trouver une
     * réponse — et c'est la date de création de la fiche elle-même, pas celle d'un
     * objet lié.
     */
    public function testLaCreationDuCompteEstLePremierFaitDeLaChronologie(): void
    {
        $search = $this->createMock(JSBDynamicSearchService::class);
        $search->method('search')->willReturnCallback(
            fn (string $classe) => $classe === Client::class ? $this->reponse([$this->client()]) : $this->reponse([]),
        );

        $result = $this->makeTool($search)->execute(['entite' => 'Client', 'id' => 42], $this->scope());

        $this->assertSame(AiToolResult::STATUS_OK, $result->status);
        $premier = $result->data['lignes'][0];
        $this->assertSame('2026-01-12', $premier['date']);
        $this->assertSame('Compte client créé', $premier['fait']);
        $this->assertSame('MIC-RC', $premier['objet']);
        $this->assertSame('2026-01-12', $premier['saisiLe']);
    }

    /**
     * DEUX DATES, JAMAIS L'UNE POUR L'AUTRE. Une police saisie le 28/02 qui prend effet
     * le 01/03 produit deux faits distincts, et c'est la date MÉTIER qui ordonne. Sans
     * cette séparation, la chronologie raconte l'histoire de la SAISIE en croyant
     * raconter celle du contrat.
     */
    public function testUnePoliceProduitTroisFaitsOrdonnesParLeurDateMetier(): void
    {
        $avenant = (new Avenant())
            ->setReferencePolice('130')
            ->setNumero('0')
            ->setStartingAt(new \DateTimeImmutable('2026-03-01'))
            ->setEndingAt(new \DateTimeImmutable('2027-02-28'));
        $avenant->setCreatedAt(new \DateTimeImmutable('2026-02-28 17:05:00'));
        $this->id($avenant, 130);

        $search = $this->createMock(JSBDynamicSearchService::class);
        $search->method('search')->willReturnCallback(
            fn (string $classe) => match ($classe) {
                Client::class => $this->reponse([$this->client()]),
                Avenant::class => $this->reponse([$avenant]),
                default => $this->reponse([]),
            },
        );

        $lignes = $this->makeTool($search)
            ->execute(['entite' => 'Client', 'id' => 42], $this->scope())
            ->data['lignes'];

        $faits = array_map(static fn (array $l) => [$l['date'], $l['fait']], $lignes);

        $this->assertSame([
            ['2026-01-12', 'Compte client créé'],
            ['2026-02-28', 'Police enregistrée'],
            ['2026-03-01', 'Police prend effet'],
            ['2027-02-28', 'Police arrive à échéance'],
        ], $faits, 'L’ordre est celui des dates MÉTIER, pas celui des saisies.');

        // La saisie reste lisible, à part, sur chacun des trois faits de la police.
        foreach (\array_slice($lignes, 1) as $ligne) {
            $this->assertSame('2026-02-28', $ligne['saisiLe']);
        }
    }

    /**
     * Une chronologie amputée par les droits doit le DIRE. Sans ce signalement, une
     * liste partielle se lit comme une liste complète — et le courtier conclut qu'il ne
     * s'est rien passé.
     */
    public function testUneSourceHorsPerimetreEstOmiseEtSignalee(): void
    {
        $search = $this->createMock(JSBDynamicSearchService::class);
        $search->method('search')->willReturnCallback(
            fn (string $classe) => $classe === Client::class ? $this->reponse([$this->client()]) : $this->reponse([]),
        );

        // L'invité lit les clients, mais pas les polices.
        $result = $this->makeTool($search, ['Client', 'PaiementPrime'])
            ->execute(['entite' => 'Client', 'id' => 42], $this->scope());

        $this->assertSame(AiToolResult::STATUS_OK, $result->status);
        $this->assertContains('Polices', $result->data['sourcesOmises']);
        $this->assertStringContainsString('PARTIELLE', $result->data['note']);
    }

    /** FAIL-CLOSED sur l'ancre : retracer une fiche, c'est la lire. */
    public function testSansDroitDeLectureSurLAncreLeRefusEstImmediat(): void
    {
        $search = $this->createMock(JSBDynamicSearchService::class);
        $search->expects($this->never())->method('search');

        $result = $this->makeTool($search, ['PaiementPrime'])
            ->execute(['entite' => 'Client', 'id' => 42], $this->scope());

        $this->assertSame(AiToolResult::STATUS_HORS_PERIMETRE, $result->status);
        $this->assertSame('Clients', $result->data['libelle']);
    }

    /** Une entité inconnue de la carte d'accès n'est pas une ancre. */
    public function testUneAncreInconnueEstIntrouvable(): void
    {
        $search = $this->createMock(JSBDynamicSearchService::class);

        $result = $this->makeTool($search)->execute(['entite' => 'Licorne', 'id' => 1], $this->scope());

        $this->assertSame(AiToolResult::STATUS_INTROUVABLE, $result->status);
    }

    /**
     * La présentation déclarée porte la distinction des deux dates : « date » (métier)
     * ordonne, « saisiLe » informe. Aucun total — additionner des dates n'a pas de sens,
     * et « totaliser » vide vaut interdiction explicite.
     */
    public function testLaPresentationDeclareLesDeuxDatesEtAucunTotal(): void
    {
        $search = $this->createMock(JSBDynamicSearchService::class);
        $search->method('search')->willReturnCallback(
            fn (string $classe) => $classe === Client::class ? $this->reponse([$this->client()]) : $this->reponse([]),
        );

        $presentation = $this->makeTool($search)
            ->execute(['entite' => 'Client', 'id' => 42], $this->scope())
            ->data['presentation'];

        $this->assertSame(['date', 'fait', 'objet', 'saisiLe', 'par'], array_keys($presentation['colonnes']));
        $this->assertSame(Colonnes::DATE, $presentation['colonnes']['date']);
        $this->assertSame(Colonnes::DATE, $presentation['colonnes']['saisiLe']);
        $this->assertSame([], $presentation['totaliser']);
    }

    /**
     * LES RÈGLEMENTS DE PRIME NE DOIVENT PAS DISPARAÎTRE, et c'est tout l'objet des
     * chemins écrits à la main.
     *
     * Mesuré sur le graphe réel le 2026-08-11 : PaiementPrime rejoint le Client en QUATRE
     * segments (tranche.cotation.piste.client), au-delà de la profondeur maximale de
     * CheminsDeRelation. Un balayage générique aurait donc silencieusement omis les
     * primes réglées — exactement les lignes que le courtier regardait. Ce test vérifie
     * que la source est bel et bien interrogée, avec ce chemin-là.
     */
    public function testLesReglementsDePrimeSontInterrogesMalgreLeurProfondeur(): void
    {
        $cheminsVus = [];
        $search = $this->createMock(JSBDynamicSearchService::class);
        $search->method('search')->willReturnCallback(
            function (string $classe, array $criteres) use (&$cheminsVus) {
                if ($classe === PaiementPrime::class) {
                    $cheminsVus = $criteres[JSBDynamicSearchService::LIEN_MULTI_CHEMINS]['paths'] ?? [];
                }

                return $classe === Client::class ? $this->reponse([$this->client()]) : $this->reponse([]);
            },
        );

        $this->makeTool($search)->execute(['entite' => 'Client', 'id' => 42], $this->scope());

        $this->assertSame(['tranche.cotation.piste.client'], $cheminsVus);
        $this->assertGreaterThan(
            CheminsDeRelation::MAX_PROFONDEUR,
            substr_count($cheminsVus[0], '.') + 1,
            'Si ce chemin tenait dans la profondeur du graphe générique, ce test n’aurait plus de raison d’être.',
        );
    }

    /**
     * Une ancre qui n'est pas un client ramène à SON client, et la réponse le dit — sans
     * quoi Ket annoncerait « l'historique de cette police » là où elle tient celui du
     * compte. Le chemin est cherché sur l'instance : une police ordinaire n'a pas de
     * piste de renouvellement, et c'est sa cotation qui porte le lien.
     */
    public function testUneAncrePoliceRamenAuDossierDeSonClientEtLeDit(): void
    {
        $client = $this->client();
        $piste = (new \App\Entity\Piste())->setNom('Incendie 2026')->setTypeAvenant(0)->setExercice(2026);
        $piste->setClient($client);
        $cotation = (new \App\Entity\Cotation())->setNom('Cotation 2026')->setDuree(365);
        $cotation->setPiste($piste);

        $avenant = (new Avenant())->setReferencePolice('130')->setNumero('0');
        $avenant->setCotation($cotation);
        $avenant->setCreatedAt(new \DateTimeImmutable('2026-02-28'));
        $this->id($avenant, 130);

        $search = $this->createMock(JSBDynamicSearchService::class);
        $search->method('search')->willReturnCallback(
            fn (string $classe) => $classe === Avenant::class ? $this->reponse([$avenant]) : $this->reponse([]),
        );

        $result = $this->makeTool($search)->execute(['entite' => 'Avenant', 'id' => 130], $this->scope());

        $this->assertSame(AiToolResult::STATUS_OK, $result->status);
        $this->assertSame('Clients — MIC-RC', $result->data['dossier']);
        $this->assertSame('Polices — 130', $result->data['ancre']);
        $this->assertStringContainsString('porte sur le DOSSIER', $result->data['note']);
    }

    /**
     * Une fiche rattachée à aucun client n'a pas de dossier à raconter. On le DIT, au
     * lieu de rendre une chronologie vide — « rien n'est rattaché » et « rien ne s'est
     * passé » ne sont pas la même chose.
     */
    public function testUneAncreSansClientLeDitAuLieuDeRendreUneListeVide(): void
    {
        $avenant = (new Avenant())->setReferencePolice('999')->setNumero('0');
        $avenant->setCreatedAt(new \DateTimeImmutable('2026-02-28'));
        $this->id($avenant, 999);

        $search = $this->createMock(JSBDynamicSearchService::class);
        $search->method('search')->willReturn($this->reponse([$avenant]));

        $result = $this->makeTool($search)->execute(['entite' => 'Avenant', 'id' => 999], $this->scope());

        $this->assertSame(AiToolResult::STATUS_OK, $result->status);
        $this->assertStringContainsString('n’est rattachée à aucun client', $result->data['bloquant']);
        $this->assertArrayNotHasKey('lignes', $result->data);
    }

    /** Le filtre de période porte sur la date MÉTIER, celle qui ordonne la chronologie. */
    public function testLaPeriodeFiltreSurLaDateMetierEtNonSurLaSaisie(): void
    {
        $avenant = (new Avenant())
            ->setReferencePolice('130')
            ->setNumero('0')
            ->setStartingAt(new \DateTimeImmutable('2026-03-01'))
            ->setEndingAt(new \DateTimeImmutable('2027-02-28'));
        $avenant->setCreatedAt(new \DateTimeImmutable('2026-02-28'));
        $this->id($avenant, 130);

        $search = $this->createMock(JSBDynamicSearchService::class);
        $search->method('search')->willReturnCallback(
            fn (string $classe) => match ($classe) {
                Client::class => $this->reponse([$this->client()]),
                Avenant::class => $this->reponse([$avenant]),
                default => $this->reponse([]),
            },
        );

        $lignes = $this->makeTool($search)
            ->execute(['entite' => 'Client', 'id' => 42, 'du' => '2026-03-01', 'au' => '2026-12-31'], $this->scope())
            ->data['lignes'];

        // La prise d'effet (01/03) est retenue ; la police SAISIE le 28/02 ne l'est pas,
        // et l'échéance de 2027 sort de la fenêtre.
        $this->assertCount(1, $lignes);
        $this->assertSame('Police prend effet', $lignes[0]['fait']);
    }

    /**
     * Un paiement de prime porte sa date de RÈGLEMENT comme date métier : c'est le jour
     * où l'assuré a payé, pas celui où le courtier l'a saisi.
     */
    public function testUnPaiementDePrimePorteSaDateDeReglement(): void
    {
        $paiement = (new PaiementPrime())
            ->setReference('PRIME-001')
            ->setMontant(1080.0)
            ->setPaidAt(new \DateTimeImmutable('2026-03-15'));
        $paiement->setCreatedAt(new \DateTimeImmutable('2026-03-17'));
        $this->id($paiement, 7);

        $search = $this->createMock(JSBDynamicSearchService::class);
        $search->method('search')->willReturnCallback(
            fn (string $classe) => match ($classe) {
                Client::class => $this->reponse([$this->client()]),
                PaiementPrime::class => $this->reponse([$paiement]),
                default => $this->reponse([]),
            },
        );

        $lignes = $this->makeTool($search)
            ->execute(['entite' => 'Client', 'id' => 42], $this->scope())
            ->data['lignes'];

        $reglement = array_values(array_filter($lignes, static fn (array $l) => $l['fait'] === 'Prime réglée par l\'assuré'));
        $this->assertCount(1, $reglement);
        $this->assertSame('2026-03-15', $reglement[0]['date']);
        $this->assertSame('2026-03-17', $reglement[0]['saisiLe'], 'Réglée le 15, saisie le 17 : les deux se lisent.');
    }

    /**
     * Double de recherche qui distingue les DEUX interrogations de ce chemin : la
     * résolution d'un nom (critère LIKE) et la relecture d'une fiche par identifiant.
     *
     * @param array<int, object> $parNom  ce que la recherche par libellé rapporte
     * @param array<int, object> $parId   fiches disponibles, indexées par identifiant
     */
    private function rechercheNommee(array $parNom, array $parId): JSBDynamicSearchService
    {
        $search = $this->createMock(JSBDynamicSearchService::class);
        $search->method('search')->willReturnCallback(
            function (string $classe, array $criteres) use ($parNom, $parId) {
                if ($classe !== Client::class) {
                    return $this->reponse([]);
                }
                // Critère LIKE => c'est le résolveur qui cherche un nom.
                if (\is_array($criteres['nom'] ?? null)) {
                    return $this->reponse($parNom);
                }
                $id = (int) ($criteres['id'] ?? 0);

                return $this->reponse(isset($parId[$id]) ? [$parId[$id]] : []);
            },
        );

        return $search;
    }

    private function clientNomme(string $nom, int $id): Client
    {
        $client = (new Client())->setNom($nom)->setExonere(false);
        $client->setCreatedAt(new \DateTimeImmutable('2026-01-12 09:30:00'));

        return $this->id($client, $id);
    }

    /**
     * L'INCIDENT DU 2026-08-13, ET C'EST LE TEST QUI COMPTE ICI.
     *
     * L'utilisateur corrige : « non, je parle de Mr. Mbusa Jean de Dieu ». Aucun client
     * ne porte ce nom. Mais l'outil EXIGEAIT alors un identifiant, que le modèle n'avait
     * aucun moyen de faire résoudre : il a donc reporté celui du dossier précédent
     * (Kibali Goldmines) et retracé SON histoire sous le nom de Mbusa. Le courtier a lu
     * l'historique d'un tiers en croyant lire le sien — et au message suivant,
     * ouvrir_dialogue, qui résout par nom, répondait « introuvable » : deux outils,
     * deux réponses contradictoires sur la même personne.
     *
     * Le nom l'emporte donc sur l'identifiant reporté : un identifiant repris du fil est
     * une hypothèse, un nom qu'on vient de prononcer est une donnée.
     */
    public function testUnNomQuiNeResoutPasNeProduitAucuneChronologie(): void
    {
        $kibali = $this->clientNomme('Kibali Goldmines SA', 11);
        // Rien ne correspond à « Mbusa » : c'est l'état réel de la base.
        $search = $this->rechercheNommee([], [11 => $kibali]);

        $result = $this->makeTool($search)->execute(
            // Exactement ce que le modèle a envoyé : l'id du dossier précédent.
            ['entite' => 'Client', 'id' => 11, 'nom' => 'Mbusa Jean de Dieu'],
            $this->scope(),
        );

        $this->assertSame(AiToolResult::STATUS_OK, $result->status);
        $this->assertFalse($result->data['pret'], 'Un nom introuvable ne peut pas produire une chronologie.');
        $this->assertNotEmpty($result->data['aDemander']);
        $this->assertArrayNotHasKey('lignes', $result->data);
        $this->assertArrayNotHasKey('dossier', $result->data, 'Aucun dossier ne doit être retracé sous un nom introuvable.');
    }

    /**
     * Le nom prime AUSSI quand il résout — et vers un AUTRE dossier que l'identifiant
     * transporté. Sans cette règle, l'outil servirait poliment la chronologie du dossier
     * précédent chaque fois que l'utilisateur change de sujet.
     */
    public function testLeNomDicteLEmporteSurLIdentifiantReporte(): void
    {
        $kibali = $this->clientNomme('Kibali Goldmines SA', 11);
        $jeanDeDieu = $this->clientNomme('Mr. jean de dieu', 96);
        $search = $this->rechercheNommee([$jeanDeDieu], [11 => $kibali, 96 => $jeanDeDieu]);

        $result = $this->makeTool($search)->execute(
            ['entite' => 'Client', 'id' => 11, 'nom' => 'jean de dieu'],
            $this->scope(),
        );

        $this->assertSame(AiToolResult::STATUS_OK, $result->status);
        $this->assertStringContainsString('Mr. jean de dieu', $result->data['dossier']);
        $this->assertStringNotContainsString('Kibali', $result->data['dossier']);
    }

    /**
     * Le libellé qui FAIT FOI voyage dans le payload, et la consigne interdit d'y
     * substituer les mots de l'utilisateur. C'est la seconde moitié de l'incident : la
     * prose avait titré « M. Mbusa Jean de Dieu (rattaché à Kibali Goldmines SA) »,
     * promouvant le nom soufflé en sujet et reléguant le vrai entre parenthèses.
     */
    public function testLePayloadOrdonneDeNommerLeDossierMotPourMot(): void
    {
        $search = $this->rechercheNommee([], [42 => $this->client()]);

        $data = $this->makeTool($search)->execute(['entite' => 'Client', 'id' => 42], $this->scope())->data;

        $this->assertStringContainsString('MIC-RC', $data['dossier']);
        $this->assertStringContainsString('EXACTEMENT COMME', $data['note']);
        $this->assertStringContainsString('rattachée', $data['note']);
    }
}
