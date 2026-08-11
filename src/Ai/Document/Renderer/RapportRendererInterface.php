<?php

namespace App\Ai\Document\Renderer;

use App\Ai\Document\DocumentFormat;
use App\Ai\Document\PiedDePage;
use App\Ai\Document\RapportSpec;
use App\Ai\Document\ThemeDocument;

/**
 * Un rendu de rapport, pour un format.
 *
 * Les implémentations sont taguées `app.ai_document_renderer` (via `_instanceof`
 * dans config/services.yaml) et retrouvées par {@see RapportRendererResolver} :
 * ajouter un format se fait en ajoutant une classe, jamais en modifiant une liste.
 *
 * Toutes reçoivent les MÊMES entrées — la spec, ses sections déjà aplaties en blocs
 * par RapportAssembleur, et le pied de page posé par le serveur — et rendent des
 * OCTETS. Aucune ne parle de HTTP, de fichier temporaire ni de tokens : ce sont des
 * fonctions de mise en forme, et rien d'autre.
 */
interface RapportRendererInterface
{
    public function format(): DocumentFormat;

    /**
     * Le THÈME est reçu par TOUS, honoré par ceux qui peuvent : un .txt n'a pas de
     * couleurs, un .docx pas de fond de page. Le passer quand même plutôt que de
     * scinder l'interface garde une seule signature — et
     * {@see DocumentFormat::supporteTheme()} dit d'avance, à l'utilisateur comme au
     * code appelant, qui l'honore. Aucun rendu ne fait donc semblant.
     *
     * @param list<array{titre: string, blocs: list<array<string, mixed>>}> $sections
     *
     * @return string les octets du fichier
     */
    public function rendre(RapportSpec $spec, array $sections, PiedDePage $pied, ThemeDocument $theme): string;
}
