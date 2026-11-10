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
| Conventions | Montants en MAD. Dates stockées et traitées en UTC ; saisie et affichage à l'heure du Maroc (`Africa/Casablanca`). Voir la révision du 2026-11-09 en fin de journal. |

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
| Tests | Base MySQL dédiée (service `db_test`, profil `test`), construite par les migrations. | Les contraintes `CHECK` et les lectures verrouillantes doivent être vérifiées sur un vrai MySQL. |

---

## 2026-11-09 — Révision du modèle après audit

Un audit du modèle a montré que plusieurs conditions étaient supposées sans être contrôlées. Les décisions métier précédentes restent inchangées.

| Sujet | Décision | Justification |
|---|---|---|
| Fuseau horaire (remplace le stockage à l'heure du Maroc) | Dates stockées et traitées en UTC ; conversion vers `Africa/Casablanca` à la saisie et à l'affichage. Un type Doctrine convertit toute date en UTC à l'écriture. | Le Maroc change d'heure autour du ramadan : une heure locale peut exister deux fois ou ne pas exister. L'UTC rend les comparaisons exactes. |
| Délai d'annulation | Les deux heures sont calculées en UTC depuis le départ de l'étape de montée. | Ce sont deux heures réelles, même si l'heure affichée change entre la limite et le départ. |
| Extrémités des segments | Les colonnes `from_stop_id` et `to_stop_id` sont supprimées : le segment de rang `p` relie les étapes `p` et `p + 1`. | Les extrémités se déduisent des rangs. L'application contrôle la continuité des rangs et le nombre de segments. |
| Modification de l'itinéraire | Un point d'entrée unique, `Trip::replaceItinerary()`, recalcule les rangs et refuse un trajet gelé. | Une modification ne peut pas laisser un itinéraire partiellement incohérent. |
| Horaires des étapes | La première étape a un départ seul, la dernière une arrivée seule, les autres les deux, dans l'ordre chronologique. | Le délai d'annulation et la recherche reposent sur ces horaires. |
| Conducteur historique | Pas de colonne `trip.driver_id`. Le propriétaire d'un véhicule utilisé par un trajet et le véhicule d'un trajet existant ne peuvent plus changer. | Le conducteur reste déductible du véhicule sans donnée dupliquée. |
| Capacité | Strictement positive, au plus égale aux places du véhicule, jamais inférieure à l'occupation. Les places d'un véhicule ne peuvent pas descendre sous la capacité d'un trajet qui l'utilise, y compris un trajet passé. | La règle reste vraie pour tous les trajets, sans cas particulier selon leur état. |
| Gel de l'itinéraire | Opération à sens unique : aucune méthode ne permet de l'annuler. | Le gel est définitif par décision du projet. |

---

## 2026-11-09 — Back-office et données de démonstration

| Sujet | Décision | Justification |
|---|---|---|
| Outil | EasyAdmin, réservé au rôle administrateur, derrière un formulaire de connexion. | Couvre la gestion des objets sans développer d'écrans sur mesure. |
| Objets modifiables | Comptes, villes, véhicules et trajets. | Leurs règles sont déjà appliquées par les entités. |
| Objets en consultation seule | Réservations, paiements, avis et notifications. | Ils ne doivent changer que par les règles métier (verrou, paiement simulé, annulations) ; les modifier à la main contournerait ces règles. |
| Trajets | L'itinéraire passe par `Trip::replaceItinerary()`, la capacité d'un trajet enregistré par le service sous verrou. Pas de suppression ; l'état n'est pas modifiable à la main. | Le back-office applique les mêmes contrôles que le reste de l'application. |
| Saisie des prix | Montant en MAD converti en centimes par analyse du texte, sans nombre flottant. | Le champ monétaire d'EasyAdmin passe par un nombre flottant. |
| Données de démonstration | Personnes fictives, adresses `@covoiturage.test`, points de rendez-vous publics, coordonnées approximatives et distances arrondies. Dates relatives au jour du chargement. | La démonstration reste à jour sans donnée réelle. |
| Mot de passe de démonstration | Commun aux comptes fictifs et lu depuis la variable `DEMO_PASSWORD`, dont la valeur figure dans `.env.example`. | Le sujet demande de documenter des comptes de démonstration. Cette valeur ne protège aucune donnée réelle. |

---

## 2026-11-09 — Précisions d'application des règles métier

Ces précisions fixent les cas limites des règles déjà décidées, sans les modifier.

| Sujet | Décision | Justification |
|---|---|---|
| Limite d'annulation du passager | L'instant exact situé deux heures avant le départ de l'étape de montée est encore accepté. | « Jusqu'à 2 heures avant » inclut cette limite. |
| Fin des réservations | Une réservation est refusée à partir de l'heure de départ exacte de l'étape de montée. Une étape intermédiaire reste réservable après le début du trajet. | La règle porte sur le départ de l'étape de montée, pas sur celui du trajet. |
| Annulation d'un trajet | Possible jusqu'à l'instant précédant le départ de la première étape, par le conducteur uniquement. | La règle désigne le conducteur ; un administrateur peut seulement marquer un trajet comme effectué. |
| Fin de trajet | Possible à partir de l'heure d'arrivée prévue à la dernière étape. | « Après l'arrivée prévue » s'apprécie sur l'horaire publié. |
| Délai des avis | Quatorze jours après la date à laquelle le trajet est marqué effectué, limite incluse. | Cette date confirme que le trajet a eu lieu. |
| Passager d'un trajet effectué | Seul un passager dont la réservation est restée confirmée peut déposer ou recevoir un avis. | Une réservation annulée ne correspond à aucun trajet effectué. |
| Lecture sous verrou | Les lectures qui décident d'une opération suivent le verrou du trajet et utilisent une lecture verrouillante ou un rechargement. | Elles portent ainsi sur le dernier état validé. |

---

## 2026-11-10 — Interface publique (niveau 3)

| Sujet | Décision | Justification |
|---|---|---|
| Interface | Pages Twig, une feuille de style et un court script sans dépendance, en français. | Le front est libre ; ce choix évite une chaîne de construction. |
| Comptes | Inscription ouverte, un seul compte par personne. Publier un trajet demande d'avoir enregistré un véhicule. | Conforme aux décisions sur les comptes ; le conducteur est le propriétaire du véhicule. |
| Identité publique | Les pages publiques affichent le prénom et l'initiale du nom ; l'adresse e-mail n'est jamais affichée. | Limite les données personnelles exposées. |
| Recherche | Ville de départ, ville d'arrivée et date, interprétée comme un jour au Maroc. Un trajet correspond si la ville de départ précède la ville d'arrivée sur son itinéraire et si le départ de cette étape est encore à venir. | Le sujet demande une recherche par départ, arrivée et date ; les passagers peuvent ne faire qu'une partie du trajet. |
| Réservation | Le passager choisit l'étape de montée, l'étape de descente et le nombre de places ; la page du trajet affiche le prix et les places libres de chaque portion. | Rend visibles le prix proportionnel et la disponibilité par segment. |
| Espace conducteur | Trajets à venir, trajets partis à clôturer et historique, avec les passagers et l'occupation par segment. Actions : publier, annuler avec motif, marquer comme effectué, noter les passagers. | Couvre « trajets publiés, passagers, historique » et les règles d'annulation et d'avis. |
| Notifications | Affichées dans l'espace de l'utilisateur, marquées comme lues à la consultation. | Notification dans l'application, sans e-mail. |
| Modification d'un trajet par le back-office | Prend le même verrou que la réservation et refuse l'enregistrement si l'itinéraire a été gelé entre-temps. | Le gel reste définitif même en cas de modification concurrente. |
| Données de démonstration | Aucun événement n'est daté dans le futur : une réservation sur un trajet à venir est datée au plus tard deux heures avant le chargement. | Les dates restent vraisemblables quel que soit le jour du chargement. |
