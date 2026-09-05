<?php

namespace App\Tests\Ai;

use App\Entity\Entreprise;
use App\Entity\Invite;
use App\Entity\Utilisateur;
use App\Repository\EntrepriseRepository;
use App\Repository\InviteRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * UN CABINET ET UN INVITÉ POUR LES TESTS DE PROMPT — SANS DÉPENDRE DE CE QUI TRAÎNE.
 *
 * Ces tests ne s'intéressent ni au cabinet ni à l'invité : ils vérifient la forme du
 * prompt et le contenu des trousses. Il leur faut seulement un périmètre valide à
 * fournir à `AiScope`.
 *
 * ⚠ ILS PRENAIENT JUSQU'ICI LE PREMIER CABINET VENU (`findOneBy([])`), c'est-à-dire ce
 * qu'un autre test avait laissé derrière lui. Ils passaient donc ou échouaient selon
 * l'ordre d'exécution et selon l'état d'une base partagée — vingt-quatre tests rouges
 * dès qu'une suite voisine faisait le ménage derrière elle, pour une raison qui n'a
 * rien à voir avec ce qu'ils vérifient. Un test qui dépend des restes d'un autre ne
 * teste plus rien de fiable.
 *
 * Le jeu est donc créé s'il n'existe pas. On réutilise celui déjà en base quand il y en
 * a un : inutile d'ajouter une ligne à chaque exécution.
 */
trait JeuDeTestKetTrait
{
    private const CABINET_KET = 'PHPUnit Ket SARL';
    private const EMAIL_KET = 'phpunit-ket-prompt@test.local';

    /** @return array{0: Entreprise, 1: Invite} */
    private function jeuDeTestKet(): array
    {
        $conteneur = static::getContainer();

        $entreprise = $conteneur->get(EntrepriseRepository::class)->findOneBy([]);
        if ($entreprise !== null) {
            $invite = $conteneur->get(InviteRepository::class)->findOneBy(['entreprise' => $entreprise]);
            if ($invite !== null) {
                return [$entreprise, $invite];
            }
        }

        return $this->creerJeuDeTestKet();
    }

    /** @return array{0: Entreprise, 1: Invite} */
    private function creerJeuDeTestKet(): array
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $utilisateur = $em->getRepository(Utilisateur::class)->findOneBy(['email' => self::EMAIL_KET]);
        if ($utilisateur === null) {
            $utilisateur = (new Utilisateur())
                ->setEmail(self::EMAIL_KET)
                ->setNom('Ket')
                ->setVerified(true)
                ->setPassword('x');
            $em->persist($utilisateur);
        }

        $entreprise = (new Entreprise())
            ->setNom(self::CABINET_KET)
            ->setLicence('LIC')
            ->setAdresse('1 rue')
            ->setTelephone('+2430000')
            ->setRccm('R')
            ->setIdnat('I')
            ->setNumimpot('N');
        $entreprise->setUtilisateur($utilisateur);
        $em->persist($entreprise);
        $utilisateur->setConnectedTo($entreprise);
        $em->flush();

        $invite = (new Invite())->setNom('Le Patron')->setEmail(self::EMAIL_KET);
        $invite->setProprietaire(true);
        $invite->setEntreprise($entreprise);
        $invite->setUtilisateur($utilisateur);
        $em->persist($invite);
        $em->flush();

        return [$entreprise, $invite];
    }
}
