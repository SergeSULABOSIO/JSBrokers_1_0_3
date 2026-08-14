<?php

namespace App\Tests\Ai;

use App\Ai\Fichier\PreuveDeContrat;
use App\Entity\AssistantConversationFichier;
use PHPUnit\Framework\TestCase;

/**
 * UN CONTRAT JOINT PROUVE QUE LA POLICE EXISTE.
 *
 * Incident du 2026-08-14 : le courtier attache « CONTRACT-MBUSA-KAYITH….pdf », et Ket
 * lui demande s'il existe un contrat à enregistrer — le contrat sous les yeux. L'étape
 * « Le contrat (avenant) » était rangée parmi les optionnelles, avec les tâches de
 * suivi, et le plan l'omettait : une cotation sans police, donc un dossier dont les
 * primes et les commissions ne comptent nulle part.
 *
 * Ces tests verrouillent les DEUX sens. Promouvoir trop peu laisse la panne ;
 * promouvoir trop transformerait en contrat une proposition encore en négociation,
 * ce qui est l'erreur exactement inverse, et plus grave.
 */
class PreuveDeContratTest extends TestCase
{
    private function fichier(?string $nom, ?string $texte = null): AssistantConversationFichier
    {
        $fichier = new AssistantConversationFichier();
        $fichier->setNomOriginal($nom);
        $fichier->setTexteExtrait($texte);

        return $fichier;
    }

    /** LE CAS DE L'INCIDENT, au nom de fichier près. */
    public function testUnContratScanneEstReconnuParSonNom(): void
    {
        $this->assertTrue(PreuveDeContrat::presenteDans([
            // PDF scanné : aucun texte extractible, c'est le cas le PLUS courant en
            // production pour un contrat signé.
            $this->fichier('CONTRACT-MBUSA-KAYITHESA-2026.pdf'),
        ]));
    }

    /** Le texte extrait suffit, même quand le nom du fichier ne dit rien. */
    public function testUnePieceContractuelleEstReconnueParSonTexte(): void
    {
        $this->assertTrue(PreuveDeContrat::presenteDans([
            $this->fichier('doc-001.pdf', "CONDITIONS PARTICULIÈRES\nSouscripteur : Mbusa Jean de Dieu"),
        ]));
        $this->assertTrue(PreuveDeContrat::presenteDans([
            $this->fichier('scan.pdf', 'Police N° 12002-33002-0021-111-00071014-2025'),
        ]));
    }

    /**
     * L'AUTRE SENS, ET C'EST LUI QUI PROTÈGE. Une COTATION parle d'assurance, de prime,
     * d'assureur et de souscripteur exactement comme un contrat. Si ces mots suffisaient,
     * on promouvrait l'étape « contrat » sur la proposition qu'on est justement en train
     * de négocier — et le plan écrirait une police qui n'existe pas.
     */
    public function testUneCotationNEstPasUnePreuveDeContrat(): void
    {
        $this->assertFalse(PreuveDeContrat::presenteDans([
            $this->fichier(
                'cotation-sunu.pdf',
                "PROPOSITION D'ASSURANCE\nAssureur : SUNU IARD RDC\nPrime nette : 80,00 USD\n"
                . 'Souscripteur : Mbusa Jean de Dieu',
            ),
        ]));
    }

    /** Une pièce quelconque du dossier ne transforme rien. */
    public function testUnePieceQuelconqueNePromeutRien(): void
    {
        $this->assertFalse(PreuveDeContrat::presenteDans([
            $this->fichier('carte-identite.jpg', 'RÉPUBLIQUE DÉMOCRATIQUE DU CONGO'),
            $this->fichier('releve-bancaire.pdf', 'Solde au 31/07/2026'),
        ]));
        $this->assertFalse(PreuveDeContrat::presenteDans([]));
    }

    /** La casse, les accents et la ponctuation ne doivent pas faire manquer la preuve. */
    public function testLaDetectionIgnoreCasseEtAccents(): void
    {
        $this->assertTrue(PreuveDeContrat::presenteDans([
            $this->fichier('x.pdf', 'conditions particulieres du contrat'),
        ]));
        $this->assertTrue(PreuveDeContrat::presenteDans([
            $this->fichier('x.pdf', 'ATTESTATION D’ASSURANCE'),
        ]));
    }

    /** La pièce qui prouve est RENDUE, pour pouvoir être citée à l'utilisateur. */
    public function testLaPieceQuiProuveEstCitable(): void
    {
        $piece = PreuveDeContrat::piece([
            $this->fichier('releve.pdf', 'Solde'),
            $this->fichier('CONTRAT-2026.pdf'),
        ]);

        $this->assertNotNull($piece);
        $this->assertSame('CONTRAT-2026.pdf', $piece->getNomOriginal());
    }
}
