#!/usr/bin/env bash
# =============================================================================
#  LA SUITE PHPUNIT, UN PROCESSUS PAR DOSSIER — et une seule suite à la fois.
# -----------------------------------------------------------------------------
#  Usage :
#    bin/phpunit-par-dossier.sh                 tous les dossiers de tests/
#    bin/phpunit-par-dossier.sh Frontend Workspace   ces dossiers seulement
#    bin/phpunit-par-dossier.sh arreter         arrête la suite en cours, PHP compris
#
#  Verdicts : var/suite-par-dossier.log (une ligne par dossier, puis FIN) ;
#  détail de chaque dossier : var/suite-<Dossier>.log.
#
#  POURQUOI UN PROCESSUS PAR DOSSIER. Un seul `bin/phpunit` atteint la limite de mémoire
#  (3 Go) au dernier test, par accumulation : chaque dossier repart de zéro.
#
#  POURQUOI UN VERROU QUI CONNAÎT SON PROPRIÉTAIRE. Deux suites simultanées se marchent
#  dessus dans la même base de test (semis et nettoyages croisés) : des dizaines
#  d'erreurs qui ne disent rien du code. C'est arrivé deux fois (2026-10-13, 2026-10-27),
#  chaque fois de la même façon : l'arrêt n'avait tué que le PHP ou l'enveloppe, la
#  boucle continuait, et le verrou avait été levé À LA MAIN. Le verrou garde donc le PID
#  de la suite : un verrou dont le propriétaire est mort se reprend seul, et `arreter`
#  tue l'arbre entier — il n'y a plus jamais de raison de lever le verrou à la main.
# =============================================================================
cd "$(dirname "$0")/.." || exit 2

PHP=${PHP:-/c/xampp/php/php.exe}
VERROU=var/suite-par-dossier.lock
PROPRIETAIRE=$VERROU/pid
SORTIE=var/suite-par-dossier.log

# Le PID WINDOWS : c'est lui que `tasklist` et `taskkill /T` connaissent, quel que soit
# le shell qui interroge. Hors Windows, le PID du shell suffit.
pid_systeme() {
    if [ -r /proc/$$/winpid ]; then cat /proc/$$/winpid; else echo $$; fi
}

est_vivant() {
    [ -n "$1" ] || return 1
    if command -v tasklist > /dev/null 2>&1; then
        tasklist //FI "PID eq $1" //NH 2>/dev/null | grep -qw "$1"
    else
        kill -0 "$1" 2>/dev/null
    fi
}

tuer_l_arbre() {
    if command -v taskkill > /dev/null 2>&1; then
        taskkill //PID "$1" //T //F > /dev/null 2>&1
    else
        pkill -TERM -P "$1" 2>/dev/null; kill -TERM "$1" 2>/dev/null
    fi
}

if [ "${1:-}" = arreter ]; then
    pid=$(cat "$PROPRIETAIRE" 2>/dev/null)
    if est_vivant "$pid"; then
        tuer_l_arbre "$pid"
        echo "Suite $pid arrêtée (boucle et PHP)."
    else
        echo "Aucune suite en cours."
    fi
    rm -rf "$VERROU"
    exit 0
fi

# mkdir est atomique : de deux lancements simultanés, un seul obtient le verrou.
if ! mkdir "$VERROU" 2>/dev/null; then
    pid=$(cat "$PROPRIETAIRE" 2>/dev/null)
    if est_vivant "$pid"; then
        echo "Une suite tourne déjà (PID $pid) : abandon. Pour l'arrêter : $0 arreter" >&2
        exit 3
    fi
    # Verrou abandonné (suite tuée sans passer par son `trap`) : on le reprend.
    rm -rf "$VERROU"
    mkdir "$VERROU" 2>/dev/null || { echo "Verrou disputé : abandon." >&2; exit 3; }
fi
pid_systeme > "$PROPRIETAIRE"
trap 'rm -rf "$VERROU"' EXIT

if [ $# -gt 0 ]; then
    dossiers=("$@")
else
    # Tout dossier de tests/ qui contient au moins un cas de test : un dossier ajouté
    # demain entre dans la suite sans qu'on pense à l'y inscrire.
    dossiers=()
    for d in tests/*/; do
        d=${d%/}
        grep -rlq --include='*Test.php' -E 'extends [A-Za-z\\]*TestCase' "$d" && dossiers+=("${d#tests/}")
    done
fi

"$PHP" bin/console cache:clear --env=test > /dev/null 2>&1
: > "$SORTIE"
for d in "${dossiers[@]}"; do
    "$PHP" bin/phpunit "tests/$d" > "var/suite-$d.log" 2>&1
    code=$?
    verdict=$(grep -aE "^(OK \(|Tests: )" "var/suite-$d.log" | tail -1)
    echo "$d exit=$code $verdict" >> "$SORTIE"
done
echo FIN >> "$SORTIE"
