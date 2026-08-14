<?php

namespace App\Ai\Trousse;

/**
 * MARQUEUR : cet outil est déclaré à la phase de COMPRÉHENSION.
 *
 * La compréhension a besoin de la base pour lever une ambiguïté de RÉFÉRENCE — « de
 * quel client parle-t-il ? », « cette police existe-t-elle ? », « y en a-t-il
 * plusieurs qui portent ce nom ? ». Sans cela elle devrait poser une question là où
 * une recherche aurait tranché, ou pire, reformuler vers un objet qui n'existe pas.
 *
 * ELLE N'A PAS BESOIN DE FAIRE LE TRAVAIL, et c'est pourquoi cette trousse est
 * volontairement ÉTROITE. Deux raisons, également décisives :
 *
 *  1. LE COÛT. Les déclarations repartent à chaque tour et la trousse de lecture
 *     complète pèse ~43 Ko. Les y mettre toutes rendrait la compréhension aussi
 *     chère qu'une planification, et lui ferait perdre le seul argument qui
 *     justifie de l'avoir ajoutée.
 *  2. LE RÔLE. Un comprenant qui peut lire la comptabilité, la vigie et la
 *     saturation finira par RÉPONDRE à la question au lieu de la comprendre — et le
 *     travail serait alors fait deux fois, une fois mal.
 *
 * Élargir le périmètre = poser ce marqueur sur un outil de plus. Rien d'autre :
 * les déclarations, la section d'aiguillage du prompt et le filtre de périmètre en
 * découlent tous par TrousseCatalogue.
 */
interface AiToolDeComprehension
{
}
