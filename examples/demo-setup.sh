
set -euo pipefail

BLOKCTL="php bin/blokctl"
SPACE_ID="${1:?Usage: $0 <SPACE_ID>}"

# Step 1: Display space info
echo "=== Step 1: Space Info ==="
$BLOKCTL space:info -S "$SPACE_ID"

# Step 1b: Retrieve the preview access token
echo "=== Step 1b: Preview Access Token ==="
TOKEN=$($BLOKCTL space:token -S "$SPACE_ID" --only-token)
echo "$TOKEN"


# Step 2: Set preview URLs
echo "=== Step 2: Set Preview URLs ==="
$BLOKCTL space:preview-set -S "$SPACE_ID" \
  "https://storyblok-demo-default-se.netlify.app/?token=${TOKEN}&path=" \
  -e "Local Development=https://localhost:3000/?token=${TOKEN}&path="

# Step 3: Remove demo mode
echo "=== Step 3: Remove Demo Mode ==="
$BLOKCTL space:demo-remove -S "$SPACE_ID"

# Step 4: Assign workflow stages to stories
echo "=== Step 4: Assign Workflow Stages ==="
$BLOKCTL stories:workflow-assign -S "$SPACE_ID"

# Step 5: Install apps
echo "=== Step 5: Install Apps ==="
for slug in releases_only storyblok-gmbh@ai-seo replace_asset export import backups; do
  echo "Installing app: $slug"
  $BLOKCTL app:provision-install -S "$SPACE_ID" --by-slug="$slug" || echo "Warning: failed to install $slug, continuing..."
done

# Step 6: Add SEO AI meta to article-page
echo "=== Step 6: Add SEO AI Meta ==="
$BLOKCTL component:field-add -S "$SPACE_ID" \
  --component=article-page --field=SEO --type=custom --field-type=sb-ai-seo --tab=SEO

# Step 7: Assign tags to stories
echo "=== Step 7: Assign Tags ==="
# "Configuration" tag for utility pages
$BLOKCTL stories:tags-assign -S "$SPACE_ID" \
  --story-slug=error-404 --story-slug=site-config \
  -t Configuration || true

# "Landing" + "Marketing" tags for key pages
$BLOKCTL stories:tags-assign -S "$SPACE_ID" \
  --story-slug=home --story-slug=contact \
  -t Landing -t Marketing || true

# "Page" tag for remaining pages — adjust slugs as needed
# List stories first to identify untagged ones:
#   $BLOKCTL stories:list -S "$SPACE_ID"
# Then uncomment and edit:
# $BLOKCTL stories:tags-assign -S "$SPACE_ID" \
#   --story-slug=about --story-slug=services \
#   -t Page

echo "=== Demo Setup Complete ==="
