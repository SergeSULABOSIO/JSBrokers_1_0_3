<?php

namespace App\Echange\Etat;

/**
 * INJECTE UN VRAI TABLEAU CROISÉ DYNAMIQUE dans un classeur déjà écrit.
 *
 * ── POURQUOI ÇA NE PASSE PAS PAR PhpSpreadsheet ─────────────────────────────────────
 * ⚠ LA BIBLIOTHÈQUE NE SAIT PAS FAIRE DE TCD. Vérifié dans la version installée (5.7) :
 * aucune classe, aucune partie d'écriture — `GETPIVOTDATA` n'existe que comme nom de
 * fonction dans le moteur de calcul. Un tableau croisé est un ensemble de parties OOXML
 * distinctes, qu'il faut donc écrire à la main DANS le zip, une fois le classeur sauvé.
 *
 * Ce n'est pas un contournement de confort : c'est la seule façon d'obtenir des champs
 * déplaçables, un repli/dépli et un bouton « Actualiser ». Une synthèse à coups de
 * SOMME.SI.ENS en aurait l'apparence sans en avoir aucun des usages.
 *
 * ── LA DÉCISION QUI RÉDUIT LE RISQUE : refreshOnLoad ────────────────────────────────
 * ⚠ ON N'ÉCRIT PAS LE CACHE. Un TCD s'accompagne normalement d'un `pivotCacheRecords`
 * qui RECOPIE toutes les données source — doublant le poids du fichier, et multipliant
 * les occasions de produire un cache qui contredit la feuille. On écrit donc un cache
 * VIDE marqué `refreshOnLoad="1"` : Excel le reconstruit lui-même à l'ouverture, depuis
 * la plage source. Moins d'octets, moins de XML à avoir juste, et aucune divergence
 * possible entre le cache et les données.
 *
 * Même raison pour les `<items>` des champs de ligne : on ne les énumère pas. Excel les
 * peuple au rafraîchissement. Chaque valeur qu'on n'écrit pas est une valeur qu'on ne
 * peut pas écrire de travers.
 *
 * ── CE QU'IL FAUT SAVOIR AVANT D'Y TOUCHER ──────────────────────────────────────────
 * ⚠ UNE ERREUR ICI NE SE VOIT PAS À L'EXÉCUTION : le fichier se produit, s'envoie, et
 * c'est EXCEL qui annonce « fichier corrompu » à l'ouverture. Aucun test PHP ne peut
 * l'attraper. Les tests de cette classe vérifient donc la STRUCTURE du zip et la
 * conformité XML de chaque partie — c'est le plus loin qu'on puisse aller sans Excel.
 */
final class InjecteurDeTcd
{
    /** Nom de la feuille qui porte le croisement. */
    public const FEUILLE = 'SYNTHESE';

    /** Cobalt de la marque : l'en-tête du croisement le porte comme le reste du classeur. */
    private const COBALT = 'FF0047AB';

    /**
     * @param string   $chemin        classeur .xlsx déjà écrit
     * @param string   $feuilleSource nom de la feuille de données
     * @param string   $plageSource   plage des données, en-tête compris (« A1:BI80 »)
     * @param int      $indexFeuille  index 1-based du fichier sheetN.xml de la SYNTHESE
     * @param int[]    $champsLigne   index 0-based des colonnes mises en LIGNE
     * @param int[]    $champsValeur  index 0-based des colonnes SOMMÉES
     * @param string[] $entetes       libellés des colonnes de la source, dans l'ordre
     */
    public function injecter(
        string $chemin,
        string $feuilleSource,
        string $plageSource,
        int $indexFeuille,
        array $champsLigne,
        array $champsValeur,
        array $entetes,
    ): void {
        $zip = new \ZipArchive();
        if ($zip->open($chemin) !== true) {
            throw new \RuntimeException('Le classeur produit n\'a pas pu être rouvert pour y poser la synthèse.');
        }

        try {
            $this->ecrirePartie($zip, 'xl/pivotCache/pivotCacheDefinition1.xml', $this->cacheDefinition(
                $feuilleSource,
                $plageSource,
                $entetes,
            ));
            $this->ecrirePartie($zip, 'xl/pivotCache/pivotCacheRecords1.xml', $this->cacheRecords());
            $this->ecrirePartie($zip, 'xl/pivotCache/_rels/pivotCacheDefinition1.xml.rels', $this->relsCache());
            $this->ecrirePartie($zip, 'xl/pivotTables/pivotTable1.xml', $this->pivotTable(
                $champsLigne,
                $champsValeur,
                $entetes,
            ));
            $this->ecrirePartie($zip, 'xl/pivotTables/_rels/pivotTable1.xml.rels', $this->relsPivot());

            $this->declarerDansLesTypes($zip);
            $this->declarerDansLeClasseur($zip);
            $this->rattacherALaFeuille($zip, $indexFeuille);
        } finally {
            $zip->close();
        }
    }

    /**
     * DÉFINITION DU CACHE : où sont les données, et quels champs elles portent.
     *
     * ⚠ `refreshOnLoad="1"` ET `recordCount="0"` vont ensemble : ils disent à Excel « le
     * cache est vide, reconstruis-le depuis la plage ». Sans le premier, Excel afficherait
     * un croisement désespérément vide ; sans le second, il chercherait des lignes qui
     * n'existent pas.
     *
     * @param string[] $entetes
     */
    private function cacheDefinition(string $feuilleSource, string $plageSource, array $entetes): string
    {
        $champs = '';
        foreach ($entetes as $entete) {
            // Aucun `sharedItems` énuméré : Excel les peuple au rafraîchissement. Une
            // liste de valeurs écrite à la main, c'est une liste qui peut être fausse.
            $champs .= sprintf(
                '<cacheField name="%s" numFmtId="0"><sharedItems/></cacheField>',
                $this->echapper($entete),
            );
        }

        return $this->entete()
            . '<pivotCacheDefinition xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"'
            . ' r:id="rId1" refreshOnLoad="1" refreshedBy="JS Brokers" recordCount="0"'
            . ' createdVersion="3" refreshedVersion="3" minRefreshableVersion="3">'
            . sprintf(
                '<cacheSource type="worksheet"><worksheetSource ref="%s" sheet="%s"/></cacheSource>',
                $this->echapper($plageSource),
                $this->echapper($feuilleSource),
            )
            . sprintf('<cacheFields count="%d">%s</cacheFields>', \count($entetes), $champs)
            . '</pivotCacheDefinition>';
    }

    /** Cache VIDE : Excel le remplit à l'ouverture. Cf. l'en-tête de la classe. */
    private function cacheRecords(): string
    {
        return $this->entete()
            . '<pivotCacheRecords xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" count="0"/>';
    }

    /**
     * LE CROISEMENT LUI-MÊME : quels champs en ligne, quels champs sommés.
     *
     * ⚠ TOUT CHAMP DE LA SOURCE DOIT AVOIR SON `<pivotField>`, dans l'ordre exact des
     * colonnes — même ceux qu'on n'utilise pas. Excel apparie par POSITION : un champ
     * omis décale tous les suivants, et le croisement somme alors la mauvaise colonne
     * sans que rien ne le signale.
     *
     * @param int[]    $champsLigne
     * @param int[]    $champsValeur
     * @param string[] $entetes
     */
    private function pivotTable(array $champsLigne, array $champsValeur, array $entetes): string
    {
        $champs = '';
        foreach (array_keys($entetes) as $index) {
            if (\in_array($index, $champsLigne, true)) {
                // `showAll="0"` : on ne montre pas les lignes sans valeur. Les items sont
                // laissés à Excel, qui les peuple au rafraîchissement.
                $champs .= '<pivotField axis="axisRow" compact="0" outline="0" showAll="0" defaultSubtotal="1"/>';
            } elseif (\in_array($index, $champsValeur, true)) {
                $champs .= '<pivotField dataField="1" compact="0" outline="0" showAll="0"/>';
            } else {
                $champs .= '<pivotField compact="0" outline="0" showAll="0"/>';
            }
        }

        $lignes = '';
        foreach ($champsLigne as $index) {
            $lignes .= sprintf('<field x="%d"/>', $index);
        }

        $valeurs = '';
        foreach ($champsValeur as $index) {
            $valeurs .= sprintf(
                '<dataField name="Somme de %s" fld="%d" baseField="0" baseItem="0" numFmtId="4"/>',
                $this->echapper($entetes[$index]),
                $index,
            );
        }

        // ── L'AXE DES COLONNES QUAND IL Y A PLUSIEURS VALEURS ───────────────────────
        //
        // ⚠ C'EST CE QUI MANQUAIT, ET EXCEL A REFUSÉ LE FICHIER POUR ÇA. Son journal de
        // réparation nommait la partie : « Rapport de tableau croisé dynamique dans
        // /xl/pivotTables/pivotTable1.xml ».
        //
        // Dès qu'un croisement porte PLUS D'UNE valeur, ces valeurs occupent l'axe des
        // colonnes, et cet axe doit être déclaré par un champ de rang -2 — le pseudo-champ
        // « Σ Valeurs ». `colItems` compte alors une entrée par valeur. Un `colItems` à une
        // seule entrée sans `colFields`, comme je l'avais écrit, ne décrit qu'un croisement
        // à UNE valeur : avec sept, la définition se contredisait.
        $nbValeurs = \count($champsValeur);
        if ($nbValeurs > 1) {
            $colFields = '<colFields count="1"><field x="-2"/></colFields>';
            $colItems = sprintf('<colItems count="%d">', $nbValeurs);
            for ($i = 0; $i < $nbValeurs; ++$i) {
                // La première entrée n'a pas d'indice ; les suivantes portent leur rang.
                $colItems .= $i === 0
                    ? '<i><x/></i>'
                    : sprintf('<i i="%d"><x v="%d"/></i>', $i, $i);
            }
            $colItems .= '</colItems>';
        } else {
            $colFields = '';
            $colItems = '<colItems count="1"><i/></colItems>';
        }

        // ⚠ `rowItems` EST OBLIGATOIRE dès qu'il y a des champs en ligne. Une amorce
        // suffit : le contenu réel est reconstruit au rafraîchissement, mais l'élément
        // doit exister, et se placer ENTRE `rowFields` et `colFields`.
        $rowItems = $champsLigne === [] ? '' : '<rowItems count="1"><i><x/></i></rowItems>';

        // ⚠ L'ORDRE DES ENFANTS EST IMPOSÉ PAR LE SCHÉMA, et il ne pardonne pas :
        // location, pivotFields, rowFields, rowItems, colFields, colItems, dataFields,
        // puis le style. Un élément déplacé fait rejeter tout le rapport.
        return $this->entete()
            . '<pivotTableDefinition xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' name="Synthese" cacheId="1" dataOnRows="0" applyNumberFormats="0" applyBorderFormats="0"'
            . ' applyFontFormats="0" applyPatternFormats="0" applyAlignmentFormats="0" applyWidthHeightFormats="1"'
            . ' dataCaption="Valeurs" updatedVersion="3" createdVersion="3" minRefreshableVersion="3"'
            . ' showMemberPropertyTips="0" useAutoFormatting="1" itemPrintTitles="1" indent="0"'
            . ' outline="1" outlineData="1" multipleFieldFilters="0" compact="0" compactData="0">'
            . sprintf(
                '<location ref="A3:%s%d" firstHeaderRow="1" firstDataRow="2" firstDataCol="1"/>',
                // La zone est une amorce qu'Excel recalcule : elle doit seulement être
                // assez large pour la colonne d'étiquettes et chaque valeur.
                $this->lettre(1 + max(1, $nbValeurs)),
                24,
            )
            . sprintf('<pivotFields count="%d">%s</pivotFields>', \count($entetes), $champs)
            . sprintf('<rowFields count="%d">%s</rowFields>', \count($champsLigne), $lignes)
            . $rowItems
            . $colFields
            . $colItems
            . sprintf('<dataFields count="%d">%s</dataFields>', $nbValeurs, $valeurs)
            // Style d'Excel, proche du cobalt de la charte. Peindre les cellules à la main
            // serait vain : Excel les réécrit au rafraîchissement.
            . '<pivotTableStyleInfo name="PivotStyleMedium2" showRowHeaders="1" showColHeaders="1"'
            . ' showRowStripes="0" showColStripes="0" showLastColumn="1"/>'
            . '</pivotTableDefinition>';
    }

    /** Lettre de colonne d'un rang 1-based, sans dépendre d'une classe de tableur. */
    private function lettre(int $rang): string
    {
        $lettre = '';
        while ($rang > 0) {
            $reste = ($rang - 1) % 26;
            $lettre = chr(65 + $reste) . $lettre;
            $rang = (int) (($rang - $reste - 1) / 26);
        }

        return $lettre;
    }

    private function relsCache(): string
    {
        return $this->entete()
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1"'
            . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/pivotCacheRecords"'
            . ' Target="pivotCacheRecords1.xml"/>'
            . '</Relationships>';
    }

    private function relsPivot(): string
    {
        return $this->entete()
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1"'
            . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/pivotCacheDefinition"'
            . ' Target="../pivotCache/pivotCacheDefinition1.xml"/>'
            . '</Relationships>';
    }

    /**
     * ⚠ SANS DÉCLARATION DANS `[Content_Types].xml`, EXCEL REFUSE LE FICHIER. C'est la
     * table qui dit de quel type est chaque partie ; une partie non déclarée rend le
     * paquet invalide, pas seulement incomplet.
     */
    private function declarerDansLesTypes(\ZipArchive $zip): void
    {
        $xml = (string) $zip->getFromName('[Content_Types].xml');
        $ajouts = '';
        foreach ([
            '/xl/pivotCache/pivotCacheDefinition1.xml' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.pivotCacheDefinition+xml',
            '/xl/pivotCache/pivotCacheRecords1.xml' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.pivotCacheRecords+xml',
            '/xl/pivotTables/pivotTable1.xml' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.pivotTable+xml',
        ] as $partie => $type) {
            $ajouts .= sprintf('<Override PartName="%s" ContentType="%s"/>', $partie, $type);
        }

        $zip->addFromString('[Content_Types].xml', str_replace('</Types>', $ajouts . '</Types>', $xml));
    }

    /**
     * Le classeur déclare le cache, et le relie.
     *
     * ⚠ L'IDENTIFIANT DE RELATION DOIT ÊTRE LIBRE. PhpSpreadsheet en a déjà posé
     * plusieurs (feuilles, styles, thème) : on prend le premier rang au-delà de ceux qui
     * existent, plutôt qu'un numéro fixe qui écraserait une relation en silence.
     */
    private function declarerDansLeClasseur(\ZipArchive $zip): void
    {
        $rels = (string) $zip->getFromName('xl/_rels/workbook.xml.rels');
        preg_match_all('/Id="rId(\d+)"/', $rels, $trouves);
        $prochain = 'rId' . (max(array_map('intval', $trouves[1] ?: ['0'])) + 1);

        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->ajouterRelation($rels, sprintf(
            '<Relationship Id="%s"'
            . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/pivotCacheDefinition"'
            . ' Target="pivotCache/pivotCacheDefinition1.xml"/>',
            $prochain,
        )));

        $classeur = (string) $zip->getFromName('xl/workbook.xml');
        $caches = sprintf('<pivotCaches><pivotCache cacheId="1" r:id="%s"/></pivotCaches>', $prochain);

        // ⚠ L'ORDRE DES ÉLÉMENTS EST IMPOSÉ par le schéma : `pivotCaches` vient APRÈS
        // `definedNames` et `calcPr`, jamais avant. Posé au mauvais endroit, le fichier
        // est refusé — et l'erreur ne dit pas laquelle des deux choses cloche.
        $zip->addFromString('xl/workbook.xml', str_replace(
            '</workbook>',
            $caches . '</workbook>',
            $classeur,
        ));
    }

    /**
     * La feuille de synthèse pointe vers le croisement.
     *
     * Elle n'a peut-être aucune relation : PhpSpreadsheet n'écrit le fichier `.rels`
     * d'une feuille que si elle en a besoin. On le crée alors.
     */
    private function rattacherALaFeuille(\ZipArchive $zip, int $indexFeuille): void
    {
        $chemin = sprintf('xl/worksheets/_rels/sheet%d.xml.rels', $indexFeuille);
        $existant = $zip->getFromName($chemin);

        $relation = '<Relationship Id="rIdPivot1"'
            . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/pivotTable"'
            . ' Target="../pivotTables/pivotTable1.xml"/>';

        $zip->addFromString($chemin, $this->ajouterRelation($existant === false ? null : (string) $existant, $relation));
    }

    /**
     * Insère une relation dans un fichier `.rels`, quelle que soit sa forme.
     *
     * ⚠ UN FICHIER DE RELATIONS VIDE EST AUTO-FERMANT. PhpSpreadsheet écrit
     * `<Relationships …/>` quand une feuille n'a aucune relation — et non
     * `<Relationships …></Relationships>`. Un simple remplacement de la balise fermante
     * ne trouve alors RIEN et ne fait RIEN, sans lever la moindre erreur : la feuille
     * reste détachée du croisement, le fichier se produit, et Excel n'affiche aucune
     * synthèse. C'est le contrôle du paquet OOXML qui a rattrapé ce cas.
     */
    private function ajouterRelation(?string $existant, string $relation): string
    {
        $enveloppe = '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';

        if ($existant === null || trim($existant) === '') {
            return $this->entete() . $enveloppe . $relation . '</Relationships>';
        }

        // Forme auto-fermante : on la déplie avant d'y poser quoi que ce soit.
        $deplie = preg_replace(
            '#<Relationships([^>]*)/>#',
            '<Relationships$1></Relationships>',
            $existant,
            1,
        ) ?? $existant;

        if (!str_contains($deplie, '</Relationships>')) {
            // Forme inattendue : on refuse plutôt que d'écrire un fichier douteux.
            throw new \RuntimeException('Fichier de relations illisible : la synthèse ne peut pas y être rattachée.');
        }

        return str_replace('</Relationships>', $relation . '</Relationships>', $deplie);
    }

    private function ecrirePartie(\ZipArchive $zip, string $chemin, string $contenu): void
    {
        $zip->addFromString($chemin, $contenu);
    }

    private function entete(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
    }

    private function echapper(string $valeur): string
    {
        return htmlspecialchars($valeur, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
