# FFTT Club Tools

Plugin WordPress pour la gestion des besoins d'un club FFTT.

## Fonctionnalités principales

- Post type `joueur` et taxonomie `equipe`
- Champs ACF pour les joueurs
- Shortcode `[ranking]`
- Import FFTT manuel depuis l'administration
- Mise a jour automatique via GitHub Releases

## Installation

1. Telecharger `fftt-club-tools.zip` depuis les Releases GitHub.
2. Dans WordPress: Extensions > Ajouter > Mettre en ligne une extension.
3. Activer le plugin.
4. Configurer les acces API dans la page `FFTT Club Tools`.

## Developpement local

```bash
composer install
```

## Releases automatiques

Un workflow GitHub Actions construit automatiquement `fftt-club-tools.zip` a chaque tag `vX.Y.Z`.
