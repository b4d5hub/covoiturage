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

---

## 2026-11-10 — Services cartographiques (niveau 4)

Les politiques d'usage ont été relues le 2026-11-10 sur leurs pages officielles : [Nominatim](https://operations.osmfoundation.org/policies/nominatim/), [tuiles OpenStreetMap](https://operations.osmfoundation.org/policies/tiles/) et serveur de démonstration OSRM ([présentation](https://github.com/Project-OSRM/osrm-backend/wiki/Demo-server), [règles d'usage](https://github.com/Project-OSRM/osrm-backend/wiki/Api-usage-policy)).

| Exigence ou sujet | Décision |
|---|---|
| Débit maximal (Nominatim et OSRM : une requête par seconde) | Un limiteur partagé par toute l'application, une requête par seconde au plus vers chaque service, appliqué avant chaque appel. |
| Identification | Requêtes envoyées par notre serveur avec un User-Agent propre à l'application (`OSM_USER_AGENT`), jamais celui de la bibliothèque HTTP. Aucune adresse e-mail personnelle n'y figure. |
| Mise en cache | Résultats de géocodage conservés 30 jours, itinéraires 7 jours. Une indisponibilité n'est pas mise en cache. |
| Changement de service | Adresses de Nominatim, d'OSRM et des tuiles dans des variables d'environnement : on peut en changer sans modifier le code. |
| Usage déclenché par l'utilisateur | Géocodage et calcul d'itinéraire uniquement sur clic du conducteur dans le formulaire de publication ; aucune autocomplétion, aucune requête périodique. |
| Données personnelles | Le formulaire demande l'adresse d'un point de rendez-vous public ; seuls cette adresse et le nom de la ville sont transmis à Nominatim. |
| Attribution | « © contributeurs OpenStreetMap » sur la carte et dans le formulaire, mention d'OSRM pour le tracé, lien « Signaler une erreur de carte ». |
| Tuiles | URL exacte `https://tile.openstreetmap.org/{z}/{x}/{y}.png`, chargées par le navigateur (cache et en-tête Referer du navigateur, pas de politique Referrer restrictive), sans préchargement. |
| Prix suggéré | Distance routière totale × 0,50 MAD par kilomètre (`PRICE_PER_KM_CENTS=50`), arrondi au dirham. Le conducteur peut le modifier ; il reste libre de son prix. |
| Panne d'un service | Saisie manuelle des coordonnées et des distances toujours possible ; la carte trace alors des lignes droites entre les étapes, en le signalant. |
| Tracé affiché | Tracé simplifié fourni par OSRM, suffisant pour une vue d'ensemble et léger à charger. |
| Tests | Aucun appel réel pendant les tests : les deux services sont simulés. |

---

## 2026-11-10 — Livraison (niveau 5)

| Sujet | Décision | Justification |
|---|---|---|
| Commande unique | `docker compose up -d --wait` installe les dépendances, crée le schéma par les migrations et charge les données de démonstration, puis rend la main quand l'application répond. | Le sujet demande une installation en une commande. |
| Données de démonstration | Chargées au premier démarrage uniquement, si la base ne contient aucun compte ; jamais rechargées ensuite. | Un redémarrage ne doit pas effacer les données saisies. |
| Ordre de démarrage | Contrôles de santé : PHP attend MySQL, Nginx attend que PHP ait terminé sa préparation. | Évite les erreurs pendant le premier démarrage. |
| Droits sur les fichiers | Le conteneur PHP s'exécute avec l'identifiant de l'utilisateur hôte (1000 par défaut, modifiable par `HOST_UID` et `HOST_GID`). | Sous Linux, les fichiers créés appartiennent à l'utilisateur et PHP peut écrire dans `var/`. |
| Environnement | Mode `dev` ; les classes proxy Doctrine ne sont régénérées que si elles manquent ou si l'entité a changé. | Le mode `dev` donne accès aux données de démonstration ; la régénération à chaque requête provoquait des collisions entre requêtes simultanées. |
| Vérification | Installation répétée à partir d'une copie exacte du dépôt, dans un projet Docker distinct ; essai prévu sur une machine virtuelle Ubuntu vierge. | Le sujet demande une installation testée sur une machine propre. |

---

## 2026-11-10 — Refonte de l'interface publique

Ces choix portent sur la présentation. Ils ne modifient aucune règle métier : chaque contrôle reste fait par le serveur, et les scripts ne servent qu'à guider la saisie.

| Sujet | Décision | Justification |
|---|---|---|
| Système visuel | Une feuille de style organisée en jetons (couleurs, espacements, rayons) et en composants réutilisables ; un seul accent vert, les autres couleurs étant réservées aux états. Polices du système, sans police externe. | Interface cohérente d'une page à l'autre ; aucune requête vers un service tiers pour l'affichage du texte. |
| Ligne de route | Les étapes d'un trajet sont affichées comme une ligne reliant les arrêts ; sur la page du trajet, la portion choisie par le passager y est mise en valeur. L'occupation de chaque segment est affichée au conducteur siège par siège. | Rend visibles la réservation par portion et la disponibilité par segment, qui sont au cœur du modèle. |
| Réservation | Récapitulatif avant l'envoi (portion, prix par place, nombre de places, total) et lien « Choisir » sur chaque portion ouverte, qui préremplit le formulaire, y compris sans JavaScript. | Le passager voit le montant exact avant de confirmer. |
| Choix proposé par défaut | Sans villes indiquées, le formulaire de réservation propose le trajet entier, depuis la première étape dont le départ est encore à venir. Depuis une recherche, il reprend les deux villes recherchées. | Un passager qui ne modifie pas les listes réserve le trajet affiché, pas seulement sa première étape. |
| Résultats de recherche | Le titre ne compte que les trajets réservables. Les trajets complets entre les deux villes et ceux dont l'utilisateur est le conducteur restent affichés, à part, sous « Complets ou non réservables ». L'accueil ne met en avant qu'un trajet ayant des places du départ à l'arrivée. | « Trouvé » doit vouloir dire réservable ; le conducteur reconnaît son propre trajet. |
| Prix affichés | Montants affichés au centime, exactement comme ils sont calculés et enregistrés ; seul le prix suggéré au conducteur est arrondi au dirham, comme décidé pour le niveau 4. | L'écran montre le montant réellement payé et remboursé. |
| Actions irréversibles | Annulation d'une réservation, annulation d'un trajet et clôture d'un trajet passent par une boîte de confirmation qui rappelle leurs conséquences. Sans JavaScript, le formulaire s'envoie directement. | Évite les erreurs de manipulation sans bloquer les navigateurs sans script. |
| Actions indisponibles | Une action impossible est remplacée par son explication : délai d'annulation de 2 heures dépassé, clôture possible à partir de l'arrivée prévue, délai d'avis de 14 jours dépassé, itinéraire gelé après la première réservation. | L'utilisateur comprend la règle au lieu de constater l'absence d'un bouton. |
| Formulaire de publication | Étapes numérotées, réordonnables et ajoutées avant l'arrivée ; champs sans objet désactivés (pas d'arrivée au départ, ni de départ ni de distance à l'arrivée) ; récapitulatif avant publication. La saisie manuelle des coordonnées et des distances reste possible. | Rend la saisie d'un itinéraire à étapes plus sûre, sans dépendre des services cartographiques. |
| Accessibilité | Lien d'accès au contenu, page courante signalée dans la navigation, erreurs reliées à leur champ (`aria-invalid`, `aria-describedby`), zones de clic d'au moins 44 px, navigation au clavier, mouvements réduits si le système le demande. | Utilisable au clavier, au lecteur d'écran et sur téléphone. |
| Typographie | Apostrophe droite dans tous les textes, comme dans les messages produits par le serveur. | Un seul style sur une même page. |
| Back-office | EasyAdmin conserve sa mise en page ; il reprend la couleur de l'application et propose un lien de retour vers le site. | L'administration reste distincte de l'espace public tout en partageant son identité. |

---

## 2026-11-11 — Identité visuelle affirmée

Révision de la refonte du 2026-11-10 : l'interface adopte une identité plus marquée. Les règles métier ne changent pas.

| Sujet | Décision | Justification |
|---|---|---|
| Typographie (remplace les polices du système) | Plus Jakarta Sans, police libre (licence SIL OFL, fichier `public/fonts/OFL.txt`), hébergée par l'application : aucune requête vers un service de polices à l'exécution. | Une police choisie donne une identité reconnaissable ; l'auto-hébergement garde le fonctionnement hors ligne sous Docker. |
| Palette | Fond blanc, encre presque noire pour le texte et les boutons principaux, gris de remplissage pour les champs ; le vert reste l'unique accent, réservé aux trajets, aux places et aux sélections. | Contraste fort et lecture rapide ; la couleur garde un sens. |
| Accueil | Carte du prochain trajet disponible en fond (tuiles OpenStreetMap atténuées en gris, tracé OSRM mis en cache), avec la recherche dans un panneau au premier plan. La carte est décorative et sans interaction ; l'attribution reste affichée. | Montre d'emblée un vrai trajet à étapes. Les tuiles sont chargées par le navigateur, comme sur la page du trajet, dans le respect de la politique d'usage déjà décrite. |
| Réservation sur mobile | Barre fixe en bas de la page du trajet, avec le total de la sélection et un accès direct au formulaire. | Le montant et l'action restent visibles pendant la lecture de l'itinéraire. |


---

## 2026-11-12 — Points de rendez-vous, villes et recherche par rayon

Révision des décisions sur les villes, le géocodage et la recherche. Les règles de réservation, de prix, de gel de l'itinéraire et de surréservation ne changent pas.

| Sujet | Décision | Justification |
|---|---|---|
| Point de rendez-vous (remplace « Géocodage ») | Le conducteur choisit n'importe quel lieu (gare, adresse, quartier, commerce) parmi les résultats de Nominatim, ou clique sur la carte (géocodage inverse). Le lieu choisi fournit le nom affiché, l'adresse et les coordonnées de l'étape ; le conducteur peut préciser le libellé. | Un point de rendez-vous est souvent un endroit précis d'une ville, pas la ville elle-même. Seul un lieu connu d'OpenStreetMap peut être choisi. |
| Ville d'une étape (remplace « Villes ») | Déterminée automatiquement par le serveur : géocodage inverse, au niveau de la localité (ville, commune ou village), des coordonnées de l'étape. La ville est identifiée par son type et son identifiant OpenStreetMap, créée à sa première utilisation puis réutilisée. Un point situé hors de toute localité est refusé. | Deux conducteurs qui choisissent des lieux d'une même ville obtiennent la même ville, quelle que soit l'orthographe : la recherche par ville reste fiable. Le serveur ne se fie pas à une ville envoyée par le navigateur. |
| Périmètre géographique | Étapes limitées au Maroc (`countrycodes=ma` pour la recherche de lieux, pays vérifié par le serveur sur le géocodage inverse). Noms en français. | Plateforme de covoiturage régional au Maroc. |
| Service indisponible | Si Nominatim ne répond pas, le conducteur choisit la ville dans la liste des villes déjà connues et saisit l'adresse et les coordonnées à la main, comme auparavant. | La publication reste possible en cas de panne, conformément à la décision du niveau 4. |
| Villes au back-office | Consultation et correction du nom affiché ; plus de création manuelle. Une ville utilisée par une étape ne peut pas être supprimée. | Les villes proviennent d'OpenStreetMap ; l'identifiant OSM reste la référence. |
| Recherche (remplace « Recherche » du niveau 3) | Départ et arrivée : une ville ou un lieu précis. Un nom de ville déjà connue est utilisé directement ; sinon le lieu est cherché dans Nominatim à l'envoi du formulaire, jamais pendant la frappe. Un trajet correspond si une étape de montée est dans la ville de départ **ou** à 5 km au plus du point de départ, si une étape de descente est dans la ville d'arrivée **ou** à 5 km au plus du point d'arrivée, si la montée précède la descente et si le départ de la montée a lieu le jour choisi et est encore à venir. | Un passager trouve aussi les trajets qui partent d'une localité voisine (Salé pour Rabat) ; la condition sur la ville couvre les grandes villes dont les étapes sont loin du centre. |
| Rayon | Fixé à 5 km, distance à vol d'oiseau calculée par MySQL (`ST_Distance_Sphere`) après un filtrage rapide par latitude et longitude. | Proche du point voulu sans mélanger des villes éloignées ; calcul simple et testable. |
| Choix des étapes dans un résultat | Si plusieurs paires d'étapes d'un trajet conviennent, la paire la plus proche des deux points recherchés est retenue. Résultats triés par distance du point de montée ; la distance est affichée (« point de rendez-vous à 1,8 km »). | Le passager voit d'abord les trajets qui partent le plus près de lui. |
| Coordonnées d'une ville | Chaque ville conserve les coordonnées de son centre fournies par Nominatim ; elles servent de point de recherche quand le passager saisit une ville. | Permet le rayon de 5 km autour d'une ville saisie par son nom. |
| Formulaire de publication (complète celui de la refonte) | Assistant pas à pas : véhicule et places, puis une étape par arrêt (lieu par recherche ou clic sur la carte, horaire) ; la carte affiche le tracé OSRM depuis l'arrêt précédent et la distance du tronçon ; ajouter un arrêt ou terminer ; prix suggéré ou prix du conducteur ; récapitulatif ; publication. Sans JavaScript, le formulaire complet reste utilisable. Toutes les règles restent vérifiées par le serveur. | Saisie guidée par la carte, sans dépendre du script pour publier. |
| Page du trajet | Le tableau « Prix entre les étapes » affiche aussi le point de rendez-vous de chaque étape. | Le passager sait où il monte et descend, pas seulement dans quelle ville. |
| Données de démonstration | Les villes de démonstration reprennent leurs identifiants et coordonnées OpenStreetMap réels, relevés une fois auprès de Nominatim. | Les villes de démonstration et celles créées par les conducteurs se confondent bien. |
| Clic sur la carte | Le point cliqué est nommé par un géocodage inverse au niveau de l'adresse ; le repère de l'étape en cours peut être déplacé, ce qui redétermine sa ville. | Le conducteur peut désigner un lieu sans connaître son nom. |
| Usage déclenché par l'utilisateur (complète le niveau 4) | Nominatim est appelé sur « Rechercher », sur un clic ou un déplacement du repère dans l'assistant, à la publication (ville de chaque étape, souvent déjà en cache) et à l'envoi d'une recherche passager dont le texte n'est pas une ville connue. Jamais pendant la frappe. | Conforme à la politique d'usage : pas d'autocomplétion ; le cache et la liste des villes connues limitent les requêtes. |
| Données transmises (remplace « Données personnelles » du niveau 4) | Seuls le texte saisi dans la recherche de lieu et les coordonnées choisies sont transmis à Nominatim, jamais l'identité du conducteur ou du passager. Le formulaire rappelle de choisir un lieu public. | Limite les données personnelles envoyées à un service tiers. |
| Recherche passager avec service partiel | Si le lieu est trouvé mais que sa ville ne peut pas être déterminée (service lent ou indisponible), la recherche se fait dans le rayon de 5 km seulement. | Le passager obtient des résultats plutôt qu'une erreur. |
| Délai d'attente de Nominatim | 10 secondes. | Le serveur public répond parfois en plusieurs secondes ; 5 secondes provoquaient de fausses indisponibilités. |
| Modification d'un trajet au back-office | L'administrateur choisit toujours la ville dans la liste ; elle n'est pas redéterminée d'après les coordonnées. | Le back-office sert à corriger des données, sans dépendre d'un service externe. |

---

## 2026-11-12 — Revue de l'interface

Revue des écrans au regard de ce que permet le serveur. Les règles métier ne changent pas ; ces choix rendent visibles des données et des opérations qui existaient déjà.

| Sujet | Décision | Justification |
|---|---|---|
| Réservation existante | La page d'un trajet sur lequel le passager a déjà une réservation confirmée affiche cette réservation à la place du formulaire. | Une seule réservation active par passager et par trajet : le formulaire aurait été refusé à l'envoi. |
| Page d'une réservation | Chaque réservation a sa page, visible de son seul passager : étapes, horaires, conducteur, montant, dates du paiement simulé et du remboursement, annulation et avis. Après une réservation, le passager y arrive directement. | Les données existaient (dates de paiement, de remboursement, d'annulation) sans être affichées ; la page sert de confirmation. |
| Places et prix modifiés par le conducteur | Depuis son espace, le conducteur modifie les places proposées et le prix du trajet entier, tant que le trajet est publié et n'est pas parti. Mêmes règles qu'au back-office, sous le verrou du trajet : pas moins que les places déjà occupées, pas plus que les places du véhicule ; le nouveau prix ne concerne que les réservations suivantes. | Ces modifications sont permises par les règles décidées le 2026-11-09 mais n'étaient accessibles qu'à l'administrateur. Après le départ, les modifier n'aurait plus de sens. |
| Mon compte | Page réservée à l'utilisateur connecté : nom complet, adresse e-mail, date d'inscription et raccourcis. L'adresse de connexion apparaît aussi dans le menu du compte. | L'utilisateur voit avec quel compte il est connecté ; ces informations restent invisibles des autres membres. |
| Distance affichée dans les résultats | La distance au point de départ n'est affichée que si l'étape de montée est hors de la ville recherchée, ou si le passager a cherché un lieu précis. | Pour une recherche « Agadir », une gare d'Agadir n'est pas « à 2,7 km d'Agadir ». |
| Prix entre les étapes | Les trajets encore réservables sont listés ; les trajets complets ou déjà partis sont regroupés dans une section repliable. | Le tableau montrait surtout des lignes « Complet ». |
| Page du trajet vue par son conducteur | Liste des passagers et accès direct à la gestion du trajet. | Le conducteur n'avait qu'un message « Vous êtes le conducteur ». |

---

## 2026-11-13 — Identité « Escale »

L'interface publique et le back-office adoptent l'identité « Escale ». Elle remplace la typographie et la palette de l'entrée du 2026-11-11 ; les autres décisions d'interface restent valables.

| Sujet | Décision | Justification |
|---|---|---|
| Palette (remplace « Palette » du 2026-11-11) | Aubergine pour l'en-tête, le texte et les actions principales, citron pour la sélection et les mises en avant, fonds ivoire et lilas. Les couleurs sont définies une seule fois dans `public/css/escale-tokens.css` et partagées avec le back-office. | Une identité chaleureuse et reconnaissable, commune à l'espace public et à l'administration. |
| Typographie (remplace « Typographie » du 2026-11-11) | Chivo pour les titres et les prix, Rethink Sans pour le texte de l'interface. Polices libres (licence SIL OFL, fichiers `public/fonts/OFL-Chivo.txt` et `public/fonts/OFL-RethinkSans.txt`), hébergées par l'application. La police précédente, devenue inutile, est retirée. | Des titres affirmés et un texte lisible, sans requête vers un service de polices. |
| Nom du produit | Le nom « Covoiturage régional » est affiché à côté du logo dans l'en-tête, sur ordinateur comme sur téléphone. | Un visiteur doit savoir sur quel site il se trouve sans descendre jusqu'au pied de page. |
| Vocabulaire | Les textes affichés parlent de « trajet » et d'« étapes », jamais de « portion » ou de « segment », réservés au code et à la documentation. | Ce sont des mots de modélisation, pas ceux d'un passager. |
| Typographie des textes (rappel) | L'apostrophe droite reste la règle dans tous les textes affichés. | Un seul style sur une même page, comme dans les messages produits par le serveur. |

---

## 2026-11-13 — Assistant de publication refait sur le modèle des plateformes existantes

Comparaison avec les parcours de publication décrits par les centres d'aide de BlaBlaCar et de Poparide : le trajet est demandé d'abord, avec une seule date et une seule heure de départ, et les arrêts sont proposés le long de la route. Les règles de l'itinéraire, du prix et du gel ne changent pas : l'assistant remplit le formulaire complet, que le serveur valide comme avant.

| Sujet | Décision | Justification |
|---|---|---|
| Ordre des étapes (complète « Formulaire de publication » du 2026-11-12) | Trajet (départ, arrivée, date, heure de départ), puis arrêts, véhicule et places, prix, récapitulatif. | Le conducteur commence par ce qu'il sait déjà : d'où, où et quand. |
| Horaires | Le conducteur saisit une seule heure, celle du départ. Les heures d'arrivée sont calculées d'après la durée de conduite donnée par OSRM, arrondie à 5 minutes, avec un arrêt de 10 minutes à chaque étape intermédiaire. Chaque heure reste modifiable ; une heure modifiée est conservée. | Saisir deux dates et heures par étape était la principale difficulté. Les heures restent celles du conducteur, et le serveur vérifie toujours leur ordre. |
| Arrêts proposés | Les villes déjà connues dont le centre est à 10 km au plus du tracé, hors départ et arrivée, sont proposées à cocher, dans l'ordre de la route. Calcul local sur les centres des villes, sans appel à Nominatim. Un autre arrêt reste possible par recherche ou clic sur la carte ; il est rangé à sa place sur la route. | Proposer plutôt que faire construire l'itinéraire arrêt par arrêt, sans requête supplémentaire vers un service externe. |
| Point de rendez-vous d'une ville proposée | Une ville cochée devient un arrêt au centre-ville (« Centre-ville, Meknès »), que le conducteur peut préciser par recherche ou clic sur la carte. La ville de l'étape est toujours redéterminée par le serveur. | Ajout en un clic ; le libellé indique clairement qu'il s'agit du centre. |
| Un seul champ par lieu, suggestions pendant la frappe | Le lieu choisi fournit le libellé du point de rendez-vous. Pendant la frappe (à partir de 2 caractères), les suggestions viennent uniquement de la base de l'application : villes connues et points de rendez-vous déjà utilisés par des trajets, comparés sans accents ni majuscules. Une dernière ligne « Chercher … sur la carte » interroge Nominatim, comme le bouton « Rechercher » : seulement sur demande du conducteur. Une ville choisie telle quelle devient un arrêt « Centre-ville », à préciser. | La politique d'usage de Nominatim interdit l'autocomplétion (« Auto-complete search […] you must not implement such a service on the client side »). Les suggestions locales donnent des résultats immédiats sans aucune requête externe ; le service Photon, conçu pour la saisie au fil de l'eau, a été écarté car il ajouterait un service tiers et remplacerait Nominatim, demandé par le sujet. |
| Prix | Prix conseillé proposé par défaut, ajusté par pas de 5 MAD avec − et +, ou saisi. Aperçu du prix entre chaque paire d'étapes consécutives, calculé avec la même formule et le même arrondi que le serveur. Le prix reste unique pour le trajet entier. | Le conducteur voit ce que paieront les passagers ; la règle du prix proportionnel ne change pas. |
| Saisie manuelle | Coordonnées, ville et distances ne sont plus visibles dans l'assistant. Le formulaire complet s'affiche si la carte ou un service ne répond pas, ou si le serveur renvoie des erreurs à corriger. | Ces champs techniques ne servent qu'en secours. |
| Trajet retour | Depuis la page de son trajet, le conducteur ouvre l'assistant prérempli avec les étapes dans l'ordre inverse, les mêmes distances, le même véhicule, les mêmes places et le même prix ; il choisit la date et l'heure. Réservé au conducteur du trajet d'origine. | Les trajets se font souvent en aller-retour : on évite de tout ressaisir. |


---

## 2026-11-13 — Prix affichés sur l'itinéraire

| Sujet | Décision | Justification |
|---|---|---|
| Prix entre les étapes (remplace la carte séparée du 2026-11-12) | La page du trajet n'a plus de carte « Prix entre les étapes ». Dans l'itinéraire, chaque étape située après l'étape de montée choisie affiche le prix par place depuis cette montée et les places libres, avec « Descendre ici » ; les étapes situées avant proposent « Monter ici ». Ces actions sont de simples liens qui préremplissent la réservation, mis à jour sans rechargement quand JavaScript est disponible. La grille complète de toutes les paires d'étapes reste disponible, repliée sous l'itinéraire. Rien n'est proposé au conducteur ni au passager qui a déjà réservé ce trajet. | Le passager lit le prix là où il lit la route, sans chercher sa ligne dans une grille ; le choix des étapes se fait à un seul endroit. |

---

## 2026-11-13 — Notifications des conducteurs et aperçu sous la cloche

| Sujet | Décision | Justification |
|---|---|---|
| Notifications (complète « Notifications » du 2026-11-09) | Le conducteur reçoit une notification dans l'application à chaque réservation sur son trajet (passager, nombre de places, étapes, date du trajet), et quand un passager annule sa réservation (les places redeviennent disponibles). Chaque notification se rapporte à la réservation concernée et est datée de l'événement. Toujours sans e-mail. | Le conducteur apprend qu'une place est prise ou libérée sans consulter son espace ; la date reste juste pour les données de démonstration. |
| Ouverture d'une notification | Une notification ouverte est marquée comme lue et mène à ce qu'elle concerne : la réservation pour le passager, le trajet dans l'espace conducteur pour le conducteur. Seul son destinataire peut l'ouvrir. La page de toutes les notifications reste disponible et les marque toutes comme lues. | Chaque notification conduit directement à l'action utile. |
| Aperçu sous la cloche | Un clic sur la cloche ouvre les 5 dernières notifications, lues ou non, sans les marquer comme lues, avec « Voir toutes les notifications ». Sur téléphone, l'aperçu s'ouvre dans le menu, sous la cloche. Sans JavaScript, la cloche mène à la page des notifications. L'aperçu est demandé dès que le pointeur ou le clavier atteint la cloche. | Consultation rapide sans quitter la page, même sans nouveauté ; l'aperçu s'affiche sans attente perceptible. |
| Fuseau horaire affiché | Les libellés des heures (formulaires de trajet, back-office compris, fiche du trajet, réservation, pied de page) indiquent le nom du fuseau, `Africa/Casablanca`, au lieu de « heure du Maroc ». | Nom exact et sans ambiguïté, valable toute l'année : un décalage fixe (UTC+1) serait faux pendant le ramadan, quand le Maroc passe à UTC+0. |
| Version des fichiers publics | Les adresses des styles, scripts et images portent la date de modification du fichier (`?v=…`). | Après une mise à jour, le navigateur charge la nouvelle version au lieu d'une copie gardée en cache. |

---

## 2026-11-13 — Test sur machine vierge

| Sujet | Résultat |
|---|---|
| Vérification (complète « Vérification » du niveau 5) | Installation réussie sur une machine virtuelle Ubuntu vierge (VirtualBox), en suivant INSTALL.md pas à pas depuis le dépôt public : `docker compose up -d --wait`, puis recherche, réservation, publication, back-office et conservation des données après un redémarrage. |
