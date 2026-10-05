<?php

namespace App\Services\Canvas\Autocomplete;

use App\Services\ServiceNombres;

/**
 * LE RENDU D'UNE OPTION D'AUTOCOMPLÉTION — ÉCRIT UNE FOIS, POUR DIX-HUIT CHAMPS.
 *
 * ── LE DÉFAUT QU'IL REFERME ─────────────────────────────────────────────────────
 * Le même squelette HTML était copié dans dix-huit fichiers. Huit construisaient une
 * grille de tuiles chiffrées, dix répétaient le même `<div><strong>` en style inline.
 * Chacun écrivait son propre `number_format($v, 2, ',', ' ')` — un format FRANÇAIS en
 * dur, dans une application bilingue.
 *
 * Et chacun écrivait ses propres `?? 'N/A'`. Un assureur sans contact affichait donc
 * `Email: N/A | Tél: N/A` : deux mots pour dire qu'il n'y a rien à dire.
 *
 * ── LA RÈGLE, EN UNE PHRASE ─────────────────────────────────────────────────────
 * Ce qui n'a rien à dire ne s'affiche pas — et son libellé non plus.
 *
 * ── CE QUE L'APPELANT N'A PLUS À SAVOIR ─────────────────────────────────────────
 * Le balisage, les classes, l'échappement, la troncature, le format des nombres, la
 * langue active, et le sort des placeholders hérités. Il déclare ce qu'il veut montrer :
 *
 *     return $this->rendu->libelle(
 *         titre:    $assureur->getNom(),
 *         contact:  [$assureur->getEmail(), $assureur->getTelephone()],
 *         chiffres: [
 *             Chiffre::montant('Prime',     $assureur->primeTotale ?? null),
 *             Chiffre::montant('Comm. TTC', $assureur->montantTTC ?? null),
 *             Chiffre::solde('Comm. due',   $assureur->solde_restant_du ?? null),
 *         ],
 *     );
 *
 * ── TROIS CHIFFRES, PAS CINQ ────────────────────────────────────────────────────
 * Une liste d'autocomplétion sert à CHOISIR. Trois chiffres — ce qui est placé, ce qui
 * est rentré, ce qui reste — suffisent à départager deux lignes ; cinq obligent à lire
 * avant de choisir, ce qui est l'inverse du but. Le plafond est tenu par le service, et
 * non laissé à la discipline de dix-huit appelants.
 */
class RenduOptionAutocomplete
{
    /** Ce qu'un champ vide valait avant ce service : des mots pour dire « rien ». */
    private const PLACEHEURS_HERITES = ['N/A', 'n/a', '-', '—', '--', '?', 'Non défini', 'Non defini'];

    /** Au-delà, on ne choisit plus : on lit. */
    private const CHIFFRES_MAX = 3;

    /** Un contexte plus long déborde du champ avant d'avoir rien appris à personne. */
    private const CONTEXTE_MAX = 80;

    public function __construct(private ServiceNombres $nombres)
    {
    }

    /**
     * Le libellé HTML d'une option.
     *
     * @param string|null             $titre    le nom, seule partie obligatoire
     * @param list<string|null>       $contact  e-mail, téléphone, référence… — les vides sautent
     * @param list<Chiffre>           $chiffres l'aide au choix ; au plus trois sont retenus
     * @param string|null             $suffixe  une précision accolée au titre (une référence)
     */
    public function libelle(
        ?string $titre,
        array $contact = [],
        array $chiffres = [],
        ?string $suffixe = null,
    ): string {
        $lignes = [$this->ligneDeTitre($titre, $suffixe)];

        $ligneContact = $this->ligneDeContact($contact);
        if ($ligneContact !== null) {
            $lignes[] = $ligneContact;
        }

        $ligneChiffres = $this->ligneDeChiffres($chiffres);
        if ($ligneChiffres !== null) {
            $lignes[] = $ligneChiffres;
        }

        return '<div class="jsb-autocomplete-item">' . implode('', $lignes) . '</div>';
    }

    private function ligneDeTitre(?string $titre, ?string $suffixe): string
    {
        $html = $this->proteger($this->nettoyer($titre) ?? 'Sans nom');

        $suffixeNet = $this->nettoyer($suffixe);
        if ($suffixeNet !== null) {
            $html .= '<span class="jsb-autocomplete-title-suffix">' . $this->proteger($suffixeNet) . '</span>';
        }

        return '<div class="jsb-autocomplete-title">' . $html . '</div>';
    }

    /**
     * La ligne de contact — ou RIEN DU TOUT si personne n'a de contact.
     *
     * C'est ici que meurent les `Email: N/A | Tél: N/A`. Et le séparateur ne s'écrit
     * qu'ENTRE deux éléments présents : un séparateur orphelin est une ponctuation qui
     * annonce une information absente.
     */
    private function ligneDeContact(array $contact): ?string
    {
        $presents = [];
        foreach ($contact as $element) {
            $net = $this->nettoyer(\is_string($element) ? $element : null);
            if ($net !== null) {
                $presents[] = $this->proteger($this->tronquer($net));
            }
        }

        if ($presents === []) {
            return null;
        }

        $separateur = '<span class="jsb-context-separator">·</span>';

        return '<div class="jsb-autocomplete-context">' . implode($separateur, $presents) . '</div>';
    }

    /** @param list<Chiffre> $chiffres */
    private function ligneDeChiffres(array $chiffres): ?string
    {
        $retenus = \array_slice(
            array_values(array_filter($chiffres, static fn (Chiffre $c): bool => $c->estCalcule())),
            0,
            self::CHIFFRES_MAX,
        );

        if ($retenus === []) {
            return null;
        }

        $html = '';
        foreach ($retenus as $chiffre) {
            $html .= '<span class="jsb-chiffre">'
                . '<span class="jsb-chiffre-libelle">' . $this->proteger($chiffre->libelle) . '</span>'
                . '<span class="' . trim('jsb-chiffre-valeur ' . $this->teinte($chiffre)) . '">'
                . $this->proteger($this->formater($chiffre))
                . '</span></span>';
        }

        return '<div class="jsb-autocomplete-aide">' . $html . '</div>';
    }

    /**
     * La couleur d'un solde — et rien d'autre n'est coloré.
     *
     * Un montant n'a pas d'état : le peindre ne dirait rien de plus que le nombre. Un
     * solde, si. Et la couleur n'est jamais seule à le dire (WCAG 1.4.1) : « 0,00 » dit
     * le soldé, un nombre non nul dit le reste, le signe moins dit le trop-perçu.
     *
     * Les teintes SOMBRES, et non `--success`/`--danger` : mesurés sur le fond d'une
     * option survolée (#e8f0fb), le vert et le rouge de la charte tombent à 3,95 et 3,94
     * — sous le seuil AA de 4,5. Les variantes foncées tiennent 8,15 et 8,24.
     */
    private function teinte(Chiffre $chiffre): string
    {
        if ($chiffre->genre !== GenreDeChiffre::Solde) {
            return '';
        }

        return abs((float) $chiffre->valeur) < 0.01 ? 'jsb-chiffre--solde' : 'jsb-chiffre--du';
    }

    /**
     * Le format suit la LANGUE ACTIVE, et non le français en dur.
     *
     * En français, `ServiceNombres` appelle littéralement le même `number_format` : le
     * rendu est identique à l'octet près. La différence n'apparaît qu'en anglais — où
     * l'ancien code écrivait `1 160,00` à un lecteur qui attend `1,160.00`.
     */
    private function formater(Chiffre $chiffre): string
    {
        $valeur = $this->nombres->format((float) $chiffre->valeur, 2);

        return $chiffre->genre === GenreDeChiffre::Taux ? $valeur . ' %' : $valeur;
    }

    /**
     * Ce qui ne dit rien devient `null` — y compris les mots qui disaient « rien ».
     *
     * La comparaison est STRICTE, jamais un `str_contains` : « N/Able » est un nom de
     * société parfaitement valide, et le filtrer serait pire que le défaut qu'on corrige.
     */
    private function nettoyer(?string $valeur): ?string
    {
        $net = trim((string) $valeur);

        if ($net === '' || \in_array($net, self::PLACEHEURS_HERITES, true)) {
            return null;
        }

        return $net;
    }

    /**
     * TRONQUER AVANT D'ÉCHAPPER, et jamais l'inverse.
     *
     * Couper après l'échappement trancherait une entité en deux — `&amp;` devenant
     * `&am` —, ce qui donne un caractère invalide dans la page. Tronquer d'abord porte
     * sur le texte réel, qui est aussi ce que l'utilisateur compte.
     */
    private function tronquer(string $texte): string
    {
        return mb_strlen($texte) > self::CONTEXTE_MAX
            ? mb_substr($texte, 0, self::CONTEXTE_MAX - 1) . '…'
            : $texte;
    }

    private function proteger(string $texte): string
    {
        return htmlspecialchars($texte, \ENT_QUOTES, 'UTF-8');
    }
}
