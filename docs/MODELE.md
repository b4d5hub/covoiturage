# Modèle de données

Ce document décrit le modèle et explique ses choix. Les règles métier et leurs justifications figurent dans [DECISIONS.md](DECISIONS.md).

## Diagramme

```mermaid
erDiagram
    APP_USER ||--o{ VEHICLE : "possède"
    VEHICLE ||--o{ TRIP : "assure"
    TRIP ||--|{ TRIP_STOP : "comporte"
    TRIP ||--|{ TRIP_SEGMENT : "se découpe en"
    CITY ||--o{ TRIP_STOP : "accueille"
    TRIP_STOP ||--o{ TRIP_SEGMENT : "début ou fin"
    TRIP ||--o{ BOOKING : "reçoit"
    APP_USER ||--o{ BOOKING : "réserve"
    TRIP_STOP ||--o{ BOOKING : "montée ou descente"
    BOOKING ||--|| PAYMENT : "donne lieu à"
    TRIP ||--o{ REVIEW : "concerne"
    APP_USER ||--o{ REVIEW : "rédige ou reçoit"
    APP_USER ||--o{ NOTIFICATION : "reçoit"

    APP_USER {
        int id PK
        string email UK
        string password
        json roles
        string first_name
        string last_name
        datetime created_at
    }
    CITY {
        int id PK
        string name UK
    }
    VEHICLE {
        int id PK
        int owner_id FK
        string brand
        string model
        int passenger_seats
    }
    TRIP {
        int id PK
        int vehicle_id FK
        int seat_capacity
        int full_price_cents
        string status
        datetime itinerary_frozen_at
        text cancellation_reason
        datetime cancelled_at
        datetime completed_at
        datetime created_at
    }
    TRIP_STOP {
        int id PK
        int trip_id FK
        int city_id FK
        int position
        string meeting_address
        decimal latitude
        decimal longitude
        datetime arrival_at
        datetime departure_at
    }
    TRIP_SEGMENT {
        int id PK
        int trip_id FK
        int from_stop_id FK
        int to_stop_id FK
        int position
        int distance_meters
    }
    BOOKING {
        int id PK
        int trip_id FK
        int passenger_id FK
        int boarding_stop_id FK
        int alighting_stop_id FK
        int seats
        int seat_price_cents
        int total_price_cents
        string status
        datetime created_at
        datetime cancelled_at
    }
    PAYMENT {
        int id PK
        int booking_id FK
        int amount_cents
        string status
        datetime paid_at
        datetime refunded_at
    }
    REVIEW {
        int id PK
        int trip_id FK
        int author_id FK
        int target_id FK
        int rating
        text comment
        datetime created_at
    }
    NOTIFICATION {
        int id PK
        int recipient_id FK
        text message
        boolean is_read
        datetime created_at
    }
```

## Entités

| Entité | Rôle |
|---|---|
| `User` | Compte unique, avec des rôles. Une même personne peut être conducteur et passager. |
| `City` | Ville de la liste gérée depuis le back-office ; sert à la recherche. |
| `Vehicle` | Véhicule d'un conducteur et nombre de places proposables, hors conducteur. |
| `Trip` | Trajet publié : véhicule, capacité, prix complet, état et gel de l'itinéraire. |
| `TripStop` | Étape : ville, rang, point de rendez-vous, coordonnées, horaires. |
| `TripSegment` | Tronçon entre deux étapes consécutives, avec sa distance en mètres. |
| `Booking` | Réservation de une ou plusieurs places entre une étape de montée et une étape de descente. |
| `Payment` | Paiement simulé d'une réservation : payé, puis éventuellement remboursé. |
| `Review` | Avis dans un sens donné, pour un trajet. |
| `Notification` | Message affiché dans l'espace passager. |

## Explication des choix

### Le segment comme unité de calcul

Un trajet de N étapes comporte N − 1 segments. Le segment de rang `p` relie l'étape de rang `p` à l'étape de rang `p + 1`. Une réservation couvre les segments dont le rang est compris entre le rang de son étape de montée (inclus) et le rang de son étape de descente (exclu).

L'occupation d'un segment se déduit donc des réservations confirmées :

```text
occupation(p) = somme des places des réservations confirmées
                où rang(montée) <= p et rang(descente) > p
```

Exemple sur Agadir – Marrakech – Casablanca : une réservation Agadir – Marrakech occupe le segment 0 et laisse le segment 1 libre.

Il n'y a **ni table de liaison réservation–segment, ni compteur de places** : l'occupation a une seule source, les réservations, et ne peut pas diverger. Le calcul et le verrouillage sont détaillés dans [DECISIONS.md](DECISIONS.md).

### Le conducteur n'est pas stocké sur le trajet

Le conducteur d'un trajet est le propriétaire de son véhicule (`Trip::getDriver()`). Une colonne supplémentaire ferait exister la même information deux fois, avec un risque d'incohérence.

### Montants et distances en entiers

Les montants sont en centimes de MAD et les distances en mètres, tous stockés en entiers : aucun calcul monétaire n'utilise la virgule flottante. La réservation conserve le prix d'une place et le total, si bien qu'un changement ultérieur du prix complet ne la modifie pas.

### Gel de l'itinéraire

`Trip.itinerary_frozen_at` est renseignée dans la transaction de la première réservation confirmée et n'est jamais remise à zéro. Le gel reste donc acquis même si les réservations sont annulées ou si une ligne de réservation est supprimée.

### Conservation de l'historique

Les clés étrangères n'ont pas de suppression en cascade au niveau de la base : un compte, un véhicule ou un trajet référencé par des réservations ne peut pas être supprimé. Les annulations sont des changements d'état, pas des suppressions.

### Coordonnées géographiques

Les coordonnées sont en `DECIMAL(9,6)`, ce qui couvre toute la plage de longitude avec une précision d'environ 10 cm.

### Horaires

Les horaires sont stockés à l'heure du Maroc (`Africa/Casablanca`), fuseau configuré pour PHP. La première étape n'a pas d'heure d'arrivée, la dernière n'a pas d'heure de départ.

## Contraintes en base

Les contraintes `UNIQUE` sont déclarées dans les entités :

| Table | Colonnes uniques |
|---|---|
| `app_user` | `email` |
| `city` | `name` |
| `trip_stop` | `trip_id`, `position` |
| `trip_segment` | `trip_id`, `position` |
| `payment` | `booking_id` |
| `review` | `trip_id`, `author_id`, `target_id` |

Les contraintes `CHECK` portent uniquement sur la validité d'une ligne et sont ajoutées dans la migration, car Doctrine ne les génère pas :

| Table | Contrôle |
|---|---|
| `vehicle` | `passenger_seats > 0` |
| `trip` | `seat_capacity > 0`, `full_price_cents > 0` |
| `trip_stop` | `position >= 0` ; latitude entre −90 et 90 ; longitude entre −180 et 180 ; au moins un horaire renseigné ; départ postérieur ou égal à l'arrivée |
| `trip_segment` | `position >= 0`, `distance_meters > 0`, étapes de début et de fin différentes |
| `booking` | `seats > 0` ; montants positifs ou nuls ; `total_price_cents = seat_price_cents * seats` ; étapes de montée et de descente différentes |
| `payment` | `amount_cents >= 0` |
| `review` | `rating` entre 1 et 5 ; auteur et destinataire différents |

Les règles qui portent sur plusieurs lignes (disponibilité, véhicule appartenant au conducteur, ordre des étapes d'une réservation, réservation active unique) sont appliquées dans le code métier.
