#!/bin/bash
#
# LES PANNES QUE SYMFONY NE VOIT JAMAIS.
#
# ── POURQUOI CE SCRIPT EXISTE ────────────────────────────────────────────────
# Toute la supervision de Joseara — la chaîne d'alerte Monolog comme le comptage
# de la Console — suppose que PHP a démarré, que le noyau Symfony s'est chargé,
# et qu'il a pu journaliser. Trois pannes échappent à cette condition :
#
#   · mémoire épuisée (« Allowed memory size exhausted ») ;
#   · erreur de syntaxe PHP, qui empêche le fichier d'être compilé ;
#   · base injoignable AU DÉMARRAGE, avant que quoi que ce soit ne tourne.
#
# Dans ces trois cas, le processus meurt avant d'avoir pu prévenir : aucun
# e-mail ne part, aucune ligne n'apparaît dans la Console, et le site répond 500
# en silence. La seule trace est celle qu'écrit PHP lui-même, dans error_log.
#
# ── POURQUOI EN SHELL, ET NON EN COMMANDE SYMFONY ────────────────────────────
# ⚠ Délibéré. Une commande `bin/console` démarre le noyau : elle échouerait
# EXACTEMENT dans les cas qu'elle est censée signaler. Un dispositif d'alerte ne
# peut pas dépendre de ce dont il surveille la panne.
#
# ── COMMENT L'E-MAIL PART ────────────────────────────────────────────────────
# Par cron, et non par `mail` : ce script écrit les lignes nouvelles sur SA
# SORTIE D'ERREUR, et cron envoie à l'adresse « Cron Email » tout ce qu'une
# tâche écrit là. C'est la doctrine déjà retenue pour les huit crons de Joseara
# (voir deploiement/README.md) : elle n'ajoute aucune dépendance, et elle a
# l'avantage de rester vraie même si `sendmail` change de place.
#
# Le code de sortie reste 0 : une panne détectée n'est pas un échec du script.
#
# ── L'OFFSET, ET LE PIÈGE DE LA ROTATION ─────────────────────────────────────
# On mémorise la taille déjà lue. Sans cela, chaque exécution horaire renverrait
# tout le fichier, et on cesserait de le lire dès la deuxième fois.
# Si le fichier a RÉTRÉCI depuis la dernière lecture (rotation, troncature,
# effacement manuel), l'offset est remis à zéro : le conserver ferait manquer
# tout ce qui s'écrirait ensuite, silencieusement — c'est le défaut classique de
# ce genre de script, et il ne se voit jamais avant d'avoir coûté une panne.
#
# Cron horaire :
#   10 * * * * /bin/bash /home/josearac/joseara/bin/veille-erreurs-fatales.sh

set -uo pipefail

RACINE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ETAT="${HOME}/logs/.error_log.offset"

# PHP n'écrit pas toujours au même endroit selon la configuration du domaine :
# on regarde les emplacements possibles plutôt que de parier sur un seul.
JOURNAUX=(
    "${RACINE}/public/error_log"
    "${RACINE}/error_log"
    "${HOME}/public_html/error_log"
)

mkdir -p "$(dirname "${ETAT}")"
touch "${ETAT}"

trouve=0

for journal in "${JOURNAUX[@]}"; do
    [ -f "${journal}" ] || continue
    trouve=1

    taille=$(wc -c < "${journal}" 2>/dev/null || echo 0)

    # Offset mémorisé pour CE fichier (une ligne « chemin<TAB>taille » par
    # journal surveillé : les trois emplacements ne se marchent pas dessus).
    lu=$(grep -F "${journal}	" "${ETAT}" 2>/dev/null | tail -n 1 | cut -f2)
    [ -n "${lu:-}" ] || lu=0

    # Le fichier a rétréci : il a été tourné ou vidé. On repart de zéro.
    if [ "${taille}" -lt "${lu}" ]; then
        lu=0
    fi

    if [ "${taille}" -gt "${lu}" ]; then
        nouveau=$(tail -c "+$((lu + 1))" "${journal}" 2>/dev/null)

        if [ -n "${nouveau}" ]; then
            # Sur stderr : c'est ce que cron transforme en e-mail.
            {
                echo "=== Joseara — erreurs fatales, ${journal} ==="
                echo "$(date '+%Y-%m-%d %H:%M:%S') — $((taille - lu)) octet(s) nouveaux"
                echo
                # Plafonné : un fichier qui a explosé (boucle fatale) ne doit pas
                # produire un e-mail de plusieurs mégaoctets, que personne
                # n'ouvrira. Les 200 dernières lignes suffisent à savoir quoi
                # regarder ; le fichier reste sur le serveur pour le reste.
                echo "${nouveau}" | tail -n 200
            } >&2
        fi
    fi

    # On réécrit l'état APRÈS lecture : si le script meurt avant, la prochaine
    # exécution relira la même chose. Un doublon est sans gravité ; un trou ne
    # se rattrape pas.
    { grep -F -v "${journal}	" "${ETAT}" 2>/dev/null; printf '%s\t%s\n' "${journal}" "${taille}"; } > "${ETAT}.tmp"
    mv "${ETAT}.tmp" "${ETAT}"
done

# Aucun journal trouvé : ce n'est PAS une bonne nouvelle en soi — cela peut
# vouloir dire que PHP écrit ailleurs, et donc que cette veille ne surveille
# rien. On le dit une fois, sur stderr, pour que la question se pose.
if [ "${trouve}" -eq 0 ]; then
    echo "Joseara — veille des erreurs fatales : aucun error_log trouvé (${JOURNAUX[*]}). Vérifier où PHP journalise." >&2
fi

exit 0
