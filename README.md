# Covoiturage régional

Plateforme de covoiturage entre les villes du Maroc. Les conducteurs publient des trajets avec des étapes ; les passagers réservent tout ou partie du parcours. Le paiement est simulé.

**Sujet** : Plateforme de covoiturage régional.
**Stack** : Symfony 7.4, PHP 8.3, MySQL 8.4, Doctrine ORM et migrations, Twig, Docker.

## Lancer le projet

```bash
git clone https://github.com/b4d5hub/covoiturage.git
cd covoiturage
docker compose up -d --wait
```

Puis ouvrir <http://localhost:8000>. Prérequis, comptes de démonstration et dépannage : [INSTALL.md](INSTALL.md).

## Niveaux atteints

| Niveau | Thème | État |
|---|---|---|
| 1 | Modéliser | Fait : modèle, migrations avec contrôles `CHECK`, données de démonstration, back-office |
| 2 | Faire respecter les règles | Fait : réservation par segments sans surréservation, annulations, avis, tests automatisés |
| 3 | En faire un produit | Fait : comptes, recherche et réservation, espaces passager et conducteur, profils |
| 4 | S'ouvrir au monde | Fait : recherche de lieux et géocodage inverse Nominatim, distances OSRM, prix suggéré, carte OpenStreetMap, recherche dans un rayon de 5 km |
| 5 | Livrer | Fait : installation Docker en une commande et [INSTALL.md](INSTALL.md). Test sur machine vierge : en attente de l'essai sur une machine virtuelle Ubuntu vierge |

## Ce qui fonctionne

- **Trajets à étapes** : un trajet est découpé en segments entre étapes consécutives ; les places sont comptées segment par segment, si bien qu'une place libérée à une étape est de nouveau réservable pour la suite.
- **Pas de surréservation** : chaque réservation s'effectue dans une transaction, sous le verrou du trajet. Un test lance plusieurs processus qui réservent la dernière place au même instant : un seul l'obtient.
- **Prix d'une portion** : proportionnel à la distance routière, calculé en centimes entiers et figé à la réservation.
- **Annulations** : le passager jusqu'à deux heures avant le départ de son étape de montée ; le conducteur avant le départ, avec un motif, remboursement simulé et notification des passagers.
- **Avis** : entre passager et conducteur, après un trajet effectué, dans un délai de 14 jours.
- **Itinéraire gelé** dès la première réservation ; dates stockées en UTC et affichées à l'heure du Maroc.
- **Espaces** : recherche par ville ou lieu précis et date, avec les trajets passant à 5 km au plus, réservation, espace passager, espace conducteur (publication, passagers, historique), profils publics, back-office d'administration.
- **Cartographie** : publication guidée étape par étape sur une carte (lieux cherchés avec Nominatim ou cliqués, ville de chaque étape déterminée par le serveur, tracé et distances OSRM), prix suggéré, carte du trajet, dans le respect des politiques d'usage d'OpenStreetMap.

## Limites connues et pistes

- Le conducteur ne peut pas encore modifier le prix ou la capacité d'un trajet depuis son espace ; le back-office le permet.
- Le back-office ne crée pas de réservation et ne clôture pas de trajet : ces opérations passent par les espaces utilisateur.
- Les données de démonstration utilisent des coordonnées approximatives et des distances arrondies.
- Le tracé affiché sur la carte est simplifié : il convient à une vue d'ensemble, moins à un fort zoom.
- Pas de réinitialisation de mot de passe ni de vérification d'adresse e-mail.
- La carte et l'aide à la saisie demandent un accès à Internet.

## Documentation

| Document | Contenu |
|---|---|
| [docs/MODELE.md](docs/MODELE.md) | Modèle de données (diagramme entité-association) et choix de conception |
| [docs/DECISIONS.md](docs/DECISIONS.md) | Journal daté des décisions, dont les réponses aux « Questions à trancher » |
| [INSTALL.md](INSTALL.md) | Installation, comptes de démonstration, dépannage |

## Tests

```bash
docker compose --profile test up -d --wait db_test
docker compose exec php php bin/phpunit
```

Tests unitaires, tests d'intégration sur un vrai MySQL (contraintes, verrous, concurrence entre processus) et tests fonctionnels des pages. Les services cartographiques y sont simulés.

## Usage de l'IA

Le développement a été mené avec un agent IA (Claude Code), sous la direction de l'auteur, qui a pris les décisions, relu et validé chaque modification avant son intégration. Les commits concernés portent la mention `Co-Authored-By`.
