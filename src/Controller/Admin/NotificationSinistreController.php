<?php

namespace App\Controller\Admin;

use App\Entity\Invite;
use DateTimeImmutable;
use App\Entity\Avenant;
use App\Entity\Entreprise;
use App\Entity\Utilisateur;
use App\Constantes\Constante;
use App\Constantes\MenuActivator;
use App\Services\ServiceMonnaies;
use App\Entity\NotificationSinistre;
use App\Repository\InviteRepository;
use App\Form\NotificationSinistreType;
use App\Repository\EntrepriseRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use App\Repository\NotificationSinistreRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;


#[Route("/admin/notificationsinistre", name: 'admin.notificationsinistre.')]
#[IsGranted('ROLE_USER')]
class NotificationSinistreController extends AbstractController
{
    public MenuActivator $activator;
    /**
     * @var string[] Liste des entités autorisées pour la recherche.
     * Ajoutez ici toutes les entités que vous souhaitez rendre consultables.
     * Ceci est une mesure de sécurité pour empêcher l'interrogation de n'importe quelle table.
     */
    private array $allowedEntities = [
        'NotificationSinistre',
        // 'User',
        // 'Product',
    ];

    /**
     * @var string[] Liste des opérateurs de comparaison autorisés.
     * Ceci est une mesure de sécurité pour empêcher l'injection de code dans la requête.
     */
    private array $allowedOperators = ['=', '!=', '<', '<=', '>', '>=', 'LIKE'];


    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private EntityManagerInterface $em,
        private EntrepriseRepository $entrepriseRepository,
        private InviteRepository $inviteRepository,
        private NotificationSinistreRepository $notificationSinistreRepository,
        private Constante $constante,
        private ServiceMonnaies $serviceMonnaies,
    ) {
        $this->activator = new MenuActivator(MenuActivator::GROUPE_CLAIMS);
    }


    #[Route('/index/{idEntreprise}', name: 'index', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function index($idEntreprise, Request $request)
    {
        $page = $request->query->getInt("page", 1);

        /** @var Utilisateur $utilisateur */
        $utilisateur = $this->getUser();

        return $this->render('admin/notificationsinistre/index.html.twig', [
            'pageName' => $this->translator->trans("notificationsinistre_page_name_new"),
            'utilisateur' => $utilisateur,
            'entreprise' => $this->entrepriseRepository->find($idEntreprise),
            'notificationsinistres' => $this->notificationSinistreRepository->paginateForEntreprise($idEntreprise, $page),
            'page' => $page,
            'constante' => $this->constante,
            'serviceMonnaie' => $this->serviceMonnaies,
            'activator' => $this->activator,
            'numericAttributes' => $this->constante->getNumericAttributes(new NotificationSinistre()),
        ]);
    }


    #[Route('/reload/{idEntreprise}', name: 'reload', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function reload($idEntreprise, Request $request)
    {
        $page = $request->query->getInt("page", 1);

        /** @var Utilisateur $utilisateur */
        $utilisateur = $this->getUser();

        return $this->render('admin/notificationsinistre/donnees.html.twig', [
            'pageName' => $this->translator->trans("notificationsinistre_page_name_new"),
            'utilisateur' => $utilisateur,
            'entreprise' => $this->entrepriseRepository->find($idEntreprise),
            'notificationsinistres' => $this->notificationSinistreRepository->paginateForEntreprise($idEntreprise, $page),
            'page' => $page,
            'constante' => $this->constante,
            'serviceMonnaie' => $this->serviceMonnaies,
            'activator' => $this->activator,
        ]);
    }


    #[Route('/create/{idEntreprise}', name: 'create')]
    public function create($idEntreprise, Request $request)
    {
        /** @var Entreprise $entreprise */
        $entreprise = $this->entrepriseRepository->find($idEntreprise);

        /** @var Utilisateur $user */
        $user = $this->getUser();

        /** @var Invite $invite */
        $invite = $this->inviteRepository->findOneByEmail($user->getEmail());

        /** @var NotificationSinistre $notificationsinistre */
        $notificationsinistre = new NotificationSinistre();
        //Paramètres par défaut
        $notificationsinistre->setOccuredAt(new DateTimeImmutable("now"));
        $notificationsinistre->setNotifiedAt(new DateTimeImmutable("now"));
        $notificationsinistre->setInvite($invite);

        $form = $this->createForm(NotificationSinistreType::class, $notificationsinistre);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($notificationsinistre);
            $this->em->flush();

            return $this->getJsonData($notificationsinistre);
        }
        return $this->render('admin/notificationsinistre/create.html.twig', [
            'pageName' => $this->translator->trans("notificationsinistre_page_name_new"),
            'utilisateur' => $user,
            'notificationsinistre' => $notificationsinistre,
            'entreprise' => $entreprise,
            'activator' => $this->activator,
            'form' => $form,
        ]);
    }


    #[Route('/edit/{idEntreprise}/{idNotificationsinistre}', name: 'edit', requirements: ['idEntreprise' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function edit($idEntreprise, $idNotificationsinistre, Request $request)
    {
        /** @var Entreprise $entreprise */
        $entreprise = $this->entrepriseRepository->find($idEntreprise);

        /** @var Utilisateur $user */
        $user = $this->getUser();

        /** @var NotificationSinistre $notificationsinistre */
        $notificationsinistre = $this->notificationSinistreRepository->find($idNotificationsinistre);

        $form = $this->createForm(NotificationSinistreType::class, $notificationsinistre);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($notificationsinistre); //On peut ignorer cette instruction car la fonction flush suffit.
            $this->em->flush();

            //Le serveur renvoie un objet JSON
            return $this->getJsonData($notificationsinistre);
        }
        //On se dirie vers la page le formulaire d'édition
        return $this->render('admin/notificationsinistre/edit.html.twig', [
            'pageName' => $this->translator->trans("notificationsinistre_page_name_update", [
                ":notificationsinistre" => $notificationsinistre->getDescriptionDeFait(),
            ]),
            'utilisateur' => $user,
            'notificationsinistre' => $notificationsinistre,
            'entreprise' => $entreprise,
            'activator' => $this->activator,
            'form' => $form,
        ]);
    }


    #[Route('/formulaire/{idEntreprise}/{idNotificationsinistre}', name: 'formulaire')]
    public function formulaire($idEntreprise, $idNotificationsinistre, Request $request)
    {
        /** @var Entreprise $entreprise */
        $entreprise = $this->entrepriseRepository->find($idEntreprise);

        /** @var Utilisateur $user */
        $user = $this->getUser();

        /** @var Invite $invite */
        $invite = $this->inviteRepository->findOneByEmail($user->getEmail());

        //Paramètres par défaut
        /** @var NotificationSinistre $notificationsinistre */
        $notificationsinistre = new NotificationSinistre();
        if ($idNotificationsinistre != -1) {
            $notificationsinistre = $this->notificationSinistreRepository->find($idNotificationsinistre);
        } else {
            $notificationsinistre->setCreatedAt(new DateTimeImmutable("now"));
            $notificationsinistre->setUpdatedAt(new DateTimeImmutable("now"));
            $notificationsinistre->setNotifiedAt(new DateTimeImmutable("now"));
            $notificationsinistre->setOccuredAt(new DateTimeImmutable("now"));
            $notificationsinistre->setInvite($invite);
        }
        // dd($notificationsinistre, $idEntreprise, $idNotificationsinistre);

        $form = $this->createForm(NotificationSinistreType::class, $notificationsinistre);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // dd("Ici");

            $avenants = $this->constante->Entreprise_getAvenantsByReference($notificationsinistre->getReferencePolice());
            if (count($avenants) != 0) {
                /** @var Avenant $ave */
                $ave = $avenants[0];
                if ($ave->getCotation() != null) {
                    $notificationsinistre->setAssureur($ave->getCotation()->getAssureur());
                    $notificationsinistre->setAssure($ave->getCotation()->getPiste()->getClient());
                    $notificationsinistre->setRisque($ave->getCotation()->getPiste()->getRisque());
                }
            }

            $this->em->persist($notificationsinistre); //On peut ignorer cette instruction car la fonction flush suffit.
            $this->em->flush();
            //Le serveur renvoie un objet JSON
            return $this->getJsonData($notificationsinistre);
        }
        //On se dirie vers la page le formulaire d'édition
        return $this->render('admin/notificationsinistre/form.html.twig', [
            'utilisateur' => $user,
            'notificationsinistre' => $notificationsinistre,
            'entreprise' => $entreprise,
            'activator' => $this->activator,
            'form' => $form,
        ]);
    }

    private function getJsonData(?NotificationSinistre $notification)
    {
        return $this->json(json_encode([
            "reponse" => "Ok",
            "idNotificationSinistre" => $notification->getId(),
            "referencePolice" => $notification->getReferencePolice(),
            "nbOffres" => count($notification->getOffreIndemnisationSinistres()),
            "nbContacts" => count($notification->getContacts()),
            "nbTaches" => count($notification->getTaches()),
            "nbDocuments" => count($notification->getPieces()),
        ]));
    }

    #[Route('/remove/{idEntreprise}/{idNotificationsinistre}', name: 'remove', requirements: ['idNotificationsinistre' => Requirement::DIGITS, 'idEntreprise' => Requirement::DIGITS])]
    public function remove($idEntreprise, $idNotificationsinistre, Request $request)
    {
        /** @var NotificationSinistre $notificationsinistre */
        $notificationsinistre = $this->notificationSinistreRepository->find($idNotificationsinistre);

        $this->em->remove($notificationsinistre);
        $this->em->flush();

        return $this->json(json_encode([
            "reponse" => "Ok",
        ]));
    }

    #[Route('/remove_many/{idEntreprise}/{tabIDString}', name: 'remove_many', requirements: ['idEntreprise' => Requirement::DIGITS])]
    public function remove_many($idEntreprise, $tabIDString, Request $request)
    {
        try {
            $deletedIDs = [];
            if (null === $tabIDString || empty($tabIDString)) {
                return $this->json(json_encode([
                    "reponse" => "Ok",
                    "message" => "Aucun tableau n'a été envoyé au serveur",
                    "deletedIds" => $deletedIDs,
                ]));
            }
            $idsTab = explode(',', $tabIDString); //On explose cette chaine en tableau.
            $idsTab = array_filter($idsTab, 'is_numeric'); //On prends que les cellules dont les valeurs sont numérique
            $idsTab = array_map('intval', $idsTab); //On prends les value entière uniquement.

            foreach ($idsTab as $id) {
                /** @var NotificationSinistre $notification */
                $notification = $this->notificationSinistreRepository->find($id);
                if ($notification != null) {
                    $this->em->remove($notification);
                    $this->em->flush();
                }
                $notification = $this->notificationSinistreRepository->find($id);
                if ($notification == null) {
                    $deletedIDs[] = $id;
                }
            }
            return $this->json(json_encode([
                "reponse" => "Ok",
                "message" => count($deletedIDs) . " éléments ont été supprimés.",
                "deletedIds" => $deletedIDs,
            ]));
        } catch (\Throwable $th) {
            return $this->json(json_encode([
                "reponse" => "Erreur",
                "message" => $th->getMessage(),
                "deletedIds" => $deletedIDs,
            ]));
        }
    }

    #[Route('/getlistelementdetails/{idEntreprise}/{idNotificationsinistre}', name: 'getlistelementdetails')]
    public function getlistelementdetails($idEntreprise, $idNotificationsinistre, Request $request)
    {
        /** @var Entreprise $entreprise */
        $entreprise = $this->entrepriseRepository->find($idEntreprise);

        /** @var Utilisateur $user */
        $user = $this->getUser();

        /** @var Invite $invite */
        $invite = $this->inviteRepository->findOneByEmail($user->getEmail());

        /** @var NotificationSinistre $notificationsinistre */
        $notificationsinistre = $this->notificationSinistreRepository->find($idNotificationsinistre);

        //On se dirie vers la page le formulaire d'édition
        return $this->render('admin/notificationsinistre/elementview.html.twig', [
            'notificationsinistre' => $notificationsinistre,
            'entreprise' => $entreprise,
            'utilisateur' => $user,
            'constante' => $this->constante,
            'serviceMonnaie' => $this->serviceMonnaies,
        ]);
    }



    #[Route('/sales-demo', name: 'app_sales_demo')]
    public function salesDemo(): Response
    {
        // 1. Définir les attributs numériques et leurs libellés ici
        $numericAttributes = [
            "amount-invoiced" => "Montant Facturé",
            'amount-paid' => "Montant Payé",
            "balance" => "Solde Restant",
        ];

        // Génération de 10 ventes de démonstration
        $sales = [];
        $clients = ['TechCorp', 'Innovate SARL', 'Digital Solutions', 'Global Systems'];
        $items = ['Laptop Pro X', 'Écran 4K Ultra', 'Souris Gamer RGB', 'Clavier Mécanique'];

        for ($i = 1; in_array($i, range(1, 10)); $i++) {
            $invoiced = rand(500, 2000) * 100; // en centimes
            $paid = rand(0, $invoiced);
            $balance = $invoiced - $paid;

            // 2. Créer le nouvel attribut `numericValues` pour chaque vente
            $numericValues = [
                'amount-invoiced' => $invoiced,
                'amount-paid' => $paid,
                'balance' => $balance,
            ];

            $sales[] = [
                'id' => 'sale-' . $i,
                'clientName' => $clients[array_rand($clients)],
                'saleDate' => (new \DateTime())->modify('-' . rand(1, 30) . ' days'),
                'itemName' => $items[array_rand($items)],
                'amountInvoiced' => $invoiced,
                'amountPaid' => $paid,
                'balance' => $invoiced - $paid,
                'numericValues' => $numericValues, // Ajout du tableau associatif
            ];
        }

        return $this->render('pages/sales_demo.html.twig', [
            'sales' => $sales,
            'numericAttributes' => $numericAttributes,
        ]);
    }

    #[Route('/api/dynamic-query', name: 'app_dynamic_query', methods: ['POST'])]
    public function query(Request $request): JsonResponse
    {
        // 1. Récupérer et décoder les données de la requête POST
        $data = json_decode($request->getContent(), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new JsonResponse(['error' => 'Invalid JSON format'], 400);
        }

        $entityName = $data['entityName'] ?? null;
        $criteria = $data['criteria'] ?? null;

        // 2. Valider les données d'entrée
        if (!$entityName || !$criteria || !is_array($criteria)) {
            return new JsonResponse(['error' => 'Missing or invalid parameters: entityName and criteria are required.'], 400);
        }

        // 3. Vérifier si l'entité est autorisée
        if (!in_array($entityName, $this->allowedEntities, true)) {
            return new JsonResponse(['error' => "Querying the entity '{$entityName}' is not allowed."], 403);
        }

        try {
            // 4. Construire la requête avec QueryBuilder
            $repository = $this->em->getRepository('App\\Entity\\' . $entityName);
            $qb = $repository->createQueryBuilder('e'); // 'e' est l'alias de notre entité

            $parameterIndex = 0; // Pour garantir des noms de paramètres uniques

            foreach ($criteria as $field => $value) {
                // Le nom du champ doit correspondre à une propriété de l'entité (ex: 'dommageEvalue')
                $parameterName = 'param' . $parameterIndex++;

                if (is_array($value) && isset($value['operator'], $value['value'])) {
                    // Cas d'un critère numérique/complexe (ex: >= 100)
                    $operator = strtoupper($value['operator']);
                    
                    // Sécurité : valider l'opérateur
                    if (!in_array($operator, $this->allowedOperators, true)) {
                        return new JsonResponse(['error' => "Operator '{$operator}' is not allowed."], 400);
                    }
                    
                    $qb->andWhere($qb->expr()->comparison('e.' . $field, $operator, ':' . $parameterName))
                       ->setParameter($parameterName, $value['value']);

                } else {
                    // Cas d'un critère simple (chaîne de caractères)
                    // On utilise LIKE pour une recherche plus flexible.
                    $qb->andWhere($qb->expr()->like('e.' . $field, ':' . $parameterName))
                       ->setParameter($parameterName, '%' . $value . '%');
                }
            }
            
            // 5. Exécuter la requête et récupérer les résultats
            $results = $qb->getQuery()->getArrayResult();

            return new JsonResponse(['data' => $results]);

        } catch (\Doctrine\ORM\Mapping\MappingException $e) {
            // L'entité ou le champ n'existe pas
            return new JsonResponse(['error' => "Invalid entity or field name provided.", 'details' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            // Autre erreur inattendue
            return new JsonResponse(['error' => 'An unexpected error occurred.', 'details' => $e->getMessage()], 500);
        }
    }
}
