#!/bin/bash
#
# optimize_landing_images.sh
# Optimizes images for landing pages by resizing and converting formats
#
# Usage: ./scripts/optimize_landing_images.sh
#
# This script will:
# 1. Resize large images to max 1600px width (preserving aspect ratio)
# 2. Convert PNGs to JPEGs (except those needing transparency)
# 3. Compress images for web use
#

set -e

# Configuration
MAX_WIDTH=1600
JPEG_QUALITY=85
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
BASE_DIR="$(dirname "$SCRIPT_DIR")"
BACKUP_DIR="${BASE_DIR}/includes/mimages/_backup_originals"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo "========================================"
echo "Landing Page Image Optimization Script"
echo "========================================"
echo ""

# Check for required tools
if ! command -v sips &> /dev/null; then
    echo -e "${RED}Error: sips command not found. This script requires macOS.${NC}"
    exit 1
fi

# Create backup directory
echo -e "${YELLOW}Creating backup directory...${NC}"
mkdir -p "$BACKUP_DIR"

# Function to get image width
get_width() {
    sips -g pixelWidth "$1" 2>/dev/null | grep pixelWidth | awk '{print $2}'
}

# Function to get file size in human readable format
get_size() {
    ls -lh "$1" | awk '{print $5}'
}

# Function to optimize a single image
optimize_image() {
    local src="$1"
    local keep_png="$2"
    local filename=$(basename "$src")
    local dir=$(dirname "$src")
    local extension="${filename##*.}"
    local name="${filename%.*}"

    echo ""
    echo -e "${YELLOW}Processing: ${filename}${NC}"

    # Get original stats
    local orig_width=$(get_width "$src")
    local orig_size=$(get_size "$src")
    echo "  Original: ${orig_width}px wide, ${orig_size}"

    # Backup original
    cp "$src" "${BACKUP_DIR}/${filename}"

    # Determine target format
    local target_ext="$extension"
    local target_file="$src"

    if [[ "$extension" == "png" ]] && [[ "$keep_png" != "true" ]]; then
        target_ext="jpg"
        target_file="${dir}/${name}.jpg"
    fi

    # Resize if needed
    if [[ $orig_width -gt $MAX_WIDTH ]]; then
        echo "  Resizing to ${MAX_WIDTH}px width..."
        sips --resampleWidth $MAX_WIDTH "$src" --out "$src" > /dev/null 2>&1
    fi

    # Convert PNG to JPEG if needed
    if [[ "$extension" == "png" ]] && [[ "$keep_png" != "true" ]]; then
        echo "  Converting PNG to JPEG..."
        sips -s format jpeg -s formatOptions $JPEG_QUALITY "$src" --out "$target_file" > /dev/null 2>&1
        # Remove original PNG
        rm "$src"
        echo "  Removed original PNG"
    elif [[ "$extension" == "jpg" ]] || [[ "$extension" == "jpeg" ]]; then
        # Re-compress JPEG
        echo "  Compressing JPEG..."
        sips -s formatOptions $JPEG_QUALITY "$src" --out "$src" > /dev/null 2>&1
    elif [[ "$keep_png" == "true" ]]; then
        echo "  Keeping as PNG (transparency needed)"
    fi

    # Get new stats
    local new_size=$(get_size "$target_file")
    local new_width=$(get_width "$target_file")
    echo -e "  ${GREEN}Optimized: ${new_width}px wide, ${new_size}${NC}"
}

# Track which files need PHP updates
declare -a PNG_TO_JPG_CONVERSIONS

echo ""
echo "========================================"
echo "Optimizing StraboField Images"
echo "========================================"

STRABOFIELD_DIR="${BASE_DIR}/includes/mimages/strabofield"
for img in "$STRABOFIELD_DIR"/*.png; do
    if [[ -f "$img" ]]; then
        filename=$(basename "$img")
        optimize_image "$img" "false"
        # Track conversion for PHP update
        PNG_TO_JPG_CONVERSIONS+=("strabofield:${filename%.png}")
    fi
done

for img in "$STRABOFIELD_DIR"/*.jpg; do
    if [[ -f "$img" ]]; then
        optimize_image "$img" "false"
    fi
done

echo ""
echo "========================================"
echo "Optimizing StraboMicro Images"
echo "========================================"

STRABOMICRO_DIR="${BASE_DIR}/includes/mimages/strabomicro"
for img in "$STRABOMICRO_DIR"/*.jpg; do
    if [[ -f "$img" ]]; then
        optimize_image "$img" "false"
    fi
done

# Handle the logo PNG separately (needs transparency)
if [[ -f "$STRABOMICRO_DIR/strabomicro_logo.png" ]]; then
    echo ""
    echo -e "${YELLOW}Processing: strabomicro_logo.png${NC}"
    local orig_size=$(get_size "$STRABOMICRO_DIR/strabomicro_logo.png")
    local orig_width=$(get_width "$STRABOMICRO_DIR/strabomicro_logo.png")
    echo "  Original: ${orig_width}px wide, ${orig_size}"

    # Backup
    cp "$STRABOMICRO_DIR/strabomicro_logo.png" "${BACKUP_DIR}/strabomicro_logo.png"

    # Resize if needed (logo probably doesn't need to be huge)
    if [[ $orig_width -gt 400 ]]; then
        echo "  Resizing to 400px width (logo)..."
        sips --resampleWidth 400 "$STRABOMICRO_DIR/strabomicro_logo.png" --out "$STRABOMICRO_DIR/strabomicro_logo.png" > /dev/null 2>&1
    fi

    local new_size=$(get_size "$STRABOMICRO_DIR/strabomicro_logo.png")
    local new_width=$(get_width "$STRABOMICRO_DIR/strabomicro_logo.png")
    echo -e "  ${GREEN}Optimized: ${new_width}px wide, ${new_size} (kept as PNG)${NC}"
fi

echo ""
echo "========================================"
echo "Optimizing StraboExperimental Images"
echo "========================================"

STRABOEXP_DIR="${BASE_DIR}/includes/mimages/straboexperimental"
for img in "$STRABOEXP_DIR"/*.png; do
    if [[ -f "$img" ]]; then
        filename=$(basename "$img")
        # Keep experiment_list.png as PNG (small file, may have transparency)
        if [[ "$filename" == "experiment_list.png" ]]; then
            echo ""
            echo -e "${YELLOW}Processing: ${filename}${NC}"
            echo "  Skipping (already small)"
        else
            optimize_image "$img" "false"
            PNG_TO_JPG_CONVERSIONS+=("straboexperimental:${filename%.png}")
        fi
    fi
done

echo ""
echo "========================================"
echo "Summary"
echo "========================================"
echo ""
echo "Original images backed up to:"
echo "  ${BACKUP_DIR}"
echo ""

if [[ ${#PNG_TO_JPG_CONVERSIONS[@]} -gt 0 ]]; then
    echo -e "${YELLOW}PNG files converted to JPG:${NC}"
    for conv in "${PNG_TO_JPG_CONVERSIONS[@]}"; do
        dir="${conv%%:*}"
        name="${conv##*:}"
        echo "  - ${dir}/${name}.png -> ${name}.jpg"
    done
    echo ""
    echo -e "${RED}IMPORTANT: Update the following PHP files to use .jpg instead of .png:${NC}"
    echo "  - strabofield_landing.php"
    echo "  - straboexperimental_landing.php"
fi

echo ""
echo -e "${GREEN}Optimization complete!${NC}"
echo ""

# Show before/after comparison
echo "========================================"
echo "Before/After Comparison"
echo "========================================"
echo ""
echo "Backup folder size (originals):"
du -sh "$BACKUP_DIR" | awk '{print "  " $1}'
echo ""
echo "Optimized folder sizes:"
du -sh "$STRABOFIELD_DIR" | awk '{print "  StraboField: " $1}'
du -sh "$STRABOMICRO_DIR" | awk '{print "  StraboMicro: " $1}'
du -sh "$STRABOEXP_DIR" | awk '{print "  StraboExperimental: " $1}'
