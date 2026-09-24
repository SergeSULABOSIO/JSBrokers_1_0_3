<?php

namespace App\Controller\Console;

use App\Ai\Fournisseur\EtatDesFournisseurs;
use App\Ai\Fournisseur\MemoireDEpuisement;
use App\Ai\Fournisseur\ModeleChoisi;
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

    /**
     * ENREGISTRE la politique. L'ÉCRAN, lui, est l'onglet « Fournisseurs » de la
     * configuration de Ket : un GET sur cette route y renvoie plutôt que de servir
     * une seconde page montrant le même formulaire.
     */
    #[Route('', name: 'index', methods: ['GET', 'POST'])]
    public function index(Request $request, LocaleSwitcher $localeSwitcher): Response
    {
        $this->applyLangPreference($request, $localeSwitcher);

        if (!$request->isMethod('POST')) {
            return $this->redirectToRoute('console.ket.reglages.index', ['onglet' => 'fournisseurs', '_fragment' => 'tab-fournisseurs']);
        }

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
                // UNE SAISIE DOUTEUSE EST REFUSÉE ICI, PAS PLUS TARD. Un nom de modèle
                // inventé vaut un refus du fournisseur à CHAQUE message : mieux vaut
                // que l'agent l'apprenne au moment où il enregistre que de le laisser
                // chercher ensuite pourquoi Ket ne répond plus. `ModeleChoisi` ignore
                // aussi ces valeurs au moment d'appeler — ceinture et bretelles, parce
                // qu'une politique peut aussi arriver d'un import ou d'un script.
                foreach (self::reglagesDouteux($decode) as $fautif) {
                    $form->addError(new FormError(sprintf(
                        'Réglage « %s » de %s : « %s » ne ressemble pas à un nom de modèle. '
                        . 'Lettres, chiffres, tiret, souligné, point et deux-points seulement — '
                        . 'par exemple claude-haiku-4-5. Laissez vide pour garder celui du serveur.',
                        $fautif['clef'],
                        $fautif['fournisseur'],
                        mb_substr($fautif['valeur'], 0, 60),
                    )));
                    $valide = false;
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

                return $this->redirectToRoute('console.ket.reglages.index', ['onglet' => 'fournisseurs', '_fragment' => 'tab-fournisseurs']);
            }
        }

        // SAISIE REFUSÉE : on RÉAFFICHE, on ne redirige pas. Une redirection perdrait
        // le JSON que l'agent vient d'écrire — parfois plusieurs lignes — et le
        // renverrait devant un formulaire vierge portant un simple message d'erreur.
        // L'écran fusionné rend le formulaire tel quel, erreurs comprises, et ouvre
        // l'onglet des fournisseurs.
        return $this->forward(KetReglagesController::class . '::index', [
            'formFournisseursInvalide' => $form,
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

        return $this->redirectToRoute('console.ket.reglages.index', ['onglet' => 'fournisseurs', '_fragment' => 'tab-fournisseurs']);
    }

    /**
     * LES RÉGLAGES QUI NE RESSEMBLENT PAS À UN NOM DE MODÈLE.
     *
     * On inspecte TOUTES les valeurs textuelles des réglages, sans présumer des
     * clés : `modele` aujourd'hui, `modeleLive`, `voix`, `modelesRepli` selon les
     * familles, et celles qu'un lot suivant ajoutera. Une liste séparée par des
     * virgules est acceptée si chacun de ses noms tient debout.
     *
     * @param array<string, mixed> $politique
     *
     * @return list<array{fournisseur: string, clef: string, valeur: string}>
     */
    private static function reglagesDouteux(array $politique): array
    {
        $fautifs = [];
        $reglages = $politique['reglages'] ?? [];
        if (!\is_array($reglages)) {
            return [];
        }

        foreach ($reglages as $fournisseur => $valeurs) {
            if (!\is_array($valeurs)) {
                continue;
            }
            foreach ($valeurs as $clef => $valeur) {
                if (!\is_string($valeur) || trim($valeur) === '') {
                    continue;
                }
                $noms = array_filter(array_map('trim', explode(',', $valeur)), static fn (string $n): bool => $n !== '');
                foreach ($noms as $nom) {
                    if (!ModeleChoisi::estPlausible($nom)) {
                        $fautifs[] = [
                            'fournisseur' => (string) $fournisseur,
                            'clef'        => (string) $clef,
                            'valeur'      => $valeur,
                        ];
                        continue 2;
                    }
                }
            }
        }

        return $fautifs;
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
