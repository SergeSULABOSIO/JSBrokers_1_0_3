<?php

namespace App\Ai\Voix;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * L'audio déjà généré d'une réponse de Ket, pour que RÉÉCOUTER ne coûte rien : ni
 * génération Gemini (10 par jour au palier gratuit), ni jeton du cabinet.
 *
 * Un fichier WAV par (cabinet, voix, texte prononcé), sous `var/ket-voix/` — dossier
 * persistant en production. Seul un audio COMPLET y entre. Les fichiers de plus de
 * 30 jours d'un cabinet sont purgés quand ce cabinet en écrit un nouveau.
 */
final class CacheAudio
{
    private const DUREE_DE_VIE_SECONDES = 30 * 86400;

    public function __construct(
        #[Autowire('%kernel.project_dir%/var/ket-voix')] private readonly string $racine,
    ) {
    }

    public function lire(int $idEntreprise, string $voix, string $texte): ?string
    {
        $chemin = $this->chemin($idEntreprise, $voix, $texte);

        return is_file($chemin) ? (file_get_contents($chemin) ?: null) : null;
    }

    public function ecrire(int $idEntreprise, string $voix, string $texte, string $pcm): void
    {
        $chemin = $this->chemin($idEntreprise, $voix, $texte);
        $dossier = \dirname($chemin);
        if (!is_dir($dossier) && !@mkdir($dossier, 0775, true) && !is_dir($dossier)) {
            return; // Pas de cache possible : la lecture a eu lieu, c'est l'essentiel.
        }
        $this->purger($dossier);
        file_put_contents($chemin, self::wav($pcm), LOCK_EX);
    }

    /** PCM 16 bits mono 24 kHz → fichier WAV (en-tête canonique de 44 octets). */
    public static function wav(string $pcm, int $taux = SyntheseVocaleGemini::TAUX_ECHANTILLONNAGE): string
    {
        $taille = \strlen($pcm);

        return 'RIFF' . pack('V', 36 + $taille) . 'WAVE'
            . 'fmt ' . pack('VvvVVvv', 16, 1, 1, $taux, $taux * 2, 2, 16)
            . 'data' . pack('V', $taille) . $pcm;
    }

    private function chemin(int $idEntreprise, string $voix, string $texte): string
    {
        return sprintf('%s/%d/%s.wav', $this->racine, $idEntreprise, sha1($voix . '|' . trim($texte)));
    }

    private function purger(string $dossier): void
    {
        $limite = time() - self::DUREE_DE_VIE_SECONDES;
        foreach (glob($dossier . '/*.wav') ?: [] as $fichier) {
            if (@filemtime($fichier) < $limite) {
                @unlink($fichier);
            }
        }
    }
}
