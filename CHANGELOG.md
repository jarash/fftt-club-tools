# Changelog

## [1.2.0] - 2026-05-08

### Ajoute
- Fichier `RELEASE.md` pour documenter le process de release
- Cache API FFTT via transients WordPress (TTL configurable, valeur par defaut: 3600s)
- Bouton admin "Vider le cache API FFTT" avec purge manuelle des transients

### Modifie
- Workflow release GitHub: extraction automatique du body depuis `CHANGELOG.md` lors d'un tag `vX.Y.Z`
- Import FFTT (joueurs et classements equipes): appels API passes via wrappers caches
- Matching des equipes renforce pour les divisions avec plusieurs equipes (ex: 2 equipes en D4)
- Normalisation des noms d'equipe pour ignorer le suffixe "Phase X" dans le matching
- En-tete de page equipe: suppression des cards "pts poule", "matchs poule" et "bilan poule"

### Corrige
- Evite les attributions de classement erronnees en cas de correspondances ambiguës (skip au lieu d'assigner au mauvais terme)

## [1.1.0] - 2026-05-04

### Ajoute
- Workflow GitHub Actions de build/release automatique du zip plugin
- Integration du Plugin Update Checker pour les updates via GitHub Releases
- Classe `FfttClubToolsImporter` pour centraliser l'import FFTT
- Bouton d'import manuel dans la page d'administration FFTT Club Tools
- Historique simple du dernier import dans l'admin

### Modifie
- Bootstrap plugin initialise constantes et autoload Composer local

### Supprime
- Import via URL (endpoint `Fftt/import.php`) retire pour limiter l'exposition externe
- Configuration du token d'import retiree de l'interface d'administration
