<?php

namespace App\Ai\Engine\Socle;

use App\Ai\AiRequest;
use App\Ai\Engine\Usage;
use App\Ai\Trousse\Phase;
use App\Ai\Trousse\Trousse;

/**
 * CE QUI RESTE PROPRE À UN FOURNISSEUR — et rien d'autre.
 *
 * Tout le reste du travail d'un message (comprendre, choisir la trousse, mener
 * les phases, exécuter les outils, mesurer, conclure) est le MÊME quel que soit
 * le fournisseur : il vit dans OrchestrateurDeMessage. Ce contrat ne couvre que
 * la forme du fil et le transport.
 *
 * POURQUOI CETTE COUTURE PLUTÔT QU'UNE SECONDE COPIE. Les règles de conduite de
 * Ket — deux appels par message et un troisième qui se mérite, la relance du tour
 * muet, le rattrapage de l'appel écrit en prose, le garde-fou anti-plan fantôme,
 * la restitution en PHP plutôt qu'une excuse — ne sont pas des détails de format :
 * ce sont des règles métier payées par des incidents datés. Écrites deux fois,
 * elles divergent, et la divergence ne se voit que le jour où le cas traverse le
 * chemin qu'on avait oublié de corriger. C'est exactement ce qui est arrivé à
 * l'adaptateur Claude, resté six mois sans les pièces jointes natives qu'une
 * simple clé posée suffisait pourtant à activer.
 *
 * Le même raisonnement est déjà écrit dans DialecteGemini, pour les mêmes raisons.
 * Celui-ci en est la généralisation : DialecteGemini décrit le proto, celui-ci
 * décrit le FIL.
 */
interface DialecteDuFil
{
    /** Identifiant du moteur pour les journaux (« gemini », « anthropic »). */
    public function nom(): string;

    /**
     * Le modèle RÉELLEMENT interrogé, qui n'est pas toujours celui de la
     * configuration : un repli en cours de message le change. À relire après
     * chaque appel, jamais à mémoriser.
     */
    public function modeleCourant(): string;

    /**
     * La clé du compteur de débit pour ce fournisseur et ce modèle.
     *
     * Elle ne se déduit pas du nom du modèle : les fournisseurs ne comptent pas la
     * même chose (Gemini inclut les tokens cachés, Anthropic les exclut) ni au même
     * endroit (un seul compteur chez l'un, entrée et sortie séparées chez l'autre).
     */
    /**
     * La clé du compteur de débit — celle de la PHASE quand on la donne.
     *
     * Le fournisseur tient sa fenêtre par modèle, et une phase peut désormais avoir
     * le sien (cf. GEMINI_MODELE_REDACTION). Sans la phase, on rend le modèle
     * courant : c'est ce que faisaient tous les appelants avant, et ils restent justes.
     */
    public function cleDeDebit(?Phase $phase = null): string;

    /**
     * L'historique de la conversation, mis à la forme du fournisseur.
     *
     * @return list<array<string, mixed>>
     */
    public function filInitial(AiRequest $request): array;

    /**
     * Joint les pièces lisibles nativement (images, PDF scannés) au DERNIER tour
     * utilisateur — inlineData chez Gemini, blocs image/document chez Anthropic.
     *
     * @param list<array<string, mixed>>                                     $fil
     * @param list<array{mimeType:string, donneesBase64:string, nom:string}> $pieces
     *
     * @return list<array<string, mixed>>
     */
    public function joindrePieces(array $fil, array $pieces): array;

    /**
     * UN aller-retour avec le fournisseur, réessais et replis compris.
     *
     * Le transport appartient au dialecte parce que les remèdes en dépendent :
     * Gemini bascule sur un modèle de secours quand le sien est débordé (son
     * compteur de débit est tenu par modèle), Anthropic attend brièvement puis
     * rejoue. L'orchestrateur, lui, n'a pas à savoir lequel des deux a eu lieu.
     *
     * @param list<array<string, mixed>> $fil
     *
     * @return array{reponse: array<string, mixed>, octets: array<string, int>, usage: Usage}
     */
    public function appeler(AiRequest $request, array $fil, Trousse $trousse, Phase $phase): array;

    /** La demande a-t-elle été bloquée par les garde-fous du fournisseur ? */
    public function estBloquee(array $reponse): bool;

    /**
     * Le tour n'a-t-il RIEN produit — ni appel d'outil, ni le moindre mot ?
     *
     * À ne pas confondre avec un blocage : celui-ci a son propre chemin, et le
     * rejouer ne ferait que le faire bloquer une seconde fois.
     */
    public function estTourVide(array $reponse): bool;

    /**
     * Le fournisseur a-t-il rejeté SON PROPRE appel d'outil ?
     *
     * Vrai chez Gemini (finishReason MALFORMED_FUNCTION_CALL : un défaut de
     * sérialisation, pas de raisonnement, d'où la reprise). Sans objet ailleurs —
     * un dialecte qui n'a pas d'équivalent rend simplement false, plutôt que de
     * laisser croire à une couverture qu'il n'a pas.
     */
    public function estAppelMalforme(array $reponse): bool;

    /**
     * Les outils que le modèle a demandés, dans l'ordre.
     *
     * @return list<array{nom: string, args: array<string, mixed>, id: string|null}>
     */
    public function appelsDOutils(array $reponse): array;

    /** Le texte rédigé par le modèle, ou $repli s'il n'a rien écrit. */
    public function texte(array $reponse, ?string $repli = null): string;

    /**
     * Les tours à ajouter au fil après exécution : l'écho du tour du modèle, puis
     * les résultats.
     *
     * L'ÉCHO N'EST PAS DÉCORATIF. PHP décode « args: {} » en TABLEAU vide ;
     * ré-encodé tel quel, il redevient une liste et le fournisseur rejette toute
     * la requête. Chaque dialecte restitue donc l'objet vide à sa façon.
     *
     * @param list<array{appel: array{nom: string, args: array, id: string|null}, statut: string, data: array}> $resultats
     * @param bool                                                                                              $rattrapage l'appel a été
     *                                                                                                                      reconstruit depuis
     *                                                                                                                      du texte : il n'existe
     *                                                                                                                      pas dans le tour du
     *                                                                                                                      modèle, et le canal
     *                                                                                                                      normal le refuserait
     *
     * @return list<array<string, mixed>>
     */
    public function toursDeResultats(array $reponse, array $resultats, bool $rattrapage): array;

    /**
     * Un tour utilisateur portant un texte nu — l'échafaudage de la relance du
     * tour muet, qui ne rejoint jamais le fil conservé.
     *
     * @return array<string, mixed>
     */
    public function tourDeRelance(string $texte): array;
}
