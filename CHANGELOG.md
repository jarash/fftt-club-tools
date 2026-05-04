# Changelog

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
