<?php
namespace App\Services;


use DateTime;
use DateInterval;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

class ServiceDates
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function ajouterJours(DateTimeImmutable $dateInitiale, $nbJours): DateTimeImmutable
    {
        $txt = "P" . $nbJours . "D";
        $copie = clone $dateInitiale;
        return $copie->add(new DateInterval($txt));
    }

    public function ajouterAnnees(DateTimeImmutable $dateInitiale, $nbAnnee): DateTimeImmutable
    {
        $txt = "P" . $nbAnnee . "Y";
        $copie = clone $dateInitiale;
        return $copie->add(new DateInterval($txt));
    }

    public function ajouterMinutes(DateTime $dateInitiale, $nbMinutes): DateTime
    {
        $txt = "PT" . $nbMinutes . "M";
        $copie = clone $dateInitiale;
        return $copie->add(new DateInterval($txt));
    }

    public function getTexte(DateTimeImmutable $date): string
    {
        return $date->format('d/m/Y à H:i');
    }

    public function getTexteSimple(DateTimeImmutable $date): string
    {
        return $date->format('d/m/Y');
    }

    public function getMois(DateTimeImmutable $date)
    {
        return $date->format('n');
    }

    public function aujourdhui(): DateTimeImmutable
    {
        return new \DateTimeImmutable("now");
    }

    public function dansUneAnnee(): DateTimeImmutable
    {
        return new \DateTimeImmutable("+365 days");
    }

    public function hier(): DateTimeImmutable
    {
        return new \DateTimeImmutable("-1 days");
    }

    public function demain(): DateTimeImmutable
    {
        return new \DateTimeImmutable("+1 days");
    }

    public function dansUneSemaine(): DateTimeImmutable
    {
        return new \DateTimeImmutable("+7 days");
    }

    public function daysEntre(DateTimeImmutable $dateA, DateTimeImmutable $dateB):int
    {
        /** @var DateInterval */
        $interval = $dateA->diff($dateB, true);
        $days = $interval->format("%a");
        // dd($interval->h);
        // dd($dateA > $dateB, $dateA, $dateB);

        return $dateA > $dateB == true ? -1 * $days : $days;
    }

    public function hoursEntre(DateTimeImmutable $dateA, DateTimeImmutable $dateB):int
    {
        /** @var DateInterval */
        $interval = $dateA->diff($dateB);
        $hours = $interval->format("%h");
        // dd($interval->h);
        return $hours;
    }

    public function minutesEntre(DateTimeImmutable $dateA, DateTimeImmutable $dateB):int
    {
        /** @var DateInterval */
        $interval = $dateA->diff($dateB);
        $hours = $interval->format("%i");
        // dd($interval->h);
        return $hours;
    }
}
