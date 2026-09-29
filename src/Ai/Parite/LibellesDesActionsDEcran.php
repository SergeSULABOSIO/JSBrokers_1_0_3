<?php

namespace App\Ai\Parite;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * LE NOM QUE LE COURTIER VOIT SUR LE BOUTON.
 *
 * ── POURQUOI CE LECTEUR EXISTE ──────────────────────────────────────────────
 * {@see CouvertureDesEcrans} indexe les actions d'écran par leur `event` — le nom
 * technique que le cerveau route, `ui:soa.revoke-request`. C'est la bonne clé pour
 * un manifeste : elle est stable, et elle ne ment pas. Mais elle ne veut rien dire
 * pour un courtier. Lui dire « l'action ui:soa.revoke-request reste à l'écran »
 * serait aussi inutile que ne rien dire.
 *
 * Or le libellé existe déjà, à côté de l'`event`, dans la déclaration même :
 *
 *     ["label" => "Révoquer le lien du SOA", "icon" => "action:disable",
 *      "event" => "ui:soa.revoke-request", …]
 *
 * Il n'y a donc RIEN à recopier à la main : on relève les couples là où ils sont,
 * dans les mêmes fichiers que le test de parité parcourt déjà.
 *
 * ── POURQUOI UNE LECTURE STATIQUE, ET PAS UNE INTROSPECTION ─────────────────
 * Construire un canevas demande une entité hydratée et tout son contexte, et
 * certaines actions sont conditionnées à un attribut calculé. Le fichier, lui, dit
 * sans condition ce que le développeur a écrit — et c'est de cela qu'on veut tenir
 * l'inventaire. Même raison, même méthode que `PariteEcranKetTest`, dont la liste
 * de sources est désormais CELLE-CI : deux listes auraient fini par diverger, et
 * l'inventaire aurait alors ignoré un fichier que le test surveille encore.
 */
final class LibellesDesActionsDEcran
{
    /**
     * Où vivent les déclarations d'`attribute_actions`.
     *
     * ⚠ CETTE LISTE EST PARTAGÉE avec `PariteEcranKetTest`. Y ajouter un fichier
     * étend du même geste le test de parité ET l'inventaire que Ket récite.
     */
    public const SOURCES = [
        'src/Services/Canvas/Provider/Form',
        'src/Services/Canvas/FormCanvasProvider.php',
        'src/Entity/Avenant.php',
        'src/Entity/Client.php',
        'src/Entity/Invite.php',
        'src/Controller/Admin/ProductionIntermediaireController.php',
        // L'atelier de rapprochement des bordereaux déclare ses propres gestes de
        // ligne (créer / corriger la police d'une ligne) au même format `event`.
        'src/Controller/Admin/BordereauController.php',
    ];

    /** @var array<string, list<string>>|null relevé mémoïsé : le worker vit */
    private ?array $cache = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {
    }

    /**
     * Les libellés d'écran de chaque action, dans leur ordre de déclaration.
     *
     * Une action peut en porter plusieurs : « Visualiser la note », « Imprimer » et
     * « Télécharger en PDF » partagent toutes `ui:note.preview-request`, en ne
     * variant que par leur URL. Les rendre toutes est plus honnête que d'en élire
     * une : c'est bien trois boutons que le courtier voit.
     *
     * @return array<string, list<string>> event => libellés
     */
    public function parEvenement(): array
    {
        return $this->cache ??= $this->relever();
    }

    /**
     * Le libellé à dire pour cette action — le premier déclaré.
     *
     * ⚠ LE REPLI SUR LE NOM TECHNIQUE NE DOIT JAMAIS SERVIR, et un test l'exige :
     * aujourd'hui les 34 actions du manifeste portent toutes un libellé. Il est là
     * pour qu'une déclaration mal formée dégrade la phrase au lieu de casser
     * l'inventaire — jamais pour qu'on invente un nom de bouton qui n'existe pas.
     */
    public function pour(string $evenement): string
    {
        return $this->parEvenement()[$evenement][0] ?? $evenement;
    }

    /**
     * @return array<string, list<string>>
     */
    private function relever(): array
    {
        $parEvenement = [];

        foreach ($this->fichiers() as $fichier) {
            $code = (string) @file_get_contents($fichier);
            if ($code === '') {
                continue;
            }

            // DEUX RELEVÉS, ET UN APPARIEMENT PAR POSITION. Les clés `label` et `event`
            // ne se suivent pas toujours — `icon`, `groupe` ou `url` s'intercalent —
            // mais dans tout le projet le libellé PRÉCÈDE l'événement de sa propre
            // entrée. On retient donc, pour chaque `event`, le dernier `label` déclaré
            // avant lui. Les deux styles de guillemets cohabitent : n'en lire qu'un
            // laisserait un tiers de l'inventaire dehors.
            $motif = '/["\'](label|event)["\']\s*=>\s*(["\'])((?:[^\\\\]|\\\\.)*?)\2/';
            preg_match_all($motif, $code, $trouves, PREG_SET_ORDER);

            $dernierLibelle = null;
            foreach ($trouves as $trouve) {
                // `stripcslashes` : sans lui, « Créer l\'avenant » s'affiche « Créer l\ ».
                $valeur = stripcslashes($trouve[3]);
                if ($trouve[1] === 'label') {
                    $dernierLibelle = $valeur;
                    continue;
                }
                if ($dernierLibelle === null) {
                    continue; // un `event` sans aucun `label` avant lui : cf. SANS_LIBELLE_PROPRE.
                }
                if (!in_array($dernierLibelle, $parEvenement[$valeur] ?? [], true)) {
                    $parEvenement[$valeur][] = $dernierLibelle;
                }
            }
        }

        ksort($parEvenement);

        return $parEvenement;
    }

    /** @return list<string> */
    private function fichiers(): array
    {
        $fichiers = [];
        foreach (self::SOURCES as $source) {
            $chemin = $this->projectDir . '/' . $source;
            if (is_dir($chemin)) {
                foreach (glob($chemin . '/*.php') ?: [] as $trouve) {
                    $fichiers[] = $trouve;
                }
            } elseif (is_file($chemin)) {
                $fichiers[] = $chemin;
            }
        }

        return $fichiers;
    }
}
