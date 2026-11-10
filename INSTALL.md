# Guide d'installation

Ce guide installe et lance l'application complète (PHP et Symfony, serveur web Nginx, MySQL) avec Docker, en une seule commande.

## 1. Prérequis

| Élément | Version ou condition |
|---|---|
| Docker | Docker Engine avec le plugin Compose v2, ou Docker Desktop. Vérification : `docker compose version`. |
| Git | Pour récupérer le projet. |
| Réseau | Accès à Internet au premier démarrage (images Docker et dépendances PHP), puis pour la carte et l'aide à la saisie des trajets. |
| Port | Le port 8000 libre sur la machine (modifiable, voir la section 6). |
| Disque | Environ 2,5 Go pour les images et les dépendances. |

L'ancienne commande `docker-compose` (version 1) n'est pas prise en charge.

## 2. Installation

```bash
git clone https://github.com/b4d5hub/covoiturage.git
cd covoiturage
docker compose up -d --wait
```

La commande rend la main quand la page d'accueil répond. Le premier démarrage dure plusieurs minutes, selon la machine et la connexion (téléchargement des images, construction de l'image PHP, installation des dépendances) ; les suivants sont beaucoup plus rapides.

Ces commandes fonctionnent dans un terminal Linux comme dans Windows PowerShell.

Ouvrez ensuite <http://localhost:8000>.

Au premier démarrage, sans aucune action manuelle :

1. le fichier `.env` est créé à partir de `.env.example`, avec un secret applicatif généré localement ;
2. les dépendances PHP sont installées ;
3. le schéma de la base est créé par les migrations ;
4. les données de démonstration sont chargées, uniquement si la base ne contient encore aucun compte.

Aux démarrages suivants, les migrations sont appliquées si besoin et les données existantes sont conservées.

> **Sous Linux** : le conteneur PHP utilise l'identifiant 1000, celui du premier utilisateur sur la plupart des distributions. Si `id -u` affiche une autre valeur, lancez plutôt :
>
> ```bash
> HOST_UID=$(id -u) HOST_GID=$(id -g) docker compose up -d --wait
> ```

## 3. Comptes de démonstration

Toutes les personnes et tous les trajets de démonstration sont fictifs. Le mot de passe commun est `demo-covoiturage` : c'est la valeur par défaut de `DEMO_PASSWORD`, lue dans `.env` au moment du chargement des données.

> **Usage local uniquement** : ces identifiants sont publics. Ne déployez jamais l'application avec les données de démonstration sur un serveur accessible depuis Internet.

| Adresse e-mail | Rôle dans la démonstration |
|---|---|
| `admin@covoiturage.test` | Administrateur : accès au back-office (`/admin`) |
| `youssef@covoiturage.test` | Conducteur, avec un trajet à venir déjà réservé et un trajet effectué |
| `khadija@covoiturage.test` | Conductrice, avec un trajet à venir réservé |
| `omar@covoiturage.test` | Conducteur, avec un trajet sans réservation |
| `salma@covoiturage.test` | Conductrice et passagère, avec un trajet annulé et un trajet à clôturer |
| `amine@covoiturage.test` | Passager, avec une notification d'annulation |
| `nadia@covoiturage.test`, `hamza@covoiturage.test`, `zineb@covoiturage.test`, `mehdi@covoiturage.test`, `imane@covoiturage.test` | Passagers |

Les dates des trajets sont fixées au moment du chargement, de 20 jours avant à 9 jours après ce jour-là. Elles ne changent pas aux redémarrages : passé ce délai, il n'y a plus de trajet à venir. Pour en retrouver, rechargez les données de démonstration (section 5).

## 4. Utilisation

| Adresse | Contenu |
|---|---|
| <http://localhost:8000> | Recherche de trajets |
| <http://localhost:8000/inscription> | Création d'un compte |
| <http://localhost:8000/connexion> | Connexion |
| <http://localhost:8000/admin> | Back-office (compte administrateur) |

Le paiement est simulé : aucune donnée bancaire n'est demandée et aucun paiement réel n'est effectué.

## 5. Commandes utiles

| Besoin | Commande |
|---|---|
| Arrêter l'application | `docker compose down` |
| Redémarrer | `docker compose up -d --wait` |
| Suivre les journaux | `docker compose logs -f php` |
| Recharger les données de démonstration | `docker compose exec php php bin/console doctrine:fixtures:load --no-interaction` |
| Tout supprimer, base comprise | `docker compose down -v` |

> **Attention, deux commandes suppriment des données :**
> - le rechargement des données de démonstration supprime d'abord toutes les données de l'application (comptes, trajets, réservations, avis…), y compris celles que vous avez saisies ; seule la structure de la base est conservée ;
> - `docker compose down -v` supprime le volume Docker du projet qui contient la base de données : toutes les données sont perdues, et les données de démonstration seront rechargées au démarrage suivant.

### Lancer les tests automatisés

Les tests utilisent une base MySQL séparée, démarrée à la demande :

```bash
docker compose --profile test up -d --wait db_test
docker compose exec php php bin/phpunit
```

Ils durent quelques minutes et n'envoient aucune requête aux services cartographiques externes. Ils ne touchent jamais à la base de l'application : sans la base de test, ils refusent de démarrer.

## 6. Personnaliser la configuration

Pour changer durablement le port ou les identifiants de la base, copiez le modèle **avant le premier démarrage**, puis décommentez et modifiez les lignes concernées en fin de fichier :

```bash
cp .env.example .env
```

Le port peut aussi être changé pour un seul lancement :

| Terminal | Commande |
|---|---|
| Linux (bash) | `APP_PORT=8080 docker compose up -d --wait` |
| Windows PowerShell | `$env:APP_PORT=8080; docker compose up -d --wait` |

Le fichier `.env` n'est jamais versionné.

## 7. Dépannage

| Symptôme | Cause probable et solution |
|---|---|
| `port is already allocated` ou port 8000 occupé | Un autre programme utilise le port. Choisissez un autre port (section 6), puis ouvrez par exemple <http://localhost:8080>. |
| La commande attend longtemps au premier démarrage | Installation en cours. Suivez l'avancement avec `docker compose logs -f php`. |
| `container ... is unhealthy` | Le démarrage a échoué. Consultez `docker compose logs php` puis `docker compose logs nginx` : le plus souvent un accès réseau impossible pendant l'installation des dépendances. Corrigez puis relancez `docker compose up -d --wait`. |
| `Permission denied` dans les journaux, sous Linux | Votre identifiant n'est pas 1000. Relancez avec `HOST_UID=$(id -u) HOST_GID=$(id -g) docker compose up -d --wait`. |
| Erreur d'accès à la base après un changement de `DB_PASSWORD` | La base a été créée avec l'ancien mot de passe. Remettez l'ancien, ou supprimez la base avec `docker compose down -v` (toutes les données sont perdues), puis relancez. |
| La carte ne s'affiche pas, ou les boutons d'aide à la saisie répondent « indisponible » | Pas d'accès à Internet ou service OpenStreetMap indisponible. L'application reste utilisable : saisissez les coordonnées et les distances à la main. |
| `docker compose` : commande inconnue | Installez le plugin Compose v2 ou une version récente de Docker Desktop. |
