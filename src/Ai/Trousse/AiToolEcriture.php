<?php

namespace App\Ai\Trousse;

/**
 * Marqueur : cet outil n'a de sens QUE dans la trousse d'écriture.
 *
 * Interface vide, sur le modèle de {@see \App\Ai\Tool\AiToolProduisantUnPlan}.
 * L'appartenance à une trousse est ainsi DÉRIVÉE DU CODE, jamais recopiée dans une
 * liste parallèle — c'est la leçon de {@see \App\Ai\Mutation\OutilsDePlan}, dont la
 * liste en dur avait dérivé deux fois en production.
 *
 * Y appartiennent les outils qui PRÉPARENT une écriture (tous ceux marqués
 * AiToolProduisantUnPlan, garanti par un test) et leurs compagnons de saisie —
 * ouverture de formulaire, inventaire des champs, trames de parcours — qui n'ont
 * aucune utilité à qui pose une question de consultation.
 *
 * CE N'EST PAS UNE SÉCURITÉ : execute() re-vérifie les droits en fail-closed.
 */
interface AiToolEcriture
{
}
