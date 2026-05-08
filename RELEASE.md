# Release Process - FFTT Club Tools

Guide pour creer une nouvelle version du plugin.

## Etapes du processus

### 1. Mettre a jour le changelog

Editer CHANGELOG.md et ajouter la nouvelle version au debut du fichier.

Format :

```markdown
## [X.Y.Z] - YYYY-MM-DD

### Ajoute
- ...

### Modifie
- ...

### Corrige
- ...

---

## [X.Y.Z-1] - ...
```

Important :
- Le changelog doit utiliser X.Y.Z (sans prefixe v)
- Le tag Git doit utiliser vX.Y.Z

### 2. Mettre a jour la version dans le plugin

Editer fftt-club-tools.php et mettre a jour :
- le header Version: X.Y.Z
- la constante FFTT_CLUB_TOOLS_VERSION

### 3. Commiter les changements

```bash
git add CHANGELOG.md fftt-club-tools.php
git commit -m "chore: bump to X.Y.Z with changelog"
git push
```

### 4. Creer et pousser le tag

```bash
git tag -a vX.Y.Z -m "vX.Y.Z: description courte"
git push origin vX.Y.Z
```

## Automatisation

GitHub Actions gere automatiquement :
1. Extraction de la section changelog correspondant au tag
2. Build du zip du plugin
3. Creation de la release GitHub avec :
- body = section changelog de la version
- asset = fftt-club-tools.zip

Release a verifier sur GitHub :
https://github.com/jarash/fftt-club-tools/releases

## Checklist rapide

- [ ] Mettre a jour CHANGELOG.md
- [ ] Mettre a jour fftt-club-tools.php (header + constante)
- [ ] Commit + push
- [ ] Creer le tag vX.Y.Z
- [ ] Push du tag
- [ ] Verifier la release apres 2-3 minutes

## Depannage

### Release creee sans description
- Verifier que CHANGELOG.md contient bien une section ## [X.Y.Z]
- Verifier que le tag est vX.Y.Z
- Verifier le workflow GitHub Actions dans l'onglet Actions

### Release non creee
- Verifier que le tag est bien pousse sur origin
- Verifier les erreurs du workflow dans Actions
