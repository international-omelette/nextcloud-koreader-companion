#!/usr/bin/env bash
set -euo pipefail
# reset_and_deploy.sh
# Removes previous PoC app copies and retries copying + enabling with detailed logs and checks.

DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
APP_SRC="$DIR/../koreader-companion"
COMPOSE_PROJECT_DIR="$DIR/.."

log(){ echo "$*"; }
error_exit(){ echo "ERROR: $*"; exit 1; }

log "Starting reset_and_deploy"

# 1) Ensure docker is available
if ! command -v docker >/dev/null 2>&1; then
  error_exit "docker command not found. Please install Docker.";
fi
if ! command -v docker compose >/dev/null 2>&1; then
  log "docker compose plugin not found; will use 'docker compose' where possible but fallback to 'docker-compose' may be needed"
fi

# 2) Check compose project
cd "$COMPOSE_PROJECT_DIR"
log "Running: docker compose ps --services --all"
docker compose ps --services --all || true

# 3) Bring up services
log "Bringing up containers (docker compose up -d)"
docker compose up -d

# 4) Detect Nextcloud app container
log "Detecting Nextcloud web container..."
CONTAINER_ID=""
CONTAINER_ID=$(docker compose ps -q app 2>/dev/null || true)
if [ -z "$CONTAINER_ID" ]; then
  CONTAINER_ID=$(docker ps --filter "ancestor=nextcloud:stable" -q | head -n1 || true)
fi
if [ -z "$CONTAINER_ID" ]; then
  CONTAINER_ID=$(docker ps --filter "name=app-" -q | head -n1 || true)
fi
if [ -z "$CONTAINER_ID" ]; then
  CONTAINER_ID=$(docker ps --filter "name=nextcloud" -q | head -n1 || true)
fi
if [ -z "$CONTAINER_ID" ]; then
  docker ps --format "table {{.ID}}\t{{.Image}}\t{{.Names}}\t{{.Status}}"
  error_exit "Could not find Nextcloud web container. Aborting."
fi
log "Using container: $CONTAINER_ID"

# 5) Remove any previous app copies inside container
log "Removing previous app copies inside container (if any)"
docker exec -u root "$CONTAINER_ID" bash -lc "rm -rf /var/www/html/apps/koreader_companion || true; rm -rf /var/www/html/apps/ebooks_poc || true; rm -rf /var/www/html/apps/nextcloud-ebooks-poc || true; ls -la /var/www/html/apps || true"

# 6) Remove any host bind mount reference in docker-compose.yml (informative)
if grep -q "nextcloud-ebooks-poc" docker-compose.yml 2>/dev/null; then
  log "Warning: docker-compose.yml contains a bind-mount for nextcloud-ebooks-poc. This script expects that to be commented out to avoid rsync conflicts."
fi

# 7) Check app source exists and build via Makefile
if [ ! -d "$APP_SRC" ]; then
  error_exit "App source not found at $APP_SRC"
fi
log "App source found at $APP_SRC"

# 7a) Build the app using Makefile to create production-ready package
log "Building app using Makefile for production-like setup"
cd "$APP_SRC"
if [ ! -f "Makefile" ]; then
  error_exit "Makefile not found in $APP_SRC"
fi

# Clean previous builds and create new tarball
make clean
make appstore || error_exit "Make build failed"

# Extract the built tarball to a temporary location for deployment
BUILD_DIR="$APP_SRC/build/artifacts/appstore"
TEMP_EXTRACT_DIR="/tmp/koreader_companion_deploy"
if [ ! -f "$BUILD_DIR/koreader_companion.tar.gz" ]; then
  error_exit "Built tarball not found at $BUILD_DIR/koreader_companion.tar.gz"
fi

log "Extracting built tarball for deployment"
rm -rf "$TEMP_EXTRACT_DIR"
mkdir -p "$TEMP_EXTRACT_DIR"
cd "$TEMP_EXTRACT_DIR"
tar -xzf "$BUILD_DIR/koreader_companion.tar.gz" || error_exit "Failed to extract tarball"

log "Copying built app to container: $CONTAINER_ID"
docker cp "$TEMP_EXTRACT_DIR/koreader_companion" "$CONTAINER_ID":/var/www/html/apps/ || error_exit "docker cp failed"

# Clean up temporary extraction directory
rm -rf "$TEMP_EXTRACT_DIR"
cd "$COMPOSE_PROJECT_DIR"
log "Copied app. Verifying contents inside container..."
docker exec -u root "$CONTAINER_ID" bash -lc "ls -la /var/www/html/apps/koreader_companion || true"

# 8) No nested folder handling needed - built tarball has correct structure
log "Built tarball deployed with correct structure"

# 9) Fix ownership and permissions
log "Setting ownership to www-data and permissions"
docker exec -u root "$CONTAINER_ID" bash -lc "chown -R www-data:www-data /var/www/html/apps/koreader_companion || true; chmod -R 0755 /var/www/html/apps/koreader_companion || true; ls -la /var/www/html/apps/koreader_companion || true"

# 10) Ensure appinfo exists
log "Checking for appinfo/info.xml"
HAS_INFO=$(docker exec -u root "$CONTAINER_ID" bash -lc "[ -f /var/www/html/apps/koreader_companion/appinfo/info.xml ] && echo yes || echo no")
if [ "$HAS_INFO" != "yes" ]; then
  docker exec -u root "$CONTAINER_ID" bash -lc "ls -la /var/www/html/apps/koreader_companion || true"
  error_exit "appinfo/info.xml not found in app - Nextcloud cannot enable the app"
fi

# 11) Clear opcache / restart PHP-FPM if available
log "Attempting to reload PHP-FPM to clear opcache (best-effort)"
docker exec -u root "$CONTAINER_ID" bash -lc "(service php8.3-fpm reload 2>/dev/null || service php8.2-fpm reload 2>/dev/null || true) || true"

# 12) Enable the app via occ
log "Enabling the app via occ"
docker exec -u www-data "$CONTAINER_ID" php occ app:enable koreader_companion || docker exec -u root "$CONTAINER_ID" bash -lc "php occ app:enable koreader_companion || true"

# 12b) No container-side version bump (already handled on host)
log "Skipping container-side version bump"

# 12c) Clear Nextcloud caches and optimized files
log "Clearing Nextcloud caches and optimized files (occ maintenance:repair & cache:clear if available)"
# Use maintenance:repair to refresh appstore and caches; cache:clear exists in some versions
docker exec -u www-data "$CONTAINER_ID" php occ upgrade || true
# Try specific cache clear if available
docker exec -u www-data "$CONTAINER_ID" bash -lc "php occ cache:clear 2>/dev/null || true"
# Clear webserver opcache by restarting PHP-FPM (best-effort)
docker exec -u root "$CONTAINER_ID" bash -lc "(service php8.3-fpm restart 2>/dev/null || service php8.2-fpm restart 2>/dev/null || service php8.1-fpm restart 2>/dev/null || true) || true"

# 13) Clear bruteforce attempts to allow testing
log "Clearing bruteforce attempts for API testing"
# Get DB container
DB_CONTAINER_ID=$(docker compose ps -q db 2>/dev/null || true)
if [ -n "$DB_CONTAINER_ID" ]; then
    docker exec "$DB_CONTAINER_ID" mysql -u nextcloud -pnextcloud nextcloud -e "DELETE FROM oc_bruteforce_attempts;" 2>/dev/null || true
    log "Cleared bruteforce attempts"
else
    log "Could not find database container, skipping bruteforce cleanup"
fi

# 14) List apps and show logs
log "Listing installed apps (excerpt)"
docker exec -u www-data "$CONTAINER_ID" php occ app:list | sed -n '1,200p' || true

log "Done. If you encounter issues, inspect container logs: docker logs $CONTAINER_ID"

exit 0
