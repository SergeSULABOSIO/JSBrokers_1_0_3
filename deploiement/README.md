# Mise en production de Joseara — mode d'emploi

Tout ce qui concerne le déploiement de `www.joseara.com` sur l'hébergement
mutualisé cPanel. À lire une fois en entier avant la première mise en ligne ;
ensuite, seule la section **« Publier une mise à jour »** sert au quotidien.

| Fichier | Rôle |
|---|---|
| `deploiement/diagnostic-serveur.php` | Script **jetable** qui interroge le serveur. À lancer en premier. |
| `bin/deploy.sh` | Le déploiement lui-même. Tourne **sur le serveur**. |
| `bin/publier.ps1` | La commande unique, **depuis votre poste Windows**. |
| `.cpanel.yml` | La même chose, **sans SSH**, par cPanel → Git Version Control. |
| `public/.htaccess` | Réécriture des URL, maintenance, sécurité, cache. |
| `public/maintenance.html` | Page d'attente affichée pendant un déploiement. |

---

## Ce que le serveur a répondu — diagnostic du 2026-09-13

| | |
|---|---|
| Compte | `josearac` · `/home/josearac` |
| PHP | **8.2.28**, 64 bits, SAPI **litespeed**, CloudLinux **alt-php** (`/opt/alt/php82/usr/bin/php`) |
| Serveur web | **LiteSpeed** — il lit les `.htaccess` comme Apache, mais ignore les directives `mod_deflate` (il compresse lui-même) |
| `open_basedir` | **aucun** → le code peut vivre hors de la racine web : **plan A possible** |
| `disable_functions` | **aucune** → `proc_open` disponible, donc `sendmail://` utilisable |
| Réseau sortant | packagist 95 ms · github 75 ms · googleapis 54 ms → **`composer install` et Gemini fonctionneront sur le serveur** |
| OPcache | actif, `validate_timestamps = true` → **les déploiements seront bien pris en compte** |
| Disque | 2,5 To libres |
| HTTPS | actif |
| Horloge | serveur en **UTC**, Kinshasa à **UTC+1** → tout cron s'écrit **une heure plus tôt** |
| Base | **MariaDB 10.11.10-MariaDB-cll-lve** — à recopier tel quel dans `serverVersion=` · droits DDL confirmés par un vrai CREATE/ALTER/DROP · base vierge |
| ⚠ Jeu de caractères | `character_set_server` = **latin1**. `doctrine.yaml` impose désormais utf8mb4 aux tables créées, mais **la base elle-même doit être convertie** — voir l'étape 2 |
| ICU (intl) | **64.2** — ancienne. Sans conséquence connue ici, mais à garder en tête si un format de date ou de montant paraît inattendu |

**Aucun blocage.** Sept réglages à corriger, tous dans cPanel, tous sans risque —
voir l'étape 2. La base de données restait à créer au moment du diagnostic.

---

## Étape 1 — Interroger le serveur *(à refaire après chaque changement de réglage)*

Quatre inconnues décident de la suite, et aucune ne se devine depuis le poste.

1. Ouvrir `deploiement/diagnostic-serveur.php` et **changer la constante `JETON`**.
2. Le téléverser dans `public_html` **sous un nom imprévisible** — par exemple
   `_d-9f3a71c4e8.php`, jamais `diagnostic.php`.
3. Ouvrir `https://www.joseara.com/_d-9f3a71c4e8.php?jeton=VOTRE-JETON`.
4. Copier la page entière, **puis supprimer le fichier**.

La page révèle la version de PHP, les fonctions désactivées et l'arborescence du
compte : c'est une carte du terrain. Le jeton évite qu'elle soit lue en passant,
la suppression évite qu'elle soit lue tout court.

Ce qu'il faut en retenir, dans l'ordre d'importance :

| Ce que dit le rapport | Ce que ça décide |
|---|---|
| `open_basedir` limité à `public_html` | Le code ne peut **pas** vivre hors de la racine web → il faut le point d'entrée déporté (voir Étape 3, plan C) |
| La version **exacte** de MariaDB | À recopier telle quelle dans `serverVersion=` : Doctrine choisit sa grammaire SQL dessus |
| `proc_open` désactivée | `MAILER_DSN=sendmail://default` ne marchera pas → passer par SMTP |
| `opcache.validate_timestamps = 0` | **Un déploiement n'aurait aucun effet visible.** À corriger avant toute chose |
| `max_input_vars < 5000` | Les gros formulaires perdront des données **en silence** |
| packagist / github injoignables | `composer install` impossible sur le serveur → téléverser `vendor/` construit localement |
| googleapis injoignable | Ket ne pourra pas répondre → poser `AI_ENGINE=simulated` |

---

## Étape 2 — Préparer cPanel

| Où | Quoi |
|---|---|
| **Select PHP Version → Extensions** | cocher **`fileinfo`** — la seule manquante au 2026-09-13. VichUploader s'en sert pour reconnaître le type des fichiers téléversés : sans elle, tout dépôt de document échoue |
| **Select PHP Version → Options** | `memory_limit` **512M** · `max_execution_time` **120** · `max_input_time` **120** · `upload_max_filesize` **32M** · `post_max_size` **48M ou plus** — il doit DÉPASSER `upload_max_filesize`, la requête transportant le fichier *plus* les champs du formulaire |
| **Réglages OPcache** *(confort, pas bloquant)* | `opcache.memory_consumption` 128 → 192 · `opcache.max_accelerated_files` 10000 → 20000. Symfony compte plus de 10 000 fichiers : au plafond actuel, OPcache en évince en permanence et le gain retombe |
| **`max_input_vars`** | **Absent du sélecteur PHP de CloudLinux** — et c'est sans importance : `public/.user.ini`, versionné avec l'application, le pose à 5000. PHP lit ce fichier en CGI/FastCGI/LSAPI, ce qui est le cas ici. Compter jusqu'à **5 minutes** avant effet (`user_ini.cache_ttl`). Si **MultiPHP INI Editor** existe dans votre cPanel, on peut aussi l'y poser — mais ce n'est pas nécessaire |
| **phpMyAdmin → SQL** | **`ALTER DATABASE \`josearac_joseara\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;`** — le serveur est en latin1. Sans cela, les tables créées sans charset explicite naîtraient en latin1, et toute jointure avec une table utf8mb4 échouerait sur « Illegal mix of collations », à un endroit qui ne dit rien de la cause. À faire **avant** le premier déploiement, pendant que la base est vide |
| **MySQL Databases** | créer la base, créer l'utilisateur, puis **Add User To Database → ALL PRIVILEGES**. cPanel **préfixe** les noms du compte et les tronque : recopier le nom exact qu'il affiche |
| **Email Accounts** | créer `contact@joseara.com` |
| **Email Deliverability** | → **Repair** jusqu'à SPF, DKIM et PTR en vert. Sans cela, chaque e-mail d'inscription part en indésirable et l'inscription *paraît* cassée |
| **SSL/TLS Status** | AutoSSL sur `joseara.com`, `www.` et `mail.` |
| **Domains** | « Force HTTPS Redirect ». ⚠ **Soit** cet interrupteur, **soit** le bloc HTTPS commenté du `.htaccess` — jamais les deux, sinon boucle de redirection |
| **Cron Jobs** | renseigner « Cron Email » avec une adresse réellement relevée |

---

## Étape 3 — Poser l'application

### Arborescence visée

```
/home/josearac/
├── joseara/            ← le dépôt git, HORS racine web
│   ├── .env.local      ← les secrets, chmod 600, jamais versionné
│   ├── public/         ← la racine web réelle
│   └── var/            ⚠ contient des DONNÉES (voir plus bas)
├── public_html  →  /home/josearac/joseara/public
├── backups/            dumps SQL horodatés, hors web
└── logs/               journaux de déploiement et de crons, hors web
```

```bash
git clone --depth 1 --branch master \
  https://github.com/SergeSULABOSIO/JSBrokers_1_0_3.git /home/josearac/joseara
```

### Faire pointer la racine de document sur `public/`

**Plan A — déplacer la racine (le mieux).** cPanel → **Domains** → `joseara.com`
→ *Manage* → **Document Root** → `/home/josearac/joseara/public`.

**Plan B — lien symbolique**, si le champ est grisé :

```bash
mv ~/public_html ~/public_html.orig && ln -s ~/joseara/public ~/public_html
```

**Plan C — la racine est immuable**, ou `open_basedir` interdit le hors-docroot.
`public_html` devient alors la racine réelle : y déposer un `index.php` qui
appelle `/home/josearac/joseara/vendor/autoload_runtime.php`, et faire recopier
`public/assets`, `public/bundles`, `public/images` et `.htaccess` par le script
de déploiement. Ce plan demande une demi-journée de plus : ne l'adopter que si
les plans A et B sont réellement impossibles.

> **Ce qu'il ne faut pas faire** : un `.htaccess` dans `public_html` qui réécrit
> vers un chemin disque hors racine. Apache refuse de servir les fichiers
> statiques hors de sa racine — les assets, les logos et les documents
> téléversés resteraient tous en 404.

---

## Étape 4 — Les secrets, sur le serveur uniquement

### `/home/josearac/joseara/.env.local` — `chmod 600`, jamais versionné

```bash
APP_ENV=prod
APP_DEBUG=0

# Généré par : php -r "echo bin2hex(random_bytes(16)), PHP_EOL;"
# ⚠ NE JAMAIS réutiliser la valeur qui était dans .env sur GitHub.
APP_SECRET=<32 caractères hexadécimaux>

# Horloge de référence — marché RDC, sans heure d'été. Ne plus y toucher après
# la mise en service : les colonnes DATETIME sont naïves, et changer ce fuseau
# décalerait le sens absolu de toutes les dates déjà enregistrées.
APP_TIMEZONE=Africa/Kinshasa

# serverVersion = ce qu'affiche le diagnostic, au caractère près.
# Si 127.0.0.1 refuse la connexion, essayer « localhost » : l'hébergeur
# n'expose alors que la socket Unix.
DATABASE_URL="mysql://<user>:<mdp>@127.0.0.1:3306/<base>?serverVersion=<10.6.21-MariaDB>&charset=utf8mb4"

# Base des liens fabriqués hors requête HTTP (crons, commandes, e-mails).
DEFAULT_URI=https://www.joseara.com

# VIDE, et c'est voulu. Le diagnostic du 2026-09-13 n'a trouvé AUCUNE en-tête
# X-Forwarded-* : LiteSpeed sert joseara.com directement, sans mandataire
# inverse devant lui. Déclarer des mandataires de confiance qui n'existent pas
# reviendrait à faire confiance à des en-têtes que n'importe quel visiteur peut
# forger — c'est-à-dire à se laisser dicter son propre nom d'hôte.
TRUSTED_PROXIES=

# ── E-MAIL ───────────────────────────────────────────────────────────────
# Boîte cPanel. Noter le %40 : c'est le « @ » de l'identifiant, encodé — sans
# lui, l'URL est coupée au mauvais endroit et l'authentification échoue.
MAILER_DSN=smtp://contact%40joseara.com:<mdp>@mail.joseara.com:465?encryption=ssl
MAILER_FROM=contact@joseara.com
# Repli si le SMTP distant est lent ou refusé, ET si proc_open est disponible :
# MAILER_DSN=sendmail://default

MESSENGER_TRANSPORT_DSN=doctrine://default?auto_setup=0

# ── ASSISTANT IA ─────────────────────────────────────────────────────────
AI_ENGINE=gemini
GEMINI_API_KEY=<clé AI Studio>
# Si le diagnostic a montré googleapis injoignable, poser plutôt :
# AI_ENGINE=simulated

# ── TÂCHES DE FOND ───────────────────────────────────────────────────────
# À 0 : tout est traité pendant la requête. Ne passer à 1 que le jour où un
# worker tourne vraiment et a fait ses preuves.
ASSISTANT_ASYNC=0
IMPORT_ASYNC=0
# Paliers d'import bornés : sur un mutualisé, dix requêtes courtes valent mieux
# qu'une seule qui heurte max_execution_time.
IMPORT_PALIER=25
```

> ⚠ **Tant que `.env.local.php` existe, `.env.local` est ignoré.** Après toute
> modification des secrets, relancer `composer dump-env prod` — ou simplement
> `bin/deploy.sh`, qui le fait à chaque passage.

### `/home/josearac/.my.cnf` — `chmod 600`

Sert à `mysqldump` pour que le mot de passe n'apparaisse jamais dans `ps` ni
dans l'historique du shell. `mysqldump` **refuse** ce fichier s'il est lisible
par d'autres.

```ini
[client]
user = <user>
password = <mdp>
host = 127.0.0.1
default-character-set = utf8mb4
```

---

## Étape 5 — La première mise en ligne

```bash
cd /home/josearac/joseara

# Renseigner une fois le nom de la base, pour que la sauvegarde fonctionne
export JOSEARA_DB=<base>

bash bin/deploy.sh --dry-run   # tout doit être vert, rien n'est modifié
bash bin/deploy.sh             # LA mise en production
```

Puis créer le premier compte : s'inscrire sur le site avec `contact@joseara.com`
et relever le message de vérification dans **Webmail**. Si le courrier tarde
pendant le réglage de SPF/DKIM, débloquer **une seule fois** par phpMyAdmin :

```sql
UPDATE utilisateur
   SET verified = 1, roles = '["ROLE_SUPER_ADMIN"]'
 WHERE email = 'contact@joseara.com';
```

Créer ensuite le premier cabinet : le catalogue (monnaies, taxes, risques,
types d'absence…) est semé automatiquement. **Aucune fixture ne doit jamais
être jouée en production** — elles créeraient les comptes de démonstration
`admin@joseara.com` / `admin`.

### Recette à passer avant d'annoncer l'ouverture

1. La page d'accueil répond et **le JS et le CSS se chargent**.
2. `https://www.joseara.com/.env` renvoie **403 ou 404**, jamais du contenu.
3. Une route profonde (`/connexion`) répond 200.
4. Une inscription déclenche un e-mail **dont le lien pointe `https://www.joseara.com`**.
5. Un **PDF de note** sort stylé, en moins de deux secondes.
6. Un export Excel, un message à Ket, la page `/nouveautes`.
7. **Un second déploiement** : vérifier qu'on **reste connecté** après.

---

## Publier une mise à jour *(le geste quotidien)*

```powershell
cd C:\JSBrokers_1_0_3
.\bin\publier.ps1 -Blanc     # répétition à blanc : montre tout, ne publie rien
.\bin\publier.ps1            # publie
```

Ce qui s'enchaîne sans intervention : dépôt propre → suite de tests →
`importmap:install` → `git push` → sauvegarde SQL → maintenance → `git reset`
→ composer *(seulement si `composer.lock` a changé)* → `dump-env` →
`cache:clear` + `warmup` → `asset-map:compile` → migrations → réouverture →
contrôle HTTP.

**Durée** : 20 à 40 s si `composer.lock` n'a pas bougé, 3 à 5 min sinon.

**Sans SSH** : `git push`, puis cPanel → **Git Version Control** →
*Update from Remote* → *Deploy HEAD Commit*.

> Prendre le réflexe du `-Blanc` dès qu'une migration figure dans le lot. Et
> pas de publication le vendredi soir.

---

## Revenir en arrière

**Le code est fautif, la base est intacte** — le cas le plus fréquent :

```bash
bash bin/deploy.sh --rollback=<sha affiché au début du déploiement>
```

**Une migration a mal tourné** — restaurer le dump est la voie sûre :

```bash
touch public/maintenance.flag
gunzip -c ~/backups/db-<horodatage>.sql.gz | mysql --defaults-file=~/.my.cnf <base>
bash bin/deploy.sh --rollback=<sha> --skip-migrations --no-backup
rm -f public/maintenance.flag
```

**Le site ne répond plus du tout** : créer `public/maintenance.flag` à la main
depuis le Gestionnaire de fichiers, lire `var/log/prod-*.log`, puis l'un des
deux cas ci-dessus.

Ce qu'un retour arrière **ne défait pas** : les fichiers téléversés depuis la
sauvegarde, et les migrations dépourvues de `down()`.

---

## Les crons cPanel

**Trois pièges, avant de coller quoi que ce soit :**

1. **Le `%` est un saut de ligne pour cron.** `date +%F` coupe la commande en
   deux. Il faut écrire `date +\%F`. C'est la cause n°1 des crons qui « ne font
   rien ».
2. **Chemin PHP absolu obligatoire.** Le `php` du `PATH` de cron n'est pas celui
   du site : version et extensions peuvent différer.
3. **Les heures sont celles du serveur**, pas de Kinshasa. Le diagnostic donne
   l'écart à appliquer.

Dans les lignes ci-dessous, remplacer `josearac` et vérifier le chemin PHP.
Les heures sont exprimées **en heure de Kinshasa**.

```cron
# 06:30 — Relances de congés.
# ⚠ LA PREMIÈRE SEMAINE, RETIRER « --force » : sans lui la commande est en
#   répétition à blanc par construction et rapporte qui SERAIT relancé. On lit
#   sept jours avant d'écrire à de vrais valideurs.
30 5 * * * cd /home/josearac/joseara && /opt/alt/php82/usr/bin/php -d memory_limit=512M bin/console app:conges:rappels --force --env=prod --no-interaction >> /home/josearac/logs/conges-rappels.log 2>&1

# 01:15 — Synchronisation CRM.
15 0 * * * cd /home/josearac/joseara && /opt/alt/php82/usr/bin/php -d memory_limit=512M bin/console app:crm:sync --env=prod --no-interaction >> /home/josearac/logs/crm-sync.log 2>&1

# 01:45 — Automatisations CRM. Trente minutes APRÈS le sync, délibérément :
# elles lisent les instantanés de santé qu'il vient d'écrire.
45 0 * * * cd /home/josearac/joseara && /opt/alt/php82/usr/bin/php -d memory_limit=512M bin/console app:crm:run-automations --env=prod --no-interaction >> /home/josearac/logs/crm-automations.log 2>&1

# 02:30 — Purge des dépôts d'import expirés.
# ENJEU DE CONFIDENTIALITÉ, pas d'espace disque : ces dépôts contiennent des
# données de clients, et leur expiration est une promesse faite aux cabinets.
# Première semaine avec « --simuler ». Ensuite, SURVEILLER que ce cron tourne :
# son silence ressemble à un succès.
30 1 * * * cd /home/josearac/joseara && /opt/alt/php82/usr/bin/php -d memory_limit=512M bin/console app:echange:purger --env=prod --no-interaction >> /home/josearac/logs/echange-purge.log 2>&1

# 1er janvier — Ouverture de l'exercice de congés.
# Un cron annuel est un cron dont on découvre la panne un an trop tard : le
# 15 décembre, le lancer À LA MAIN sans « --force » et lire le résultat.
5 23 31 12 * cd /home/josearac/joseara && /opt/alt/php82/usr/bin/php -d memory_limit=512M bin/console app:conges:ouvrir-exercice --force --env=prod --no-interaction >> /home/josearac/logs/conges-exercice.log 2>&1

# 03:00 — LE CRON LE PLUS IMPORTANT DE LA LISTE.
# Ne pas se reposer sur les sauvegardes de l'hébergeur : leur rétention et leur
# délai de restauration ne sont pas sous votre contrôle. Et descendre une copie
# HORS du serveur une fois par mois — une sauvegarde qui vit sur la machine
# qu'elle protège n'en est pas une.
0 2 * * * /usr/bin/mysqldump --defaults-file=/home/josearac/.my.cnf --single-transaction --quick --routines --triggers --default-character-set=utf8mb4 <base> | /usr/bin/gzip -9 > /home/josearac/backups/auto-$(date +\%Y\%m\%d).sql.gz 2>> /home/josearac/logs/backup.log

# 03:30 — Rétention des sauvegardes de base à 30 jours.
30 2 * * * /usr/bin/find /home/josearac/backups -name 'auto-*.sql.gz' -mtime +30 -delete

# 03:45 dimanche — SAUVEGARDE DES FICHIERS. Un dump de base SEUL est une
# demi-sauvegarde : les documents vivent sur le DISQUE, la base ne contient que
# leurs noms. Restaurer l'un sans l'autre donne une application qui liste des
# pièces jointes introuvables — et personne ne s'en aperçoit avant d'en ouvrir
# une. Environ 215 Mo au 2026-09-13, d'où la cadence hebdomadaire.
45 2 * * 0 cd /home/josearac/joseara && /usr/bin/tar czf /home/josearac/backups/fichiers-$(date +\%Y\%m\%d).tar.gz var/uploads/assistant var/uploads/assistant-documents public/uploads/documents public/images/entreprises 2>> /home/josearac/logs/backup.log

# 04:15 dimanche — Rétention des sauvegardes de fichiers à 60 jours.
15 3 * * 0 /usr/bin/find /home/josearac/backups -name 'fichiers-*.tar.gz' -mtime +60 -delete

# Dimanche 04:00 — Entretien des journaux de crons (que personne ne borne).
0 3 * * 0 /usr/bin/find /home/josearac/logs -name '*.log' -size +20M -exec /usr/bin/truncate -s 0 {} \; ; /usr/bin/find /home/josearac/logs -name 'deploy-*.log' -mtime +60 -delete
```

### Worker Messenger : inutile au premier déploiement

Les e-mails partent en **synchrone** en production (`when@prod` de
`config/packages/messenger.yaml`), et `ASSISTANT_ASYNC=0` / `IMPORT_ASYNC=0`
gardent tout le reste dans la requête. **Rien n'attend dans la file.**

Le cron ci-dessous n'est à créer que le jour d'un passage à
`ASSISTANT_ASYNC=1` :

```cron
*/5 * * * * /usr/bin/flock -n /home/josearac/joseara/var/worker.lock -c "cd /home/josearac/joseara && /opt/alt/php82/usr/bin/php -d memory_limit=256M bin/console messenger:consume async --time-limit=280 --memory-limit=200M --limit=100 --env=prod --no-interaction -q" >> /home/josearac/logs/worker.log 2>&1
```

`flock -n` empêche deux workers de se superposer · `--time-limit=280` fait
mourir le worker avant le cron suivant · `--memory-limit` le tue avant que PHP
ne fuie · `-q` évite un e-mail à chaque passage.

---

## Deux garde-fous à ne jamais oublier

**`var/` n'est pas jetable.** Il contient `var/uploads/assistant/` et
`var/uploads/assistant-documents/` — les pièces jointes et les documents
produits par Ket, référencés en base, et qui pèsent déjà **137 Mo** sur le
poste de développement. Un `rm -rf var/` réflexe détruit des données
utilisateur **sans aucun message d'erreur**. Seul `var/cache` est jetable, et
`cache:clear` s'en charge proprement.

Les **cinq chemins qui portent des données** et qu'aucun déploiement ne doit
toucher — ce sont eux que sauvegarde le cron hebdomadaire :

```
var/uploads/assistant              pièces jointes du chat Ket
var/uploads/assistant-documents    documents produits par Ket
public/uploads/documents           pièces de sinistres
public/images/entreprises          logos des cabinets (mêlés aux fichiers de marque)
var/log                            journaux (utiles à un diagnostic après coup)
```

**`git clean` est interdit dans tout script de déploiement.**
`public/images/entreprises/` mêle des fichiers de marque suivis et les logos
téléversés par les cabinets ; `public/uploads/documents/` et `public/pdfs/` ne
contiennent que des données. C'est exactement pourquoi `bin/deploy.sh` utilise
`git reset --hard`, qui ne touche pas aux fichiers non suivis.
