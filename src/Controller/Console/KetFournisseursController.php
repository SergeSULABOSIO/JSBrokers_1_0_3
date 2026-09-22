<?php

namespace App\Controller\Console;

use App\Ai\Fournisseur\EtatDesFournisseurs;
use App\Ai\Fournisseur\MemoireDEpuisement;
use App\Ai\Fournisseur\PolitiqueDesFournisseurs;
use App\Form\KetFournisseursType;
use App\Repository\PlateformeParametresRepository;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * LES FOURNISSEURS DE KET, pilotés par les agents Joseara.
 *
 * Cinq familles — moteur de texte, compréhension, dictée, voix, oreilles —, et
 * pour chacune : qui répond, dans quel ordre, avec quel modèle. Tout cela vivait
 * dans le `.env` : changer de voix demandait un accès serveur et un redémarrage.
 *
 * CE QUE CET ÉCRAN NE FAIT PAS. Il ne touche à aucune clé d'API. Elles restent en
 * `.env`, comme tous les secrets de ce projet — aucun n'a jamais été stocké en
 * base, et les sauvegardes n'ont pas à le devenir. L'écran affiche seulement si
 * la clé est présente, ce qui suffit à comprendre pourquoi un fournisseur ne
 * répond pas.
 *
 * SUPER-ADMIN, comme le plan tarifaire et les paramètres CRM : ce réglage décide
 * de ce que la plateforme dépense et de la qualité de ses réponses.
 */
#[Route('/console/ket/fournisseurs', name: 'console.ket.fournisseurs.')]
#[IsGranted('ROLE_SUPER_ADMIN')]
class KetFournisseursController extends AbstractConsoleController
{
    public function __construct(
        private PlateformeParametresRepository $repository,
        private PolitiqueDesFournisseurs $politique,
        private EtatDesFournisseurs $etat,
        private MemoireDEpuisement $epuisement,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET', 'POST'])]
    public function index(Request $request, LocaleSwitcher $localeSwitcher): Response
    {
        $this->applyLangPreference($request, $localeSwitcher);
        $singleton = $this->repository->getSingleton();

        $form = $this->createForm(KetFournisseursType::class, null, [
            'politique' => $this->politique->tout(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // ATOMICITÉ : les cinq familles sont décodées AVANT le moindre
            // enregistrement. Un JSON cassé sur une seule d'entre elles ne doit rien
            // écrire du tout — enregistrer les quatre autres laisserait l'agent
            // devant un écran à moitié pris en compte, sans savoir laquelle manque.
            // Même règle que PlanTarifaireController.
            $familles = [];
            $valide = true;
            foreach (PolitiqueDesFournisseurs::FAMILLES as $famille) {
                $decode = self::decoder((string) $form->get($famille . 'Json')->getData());
                if ($decode === false) {
                    $form->addError(new FormError(sprintf('JSON invalide pour « %s ».', $famille)));
                    $valide = false;
                    continue;
                }
                if ($decode !== []) {
                    $familles[$famille] = $decode;
                }
            }

            if ($valide) {
                $singleton->setKetFournisseurs($familles);
                $this->em->flush();
                // L'unique mécanisme d'invalidation du projet : sans lui, l'écran se
                // réaffiche avec la politique d'AVANT l'enregistrement.
                $this->politique->refresh();
                $this->addFlash('success', 'Politique des fournisseurs enregistrée.');

                return $this->redirectToRoute('console.ket.fournisseurs.index');
            }
        }

        return $this->render('console/ket_fournisseurs/form.html.twig', [
            'pageName'    => 'Ket — Fournisseurs',
            'formIcon'    => 'action:settings',
            'form'        => $form,
            'backUrl'     => $this->generateUrl('console.dashboard'),
            'backLabel'   => 'Console',
            'submitLabel' => 'Enregistrer la politique',
            'description' => 'Qui répond pour Ket, dans quel ordre, avec quel modèle. '
                . 'Les clés d’API restent dans la configuration du serveur : cet écran n’affiche que leur présence.',
            'etat'        => $this->etat->tout(),
        ]);
    }

    /**
     * RÉARMER un fournisseur marqué à sec avant son échéance.
     *
     * Sans ce geste, une marque erronée — un 429 mal interprété, une clé changée
     * entre-temps — met un fournisseur hors jeu jusqu'à minuit heure du Pacifique,
     * et la seule issue serait un accès serveur. C'est exactement le genre
     * d'incident de support qu'un bouton supprime.
     */
    #[Route('/rearmer', name: 'rearmer', methods: ['POST'])]
    public function rearmer(Request $request): Response
    {
        $cle = trim((string) $request->request->get('cle'));
        if ($cle !== '' && $this->isCsrfTokenValid('ket_rearmer', (string) $request->request->get('_token'))) {
            $this->epuisement->oublier($cle);
            $this->addFlash('success', sprintf('« %s » est de nouveau interrogeable.', $cle));
        }

        return $this->redirectToRoute('console.ket.fournisseurs.index');
    }

    /**
     * Décode une famille. Rend false — et non un tableau vide — quand le JSON est
     * illisible : la différence entre « rien de personnalisé » et « je n'ai pas su
     * lire » doit remonter jusqu'à l'agent, pas être avalée.
     *
     * @return array<string, mixed>|false
     */
    private static function decoder(string $json): array|false
    {
        $json = trim($json);
        if ($json === '') {
            return [];
        }

        $decode = json_decode($json, true);

        return \is_array($decode) ? $decode : false;
    }
}
