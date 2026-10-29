# WORKER DE KET EN DEVELOPPEMENT, SANS TERMINAL OUVERT (Serge, 2026-10-29).
#
# Installe une tache planifiee Windows qui lance `app:ket:worker` CHAQUE MINUTE, par
# php-win.exe (aucune fenetre ne s'ouvre). La commande prend un verrou de fichier : si un
# worker tourne deja (celui de `symfony serve`, ou le precedent), elle sort aussitot. Le
# worker s'arrete de lui-meme au bout de --time-limit (code neuf relu) et la tache le relance
# dans la minute.
#
#   Installer :  powershell -ExecutionPolicy Bypass -File bin\worker-ket-dev.ps1
#   Retirer   :  powershell -ExecutionPolicy Bypass -File bin\worker-ket-dev.ps1 -Retirer
#   Verifier  :  Get-ScheduledTask -TaskName 'Joseara - worker de Ket (dev)' | Get-ScheduledTaskInfo
#
# Le fichier est en ASCII : PowerShell 5.1 lit un UTF-8 sans BOM en Windows-1252.
param(
    [switch]$Retirer,
    [string]$Php = 'C:\xampp\php\php-win.exe',
    [int]$DureeDeVie = 900
)

$ErrorActionPreference = 'Stop'
$nom = 'Joseara - worker de Ket (dev)'
$racine = Split-Path -Parent $PSScriptRoot

if ($Retirer) {
    Unregister-ScheduledTask -TaskName $nom -Confirm:$false -ErrorAction SilentlyContinue
    Write-Output "Tache '$nom' retiree."
    return
}

if (-not (Test-Path $Php)) {
    throw "php-win.exe introuvable : $Php (passer -Php <chemin>)."
}

$action = New-ScheduledTaskAction -Execute $Php `
    -Argument "bin\console app:ket:worker --time-limit=$DureeDeVie" `
    -WorkingDirectory $racine
# Chaque minute, sans fin (dix ans).
$declencheur = New-ScheduledTaskTrigger -Once -At (Get-Date) `
    -RepetitionInterval (New-TimeSpan -Minutes 1) `
    -RepetitionDuration (New-TimeSpan -Days 3650)
# IgnoreNew : la tache ne lance pas une seconde instance tant que la premiere tourne (le
# verrou de la commande reste la vraie garantie).
$reglages = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -StartWhenAvailable `
    -ExecutionTimeLimit (New-TimeSpan -Seconds ($DureeDeVie + 600))

Register-ScheduledTask -TaskName $nom -Action $action -Trigger $declencheur -Settings $reglages `
    -Description 'Garde le worker de Ket (messenger:consume async) actif en developpement.' -Force | Out-Null

Write-Output "Tache '$nom' installee : app:ket:worker chaque minute ($racine)."
