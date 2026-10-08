#!/usr/bin/env bash
# =============================================================================
#  JOSEARA — LES BLOCS QUE L'HÉBERGEUR ÉCRIT DANS public/.htaccess
# -----------------------------------------------------------------------------
#  Usage :
#     bash bin/htaccess-hebergeur.sh blocs       <fichier>   blocs de l'hébergeur
#     bash bin/htaccess-hebergeur.sh sans-blocs  <fichier>   le fichier SANS eux
#     bash bin/htaccess-hebergeur.sh php-imposes <fichier>   versions PHP imposées (ex. 8.1)
#
#  ── POURQUOI ───────────────────────────────────────────────────────────────
#  public/.htaccess est versionné — il porte les règles de réécriture sans
#  lesquelles toutes les routes répondent 404. Mais sur un hébergement cPanel, ce
#  fichier n'est pas à nous seuls : MultiPHP Manager y écrit le gestionnaire PHP
#  du domaine, LiteSpeed son cache, d'autres outils leurs réglages. Le
#  2026-10-08, cPanel y a ajouté son bloc, et le déploiement s'est arrêté net sur
#  « fichier suivi modifié sur le serveur ».
#
#  Ces outils signent leurs blocs par des commentaires-marqueurs :
#     # php -- BEGIN cPanel-generated handler, do not edit
#     …
#     # php -- END cPanel-generated handler, do not edit
#  ou « # BEGIN LSCACHE » / « # END LSCACHE ». Le fichier versionné n'en porte
#  AUCUN : tout bloc ainsi marqué vient donc de l'hébergeur. C'est le critère —
#  pas une liste d'outils à tenir à jour.
#
#  Le déploiement (bin/deploy.sh) s'en sert pour :
#   · les CONSERVER à travers le « git reset --hard » (sauvegarde, puis remise) ;
#   · REFUSER toute autre retouche du fichier (sans-blocs ≠ version du dépôt) ;
#   · REFUSER un gestionnaire qui impose un PHP trop ancien pour l'application.
# =============================================================================
set -euo pipefail

commande="${1:-}"
fichier="${2:-}"
[ -n "$commande" ] && [ -f "$fichier" ] || { sed -n '5,8p' "$0" >&2; exit 64; }

# Un marqueur est une ligne de COMMENTAIRE portant BEGIN (ouverture) ou END
# (fermeture), en majuscules : c'est ainsi que les outils de l'hébergeur les signent.
OUVERTURE='^[[:space:]]*#.*BEGIN'
FERMETURE='^[[:space:]]*#.*END'

case "$commande" in
  blocs)
    awk -v o="$OUVERTURE" -v f="$FERMETURE" '
      !dans && $0 ~ o { dans = 1; print; next }
      dans            { print; if ($0 ~ f) dans = 0 }
    ' "$fichier"
    ;;

  sans-blocs)
    # Les lignes vides qui PRÉCÈDENT un bloc partent avec lui : elles ont été
    # ajoutées pour le séparer, et la version du dépôt ne les porte pas.
    awk -v o="$OUVERTURE" -v f="$FERMETURE" '
      dans            { if ($0 ~ f) dans = 0; next }
      $0 ~ o          { vides = ""; dans = 1; next }
      /^[[:space:]]*$/ { vides = vides $0 "\n"; next }
                      { printf "%s", vides; vides = ""; print }
      END             { printf "%s", vides }
    ' "$fichier"
    ;;

  php-imposes)
    # « AddHandler application/x-httpd-ea-php81 » ou « …alt-php82 » → 8.1 / 8.2.
    # Seules les lignes de gestionnaire DANS les blocs comptent.
    # « bash "$0" » et non « "$0" » : le script n'est pas forcément exécutable.
    bash "$0" blocs "$fichier" \
      | grep -oE '(ea|alt)-php[0-9]{2,3}' \
      | sed -E 's/.*php([0-9])([0-9]+)/\1.\2/' \
      | sort -u
    ;;

  *)
    sed -n '5,8p' "$0" >&2
    exit 64
    ;;
esac
