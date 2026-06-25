<?php

namespace App\Controller\Console;

use App\Comptabilite\ComptaExportService;
use App\Comptabilite\EcritureComptableService;
use App\Form\ParametresComptablesType;
use App\Repository\PlateformeParametresRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * Documents comptables de JS Brokers (journal, grand livre, balance, compte de
 * résultat, tableau de formation du résultat, bilan comparatif, flux de trésorerie),
 * générés à la volée depuis les ventes et dépenses (cf. EcritureComptableService).
 * Une seule rubrique avec sélecteur d'exercice et sous-onglets ; export Excel par
 * document ou classeur complet. Saisie des paramètres comptables (capital social)
 * réservée au super-admin.
 */
#[Route('/console/documents', name: 'console.document.')]
#[IsGranted('ROLE_ADMIN')]
class DocumentComptableController extends AbstractConsoleController
{
    public function __construct(
        private EcritureComptableService $ecritures,
        private ComptaExportService $export,
    ) {
    }

    #[Route('', name: 'index')]
    public function index(Request $request, LocaleSwitcher $localeSwitcher): Response
    {
        $this->applyLangPreference($request, $localeSwitcher);

        $exercices = $this->ecritures->exercicesDisponibles();
        $exercice  = $request->query->getInt('exercice') ?: $exercices[0];

        $doc = $request->query->get('doc', 'journal');
        if (!isset(ComptaExportService::DOCUMENTS[$doc])) {
            $doc = 'journal';
        }

        return $this->render('console/document/index.html.twig', [
            'pageName'  => 'Documents comptables',
            'pageIcon'  => 'document',
            'documents' => $this->ecritures->documents($exercice),
            'docs'      => ComptaExportService::DOCUMENTS,
            'docActif'  => $doc,
            'exercice'  => $exercice,
            'exercices' => $exercices,
        ]);
    }

    #[Route('/export', name: 'export')]
    public function exportXlsx(Request $request): Response
    {
        $exercices = $this->ecritures->exercicesDisponibles();
        $exercice  = $request->query->getInt('exercice') ?: $exercices[0];
        $doc       = $request->query->get('doc', 'journal');

        return $this->export->export($doc, $exercice);
    }

    #[Route('/parametres', name: 'parametres', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function parametres(Request $request, LocaleSwitcher $localeSwitcher, PlateformeParametresRepository $repository): Response
    {
        $this->applyLangPreference($request, $localeSwitcher);

        $params = $repository->getSingleton();
        $form = $this->createForm(ParametresComptablesType::class, $params);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->flush();
            $this->addFlash('success', 'Paramètres comptables enregistrés.');

            return $this->redirectToRoute('console.document.index');
        }

        return $this->render('console/form.html.twig', [
            'pageName'    => 'Paramètres comptables',
            'form'        => $form,
            'backUrl'     => $this->generateUrl('console.document.index'),
            'backLabel'   => 'Documents comptables',
            'submitLabel' => 'Enregistrer',
            'description' => 'Renseignez le capital social (apport des actionnaires) et la date de '
                . 'constitution de JS Brokers. Ces données alimentent l\'écriture d\'ouverture du bilan.',
            'formIcon'    => 'entreprise',
        ]);
    }
}
