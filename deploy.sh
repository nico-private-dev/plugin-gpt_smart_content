#!/bin/bash
# Script de déploiement — AI Content Filler
# Usage : ./deploy.sh
# Génère un ZIP propre prêt à uploader sur Freemius.

set -e

PLUGIN_DIR="ai-content-filler"
ZIP_NAME="ai-content-filler.zip"

# Lire la version depuis le fichier principal
VERSION=$(grep "Version:" "$PLUGIN_DIR/ai-content-filler.php" | head -1 | sed 's/.*Version:[[:space:]]*//' | tr -d '[:space:]')

echo "→ Version : $VERSION"

# Supprimer l'ancien ZIP s'il existe
rm -f "$ZIP_NAME"

# Créer le ZIP en excluant tous les fichiers inutiles
zip -r "$ZIP_NAME" "$PLUGIN_DIR/" \
    --exclude "*.git*" \
    --exclude "*/.github/*" \
    --exclude "*.DS_Store" \
    --exclude "*/__MACOSX/*" \
    --exclude "*/node_modules/*" \
    --exclude "*/gulptasks/*" \
    --exclude "*/gulpfile.js" \
    --exclude "*/package.json" \
    --exclude "*/package-lock.json" \
    --exclude "*/composer.json" \
    --exclude "*/composer.lock" \
    --exclude "*/.editorconfig" \
    --exclude "*/.example.env" \
    --exclude "*/phpstan.neon" \
    --exclude "*/phpcs.xml" \
    --exclude "*/phpcompat.xml" \
    --exclude "*/CONTRIBUTING.md" \
    --exclude "*/README.md" \
    --exclude "*/.phpstan/*" \
    --exclude "*/assets/scss/*" \
    > /dev/null

SIZE=$(du -sh "$ZIP_NAME" | cut -f1)
echo "✓ $ZIP_NAME créé ($SIZE) — version $VERSION"
echo "  → $(pwd)/$ZIP_NAME"
