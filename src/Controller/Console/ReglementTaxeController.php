<?php

namespace App\Controller\Console;

use App\Comptabilite\SuiviFiscalService;
use App\Entity\ReglementTaxe;
use App\Form\ReglementTaxeType;
use App\Repository\ReglementTaxeRepository;
use App\Repository\TaxeVenteRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * Reversements de TVA à l'autorité fiscale (saisie + suppression). Le suivi
 * (montant dû / payé / solde) est affiché dans la page Fiscalité ; chaque
 * reversement génère une écriture comptable (cf. EcritureComptableService).
 */
#[Route('/console/taxes/reversements', name: 'console.reglement_taxe.')]
#[IsGranted('ROLE_ADMIN')]
class ReglementTaxeController extends AbstractConsoleController
{
    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        LocaleSwitcher $localeSwitcher,
        TaxeVenteRepository $taxeVenteRepository,
        SuiviFiscalService $suiviFiscal,
        ReglementTaxeRepository $reglementRepository,
    ): Response {
        $this->applyLangPreference($request, $localeSwitcher);

        $now = new \DateTimeImmutable();
        $reglement = (new ReglementTaxe())
            ->setAnnee((int) ($request->query->get('exercice') ?: $now->format('Y')))
            ->setMois((int) $now->format('n'))
            ->setDatePaiement($now);

        // Pré-remplissage de l'autorité depuis une taxe active (la plus courante).
        $taxes = $taxeVenteRepository->findActives();
        if ($taxes !== []) {
            $t = $taxes[0];
            $reglement->setAutorite(trim($t->getAutoriteNom() . ' (' . $t->getAutoriteAbreviation() . ')'));
        }

        // Pré-remplissage du montant = TVA nette restant à déclarer pour la période par défaut.
        $resteInitial = $this->resteADeclarer($suiviFiscal, $reglementRepository, $reglement->getAnnee(), $reglement->getMois());
        $reglement->setMontant(number_format(max(0.0, $resteInitial['collectee'] - $resteInitial['deductible']), 2, '.', ''));

        $form = $this->createForm(ReglementTaxeType::class, $reglement);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Photo (figée) de la TVA de la période RESTANT à déclarer, d'après le
            // mois/année soumis — base de l'écriture comptable détaillée.
            $reste = $this->resteADeclarer($suiviFiscal, $reglementRepository, $reglement->getAnnee(), $reglement->getMois());
            $reglement->setTvaCollectee(number_format($reste['collectee'], 2, '.', ''));
            $reglement->setTvaDeductible(number_format($reste['deductible'], 2, '.', ''));

            $this->em->persist($reglement);
            $this->em->flush();

            $this->addFlash('success', sprintf('Reversement de TVA (%s) enregistré.', $reglement->getPeriodeLabel()));

            return $this->redirectToRoute('console.taxe.index', ['exercice' => $reglement->getAnnee()]);
        }

        return $this->render('console/form.html.twig', [
            'pageName'    => 'Enregistrer un reversement de TVA',
            'form'        => $form,
            'backUrl'     => $this->generateUrl('console.taxe.index'),
            'backLabel'   => 'Fiscalité',
            'submitLabel' => 'Enregistrer le reversement',
            'description' => 'Saisissez un paiement de TVA nette effectué à l\'autorité fiscale pour une période '
                . 'donnée. Le solde dû de la Fiscalité est mis à jour et une écriture comptable est générée '
                . '(D 443 État, TVA facturée / C trésorerie).',
            'formIcon'    => 'taxe',
        ]);
    }

    /**
     * TVA collectée/déductible d'une période RESTANT à déclarer = totaux de la
     * période moins ce que les reversements antérieurs de la même période ont déjà
     * figé (évite tout double comptage en cas de paiements multiples).
     *
     * @return array{collectee: float, deductible: float}
     */
    private function resteADeclarer(SuiviFiscalService $suiviFiscal, ReglementTaxeRepository $reglementRepository, int $annee, int $mois): array
    {
        $ligne = $suiviFiscal->suivi($annee)['lignes'][$mois - 1] ?? ['collectee' => 0.0, 'deductible' => 0.0];
        $deja = $reglementRepository->sommeSnapshotsPeriode($annee, $mois);

        return [
            'collectee'  => max(0.0, round($ligne['collectee'] - $deja['collectee'], 2)),
            'deductible' => max(0.0, round($ligne['deductible'] - $deja['deductible'], 2)),
        ];
    }

    #[Route('/{id}', name: 'delete', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    public function delete(ReglementTaxe $reglement, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('delete-reglement-' . $reglement->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $annee = $reglement->getAnnee();
        $periode = $reglement->getPeriodeLabel();
        $this->em->remove($reglement);
        $this->em->flush();

        $this->addFlash('success', sprintf('Reversement de TVA (%s) supprimé.', $periode));

        return $this->redirectToRoute('console.taxe.index', ['exercice' => $annee]);
    }
}
