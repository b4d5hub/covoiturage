# Journal des décisions

Ce journal consigne les décisions et hypothèses du projet, y compris les réponses aux « Questions à trancher » du sujet. Ce sont des **choix de conception du projet**, et non des consignes de l'énoncé.

---

## 2026-11-09 — Règles métier

### Questions à trancher

#### Une place libérée à une étape intermédiaire peut-elle être revendue pour la suite du trajet ?

**Oui.** La disponibilité est calculée segment par segment : une place occupée de A à B est de nouveau disponible à partir de B. Une réservation n'est acceptée que si chaque segment demandé dispose d'assez de places.

*Justification :* un passager qui descend libère réellement sa place. Raisonner à l'échelle du trajet entier sous-utiliserait le véhicule, alors que le sujet part du constat que les passagers ne font pas toujours le trajet complet.

#### Comment se calcule le prix d'une portion de trajet ?

**Proportionnellement à la distance routière.**

`prix d'une place = prix du trajet complet × (distance de la portion / distance totale)`

Le montant total est le prix d'une place multiplié par le nombre de places réservées. Exemple : pour un trajet à 200 MAD et une portion représentant 40 % de la distance, le prix est de 80 MAD pour une place et de 160 MAD pour deux.

- Le trajet porte un seul prix complet ; les segments n'ont pas de prix propre.
- La distance de la portion est la somme des distances stockées sur ses segments.
- Les distances sont stockées en mètres entiers, les montants en centimes entiers : aucun calcul monétaire en virgule flottante.
- Le prix d'une place est arrondi au centime le plus proche, puis multiplié par le nombre de places. De légers écarts d'arrondi entre des portions réservées séparément sont acceptés.
- Le montant final est enregistré sur la réservation au moment de celle-ci.

*Justification :* la règle est simple, car le conducteur ne saisit qu'un seul prix. Elle est cohérente, car la même formule s'applique à toutes les portions d'un trajet. Elle est transparente, car le passager peut retrouver son prix à partir des distances. Le calcul entier et l'arrondi unique rendent le résultat déterministe et testable.

#### Que se passe-t-il quand le conducteur modifie un horaire ou supprime une étape après des réservations ?

**Ces modifications sont refusées.** Dès qu'un trajet a connu au moins une réservation confirmée, son itinéraire est **gelé définitivement**, même si toutes les réservations sont annulées par la suite. Le gel couvre :

- les horaires ;
- l'ajout, la suppression et la réorganisation d'étapes ;
- les points de rendez-vous ;
- les distances des segments.

Pour changer l'itinéraire, le conducteur annule le trajet et en publie un nouveau. Les exceptions décrites plus bas (capacité et prix) restent possibles, car elles ne touchent pas à l'itinéraire.

*Justification :* un passager réserve sur la base d'un horaire, d'un lieu et d'un prix dérivé des distances, et le gel garantit que ces engagements restent valables. Il est permanent afin de préserver aussi la cohérence de l'historique : une réservation, même annulée, continue de se rapporter aux étapes, aux horaires et aux distances sur lesquels son montant a été calculé. La capacité et le prix complet restent modifiables dans les limites décrites plus bas, car ils ne changent pas l'itinéraire.

#### Un passager peut-il réserver plusieurs places en une fois ?

**Oui**, sous forme d'une seule réservation portant une quantité, à condition que la capacité soit suffisante sur chaque segment de la portion demandée.

*Justification :* cela couvre le cas courant de personnes voyageant ensemble, et la vérification par segment reste la même que pour une place.

#### Qui note qui, et quand ?

Le passager et le conducteur se notent **mutuellement**. Il n'y a pas d'avis entre passagers. Un avis n'est possible qu'après un trajet effectué, dans un délai de 14 jours, à raison d'un avis par sens et par trajet.

*Justification :* seule la relation conducteur–passager repose sur un service rendu. Le délai de 14 jours laisse à chacun le temps de déposer son avis, tout en le gardant assez proche du trajet pour que le souvenir en soit fidèle. Il évite aussi qu'un avis arrive très longtemps après les faits, et se vérifie par une simple comparaison de dates.

### Autres règles métier

| Sujet | Décision |
|---|---|
| Trajet effectué | Le conducteur ou un administrateur marque le trajet comme effectué, uniquement après l'heure d'arrivée prévue à la dernière étape. |
| Annulation par le passager | La réservation est annulée en entier (pas place par place), jusqu'à 2 heures avant le départ prévu de son étape de montée. Toutes ses places sont libérées et le paiement simulé est intégralement remboursé. |
| Annulation par le conducteur | Le trajet entier peut être annulé avant son début, avec un motif obligatoire. Toutes les réservations sont annulées, les paiements simulés intégralement remboursés et les passagers notifiés dans l'application. Le conducteur ne peut pas annuler la réservation d'un seul passager, ni annuler un trajet après le départ. |
| Paiement simulé | Paiement et confirmation ont lieu dans la même transaction, sans aucun champ de carte. États : payé, remboursé. |
| Limites de réservation | Pas de réservation sur son propre trajet ; une seule réservation active par passager et par trajet ; réservation possible jusqu'au départ de l'étape de montée. |
| Modifications après réservation | La capacité ne peut pas descendre sous l'occupation actuelle. Un changement du prix complet ne concerne que les nouvelles réservations ; les montants déjà enregistrés ne changent pas. |
| Comptes | Une seule table de comptes avec des rôles. Un utilisateur peut être conducteur et passager ; l'administrateur utilise le même système. Publier un trajet nécessite un véhicule. |
| Étapes | Une étape comprend une ville, une position ordonnée, l'adresse précise du point de rendez-vous, des coordonnées géographiques et les horaires prévus d'arrivée et/ou de départ. La recherche utilise les villes ; la carte et l'itinéraire utilisent les coordonnées. |
| Villes | Le conducteur choisit dans une liste gérée depuis le back-office. |
| Notifications | Entité simple : destinataire, message, date de création, état de lecture. Affichées dans l'espace passager, sans e-mail. |
| Données de démonstration | Points de rendez-vous publics uniquement (gares routières, etc.), aucune adresse privée. Aucune donnée personnelle réelle : les profils de démonstration sont fictifs. Coordonnées et distances valides. |
| Conventions | Montants en MAD, fuseau horaire `Africa/Casablanca`. |

### Prévention des surréservations

Toute réservation s'effectue dans **une seule transaction** :

1. verrouillage de la ligne du trajet ;
2. calcul de l'occupation de chaque segment demandé, à partir de l'état validé le plus récent des réservations confirmées ;
3. vérification que chaque segment dispose d'assez de places ;
4. création de la réservation ;
5. validation de la transaction.

Les opérations qui modifient l'occupation ou la capacité appliquent le même verrouillage.

Les contraintes `CHECK` servent uniquement aux validations simples sur une ligne, comme une quantité de places positive. Elles ne peuvent pas garantir à elles seules une disponibilité agrégée sur plusieurs réservations : c'est le verrouillage transactionnel et le contrôle de capacité qui empêchent la surréservation.

**Lecture de l'occupation après verrouillage.** Avec l'isolation par défaut d'InnoDB (`REPEATABLE READ`), un `SELECT` ordinaire lit l'instantané établi par la première lecture cohérente de la transaction. Si une telle lecture a lieu avant l'obtention du verrou, le calcul peut ignorer une réservation validée pendant l'attente de ce verrou. La requête d'occupation doit donc lire l'état validé le plus récent une fois le verrou obtenu :

- elle est exécutée comme une lecture verrouillante (`FOR SHARE`), qui ne dépend pas de l'instantané ;
- c'est une requête d'agrégation envoyée à la base, jamais un calcul sur des entités déjà chargées en mémoire par Doctrine.

*Justification :* verrouiller le trajet sérialise les opérations concurrentes sur ce trajet seulement et simplifie le contrôle de la concurrence : un seul verrou à prendre, toujours en premier, plutôt qu'un verrou par segment. Cela réduit le risque d'interblocage sans l'éliminer, car d'autres verrous peuvent intervenir dans la transaction ; en cas d'interblocage, InnoDB annule une transaction, sans effet partiel. Calculer l'occupation depuis les réservations évite un compteur susceptible de diverger.

### Intégrations externes (niveau 4)

| Sujet | Décision |
|---|---|
| Coordonnées et distances | Saisie manuelle au niveau 3, avec validation (coordonnées dans les plages géographiques valides, distances strictement positives). Au niveau 4, Nominatim et OSRM pré-remplissent ces valeurs ; la saisie manuelle reste disponible si les API sont indisponibles. |
| Géocodage | Nominatim géocode l'adresse du point de rendez-vous dans la ville choisie. |
| Prix suggéré | Distance routière × tarif au kilomètre configurable (valeur à fixer au niveau 4). Le conducteur peut le modifier, et la publication reste possible si les API sont indisponibles. |
| Carte | Leaflet avec les tuiles OpenStreetMap et l'attribution requise. |

---

## 2026-11-09 — Choix techniques

| Sujet | Décision | Justification |
|---|---|---|
| Environnement | Développement sous Docker dès le premier jour (PHP-FPM 8.3, Nginx, MySQL 8.4). | Même environnement en développement et à la livraison ; les tests de concurrence ont besoin d'un vrai MySQL. |
| Configuration | `.env` n'est pas versionné : il est généré depuis `.env.example`, avec un `APP_SECRET` créé localement. | Aucun secret dans le dépôt. |
| Base de données locale | `compose.yaml` fournit un mot de passe par défaut, surchargeable, réservé au conteneur local. MySQL n'est pas exposé sur la machine hôte et le mot de passe root est aléatoire. | Permet une installation en une commande sans secret réel dans le dépôt. |
| Langue | Documentation, messages de commit et interface en français ; identifiants de code en anglais. | Public francophone ; conventions de Symfony et Doctrine. |
