# Modèle de données

Ce document présente le modèle de données et explique ses choix. Les règles métier et leurs justifications figurent dans [DECISIONS.md](DECISIONS.md).

## Diagramme entité-association

![Diagramme entité-association : dix tables et leurs quatorze relations](modele-donnees.png)

Le diagramme montre les dix tables, toutes leurs colonnes et leurs quatorze relations. Les types y sont simplifiés ; les longueurs exactes figurent dans les entités Doctrine. La source modifiable est [modele-donnees.drawio](modele-donnees.drawio).

Les cardinalités sont celles que la base impose. Deux règles métier sont plus strictes et relèvent de l'application : un trajet comporte au moins deux étapes et N − 1 segments, et chaque réservation doit avoir un paiement simulé (prévu au niveau 2).

## Entités

Chaque entité correspond à une table au nom en `snake_case`, à l'exception de `User`, stockée dans `app_user`.

| Domaine | Entité | Rôle |
|---|---|---|
| Comptes | `User` | Compte unique, avec des rôles. Une même personne peut être conducteur et passager. |
| | `Vehicle` | Véhicule d'un conducteur et nombre de places proposables, hors conducteur. |
| Offre | `City` | Ville de la liste gérée depuis le back-office ; sert à la recherche. |
| | `Trip` | Trajet publié : véhicule, capacité, prix complet, état et gel de l'itinéraire. |
| | `TripStop` | Étape : ville, rang, point de rendez-vous, coordonnées, horaires. |
| | `TripSegment` | Tronçon entre deux étapes consécutives, avec sa distance en mètres. |
| Réservation | `Booking` | Réservation d'une ou plusieurs places entre une étape de montée et une étape de descente. |
| | `Payment` | Paiement simulé d'une réservation : payé, puis éventuellement remboursé. |
| Après le trajet | `Review` | Avis dans un sens donné, pour un trajet. |
| | `Notification` | Message affiché dans l'espace passager. |

## Choix de conception

### Le segment comme unité de calcul

Un trajet de N étapes comporte N − 1 segments. Le segment de rang `p` relie les étapes de rang `p` et `p + 1` : ses extrémités se déduisent des rangs et ne sont pas stockées.

Une réservation couvre les segments dont le rang est compris entre le rang de son étape de montée (inclus) et celui de son étape de descente (exclu) :

```text
occupation(p) = somme des places des réservations confirmées
                où rang(montée) <= p et rang(descente) > p
```

Exemple sur Agadir – Marrakech – Casablanca : une réservation Agadir – Marrakech occupe le segment 0 et laisse le segment 1 libre.

Déduire les extrémités des rangs supprime un risque d'incohérence, mais pas tous. La base garantit seulement l'unicité des rangs : leur continuité et le nombre de segments sont contrôlés par l'application.

### Occupation sans compteur

Il n'y a ni table de liaison réservation–segment, ni compteur de places. L'occupation est calculée depuis les réservations, ce qui évite de maintenir une valeur dupliquée. Son exactitude en cas d'accès concurrents repose sur le verrouillage du trajet et la lecture décrits dans [DECISIONS.md](DECISIONS.md).

### Le conducteur n'est pas stocké sur le trajet

Le conducteur d'un trajet est le propriétaire de son véhicule (`Trip::getDriver()`). Pour que cette déduction reste juste dans le temps, le propriétaire d'un véhicule utilisé par un trajet ne peut plus changer, et le véhicule d'un trajet existant non plus.

### Montants et distances en entiers

Les montants sont en centimes de MAD et les distances en mètres, tous stockés en entiers : aucun calcul monétaire n'utilise la virgule flottante. La réservation conserve le prix d'une place et le total, si bien qu'un changement ultérieur du prix complet ne la modifie pas.

### Gel de l'itinéraire

`trip.itinerary_frozen_at` ne peut être renseignée qu'une fois, sans retour possible, et un itinéraire gelé ne peut plus être modifié.

### Conservation de l'historique

Aucune clé étrangère ne supprime en cascade : une ligne référencée par une autre ne peut pas être supprimée. Les annulations sont des changements d'état, pas des suppressions.

### Coordonnées et horaires

Les coordonnées sont en `DECIMAL(9,6)`, ce qui couvre toute la plage de longitude avec une précision d'environ 10 cm.

Les dates sont stockées et traitées en UTC, ce qui rend les comparaisons insensibles aux changements d'heure du Maroc. La première étape n'a pas d'heure d'arrivée et la dernière n'a pas d'heure de départ.

## Contraintes en base

Les colonnes facultatives sont les dates d'annulation, de fin, de remboursement et de gel, `arrival_at`, `departure_at`, `trip.cancellation_reason` et `review.comment` ; toutes les autres sont `NOT NULL`.

Les contraintes d'unicité portent sur les colonnes marquées `UK` et sur ces combinaisons :

| Table | Colonnes uniques ensemble |
|---|---|
| `trip_stop` | `trip_id`, `position` |
| `trip_segment` | `trip_id`, `position` |
| `review` | `trip_id`, `author_id`, `target_id` |

Les contrôles `CHECK` portent uniquement sur la validité d'une ligne. Ils ne sont pas générés à partir du mapping Doctrine : ils sont écrits à la main dans la migration initiale.

| Table | Contrôle |
|---|---|
| `vehicle` | `passenger_seats > 0` |
| `trip` | `seat_capacity > 0`, `full_price_cents > 0` |
| `trip_stop` | `position >= 0` ; latitude entre −90 et 90 ; longitude entre −180 et 180 ; au moins un horaire renseigné ; départ postérieur ou égal à l'arrivée |
| `trip_segment` | `position >= 0`, `distance_meters > 0` |
| `booking` | `seats > 0` ; montants positifs ou nuls ; `total_price_cents = seat_price_cents * seats` ; étapes de montée et de descente différentes |
| `payment` | `amount_cents >= 0` |
| `review` | `rating` entre 1 et 5 ; auteur et destinataire différents |

## Règles appliquées hors de la base

Ces règles portent sur plusieurs lignes. Elles sont contrôlées par les entités, à la validation et à l'écriture par Doctrine ; une requête SQL directe peut les contourner.

**Déjà en place et testées**

- les étapes d'une réservation appartiennent à son trajet, et la montée précède la descente ;
- rangs d'étapes et de segments consécutifs, N − 1 segments, distances positives, horaires cohérents ;
- modification de l'itinéraire uniquement par `Trip::replaceItinerary()`, refusée une fois le trajet gelé ;
- propriétaire du véhicule et véhicule du trajet fixes, dans les conditions décrites plus haut ;
- capacité strictement positive, au plus égale aux places du véhicule, et jamais inférieure à l'occupation ;
- calcul du prix d'une portion, proportionnel à la distance.

**Prévues au niveau 2, pas encore implémentées**

- création d'une réservation sous verrou, avec contrôle de disponibilité ;
- interdiction de réserver son propre trajet, et réservation active unique par passager et par trajet ;
- gel de l'itinéraire à la première réservation confirmée ;
- paiement simulé et remboursements ;
- annulations par le passager et par le conducteur, et notifications associées ;
- fin de trajet et avis.
