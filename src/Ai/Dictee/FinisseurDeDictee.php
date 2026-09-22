<?php

namespace App\Ai\Dictee;

use App\Ai\Debit\BudgetDebit;
use Psr\Log\LoggerInterface;

/**
 * LA FINITION D'UNE DICTÉE : du parlé brut au texte qu'on aurait écrit.
 *
 * La reconnaissance vocale du navigateur transcrit fidèlement ce qui a été DIT — les
 * « euh », les mots répétés, les faux départs, et aucune ponctuation. Relire cela
 * avant de l'envoyer à Ket est pénible ; l'envoyer tel quel dégrade la compréhension.
 * Un petit appel au modèle léger (celui de la compréhension, compteur de débit à
 * part) remet le texte au propre quand l'utilisateur arrête de dicter.
 *
 * NE CHANGE JAMAIS LE SENS. Le modèle nettoie, il ne répond pas et ne reformule pas.
 * Trois garde-fous le vérifient APRÈS coup, parce qu'une consigne ne se vérifie pas :
 * la longueur (une sortie qui s'effondre a perdu du contenu, une sortie qui enfle en a
 * inventé), les NOMBRES (un montant, une date ou un taux absents de la sortie) et les
 * NOMS (un mot dicté avec une majuscule qui disparaît).
 *
 * FAIL-OPEN : panne, quota, délai, JSON illisible, garde-fou déclenché → le texte brut
 * revient tel quel, et `finie` vaut false. La dictée ne doit jamais perdre ce que
 * l'utilisateur a dit parce que sa finition a échoué.
 */
final class FinisseurDeDictee
{
    /** Bornes de longueur de la sortie, en proportion de l'entrée. */
    private const RATIO_MIN = 0.4;
    private const RATIO_MAX = 1.3;

    /** En deçà, il n'y a rien à mettre au propre. */
    private const LONGUEUR_MIN = 3;

    private const CONSIGNE = <<<'CONSIGNE'
    Tu reçois la transcription BRUTE d'une dictée vocale adressée à un assistant de gestion de cabinet
    de courtage en assurance. Rends le MÊME message, mis au propre comme si la personne l'avait écrit.

    À FAIRE :
    - Supprime les hésitations et bruits de parole : euh, heu, hum, hmm, bah, ben, bon ben, genre,
      en fait / du coup / voilà quand ce sont des tics sans rôle dans la phrase.
    - Supprime les bégaiements et les mots répétés (« le le client », « je je voudrais »).
    - Faux départ corrigé par la personne (« pour le client Ki… pour le client Kibali ») : garde
      seulement la version corrigée.
    - Ponctuation et majuscules correctes. Applique les commandes dictées : « virgule », « point »,
      « point d'interrogation », « deux points », « à la ligne » / « point à la ligne ».
    - Termine par « ? » TOUTE phrase qui pose une question, y compris sans mot interrogatif
      (« tu peux me donner la liste », « est-ce que », inversion sujet-verbe, « c'est combien »).
    - Une énumération (plusieurs éléments de même nature cités à la suite, « premièrement…
      deuxièmement… », « et aussi… et aussi… ») se présente en liste : la phrase d'introduction
      se termine par « : », puis CHAQUE élément sur SA PROPRE LIGNE (vrai retour à la ligne,
      « \n » dans le JSON) commençant par « - ». Jamais plusieurs « - » sur une même ligne.
    - Plusieurs demandes distinctes : une phrase chacune.

    INTERDIT :
    - Changer le sens, ajouter une information, résumer, ou retirer une demande.
    - Supprimer ou modifier un NOM propre — y compris le nom de l'assistant à qui l'on parle (« Ket ») —,
      un nombre, un montant, un taux, une date ou une référence : recopie-les à l'identique (tu peux
      écrire en chiffres un nombre dicté en lettres).
    - Répondre à la question, commenter, ou t'adresser à la personne.
    - Traduire : garde la langue de la dictée.

    EXEMPLE
    Dictée : « euh bonjour Ket je je voudrais euh la liste des clients qui ont des impayés et aussi les polices
    qui arrivent à échéance tu peux faire ça pour le client Ki pour le client Kibali »
    Texte : « Bonjour Ket, je voudrais :\n- la liste des clients qui ont des impayés ;\n- les polices qui
    arrivent à échéance.\nPeux-tu faire ça pour le client Kibali ? »

    Rends UNIQUEMENT un objet JSON {"texte": "..."}.
    CONSIGNE;

    public function __construct(
        // LE FOURNISSEUR, derrière un contrat : cette classe ne sait plus à qui elle
        // parle. Tout ce qu'elle garde — le plancher de longueur, le contrôle de
        // fidélité, la remise en liste, le repli sur le texte brut — n'a jamais rien
        // eu de gémino-spécifique.
        private readonly AppelDeFinition $appel,
        private readonly BudgetDebit $budget,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** La finition est-elle possible dans cet environnement (clé présente, moteur réel) ? */
    public function estDisponible(): bool
    {
        return $this->appel->estDisponible();
    }

    public function finir(string $brut): FinitionDeDictee
    {
        $brut = trim($brut);
        if (mb_strlen($brut) < self::LONGUEUR_MIN || !$this->estDisponible()) {
            return FinitionDeDictee::inchangee($brut);
        }

        $plafondSortie = $this->plafondSortie($brut);
        // Jamais d'attente : quelqu'un vient de finir de parler.
        if ($this->budget->secondesAvantLiberation($this->appel->cleDeDebit(), $plafondSortie) !== 0) {
            return FinitionDeDictee::inchangee($brut);
        }

        try {
            ['texte' => $propre] = $this->appel->finir(self::CONSIGNE, $brut, $plafondSortie);
        } catch (\Throwable $e) {
            $this->logger->warning('Dictée : la finition a échoué, le texte brut est conservé.', [
                'exception'   => $e,
                'fournisseur' => $this->appel->nom(),
                'modele'      => $this->appel->modele(),
            ]);

            return FinitionDeDictee::inchangee($brut);
        }

        $propre = $this->listesSurDesLignes($propre);
        if ($propre === '' || !$this->fidele($brut, $propre)) {
            return FinitionDeDictee::inchangee($brut);
        }

        return FinitionDeDictee::finie($propre);
    }

    /**
     * La sortie a-t-elle gardé le contenu de l'entrée ?
     *
     * Les NOMBRES sont comparés chiffre à chiffre, espaces retirés (« 1 500 » = « 1500 ») :
     * un nombre dicté en chiffres qui disparaît est une information perdue. L'inverse —
     * un nombre dit en lettres et écrit en chiffres — est permis par la consigne.
     */
    public function fidele(string $brut, string $propre): bool
    {
        $ratio = mb_strlen($propre) / max(1, mb_strlen($brut));
        if ($ratio < self::RATIO_MIN || $ratio > self::RATIO_MAX) {
            return false;
        }

        $sansEspaces = static fn (string $t): string => preg_replace('/(?<=\d)[\s\x{00A0}\x{202F}.]+(?=\d)/u', '', $t) ?? $t;
        preg_match_all('/\d+/', $sansEspaces($brut), $nombres);
        $sortie = $sansEspaces($propre);
        foreach (array_unique($nombres[0]) as $nombre) {
            if (!str_contains($sortie, $nombre)) {
                return false;
            }
        }

        // Les NOMS : tout mot dicté avec une majuscule (le navigateur la pose sur les noms
        // propres qu'il reconnaît) doit survivre. Comparaison par inclusion : un faux départ
        // « Ki… Kibali » est couvert par « Kibali ». Constaté sur Gemini : « Bonjour Ket »
        // rendu « Bonjour. ».
        preg_match_all('/(?<!\p{L})\p{Lu}[\p{L}\x{2019}\'-]+/u', $brut, $noms);
        foreach (array_unique($noms[0]) as $nom) {
            if (mb_stripos($propre, $nom) === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Une liste rendue EN LIGNE (« Voici : - a - b - c ») est remise sur des lignes. Le modèle
     * le fait parfois malgré la consigne ; ne rien toucher quand la liste a déjà ses lignes.
     */
    public function listesSurDesLignes(string $texte): string
    {
        $debut = mb_strpos($texte, ': - ');
        if ($debut === false || str_contains($texte, "\n- ")) {
            return $texte;
        }

        $introduction = mb_substr($texte, 0, $debut + 1);
        $elements = preg_split('/\s+-\s+/u', mb_substr($texte, $debut + 4)) ?: [];
        if (\count($elements) < 2) {
            return $texte;
        }

        // Le dernier élément s'arrête à la première fin de phrase : la suite du texte
        // reprend sur sa propre ligne.
        $suite = '';
        $dernier = array_pop($elements);
        if (preg_match('/^(.*?[.?!;])\s+(\p{Lu}.*)$/su', $dernier, $m) === 1) {
            $dernier = $m[1];
            $suite = $m[2];
        }
        $elements[] = $dernier;

        return $introduction . "\n- " . implode("\n- ", array_map('trim', $elements))
            . ($suite !== '' ? "\n" . $suite : '');
    }

    /** Assez pour recopier le texte avec sa ponctuation et ses retours à la ligne. */
    private function plafondSortie(string $brut): int
    {
        return min(3000, 200 + (int) ceil(mb_strlen($brut) / 2));
    }
}
