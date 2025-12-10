#!/bin/bash

# Helper script to update CHANGELOG.md using git-cliff
# Usage:
#   ./update-changelog.sh                    # Preview unreleased changes
#   ./update-changelog.sh --release v1.2.0   # Prepare release with new tag
#   ./update-changelog.sh --full             # Regenerate full changelog

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Check if git-cliff is installed
if ! command -v git-cliff &> /dev/null; then
    echo -e "${RED}Error: git-cliff is not installed${NC}"
    echo "Install it with: brew install git-cliff"
    exit 1
fi

# Check if we're in a git repository
if ! git rev-parse --git-dir > /dev/null 2>&1; then
    echo -e "${RED}Error: Not in a git repository${NC}"
    exit 1
fi

# Parse arguments
if [ "$1" == "--release" ]; then
    if [ -z "$2" ]; then
        echo -e "${RED}Error: Version tag required${NC}"
        echo "Usage: $0 --release v1.2.0"
        exit 1
    fi

    VERSION=$2
    echo -e "${GREEN}Preparing release: $VERSION${NC}"
    echo ""

    # Check if tag already exists
    if git rev-parse "$VERSION" >/dev/null 2>&1; then
        echo -e "${RED}Error: Tag $VERSION already exists${NC}"
        exit 1
    fi

    # Preview changes
    echo -e "${YELLOW}Changes to be added:${NC}"
    git-cliff --unreleased --tag "$VERSION"
    echo ""

    # Ask for confirmation
    read -p "Update CHANGELOG.md with these changes? (y/N) " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        echo "Cancelled."
        exit 0
    fi

    # Update changelog
    git-cliff --unreleased --tag "$VERSION" --prepend CHANGELOG.md
    echo -e "${GREEN}✓ CHANGELOG.md updated${NC}"
    echo ""
    echo "Next steps:"
    echo "1. Review CHANGELOG.md"
    echo "2. Update composer.json version to ${VERSION#v}"
    echo "3. Commit changes: git add CHANGELOG.md composer.json && git commit -m 'Release $VERSION'"
    echo "4. Create tag: git tag $VERSION"
    echo "5. Push: git push && git push --tags"

elif [ "$1" == "--full" ]; then
    echo -e "${YELLOW}Regenerating full changelog...${NC}"
    git-cliff --output CHANGELOG.md
    echo -e "${GREEN}✓ Full changelog regenerated${NC}"

else
    # Default: preview unreleased changes
    echo -e "${YELLOW}Unreleased changes:${NC}"
    echo ""
    git-cliff --unreleased
    echo ""
    echo -e "${GREEN}To prepare a release:${NC} $0 --release v1.2.0"
    echo -e "${GREEN}To regenerate full changelog:${NC} $0 --full"
fi
