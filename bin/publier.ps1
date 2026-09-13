# =============================================================================
#  JOSEARA — PUBLIER EN PRODUCTION. Un seul geste, depuis le poste de travail.
# -----------------------------------------------------------------------------
#     .\bin\publier.ps1 -Blanc        repetition a blanc : ne publie RIEN
#     .\bin\publier.ps1               publie master sur www.joseara.com
#     .\bin\publier.ps1 -SansTests    saute la suite de tests (a eviter)
#     .\bin\publier.ps1 -Retour <sha> revient a une version anterieure
#
#  ── CE QUE CE SCRIPT ENCHAINE ───────────────────────────────────────────────
#    1. refuse de partir d'un depot sale
#    2. lance la suite de tests — le seul garde-fou avant la production
#    3. rafraichit les dependances front ET LES COMMITE (assets/vendor est
#       versionne : c'est ce qui evite au SERVEUR de dependre d'un CDN)
#    4. pousse sur GitHub
#    5. appelle bin/deploy.sh sur le serveur, qui fait le vrai travail
#
#  ── POURQUOI LE FRONT EST CONSTRUIT ICI, ET PAS LA-BAS ──────────────────────
#  « importmap:install » telecharge depuis cdn.jsdelivr.net. Le faire sur le
#  poste, qui a Internet, plutot que sur un mutualise qui n'en a peut-etre pas,
#  supprime le seul point du deploiement qui pouvait echouer pour une raison
#  exterieure — et laisser le site a moitie a jour.
# =============================================================================
param(
    [switch]$Blanc,
    [switch]$SansTests,
    [string]$Retour = "",

    # A renseigner une fois, apres le diagnostic du serveur.
    # Exemple : "joseara@srv123.hebergeur.com" ou "joseara@www.joseara.com -p 2222"
    [string]$Serveur = $env:JOSEARA_SSH,
    [string]$Chemin  = "/home/joseara/joseara",
    [string]$Branche = "master",
    [string]$Remote  = "JSBrokers_1_0_3"
)

$ErrorActionPreference = "Stop"
Set-Location (Split-Path $PSScriptRoot -Parent)

function Etape($texte) { Write-Host "`n== $texte" -ForegroundColor Cyan }
function Bien($texte)  { Write-Host "   OK  $texte" -ForegroundColor Green }
function Mal($texte)   { Write-Host "   !!  $texte" -ForegroundColor Red }

if (-not $Serveur) {
    Mal "Serveur SSH inconnu."
    Write-Host "   Renseignez-le une fois pour toutes :"
    Write-Host '     [Environment]::SetEnvironmentVariable("JOSEARA_SSH", "joseara@srv123.hebergeur.com", "User")'
    Write-Host "   ou passez-le a l'appel : .\bin\publier.ps1 -Serveur joseara@srv123.hebergeur.com"
    Write-Host ""
    Write-Host "   Sans SSH, le deploiement passe par cPanel -> Git Version Control :"
    Write-Host "   « Update from Remote » puis « Deploy HEAD Commit » (voir .cpanel.yml)."
    exit 1
}

# --- 1. Rien ne part d'un depot sale -----------------------------------------
# Publier un etat non committe, c'est publier quelque chose qu'on ne pourra pas
# retrouver ni annuler : le serveur, lui, ne connait que des commits.
Etape "Controle du depot local"
$sale = git status --porcelain
if ($sale) {
    Mal "Des modifications ne sont pas committees :"
    git status --short
    Write-Host "`n   Committez-les, ou mettez-les de cote (git stash), puis relancez."
    exit 1
}
Bien "Depot propre sur $(git rev-parse --abbrev-ref HEAD) — $(git rev-parse --short HEAD)"

# --- 2. La suite de tests ----------------------------------------------------
if (-not $SansTests) {
    Etape "Suite de tests"
    php bin/phpunit --no-coverage
    if ($LASTEXITCODE -ne 0) {
        Mal "Tests en echec — publication annulee."
        exit 1
    }
    Bien "Suite au vert"
} else {
    Mal "Tests SAUTES a la demande"
}

# --- 3. Dependances front, construites ICI et versionnees --------------------
Etape "Dependances front (importmap)"
php bin/console importmap:install
if ($LASTEXITCODE -ne 0) { Mal "importmap:install a echoue"; exit 1 }

if (git status --porcelain -- assets/vendor) {
    git add assets/vendor
    git commit -m "assets: rafraichissement des dependances importmap"
    Bien "assets/vendor mis a jour et committe"
} else {
    Bien "assets/vendor deja a jour"
}

# --- 4. Envoi ----------------------------------------------------------------
Etape "Envoi vers GitHub ($Remote/$Branche)"
git push $Remote $Branche
if ($LASTEXITCODE -ne 0) { Mal "git push a echoue"; exit 1 }
Bien "Pousse"

# --- 5. Deploiement distant : LE geste ---------------------------------------
$options = @()
if ($Blanc)  { $options += "--dry-run" }
if ($Retour) { $options += "--rollback=$Retour" }

Etape "Deploiement sur www.joseara.com"
if ($Blanc) { Write-Host "   (repetition a blanc : le serveur ne modifiera rien)" -ForegroundColor Yellow }

ssh $Serveur "bash $Chemin/bin/deploy.sh $($options -join ' ')"
if ($LASTEXITCODE -ne 0) {
    Mal "Deploiement en echec — voir le journal ci-dessus."
    Write-Host "   Les journaux complets sont sur le serveur, dans ~/logs/."
    exit 1
}

if ($Blanc) {
    Etape "Repetition a blanc terminee — rien n'a ete publie."
} else {
    Etape "Publie."
    Start-Process "https://www.joseara.com/"
}
