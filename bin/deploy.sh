#!/usr/bin/env bash
# =============================================================================
#  JOSEARA — DÉPLOIEMENT EN PRODUCTION (hébergement mutualisé cPanel)
# -----------------------------------------------------------------------------
#  Usage :
#     bash bin/deploy.sh --dry-run          ne touche à RIEN, dit ce qu'il ferait
#     bash bin/deploy.sh                    publie la tête de la branche master
#     bash bin/deploy.sh --ref=v4127        publie une étiquette précise
#     bash bin/deploy.sh --rollback=<sha>   revient à une version antérieure
#     bash bin/deploy.sh --skip-migrations  saute les migrations (diagnostic)
#     bash bin/deploy.sh --skip-git         le code est déjà posé (.cpanel.yml)
#     bash bin/deploy.sh --no-backup        saute le dump SQL (À ÉVITER)
#
#  ── PRINCIPE DIRECTEUR ──────────────────────────────────────────────────────
#  Entre la première écriture et la dernière, le site est EN MAINTENANCE. On ne
#  cherche pas le « zéro interruption » — sur un mutualisé il n'existe pas — on
#  cherche à ce que PERSONNE ne voie jamais un état intermédiaire : ni un ancien
#  code face à un schéma déjà migré, ni un cache à moitié reconstruit.
#
#  ── DEUX INTERDITS ABSOLUS ──────────────────────────────────────────────────
#  · JAMAIS « git clean » : public/images/entreprises mêle des fichiers de marque
#    suivis et les logos TÉLÉVERSÉS par les cabinets ; public/uploads/documents
#    et public/pdfs ne contiennent QUE des données. « git reset --hard » ne
#    touche pas aux fichiers non suivis — c'est exactement pourquoi il est ici.
#  · JAMAIS « rm -rf var/ » : var/uploads/assistant et assistant-documents
#    contiennent les pièces jointes et les documents produits par Ket,
#    référencés en base. Seul var/cache est jetable, et « cache:clear » s'en
#    charge proprement.
# =============================================================================
set -Eeuo pipefail

# ---------------------------------------------------------------------------
#  CONFIGURATION — à ajuster une seule fois, après le diagnostic du serveur.
#  Chaque valeur peut aussi être passée par variable d'environnement, ce qui
#  évite de modifier ce fichier versionné sur le serveur.
# ---------------------------------------------------------------------------
APP_DIR="${JOSEARA_APP_DIR:-$HOME/joseara}"

# Racine web réelle. Vaut « $APP_DIR/public » dans le cas normal ; vaut
# « $HOME/public_html » si la racine de document n'a pas pu être déplacée.
PUBLIC_DIR="${JOSEARA_PUBLIC_DIR:-$APP_DIR/public}"

# ── LE BINAIRE PHP ──────────────────────────────────────────────────────────
# Le « php » du PATH d'un cron n'est PAS forcément celui qui sert le site :
# version et extensions peuvent différer, et c'est alors le cron qui tombe, seul,
# la nuit, sans que personne ne le voie.
#
# ⚠ ON NE CHERCHE PAS LE PLUS RÉCENT. Une machine mutualisée propose souvent
#   plusieurs versions installées côte à côte, mais les extensions ne sont
#   activées que pour CELLE QUE LE SITE UTILISE. Retenir la plus neuve, c'est
#   retenir celle qui n'a rien — vérifié le 2026-09-13 sur joseara.com, où
#   alt-php83 existe, tout nu, à côté d'un alt-php82 complet.
#
# Le bon critère n'est donc pas la version, c'est : « ce PHP peut-il faire
# tourner l'application ? ». On exige la version MINIMALE et TOUTES les
# extensions. Le premier candidat qui satisfait les deux gagne.
#
# L'ordre place « php » du PATH en tête : sur CloudLinux, c'est un lien vers la
# version SÉLECTIONNÉE pour le compte — donc celle du site, par construction.
EXTENSIONS_REQUISES="ctype curl dom fileinfo filter gd iconv intl json libxml \
mbstring openssl pdo pdo_mysql session simplexml tokenizer xml xmlreader \
xmlwriter zip zlib"

# Rend la liste des extensions manquantes pour un binaire donné (vide = aucune).
extensions_manquantes() {
  local binaire="$1" ext presentes manquantes=""
  presentes="$("$binaire" -m 2>/dev/null | tr 'A-Z' 'a-z')"
  for ext in $EXTENSIONS_REQUISES; do
    printf '%s\n' "$presentes" | grep -qx "$ext" || manquantes="$manquantes $ext"
  done
  printf '%s' "$manquantes"
}

CANDIDATS_PHP="$(command -v php 2>/dev/null)
/opt/alt/php83/usr/bin/php
/opt/alt/php82/usr/bin/php
/opt/cpanel/ea-php83/root/usr/bin/php
/opt/cpanel/ea-php82/root/usr/bin/php"

# Vrai si le binaire est un PHP en ligne de commande (SAPI « cli »).
#
# ⚠ CE CONTRÔLE N'EST PAS DÉCORATIF. Sur joseara.com, le « php » du PATH est un
#   binaire **CGI** : il ne connaît pas l'option -r, et répond par son mode
#   d'emploi — SUR LA SORTIE STANDARD. Une substitution de commande avale donc ce
#   mode d'emploi et le prend pour un chemin. C'est ce qui s'est produit le
#   2026-09-13 : le script a cru avoir trouvé un binaire nommé « Usage: php-cgi
#   [-q] [-h]… ». D'où la double précaution — on vérifie la SAPI, et on jette
#   stdout de chaque sonde.
est_php_cli() {
  "$1" -v 2>/dev/null | head -1 | grep -q '(cli)'
}

trouver_php() {
  local candidat
  while IFS= read -r candidat; do
    [ -n "$candidat" ] && [ -x "$candidat" ] || continue
    est_php_cli "$candidat" || continue
    "$candidat" -r 'exit(PHP_VERSION_ID >= 80200 ? 0 : 1);' >/dev/null 2>&1 || continue
    [ -z "$(extensions_manquantes "$candidat")" ] || continue
    echo "$candidat"
    return 0
  done <<EOF_CANDIDATS
$CANDIDATS_PHP
EOF_CANDIDATS
  return 1
}

PHP="${JOSEARA_PHP:-$(trouver_php || true)}"
if [ -z "$PHP" ]; then
  # Aucun candidat complet : on ne se contente pas de le dire, on montre POURQUOI
  # chacun a été écarté. C'est la différence entre « ça ne marche pas » et « voici
  # quelle case cocher, dans quel onglet, pour quelle version ».
  echo "" >&2
  echo "Aucun PHP utilisable trouve. Etat de chaque candidat :" >&2
  while IFS= read -r c; do
    [ -n "$c" ] && [ -x "$c" ] || continue
    v="$("$c" -v 2>/dev/null | head -1 | awk '{print $2}')"
    if ! est_php_cli "$c"; then
      printf '  %-46s %-9s pas un PHP en ligne de commande (SAPI cgi)\n' "$c" "$v" >&2
    elif ! "$c" -r 'exit(PHP_VERSION_ID >= 80200 ? 0 : 1);' >/dev/null 2>&1; then
      printf '  %-46s %-9s trop ancien (8.2 minimum)\n' "$c" "$v" >&2
    else
      printf '  %-46s %-9s manque :%s\n' "$c" "$v" "$(extensions_manquantes "$c")" >&2
    fi
  done <<EOF_DIAG
$CANDIDATS_PHP
EOF_DIAG
  echo "" >&2
  echo "-> cPanel -> Select PHP Version : verifiez que la version choisie est bien" >&2
  echo "   celle du site, et cochez les extensions manquantes DANS CETTE VERSION." >&2
  echo "-> Ou imposez le binaire : JOSEARA_PHP=/chemin/vers/php bash bin/deploy.sh" >&2
  exit 1
fi

# Composer : composer.phar déposé dans ~/bin, ou le binaire du système.
COMPOSER_PHAR="${JOSEARA_COMPOSER:-$HOME/bin/composer.phar}"

BRANCHE="${JOSEARA_BRANCHE:-master}"

# ⚠ « origin » est correct ICI, et seulement ici. Le serveur obtient son dépôt
#   par « git clone », qui nomme toujours sa source « origin ». Sur le POSTE de
#   développement, en revanche, la remote s'appelle « JSBrokers_1_0_3 » — c'est
#   ce nom-là qu'utilise bin/publier.ps1 pour pousser. Les deux sont justes :
#   ne pas « corriger » l'un d'après l'autre.
DEPOT="${JOSEARA_REMOTE:-origin}"
BACKUP_DIR="${JOSEARA_BACKUP_DIR:-$HOME/backups}"
LOG_DIR="${JOSEARA_LOG_DIR:-$HOME/logs}"
RETENTION_JOURS=14
URL_SANTE="${JOSEARA_URL_SANTE:-https://www.joseara.com/}"

# Nom de la base, pour le dump. Les identifiants vivent dans ~/.my.cnf (chmod
# 600) : jamais sur la ligne de commande, où « ps » les rendrait visibles.
DB_NAME="${JOSEARA_DB:-}"
MY_CNF="${JOSEARA_MY_CNF:-$HOME/.my.cnf}"

# ---------------------------------------------------------------------------
#  ARGUMENTS
# ---------------------------------------------------------------------------
DRY_RUN=0; SKIP_MIGRATIONS=0; SKIP_GIT=0; NO_BACKUP=0; REF=""; ROLLBACK=""
for arg in "$@"; do
  case "$arg" in
    --dry-run)         DRY_RUN=1 ;;
    --skip-migrations) SKIP_MIGRATIONS=1 ;;
    --skip-git)        SKIP_GIT=1 ;;
    --no-backup)       NO_BACKUP=1 ;;
    --ref=*)           REF="${arg#*=}" ;;
    --rollback=*)      ROLLBACK="${arg#*=}" ;;
    -h|--help)         sed -n '2,29p' "$0"; exit 0 ;;
    *) echo "Option inconnue : $arg" >&2; exit 64 ;;
  esac
done

HORODATAGE="$(date +%Y%m%d-%H%M%S)"
mkdir -p "$BACKUP_DIR" "$LOG_DIR"
JOURNAL="$LOG_DIR/deploy-$HORODATAGE.log"

# ---------------------------------------------------------------------------
#  AFFICHAGE ET EXÉCUTION
# ---------------------------------------------------------------------------
titre() { printf '\n\033[1;36m== %s\033[0m\n' "$*" | tee -a "$JOURNAL"; }
info()  { printf '   %s\n'                    "$*" | tee -a "$JOURNAL"; }
ok()    { printf '\033[1;32m   OK  %s\033[0m\n' "$*" | tee -a "$JOURNAL"; }
ko()    { printf '\033[1;31m   !!  %s\033[0m\n' "$*" | tee -a "$JOURNAL"; }

# TOUTE écriture passe par « executer ». En --dry-run la commande est AFFICHÉE
# et non lancée : c'est ce qui rend la répétition à blanc honnête — elle montre
# exactement ce que le vrai passage ferait, sans rien en faire.
executer() {
  if [ "$DRY_RUN" -eq 1 ]; then
    printf '\033[0;33m   [A BLANC] %s\033[0m\n' "$*" | tee -a "$JOURNAL"
  else
    printf '   $ %s\n' "$*" | tee -a "$JOURNAL"
    eval "$@" >>"$JOURNAL" 2>&1
  fi
}

SHA_AVANT=""
CIBLE=""
MAINTENANCE_OUVERTE=0

# Un échec APRÈS l'ouverture de la maintenance laisse délibérément le site
# fermé : le schéma et le code peuvent être incohérents, et servir un état
# intermédiaire serait pire qu'une page d'attente.
sortie_propre() {
  local code=$?
  if [ "$code" -ne 0 ]; then
    ko "ECHEC (code $code). Journal complet : $JOURNAL"
    if [ "$MAINTENANCE_OUVERTE" -eq 1 ]; then
      ko "Le site est reste EN MAINTENANCE, deliberement."
      ko "Corrigez puis relancez, ou revenez en arriere :"
      [ -n "$SHA_AVANT" ] && ko "   bash bin/deploy.sh --rollback=$SHA_AVANT"
    fi
  fi
  exit $code
}
trap sortie_propre EXIT

cd "$APP_DIR"

# ===========================================================================
titre "1/11  Verifications prealables"
# ===========================================================================
[ -x "$PHP" ] || { ko "Binaire PHP introuvable : $PHP"; exit 1; }
"$PHP" -r 'exit(PHP_VERSION_ID >= 80200 ? 0 : 1);' \
  || { ko "PHP trop ancien : $("$PHP" -r 'echo PHP_VERSION;') — 8.2 minimum"; exit 1; }
ok "PHP $("$PHP" -r 'echo PHP_VERSION;')"

# La détection ci-dessus n'a retenu qu'un binaire COMPLET : ce contrôle est donc
# normalement déjà acquis. Il reste utile dans un seul cas, mais un cas réel —
# quand JOSEARA_PHP impose un binaire à la main, en sautant la détection. Une
# seule liste (EXTENSIONS_REQUISES) sert aux deux : deux listes finiraient par
# diverger, et c'est la plus indulgente qui ferait foi.
MANQUANTES="$(extensions_manquantes "$PHP")"
if [ -n "$MANQUANTES" ]; then
  ko "Extensions PHP manquantes dans $PHP :$MANQUANTES"
  ko "-> cPanel -> Select PHP Version -> onglet Extensions,"
  ko "   en verifiant d'abord que la version selectionnee est celle du SITE."
  exit 1
fi
ok "Extensions PHP : toutes presentes"

[ -f "$APP_DIR/.env.local" ] || { ko ".env.local absent : les secrets de production n'existent pas"; exit 1; }
grep -q '^APP_ENV=prod' "$APP_DIR/.env.local" || { ko ".env.local ne pose pas APP_ENV=prod"; exit 1; }
ok ".env.local present, APP_ENV=prod"

if [ "$SKIP_GIT" -eq 0 ]; then
  # Un fichier SUIVI modifié à la main sur le serveur sera écrasé par le reset.
  # Mieux vaut le dire avant que de le découvrir après.
  if ! git diff --quiet || ! git diff --cached --quiet; then
    ko "Des fichiers SUIVIS ont ete modifies sur le serveur :"
    git status --porcelain | grep -v '^??' | tee -a "$JOURNAL"
    ko "Ils seront ECRASES par « git reset --hard »."
    ko "Pour voir ce qui differe :  git diff --stat && git diff"
    ko "Pour les abandonner         :  git checkout -- <fichier>"
    if [ "$DRY_RUN" -eq 1 ]; then
      # La répétition à blanc ne modifie rien : elle CONTINUE pour montrer le
      # reste des contrôles. Mais elle doit dire clairement que le passage réel,
      # lui, s'arrêtera ici — sinon on croit l'avertissement sans conséquence.
      ko "(repetition a blanc : on continue, mais le passage REEL refusera)"
    else
      exit 1
    fi
  else
    ok "Copie de travail propre"
  fi
fi

# ── PREMIÈRE INSTALLATION ? ─────────────────────────────────────────────────
# « bin/console » a besoin de vendor/autoload_runtime.php. Sur un dépôt tout
# juste cloné, ce fichier n'existe pas encore : toute vérification qui passe par
# la console échouerait ici, AVANT l'étape 6 qui l'aurait rendue possible.
# On distingue donc les deux situations, plutôt que d'imposer une installation
# manuelle préalable dont personne ne se souviendrait la fois suivante.
PREMIERE_INSTALLATION=0
if [ ! -f "$APP_DIR/vendor/autoload_runtime.php" ]; then
  PREMIERE_INSTALLATION=1
  info "vendor/ absent : PREMIÈRE INSTALLATION."
  info "Les contrôles qui passent par la console (base, migrations en attente)"
  info "sont reportés après l'installation des dépendances."
fi

if [ "$PREMIERE_INSTALLATION" -eq 0 ]; then
  "$PHP" bin/console dbal:run-sql "SELECT 1" --env=prod >/dev/null 2>&1 \
    || { ko "Base injoignable — verifiez DATABASE_URL dans .env.local"; exit 1; }
  ok "Base de donnees joignable"
fi

ESPACE_MO=$(df -Pm "$APP_DIR" | awk 'NR==2{print $4}')
[ "${ESPACE_MO:-0}" -gt 500 ] || { ko "Moins de 500 Mo libres (${ESPACE_MO} Mo)"; exit 1; }
ok "Espace disque : ${ESPACE_MO} Mo libres"

BESOIN_COMPOSER=1
if [ "$SKIP_GIT" -eq 0 ]; then
  SHA_AVANT="$(git rev-parse HEAD)"
  CIBLE="${REF:-$DEPOT/$BRANCHE}"
  [ -n "$ROLLBACK" ] && CIBLE="$ROLLBACK"

  git fetch --prune --tags "$DEPOT" >>"$JOURNAL" 2>&1 || true
  info "Version actuelle : ${SHA_AVANT:0:8}  ($(git log -1 --format=%s))"
  info "Version visee    : $(git rev-parse --short "$CIBLE" 2>/dev/null || echo '?')"

  titre "      Ce qui va changer"
  git --no-pager log --oneline "HEAD..$CIBLE" 2>/dev/null | head -40 | tee -a "$JOURNAL" || true
  git --no-pager diff --stat "HEAD..$CIBLE" 2>/dev/null | tail -20 | tee -a "$JOURNAL" || true

  # composer.lock inchangé ⇒ vendor/ inchangé ⇒ on saute l'étape la plus longue
  # et la plus risquée. C'est ce qui fait qu'une mise à jour courante dure vingt
  # secondes au lieu de quatre minutes.
  if git diff --quiet "HEAD..$CIBLE" -- composer.lock 2>/dev/null; then
    BESOIN_COMPOSER=0; info "composer.lock inchange -> vendor/ conserve tel quel"
  else
    info "composer.lock MODIFIE -> reinstallation des dependances"
  fi
fi

if [ "$PREMIERE_INSTALLATION" -eq 0 ]; then
  titre "      Migrations en attente"
  "$PHP" bin/console doctrine:migrations:up-to-date --env=prod 2>&1 | tee -a "$JOURNAL" || true
else
  titre "      Migrations en attente"
  info "(non consultables avant l'installation des dependances — les 76"
  info " migrations s'appliqueront a l'etape 9)"
fi

if [ "$DRY_RUN" -eq 1 ]; then
  titre "REPETITION A BLANC TERMINEE — rien n'a ete modifie"
  if [ "$PREMIERE_INSTALLATION" -eq 1 ]; then
    info ""
    info "PREMIERE INSTALLATION : cette repetition n'a PAS pu verifier la base,"
    info "faute de dependances installees. C'est le passage reel qui le fera,"
    info "juste apres composer install, et avant toute migration."
  fi
  exit 0
fi

# ===========================================================================
titre "2/11  Sauvegarde"
# ===========================================================================
if [ "$NO_BACKUP" -eq 0 ] && [ -n "$DB_NAME" ] && [ -f "$MY_CNF" ]; then
  executer "mysqldump --defaults-file='$MY_CNF' --single-transaction --quick \
     --routines --triggers --default-character-set=utf8mb4 '$DB_NAME' \
     | gzip -9 > '$BACKUP_DIR/db-$HORODATAGE.sql.gz'"
  [ -n "$SHA_AVANT" ] && executer "echo '$SHA_AVANT' > '$BACKUP_DIR/sha-$HORODATAGE.txt'"
  executer "cp '$APP_DIR/.env.local' '$BACKUP_DIR/env-$HORODATAGE.local'"
  executer "chmod 600 '$BACKUP_DIR'/*-$HORODATAGE.*"
  ok "Dump : $BACKUP_DIR/db-$HORODATAGE.sql.gz"
  executer "find '$BACKUP_DIR' -name 'db-*.sql.gz' -mtime +$RETENTION_JOURS -delete"
else
  ko "SAUVEGARDE SAUTEE — aucun retour arriere possible sur les donnees"
  [ -z "$DB_NAME" ] && ko "   (JOSEARA_DB n'est pas renseigne dans ce script)"
fi

# ===========================================================================
titre "3/11  Passage en maintenance"
# ===========================================================================
executer "touch '$PUBLIC_DIR/maintenance.flag'"
MAINTENANCE_OUVERTE=1
# Laisse les requêtes déjà en vol se terminer avant de bouger le code sous
# leurs pieds. Trois secondes suffisent pour une application de ce gabarit.
executer "sleep 3"
ok "Site ferme aux visiteurs"

# ===========================================================================
titre "4/11  Recuperation du code"
# ===========================================================================
if [ "$SKIP_GIT" -eq 0 ]; then
  # « reset --hard » et NON « pull » : un pull peut produire un conflit de
  # fusion et laisser l'arbre de travail dans un état intermédiaire. reset pose
  # la version visée, point.
  executer "git reset --hard '$CIBLE'"
  ok "Code pose sur $(git rev-parse --short HEAD)"
else
  info "Code deja pose par l'appelant (--skip-git)"
fi

# ===========================================================================
titre "5/11  Dossiers de donnees et permissions"
# ===========================================================================
executer "mkdir -p '$APP_DIR/var/cache' '$APP_DIR/var/log' '$APP_DIR/var/sessions' \
          '$APP_DIR/var/tmp' '$APP_DIR/var/echange' \
          '$APP_DIR/var/uploads/assistant' '$APP_DIR/var/uploads/assistant-documents' \
          '$PUBLIC_DIR/pdfs' '$PUBLIC_DIR/uploads/documents' '$PUBLIC_DIR/images/entreprises'"
# 755/644 et JAMAIS 777 : avec suexec ou PHP-FPM par utilisateur, Apache REFUSE
# d'exécuter un script situé dans une arborescence ouverte en écriture au
# groupe ou au monde. « chmod -R 777 » est le réflexe qui casse le site.
executer "find '$APP_DIR/var' -type d -exec chmod 755 {} +"
executer "find '$PUBLIC_DIR/uploads' '$PUBLIC_DIR/pdfs' -type d -exec chmod 755 {} +"
executer "chmod 600 '$APP_DIR/.env.local'"
executer "chmod +x '$APP_DIR/bin/console' '$APP_DIR/bin/deploy.sh'"
ok "Arborescence et permissions en place"

# ===========================================================================
titre "6/11  Dependances PHP"
# ===========================================================================
if [ -f "$COMPOSER_PHAR" ]; then
  COMPOSER_CMD="$PHP -d memory_limit=-1 $COMPOSER_PHAR"
elif command -v composer >/dev/null 2>&1; then
  COMPOSER_CMD="composer"
else
  COMPOSER_CMD=""
fi

if [ "$BESOIN_COMPOSER" -eq 1 ] || [ ! -f "$APP_DIR/vendor/autoload_runtime.php" ]; then
  [ -n "$COMPOSER_CMD" ] || { ko "Composer introuvable. Deposez composer.phar dans ~/bin/"; exit 1; }

  # --no-scripts est DÉLIBÉRÉ. Les « auto-scripts » de composer.json lancent
  # cache:clear (dans le mauvais environnement) et importmap:install (qui exige
  # un accès HTTPS sortant vers jsDelivr). On refait ces étapes nous-mêmes, plus
  # bas, dans le bon ordre et sans dépendre du réseau.
  executer "APP_ENV=prod $COMPOSER_CMD install --no-dev --no-interaction --no-progress \
            --no-scripts --optimize-autoloader --classmap-authoritative --prefer-dist"
  ok "vendor/ reinstalle"
else
  info "vendor/ conserve (composer.lock inchange)"
fi

# Compile .env + .env.local en un seul tableau PHP : plus aucun fichier .env
# n'est analysé à chaque requête.
# ⚠ TANT QUE .env.local.php EXISTE, .env.local EST IGNORÉ. Toute modification
#   des secrets exige donc de rejouer ceci — c'est pour cela que l'étape est
#   INCONDITIONNELLE et vit dans le script de déploiement, et nulle part ailleurs.
if [ -n "$COMPOSER_CMD" ]; then
  executer "APP_ENV=prod $COMPOSER_CMD dump-env prod"
  executer "chmod 600 '$APP_DIR/.env.local.php'"
  ok "Environnement compile (.env.local.php)"
fi

# Le contrôle de base reporté par l'étape 1 en première installation. Il a lieu
# ICI, c'est-à-dire dès que la console est utilisable et AVANT toute migration :
# découvrir un DATABASE_URL fautif au moment d'écrire dans le schéma serait le
# découvrir trop tard.
if [ "$PREMIERE_INSTALLATION" -eq 1 ]; then
  "$PHP" bin/console dbal:run-sql "SELECT 1" --env=prod >/dev/null 2>&1 \
    || { ko "Base injoignable — verifiez DATABASE_URL dans .env.local"; exit 1; }
  ok "Base de donnees joignable (controle reporte de l'etape 1)"
fi

# ===========================================================================
titre "7/11  Reconstruction du cache"
# ===========================================================================
# cache:clear compile dans un dossier temporaire puis le renomme : le
# basculement est atomique au niveau du système de fichiers.
# ⚠ NE JAMAIS remplacer par « rm -rf var/cache/prod » : ce geste-là crée un trou
#   de plusieurs secondes pendant lequel chaque requête recompile le conteneur.
# Les sessions vivent dans var/sessions (framework.yaml) : personne n'est
# déconnecté par cette étape.
executer "APP_ENV=prod APP_DEBUG=0 $PHP -d memory_limit=512M bin/console cache:clear --no-warmup"
executer "APP_ENV=prod APP_DEBUG=0 $PHP -d memory_limit=512M bin/console cache:warmup"
ok "Cache de production reconstruit et prechauffe"

# ===========================================================================
titre "8/11  Ressources statiques"
# ===========================================================================
executer "APP_ENV=prod $PHP bin/console assets:install '$PUBLIC_DIR' --no-interaction"
# PAS d'« importmap:install » : assets/vendor/ est VERSIONNÉ (voir .gitignore),
# donc aucun téléchargement n'est nécessaire. « asset-map:compile » n'est que de
# la copie et du hachage local : il ne peut pas échouer faute de réseau.
executer "APP_ENV=prod $PHP -d memory_limit=512M bin/console asset-map:compile"
ok "public/assets et public/bundles a jour"

# ===========================================================================
titre "9/11  Migrations de la base"
# ===========================================================================
if [ "$SKIP_MIGRATIONS" -eq 0 ]; then
  # APRÈS cache:clear : les métadonnées Doctrine utilisées ici doivent être
  # celles du NOUVEAU code, pas celles que l'ancien cache avait figées.
  executer "APP_ENV=prod $PHP -d memory_limit=512M bin/console doctrine:migrations:migrate \
            --no-interaction --allow-no-migration --all-or-nothing"
  ok "Schema a jour"
else
  ko "Migrations SAUTEES a la demande"
fi

# ===========================================================================
titre "10/11  Reouverture du site"
# ===========================================================================
executer "rm -f '$PUBLIC_DIR/maintenance.flag'"
MAINTENANCE_OUVERTE=0
ok "Site rouvert"

# ===========================================================================
titre "11/11  Controle"
# ===========================================================================
CODE_HTTP="$(curl -s -o /dev/null -w '%{http_code}' -L --max-time 25 "$URL_SANTE" || echo 000)"
info "GET $URL_SANTE -> HTTP $CODE_HTTP"
if [ "$CODE_HTTP" = "200" ]; then
  ok "Le site repond."
else
  ko "Reponse inattendue. Dernieres erreurs applicatives :"
  tail -n 40 "$APP_DIR/var/log/prod"*.log 2>/dev/null | tee -a "$JOURNAL" || true
  [ -n "$SHA_AVANT" ] && ko "Retour arriere : bash bin/deploy.sh --rollback=$SHA_AVANT"
  exit 1
fi

printf '\n\033[1;32m=== PUBLIE : %s -> %s ===\033[0m\n' \
  "${SHA_AVANT:0:8}" "$(git rev-parse --short HEAD 2>/dev/null || echo '?')" | tee -a "$JOURNAL"
info "Version applicative : $(head -1 VERSION 2>/dev/null)"
info "Journal : $JOURNAL"
